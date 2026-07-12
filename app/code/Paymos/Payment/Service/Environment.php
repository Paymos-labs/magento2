<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

/**
 * One environment block (sandbox or live) of the generated Paymos config.
 * Immutable; base_url defaults to the public host when absent.
 */
final class Environment
{
    /** @var string */
    private $name;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiSecret;

    /** @var string */
    private $projectId;

    /** @var string */
    private $webhookSecret;

    public function __construct($name, $baseUrl, $apiKey, $apiSecret, $projectId, $webhookSecret)
    {
        $this->name = (string) $name;
        $this->baseUrl = trim((string) $baseUrl) !== '' ? trim((string) $baseUrl) : 'https://api.paymos.io';
        $this->apiKey = (string) $apiKey;
        $this->apiSecret = (string) $apiSecret;
        $this->projectId = (string) $projectId;
        $this->webhookSecret = (string) $webhookSecret;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function apiSecret(): string
    {
        return $this->apiSecret;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->projectId !== '';
    }

    /**
     * Reject a mode/credential mismatch early (a "_live_" key under sandbox or a
     * "_test_" key under live) so a misconfigured package fails loudly instead of
     * hitting the wrong environment.
     */
    public function assertCredentialKind(): void
    {
        if (!$this->isConfigured()) {
            throw new \InvalidArgumentException(
                'Paymos ' . $this->name . ' credentials are missing. Connect this store from the Magento admin.'
            );
        }

        if ($this->name === 'sandbox') {
            if (strpos($this->apiKey, '_test_') === false || strpos($this->apiSecret, '_test_') === false) {
                throw new \InvalidArgumentException('Paymos sandbox mode requires *_test_* API credentials.');
            }
            return;
        }

        if (strpos($this->apiKey, '_live_') === false || strpos($this->apiSecret, '_live_') === false) {
            throw new \InvalidArgumentException('Paymos live mode requires *_live_* API credentials.');
        }
    }

    /**
     * Masked api key for the admin diagnostics panel (never the full secret).
     */
    public function maskedApiKey(): string
    {
        return self::mask($this->apiKey);
    }

    private static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 6) . str_repeat('*', 4) . substr($value, -4);
    }
}
