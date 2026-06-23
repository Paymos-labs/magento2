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
