<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

/**
 * Persistence for the per-order Paymos invoice snapshot. The concrete
 * implementation (InvoiceSnapshotRepository) is backed by paymos_payment_invoice;
 * tests use an in-memory stub.
 */
interface SnapshotRepositoryInterface
{
    /** Serialize checkout, webhook and reconciliation for one native order. */
    public function synchronized(int $orderId, callable $action);

    public function assertLocked(int $orderId): void;

    /**
     * @param array<string, mixed> $row
     * @return void
     */
    public function save(array $row);

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId);

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId);

    /**
     * @return void
     */
    public function updateStatus(string $paymosInvoiceId, string $status);

    /**
     * Non-terminal invoices created within the lookback window, for reconcile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent(int $limit, int $createdAfterUnix);
}
