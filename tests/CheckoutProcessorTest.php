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
    $snapshots = new InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $invoices = new FakeInvoices(array(
        'invoice_id' => 'inv_renew',
        'payment_url' => 'https://paymos.test/pay/inv_renew',
        'status' => 'awaiting_client',
    ));
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
}
