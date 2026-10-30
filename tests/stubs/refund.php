<?php

declare(strict_types=1);

namespace Paymos\Payment\Tests;

use Paymos\Payment\Service\CredentialStore;
use Paymos\Payment\Service\PaymosClientFactory;
use Paymos\Payment\Service\RefundIntentStore;
use Paymos\Payment\Service\RefundNativeGateway;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\VerifiedRefund;

class RefundDb
{
    public $tables = [];
    public $native = ['documents' => 0, 'notes' => 0, 'refunded' => '0', 'stock' => 0, 'state' => 'processing'];
    public $level = 0;
    public $owned = true;
    public $busy = false;
    public $snapshots = [];
    public $commits = 0;
    public $existingTables = [];
    public $engines = [];

    public function getTransactionLevel() { return $this->level; }
    public function beginTransaction() { $this->snapshots[] = [$this->tables, $this->native]; $this->level++; }
    public function commit() { array_pop($this->snapshots); $this->level--; $this->commits++; }
    public function rollBack() { list($this->tables, $this->native) = array_pop($this->snapshots); $this->level--; }
    public function insertOnDuplicate($table, $data, $fields)
    {
        if (empty($this->tables[$table])) { $this->tables[$table][] = $data; }
    }
    public function insert($table, $data)
    {
        foreach ($this->tables[$table] ?? [] as $row) {
            foreach (['operation_id', 'external_order_id', 'withdrawal_id'] as $unique) {
                if (isset($data[$unique], $row[$unique]) && $data[$unique] === $row[$unique]) {
                    throw new \Magento\Framework\DB\Adapter\DuplicateException('duplicate');
                }
            }
            if (isset($data['request_token'], $row['request_token']) && $data['installation_id'] === $row['installation_id']
                && $data['request_token'] === $row['request_token']) {
                throw new \Magento\Framework\DB\Adapter\DuplicateException('duplicate token');
            }
        }
        $this->tables[$table][] = $data;
    }
    public function update($table, $data, $where)
    {
        $count = 0;
        foreach ($this->tables[$table] ?? [] as $i => $row) {
            $match = true;
            foreach ($where as $condition => $value) {
                if (is_int($condition)) {
                    $match = $match && strpos($value, 'IS NULL') !== false && !isset($row['withdrawal_id']);
                } else {
                    $field = trim(explode('=', $condition)[0]);
                    $match = $match && ($row[$field] ?? null) === $value;
                }
            }
            if ($match) { $this->tables[$table][$i] = array_replace($row, $data); $count++; }
        }
        return $count;
    }
    public function fetchOne($sql, $bind = [])
    {
        if (strpos($sql, 'GET_LOCK') !== false) { return $this->busy ? 0 : 1; }
        if (strpos($sql, 'IS_USED_LOCK') !== false) { return $this->owned ? 1 : 0; }
        if (strpos($sql, 'RELEASE_LOCK') !== false) { return 1; }
        if (strpos($sql, 'SELECT ENGINE') === 0) { return $this->engines[$bind[0]] ?? 'InnoDB'; }
        if (strpos($sql, 'SELECT installation_id') === 0) { return $this->tables['paymos_refund_installation'][0]['installation_id']; }
        if (strpos($sql, 'SELECT COUNT(*)') === 0) { return count($this->fetchAll($sql, $bind)); }
        throw new \RuntimeException('Unexpected SQL: ' . $sql);
    }
    public function fetchRow($sql, $bind = [])
    {
        $rows = $this->fetchAll($sql, $bind);
        return count($rows) ? $rows[0] : false;
    }
    public function fetchAll($sql, $bind = [])
    {
        if (!preg_match('/FROM ([a-z_]+)/', $sql, $match)) { throw new \RuntimeException('Unexpected SQL'); }
        $rows = $this->tables[$match[1]] ?? [];
        if (strpos($sql, 'next_due <= ?') !== false) {
            $rows = array_filter($rows, static function ($r) use ($bind) {
                return $r['next_due'] <= $bind[0] && (count($bind) === 1 || $r['next_due'] > $bind[1]
                    || ($r['next_due'] === $bind[2] && $r['operation_id'] > $bind[3]));
            });
            usort($rows, static function ($a, $b) { return [$a['next_due'], $a['operation_id']] <=> [$b['next_due'], $b['operation_id']]; });
        } else {
            preg_match_all('/([a-z_]+) = \?/', $sql, $conditions);
            foreach ($conditions[1] as $i => $field) {
                $rows = array_filter($rows, static function ($r) use ($field, $bind, $i) { return (string) ($r[$field] ?? '') === (string) $bind[$i]; });
            }
        }
        if (preg_match('/ LIMIT ([0-9]+)/', $sql, $limit)) { $rows = array_slice($rows, 0, (int) $limit[1]); }
        return array_values($rows);
    }
    public function isTableExists($table) { return in_array($table, $this->existingTables, true); }
}

