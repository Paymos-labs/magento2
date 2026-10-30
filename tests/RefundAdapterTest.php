<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/refund.php';

use Paymos\Payment\Service\RefundAdapter;
use Paymos\Payment\Service\RefundIntentStore;
use Paymos\Payment\Service\RefundNativeGateway;
use Paymos\Payment\Tests\RefundDb;
use Paymos\Payment\Tests\RefundResource;
use Paymos\Payment\Tests\RefundCredentials;
use Paymos\Payment\Tests\RefundClients;
use Paymos\Payment\Tests\RefundNative;
use Paymos\Plugin\RefundIntent;

class MagentoRefundTransport implements \Paymos\Http\TransportInterface
{
    public $store;
    public $db;
    public $requests = [];
    public $withdrawals = [];
    public $timeout = false;
    public $loseLock = false;
    public $invoice;
    public $onPost;

    public function __construct($store, $db)
    {
        $this->store = $store;
        $this->db = $db;
        $this->invoice = ['invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true,
            'order' => ['external_id' => '100000042-0', 'amount' => '100', 'currency' => 'USD'],
            'refund_summary' => ['can_refund_full' => true, 'can_refund_overpayment' => true,
                'full_refund_amount' => '120', 'overpayment_refund_amount' => '20']];
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        assertSameValue(0, $this->db->level, 'HTTP outside database transaction');
        assertContainsValue('HMAC-SHA256', $headers['Authorization'] ?? '', 'SDK signs every request');
        $this->requests[] = ['method' => $method, 'path' => parse_url($url, PHP_URL_PATH), 'body' => $body];
        $path = parse_url($url, PHP_URL_PATH);
        if ($method === 'POST') {
            $data = json_decode($body, true);
            assertSameValue(['invoice_id', 'mode', 'external_order_id'], array_keys($data), 'Only contract fields enter POST');
            $intent = $this->store->findByExternalId($data['external_order_id']);
            assertTrueValue($intent !== null, 'Intent exists before HTTP');
            assertSameValue('unknown', $intent->cashState(), 'Uncertainty committed before HTTP');
            assertTrueValue($this->db->commits >= 2, 'Intent and uncertainty committed separately');
            $id = 'wdr_' . substr(hash('sha256', $data['external_order_id']), 0, 12);
            if (!isset($this->withdrawals[$id])) {
                $this->withdrawals[$id] = ['withdrawal_id' => $id, 'invoice_id' => $data['invoice_id'],
                    'project_id' => 'prj_123', 'is_test' => true, 'mode' => $data['mode'], 'external_order_id' => $data['external_order_id'],
                    'amount' => $data['mode'] === 'overpayment' ? '20' : $this->invoice['refund_summary']['full_refund_amount'],
                    'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'sender-A', 'status' => 'created'];
            }
            if ($this->onPost !== null) { call_user_func($this->onPost, $intent); }
            if ($this->timeout) { throw new RuntimeException('Response lost after provider commit'); }
            $wire = ['withdrawal_id' => $id];
        } elseif (strpos($path, '/v1/invoices/') === 0) {
            $wire = $this->invoice;
        } else {
            $id = basename($path);
            $wire = $this->withdrawals[$id];
        }
        if ($this->loseLock) { $this->db->owned = false; }
        return new \Paymos\Http\HttpResponse(200, json_encode($wire), ['Cache-Control' => 'private, no-store']);
    }
}

function magento_refund_fixture()
{
    $db = new RefundDb();
    $time = new DateTimeImmutable('2026-10-28T00:00:00Z');
    $store = new RefundIntentStore(new RefundResource($db), static function () use (&$time) { return $time; });
    $transport = new MagentoRefundTransport($store, $db);
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $transport);
    $native = new RefundNative($store, $db);
    $credentials = new RefundCredentials();
    return [$db, $store, $transport, $native, $credentials,
        new RefundAdapter($store, $native, $credentials, new RefundClients($client)), &$time];
}

function test_magento_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    list($db, $store, $http, $native) = magento_refund_fixture();
    paymos_cms_ton_pin_contract($store, 'magento2', $store->installationId(), $native->paymentKey(42));
}

function magento_refund_throws(callable $action, $reason = '')
{
    try { $action(); } catch (RuntimeException | InvalidArgumentException $error) {
        if ($reason !== '') { assertContainsValue($reason, $error->getMessage(), 'Refusal reason'); }
        return;
    }
    throw new RuntimeException('Expected refusal');
}

function magento_refund_status($transport, $intent, $status)
{
    $transport->withdrawals[$intent->withdrawalId()]['status'] = $status;
}

function test_magento_refund_over20_then_full100_and_reorg()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $over = $adapter->submit(42, 'overpayment', 'request-over-0001');
    assertSameValue(0, $db->native['notes'], 'Accepted is not native completion');
    magento_refund_status($http, $over, 'completed');
    assertSameValue('apply_completed', $adapter->reconcile($over->operationId()), 'Verified overpayment applies');
    assertSameValue(['documents' => 0, 'notes' => 1, 'refunded' => '0', 'stock' => 0, 'state' => 'processing'], $db->native, 'Cash-only note preserves paid order and stock');
    assertSameValue(0, $native->mail, 'Overpayment bypasses full refund mail');
    $http->invoice['refund_summary']['full_refund_amount'] = '100';
    $full = $adapter->submit(42, 'full', 'request-full-0001', ['send_email' => '1', 'restock' => '1'], 7);
    assertSameValue('100', $full->observation()->principalDecimal(), 'Server computes remaining full principal');
    magento_refund_status($http, $over, 'signed');
    $adapter->reconcile($over->operationId());
    assertSameValue('created', $store->getIntent($full->operationId())->cashState(), 'Old over callback does not overwrite full intent');
    magento_refund_status($http, $full, 'completed');
    $adapter->reconcile($full->operationId());
    assertSameValue(1, $db->native['documents'], 'One original native full document');
    assertSameValue('1000000', $db->native['refunded'], 'Native accounting is original 100 at scale4');
    assertSameValue(1, $db->native['stock'], 'Saved restock choice applied once');
    assertSameValue(1, $native->mail, 'Full mail attempted once after commit');
    magento_refund_status($http, $full, 'signed');
    $adapter->reconcile($full->operationId());
    assertSameValue('native_review', $store->getIntent($full->operationId())->projectionState(), 'Reorg keeps historical document and cash warning');
    magento_refund_status($http, $full, 'completed');
    assertSameValue('resume_recovery', $adapter->reconcile($full->operationId()), 'Recompletion recovers exact marker');
    assertSameValue(1, $db->native['documents'], 'No second financial record');
    assertSameValue(1, $db->native['stock'], 'No second stock effect');
    assertSameValue(1, $native->mail, 'No second email');
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'overpayment', 'request-over-0002'); });
}

