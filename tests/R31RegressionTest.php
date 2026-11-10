<?php

declare(strict_types=1);

function test_magento_r31_sql_update_cannot_overwrite_any_final_snapshot()
{
    $db = new \Paymos\Payment\Tests\FakeDbConnection();
    $repository = new \Paymos\Payment\Service\InvoiceSnapshotRepository(new \Magento\Framework\App\ResourceConnection($db));
    foreach (array('paid', 'paid_over', 'underpaid', 'expired', 'cancelled') as $final) {
        $db->rows['invoice'] = array('paymos_invoice_id' => 'inv_123', 'status' => $final);
        $repository->updateStatus('inv_123', 'confirming');
        assertSameValue($final, $db->rows['invoice']['status'], 'WHERE guard preserves ' . $final);
    }
    $db->rows['invoice']['status'] = 'confirming';
    $repository->updateStatus('inv_123', 'paid');
    assertSameValue('paid', $db->rows['invoice']['status'], 'A legitimate final transition remains writable.');
}

function test_magento_r31_connection_owned_mutex_fences_lost_lock_and_releases_on_failure()
{
    $db = new \Paymos\Payment\Tests\FakeDbConnection();
    $repository = new \Paymos\Payment\Service\InvoiceSnapshotRepository(new \Magento\Framework\App\ResourceConnection($db));
    try {
        $repository->synchronized(42, static function () use ($repository, $db) {
            $repository->assertLocked(42);
            $db->locks = array(); // Lost/replaced DB connection.
            $repository->assertLocked(42);
            throw new LogicException('Lost mutex was accepted.');
        });
        throw new LogicException('Expected ownership failure.');
    } catch (RuntimeException $error) {
        assertContainsValue('lock was lost', $error->getMessage(), 'Lost ownership fails closed.');
    }
    assertSameValue(array(), $db->locks, 'Finally releases the connection-owned lock.');
}

function test_magento_r31_waiting_webhook_reloads_snapshot_after_mutex_acquisition()
{
    $snapshots = new \Paymos\Payment\Tests\InMemorySnapshotRepository(array(paymos_m2_snapshot()));
    $snapshots->onLock = static function () use ($snapshots) { $snapshots->rows[0]['status'] = 'paid'; };
    $context = paymos_m2_processor(array('snapshots' => $snapshots));
    $body = json_encode(paymos_m2_invoice_event('evt_stale_r31', 'invoice.confirming', 'confirming'));
    $result = $context['processor']->handle($body, paymos_m2_signed_header('whsec_test_secret', $body, 1709000000), false, 1709000000);
    assertSameValue(200, $result->httpCode(), 'Stale webhook is acknowledged.');
    assertSameValue('paid', $snapshots->rows[0]['status'], 'The freshly committed final snapshot stays final.');
    assertSameValue(array(), $context['gateway']->calls, 'Stale event does not mutate the native order.');
}

function test_magento_r31_rejected_paid_order_transition_does_not_update_snapshot()
{
    foreach (array('webhook', 'reconcile') as $route) {
        $snapshots = new \Paymos\Payment\Tests\InMemorySnapshotRepository(array(paymos_m2_snapshot()));
        $gateway = new \Paymos\Payment\Tests\FakeOrderGateway();
        $gateway->setPaid(true);
        $event = paymos_m2_invoice_event('evt_r31_' . $route, 'invoice.confirming', 'confirming');
        if ($route === 'webhook') {
            $context = paymos_m2_processor(array('snapshots' => $snapshots, 'gateway' => $gateway));
            $body = json_encode($event);
            assertSameValue(200, $context['processor']->handle($body,
                paymos_m2_signed_header('whsec_test_secret', $body, 1709000000), false, 1709000000)->httpCode(), 'Stale event acknowledged.');
        } else {
            paymos_m2_reconciler(array('snapshots' => $snapshots, 'gateway' => $gateway, 'invoice' => $event['data']))->run();
        }
        assertSameValue(paymos_m2_snapshot()['status'], $snapshots->rows[0]['status'], 'Rejected ' . $route . ' transition does not persist.');
        assertSameValue(array(), $gateway->calls, 'Paid native order remains untouched.');
    }
}
