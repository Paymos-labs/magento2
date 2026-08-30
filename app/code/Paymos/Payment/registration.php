<?php

/**
 * Paymos payment module registration.
 *
 * Registers Paymos_Payment with the Magento component registrar so the
 * framework discovers the module under app/code/Paymos/Payment.
 *
 * @see https://developer.adobe.com/commerce/php/development/build/component-registration
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Paymos_Payment',
    __DIR__
);

// paymos:bundled-sdk-fallback:begin
// MANUAL (dashboard ZIP) installs ship the Paymos PHP SDK vendored under
// vendor/paymos/php-sdk and load it here, because Magento does not autoload a
// module-local vendor/. COMPOSER installs already have the SDK on the global
// autoloader, so the guard skips it.
//
// This whole block ships only in the direct-install ZIP. build-cms-store-submission.ps1
// drops vendor/, Autoloader.php and this block when it assembles the Marketplace
// component, because Magento2.Security.IncludeFile forbids require in a Composer
// package. The markers are the packaging contract: moving or rewording them outside
// this block breaks the build.
if (!class_exists(\Paymos\Client::class, false) && is_file(__DIR__ . '/Autoloader.php')) {
    require_once __DIR__ . '/Autoloader.php';
    \Paymos\Payment\Autoloader::registerBundledSdk(__DIR__);
}
// paymos:bundled-sdk-fallback:end
