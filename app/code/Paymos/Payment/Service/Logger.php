<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Monolog\Logger as MonologLogger;

/**
 * Module logger writing to var/log/paymos.log (handler wired in etc/di.xml).
 * Callers gate routine diagnostics on the admin "Debug logging" toggle;
 * operational failures are logged unconditionally.
 */
final class Logger extends MonologLogger
{
}
