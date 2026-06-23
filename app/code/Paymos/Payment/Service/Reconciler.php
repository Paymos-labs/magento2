<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Client;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\WebhookEvent;

/**
 * Safety net for missed webhooks: pulls recent non-terminal invoices from the
 * Paymos API and re-applies their current status through the same OrderMapper.
 *
 * Because the status comes straight from the authoritative API (a GET on the
 * invoice), reverse-verification is unnecessary here — but the snapshot is still
 * cross-checked against the live invoice before applying, and the roll-back
 * guard + AmountGuard inside OrderMapper still protect the order.
 */
final class Reconciler
{
    /** @var Config */
    private $config;

    /** @var SnapshotRepositoryInterface */
    private $snapshots;

    /** @var OrderMapper */
    private $orderMapper;

    /** @var Settings */
    private $settings;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        Config $config,
        SnapshotRepositoryInterface $snapshots,
        OrderMapper $orderMapper,
        Settings $settings,
        callable $clientFactory = null
    ) {
        $this->config = $config;
        $this->snapshots = $snapshots;
        $this->orderMapper = $orderMapper;
        $this->settings = $settings;
        $this->clientFactory = $clientFactory;
    }

    public function run($now = null): int
    {
        $now = $now === null ? time() : (int) $now;
        $applied = 0;

        foreach ($this->snapshots->findUnpaidRecent(50, $now - 86400) as $row) {
            try {
                $invoice = $this->client((string) $row['environment'])->invoices()->get((string) $row['paymos_invoice_id']);
                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->orderMapper->gateway()->log('Paymos reconcile skipped a snapshot mismatch.', [
                        'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
                    ]);
                    continue;
                }

                $status = $this->field($invoice, ['status']);
                $this->snapshots->updateStatus((string) $row['paymos_invoice_id'], $status);

                $event = new WebhookEvent([
                    'event_id' => 'reconcile_' . (string) $row['paymos_invoice_id'] . '_' . $status,
                    'event_type' => $this->eventTypeForStatus($status),
                    'occurred_at' => $now,
                    'data' => $invoice,
                ]);

                if ($this->orderMapper->apply($event, $row, $this->settings->paidOrderStatus(), false)) {
                    $applied++;
                }
            } catch (\Throwable $e) {
                $this->orderMapper->gateway()->log('Paymos reconcile failed for an invoice.', [
                    'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $applied;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice): bool
    {
        return $this->matches((string) $row['project_id'], $this->field($invoice, ['project_id']))
            && $this->matches((string) $row['external_order_id'], $this->field($invoice, ['order', 'external_id']))
            && $this->matches(strtoupper((string) $row['currency']), strtoupper($this->field($invoice, ['order', 'currency'])))
            && StatusMapper::invoiceAction('', $this->field($invoice, ['status'])) !== StatusMapper::ACTION_IGNORE;
    }

    private function matches(string $expected, string $actual): bool
    {
        $expected = trim($expected);
        $actual = trim($actual);

        return $expected === '' || $actual === '' || $expected === $actual;
    }

    private function eventTypeForStatus(string $status): string
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                // Both underpaid_waiting and the reorg-regression awaiting_payment
                // map to ACTION_AWAITING_PAYMENT; label each with its own real
                // event type rather than collapsing both to underpaid_waiting.
                return $status === 'awaiting_payment' ? 'invoice.awaiting_payment' : 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $status === 'paid_over' ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $status === 'expired' ? 'invoice.expired' : 'invoice.cancelled';
        }

        // Unknown status: leave the synthetic event type empty rather than invent a
        // non-existent type. StatusMapper's status fallback still classifies it,
        // and an unmapped status resolves to ACTION_IGNORE. (snapshotMatches has
        // already filtered ACTION_IGNORE statuses before we build the event, so
        // this branch is defensive.)
        return '';
    }

    /**
     * @return Client|object An SDK client (or a duck-typed test double exposing
     *                       invoices()->get()).
     */
    private function client(string $environment)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client($this->config->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path): string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
