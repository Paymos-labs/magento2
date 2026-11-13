<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\ResourceConnection;

/**
 * paymos_payment_invoice-backed snapshot store. Stores the amount/currency/
 * project snapshot at invoice-creation time so AmountGuard and the reverse
 * verifier have a trusted baseline, and so a changed order can renew its invoice.
 */
class InvoiceSnapshotRepository implements SnapshotRepositoryInterface
{
    private const TABLE = 'paymos_payment_invoice';

    /** @var ResourceConnection */
    private $resource;

    private $locks = [];

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function synchronized(int $orderId, callable $action)
    {
        if (isset($this->locks[$orderId])) {
            $this->assertLocked($orderId);
            return $action();
        }
        $connection = $this->resource->getConnection();
        $name = 'paymos_order_' . sha1($this->resource->getTableName(self::TABLE) . ':' . $orderId);
        if ((int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$name]) !== 1) {
            throw new \RuntimeException('Paymos order is being updated. Please retry.');
        }
        $this->locks[$orderId] = $name;
        try {
            $this->assertLocked($orderId);
            return $action();
        } finally {
            unset($this->locks[$orderId]);
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function assertLocked(int $orderId): void
    {
        if (!isset($this->locks[$orderId]) || (int) $this->resource->getConnection()->fetchOne(
            'SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [$this->locks[$orderId]]
        ) !== 1) {
            throw new \RuntimeException('Paymos order lock was lost. Please retry.');
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    public function save(array $row): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $data = [
            'order_id' => (int) $row['order_id'],
            'order_increment_id' => (string) $row['order_increment_id'],
            'external_order_id' => (string) $row['external_order_id'],
            'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
            'environment' => (string) $row['environment'],
            'project_id' => (string) $row['project_id'],
            'amount' => (string) $row['amount'],
            'currency' => (string) $row['currency'],
            'payment_url' => (string) $row['payment_url'],
            'status' => (string) $row['status'],
            'renew_count' => (int) $row['renew_count'],
        ];

        // A fresh invoice for the same order (renewed after an amount change)
        // carries a new external_order_id and paymos_invoice_id, so insert a new
        // row; updating in place would lose the prior invoice's snapshot used by
        // a late webhook for that older invoice.
        $connection->insert($table, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByExternalOrderId(string $externalOrderId)
    {
        return $this->fetchRow('external_order_id', $externalOrderId);
    }

    /**
     * The most recent snapshot for the order (the live invoice).
     *
     * @return array<string, mixed>|null
     */
    public function findByOrderId(int $orderId)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table)
            ->where('order_id = ?', $orderId)
            ->order('entity_id DESC')
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    public function updateStatus(string $paymosInvoiceId, string $status): void
    {
        if ($paymosInvoiceId === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->update(
            $table,
            ['status' => $status],
            ['paymos_invoice_id = ?' => $paymosInvoiceId,
                'status NOT IN (?)' => ['paid', 'paid_over', 'underpaid', 'expired', 'cancelled']]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findUnpaidRecent(int $limit, int $createdAfterUnix): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table)
            ->where('status NOT IN (?)', ['paid', 'paid_over', 'underpaid', 'expired', 'cancelled'])
            ->where("(status = 'payment_review' OR created_at >= ?)", date('Y-m-d H:i:s', $createdAfterUnix))
            ->order('entity_id DESC')
            ->limit($limit);

        $rows = $connection->fetchAll($select);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $column, string $value)
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table)
            ->where($column . ' = ?', $value)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }
}
