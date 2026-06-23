<?php

declare(strict_types=1);

use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Tests\FakeOrderGateway;
use Paymos\Webhook\WebhookEvent;

function test_magento_mapper_invoices_order_on_paid()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertTrueValue($paid, 'invoice.paid must report a completed payment.');
    $invoiced = $gateway->opsOfType('invoice');
    assertSameValue(1, count($invoiced), 'The order must be invoiced exactly once.');
    assertSameValue('processing', $invoiced[0]['paid_status'], 'The configured paid status must be applied.');
    assertSameValue('inv_123', $invoiced[0]['transaction_id'], 'The invoice id is the transaction id when transfers are absent.');
}

function test_magento_mapper_uses_confirmed_transfer_hash_as_transaction_id()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid', array(
        'data' => array(
            'payment' => array(
                'transfers' => array(
                    array('tx_hash' => '0xolder', 'status' => 'confirmed'),
                    array('tx_hash' => '0xlatest', 'status' => 'confirmed'),
                ),
            ),
        ),
    )));
    $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    $invoiced = $gateway->opsOfType('invoice');
    assertSameValue('0xlatest', $invoiced[0]['transaction_id'], 'The last confirmed transfer hash must be the transaction id.');
}

function test_magento_mapper_invoices_order_on_paid_over()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_over', 'invoice.paid_over', 'paid_over'));
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertTrueValue($paid, 'invoice.paid_over must also complete the order.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'paid_over must invoice the order.');
}

function test_magento_mapper_holds_order_on_amount_mismatch()
{
    $gateway = new FakeOrderGateway();
    $gateway->setAmount('250.00'); // order total changed after invoice creation
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));

    // An amount mismatch must NOT throw (that would 400 and the server would retry
    // forever, re-adding the comment each time). It holds for manual review and
    // returns false so the webhook is acknowledged.
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertFalseValue($paid, 'An amount mismatch must not mark the order paid.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'A mismatched order must NOT be invoiced.');
    assertSameValue(1, count($gateway->opsOfType('comment')), 'A mismatch must leave a manual-review comment.');
}

function test_magento_mapper_confirming_sets_status_without_invoicing()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_conf', 'invoice.confirming', 'confirming'));
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertFalseValue($paid, 'confirming must not complete the order.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'confirming must not invoice.');
    assertSameValue(1, count($gateway->opsOfType('status')), 'confirming must set the order status.');
}

function test_magento_mapper_expired_cancels_unpaid_order()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_exp', 'invoice.expired', 'expired'));
    $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertSameValue(1, count($gateway->opsOfType('cancel')), 'expired must cancel an unpaid order.');
}

function test_magento_mapper_underpaid_fails_order()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_under', 'invoice.underpaid', 'underpaid'));
    $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertSameValue(1, count($gateway->opsOfType('cancel')), 'underpaid must cancel the order.');
}

function test_magento_mapper_roll_back_guard_ignores_late_cancel_after_paid()
{
    $gateway = new FakeOrderGateway();
    $gateway->setPaid(true); // order already paid
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_late', 'invoice.cancelled', 'cancelled'));
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertFalseValue($paid, 'A late cancel must not report a completion.');
    assertSameValue(0, count($gateway->opsOfType('cancel')), 'A late cancel must NOT downgrade an already-paid order.');
    assertSameValue(1, count($gateway->logs), 'The roll-back guard must log that it ignored the stale status.');
}

function test_magento_mapper_ignore_does_nothing()
{
    $gateway = new FakeOrderGateway();
    $mapper = new OrderMapper($gateway);

    // An unknown event type maps to ACTION_IGNORE.
    $event = new WebhookEvent(paymos_m2_invoice_event('evt_x', 'invoice.unknown', ''));
    $paid = $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);

    assertFalseValue($paid, 'An ignored event must not complete the order.');
    assertSameValue(0, count($gateway->calls), 'An ignored event must not mutate the order.');
}

function test_magento_mapper_missing_order_throws()
{
    $gateway = new FakeOrderGateway();
    $gateway->clearOrder();
    $mapper = new OrderMapper($gateway);

    $event = new WebhookEvent(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));

    $threw = false;
    try {
        $mapper->apply($event, paymos_m2_snapshot(), 'processing', true);
    } catch (\RuntimeException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'A missing order must throw so the server retries.');
}
