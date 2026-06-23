<?php

declare(strict_types=1);

namespace Paymos\Payment;

/**
 * Registers a PSR-4 autoloader for the bundled Paymos PHP SDK (the `Paymos\`
 * namespace, excluding this module's own `Paymos\Payment\` classes which Magento
 * autoloads). Only used for manual (dashboard ZIP) installs where the SDK ships
 * under the module's vendor/ directory; the Composer install provides the SDK on
 * the global autoloader, so this is skipped (see registration.php).
 */
final class Autoloader
{
    /** @var bool */
    private static $registered = false;

    public static function registerBundledSdk(string $moduleDir): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $sdkSrc = rtrim($moduleDir, '/\\') . '/vendor/paymos/php-sdk/src';
        if (!is_dir($sdkSrc)) {
            return;
        }

        spl_autoload_register(static function ($class) use ($sdkSrc) {
            $prefix = 'Paymos\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            // The module's own classes are autoloaded by Magento's PSR-4 map.
            if (strncmp($class, 'Paymos\\Payment\\', strlen('Paymos\\Payment\\')) === 0) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $sdkSrc . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });
    }
}
