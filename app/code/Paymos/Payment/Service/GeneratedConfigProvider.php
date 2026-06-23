<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Locates and loads the dashboard-generated paymos-config.php that sits at the
 * module root (next to registration.php). The dashboard ZIP injects this file;
 * the merchant never edits it. Loaded once per request.
 */
final class GeneratedConfigProvider
{
    /** @var ComponentRegistrar */
    private $componentRegistrar;

    /** @var Config|null */
    private $cached;

    public function __construct(ComponentRegistrar $componentRegistrar)
    {
        $this->componentRegistrar = $componentRegistrar;
    }

    public function get(): Config
    {
        if ($this->cached === null) {
            $this->cached = Config::fromFile($this->path());
        }

        return $this->cached;
    }

    public function path(): string
    {
        $moduleDir = $this->componentRegistrar->getPath(
            ComponentRegistrar::MODULE,
            'Paymos_Payment'
        );

        return rtrim((string) $moduleDir, '/\\') . '/paymos-config.php';
    }
}
