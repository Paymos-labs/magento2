<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\AlreadyExistsException;
use Paymos\Webhook\CommitAwareEventStoreInterface;

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
class EventStore implements CommitAwareEventStoreInterface
{
    private const TABLE = 'paymos_payment_event';

    /** Reservation window before commit(); a crashed callback frees the id quickly. */
    private const RESERVATION_TTL_SECONDS = 300;

    /** @var ResourceConnection */
    private $resource;

    /** @var string */
    private $pendingEventId = '';

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

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

        $ownerToken = bin2hex(random_bytes(16));

        try {
            $connection->insert($table, [
                'event_id' => $eventId,
                'owner_token' => $ownerToken,
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
        $this->pendingOwnerToken = $ownerToken;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * The table keeps no creation time, so a row counts as committed once it
     * outlives any reservation remember() could have given it.
     *
     * @param string $eventId
     */
    public function isCommitted($eventId): bool
    {
        $eventId = (string) $eventId;
        if ($eventId === '') {
            return false;
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), 'expires_at')
            ->where('event_id = ?', $eventId)
            ->limit(1);
        $expiresAt = $connection->fetchOne($select);

        return $expiresAt !== false && (int) $expiresAt > time() + self::RESERVATION_TTL_SECONDS;
    }

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero affected rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     */
    public function commit(): bool
    {
        if ($this->pendingEventId === '') {
            return false;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $affected = $connection->update(
            $table,
            ['expires_at' => time() + $this->pendingTtlSeconds],
            [
                'event_id = ?' => $this->pendingEventId,
                'owner_token = ?' => $this->pendingOwnerToken,
            ]
        );

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return (int) $affected > 0;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
    public function release(): void
    {
        if ($this->pendingEventId === '') {
            return;
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, [
            'event_id = ?' => $this->pendingEventId,
            'owner_token = ?' => $this->pendingOwnerToken,
        ]);

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
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