function test_magento_refund_unknown_reuses_committed_key_and_rejects_mode_switch()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $http->timeout = true;
    $intent = $adapter->submit(42, 'full', 'request-full-0001', []);
    assertSameValue('unknown', $intent->cashState(), 'Lost response is unknown');
    $key = $intent->externalOrderId();
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'overpayment', 'request-full-0001'); }, 'conflict');
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'full', 'request-full-0001', ['send_email' => 1]); }, 'conflict');
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'overpayment', 'request-over-0002'); });
    $credentials->enabled = false;
    $credentials->capable = false;
    $http->timeout = false;
    $result = $adapter->resume(42, 'request-full-0001');
    assertSameValue($key, $result->externalOrderId(), 'Unknown replay ignores new submission gate');
    assertSameValue(1, count($http->withdrawals), 'Same key returns one Withdrawal');
    $posts = array_values(array_filter($http->requests, static function ($r) { return $r['method'] === 'POST'; }));
    assertSameValue($posts[0]['body'], $posts[1]['body'], 'Unknown replay payload is byte-identical');
    $contract = json_decode(file_get_contents(getenv('PAYMOS_CONFORMANCE_CONTRACT') ?: dirname(__DIR__, 2) . '/sdk-conformance/contract.json'), true)['vectors']['refunds'];
    assertSameValue($contract['create']['path'], $posts[0]['path'], 'Canonical server refund route');
    assertSameValue(array_keys($contract['create']['body']), array_keys(json_decode($posts[0]['body'], true)), 'Canonical mode-only body shape');
}

function test_magento_refund_guards_prevent_post()
{
    foreach (['partial', 'capability', 'disabled', 'boundary', 'draft'] as $case) {
        list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
        if ($case === 'partial') { $native->partial = true; }
        if ($case === 'capability') { $credentials->capable = false; }
        if ($case === 'disabled') { $credentials->enabled = false; }
        if ($case === 'boundary') { $native->boundary = false; }
        magento_refund_throws(static function () use ($adapter, $case) {
            $adapter->submit(42, 'full', 'request-full-0001', $case === 'draft' ? ['partial' => true] : []);
        });
        assertSameValue([], $http->requests, 'Invalid native/capability input never enters HTTP');
    }
}

function test_magento_refund_server_disabled_summary_and_mapping_block_post()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $http->invoice['refund_summary']['can_refund_full'] = false;
    $intent = $adapter->submit(42, 'full', 'request-full-0001', []);
    assertSameValue('review', $intent->cashState(), 'Server refuses unavailable action');
    assertSameValue(['GET'], array_column($http->requests, 'method'), 'Only fresh server preflight GET');
}

function test_magento_refund_binding_and_money_are_write_once()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', []);
    $id = $intent->withdrawalId();
    $http->withdrawals[$id]['amount'] = '119';
    $http->withdrawals[$id]['status'] = 'completed';
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Changed server principal requires review');
    assertSameValue(0, $db->native['documents'], 'Changed evidence cannot finalize');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent, $id) {
        assertSameValue('same', $store->bindWithdrawal($intent->operationId(), $id), 'Repeated binding');
        assertSameValue('conflict', $store->bindWithdrawal($intent->operationId(), 'wdr_other'), 'Binding cannot change');
        assertSameValue($id, $store->getIntent($intent->operationId())->withdrawalId(), 'Original provider ID retained');
    });
}

function test_magento_refund_native_crash_rolls_back_then_marker_recovers()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', []);
    magento_refund_status($http, $intent, 'completed');
    $native->throwAfterWrite = true;
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Interrupted native transaction requires recovery');
    assertSameValue(0, $db->native['documents'], 'Same-connection mutation rolled back with marker');
    $native->throwAfterWrite = false;
    assertSameValue('apply_completed', $adapter->reconcile($intent->operationId()), 'Safe rollback permits completion');
    // Simulate loss of the coordinator link after the native marker commit.
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) {
        $store->recordState($intent->operationId(), 'completed', 'native_review', 'native_projection_unknown');
    });
    assertSameValue('resume_recovery', $adapter->reconcile($intent->operationId()), 'Exact native marker repairs coordinator state');
    assertSameValue(1, $db->native['documents'], 'Recovery does not repeat the native write');
}

function test_magento_refund_ambiguous_marker_and_mail_unknown_do_not_repeat()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', ['send_email' => 1]);
    magento_refund_status($http, $intent, 'completed');
    $native->ambiguous = true;
    assertSameValue('show_review', $adapter->reconcile($intent->operationId()), 'Partial marker refuses second write');
    assertSameValue(0, $db->native['documents'], 'Ambiguous evidence does not create a document');
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', ['send_email' => 1]);
    magento_refund_status($http, $intent, 'completed');
    $native->throwMail = true;
    $adapter->reconcile($intent->operationId());
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, $native->mail, 'Unknown SMTP acknowledgement never auto resends');
    assertSameValue('unknown', $store->getIntent($intent->operationId())->toArray()['effects']['creditmemo_email:1'], 'Mail unknown is explicit');
}

