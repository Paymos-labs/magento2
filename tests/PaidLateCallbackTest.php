<?php

declare(strict_types=1);

use Paymos\Payment\Service\OrderMapper;
use Paymos\Payment\Service\WebhookProcessor;
use Paymos\Payment\Tests\FakeOrderGateway;
use Paymos\Payment\Tests\InMemorySnapshotRepository;
use Paymos\Webhook\InMemoryEventStore;

// invoice-late-payment: a paid_late event on an invoice whose snapshot holds a FINAL status
// (expired) produces exactly ONE status-history comment — never an invoice, state or payment
// action — and a sequential duplicate (distinct event id) stays silent.

function paymos_m2_late_processor(array $opts = array())
{
    $config = Paymos\Payment\Service\Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'expired'))));
    $eventStore = isset($opts['eventStore']) ? $opts['eventStore'] : new InMemoryEventStore(static function () {
        return 1709000000;
    });
    $gateway = isset($opts['gateway']) ? $opts['gateway'] : new FakeOrderGateway();

    $processor = new WebhookProcessor(
        $config,
        $snapshots,
        $eventStore,
        new OrderMapper($gateway, paymos_m2_settings(array('payment/paymos/paid_order_status' => 'processing'))),
        static function () use ($opts) {
            return paymos_m2_reverse_client(isset($opts['invoice']) ? $opts['invoice'] : array());
        }
    );

    return array('processor' => $processor, 'gateway' => $gateway, 'snapshots' => $snapshots);
}

function paymos_m2_late_body($eventId)
{
    return json_encode(paymos_m2_invoice_event($eventId, 'invoice.paid_late', 'expired', array(
        'data' => array('paid_late_at' => 1777978800),
    )));
}

function test_magento_paid_late_comments_once_and_changes_nothing()
{
    $ctx = paymos_m2_late_processor();
    $body = paymos_m2_late_body('evt_late_1');
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, 1709000000);

    $result = $ctx['processor']->handle($body, $signature, true, 1709000000);

    $comments = $ctx['gateway']->opsOfType('comment');
    assertSameValue(1, count($comments), 'paid_late must add exactly one status-history comment.');
    assertSameValue(42, $comments[0]['order_id'], 'the comment belongs to the snapshot order.');
    assertTrueValue(
        strpos($comments[0]['comment'], 'finished paying for this order after the invoice closed') !== false,
        'the comment names the late fact.'
    );
    assertSameValue(0, count($ctx['gateway']->opsOfType('invoice')), 'paid_late must never invoice the order.');
    assertSameValue(0, count($ctx['gateway']->opsOfType('status')), 'paid_late must never change the order status.');
    assertTrueValue(
        $ctx['gateway']->hasPaymentFlag(42, 'paymos_paid_late:inv_123'),
        'the marker lands on the order payment KV.'
    );
    assertSameValue('evt_late_1', $ctx['gateway']->paymentFlags[42]['paymos_paid_late:inv_123'], 'marker records the event id.');
    // The snapshot store is INSERT-only — the note must NOT add a phantom snapshot row.
    assertSameValue(1, count($ctx['snapshots']->rows), 'no extra snapshot row is written.');
    assertTrueValue($result->httpCode() === 200 || $result->httpCode() === 204, 'the note outcome acknowledges.');
}

function test_magento_paid_late_sequential_duplicate_is_silent()
{
    $eventStore = new InMemoryEventStore(static function () {
        return 1709000000;
    });
    $ctx = paymos_m2_late_processor(array('eventStore' => $eventStore));

    foreach (array('evt_late_1', 'evt_late_2') as $eventId) {
        $body = paymos_m2_late_body($eventId);
        $ctx['processor']->handle($body, paymos_m2_signed_header('whsec_test_secret', $body, 1709000000), true, 1709000000);
    }

    assertSameValue(1, count($ctx['gateway']->opsOfType('comment')), 'a sequential duplicate must not comment twice.');
    assertSameValue(0, count($ctx['gateway']->opsOfType('invoice')), 'duplicates never invoice.');
}