class RefundResource extends \Magento\Framework\App\ResourceConnection
{
    public $db;
    public function __construct(RefundDb $db) { $this->db = $db; }
    public function getConnection($resourceName = 'default') { return $this->db; }
    public function getTableName($tableName, $connectionName = 'default') { return $tableName; }
}

class RefundCredentials extends CredentialStore
{
    public $enabled = true;
    public $capable = true;
    public $project = 'prj_123';
    public function __construct() {}
    public function loadCredentials(): array
    {
        return ['sandbox' => ['project_id' => $this->project, 'capabilities' => $this->capable ? ['invoice_refunds_v1'] : [],
            'refund_contract_version' => 1, 'refunds_enabled' => $this->enabled]];
    }
}

class RefundClients extends PaymosClientFactory
{
    public $client;
    public function __construct($client) { $this->client = $client; }
    public function create(string $environment): \Paymos\Client { return $this->client; }
}

class RefundNative extends RefundNativeGateway
{
    public $store;
    public $db;
    public $mail = 0;
    public $throwAfterWrite = false;
    public $throwMail = false;
    public $partial = false;
    public $ambiguous = false;
    public $boundary = true;
    public $mappingRow;
    public function __construct(RefundIntentStore $store, RefundDb $db)
    {
        $this->store = $store;
        $this->db = $db;
        $this->mappingRow = ['order' => ['store_id' => 1, 'increment_id' => '100000042', 'grand_total' => '100.0000',
            'order_currency_code' => 'USD'], 'invoice' => ['entity_id' => 7], 'snapshot' => \paymos_m2_snapshot(['status' => 'paid']),
            'payment_key' => 'magento2:42:9'];
    }
    public function paymentKey(int $orderId): string { return 'magento2:' . $orderId . ':9'; }
    public function isPaymos(int $orderId): bool { return $orderId === 42; }
    public function entryOrderId(int $orderId, ?int $invoiceId): int { return $orderId ?: 42; }
    public function orderForProviderInvoice(string $invoiceId): int { return 42; }
    public function identity(int $orderId): array { $this->store->assertLock($this->paymentKey($orderId)); return $this->mappingRow; }
    public function mapping(int $orderId): array
    {
        if ($this->partial || $this->db->native['refunded'] !== '0') { throw new \RuntimeException('partial native'); }
        return $this->identity($orderId);
    }
    public function assertBoundary(bool $financial = true): string
    {
        if (!$this->boundary) { throw new \RuntimeException('unsupported resource'); }
        return 'unit-double';
    }
    public function draft(array $mapping, array $input, ?int $invoiceId): array
    {
        if (($input['partial'] ?? false) || ($invoiceId !== null && $invoiceId !== 7)) { throw new \RuntimeException('partial'); }
        return ['amount_minor' => '1000000', 'currency' => 'USD', 'order_id' => 42, 'invoice_id' => 7,
            'send_email' => !empty($input['send_email']), 'restock' => !empty($input['restock'])];
    }
    public function inspect(RefundIntent $intent): NativeProjectionResult
    {
        if ($this->ambiguous) { return new NativeProjectionResult('ambiguous', $intent->mode(), null, null, null, null, null, 'partial marker'); }
        $row = $this->store->marker($intent->operationId());
        return $row === null ? new NativeProjectionResult('absent', $intent->mode())
            : NativeProjectionResult::fromStorage(json_decode($row['result'], true));
    }
    public function apply(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        if ($intent->mode() === 'full') {
            $this->db->native['documents']++;
            $this->db->native['refunded'] = '1000000';
            $this->db->native['stock'] += $intent->fullDraft()['restock'] ? 1 : 0;
            $this->db->native['state'] = 'closed';
            $result = new NativeProjectionResult('exact_match', 'full', 'memo-1', '1000000', 'USD');
        } else {
            $this->db->native['notes']++;
            $result = new NativeProjectionResult('exact_match', 'overpayment', 'note-1', null, null, $refund->principalDecimal(), 'USDT/TRC20');
        }
        if ($this->throwAfterWrite) { throw new \RuntimeException('native write interrupted'); }
        $this->store->saveMarker($intent, $result);
        return $result;
    }
    public function send(RefundIntent $intent): bool
    {
        if ($this->db->level !== 0 || ($this->store->getIntent($intent->operationId())->toArray()['effects']['creditmemo_email:1'] ?? '') !== 'started') {
            throw new \LogicException('mail before commit');
        }
        $this->mail++;
        if ($this->throwMail) { throw new \RuntimeException('SMTP acknowledgement lost'); }
        return true;
    }

