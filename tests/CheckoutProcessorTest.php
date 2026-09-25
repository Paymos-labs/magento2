<?php

declare(strict_types=1);

use Paymos\Payment\Service\CheckoutProcessor;
use Paymos\Payment\Service\Config;
use Paymos\Payment\Tests\FakeClient;
use Paymos\Payment\Tests\FakeInvoices;
use Paymos\Payment\Tests\InMemorySnapshotRepository;

function test_magento_checkout_creates_invoice_and_snapshot()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository();
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100',
        'currency' => 'usd',
        'customer_id' => 77,
    ), 'sandbox');

    assertSameValue('inv_123', $result['invoice_id'], 'The invoice id must be returned.');
    assertSameValue('https://paymos.test/pay/inv_123', $result['payment_url'], 'The payment URL must be returned.');
    assertFalseValue($result['reused'], 'A fresh invoice must not be flagged reused.');

    $payload = $invoices->createPayloads[0];
    assertSameValue('prj_123', $payload['project_id'], 'project_id must come from the credential config.');
    assertSameValue('100.00', $payload['amount'], 'Amount must be a dot-decimal 2dp string.');
    assertSameValue('USD', $payload['currency'], 'Currency must be upper-cased.');
    assertSameValue('100000042-0', $payload['external_order_id'], 'external_order_id must be increment id + renew suffix.');
    assertSameValue('77', $payload['client_id'], 'client_id must be the Magento customer id.');
    assertTrueValue($payload['allow_multiple_payments'], 'allow_multiple_payments must be true.');

    // Forbidden fields must never be sent.
    assertFalseValue(isset($payload['lifetime']), 'No lifetime field — TTL is server-side.');
    assertFalseValue(isset($payload['merchant_id']), 'merchant_id is never sent (derived from project_id).');
    assertFalseValue(isset($payload['return_url']), 'No return_url field.');
    assertFalseValue(isset($payload['webhook_url']), 'No webhook_url field.');

    assertSameValue(1, count($snapshots->rows), 'A snapshot row must be persisted.');
    assertSameValue('100.00', $snapshots->rows[0]['amount'], 'Snapshot amount must be stored for AmountGuard.');
}

function test_magento_checkout_omits_client_id_for_guest()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, new InMemorySnapshotRepository(), static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => null,
    ), 'sandbox');

    $payload = $invoices->createPayloads[0];
    assertFalseValue(isset($payload['client_id']), 'Guest checkout must omit client_id (never send the email).');
}

function test_magento_checkout_reuses_snapshot_when_amount_unchanged()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $invoices = new FakeInvoices();
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertTrueValue($result['reused'], 'A matching snapshot must be reused.');
    assertSameValue(0, count($invoices->createPayloads), 'Reuse must NOT create a second invoice.');
}

function test_magento_checkout_renews_invoice_when_amount_changes()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_renew',
        'payment_url' => 'https://paymos.test/pay/inv_renew',
        'status' => 'awaiting_client',
    ), array('invoice_id' => 'inv_123', 'status' => 'awaiting_client'));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '150.00', // cart changed
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'A changed amount must NOT reuse the old invoice.');
    assertSameValue('100000042-1', $invoices->createPayloads[0]['external_order_id'], 'A changed order must bump the renew suffix.');
    // BUG-166: the old invoice is cancelled on the server first, or the buyer
    // could pay both.
    assertSameValue(array('cancel inv_123', 'create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'the old invoice is cancelled before the new one is created.');
    assertSameValue('cancelled', $snapshots->rows[0]['status'], 'the old row records the cancel, so its cancelled webhook is ignored as stale.');
}

function test_magento_checkout_renews_an_invoice_that_expired_on_the_server()
{
    // BUG-090 (Magento): the buyer returns to the redirect after the Paymos
    // invoice's 30 minutes ran out. Same amount and currency, but the old link
    // leads to an expired checkout — a new invoice must be cut.
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_fresh',
        'payment_url' => 'https://paymos.test/pay/inv_fresh',
        'status' => 'awaiting_client',
    ), array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'awaiting_client',
        'expires_at' => time() - 3600,
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'an expired invoice must not be reused.');
    assertSameValue('https://paymos.test/pay/inv_fresh', $result['payment_url'], 'the buyer must get the fresh invoice.');
    assertSameValue('100000042-1', $invoices->createPayloads[0]['external_order_id'], 'the fresh invoice needs a new external order id.');
    // Still awaiting_client on the server (its expiry job has not run): it is
    // cancelled before the replacement.
    assertSameValue(array('get inv_123', 'cancel inv_123', 'create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'read, cancel, create.');
}

function test_magento_checkout_renews_without_a_lookup_when_the_invoice_is_already_final()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'cancelled'))));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_fresh',
        'payment_url' => 'https://paymos.test/pay/inv_fresh',
        'status' => 'awaiting_client',
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'sandbox');

    assertFalseValue($result['reused'], 'a cancelled invoice must not be reused.');
    assertSameValue(1, count($invoices->createPayloads), 'a fresh invoice must be created.');
    assertSameValue(array('create'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'a recorded final status needs neither a read nor a cancel.');
}