function test_magento_refund_signed_callback_scope_and_fresh_reads()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'overpayment', 'request-over-0001');
    $event = ['event_id' => 'evt_refund', 'event_type' => 'withdrawal.completed', 'version' => 1, 'occurred_at' => time(),
        'data' => ['withdrawal_id' => $intent->withdrawalId(), 'external_order_id' => $intent->externalOrderId(),
            'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'refund_mode' => 'overpayment', 'amount' => '999']];
    $config = \Paymos\Payment\Service\Config::fromArray(paymos_m2_generated_config());
    $events = new \Paymos\Webhook\InMemoryEventStore();
    $mapper = new \Paymos\Payment\Service\OrderMapper(new \Paymos\Payment\Tests\FakeOrderGateway(), paymos_m2_settings());
    $processor = new \Paymos\Payment\Service\WebhookProcessor($config,
        new \Paymos\Payment\Tests\InMemorySnapshotRepository(), $events, $mapper, null, $adapter);
    $body = json_encode($event);
    $signature = paymos_m2_signed_header('whsec_test_secret', $body, time());
    assertSameValue(401, $processor->handle($body, 'invalid', false)->httpCode(), 'Signature gate precedes refund dispatch');
    $before = count($http->requests);
    assertSameValue(200, $processor->handle($body, $signature, false)->httpCode(), 'Signed callback handled');
    assertSameValue(0, $db->native['notes'], 'Event completed cannot override primary GET created');
    assertSameValue(2, count($http->requests) - $before, 'Fresh refund and invoice GET');
    $event['event_id'] = 'evt_wrong_project';
    $event['data']['project_id'] = 'prj_foreign';
    $body = json_encode($event);
    assertSameValue(400, $processor->handle($body, paymos_m2_signed_header('whsec_test_secret', $body, time()), false)->httpCode(), 'Cross-project callback rejected');
}

function test_magento_refund_lock_loss_and_outer_transaction_fail_closed()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $db->beginTransaction();
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'full', 'request-full-0001', []); }, 'outer_transaction');
    $db->rollBack();
    $http->onPost = static function ($intent) use ($db) { $db->owned = false; };
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'full', 'request-full-0001', []); }, 'lock_lost');
    assertSameValue(0, $db->native['documents'], 'Lost lock forbids native effects');
    assertSameValue('unknown', json_decode($db->tables['paymos_refund_intent'][0]['payload'], true)['cash_state'], 'Lost lock cannot record a verified result');
}

function test_magento_refund_failed_cancelled_allow_only_new_explicit_token()
{
    foreach (['failed', 'cancelled'] as $status) {
        list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
        $intent = $adapter->submit(42, 'overpayment', 'request-over-0001');
        magento_refund_status($http, $intent, $status);
        $adapter->reconcile($intent->operationId());
        $again = $adapter->submit(42, 'overpayment', 'request-over-0001');
        assertSameValue($intent->withdrawalId(), $again->withdrawalId(), 'Terminal retry keeps old operation');
        $new = $adapter->submit(42, 'full', 'request-full-0002', []);
        assertTrueValue($new->operationId() !== $intent->operationId(), 'New explicit action creates a different intent');
        assertSameValue(0, $db->native['documents'], 'Failed/cancelled do not finalize');
    }
}

function test_magento_refund_store_token_payload_scheduling_and_effect_claims()
{
    $fixture = magento_refund_fixture();
    list($db, $store, $http, $native, $credentials, $adapter) = $fixture;
    $intent = $adapter->submit(42, 'overpayment', 'request-over-0001');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) {
        $copy = $intent->toArray();
        $copy['operation_id'] = RefundIntentStore::uuid();
        unset($copy['external_order_id'], $copy['payload_hash']);
        assertSameValue($intent->operationId(), $store->createIntent(new RefundIntent($copy))->operationId(), 'Same token and immutable payload returns existing');
        $copy['mode'] = 'full';
        $copy['full_draft'] = ['amount_minor' => '1000000', 'currency' => 'USD'];
        magento_refund_throws(static function () use ($store, $copy) { $store->createIntent(new RefundIntent($copy)); }, 'conflict');
    });
    assertSameValue([], $store->listDue(new DateTimeImmutable('2026-10-28T00:00:59Z'), 100, null)->items(), 'First backoff minute');
    assertSameValue(1, count($store->listDue(new DateTimeImmutable('2026-10-28T00:01:00Z'), 100, null)->items()), 'Due at first minute');
    magento_refund_throws(static function () use ($store) { $store->listDue(new DateTimeImmutable(), 100, 'invalid'); });
    assertSameValue('123400', RefundNativeGateway::units('12.3400'), 'Exact native decimal scale4');
    magento_refund_throws(static function () { RefundNativeGateway::units('1.00001'); });
}

function magento_refund_inject($class, array $properties)
{
    $type = new ReflectionClass($class);
    $object = $type->newInstanceWithoutConstructor();
    foreach ($properties as $name => $value) { $type->getProperty($name)->setValue($object, $value); }
    return $object;
}

