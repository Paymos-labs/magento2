<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

/**
 * The narrow slice of Magento order behaviour the OrderMapper needs. Keeping it
 * behind an interface isolates the crypto-critical mapping (StatusMapper,
 * AmountGuard, roll-back guard) from the Magento framework so it stays unit
 * testable with a plain stub.
 *
 * The concrete implementation (MagentoOrderGateway) wraps OrderRepository,
 * InvoiceService, DB\Transaction and InvoiceSender.
 */
interface MagentoOrderGatewayInterface
{
    /**
     * Order summary used by the mapper, or null when the order is missing.
     *
     * @return array{order_id:int, increment_id:string, amount:string, currency:string, state:string, status:string, is_paid:bool}|null
     */
    public function loadOrder(int $orderId);

    /**
     * Invoice the order (create the Magento invoice) and move it to the paid
     * status. Idempotent: a no-op when the order can no longer be invoiced.
     *
     * @return void
     */
    public function invoiceOrder(int $orderId, string $paidStatus, string $comment, string $transactionId, bool $notifyCustomer);

    /**
     * Cancel an order that has not yet been paid.
     *
     * @return void
     */
    public function cancelOrder(int $orderId, string $comment);

    /**
     * Set the order status without changing the paid/cancelled lifecycle
     * (confirming / awaiting-payment transitions), adding a history comment.
     *
     * @return void
     */
    public function setStatus(int $orderId, string $status, string $comment);

    /**
     * Add a history comment without changing state (manual review / ignore).
     *
     * @return void
     */
    public function addComment(int $orderId, string $comment);

    /**
     * @param array<string, mixed> $context
     * @return void
     */
    public function log(string $message, array $context = []);
}
