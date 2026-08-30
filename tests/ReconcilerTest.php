<?php

declare(strict_types=1);

use Paymos\Payment\Service\Config;
use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Service\Reconciler;
use Paymos\Payment\Tests\FakeOrderGateway;
use Paymos\Payment\Tests\InMemorySnapshotRepository;
use Paymos\Webhook\WebhookEvent;

/**
 * D5 guard: a reconcile answer that is MISSING a field the snapshot expects
 * must not count as a match. Before the fix, matches() treated an empty actual
 * value as equality, so a changed API response shape would rubber-stamp every
 * snapshot and apply statuses to the wrong orders.
 */
function paymos_m2_reconciler(array $opts = array())
{
    $gateway = isset($opts['gateway']) ? $opts['gateway'] : new FakeOrderGateway();
    $snapshots = isset($opts['snapshots']) ? $opts['snapshots'] : new InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $invoiceJson = isset($opts['invoice']) ? $opts['invoice'] : array();

    return new Reconciler(
        Config::fromArray(paymos_m2_generated_config()),
        $snapshots,
        new OrderMapper($gateway, paymos_m2_settings(array('payment/paymos/paid_order_status' => 'processing'))),
        static function () use ($invoiceJson) {
            return paymos_m2_reverse_client($invoiceJson);
        }
    );
}

function test_magento_reconciler_ignores_answer_missing_order_reference()
{
    $gateway = new FakeOrderGateway();
    // A live invoice whose payload carries NO order block at all — everything
    // the snapshot guard compares against is missing.
    $invoice = array(
        'invoice_id' => paymos_m2_snapshot()['paymos_invoice_id'],
        'project_id' => paymos_m2_snapshot()['project_id'],
        'status' => 'paid',
    );

    $reconciler = paymos_m2_reconciler(array('gateway' => $gateway, 'invoice' => $invoice));
    $applied = $reconciler->run();

    assertSameValue(0, $applied, 'An answer missing the order reference must not be applied.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'No order may be invoiced from a mismatched answer.');
}

function test_magento_reconciler_applies_paid_answer_that_matches()
{
    $gateway = new FakeOrderGateway();
    $row = paymos_m2_snapshot();
    $invoice = array(
        'invoice_id' => $row['paymos_invoice_id'],
        'project_id' => $row['project_id'],
        'status' => 'paid',
        'order' => array('external_id' => $row['external_order_id'], 'currency' => $row['currency']),
    );

    $reconciler = paymos_m2_reconciler(array('gateway' => $gateway, 'invoice' => $invoice));
    $applied = $reconciler->run();

    assertSameValue(1, $applied, 'A matching paid answer must be applied.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'The order must be invoiced.');
}