    public function observeUnbound(int $orderId, VerifiedRefund $refund): void
    {
        $this->store->transaction(function () use ($orderId, $refund) {
            $old = $this->store->unbound($refund->withdrawalId());
            $id = $old === null ? null : $old['history_id'];
            if ($refund->mode() === 'overpayment' && $refund->status() === 'completed' && $id === null) {
                $this->db->native['notes']++;
                $id = 'unbound-note';
            }
            $this->store->saveUnbound($this->paymentKey($orderId), $refund, $id,
                $refund->mode() === 'full' ? 'refund_unbound_full_requires_draft' : null);
        });
    }
}

class RefundModel
{
    public $data;
    public $items = [];
    public $db;
    public function __construct(array $data = [], $db = null) { $this->data = $data; $this->db = $db; }
    public function load($id) { return $this; }
    public function getId() { return $this->data['entity_id'] ?? null; }
    public function getAllItems() { return $this->items; }
    public function getData($key) { return $this->data[$key] ?? '0'; }
    public function setData($key, $value) { $this->data[$key] = $value; return $this; }
    public function __call($method, $arguments)
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
        if (strpos($method, 'set') === 0) { return $this->setData($key, $arguments[0]); }
        if (strpos($method, 'get') === 0) { return $this->getData($key); }
        throw new \LogicException('Unexpected model method: ' . $method);
    }
    public function addCommentToStatusHistory($text, $status)
    {
        return new RefundHistory(['parent_id' => $this->getId(), 'comment' => $text, 'status' => $status], $this->db);
    }
}

class RefundHistory extends RefundModel
{
    public function save()
    {
        $this->data['entity_id'] = count($this->db->tables['sales_order_status_history'] ?? []) + 1;
        $this->db->insert('sales_order_status_history', $this->data);
        return $this;
    }
}

class RefundModelFactory
{
    private $model;
    public function __construct($model) { $this->model = $model; }
    public function create() { return clone $this->model; }
}

class RefundMemoFactory
{
    public $calls = [];
    public function createByInvoice($invoice, array $data = [])
    {
        $this->calls[] = $data;
        $memo = new RefundModel(['order_id' => 42, 'invoice_id' => 7, 'grand_total' => '100.0000', 'base_grand_total' => '100.0000',
            'tax_amount' => '20.0000', 'base_tax_amount' => '20.0000', 'subtotal' => '80.0000', 'base_subtotal' => '80.0000']);
        $memo->items = [new RefundModel(['order_item_id' => 11, 'qty' => $data['qtys'][11]])];
        return $memo;
    }
}

class RefundFinancialService
{
    public $gateway;
    public $db;
    public $calls = 0;
    public $guard;
    public $operationGuard;
    public function __construct($db) { $this->db = $db; }
    public function refund($memo, $offline = false)
    {
        \assertSameValue(true, $offline, 'Production projector invokes offline native refund');
        \assertSameValue(true, $this->gateway->permits($memo, $offline), 'Exact native finalizer context');
        \assertSameValue(1, $this->db->level, 'Module transaction surrounds native transaction');
        \assertSameValue(false, $memo->getData('send_email'), 'Native financial path does not send mail');
        if ($this->guard !== null) {
            $this->guard->aroundRefund($this, static function ($memo, $offline) { return $memo; }, $memo, $offline);
            $this->operationGuard->beforeExecute(null, $memo, null, !$offline);
            // Both repository and resource save receive this same finalizing object.
            $this->guard->aroundSave($this, static function ($memo) { return $memo; }, $memo);
            $this->guard->aroundSave($this, static function ($memo) { return $memo; }, $memo);
        }
        $this->db->beginTransaction();
        $memo->setData('entity_id', 77)->setData('state', 2)->setData('order_currency_code', 'USD');
        $this->db->insert('sales_creditmemo', $memo->data);
        $this->db->tables['sales_order'][0]['total_refunded'] = '100.0000';
        $this->db->tables['sales_order'][0]['base_total_refunded'] = '100.0000';
        $this->db->commit();
        $this->calls++;
        return $memo;
    }
}

namespace Magento\Framework\Exception;

if (!class_exists(LocalizedException::class)) {
    class LocalizedException extends \RuntimeException
    {
        public function __construct($phrase) { parent::__construct((string) $phrase); }
    }
}

namespace Magento\Sales\Api;

interface RefundOrderInterface {}
interface RefundInvoiceInterface {}
