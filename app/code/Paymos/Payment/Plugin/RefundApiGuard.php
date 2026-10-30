<?php

declare(strict_types=1);

namespace Paymos\Payment\Plugin;

use Magento\Sales\Api\RefundInvoiceInterface;
use Paymos\Payment\Service\RefundNativeGateway;

class RefundApiGuard
{
    private $native;
    private $guard;

    public function __construct(RefundNativeGateway $native, RefundGuard $guard)
    {
        $this->native = $native;
        $this->guard = $guard;
    }

    public function beforeExecute($subject, $id): void
    {
        $orderId = $subject instanceof RefundInvoiceInterface
            ? $this->native->entryOrderId(0, (int) $id) : (int) $id;
        $this->guard->assertOrderAllowed($orderId);
    }
}
