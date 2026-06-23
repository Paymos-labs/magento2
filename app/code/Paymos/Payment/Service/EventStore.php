<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Paymos\Webhook\EventStoreInterface;

/**
 * Race-proof webhook dedup backed by the paymos_payment_event table (event_id is
 * the PRIMARY KEY). The SDK owns the dedup *logic*; this provides the *backend*.
 *
 * The SDK's EventStoreInterface only defines remember(). commit()/release() are
 * the plugin-side transactional half (called by the processor via method_exists,
 * matching the OpenCart/PrestaShop reference plugins):
 *   remember() — INSERT the event id with a SHORT reservation TTL; a duplicate-key
 *                failure means "already seen" and returns false. The row is the
 *                in-flight lock, and a crashed callback frees the id quickly.
 *   commit()   — extend the reservation to the full dedup window once the order
 *                mutation has succeeded, so a re-delivery is suppressed for the
 *                whole window.
 *   release()  — DELETE the in-flight row so a processing failure does NOT block
 *                the server's retry of the same event.
 */
final class EventStore implements EventStoreInterface
{
    private const TABLE = 'paymos_payment_event';

    /** Reservation window before commit(); a crashed callback frees the id quickly. */
    private const RESERVATION_TTL_SECONDS = 300;

    /** @var ResourceConnection */
    private $resource;

    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        if ($eventId === '') {
            return false;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = time();

        // Prune expired reservations so a crashed callback's id can be retried.
        $connection->delete($table, ['expires_at < ?' => $now]);

        try {
            $connection->insert($table, [
                'event_id' => $eventId,
                'expires_at' => $now + self::RESERVATION_TTL_SECONDS,
            ]);
        } catch (AlreadyExistsException $e) {
            return false;
        } catch (\Magento\Framework\DB\Adapter\DuplicateException $e) {
            return false;
        } catch (\Exception $e) {
            // A unique-constraint violation surfaces as a generic exception on
            // some adapters; treat a row that already exists as "seen".
            if ($this->exists($eventId)) {
                return false;
            }
            throw $e;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * Extend the reservation to the full dedup window now the order mutation has
     * succeeded, so a re-delivery of this event is suppressed for the whole window.
     */
    public function commit(): void
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->update(
            $table,
            ['expires_at' => time() + $this->pendingTtlSeconds],
            ['event_id = ?' => $this->pendingEventId]
        );

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     */
    public function release(): void
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['event_id = ?' => $this->pendingEventId]);

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    private function exists(string $eventId): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $select = $connection->select()
            ->from($table, 'event_id')
            ->where('event_id = ?', $eventId)
            ->limit(1);

        return (bool) $connection->fetchOne($select);
    }
}
