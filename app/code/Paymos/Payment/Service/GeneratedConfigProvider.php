<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

/** Loads locally encrypted credentials once per request. */
final class GeneratedConfigProvider
{
    /** @var Config|null */
    private $cached;

    /** @var CredentialStore */
    private $credentialStore;

    public function __construct(CredentialStore $credentialStore)
    {
        $this->credentialStore = $credentialStore;
    }

    public function get(): Config
    {
        if ($this->cached === null) {
            $this->cached = Config::fromArray([
                'environments' => $this->credentialStore->loadCredentials(),
            ]);
        }

        return $this->cached;
    }
}
