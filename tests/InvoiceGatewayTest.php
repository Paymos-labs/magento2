<?php

declare(strict_types=1);

// Isolated gateway contract fixtures, not a native Magento integration suite.
namespace Magento\Sales\Model\Order {
    class Invoice { const STATE_PAID = 2; const CAPTURE_OFFLINE = 'offline'; }
}
namespace Magento\Sales\Model\Service {
    class InvoiceService {
        public $prepared = 0;
        public function prepareInvoice($order) {
            $this->prepared++;
            return new \PaymosF01Invoice($order);
        }
    }
}
namespace Magento\Sales\Model\Order\Email\Sender {
    class InvoiceSender { public function send($invoice) {} }
}
namespace {
    class PaymosF01InvoiceCollection implements \IteratorAggregate {
        public $items = [];
        public function clear() { return $this; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->items); }
    }
    class PaymosF01Order extends \Magento\Sales\Model\Order {
        public $state = 'new';
        public $status = 'pending_payment';
        public $canInvoice = true;
        public $comments = [];
        public $invoices;
        public $persisted = [];
        public $payment;
        public function __construct() {
            $this->invoices = new PaymosF01InvoiceCollection();
            $this->payment = new class {
                public $pending = true;
                public function setLastTransId($id) {}
                public function setTransactionId($id) {}
                public function setIsTransactionPending($pending) { $this->pending = $pending; }
                public function setIsTransactionClosed($closed) {}
            };
        }
        public function persist() { $this->persisted = [$this->state, $this->status, $this->canInvoice]; }
        public function reset() { return $this; }
        public function getResource() { return new class {
            public function load($order, $id) { [$order->state, $order->status, $order->canInvoice] = $order->persisted; }
        }; }
        public function getEntityId() { return 42; }
        public function getStoreId() { return 1; }
        public function getIncrementId() { return '100000042'; }
        public function getGrandTotal() { return '10.001'; }
        public function getOrderCurrencyCode() { return 'BHD'; }
        public function getTotalPaid() { return count($this->invoices->items) > 0 ? '10.001' : '0'; }
        public function getState() { return $this->state; }
        public function getStatus() { return $this->status; }
        public function isPaymentReview() { return $this->state === 'payment_review'; }
        public function setState($state) { $this->state = $state; return $this; }
        public function setStatus($status) { $this->status = $status; return $this; }
        public function canInvoice() { return $this->canInvoice; }
        public function getInvoiceCollection() { return $this->invoices; }
        public function getPayment() { return $this->payment; }
        public function addCommentToStatusHistory($comment, $status) {
            $this->comments[] = $comment;
            return new class { public function setIsCustomerNotified($notify) {} };
        }
    }
    class PaymosF01Invoice extends \Magento\Sales\Model\Order\Invoice {
        public $state = 1;
        public $transactionId = '';
        public $total = '10.001';
        public $currency = 'BHD';
        private $order;
        public function __construct($order) { $this->order = $order; }
        public function getState() { return $this->state; }
        public function getTransactionId() { return $this->transactionId; }
        public function getGrandTotal() { return $this->total; }
        public function getOrderCurrencyCode() { return $this->currency; }
        public function getTotalQty() { return 1; }
        public function getOrder() { return $this->order; }
        public function setRequestedCaptureCase($capture) { return $this; }
        public function setTransactionId($id) { $this->transactionId = $id; return $this; }
        public function register() {
            $this->order->canInvoice = false;
            if (!$this->order->getPayment()->pending) { $this->state = self::STATE_PAID; }
            return $this;
        }
    }
    function paymos_f01_native_gateway(PaymosF01Order $order): array {
        $order->persist();
        $repository = new class($order) implements \Magento\Sales\Api\OrderRepositoryInterface {
            private $order;
            public $failSave = false;
            public function __construct($order) { $this->order = $order; }
            public function get($id) { return $this->order; }
            public function save($order) {
                if ($this->failSave) { $this->failSave = false; throw new \RuntimeException('Native order save failed.'); }
                $order->persist();
                return $order;
            }
        };
        $service = new \Magento\Sales\Model\Service\InvoiceService();
        $transaction = new class extends \Magento\Framework\DB\Transaction {
            public $objects = [];
            public $failSave = false;
            public function addObject($object) { $this->objects[] = $object; return $this; }
            public function save() {
                if ($this->failSave) {
                    $this->failSave = false;
                    $this->objects = [];
                    throw new \RuntimeException('Native invoice transaction failed.');
                }
                foreach ($this->objects as $object) {
                    if ($object instanceof PaymosF01Invoice) {
                        $order = $object->getOrder();
                        $order->invoices->items[] = $object;
                        $order->canInvoice = false;
                        $order->persist();
                    }
                }
                $this->objects = [];
            }
        };
        // Logger is unused on these synchronous success/failure paths.
        $logger = (new \ReflectionClass(\Paymos\Payment\Service\Logger::class))->newInstanceWithoutConstructor();
        $gateway = new \Paymos\Payment\Service\MagentoOrderGateway($repository, $service, $transaction,
            new \Magento\Sales\Model\Order\Email\Sender\InvoiceSender(), $logger, paymos_m2_settings());
        return [$gateway, $service, $repository, $transaction];
    }
    function test_magento_invoice_gateway_refuses_can_invoice_false_without_payment_proof() {
        $order = new PaymosF01Order();
        $order->canInvoice = false;
        [$gateway, $service] = paymos_f01_native_gateway($order);
        $threw = false;
        try { $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false); }
        catch (\RuntimeException $error) { $threw = true; }
        assertTrueValue($threw, 'canInvoice=false without matching invoice must fail.');
        assertSameValue('new', $order->state, 'Failure must not mark order processing.');
        assertSameValue('pending_payment', $order->status, 'Failure must not set paid status.');
        assertSameValue(0, $service->prepared, 'No invoice is fabricated.');
    }
    function test_magento_invoice_gateway_refuses_canceled_order_before_mutation() {
        $order = new PaymosF01Order();
        $order->state = 'canceled';
        [$gateway, $service] = paymos_f01_native_gateway($order);
        $threw = false;
        try { $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false); }
        catch (\RuntimeException $error) { $threw = true; }
        assertTrueValue($threw, 'Direct gateway entry must also reject canceled state.');
        assertSameValue('canceled', $order->state, 'Canceled state is preserved.');
        assertSameValue(0, $service->prepared, 'Canceled order cannot prepare invoice.');
    }
    function test_magento_invoice_gateway_recovers_after_native_invoice_commit_without_duplicate() {
        $order = new PaymosF01Order();
        [$gateway, $service, $repository] = paymos_f01_native_gateway($order);
        assertSameValue('10.001', $gateway->loadOrder(42)['amount'], 'Native gateway must preserve significant decimals.');
        $repository->failSave = true;
        $threw = false;
        try { $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false); }
        catch (\RuntimeException $error) { $threw = true; }
        assertTrueValue($threw, 'First status save failure is reported.');
        assertSameValue(1, count($order->invoices->items), 'Invoice already committed before the status failure.');
        $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false);
        assertSameValue(1, $service->prepared, 'Retry must not prepare a second invoice.');
        assertSameValue(1, count($order->invoices->items), 'Exactly one invoice after recovery.');
        assertSameValue('paid_status', $order->status, 'Retry repairs native order status.');
    }
    function test_magento_invoice_gateway_rejects_wrong_existing_invoice_proof() {
        foreach (['transaction', 'amount', 'state', 'currency'] as $mismatch) {
            $order = new PaymosF01Order();
            $order->canInvoice = false;
            $invoice = new PaymosF01Invoice($order);
            $invoice->state = $mismatch === 'state' ? 1 : 2;
            $invoice->transactionId = $mismatch === 'transaction' ? 'foreign' : 'inv_123';
            $invoice->total = $mismatch === 'amount' ? '10.00' : '10.001';
            $invoice->currency = $mismatch === 'currency' ? 'KWD' : 'BHD';
            $order->invoices->items[] = $invoice;
            [$gateway] = paymos_f01_native_gateway($order);
            $threw = false;
            try { $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false); }
            catch (\RuntimeException $error) { $threw = true; }
            assertTrueValue($threw, 'Wrong native invoice ' . $mismatch . ' cannot prove completion.');
            assertSameValue('pending_payment', $order->status, 'Wrong native invoice must not set paid status.');
        }
    }
    function test_magento_invoice_gateway_reloads_cached_order_after_failed_transaction() {
        $order = new PaymosF01Order();
        [$gateway, $service, $repository, $transaction] = paymos_f01_native_gateway($order);
        $transaction->failSave = true;
        $threw = false;
        try { $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false); }
        catch (\RuntimeException $error) { $threw = true; }
        assertTrueValue($threw, 'Injected failure after register must be reported.');
        assertFalseValue($order->canInvoice, 'register dirtied cached native item state.');
        assertSameValue(0, count($order->invoices->items), 'Failed transaction persisted no invoice.');
        $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false);
        assertSameValue(2, $service->prepared, 'Retry rehydrates and prepares a new uncommitted invoice.');
        assertSameValue(1, count($order->invoices->items), 'Only the successful invoice is persisted.');
        assertSameValue('paid_status', $order->status, 'Recovered native payment gets paid status.');
    }
    function test_magento_invoice_gateway_snapshot_retry_preserves_later_native_terminal_state() {
        foreach (['complete', 'closed'] as $state) {
            $order = new PaymosF01Order();
            $order->state = $state;
            $order->status = $state;
            $order->canInvoice = false;
            $invoice = new PaymosF01Invoice($order);
            $invoice->state = 2;
            $invoice->transactionId = 'inv_123';
            $order->invoices->items[] = $invoice;
            [$gateway, $service] = paymos_f01_native_gateway($order);
            $gateway->invoiceOrder(42, 'paid_status', 'Paid', 'inv_123', false);
            assertSameValue($state, $order->state, 'Snapshot retry cannot undo native terminal state.');
            assertSameValue($state, $order->status, 'Snapshot retry preserves fulfilled/refunded status.');
            assertSameValue(0, $service->prepared, 'Prior matching native invoice is reused.');
        }
    }
}
