<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\InvoiceFactory;
use Magento\Sales\Model\Service\CreditmemoService;
use Magento\Sales\Model\Order\Email\Sender\CreditmemoSender;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;

class RefundNativeGateway
{
    private $resource;
    private $store;
    private $orders;
    private $invoices;
    private $creditmemos;
    private $service;
    private $sender;
    private $metadata;
    private $memoRepository;
    private $scopeConfig;
    private $finalizing;
    private $sending;

    public function __construct(ResourceConnection $resource, RefundIntentStore $store, OrderFactory $orders,
        InvoiceFactory $invoices, CreditmemoFactory $creditmemos, CreditmemoService $service,
        CreditmemoSender $sender, ProductMetadataInterface $metadata, CreditmemoRepositoryInterface $memoRepository,
        ScopeConfigInterface $scopeConfig)
    {
        $this->resource = $resource;
        $this->store = $store;
        $this->orders = $orders;
        $this->invoices = $invoices;
        $this->creditmemos = $creditmemos;
        $this->service = $service;
        $this->sender = $sender;
        $this->metadata = $metadata;
        $this->memoRepository = $memoRepository;
        $this->scopeConfig = $scopeConfig;
    }

    public static function units($value): string
    {
        $value = AmountGuard::normalizeDecimal((string) $value);
        if ($value === null || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,4}))?$/D', $value, $parts)) {
            throw new \RuntimeException('refund_native_decimal_invalid');
        }
        $units = ltrim($parts[1] . str_pad($parts[2] ?? '', 4, '0'), '0');
        return $units === '' ? '0' : $units;
    }

    public function paymentKey(int $orderId): string
    {
        $payment = $this->row('sales_order_payment', 'parent_id = ?', [$orderId]);
        if ($payment === null || $payment['method'] !== 'paymos') {
            throw new \RuntimeException('refund_payment_mapping_invalid');
        }
        return 'magento2:' . $orderId . ':' . $payment['entity_id'];
    }

    public function isPaymos(int $orderId): bool
    {
        $payment = $this->row('sales_order_payment', 'parent_id = ?', [$orderId]);
        return $payment !== null && $payment['method'] === 'paymos';
    }

    public function entryOrderId(int $orderId, ?int $invoiceId): int
    {
        if ($orderId > 0 || $invoiceId === null) {
            return $orderId;
        }
        $invoice = $this->row('sales_invoice', 'entity_id = ?', [$invoiceId]);
        return $invoice === null ? 0 : (int) $invoice['order_id'];
    }

    public function orderForProviderInvoice(string $invoiceId): int
    {
        $row = $this->row('paymos_payment_invoice', 'paymos_invoice_id = ?', [$invoiceId]);
        if ($row === null) {
            throw new \RuntimeException('refund_unbound_mapping_missing');
        }
        return (int) $row['order_id'];
    }

    public function identity(int $orderId): array
    {
        $key = $this->paymentKey($orderId);
        $this->store->assertLock($key);
        $order = $this->row('sales_order', 'entity_id = ?', [$orderId]);
        $db = $this->store->connection();
        $snapshots = $db->fetchAll('SELECT * FROM ' . $this->table('paymos_payment_invoice') . ' WHERE order_id = ? ORDER BY entity_id DESC', [$orderId]);
        if ($order === null || count($snapshots) !== 1) {
            throw new \RuntimeException('refund_mapping_ambiguous');
        }
        return ['order' => $order, 'snapshot' => $snapshots[0], 'payment_key' => $key];
    }

    public function mapping(int $orderId): array
    {
        $mapping = $this->identity($orderId);
        $order = $mapping['order'];
        $snapshots = [$mapping['snapshot']];
        $key = $mapping['payment_key'];
        $db = $this->store->connection();
        if (!in_array($snapshots[0]['status'], ['paid', 'paid_over'], true)
            || self::units($order['total_paid']) === '0' || self::units($order['total_paid']) !== self::units($order['grand_total'])
            || self::units($order['base_total_paid']) !== self::units($order['base_grand_total'])
            || self::units($order['total_refunded'] ?? '0') !== '0' || self::units($order['base_total_refunded'] ?? '0') !== '0'
            || !in_array($order['state'], ['processing', 'complete'], true)
            || !AmountGuard::amountsEqual($snapshots[0]['amount'], $order['grand_total'])
            || $snapshots[0]['currency'] !== $order['order_currency_code']) {
            throw new \RuntimeException('refund_native_payment_not_original_full');
        }
        if ($db->fetchOne('SELECT COUNT(*) FROM ' . $this->table('sales_creditmemo') . ' WHERE order_id = ?', [$orderId])) {
            throw new \RuntimeException('refund_manual_creditmemo_present');
        }
        $invoices = $db->fetchAll('SELECT * FROM ' . $this->table('sales_invoice') . ' WHERE order_id = ?', [$orderId]);
        if (count($invoices) !== 1 || (string) $invoices[0]['state'] !== '2'
            || self::units($invoices[0]['grand_total']) !== self::units($order['total_paid'])
            || self::units($invoices[0]['base_grand_total']) !== self::units($order['base_total_paid'])
            || self::units($invoices[0]['base_total_refunded'] ?? '0') !== '0') {
            throw new \RuntimeException('refund_native_invoice_ambiguous');
        }
        return ['order' => $order, 'invoice' => $invoices[0], 'snapshot' => $snapshots[0], 'payment_key' => $key];
    }

    public function assertBoundary(bool $financial = true): string
    {
        $version = $this->metadata->getVersion();
        if (!preg_match('/^2\.4\.[0-9]+(?:-p[0-9]+)?$/D', $version)
            || $this->resource->getConnection() !== $this->store->connection()
            || $this->resource->getConnection('checkout') !== $this->store->connection()) {
            throw new \RuntimeException('refund_native_connection_unsupported');
        }
        $tables = ['sales_order', 'sales_order_item', 'sales_order_payment', 'sales_invoice', 'sales_invoice_item',
            'sales_creditmemo', 'sales_creditmemo_item', 'sales_creditmemo_comment', 'sales_order_status_history',
            'sales_payment_transaction', 'cataloginventory_stock_item', 'cataloginventory_stock_status',
            'paymos_payment_invoice', 'paymos_refund_installation', 'paymos_refund_intent', 'paymos_refund_projection', 'paymos_refund_observation'];
        foreach ($tables as $table) {
            $engine = $this->store->connection()->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->table($table)]);
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('refund_native_requires_innodb');
            }
        }
        // MSI reservations and Commerce balances need a separately proven boundary.
        foreach ($financial ? ['inventory_reservation', 'magento_customerbalance', 'magento_giftcardaccount'] : [] as $table) {
            if ($this->store->connection()->isTableExists($this->table($table))) {
                throw new \RuntimeException('refund_native_resource_requires_verification');
            }
        }
        return $version;
    }

    public function draft(array $mapping, array $input, ?int $invoiceId): array
    {
        $version = $this->assertBoundary();
        if ($invoiceId !== null && $invoiceId !== (int) $mapping['invoice']['entity_id']) {
            throw new \RuntimeException('refund_native_invoice_mismatch');
        }
        foreach ($input as $name => $value) {
            if (!in_array($name, ['items', 'shipping_amount', 'adjustment_positive', 'adjustment_negative',
                'send_email', 'do_offline', 'comment_text', 'comment_customer_notify', 'is_visible_on_front'], true)) {
                throw new \RuntimeException('refund_native_draft_unsupported');
            }
        }
        if (!empty($input['comment_text']) || !empty($input['comment_customer_notify']) || !empty($input['is_visible_on_front'])
            || self::units($input['adjustment_positive'] ?? '0') !== '0'
            || self::units($input['adjustment_negative'] ?? '0') !== '0') {
            throw new \RuntimeException('refund_native_draft_unsupported');
        }
        $items = $this->store->connection()->fetchAll('SELECT * FROM ' . $this->table('sales_order_item') . ' WHERE order_id = ? ORDER BY item_id', [(int) $mapping['order']['entity_id']]);
        $qtys = [];
        $restock = [];
        $nativeItems = [];
        $requested = $input['items'] ?? [];
        $explicitItems = array_key_exists('items', $input);
        $autoReturn = (bool) $this->scopeConfig->getValue('cataloginventory/item_options/auto_return',
            ScopeInterface::SCOPE_STORE, (int) $mapping['order']['store_id']);
        if (!is_array($requested)) {
            throw new \RuntimeException('refund_native_items_invalid');
        }
        foreach ($items as $item) {
            $id = (string) $item['item_id'];
            if ($explicitItems && !array_key_exists($id, $requested)) {
                throw new \RuntimeException('refund_native_partial_rejected');
            }
            if (self::units($item['qty_refunded']) !== '0' || self::units($item['qty_canceled']) !== '0'
                || !AmountGuard::amountsEqual($item['qty_ordered'], $item['qty_invoiced'])) {
                throw new \RuntimeException('refund_native_items_changed');
            }
            $data = $requested[$id] ?? ['qty' => $item['qty_invoiced']];
            if (!is_array($data) || array_diff(array_keys($data), ['qty', 'back_to_stock'])
                || !AmountGuard::amountsEqual($data['qty'] ?? '', $item['qty_invoiced'])) {
                throw new \RuntimeException('refund_native_partial_rejected');
            }
            $qtys[$id] = (string) $item['qty_invoiced'];
            $restock[$id] = !empty($data['back_to_stock']) || $autoReturn;
            $nativeItems[$id] = array_intersect_key($item, array_flip(['product_id', 'sku', 'product_type', 'parent_item_id',
                'qty_ordered', 'qty_invoiced', 'row_total', 'base_row_total', 'tax_amount', 'base_tax_amount',
                'discount_amount', 'base_discount_amount', 'row_total_incl_tax', 'base_row_total_incl_tax']));
            unset($requested[$id]);
        }
        if ($requested !== [] || $qtys === []) {
            throw new \RuntimeException('refund_native_items_invalid');
        }
        $factoryData = ['qtys' => $qtys, 'adjustment_positive' => '0', 'adjustment_negative' => '0'];
        if (isset($input['shipping_amount'])) {
            if (!is_scalar($input['shipping_amount'])) {
                throw new \RuntimeException('refund_native_shipping_invalid');
            }
            $factoryData['shipping_amount'] = (string) $input['shipping_amount'];
        }
        $memo = $this->build((int) $mapping['order']['entity_id'], (int) $mapping['invoice']['entity_id'], $factoryData, $restock);
        if (self::units($memo->getGrandTotal()) !== self::units($mapping['order']['total_paid'])
            || self::units($memo->getBaseGrandTotal()) !== self::units($mapping['order']['base_total_paid'])) {
            throw new \RuntimeException('refund_native_partial_rejected');
        }
        return ['amount_minor' => self::units($mapping['order']['total_paid']), 'currency' => $mapping['order']['order_currency_code'],
            'scale' => 4, 'auto_return' => $autoReturn, 'order_id' => (int) $mapping['order']['entity_id'], 'invoice_id' => (int) $mapping['invoice']['entity_id'],
            'store_id' => (int) $mapping['order']['store_id'], 'version' => $version, 'factory_data' => $factoryData,
            'restock' => $restock, 'send_email' => !empty($input['send_email']), 'items' => $nativeItems,
            'base_amount_minor' => self::units($mapping['order']['base_total_paid']),
            'totals' => $this->memoTotals($memo)];
    }

    private function memoTotals($memo): array
    {
        $totals = [];
        foreach (['grand_total', 'base_grand_total', 'subtotal', 'base_subtotal', 'tax_amount', 'base_tax_amount',
            'discount_amount', 'base_discount_amount', 'shipping_amount', 'base_shipping_amount', 'shipping_tax_amount',
            'base_shipping_tax_amount', 'adjustment_positive', 'adjustment_negative'] as $name) {
            $totals[$name] = AmountGuard::normalizeDecimal((string) $memo->getData($name));
        }
        return $totals;
    }

    private function build(int $orderId, int $invoiceId, array $data, array $restock)
    {
        $order = $this->orders->create()->load($orderId);
        $invoice = $this->invoices->create()->load($invoiceId);
        if (!$order->getId() || (int) $invoice->getOrderId() !== $orderId) {
            throw new \RuntimeException('refund_native_mapping_missing');
        }
        $invoice->setOrder($order);
        $memo = $this->creditmemos->createByInvoice($invoice, $data);
        foreach ($memo->getAllItems() as $item) {
            $item->setBackToStock($restock[(string) $item->getOrderItemId()] ?? false);
        }
        $memo->setSendEmail(false);
        $memo->setCustomerNoteNotify(false);
        return $memo;
    }

    public function inspect(RefundIntent $intent): NativeProjectionResult
    {
        $marker = $this->store->marker($intent->operationId());
        $mode = $intent->mode();
        $documents = $this->store->connection()->fetchAll('SELECT * FROM ' . $this->table('sales_creditmemo') . ' WHERE paymos_refund_operation_id = ?', [$intent->operationId()]);
        $notes = $this->store->connection()->fetchAll('SELECT * FROM ' . $this->table('sales_order_status_history') . ' WHERE paymos_refund_operation_id = ?', [$intent->operationId()]);
        if ($marker === null) {
            $partial = count($documents) || count($notes);
            return new NativeProjectionResult($partial ? NativeProjectionResult::AMBIGUOUS : NativeProjectionResult::ABSENT,
                $mode, null, null, null, null, null, $partial ? 'refund_native_marker_partial' : null);
        }
        if ($marker['payment_key'] !== $intent->paymentKey()) {
            return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $mode, null, null, null, null, null, 'refund_native_parent_mismatch');
        }
        $result = NativeProjectionResult::fromStorage(json_decode($marker['result'], true, 512, JSON_THROW_ON_ERROR));
        if ($mode === RefundStates::FULL) {
            $draft = $intent->fullDraft();
            $order = $this->row('sales_order', 'entity_id = ?', [$draft['order_id']]);
            if (count($notes) || count($documents) !== 1 || (string) $documents[0]['entity_id'] !== $result->nativeId()
                || (int) $documents[0]['order_id'] !== $draft['order_id'] || (int) $documents[0]['invoice_id'] !== $draft['invoice_id']
                || (string) $documents[0]['state'] !== '2' || self::units($documents[0]['grand_total']) !== $draft['amount_minor']
                || self::units($documents[0]['base_grand_total']) !== $draft['base_amount_minor']
                || $documents[0]['order_currency_code'] !== $draft['currency'] || $order === null
                || self::units($order['total_refunded'] ?? '0') !== $draft['amount_minor']
                || self::units($order['total_paid']) !== $draft['amount_minor']
                || self::units($order['base_total_refunded'] ?? '0') !== $draft['base_amount_minor']) {
                return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $mode, null, null, null, null, null, 'refund_native_document_changed');
            }
            foreach ($draft['totals'] as $field => $value) {
                if (AmountGuard::normalizeDecimal((string) ($documents[0][$field] ?? '0')) !== $value) {
                    return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $mode, null, null, null, null, null, 'refund_native_totals_changed');
                }
            }
        } else {
            $history = $this->row('sales_order_status_history', 'entity_id = ?', [$result->nativeId()]);
            $parts = explode(':', $intent->paymentKey());
            if (count($documents) || count($notes) !== 1 || $history === null || (string) $history['parent_id'] !== $parts[1]
                || $history['comment'] !== $this->note($intent)) {
                return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $mode, null, null, null, null, null, 'refund_native_note_changed');
            }
        }
        return $result;
    }

    public function apply(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $parts = explode(':', $intent->paymentKey());
        $mapping = $this->mapping((int) $parts[1]);
        $this->assertBoundary($intent->mode() === RefundStates::FULL);
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $order = $this->orders->create()->load((int) $parts[1]);
            $history = $order->addCommentToStatusHistory($this->note($intent), $order->getStatus());
            $history->setData('paymos_refund_operation_id', $intent->operationId());
            $history->setIsCustomerNotified(false)->setIsVisibleOnFront(false)->save();
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), (string) $history->getId(),
                null, null, $refund->principalDecimal(), $refund->currency() . '/' . $refund->network());
        } else {
            $draft = $intent->fullDraft();
            $saved = $draft;
            unset($saved['request_input'], $saved['entry_invoice_id']);
            if ($this->draft($mapping, $draft['request_input'], $draft['entry_invoice_id']) !== $saved) {
                throw new \RuntimeException('refund_native_draft_changed');
            }
            $memo = $this->build($draft['order_id'], $draft['invoice_id'], $draft['factory_data'], $draft['restock']);
            $memo->setData('paymos_refund_operation_id', $intent->operationId());
            $this->finalizing = $memo;
            try {
                $this->service->refund($memo, true);
            } finally {
                $this->finalizing = null;
            }
            $result = new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(), (string) $memo->getId(),
                $draft['amount_minor'], $draft['currency']);
        }
        $this->store->saveMarker($intent, $result);
        return $result;
    }

    private function note(RefundIntent $intent): string
    {
        $refund = $intent->observation();
        return 'Paymos overpayment refund completed. The order stays paid and stock is unchanged. [paymos_refund:' . $intent->operationId() . '] ' . $refund->principalDecimal()
            . ' ' . $refund->currency() . '/' . $refund->network() . ' ' . $refund->withdrawalId();
    }

    public function observeUnbound(int $orderId, VerifiedRefund $refund): void
    {
        $key = $this->paymentKey($orderId);
        $this->assertBoundary(false);
        $this->store->transaction(function () use ($orderId, $refund, $key) {
            $old = $this->store->unbound($refund->withdrawalId());
            $historyId = $old === null ? null : $old['history_id'];
            $reason = $refund->mode() === RefundStates::FULL && !in_array($refund->status(), ['failed', 'cancelled'], true)
                ? 'refund_unbound_full_requires_draft' : null;
            $this->store->saveUnbound($key, $refund, $historyId, $reason);
            if ($refund->mode() === RefundStates::OVERPAYMENT) {
                $marker = substr(hash('sha256', 'unbound:' . $refund->withdrawalId()), 0, 32);
                $histories = $this->store->connection()->fetchAll('SELECT * FROM ' . $this->table('sales_order_status_history')
                    . ' WHERE paymos_refund_operation_id = ?', [$marker]);
                $text = 'Paymos overpayment refund completed. It was started outside this store. The order stays paid and stock is unchanged. [paymos_refund:' . $marker . '] ' . $refund->principalDecimal()
                    . ' ' . $refund->currency() . '/' . $refund->network() . ' ' . $refund->withdrawalId();
                if ($historyId !== null) {
                    if (count($histories) !== 1 || (string) $histories[0]['entity_id'] !== $historyId
                        || (int) $histories[0]['parent_id'] !== $orderId || $histories[0]['comment'] !== $text) {
                        $reason = 'refund_native_note_changed';
                    } elseif ($refund->status() !== 'completed') {
                        $reason = 'cash_rewound';
                    }
                } elseif (count($histories)) {
                    $reason = 'refund_native_marker_partial';
                } elseif ($refund->status() === 'completed') {
                    $order = $this->orders->create()->load($orderId);
                    $history = $order->addCommentToStatusHistory($text, $order->getStatus());
                    $history->setData('paymos_refund_operation_id', $marker);
                    $history->setIsCustomerNotified(false)->setIsVisibleOnFront(false)->save();
                    $historyId = (string) $history->getId();
                }
                $this->store->saveUnbound($key, $refund, $historyId, $reason);
            }
        });
    }

    public function permits($memo, bool $offline): bool { return $offline && $this->finalizing === $memo; }
    public function permitsMail($memo): bool { return $this->sending === (string) $memo->getId(); }

    public function send(RefundIntent $intent): bool
    {
        $result = $this->inspect($intent);
        if ($result->kind() !== NativeProjectionResult::EXACT_MATCH || $intent->mode() !== RefundStates::FULL) {
            throw new \RuntimeException('refund_mail_projection_missing');
        }
        $memo = $this->memoRepository->get((int) $result->nativeId());
        $this->sending = $result->nativeId();
        try {
            return $this->sender->send($memo, true) === true;
        } finally {
            $this->sending = null;
        }
    }

    private function table(string $name): string { return $this->resource->getTableName($name, 'sales'); }
    private function row(string $table, string $where, array $bind): ?array
    {
        $row = $this->store->connection()->fetchRow('SELECT * FROM ' . $this->table($table) . ' WHERE ' . $where, $bind);
        return is_array($row) ? $row : null;
    }
}
