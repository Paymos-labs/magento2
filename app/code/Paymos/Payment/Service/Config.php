<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Paymos\ClientConfig;

/**
 * Immutable Paymos configuration, sourced from the dashboard-generated
 * paymos-config.php (shape: {config_version, environments:{sandbox,live}}).
 *
 * Secrets are read-only and never typed in admin — admin only chooses the
 * active mode (sandbox|live) and presentation. This class mirrors the two-tier
 * config contract every Paymos CMS plugin follows.
 */
final class Config
{
    /** @var array<string, Environment> */
    private $environments;

    /**
     * @param array<string, Environment> $environments
     */
    private function __construct(array $environments)
    {
        $this->environments = $environments;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $environments = [];
        $rawEnvironments = isset($config['environments']) && is_array($config['environments'])
            ? $config['environments']
            : [];

        foreach (['sandbox', 'live'] as $name) {
            $block = isset($rawEnvironments[$name]) && is_array($rawEnvironments[$name])
                ? $rawEnvironments[$name]
                : [];

            $environments[$name] = new Environment(
                $name,
                self::scalar($block, 'base_url'),
                self::scalar($block, 'api_key'),
                self::scalar($block, 'api_secret'),
                self::scalar($block, 'project_id'),
                self::scalar($block, 'webhook_secret')
            );
        }

        return new self($environments);
    }

    /**
     * Load the generated config file; returns an empty config when absent so the
     * module still installs cleanly before the merchant uploads credentials.
     */
    public static function fromFile(string $path): self
    {
        if (is_file($path)) {
            /** @psalm-suppress UnresolvableInclude */
            $config = require $path;
            if (is_array($config)) {
                return self::fromArray($config);
            }
        }

        return self::fromArray(['environments' => []]);
    }

    /**
     * @param string $name sandbox|live (anything else resolves to sandbox)
     */
    public function environment($name): Environment
    {
        $name = $name === 'live' ? 'live' : 'sandbox';

        return $this->environments[$name];
    }

    /**
     * Webhook secrets keyed by environment, for the multi-environment verifier.
     * Only non-empty secrets are returned.
     *
     * @return array<string, string>
     */
    public function webhookSecrets(): array
    {
        $secrets = [];
        foreach (['sandbox', 'live'] as $name) {
            $secret = $this->environments[$name]->webhookSecret();
            if ($secret !== '') {
                $secrets[$name] = $secret;
            }
        }

        return $secrets;
    }

    /**
     * Build an SDK ClientConfig for the given environment, asserting the
     * credential kind matches (sandbox keys carry "_test_", live carry "_live_").
     */
    public function clientConfigForEnvironment($name): ClientConfig
    {
        $environment = $this->environment($name);
        $environment->assertCredentialKind();

        return new ClientConfig(
            $environment->apiKey(),
            $environment->apiSecret(),
            $environment->baseUrl(),
            30
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function scalar(array $source, string $key): string
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }
}
