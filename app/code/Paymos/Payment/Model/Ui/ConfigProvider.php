<?php

declare(strict_types=1);

namespace Paymos\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;

/**
 * Exposes the Paymos payment method to the Knockout checkout: its display title
 * and the storefront redirect route the renderer sends the customer to after
 * the order is placed.
 *
 * @see https://developer.adobe.com/commerce/php/development/payments-integrations/base-integration/
 */
final class ConfigProvider implements ConfigProviderInterface
{
    public const CODE = 'paymos';

    /** @var GatewayConfig */
    private $gatewayConfig;

    /** @var UrlInterface */
    private $urlBuilder;

    public function __construct(GatewayConfig $gatewayConfig, UrlInterface $urlBuilder)
    {
        $this->gatewayConfig = $gatewayConfig;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $title = (string) $this->gatewayConfig->getValue('title');
        if ($title === '') {
            $title = 'Pay with crypto (Paymos)';
        }

        return [
            'payment' => [
                self::CODE => [
                    'title' => $title,
                    'redirectUrl' => $this->urlBuilder->getUrl('paymos/payment/redirect', ['_secure' => true]),
                ],
            ],
        ];
    }
}
