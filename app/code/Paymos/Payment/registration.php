<?php

/**
 * Paymos payment module registration.
 *
 * Registers Paymos_Payment with the Magento component registrar so the
 * framework discovers the module under app/code/Paymos/Payment.
 *
 * For MANUAL (dashboard ZIP) installs the Paymos PHP SDK is bundled under
 * vendor/paymos/php-sdk and registered here, because Magento does not autoload a
 * module-local vendor/. For COMPOSER installs the SDK is already on the global
 * autoloader, so the bundled fallback is skipped.
 *
 * @see https://developer.adobe.com/commerce/php/development/build/component-registration
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Paymos_Payment',
    __DIR__
);

if (!class_exists(\Paymos\Client::class, false) && is_file(__DIR__ . '/Autoloader.php')) {
    require_once __DIR__ . '/Autoloader.php';
    \Paymos\Payment\Autoloader::registerBundledSdk(__DIR__);
}
