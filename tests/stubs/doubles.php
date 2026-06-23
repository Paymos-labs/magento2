<?php

declare(strict_types=1);

namespace Paymos\Payment\Tests;

use Paymos\Payment\Service\MagentoOrderGatewayInterface;
use Paymos\Payment\Service\SnapshotRepositoryInterface;
use Paymos\Webhook\EventStoreInterface;

/**
 * Faithful in-memory stand-in for the slice of Magento's DB AdapterInterface that
 * EventStore uses (insert / delete / update / select+fetchOne). It enforces the
 * event_id PRIMARY KEY (throwing DuplicateException on a repeat insert) and
 * honours the two distinct condition shapes EventStore issues:
 *   - delete($t, ['expires_at < ?' => $now]) — prune expired reservations
 *   - delete($t, ['event_id = ?'   => $id])  — drop one in-flight row
 *   - update($t, ['expires_at' => …], ['event_id = ?' => $id]) — extend a row
 * Each row is keyed by its event_id so the PK collision check is exact.
 */
final class FakeDbConnection
{
    /** @var array<string, array<string, mixed>> */
    public $rows = array();

    /**
     * @param array<string, mixed> $bind
     */
    public function insert($table, array $bind)
    {
        $id = (string) $bind['event_id'];
        if (isset($this->rows[$id])) {
            throw new \Magento\Framework\DB\Adapter\DuplicateException('Duplicate entry ' . $id);
        }
        $this->rows[$id] = $bind;

        return 1;
    }

    /**
     * @param array<string, mixed> $bind
     * @param array<string, mixed>|string $where
     */
    public function update($table, array $bind, $where = '')
    {
        $affected = 0;
        foreach ($this->matchingIds($where) as $id) {
            $this->rows[$id] = array_merge($this->rows[$id], $bind);
            $affected++;
        }

        return $affected;
    }

    /**
     * @param array<string, mixed>|string $where
     */
    public function delete($table, $where = '')
    {
        $affected = 0;
        foreach ($this->matchingIds($where) as $id) {
            unset($this->rows[$id]);
            $affected++;
        }

        return $affected;
    }

    public function select()
    {
        return new FakeDbSelect($this);
    }

    public function fetchOne($select)
    {
        if ($select instanceof FakeDbSelect) {
            $id = $select->whereValue();
            return isset($this->rows[$id]) ? $id : false;
        }

        return false;
    }