function test_magento_refund_native_entry_checks_acl_form_key_and_intercepts_save()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $request = new class {
        public $post = true;
        public $draft = [];
        public $data = ['order_id' => '42', 'invoice_id' => '7', 'paymos_refund_token' => 'request-full-0001'];
        public function isPost() { return $this->post; }
        public function getParam($key) { return $this->data[$key] ?? null; }
        public function getPost($key) { return $this->draft; }
    };
    $auth = new class { public $allowed = true; public function isAllowed($resource) { return $this->allowed; } };
    $form = new class { public $valid = true; public function validate($request) { return $this->valid; } };
    $redirects = new class {
        public function create() { return $this; }
        public function setPath($path, $params) { return ['path' => $path, 'params' => $params]; }
    };
    $messages = new class {
        public $notices = [];
        public function addErrorMessage($text) { $this->notices[] = (string) $text; }
        public function addNoticeMessage($text) { $this->notices[] = (string) $text; }
    };
    $logger = new class extends \Paymos\Payment\Service\Logger {
        public $warnings = [];
        public function warning($text, array $context = []) { $this->warnings[] = $context; }
    };
    $entry = magento_refund_inject(\Paymos\Payment\Plugin\RefundEntry::class, ['request' => $request,
        'authorization' => $auth, 'formKey' => $form, 'redirects' => $redirects, 'adapter' => $adapter, 'native' => $native,
        'messages' => $messages, 'logger' => $logger]);
    $proceeded = 0;
    $proceed = static function () use (&$proceeded) { $proceeded++; return 'native'; };
    foreach (['post', 'allowed', 'valid'] as $field) {
        $target = $field === 'post' ? $request : ($field === 'allowed' ? $auth : $form);
        $target->$field = false;
        $response = $entry->aroundExecute(null, $proceed);
        assertSameValue('adminhtml/system_config/edit', $response['path'], 'Access refusal returns to panel');
        assertSameValue('refund_access_denied', end($logger->warnings)['reason'], 'Access code stays in log');
        assertContainsValue('Magento did not save this credit memo.', end($messages->notices), 'Access refusal uses existing readable text');
        $target->$field = true;
    }
    foreach (['missing', 'existing'] as $case) {
        $request->draft = $case === 'missing' ? null : [];
        $request->data['creditmemo_id'] = $case === 'existing' ? '77' : null;
        $response = $entry->aroundExecute(null, $proceed);
        assertSameValue('adminhtml/system_config/edit', $response['path'], 'Invalid draft returns to panel');
        assertSameValue('refund_native_new_draft_required', end($logger->warnings)['reason'], 'Draft code stays in log');
        assertContainsValue('Magento did not save this credit memo.', end($messages->notices), 'Draft refusal is readable');
    }
    $request->draft = [];
    unset($request->data['creditmemo_id']);
    foreach ($messages->notices as $notice) {
        assertFalseValue(strpos($notice, 'refund_') !== false, 'No raw refusal code reaches admin');
    }
    assertSameValue([], $http->requests, 'No HTTP for failed authorization');
    $response = $entry->aroundExecute(null, $proceed);
    assertSameValue(0, $proceeded, 'Native Save never runs for pending Paymos cash');
    assertSameValue(0, $db->native['documents'], 'Pending is not a credit memo');
    assertSameValue('adminhtml/system_config/edit', $response['path'], 'Pending returns to existing Paymos panel');
    assertContainsValue('paymos_refund_token', $entry->afterToHtml(null, '<div/>'), 'Native form carries stable request token');
    $request->data['order_id'] = '43';
    assertSameValue('native', $entry->aroundExecute(null, $proceed), 'Other payment methods retain native Save');
}

function test_magento_refund_guard_blocks_direct_native_and_scopes_mail()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    magento_refund_native_rows($db);
    $gateway = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store]);
    $guard = new \Paymos\Payment\Plugin\RefundGuard($gateway);
    $memo = new class {
        public $method = 'paymos';
        public $marker = 'operation';
        public $orderId = 42;
        public function getOrderId() { return $this->orderId; }
        public function getOrder() { return $this; }
        public function getPayment() { return $this; }
        public function getMethod() { return $this->method; }
        public function getId() { return 77; }
        public function getData($key) { return $this->marker; }
    };
    $called = 0;
    $proceed = static function ($m, $flag) use (&$called) { $called++; return $flag; };
    magento_refund_throws(static function () use ($guard, $proceed, $memo) { $guard->aroundRefund(null, $proceed, $memo, true); }, 'paid with Paymos');
    assertSameValue(0, $called, 'Direct service bypass refuses before native financial write');
    assertSameValue(false, $guard->aroundSend(null, $proceed, $memo), 'Queued native mail suppressed outside claimed effect');
    (new ReflectionProperty(RefundNativeGateway::class, 'sending'))->setValue($gateway, '77');
    assertSameValue(true, $guard->aroundSend(null, $proceed, $memo, true), 'Claimed worker may force synchronous send');
    $memo->marker = null;
    assertSameValue(false, $guard->aroundSend(null, $proceed, $memo, false), 'Ordinary mail delegates unchanged');
    (new ReflectionProperty(RefundNativeGateway::class, 'finalizing'))->setValue($gateway, $memo);
    assertSameValue(true, $guard->aroundRefund(null, $proceed, $memo, true), 'Exact offline projector context is permitted');
    assertSameValue(false, $gateway->permits(clone $memo, true), 'A forged operation marker is insufficient');
    assertSameValue(false, $gateway->permits($memo, false), 'Online native refund never permitted');
    $memo->method = 'other';
    $memo->orderId = 43;
    assertSameValue(false, $guard->aroundRefund(null, $proceed, $memo, false), 'Other gateway delegates native refund');
}

