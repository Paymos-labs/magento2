<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Service\InvoiceService;
use Paymos\Plugin\AmountGuard;

/**
 * Concrete Magento implementation of the order operations the OrderMapper needs.
 * Wraps OrderRepository, InvoiceService, DB\Transaction and InvoiceSender.
 *
 * A paid order is invoiced and moved to the Processing state (NOT Complete —
 * Complete means shipped). Cancellation goes through Order::cancel() so stock is
 * released. All mutations are guarded against an order that no longer permits
 * the transition (idempotent under webhook re-delivery).
 *
 * @see Magento\Sales\Model\Service\InvoiceService::prepareInvoice
 * @see Magento\Sales\Model\Order (STATE_PROCESSING, STATE_CANCELED, cancel())
 */
class MagentoOrderGateway implements MagentoOrderGatewayInterface
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var InvoiceService */
    private $invoiceService;

    /** @var Transaction */
    private $transaction;

    /** @var InvoiceSender */
    private $invoiceSender;

    /** @var Logger */
    private $logger;

    /** @var Settings */
    private $settings;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        InvoiceService $invoiceService,
        Transaction $transaction,
        InvoiceSender $invoiceSender,
        Logger $logger,
        Settings $settings
    ) {
        $this->orderRepository = $orderRepository;
        $this->invoiceService = $invoiceService;
        $this->transaction = $transaction;
        $this->invoiceSender = $invoiceSender;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    /**
     * @return array{order_id:int, increment_id:string, amount:string, currency:string, state:string, status:string, is_paid:bool}|null
     */
    public function loadOrder(int $orderId)
    {
        $order = $this->getOrder($orderId);
        if ($order === null) {
            return null;
        }

        $totalPaid = (float) $order->getTotalPaid();

        return [
            'order_id' => (int) $order->getEntityId(),
            'store_id' => (int) $order->getStoreId(),
            'increment_id' => (string) $order->getIncrementId(),
            'amount' => (string) $order->getGrandTotal(),
            'currency' => strtoupper((string) $order->getOrderCurrencyCode()),
            'state' => (string) $order->getState(),
            'status' => (string) $order->getStatus(),
            'is_paid' => $totalPaid > 0.0 || $order->getState() === Order::STATE_PROCESSING || $order->getState() === Order::STATE_COMPLETE,
        ];
    }

    public function invoiceOrder(int $orderId, string $paidStatus, string $comment, string $transactionId, bool $notifyCustomer): void
    {
        $order = $this->getOrder($orderId);
        if ($order === null) {
            throw new \RuntimeException('Magento order to invoice was not found.');
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            throw new \RuntimeException('A canceled Magento order cannot be invoiced automatically.');
        }

        // A redirect gateway parks the order in "payment review" while the buyer
        // pays, and Magento refuses to invoice an order in that state. This webhook
        // IS the outcome of that review, so lift the order out of it BEFORE asking
        // canInvoice() — otherwise the invoice is silently skipped and the order
        // ends up in a paid status with no payment recorded and no invoice
        // document at all, which is what a live 2.4.7-p3 store did on every order.
        if ($order->isPaymentReview()) {
            $order->setState(Order::STATE_PROCESSING);
        }

        // A retry after the native transaction committed must reuse that exact
        // paid invoice. A state label or canInvoice() === false is not proof.
        $alreadyInvoiced = false;
        foreach ($order->getInvoiceCollection()->clear() as $existingInvoice) {
            if ((int) $existingInvoice->getState() === \Magento\Sales\Model\Order\Invoice::STATE_PAID
                && (string) $existingInvoice->getTransactionId() === $transactionId
                && AmountGuard::amountsEqual($existingInvoice->getGrandTotal(), $order->getGrandTotal())
                && strtoupper((string) $existingInvoice->getOrderCurrencyCode()) === strtoupper((string) $order->getOrderCurrencyCode())
            ) {
                $alreadyInvoiced = true;
                break;
            }
        }
        if (!$alreadyInvoiced) {
            if (!$order->canInvoice()) {
                throw new \RuntimeException('Magento order cannot be invoiced and has no matching paid invoice.');
            }
            $payment = $order->getPayment();
            if ($payment === null) {
                throw new \RuntimeException('Magento order has no payment to invoice.');
            }
            $payment->setLastTransId($transactionId);
            $payment->setTransactionId($transactionId);
            // This verified settlement completes the redirect authorization.
            // Otherwise Magento register() may leave an offline invoice open.
            $payment->setIsTransactionPending(false);
            $payment->setIsTransactionClosed(true);

            $invoice = $this->invoiceService->prepareInvoice($order);
            if (!$invoice || (float) $invoice->getTotalQty() <= 0
                || !AmountGuard::amountsEqual($invoice->getGrandTotal(), $order->getGrandTotal())
            ) {
                throw new \RuntimeException('Magento could not prepare a full invoice for the Paymos payment.');
            }
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->setTransactionId($transactionId);
            $invoice->register();
            if ((int) $invoice->getState() !== \Magento\Sales\Model\Order\Invoice::STATE_PAID) {
                throw new \RuntimeException('Magento did not register the Paymos invoice as paid.');
            }

            $transactionSave = $this->transaction->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();

            if ($notifyCustomer) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                    $this->logFailure('Paymos could not send the invoice email.', ['error' => $e->getMessage()]);
                }
            }
        }

        // A delayed snapshot retry must not undo fulfilment or a later refund.
        if ($alreadyInvoiced && in_array($order->getState(), [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return;
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus($paidStatus !== '' ? $paidStatus : Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory($comment, $order->getStatus())->setIsCustomerNotified($notifyCustomer);
        $this->orderRepository->save($order);
    }

    public function cancelOrder(int $orderId, string $comment): void
    {
        $order = $this->getOrder($orderId);
        if ($order === null) {
            return;
        }

        if ($order->canCancel()) {
            $order->cancel();
        }

        $order->addCommentToStatusHistory($comment, $order->getStatus());
        $this->orderRepository->save($order);
    }

    public function setStatus(int $orderId, string $status, string $comment): void
    {
        $order = $this->getOrder($orderId);
        if ($order === null) {
            return;
        }

        if ($status !== '') {
            $order->setStatus($status);
        }
        $order->addCommentToStatusHistory($comment, $order->getStatus());
        $this->orderRepository->save($order);
    }

    public function hasPaymentFlag(int $orderId, string $key): bool
    {
        $order = $this->getOrder($orderId);
        if ($order === null || $order->getPayment() === null) {
            return false;
        }

        $value = $order->getPayment()->getAdditionalInformation($key);

        return $value !== null && $value !== '';
    }

    public function setPaymentFlag(int $orderId, string $key, string $value): void
    {
        $order = $this->getOrder($orderId);
        if ($order === null || $order->getPayment() === null) {
            return;
        }

        $order->getPayment()->setAdditionalInformation($key, $value);
        $this->orderRepository->save($order);
    }

    public function addComment(int $orderId, string $comment): void
    {
        $order = $this->getOrder($orderId);
        if ($order === null) {
            return;
        }

        $order->addCommentToStatusHistory($comment, $order->getStatus());
        $this->orderRepository->save($order);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $message, array $context = []): void
    {
        if (!$this->settings->debugEnabled()) {
            return;
        }

        $this->logger->info($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logFailure(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    private function getOrder(int $orderId): ?Order
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        if (!$order instanceof Order) {
            return null;
        }
        // OrderRepository caches models. register() mutates payment/items before
        // transaction save, so a failed save must not become the next attempt's
        // evidence. Reset child collections as well as scalar totals and reload.
        $order->reset();
        $order->getResource()->load($order, $orderId);
        return $order->getEntityId() ? $order : null;
    }
}
