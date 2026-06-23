<?php

declare(strict_types=1);

namespace Paymos\Payment\Gateway\Config;

use Magento\Payment\Gateway\Config\Config as GatewayConfig;

/**
 * Thin marker subclass of the standard gateway Config so the payment facade,
 * value handler and ConfigProvider all resolve the same `payment/paymos/*`
 * configuration helper through DI (etc/di.xml binds the method code here).
 *
 * @see https://developer.adobe.com/commerce/php/development/payments-integrations/base-integration/
 */
final class Config extends GatewayConfig
{
}
