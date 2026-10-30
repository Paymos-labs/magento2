<?php

declare(strict_types=1);

namespace Paymos\Payment\Cron;

use Paymos\Payment\Service\RefundAdapter;

class ReconcileRefunds
{
    private $adapter;

    public function __construct(RefundAdapter $adapter) { $this->adapter = $adapter; }
    public function execute(): void { $this->adapter->run(); }
}
