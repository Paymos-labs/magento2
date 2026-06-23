<?php

declare(strict_types=1);

namespace Paymos\Payment\Service\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

/**
 * Dedicated file handler so Paymos diagnostics land in var/log/paymos.log
 * instead of the shared system log.
 */
final class Handler extends Base
{
    /** @var int */
    protected $loggerType = MonologLogger::DEBUG;

    /** @var string */
    protected $fileName = '/var/log/paymos.log';
}
