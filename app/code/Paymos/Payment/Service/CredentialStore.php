<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Paymos\Plugin\CredentialSet;

final class CredentialStore
{
    private const CREDENTIALS_PATH = 'payment/paymos/credentials_v1';
    private const STATE_PATH = 'payment/paymos/connect_state_v1';

    private $scopeConfig;
    private $writer;
    private $encryptor;
    private $cacheTypes;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $writer,
        EncryptorInterface $encryptor,
        TypeListInterface $cacheTypes
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->writer = $writer;
        $this->encryptor = $encryptor;
        $this->cacheTypes = $cacheTypes;
    }

    public function loadCredentials(): array
    {
        $payload = $this->load(self::CREDENTIALS_PATH);
        if (count($payload) === 0) {
            return [];
        }
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        return CredentialSet::normalize($payload['environments']);
    }

    public function saveCredentials(array $environments): void
    {
        $this->save(self::CREDENTIALS_PATH, [
            'schema' => 1,
            'environments' => CredentialSet::normalize($environments),
        ]);
    }

    public function saveState(array $state): void
    {
        $this->save(self::STATE_PATH, [
            'schema' => 1,
            'expires_at' => time() + (int) $state['expires_in'],
            'state' => $state,
        ]);
    }

    public function loadState(): array
    {
        $payload = $this->load(self::STATE_PATH);
        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || time() >= (int) $payload['expires_at']) {
            $this->clearState();
            return [];
        }
        return $payload['state'];
    }

    public function clearState(): void
    {
        $this->writer->delete(self::STATE_PATH);
        $this->cacheTypes->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    private function load(string $path): array
    {
        $encrypted = trim((string) $this->scopeConfig->getValue($path));
        if ($encrypted === '') {
            return [];
        }
        $json = $this->encryptor->decrypt($encrypted);
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Stored Paymos protected data is invalid.');
        }
        return $payload;
    }

    private function save(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Paymos protected data could not be serialized.');
        }
        $this->writer->save($path, $this->encryptor->encrypt($json));
        $this->cacheTypes->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
