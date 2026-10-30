<?php

declare(strict_types=1);

namespace Paymos\Payment\Plugin;

use Magento\Framework\Exception\LocalizedException;
use Paymos\Payment\Service\RefundNativeGateway;

class RefundGuard
{
    private $native;

    public function __construct(RefundNativeGateway $native) { $this->native = $native; }

    public function aroundRefund($subject, callable $proceed, $creditmemo, $offlineRequested = false)
    {
        $this->assertAllowed($creditmemo, (bool) $offlineRequested);
        return $proceed($creditmemo, $offlineRequested);
    }

    public function aroundSave($subject, callable $proceed, $creditmemo)
    {
        $this->assertAllowed($creditmemo, true);
        return $proceed($creditmemo);
    }

    public function assertAllowed($creditmemo, bool $offline): void
    {
        // Resolve the persisted payment, not a caller-supplied/cached order object.
        if (!$this->native->permits($creditmemo, $offline)) {
            $orderId = (int) $creditmemo->getOrderId();
            // The resource's _beforeSave can fill a missing order_id from getOrder().
            if ($orderId <= 0 && is_callable([$creditmemo, 'getOrder'])) {
                $order = $creditmemo->getOrder();
                if (is_object($order)) {
                    $orderId = (int) $order->getId();
                }
            }
            $this->assertOrderAllowed($orderId);
        }
    }

    public function assertOrderAllowed(int $orderId): void
    {
        if ($this->native->isPaymos($orderId)) {
            throw new LocalizedException(__('This order was paid with Paymos. To refund it, open the order in the Magento admin and create a credit memo for the full order. The refund then goes through Paymos.'));
        }
    }

    public function aroundSend($subject, callable $proceed, $creditmemo, $forceSyncMode = false)
    {
        if ($creditmemo->getData('paymos_refund_operation_id') && !$this->native->permitsMail($creditmemo)) {
            return false;
        }
        return $proceed($creditmemo, $forceSyncMode);
    }
}
