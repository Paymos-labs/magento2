<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\DB\Transaction;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Service\InvoiceService;

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
final class MagentoOrderGateway implements MagentoOrderGatewayInterface
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
            'increment_id' => (string) $order->getIncrementId(),
            'amount' => number_format((float) $order->getGrandTotal(), 2, '.', ''),
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
            return;
        }

        if ($order->canInvoice()) {
            $payment = $order->getPayment();
            if ($payment !== null) {
                $payment->setLastTransId($transactionId);
                $payment->setTransactionId($transactionId);
            }

            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
            $invoice->setTransactionId($transactionId);
            $invoice->register();

            $transactionSave = $this->transaction->addObject($invoice)->addObject($invoice->getOrder());
            $transactionSave->save();

            if ($notifyCustomer) {
                try {
                    $this->invoiceSender->send($invoice);
                } catch (\Exception $e) {
                    $this->logUnconditional('Paymos could not send the invoice email.', ['error' => $e->getMessage()]);
                }
            }
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
    private function logUnconditional(string $message, array $context = []): void
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

        return $order instanceof Order ? $order : null;
    }
}
