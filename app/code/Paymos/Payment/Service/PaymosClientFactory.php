<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\Client;

/**
 * Builds a Paymos SDK Client for a given environment from the generated config.
 * Centralises client construction so controllers and the webhook processor
 * share one factory (and tests can swap a fake via a callable).
 */
final class PaymosClientFactory
{
    /** @var GeneratedConfigProvider */
    private $configProvider;

    public function __construct(GeneratedConfigProvider $configProvider)
    {
        $this->configProvider = $configProvider;
    }

    public function create(string $environment): Client
    {
        return new Client($this->configProvider->get()->clientConfigForEnvironment($environment));
    }

    /**
     * A callable suitable for CheckoutProcessor / WebhookProcessor client
     * factories: fn(string $environment): Client.
     */
    public function asCallable(): callable
    {
        return function ($environment) {
            return $this->create((string) $environment);
        };
    }
}