function magento_refund_native_rows(RefundDb $db): void
{
    $db->tables['sales_order_payment'] = [['entity_id' => 9, 'parent_id' => 42, 'method' => 'paymos']];
    $db->tables['paymos_payment_invoice'] = [paymos_m2_snapshot(['status' => 'paid'])];
    $db->tables['sales_order'] = [['entity_id' => 42, 'store_id' => 1, 'grand_total' => '100.0000', 'total_paid' => '100.0000',
        'base_grand_total' => '100.0000', 'base_total_paid' => '100.0000', 'total_refunded' => '0.0000',
        'base_total_refunded' => '0.0000', 'state' => 'processing', 'order_currency_code' => 'USD']];
    $db->tables['sales_invoice'] = [['entity_id' => 7, 'order_id' => 42, 'state' => 2, 'grand_total' => '100.0000',
        'base_grand_total' => '100.0000', 'base_total_refunded' => '0.0000']];
    $db->tables['sales_order_item'] = [['item_id' => 11, 'order_id' => 42, 'qty_ordered' => '1', 'qty_invoiced' => '1',
        'qty_refunded' => '0', 'qty_canceled' => '0', 'tax_amount' => '20.0000', 'row_total' => '80.0000']];
}

function test_magento_refund_actual_native_mapping_and_partial_guards()
{
    list($db, $store) = magento_refund_fixture();
    magento_refund_native_rows($db);
    $gateway = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store,
        'metadata' => new class { public function getVersion() { return '2.4.7-p3'; } }, 'scopeConfig' => new \Paymos\Payment\Tests\FakeScopeConfig()]);
    $store->withPaymentLock('magento2:42:9', static function () use ($gateway, $db) {
        $mapping = $gateway->mapping(42);
        assertSameValue('magento2:42:9', $mapping['payment_key'], 'Actual SQL native mapping');
        magento_refund_throws(static function () use ($gateway, $mapping) { $gateway->draft($mapping, ['items' => [11 => ['qty' => '0.5']]], 7); }, 'partial');
        magento_refund_throws(static function () use ($gateway, $mapping) { $gateway->draft($mapping, ['adjustment_positive' => '1'], 7); }, 'unsupported');
        magento_refund_throws(static function () use ($gateway, $mapping) { $gateway->draft($mapping, [], 8); }, 'invoice_mismatch');
        $db->tables['sales_order_item'][] = array_replace($db->tables['sales_order_item'][0], ['item_id' => 12]);
        magento_refund_throws(static function () use ($gateway, $mapping) { $gateway->draft($mapping, ['items' => [11 => ['qty' => '1']]], 7); }, 'partial');
        $db->tables['sales_order'][0]['total_refunded'] = '1.0000';
        magento_refund_throws(static function () use ($gateway) { $gateway->mapping(42); }, 'not_original_full');
        $db->tables['sales_order'][0]['total_refunded'] = '0.0000';
        $db->tables['sales_creditmemo'][] = ['order_id' => 42];
        magento_refund_throws(static function () use ($gateway) { $gateway->mapping(42); }, 'manual_creditmemo');
    });
}

function test_magento_refund_actual_inspect_rejects_orphan_and_changed_document()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', []);
    $gateway = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store]);
    $db->tables['sales_creditmemo'] = [['entity_id' => 8, 'order_id' => 42, 'paymos_refund_operation_id' => $intent->operationId()]];
    assertSameValue('ambiguous', $gateway->inspect($intent)->kind(), 'Orphan native credit memo is not absent');
    $db->tables['sales_creditmemo'] = [];
    $db->tables['sales_order_status_history'] = [['entity_id' => 9, 'parent_id' => 42, 'paymos_refund_operation_id' => $intent->operationId()]];
    assertSameValue('ambiguous', $gateway->inspect($intent)->kind(), 'Orphan native note is not absent');
    $db->tables['sales_order_status_history'] = [];
    assertSameValue('absent', $gateway->inspect($intent)->kind(), 'Clean native state is absent');
}

function test_magento_refund_callback_before_binding_recovers_same_key()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $http->timeout = true;
    $intent = $adapter->submit(42, 'overpayment', 'request-over-0001');
    $id = array_keys($http->withdrawals)[0];
    $http->withdrawals[$id]['status'] = 'completed';
    $http->timeout = false;
    $adapter->handleEvent(new \Paymos\Webhook\WebhookEvent(['event_type' => 'withdrawal.completed', 'data' => [
        'withdrawal_id' => $id, 'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true,
        'refund_mode' => 'overpayment', 'external_order_id' => $intent->externalOrderId()]]), 'sandbox');
    assertSameValue(1, count($http->withdrawals), 'Callback cannot introduce a new key');
    assertSameValue($id, $store->getIntent($intent->operationId())->withdrawalId(), 'Verified replay response establishes binding');
    assertSameValue(1, $db->native['notes'], 'Recovered completion applies once');
}

function test_magento_refund_unbound_full_is_review_and_over_is_cash_only()
{
    foreach (['full', 'overpayment'] as $mode) {
        list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
        $http->withdrawals['wdr_dashboard'] = ['withdrawal_id' => 'wdr_dashboard', 'invoice_id' => 'inv_123',
            'project_id' => 'prj_123', 'mode' => $mode, 'is_test' => true, 'external_order_id' => 'dashboard-operation',
            'status' => 'completed', 'amount' => '20', 'currency' => 'USDT', 'network' => 'TRC20', 'first_sender' => 'sender-A'];
        $event = new \Paymos\Webhook\WebhookEvent(['event_type' => 'withdrawal.completed', 'data' => [
            'withdrawal_id' => 'wdr_dashboard', 'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'refund_mode' => $mode]]);
        $adapter->handleEvent($event, 'sandbox');
        $adapter->handleEvent($event, 'sandbox');
        assertSameValue(0, $db->native['documents'], 'Unbound full never invents native draft');
        assertSameValue($mode === 'overpayment' ? 1 : 0, $db->native['notes'], 'Only verified unbound overpayment creates one note');
        assertSameValue('0', $db->native['refunded'], 'Unbound observation does not change native refunded');
        assertSameValue(['GET', 'GET', 'GET', 'GET'], array_column($http->requests, 'method'), 'Unbound recovery never POSTs');
        assertTrueValue($store->unbound('wdr_dashboard') !== null, 'Verified unbound cash is durable');
        if ($mode === 'full') {
            magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'full', 'request-full-0001', []); }, 'review');
        }
    }
}