    /**
     * Resolve a where-clause map to the set of row keys (event ids) it matches,
     * emulating just the two operators EventStore relies on: equality on
     * `event_id` and a `<` comparison on `expires_at`.
     *
     * @param array<string, mixed>|string $where
     * @return array<int, string>
     */
    private function matchingIds($where)
    {
        if (!is_array($where)) {
            return array();
        }

        $ids = array();
        foreach ($this->rows as $id => $row) {
            if ($this->rowMatches($row, $where)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $where
     */
    private function rowMatches(array $row, array $where)
    {
        foreach ($where as $condition => $value) {
            if (strpos($condition, 'expires_at <') !== false) {
                if (!(isset($row['expires_at']) && (int) $row['expires_at'] < (int) $value)) {
                    return false;
                }
                continue;
            }
            if (strpos($condition, 'event_id =') !== false) {
                if ((string) ($row['event_id'] ?? '') !== (string) $value) {
                    return false;
                }
                continue;
            }

            // Unknown condition: be conservative and do not match.
            return false;
        }

        return true;
    }
}

/**
 * Tiny chainable select stub: only what EventStore::exists() builds.
 */
final class FakeDbSelect
{
    /** @var FakeDbConnection */
    private $connection;

    /** @var string */
    private $whereValue = '';

    public function __construct(FakeDbConnection $connection)
    {
        $this->connection = $connection;
    }

    public function from($table, $columns = '*')
    {
        return $this;
    }

    public function where($condition, $value = null)
    {
        if ($value !== null) {
            $this->whereValue = (string) $value;
        }
        return $this;
    }

    public function limit($count = null, $offset = null)
    {
        return $this;
    }

    public function whereValue()
    {
        return $this->whereValue;
    }
}

/**
 * Records order mutations so tests can assert what the mapper did, without a
 * Magento order. `is_paid` is mutated to true after an invoice so the roll-back
 * guard can be exercised across two events.
 */
final class FakeOrderGateway implements MagentoOrderGatewayInterface
{
    /** @var array<string, mixed>|null */
    private $order;

    /** @var array<int, array<string, mixed>> */
    public $calls = array();

    /** @var array<int, array<string, mixed>> */
    public $logs = array();

    /**
     * @param array<string, mixed>|null $order
     */
    public function __construct(array $order = null)
    {
        $this->order = $order ?: array(
            'order_id' => 42,
            'increment_id' => '100000042',
            'amount' => '100.00',
            'currency' => 'USD',
            'state' => 'new',
            'status' => 'pending_payment',
            'is_paid' => false,
        );
    }

    public function setPaid($paid)
    {
        if ($this->order !== null) {
            $this->order['is_paid'] = (bool) $paid;
        }
    }

    public function setAmount($amount, $currency = null)
    {
        if ($this->order !== null) {
            $this->order['amount'] = (string) $amount;
            if ($currency !== null) {
                $this->order['currency'] = (string) $currency;
            }
        }
    }

    public function clearOrder()
    {
        $this->order = null;
    }

    public function loadOrder(int $orderId)
    {
        return $this->order;
    }

    public function invoiceOrder(int $orderId, string $paidStatus, string $comment, string $transactionId, bool $notifyCustomer)
    {
        $this->calls[] = array(
            'op' => 'invoice',
            'order_id' => $orderId,
            'paid_status' => $paidStatus,
            'comment' => $comment,
            'transaction_id' => $transactionId,
            'notify' => $notifyCustomer,
        );
        $this->setPaid(true);
    }

    public function cancelOrder(int $orderId, string $comment)
    {
        $this->calls[] = array('op' => 'cancel', 'order_id' => $orderId, 'comment' => $comment);
    }

    public function setStatus(int $orderId, string $status, string $comment)
    {
        $this->calls[] = array('op' => 'status', 'order_id' => $orderId, 'status' => $status, 'comment' => $comment);
    }

    public function addComment(int $orderId, string $comment)
    {
        $this->calls[] = array('op' => 'comment', 'order_id' => $orderId, 'comment' => $comment);
    }

    public function log(string $message, array $context = array())
    {
        $this->logs[] = array('message' => $message, 'context' => $context);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function opsOfType($op)
    {
        $out = array();
        foreach ($this->calls as $call) {
            if ($call['op'] === $op) {
                $out[] = $call;
            }
        }

        return $out;
    }
}

/**
 * In-memory snapshot repository.
 */
final class InMemorySnapshotRepository implements SnapshotRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    public $rows = array();

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows = array())
    {
        $this->rows = array_values($rows);
    }

    public function save(array $row)
    {
        $this->rows[] = $row;
    }

    public function findByExternalOrderId(string $externalOrderId)
    {
        foreach ($this->rows as $row) {
            if ((string) $row['external_order_id'] === $externalOrderId) {
                return $row;
            }
        }

        return null;
    }

    public function findByOrderId(int $orderId)
    {
        $found = null;
        foreach ($this->rows as $row) {
            if ((int) $row['order_id'] === $orderId) {
                $found = $row;
            }
        }

        return $found;
    }

    public function updateStatus(string $paymosInvoiceId, string $status)
    {
        foreach ($this->rows as $i => $row) {
            if ((string) $row['paymos_invoice_id'] === $paymosInvoiceId) {
                $this->rows[$i]['status'] = $status;
            }
        }
    }

    public function findUnpaidRecent(int $limit, int $createdAfterUnix)
    {
        $terminal = array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled');
        $out = array();
        foreach ($this->rows as $row) {
            if (!in_array((string) $row['status'], $terminal, true)) {
                $out[] = $row;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}

/**
 * Event store double that supports the full remember/commit/release contract so
 * tests can assert the in-flight lock is released on a processing failure.
 */
final class RecordingEventStore implements EventStoreInterface
{
    /** @var array<string, bool> */
    private $seen = array();

    /** @var string */
    private $pending = '';

    /** @var bool */
    public $committed = false;

    /** @var bool */
    public $released = false;

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        if (isset($this->seen[$eventId])) {
            return false;
        }
        $this->seen[$eventId] = true;
        $this->pending = $eventId;

        return true;
    }

    public function commit()
    {
        $this->committed = true;
        $this->pending = '';
    }

    public function release()
    {
        if ($this->pending !== '') {
            unset($this->seen[$this->pending]);
            $this->pending = '';
        }
        $this->released = true;
    }
}

/**
 * Fake SDK Invoices resource for reverse-verify / reconcile.
 */
final class FakeInvoices
{
    /** @var array<int, array<string, mixed>> */
    public $createPayloads = array();

    /** @var array<string, mixed> */
    private $createResponse;

    /** @var array<string, mixed> */
    private $getResponse;

    /**
     * @param array<string, mixed> $createResponse
     * @param array<string, mixed> $getResponse
     */
    public function __construct(array $createResponse = array(), array $getResponse = array())
    {
        $this->createResponse = $createResponse ?: array(
            'invoice_id' => 'inv_123',
            'payment_url' => 'https://paymos.test/pay/inv_123',
            'status' => 'awaiting_client',
        );
        // Server trims trailing zeros on the wire ("100.00" -> "100"); the
        // snapshot keeps "100.00". Reverse-verify must treat them equal.
        $this->getResponse = $getResponse ?: array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => '100000042-0',
                'amount' => '100',
                'currency' => 'USD',
            ),
        );
    }

    public function create(array $payload)
    {
        $this->createPayloads[] = $payload;
        return $this->createResponse;
    }

    public function get($invoiceId)
    {
        return $this->getResponse;
    }
}

/**
 * Fake SDK Client exposing the fake Invoices resource.
 */
final class FakeClient
{
    /** @var FakeInvoices */
    public $invoices;

    public function __construct(FakeInvoices $invoices = null)
    {
        $this->invoices = $invoices ?: new FakeInvoices();
    }

    public function invoices()
    {
        return $this->invoices;
    }
}
