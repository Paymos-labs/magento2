<?php

declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Paymos\Payment\Service\EventStore;
use Paymos\Payment\Tests\FakeDbConnection;

function test_magento_event_store_remembers_new_event()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    assertTrueValue($store->remember('evt_1', 604800), 'A new event must be remembered.');
    assertTrueValue(isset($connection->rows['evt_1']), 'The dedup row must be inserted.');
    // remember() reserves with a short TTL; commit() later extends it.
    assertTrueValue(
        (int) $connection->rows['evt_1']['expires_at'] > 0,
        'The reservation must carry an expiry.'
    );
}

function test_magento_event_store_rejects_duplicate_event()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    assertTrueValue($store->remember('evt_dup', 604800), 'First insert must succeed.');
    assertFalseValue($store->remember('evt_dup', 604800), 'A duplicate event id must return false.');
}

function test_magento_event_store_release_drops_in_flight_lock()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    $store->remember('evt_release', 604800);
    $store->release();

    assertFalseValue(isset($connection->rows['evt_release']), 'release() must delete the in-flight row.');
}

function test_magento_event_store_commit_extends_reservation()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    $store->remember('evt_commit', 604800);
    $reservedExpiry = (int) $connection->rows['evt_commit']['expires_at'];
    $store->commit();

    assertTrueValue(isset($connection->rows['evt_commit']), 'commit() must keep the durable dedup row.');
    assertTrueValue(
        (int) $connection->rows['evt_commit']['expires_at'] > $reservedExpiry,
        'commit() must extend the reservation to the full dedup window.'
    );
}

function test_magento_event_store_empty_event_id_is_not_remembered()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    assertFalseValue($store->remember('', 604800), 'An empty event id must not be remembered.');
}

function test_magento_event_store_prunes_expired_reservation_before_insert()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    // A stale reservation from a crashed callback (already past its expiry).
    $connection->rows['evt_stale'] = ['event_id' => 'evt_stale', 'expires_at' => 1];

    assertTrueValue(
        $store->remember('evt_fresh', 604800),
        'A fresh event must be remembered after pruning expired rows.'
    );
    assertFalseValue(
        isset($connection->rows['evt_stale']),
        'remember() must prune reservations whose expiry has passed.'
    );
}

function test_magento_event_store_tells_a_locked_event_from_a_committed_one()
{
    // BUG-103: remember() says "seen" for both; isCommitted() must not.
    $connection = new FakeDbConnection();
    $first = new EventStore(new ResourceConnection($connection));
    assertTrueValue($first->remember('evt_lock', 604800), 'first delivery takes the lock.');

    $retry = new EventStore(new ResourceConnection($connection));
    assertFalseValue($retry->remember('evt_lock', 604800), 'a retry while the lock is held is not new.');
    assertFalseValue($retry->isCommitted('evt_lock'), 'a locked, uncommitted event is not committed.');

    $first->commit();
    assertTrueValue($retry->isCommitted('evt_lock'), 'after commit the event is committed.');
}

/**
 * A10-02: a delivery whose reservation expired must not delete the successor's
 * row, and while the successor is still busy a third delivery must be rejected
 * as in-flight until the successor commits.
 */
function test_magento_stale_owner_release_does_not_touch_the_successor()
{
    $connection = new FakeDbConnection();
    $ownerA = new EventStore(new ResourceConnection($connection));
    assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

    // Delivery A is stalled past its 300s reservation: rewind the row's expiry
    // so the next remember() prunes it and takes the id over.
    $connection->rows['evt_stale_release']['expires_at'] = time() - 1;

    $ownerB = new EventStore(new ResourceConnection($connection));
    assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
    $tokenB = (string) $connection->rows['evt_stale_release']['owner_token'];
    assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

    // A wakes up and fails: its release() must complete silently and leave B's row.
    $ownerA->release();
    assertTrueValue(isset($connection->rows['evt_stale_release']), 'a stale release must not delete the successor reservation.');
    assertSameValue($tokenB, (string) $connection->rows['evt_stale_release']['owner_token'], 'the successor row must keep its own token.');

    // While B is busy, a third delivery is rejected as in-flight, not duplicate.
    $ownerC = new EventStore(new ResourceConnection($connection));
    assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
    assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: a stale delivery's commit() must report lost ownership (false) and
 * must not extend the successor's short reservation into a committed one.
 */
function test_magento_stale_owner_commit_does_not_confirm_the_successor()
{
    $connection = new FakeDbConnection();
    $ownerA = new EventStore(new ResourceConnection($connection));
    assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

    $connection->rows['evt_stale_commit']['expires_at'] = time() - 1;

    $ownerB = new EventStore(new ResourceConnection($connection));
    assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
    $reservedByB = (int) $connection->rows['evt_stale_commit']['expires_at'];

    assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
    assertSameValue($reservedByB, (int) $connection->rows['evt_stale_commit']['expires_at'], "a stale commit must not extend the successor's reservation.");
    assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

    $ownerC = new EventStore(new ResourceConnection($connection));
    assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: owner tokens come from random_bytes, not the clock — two reservations
 * taken within the same second can never share a token.
 */
function test_magento_owner_tokens_do_not_collide_within_the_same_second()
{
    $connection = new FakeDbConnection();
    $first = new EventStore(new ResourceConnection($connection));
    $second = new EventStore(new ResourceConnection($connection));

    assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
    assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

    $tokenA = (string) $connection->rows['evt_token_a']['owner_token'];
    $tokenB = (string) $connection->rows['evt_token_b']['owner_token'];

    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
    assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
}

/**
 * A10-02: commit() with nothing pending, or against a row taken over by a
 * successor, is a quiet non-success (zero affected rows), never an error.
 */
function test_magento_zero_row_commit_is_reported_as_not_owned()
{
    $connection = new FakeDbConnection();
    $store = new EventStore(new ResourceConnection($connection));

    assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

    assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before losing the row.');
    $connection->rows['evt_zero_rows']['expires_at'] = time() - 1;
    $successor = new EventStore(new ResourceConnection($connection));
    assertTrueValue($successor->remember('evt_zero_rows', 604800), 'the successor takes the expired id over.');
    assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
    assertTrueValue(isset($connection->rows['evt_zero_rows']), 'the failed commit must leave the successor row in place.');
}
