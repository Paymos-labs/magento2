<?php

declare(strict_types=1);

use Paymos\Payment\Service\Config;
use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Service\WebhookProcessor;
use Paymos\Payment\Tests\FakeOrderGateway;
use Paymos\Payment\Tests\InMemorySnapshotRepository;
use Paymos\Payment\Tests\RecordingEventStore;
use Paymos\Webhook\InMemoryEventStore;

/**
 * @param array<string, mixed> $opts
 */
function paymos_m2_processor(array $opts = array())
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = isset($opts['snapshots']) ? $opts['snapshots'] : new InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $eventStore = isset($opts['eventStore']) ? $opts['eventStore'] : new InMemoryEventStore(static function () {
        return 1709000000;
    });
    $gateway = isset($opts['gateway']) ? $opts['gateway'] : new FakeOrderGateway();
    $invoiceJson = isset($opts['invoice']) ? $opts['invoice'] : array();

    $processor = new WebhookProcessor(
        $config,
        $snapshots,
        $eventStore,
        new OrderMapper($gateway),
        static function () use ($invoiceJson) {
            return paymos_m2_reverse_client($invoiceJson);
        }
    );

    return array('processor' => $processor, 'gateway' => $gateway, 'eventStore' => $eventStore, 'snapshots' => $snapshots);
}

function test_magento_webhook_completes_paid_order()
{
    $gateway = new FakeOrderGateway();
    $ctx = paymos_m2_processor(array('gateway' => $gateway));

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(200, $result->httpCode(), 'A valid paid webhook must return 200.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'The matching order must be invoiced once.');
    assertSameValue('processing', $gateway->opsOfType('invoice')[0]['paid_status'], 'The configured paid status must be used.');
}

function test_magento_webhook_is_idempotent_for_duplicates()
{
    $gateway = new FakeOrderGateway();
    $eventStore = new InMemoryEventStore(static function () {
        return 1709000000;
    });
    $ctx = paymos_m2_processor(array('gateway' => $gateway, 'eventStore' => $eventStore));

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);

    $first = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);
    // A second reverse-verify client is needed for a non-duplicate, but the
    // duplicate short-circuits before any API call, so the single-response mock
    // is fine.
    $second = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(200, $first->httpCode(), 'First webhook must be accepted.');
    assertSameValue(200, $second->httpCode(), 'Duplicate webhook must be acked with 200.');
    assertTrueValue($second->isDuplicate(), 'The second delivery must be flagged a duplicate.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'A duplicate must not invoice the order twice.');
}

function test_magento_webhook_rejects_bad_signature()
{
    $ctx = paymos_m2_processor();

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_WRONG', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(401, $result->httpCode(), 'A bad signature must return 401.');
}

function test_magento_webhook_rejects_timestamp_skew()
{
    $ctx = paymos_m2_processor();

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    // Sign for a timestamp far outside the 300s tolerance of "now".
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000 + 4000);

    assertSameValue(401, $result->httpCode(), 'A stale timestamp must return 401.');
}

function test_magento_webhook_holds_for_manual_review_on_amount_mismatch()
{
    // Reverse-verify PASSES (invoice matches snapshot), but the live order total
    // changed -> AmountGuard fails. This is NOT transient: the webhook is
    // acknowledged (200) and the event committed so the server does not retry
    // forever; the order is held with a review comment, never invoiced.
    $gateway = new FakeOrderGateway();
    $gateway->setAmount('250.00');
    $eventStore = new RecordingEventStore();
    $ctx = paymos_m2_processor(array('gateway' => $gateway, 'eventStore' => $eventStore));

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(200, $result->httpCode(), 'An amount mismatch must acknowledge the webhook (200), not retry forever.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'A mismatched order must not be invoiced.');
    assertSameValue(1, count($gateway->opsOfType('comment')), 'A mismatch must leave a manual-review comment.');
    assertTrueValue($eventStore->committed, 'A handled (held) event must be committed so it is not retried.');
    assertFalseValue($eventStore->released, 'A held mismatch must not release the dedup lock.');
}

function test_magento_webhook_does_not_roll_back_paid_order_on_late_cancel()
{
    $gateway = new FakeOrderGateway();
    $gateway->setPaid(true); // order already paid
    $cancelledInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'cancelled',
        'order' => array('external_id' => '100000042-0', 'amount' => '100', 'currency' => 'USD'),
    );
    $ctx = paymos_m2_processor(array('gateway' => $gateway, 'invoice' => $cancelledInvoice));

    $body = json_encode(paymos_m2_invoice_event('evt_late', 'invoice.cancelled', 'cancelled'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(200, $result->httpCode(), 'A late cancel after paid must still ack with 200.');
    assertSameValue(0, count($gateway->opsOfType('cancel')), 'A late cancel must NOT downgrade an already-paid order.');
}

function test_magento_webhook_ignores_non_invoice_event()
{
    $gateway = new FakeOrderGateway();
    $ctx = paymos_m2_processor(array('gateway' => $gateway));

    $body = json_encode(array(
        'event_id' => 'evt_wd',
        'event_type' => 'withdrawal.completed',
        'occurred_at' => 1709000000,
        'data' => array('status' => 'completed'),
    ));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(200, $result->httpCode(), 'A non-invoice event must be acked with 200.');
    assertSameValue(0, count($gateway->calls), 'A non-invoice event must not touch any order.');
}

function test_magento_webhook_returns_400_when_snapshot_missing()
{
    $gateway = new FakeOrderGateway();
    $ctx = paymos_m2_processor(array('gateway' => $gateway, 'snapshots' => new InMemorySnapshotRepository()));

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(400, $result->httpCode(), 'A missing snapshot must return 400 so the server retries.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'No order must be invoiced without a snapshot.');
}

function test_magento_webhook_reverse_verify_mismatch_returns_400()
{
    // Reverse-verify FAILS: the live invoice reports a different external order.
    $gateway = new FakeOrderGateway();
    $badInvoice = array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'SOMEONE_ELSE-0', 'amount' => '100', 'currency' => 'USD'),
    );
    $ctx = paymos_m2_processor(array('gateway' => $gateway, 'invoice' => $badInvoice));

    $body = json_encode(paymos_m2_invoice_event('evt_paid', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    $result = $ctx['processor']->handle($body, $signature, 'processing', true, 1709000000);

    assertSameValue(400, $result->httpCode(), 'A reverse-verify mismatch must return 400.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'A spoofed/mismatched invoice must not complete the order.');
}
