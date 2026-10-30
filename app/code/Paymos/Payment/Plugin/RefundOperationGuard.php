<?php

declare(strict_types=1);

namespace Paymos\Payment\Plugin;

class RefundOperationGuard
{
    private $guard;

    public function __construct(RefundGuard $guard) { $this->guard = $guard; }

    public function beforeExecute($subject, $creditmemo, $order, $online = false): void
    {
        $this->guard->assertAllowed($creditmemo, !(bool) $online);
    }
}
