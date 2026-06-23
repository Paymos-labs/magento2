<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\WebhookEvent;

/**
 * Maps a verified Paymos invoice event onto a Magento order. Order state is
 * driven ONLY by StatusMapper::invoiceAction across all 8 events / 9 statuses —
 * never by hardcoded status strings. (`confirmed`/`failed` are NOT invoice
 * statuses — `confirmed` is a transfers[].status sub-value — so they are not
 * mapped here.)
 *
 *   ACTION_PAYMENT_COMPLETE (paid, paid_over)        -> invoice + paid status
 *   ACTION_CONFIRMING (confirming)                   -> keep pending, set status
 *   ACTION_AWAITING_PAYMENT (underpaid_waiting, reorg)-> keep pending
 *   ACTION_FAIL_ORDER (underpaid)                    -> cancel (unless paid)
 *   ACTION_CANCEL_ORDER (expired, cancelled)         -> cancel (unless paid)
 *   ACTION_IGNORE                                    -> nothing
 *
 * A PAYMENT_COMPLETE whose amount no longer matches the order is held (a review
 * comment, no paid transition) and the webhook is acknowledged — never retried.
 *
 * A roll-back guard stops a late cancelled/confirming/underpaid from
 * downgrading an order that is already paid.
 */
final class OrderMapper
{
    /** @var MagentoOrderGatewayInterface */
    private $gateway;

    public function __construct(MagentoOrderGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function gateway(): MagentoOrderGatewayInterface
    {
        return $this->gateway;
    }

    /**
     * @param array<string, mixed> $row     Invoice snapshot row.
     * @param string               $paidStatus Configured paid order status.
     * @return bool True when the order was marked paid by this call.
     */
    public function apply(WebhookEvent $event, array $row, string $paidStatus, bool $notifyCustomer): bool
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        if ($action === StatusMapper::ACTION_IGNORE) {
            return false;
        }

        $orderId = (int) $row['order_id'];
        $order = $this->gateway->loadOrder($orderId);
        if ($order === null) {
            throw new \RuntimeException('Magento order for the Paymos invoice snapshot was not found.');
        }

        // Roll-back guard: out-of-order delivery (a stale confirming, a late
        // cancelled/expired/underpaid after paid) must never downgrade a paid
        // order. Reverse-verify covers forgery, not delivery order.
        if ($this->wouldRollBackPaidOrder($order, $action)) {
            $this->gateway->log(
                'Paymos ignored a stale invoice status after payment completed. Invoice: ' . $event->invoiceId(),
                ['action' => $action, 'order_id' => $orderId]
            );
            return false;
        }

        switch ($action) {
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return $this->complete($event, $row, $order, $paidStatus, $notifyCustomer);

            case StatusMapper::ACTION_CONFIRMING:
                $this->gateway->setStatus(
                    $orderId,
                    (string) $order['status'],
                    'Paymos payment is confirming. Invoice: ' . $event->invoiceId()
                );
                return false;

            case StatusMapper::ACTION_AWAITING_PAYMENT:
                $this->gateway->setStatus(
                    $orderId,
                    (string) $order['status'],
                    'Awaiting Paymos payment. Invoice: ' . $event->invoiceId()
                );
                return false;

            case StatusMapper::ACTION_FAIL_ORDER:
                $this->gateway->cancelOrder(
                    $orderId,
                    'Paymos payment failed or remained underpaid. Invoice: ' . $event->invoiceId()
                );
                return false;

            case StatusMapper::ACTION_CANCEL_ORDER:
                $this->gateway->cancelOrder(
                    $orderId,
                    'Paymos invoice expired or was cancelled. Invoice: ' . $event->invoiceId()
                );
                return false;
        }

        return false;
    }

    /**
     * @param array{order_id:int, increment_id:string, amount:string, currency:string, state:string, status:string, is_paid:bool} $order
     * @param array<string, mixed> $row
     */
    private function complete(WebhookEvent $event, array $row, array $order, string $paidStatus, bool $notifyCustomer): bool
    {
        if (!AmountGuard::isSafeToComplete(
            (string) $row['amount'],
            (string) $row['currency'],
            (string) $order['amount'],
            (string) $order['currency'],
            $event->orderAmount(),
            $event->orderCurrency()
        )) {
            // Amount/currency changed after invoice creation: hold for manual
            // review instead of auto-completing a mismatched order. This is NOT a
            // transient failure — the figures won't change on redelivery — so do
            // NOT throw into the retry path (that would 400 and the server would
            // retry forever, re-adding this comment each time). Add the review
            // comment and return false so the webhook is acknowledged (200).
            $this->gateway->addComment(
                (int) $order['order_id'],
                'Paymos payment needs manual review. ' . AmountGuard::mismatchSummary(
                    (string) $row['amount'],
                    (string) $row['currency'],
                    (string) $order['amount'],
                    (string) $order['currency'],
                    $event->orderAmount(),
                    $event->orderCurrency()
                )
            );
            return false;
        }

        $this->gateway->invoiceOrder(
            (int) $order['order_id'],
            $paidStatus,
            'Paymos payment completed. Invoice: ' . $event->invoiceId(),
            $this->transactionId($event),
            $notifyCustomer
        );

        return true;
    }

    /**
     * @param array{is_paid:bool} $order
     */
    private function wouldRollBackPaidOrder(array $order, string $action): bool
    {
        if (empty($order['is_paid'])) {
            return false;
        }

        return in_array($action, [
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ], true);
    }

    /**
     * The on-chain tx hash (last confirmed transfer) is the most meaningful
     * transaction id; fall back to the invoice id when transfers are absent
     * (always the case in sandbox).
     */
    private function transactionId(WebhookEvent $event): string
    {
        $payload = $event->toArray();
        $transfers = $payload['data']['payment']['transfers'] ?? null;

        if (is_array($transfers)) {
            for ($i = count($transfers) - 1; $i >= 0; $i--) {
                $transfer = $transfers[$i];
                if (is_array($transfer)
                    && isset($transfer['status'], $transfer['tx_hash'])
                    && $transfer['status'] === 'confirmed'
                    && is_scalar($transfer['tx_hash'])
                    && (string) $transfer['tx_hash'] !== ''
                ) {
                    return (string) $transfer['tx_hash'];
                }
            }
        }

        return $event->invoiceId();
    }
}
