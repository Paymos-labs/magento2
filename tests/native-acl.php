<?php

declare(strict_types=1);

/**
 * Run against an installed Magento, never the stubbed unit-test bootstrap.
 * Usage: php tests/native-acl.php /path/to/magento
 * Test roles exist only in this process's ACL object; no database writes.
 */
try {
    $root = realpath($argv[1] ?? '');
    if ($root === false || !is_file($root . '/app/bootstrap.php')) {
        throw new RuntimeException('An installed Magento directory is required.');
    }

    require $root . '/app/bootstrap.php';
    $bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
    $objects = $bootstrap->getObjectManager();
    $objects->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

    // DI compilation alone does not build the ACL. This executes the real
    // resource, role and rule loaders, including duplicate-resource detection.
    $acl = $objects->get(\Magento\Framework\Acl\Builder::class)->getAcl();
    $resources = $objects->get(\Magento\Framework\Acl\AclResource\Provider::class)->getAclResources();
    $paths = [];
    $visit = static function (array $nodes, array $parents = []) use (&$visit, &$paths): void {
        foreach ($nodes as $node) {
            $path = array_merge($parents, [$node['id']]);
            $paths[$node['id']][] = $path;
            $visit($node['children'] ?? [], $path);
        }
    };
    $visit($resources);

    $expected = [
        'Magento_Backend::admin',
        'Magento_Backend::stores',
        'Magento_Backend::stores_settings',
        'Magento_Config::config',
        'Paymos_Payment::connect',
    ];
    if (count($paths['Magento_Config::config'] ?? []) !== 1
        || ($paths['Paymos_Payment::connect'] ?? []) !== [$expected]
        || !$acl->hasResource('Magento_Catalog::products')) {
        throw new RuntimeException('Native ACL resource hierarchy is incorrect.');
    }

    $role = 'paymos-ci-config-only';
    if ($acl->hasRole($role)) {
        throw new RuntimeException('The isolated test role unexpectedly exists.');
    }
    $acl->addRole(new \Laminas\Permissions\Acl\Role\GenericRole($role));
    $acl->deny($role);
    if ($acl->isAllowed($role, 'Paymos_Payment::connect')
        || $acl->isAllowed($role, 'Magento_Catalog::products')) {
        throw new RuntimeException('An unprivileged role received access.');
    }
    $acl->allow($role, 'Magento_Config::config');
    if (!$acl->isAllowed($role, 'Paymos_Payment::connect')
        || $acl->isAllowed($role, 'Magento_Catalog::products')) {
        throw new RuntimeException('Configuration inheritance broadened catalog permissions.');
    }

    echo json_encode([
        'passed' => true,
        'php' => PHP_VERSION,
        'configuration_resource_count' => count($paths['Magento_Config::config']),
        'connect_path' => $expected,
        'deny_all_rejected' => true,
        'config_grants_connect_only' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, json_encode([
        'passed' => false,
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
