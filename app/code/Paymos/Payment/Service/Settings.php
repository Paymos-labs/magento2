<?php

declare(strict_types=1);

namespace Paymos\Payment\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Paymos\Payment\Model\Ui\ConfigProvider;

/**
 * Reads the admin presentation/mode settings from payment/paymos/* in the
 * given store scope. Holds NO secrets — those live in the generated config.
 */
final class Settings
{
    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param int|string|null $storeId
     */
    public function mode($storeId = null): string
    {
        return $this->value('mode', $storeId) === 'live' ? 'live' : 'sandbox';
    }

    /**
     * @param int|string|null $storeId
     */
    public function newOrderStatus($storeId = null): string
    {
        return $this->value('order_status', $storeId);
    }

    /**
     * @param int|string|null $storeId
     */
    public function paidOrderStatus($storeId = null): string
    {
        return $this->value('paid_order_status', $storeId);
    }

    /**
     * @param int|string|null $storeId
     */
    public function debugEnabled($storeId = null): bool
    {
        return $this->flag('debug', $storeId);
    }

    /**
     * @param int|string|null $storeId
     */
    public function value(string $key, $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            'payment/' . ConfigProvider::CODE . '/' . $key,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === null ? '' : trim((string) $value);
    }

    /**
     * @param int|string|null $storeId
     */
    private function flag(string $key, $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            'payment/' . ConfigProvider::CODE . '/' . $key,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