function test_magento_checkout_keeps_an_invoice_the_server_holds_open_past_the_old_deadline()
{
    // BUG-163: confirming a network moves expires_at on the server and sends
    // no webhook. Only the server's answer decides; an open invoice is kept.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting') as $status) {
        $config = Config::fromArray(paymos_m2_generated_config());
        $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
        $invoices = new FakeInvoices(array(
            'invoice_id' => 'inv_second',
            'payment_url' => 'https://paymos.test/pay/inv_second',
            'status' => 'awaiting_client',
        ), array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_final' => false,
            'expires_at' => time() - 3600,
        ));
        $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
            return new FakeClient($invoices);
        });

        $result = $processor->start(array(
            'order_id' => 42,
            'increment_id' => '100000042',
            'amount' => '100.00',
            'currency' => 'USD',
            'customer_id' => 77,
        ), 'sandbox');

        assertTrueValue($result['reused'], $status . ': the open invoice is reused.');
        assertSameValue('https://paymos.test/pay/inv_123', $result['payment_url'], $status . ': the buyer returns to the same invoice.');
        assertSameValue(0, count($invoices->createPayloads), $status . ': no second invoice is created.');
    }
}

function paymos_m2_changed_order()
{
    return array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '150.00',
        'currency' => 'USD',
        'customer_id' => 77,
    );
}

function paymos_m2_start_expecting_block(CheckoutProcessor $processor, array $order, $environment = 'sandbox')
{
    try {
        $processor->start($order, $environment);
    } catch (\Paymos\Plugin\InvoiceReplacementBlockedException $e) {
        return $e;
    }

    throw new RuntimeException('checkout must refuse to replace an invoice it cannot prove closed.');
}

function test_magento_checkout_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    // BUG-166: only awaiting_client is cancellable on the server. A network
    // picked, funds confirming, a part paid or a full payment keeps the old
    // invoice; no second one is cut.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $config = Config::fromArray(paymos_m2_generated_config());
        $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
        $invoices = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => $status));
        $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
            return new FakeClient($invoices);
        });

        $e = paymos_m2_start_expecting_block($processor, paymos_m2_changed_order());

        assertSameValue($status, $e->result()->status(), $status . ': the blocking status is reported.');
        assertSameValue(array('cancel inv_123', 'get inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), $status . ': cancel, read, and no create.');
        assertSameValue('awaiting_client', $snapshots->rows[0]['status'], $status . ': an open or paid status read here is not recorded.');
        assertSameValue(1, count($snapshots->rows), $status . ': no new snapshot row.');
    }
}

function test_magento_checkout_does_not_replace_an_invoice_the_server_answers_404_for()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices();
    $invoices->cancelException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    $e = paymos_m2_start_expecting_block($processor, paymos_m2_changed_order());

    assertSameValue(\Paymos\Plugin\InvoiceReplacementResult::REASON_NOT_FOUND, $e->result()->reason(), 'the 404 is the reason.');
    assertSameValue(array('cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'no invoice is created after a 404.');
}

function test_magento_checkout_does_not_renew_when_the_lookup_answers_404()
{
    // Same amount; before BUG-166 a 404 on the read cut a new invoice.
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $invoices = new FakeInvoices();
    $invoices->getException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $invoices->cancelException = \Paymos\Payment\Tests\paymos_m2_api_error(404, 'not_found', 'Invoice not found.');
    $processor = new CheckoutProcessor($config, $snapshots, static function () use ($invoices) {
        return new FakeClient($invoices);
    });

    paymos_m2_start_expecting_block($processor, array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ));

    assertSameValue(array('get inv_123', 'cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($invoices), 'read, cancel attempt, and no create.');
}

function test_magento_checkout_cancels_the_old_invoice_in_its_own_environment()
{
    $config = Config::fromArray(paymos_m2_generated_config());
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot(array('status' => 'awaiting_client'))));
    $sandbox = new FakeInvoices(array(), array('invoice_id' => 'inv_123', 'status' => 'awaiting_client'));
    $live = new FakeInvoices(array(
        'invoice_id' => 'inv_live',
        'payment_url' => 'https://paymos.test/pay/inv_live',
        'status' => 'awaiting_client',
    ));
    $processor = new CheckoutProcessor($config, $snapshots, static function ($environment) use ($sandbox, $live) {
        return new FakeClient($environment === 'live' ? $live : $sandbox);
    });

    $result = $processor->start(array(
        'order_id' => 42,
        'increment_id' => '100000042',
        'amount' => '100.00',
        'currency' => 'USD',
        'customer_id' => 77,
    ), 'live');

    assertSameValue('inv_live', $result['invoice_id'], 'the live invoice is issued.');
    assertSameValue(array('cancel inv_123'), \Paymos\Payment\Tests\paymos_m2_calls($sandbox), 'the sandbox invoice is cancelled with sandbox credentials.');
    assertSameValue(array('create'), \Paymos\Payment\Tests\paymos_m2_calls($live), 'only the create goes to live.');
}
