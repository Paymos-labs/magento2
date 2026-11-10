<?php

declare(strict_types=1);

use Paymos\Payment\Service\CheckoutProcessor;
use Paymos\Payment\Service\Config;
use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Tests\FakeClient;
use Paymos\Payment\Tests\FakeInvoices;
use Paymos\Payment\Tests\FakeOrderGateway;
use Paymos\Payment\Tests\InMemorySnapshotRepository;
use Paymos\Payment\Tests\RecordingEventStore;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\WebhookEvent;

function paymos_f01_paid_invoice(array $row): array
{
    return ['invoice_id' => $row['paymos_invoice_id'], 'project_id' => $row['project_id'],
        'status' => 'paid', 'is_test' => true,
        'order' => ['external_id' => $row['external_order_id'], 'amount' => $row['amount'], 'currency' => $row['currency']]];
}

function test_magento_finisher_reconcile_retries_native_failure_before_terminal_snapshot()
{
    $row = paymos_m2_snapshot();
    $snapshots = new InMemorySnapshotRepository([$row]);
    $gateway = new FakeOrderGateway();
    $gateway->invoiceFailuresRemaining = 1;
    $reconciler = paymos_m2_reconciler(['gateway' => $gateway, 'snapshots' => $snapshots, 'invoice' => paymos_f01_paid_invoice($row)]);
    assertSameValue(0, $reconciler->run(), 'First failed save must not count as applied.');
    assertSameValue('awaiting_payment', $snapshots->rows[0]['status'], 'Native failure must remain in retry window.');
    assertSameValue(1, $reconciler->run(), 'Next reconcile must recover.');
    assertSameValue('paid', $snapshots->rows[0]['status'], 'Successful native save permits terminal snapshot.');
    assertSameValue(0, $reconciler->run(), 'Applied terminal row leaves retry window.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'Recovery creates exactly one invoice.');
}

function test_magento_finisher_reconcile_retries_snapshot_failure_without_second_invoice()
{
    $row = paymos_m2_snapshot();
    $snapshots = new InMemorySnapshotRepository([$row]);
    $snapshots->statusFailuresRemaining = 1;
    $gateway = new FakeOrderGateway();
    $reconciler = paymos_m2_reconciler(['gateway' => $gateway, 'snapshots' => $snapshots, 'invoice' => paymos_f01_paid_invoice($row)]);
    $reconciler->run();
    assertSameValue('awaiting_payment', $snapshots->rows[0]['status'], 'Failed snapshot remains retryable.');
    $reconciler->run();
    assertSameValue('paid', $snapshots->rows[0]['status'], 'Retry advances snapshot.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'Retry reuses prior native payment.');
}

function test_magento_finisher_reconcile_rejects_empty_or_foreign_invoice_identity()
{
    foreach (['', 'inv_foreign'] as $invoiceId) {
        $row = paymos_m2_snapshot();
        $invoice = paymos_f01_paid_invoice($row);
        $invoice['invoice_id'] = $invoiceId;
        $gateway = new FakeOrderGateway();
        $snapshots = new InMemorySnapshotRepository([$row]);
        $reconciler = paymos_m2_reconciler(['gateway' => $gateway, 'snapshots' => $snapshots, 'invoice' => $invoice]);
        assertSameValue(0, $reconciler->run(), 'Missing/foreign API invoice is not payment proof.');
        assertSameValue(0, count($gateway->opsOfType('invoice')), 'No native mutation on invoice mismatch.');
        assertSameValue('awaiting_payment', $snapshots->rows[0]['status'], 'Mismatch must not advance snapshot.');
    }
}

function test_magento_finisher_canceled_order_is_manual_review_without_paid_transition()
{
    $gateway = new FakeOrderGateway(['order_id' => 42, 'store_id' => 1, 'increment_id' => '100000042',
        'amount' => '100.00', 'currency' => 'USD', 'state' => 'canceled', 'status' => 'canceled', 'is_paid' => false]);
    $mapper = new OrderMapper($gateway, paymos_m2_settings());
    assertFalseValue($mapper->apply(new WebhookEvent(paymos_m2_invoice_event('evt_canceled_paid', 'invoice.paid', 'paid')), paymos_m2_snapshot(), true), 'Canceled order is not a completed payment.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'Canceled order must not be invoiced.');
    assertSameValue(0, count($gateway->opsOfType('status')), 'Canceled order must not change status.');
    assertSameValue(1, count($gateway->opsOfType('comment')), 'Manual review is recorded.');
    assertSameValue('canceled', $gateway->loadOrder(42)['state'], 'Canceled state is preserved.');
}

function test_magento_finisher_webhook_checks_native_order_binding_and_invoice_id()
{
    $cases = [
        ['100000042', 0, 'inv_123', 200],
        ['100000042-0', 0, 'inv_123', 200],
        ['100000042-12', 12, 'inv_123', 200],
        ['100000043-0', 0, 'inv_123', 400],
        ['1000000420-0', 0, 'inv_123', 400],
        ['100000042-01', 1, 'inv_123', 400],
        ['100000042-1-extra', 1, 'inv_123', 400],
        ['100000042-1', 2, 'inv_123', 400],
        ['100000042-0', 0, '', 400],
        ['100000042-0', 0, 'inv_foreign', 400],
    ];
    foreach ($cases as $i => $case) {
        [$externalId, $renewCount, $eventInvoiceId, $expectedCode] = $case;
        $row = paymos_m2_snapshot(['external_order_id' => $externalId, 'renew_count' => $renewCount]);
        $gateway = new FakeOrderGateway();
        $store = new RecordingEventStore();
        $ctx = paymos_m2_processor(['gateway' => $gateway, 'eventStore' => $store,
            'snapshots' => new InMemorySnapshotRepository([$row]), 'invoice' => paymos_f01_paid_invoice($row)]);
        $body = json_encode(paymos_m2_invoice_event('evt_binding_' . $i, 'invoice.paid', 'paid',
            ['data' => ['invoice_id' => $eventInvoiceId, 'order' => ['external_id' => $externalId]]]));
        $result = $ctx['processor']->handle($body, paymos_m2_signed_header('whsec_test_secret', $body, 1709000000), false, 1709000000);
        assertSameValue($expectedCode, $result->httpCode(), 'Native order and invoice binding case ' . $i);
        assertSameValue($expectedCode === 200 ? 1 : 0, count($gateway->opsOfType('invoice')), 'Only exact order binding may invoice.');
        assertSameValue($expectedCode === 200, $store->committed, 'Invalid identity is never committed.');
    }
}

function test_magento_finisher_webhook_internal_failures_are_500_and_retryable()
{
    $gateway = new FakeOrderGateway();
    $gateway->invoiceFailuresRemaining = 1;
    $store = new RecordingEventStore();
    $ctx = paymos_m2_processor(['gateway' => $gateway, 'eventStore' => $store]);
    $body = json_encode(paymos_m2_invoice_event('evt_retry_save', 'invoice.paid', 'paid'));
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);
    assertSameValue(500, $ctx['processor']->handle($body, $signature, false, 1709000000)->httpCode(), 'Native storage failure is internal.');
    assertTrueValue($store->released, 'Failed native save releases own event.');
    assertSameValue('awaiting_payment', $ctx['snapshots']->rows[0]['status'], 'Native failure cannot finalize snapshot.');
    assertSameValue(200, $ctx['processor']->handle($body, $signature, false, 1709000000)->httpCode(), 'Same event must recover.');
    assertSameValue(1, count($gateway->opsOfType('invoice')), 'Retry completes exactly once.');

    $brokenStore = new class implements EventStoreInterface {
        public function remember($eventId, $ttlSeconds) { throw new RuntimeException('Database unavailable.'); }
    };
    $ctx = paymos_m2_processor(['eventStore' => $brokenStore]);
    assertSameValue(500, $ctx['processor']->handle($body, $signature, false, 1709000000)->httpCode(), 'Event storage failure is 500.');
}

function test_magento_finisher_checkout_preserves_three_decimal_currency()
{
    $invoices = new FakeInvoices();
    $snapshots = new InMemorySnapshotRepository();
    $checkout = new CheckoutProcessor(Config::fromArray(paymos_m2_generated_config()), $snapshots,
        static function () use ($invoices) { return new FakeClient($invoices); });
    $checkout->start(['order_id' => 42, 'increment_id' => '100000042', 'amount' => '10.001', 'currency' => 'BHD'], 'sandbox');
    assertSameValue('10.001', $invoices->createPayloads[0]['amount'], 'Three decimal order amount must reach API exactly.');
    assertSameValue('10.001', $snapshots->rows[0]['amount'], 'Snapshot must preserve significant digits.');
    $gateway = new FakeOrderGateway();
    $gateway->setAmount('10.002', 'BHD');
    $mapper = new OrderMapper($gateway, paymos_m2_settings());
    $event = new WebhookEvent(paymos_m2_invoice_event('evt_bhd', 'invoice.paid', 'paid', ['data' => ['order' => ['amount' => '10.001', 'currency' => 'BHD']]]));
    assertFalseValue($mapper->apply($event, $snapshots->rows[0], false), 'A change in the third decimal must prevent completion.');
    assertSameValue(0, count($gateway->opsOfType('invoice')), 'Changed three decimal order must be held.');
}

function test_magento_finisher_event_commit_and_cleanup_failure_return_500()
{
    foreach ([false, true] as $cleanupThrows) {
        $store = new class($cleanupThrows) implements EventStoreInterface {
            private $cleanupThrows;
            public function __construct($cleanupThrows) { $this->cleanupThrows = $cleanupThrows; }
            public function remember($eventId, $ttlSeconds) { return true; }
            public function commit() { return false; }
            public function release() { if ($this->cleanupThrows) { throw new RuntimeException('Database still unavailable.'); } }
        };
        $ctx = paymos_m2_processor(['eventStore' => $store]);
        $body = json_encode(paymos_m2_invoice_event('evt_commit_' . (int) $cleanupThrows, 'invoice.paid', 'paid'));
        $result = $ctx['processor']->handle($body, paymos_m2_signed_header('whsec_test_secret', $body, 1709000000), false, 1709000000);
        assertSameValue(500, $result->httpCode(), 'Failed commit/cleanup must never ACK or escape HTTP handling.');
    }
}

function test_magento_finisher_recovered_create_paid_response_remains_applicable()
{
    foreach (['paid', 'paid_over'] as $status) {
        // The first POST succeeded remotely but its response/local snapshot was
        // lost; Paymos returns the paid invoice on the same external-id retry.
        $invoices = new FakeInvoices(['invoice_id' => 'inv_123', 'payment_url' => 'https://paymos.test/pay/inv_123', 'status' => $status]);
        $snapshots = new InMemorySnapshotRepository();
        $checkout = new CheckoutProcessor(Config::fromArray(paymos_m2_generated_config()), $snapshots,
            static function () use ($invoices) { return new FakeClient($invoices); });
        $checkout->start(['order_id' => 42, 'increment_id' => '100000042', 'amount' => '100', 'currency' => 'USD'], 'sandbox');
        assertFalseValue(Paymos\Plugin\StatusMapper::isFinalStatus($snapshots->rows[0]['status']), 'Recovered create observation is not native application.');
        $gateway = new FakeOrderGateway();
        $invoice = paymos_f01_paid_invoice($snapshots->rows[0]);
        $invoice['status'] = $status;
        $reconciler = paymos_m2_reconciler(['gateway' => $gateway, 'snapshots' => $snapshots, 'invoice' => $invoice]);
        assertSameValue(1, $reconciler->run(), 'Recovered paid create remains eligible for native application.');
        assertSameValue(0, $reconciler->run(), 'Applied snapshot is now final.');
        assertSameValue(1, count($gateway->opsOfType('invoice')), 'Recovered create credits exactly once.');
        assertSameValue($status, $snapshots->rows[0]['status'], 'Final status follows native application.');
    }
}