function test_magento_refund_started_mail_crash_is_unknown_without_send()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $intent = $adapter->submit(42, 'full', 'request-full-0001', ['send_email' => 1]);
    magento_refund_status($http, $intent, 'completed');
    $coordinator = new \Paymos\Plugin\RefundCoordinator(new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $http), $store, $adapter);
    $coordinator->reconcile($intent->operationId());
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) {
        assertSameValue('claimed', $store->claimEffect($intent->operationId(), 'creditmemo_email', 1)->kind(), 'Started is committed before send');
    });
    $adapter->reconcile($intent->operationId());
    assertSameValue(0, $native->mail, 'Crash after claim cannot auto send');
    assertSameValue('unknown', $store->getIntent($intent->operationId())->toArray()['effects']['creditmemo_email:1'], 'Uncertain email is explicit');
}

function test_magento_refund_recovery_backoff_and_fair_cursor()
{
    $fixture = magento_refund_fixture();
    list($db, $store, $http, $native, $credentials, $adapter) = $fixture;
    for ($i = 0; $i < 205; $i++) {
        $key = 'magento2:' . (1000 + $i) . ':9';
        $store->withPaymentLock($key, static function () use ($store, $key, $i) {
            $store->createIntent(new RefundIntent(['operation_id' => RefundIntentStore::uuid(), 'request_token' => sprintf('request-page-%04d', $i),
                'installation_id' => $store->installationId(), 'platform' => 'magento2', 'native_payment_key' => $key,
                'invoice_id' => 'inv_page', 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'overpayment']));
        });
    }
    $now = new DateTimeImmutable('2026-10-28T00:00:00Z');
    $first = $store->listDue($now, 100, null);
    $second = $store->listDue($now, 100, $first->nextCursor());
    $third = $store->listDue($now, 100, $second->nextCursor());
    assertSameValue([100, 100, 5], [count($first->items()), count($second->items()), count($third->items())], 'Fair bounded pages');
    $ids = array_map(static function ($intent) { return $intent->operationId(); }, array_merge($first->items(), $second->items(), $third->items()));
    assertSameValue(205, count(array_unique($ids)), 'No duplicate or missing intents across cursor pages');
    assertSameValue(null, $third->nextCursor(), 'Last page has no cursor');
}

function test_magento_refund_reconciler_restores_committed_unsubmitted_intent()
{
    $fixture = magento_refund_fixture();
    list($db, $store, $http, $native, $credentials, $adapter) = $fixture;
    $store->withPaymentLock('magento2:42:9', static function () use ($store) {
        $store->createIntent(new RefundIntent(['operation_id' => RefundIntentStore::uuid(), 'request_token' => 'request-crash-001',
            'installation_id' => $store->installationId(), 'platform' => 'magento2', 'native_payment_key' => 'magento2:42:9',
            'invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'environment' => 'sandbox', 'mode' => 'overpayment']));
    });
    assertSameValue(1, $adapter->run(), 'Cron submits the authorized committed operation after process death');
    assertSameValue(1, count($http->withdrawals), 'Recovery uses original intent identity');
    assertSameValue(0, $db->native['documents'], 'Recovery of accepted cash is pending');
}

function test_magento_refund_backoff_and_unresolved_24h_review()
{
    $fixture = magento_refund_fixture();
    list($db, $store, $http, $native, $credentials, $adapter) = $fixture;
    $clock =& $fixture[6];
    $intent = $adapter->submit(42, 'overpayment', 'request-over-0001');
    foreach ([120, 300, 900, 3600, 3600] as $delay) {
        $clock = $clock->modify('+1 hour');
        $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
        assertSameValue($clock->getTimestamp() + $delay, $db->tables['paymos_refund_intent'][0]['next_due'], 'Bounded backoff schedule');
    }
    $clock = $clock->modify('+25 hours');
    $store->withPaymentLock($intent->paymentKey(), static function () use ($store, $intent) { $store->schedule($intent->operationId()); });
    $current = $store->getIntent($intent->operationId());
    assertSameValue('created', $current->cashState(), 'Review does not invent provider failure');
    assertSameValue('native_review', $current->projectionState(), 'Unresolved operation older than 24h requires review');
    assertSameValue('refund_unresolved_24h', $current->toArray()['review_reason'], 'Explicit age review reason');
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'overpayment', 'request-over-0002'); });
}

