<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

/**
 * Result of processing a webhook: the HTTP status the controller must emit and a
 * short body. The status codes are the contract the Paymos delivery worker's
 * retry logic depends on (2xx = stop retrying).
 */
final class CallbackResult
{
    /** @var int */
    private $httpCode;

    /** @var string */
    private $message;

    /** @var bool */
    private $duplicate;

    public function __construct($httpCode, $message, $duplicate = false)
    {
        $this->httpCode = (int) $httpCode;
        $this->message = (string) $message;
        $this->duplicate = (bool) $duplicate;
    }

    public function httpCode(): int
    {
        return $this->httpCode;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isDuplicate(): bool
    {
        return $this->duplicate;
    }
}
