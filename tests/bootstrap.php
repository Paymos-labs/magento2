<?php

declare(strict_types=1);

/**
 * Test bootstrap for the Paymos Magento 2 module.
 *
 * Runs under plain php:7.4-cli with NO Magento install: the few Magento symbols
 * the crypto-critical Service classes touch (ResourceConnection + two DB
 * exceptions) are stubbed below, and the SDK is autoloaded from the sibling
 * php-sdk checkout (or PAYMOS_SDK_SRC). Crypto-critical logic is exercised
 * through plain stubs of the module's own interfaces.
 */

error_reporting(E_ALL);

define('PAYMOS_M2_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PAYMOS_M2_MODULE_DIR', PAYMOS_M2_PLUGIN_DIR . 'app/code/Paymos/Payment/');

// ── Autoload the module's own classes (Paymos\Payment\…) ────────────────────
spl_autoload_register(static function ($class) {
    $prefix = 'Paymos\\Payment\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = PAYMOS_M2_MODULE_DIR . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// ── Autoload the Paymos PHP SDK (Paymos\… but NOT Paymos\Payment\…) ──────────
spl_autoload_register(static function ($class) {
    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) !== 0) {
        return;
    }
    if (strncmp($class, 'Paymos\\Payment\\', strlen('Paymos\\Payment\\')) === 0) {
        return; // handled by the module autoloader above
    }

    $relative = substr($class, strlen($sdkPrefix));
    $candidates = array(
        PAYMOS_M2_MODULE_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        getenv('PAYMOS_SDK_SRC')
            ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
            : null,
        dirname(rtrim(PAYMOS_M2_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
    );
    foreach ($candidates as $candidate) {
        if ($candidate !== null && is_file($candidate)) {
            require $candidate;
            return;
        }
    }
});

// ── Minimal Magento stubs (only what the Service classes reference) ──────────
require __DIR__ . '/stubs/magento.php';

// ── Test doubles ────────────────────────────────────────────────────────────
require __DIR__ . '/stubs/doubles.php';

// ── Assertions ──────────────────────────────────────────────────────────────
function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message . ' Expected true, got ' . var_export($actual, true));
    }
}

function assertFalseValue($actual, $message)
{
    if ($actual !== false) {
        throw new RuntimeException($message . ' Expected false, got ' . var_export($actual, true));
    }
}

function assertContainsValue($needle, $haystack, $message)
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . ' in ' . var_export($haystack, true));
    }
}

// ── Webhook signing + payload helpers ───────────────────────────────────────

function paymos_m2_signed_header($secret, $body, $timestamp)
{
    return 't=' . (int) $timestamp . ',v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $body, (string) $secret);
}

/**
 * A REAL Paymos\Client over a MockTransport returning the given invoice GET
 * response. InvoiceReverseVerifier type-hints the concrete Client, so a fake
 * object will not do — the SDK must drive a mock HTTP layer.
 *
 * @param array<string, mixed> $invoice
 */
function paymos_m2_reverse_client(array $invoice = array())
{
    $invoice = $invoice ?: array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array(
            // Server trims trailing zeros ("100.00" -> "100"); snapshot is
            // "100.00". Reverse-verify must treat them equal.
            'external_id' => '100000042-0',
            'amount' => '100',
            'currency' => 'USD',
        ),
    );

    return new \Paymos\Client(
        new \Paymos\ClientConfig('pk_test_abc', 'sk_test_abc', 'https://api.paymos.local', 30),
        new \Paymos\Http\MockTransport(array(
            new \Paymos\Http\HttpResponse(200, json_encode($invoice), array()),
        )),
        static function () {
            return 1709000000;
        }
    );
}

/**
 * @return array<string, mixed>
 */
function paymos_m2_generated_config()
{
    return array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'base_url' => 'https://api.paymos.local',
                'api_key' => 'pk_test_abc',
                'api_secret' => 'sk_test_abc',
                'project_id' => 'prj_123',
                'webhook_secret' => 'whsec_test_secret',
            ),
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_abc',
                'api_secret' => 'sk_live_abc',
                'project_id' => 'prj_live',
                'webhook_secret' => 'whsec_live_secret',
            ),
        ),
    );
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function paymos_m2_invoice_event($eventId, $eventType, $status, array $overrides = array())
{
    return array_replace_recursive(array(
        'event_id' => $eventId,
        'event_type' => $eventType,
        'version' => 1,
        'occurred_at' => 1709000000,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => $status,
            'is_test' => true,
            'order' => array(
                'external_id' => '100000042-0',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ), $overrides);
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function paymos_m2_snapshot(array $overrides = array())
{
    return array_merge(array(
        'order_id' => 42,
        'order_increment_id' => '100000042',
        'external_order_id' => '100000042-0',
        'paymos_invoice_id' => 'inv_123',
        'environment' => 'sandbox',
        'project_id' => 'prj_123',
        'amount' => '100.00',
        'currency' => 'USD',
        'payment_url' => 'https://paymos.test/pay/inv_123',
        'status' => 'awaiting_payment',
        'renew_count' => 0,
    ), $overrides);
}