function test_magento_refund_production_projector_calls_offline_service_and_recovers_marker()
{
    list($db, $store, $http, $unused, $credentials) = magento_refund_fixture();
    magento_refund_native_rows($db);
    $order = new \Paymos\Payment\Tests\RefundModel($db->tables['sales_order'][0] + ['status' => 'processing'], $db);
    $invoice = new \Paymos\Payment\Tests\RefundModel($db->tables['sales_invoice'][0]);
    $factory = new \Paymos\Payment\Tests\RefundMemoFactory();
    $service = new \Paymos\Payment\Tests\RefundFinancialService($db);
    $gateway = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store,
        'metadata' => new class { public function getVersion() { return '2.4.7-p3'; } },
        'scopeConfig' => new \Paymos\Payment\Tests\FakeScopeConfig(),
        'orders' => new \Paymos\Payment\Tests\RefundModelFactory($order),
        'invoices' => new \Paymos\Payment\Tests\RefundModelFactory($invoice), 'creditmemos' => $factory, 'service' => $service]);
    $service->gateway = $gateway;
    $service->guard = new \Paymos\Payment\Plugin\RefundGuard($gateway);
    $service->operationGuard = new \Paymos\Payment\Plugin\RefundOperationGuard($service->guard);
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $http);
    $adapter = new RefundAdapter($store, $gateway, $credentials, new RefundClients($client));
    $over = $adapter->submit(42, 'overpayment', 'request-over-0001');
    magento_refund_status($http, $over, 'completed');
    $adapter->reconcile($over->operationId());
    assertSameValue(1, count($db->tables['sales_order_status_history']), 'Production gateway creates one cash history');
    assertSameValue(0, $service->calls, 'Overpayment never enters native financial service');
    assertSameValue('0.0000', $db->tables['sales_order'][0]['total_refunded'], 'Cash note does not mutate native refunded');
    $http->invoice['refund_summary']['full_refund_amount'] = '100';
    $full = $adapter->submit(42, 'full', 'request-full-0001', ['items' => [11 => ['qty' => '1', 'back_to_stock' => '1']]], 7);
    magento_refund_status($http, $full, 'completed');
    assertSameValue('apply_completed', $adapter->reconcile($full->operationId()), 'Actual projector finalizes through offline service');
    assertSameValue(1, $service->calls, 'One financial service invocation');
    assertSameValue('resume_recovery', $adapter->reconcile($full->operationId()), 'Production inspect recovers exact native credit memo');
    assertSameValue(1, $service->calls, 'Recovery does not replay native service');
    $db->tables['sales_creditmemo'][0]['grand_total'] = '99';
    assertSameValue('show_review', $adapter->reconcile($full->operationId()), 'Changed document requires review');
}

function magento_refund_guard_fixture()
{
    list($db, $store, $http) = magento_refund_fixture();
    magento_refund_native_rows($db);
    $db->tables['sales_order_payment'][] = ['entity_id' => 10, 'parent_id' => 43, 'method' => 'checkmo'];
    $db->tables['sales_invoice'][] = ['entity_id' => 8, 'order_id' => 43];
    $native = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store]);
    return [$db, $store, $http, $native, new \Paymos\Payment\Plugin\RefundGuard($native)];
}

function test_magento_refund_order_api_blocks_paymos_and_preserves_other_orders()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $plugin = new \Paymos\Payment\Plugin\RefundApiGuard($native, $guard);
    $subject = new class implements \Magento\Sales\Api\RefundOrderInterface {};
    magento_refund_throws(static function () use ($plugin, $subject) { $plugin->beforeExecute($subject, 42, [], true); }, 'paid with Paymos');
    assertSameValue(null, $plugin->beforeExecute($subject, 43, [], true), 'Other order API arguments remain untouched');
    assertSameValue([], $http->requests, 'Order refund rejection performs no HTTP');
    assertSameValue(0, $db->native['documents'], 'No credit memo is created');
}

function test_magento_refund_invoice_api_blocks_online_and_offline_paymos()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $plugin = new \Paymos\Payment\Plugin\RefundApiGuard($native, $guard);
    $subject = new class implements \Magento\Sales\Api\RefundInvoiceInterface {};
    foreach ([false, true] as $online) {
        magento_refund_throws(static function () use ($plugin, $subject, $online) {
            $plugin->beforeExecute($subject, 7, [], $online, true, true);
        }, 'paid with Paymos');
        assertSameValue(null, $plugin->beforeExecute($subject, 8, [], $online, true, true), 'Other invoice refund retains all arguments');
    }
    assertSameValue([], $http->requests, 'Invoice refund rejection performs no HTTP');
    assertSameValue(0, $db->native['documents'], 'No invoice credit memo is created');
}

function test_magento_refund_management_blocks_both_flags_without_http()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $memo = new \Paymos\Payment\Tests\RefundModel(['order_id' => 42]);
    $calls = [];
    $proceed = static function ($value, $offline) use (&$calls) { $calls[] = [$value, $offline]; return $value; };
    foreach ([false, true] as $offline) {
        magento_refund_throws(static function () use ($guard, $proceed, $memo, $offline) {
            $guard->aroundRefund(null, $proceed, $memo, $offline);
        }, 'paid with Paymos');
    }
    assertSameValue([], $calls, 'Management never delegates Paymos bypass');
    $memo->setOrderId(43);
    foreach ([false, true] as $offline) {
        assertSameValue($memo, $guard->aroundRefund(null, $proceed, $memo, $offline), 'Other payment result is unchanged');
    }
    assertSameValue([[$memo, false], [$memo, true]], $calls, 'Other payment arguments are unchanged');
    assertSameValue([], $http->requests, 'Management guard performs no HTTP');
}

function test_magento_refund_repository_and_resource_save_reject_forged_memos()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $memo = new \Paymos\Payment\Tests\RefundModel(['order_id' => 42, 'paymos_refund_operation_id' => 'forged-marker']);
    $calls = 0;
    $proceed = static function ($value) use (&$calls) { $calls++; return $value; };
    foreach (['repository', 'resource'] as $path) {
        foreach ([null, 77] as $id) {
            $memo->setData('entity_id', $id);
            magento_refund_throws(static function () use ($guard, $proceed, $memo, $path) {
                $guard->aroundSave((object) ['path' => $path], $proceed, $memo);
            }, 'paid with Paymos');
        }
    }
    assertSameValue(0, $calls, 'Marker or supplied entity id cannot authorize persistence');
    (new ReflectionProperty(RefundNativeGateway::class, 'finalizing'))->setValue($native, $memo);
    assertSameValue($memo, $guard->aroundSave(null, $proceed, $memo), 'Paymos finalizer can save its exact memo');
    magento_refund_throws(static function () use ($guard, $proceed, $memo) { $guard->aroundSave(null, $proceed, clone $memo); }, 'paid with Paymos');
    (new ReflectionProperty(RefundNativeGateway::class, 'finalizing'))->setValue($native, null);
    $memo->setOrderId(43);
    assertSameValue($memo, $guard->aroundSave(null, $proceed, $memo), 'Other payment persistence delegates unchanged');
    assertSameValue([], $http->requests, 'Persistence guard performs no HTTP');
}

function test_magento_refund_operation_blocks_direct_financial_mutation()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $plugin = new \Paymos\Payment\Plugin\RefundOperationGuard($guard);
    $memo = new \Paymos\Payment\Tests\RefundModel(['order_id' => 42]);
    foreach ([false, true] as $online) {
        magento_refund_throws(static function () use ($plugin, $memo, $online) {
            $plugin->beforeExecute(null, $memo, null, $online);
        }, 'paid with Paymos');
    }
    (new ReflectionProperty(RefundNativeGateway::class, 'finalizing'))->setValue($native, $memo);
    assertSameValue(null, $plugin->beforeExecute(null, $memo, null, false), 'Paymos offline finalizer is allowed');
    magento_refund_throws(static function () use ($plugin, $memo) { $plugin->beforeExecute(null, $memo, null, true); }, 'paid with Paymos');
    $memo->setOrderId(43);
    assertSameValue(null, $plugin->beforeExecute(null, $memo, null, true), 'Other payment operation is unchanged');
    assertSameValue([], $http->requests, 'Operation guard performs no HTTP');
}

function test_magento_refund_resource_cannot_fill_a_missing_order_id_after_guard()
{
    list($db, $store, $http, $native, $guard) = magento_refund_guard_fixture();
    $memo = new class {
        public $orderId = 42;
        public function getOrderId() { return null; }
        public function getOrder() { return new \Paymos\Payment\Tests\RefundModel(['entity_id' => $this->orderId]); }
    };
    $calls = 0;
    $proceed = static function ($value) use (&$calls) { $calls++; return $value; };
    magento_refund_throws(static function () use ($guard, $memo, $proceed) { $guard->aroundSave(null, $proceed, $memo); }, 'paid with Paymos');
    assertSameValue(0, $calls, 'Resource must not fill and persist a Paymos order after the guard');
    $memo->orderId = 43;
    assertSameValue($memo, $guard->aroundSave(null, $proceed, $memo), 'Native resource may still fill a non-Paymos order');
    assertSameValue([], $http->requests, 'Missing-order guard performs no HTTP');
}

function test_magento_refund_all_service_guards_are_registered_globally()
{
    $di = simplexml_load_file(PAYMOS_M2_MODULE_DIR . 'etc/di.xml');
    $types = [
        'Magento\\Sales\\Api\\CreditmemoManagementInterface' => 'Paymos\\Payment\\Plugin\\RefundGuard',
        'Magento\\Sales\\Api\\RefundOrderInterface' => 'Paymos\\Payment\\Plugin\\RefundApiGuard',
        'Magento\\Sales\\Api\\RefundInvoiceInterface' => 'Paymos\\Payment\\Plugin\\RefundApiGuard',
        'Magento\\Sales\\Api\\CreditmemoRepositoryInterface' => 'Paymos\\Payment\\Plugin\\RefundGuard',
        'Magento\\Sales\\Model\\ResourceModel\\Order\\Creditmemo' => 'Paymos\\Payment\\Plugin\\RefundGuard',
        'Magento\\Sales\\Model\\Order\\Creditmemo\\RefundOperation' => 'Paymos\\Payment\\Plugin\\RefundOperationGuard',
    ];
    foreach ($types as $type => $plugin) {
        $nodes = $di->xpath('/config/type[@name="' . $type . '"]/plugin[@type="' . $plugin . '"]');
        assertSameValue(1, count($nodes), 'Global DI protects ' . $type);
        assertSameValue('1', (string) $nodes[0]['sortOrder'], 'Guard precedes native processing');
    }
}

function test_magento_refund_msi_blocks_full_even_when_reservations_are_empty()
{
    list($db, $store, $http, $unused, $credentials) = magento_refund_fixture();
    magento_refund_native_rows($db);
    $native = magento_refund_inject(RefundNativeGateway::class, ['resource' => new RefundResource($db), 'store' => $store,
        'metadata' => new class { public function getVersion() { return '2.4.7-p3'; } }]);
    $adapter = new RefundAdapter($store, $native, $credentials,
        new RefundClients(new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_key'), $http)));
    assertSameValue('2.4.7-p3', $native->assertBoundary(true), 'Supported non-MSI boundary passes');
    $db->existingTables = ['inventory_reservation'];
    $db->tables['inventory_reservation'] = [];
    magento_refund_throws(static function () use ($native) { $native->assertBoundary(true); }, 'resource_requires_verification');
    magento_refund_throws(static function () use ($adapter) { $adapter->submit(42, 'full', 'request-msi-0001', []); }, 'resource_requires_verification');
    assertSameValue([], $http->requests, 'Unsupported full MSI refund stops before HTTP');
    assertSameValue('2.4.7-p3', $native->assertBoundary(false), 'Cash-only boundary does not reject MSI');
    $over = $adapter->submit(42, 'overpayment', 'request-msi-over-01');
    assertSameValue('created', $over->cashState(), 'MSI still permits overpayment request');
}

function test_magento_refund_panel_returns_loaded_order_number_and_money()
{
    list($db, $store, $http, $native, $credentials, $adapter) = magento_refund_fixture();
    $panel = $adapter->panel(42);
    assertSameValue(42, $panel['order_id'], 'Panel identifies loaded internal order id');
    assertSameValue('100000042', $panel['order_increment_id'], 'Panel identifies visible order number');
    assertSameValue('100.0000', $panel['order_amount'], 'Panel exposes precise loaded order amount');
    assertSameValue('USD', $panel['order_currency'], 'Panel exposes loaded order currency');
    assertSameValue(['GET'], array_column($http->requests, 'method'), 'Loading order cannot request a refund');
}
