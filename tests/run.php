<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$testFiles = array(
    __DIR__ . '/ConfigTest.php',
    __DIR__ . '/EventStoreTest.php',
    __DIR__ . '/CheckoutProcessorTest.php',
    __DIR__ . '/OrderMapperTest.php',
    __DIR__ . '/WebhookProcessorTest.php',
);

foreach ($testFiles as $file) {
    require $file;
}

$tests = array_filter(get_defined_functions()['user'], static function ($name) {
    return strpos($name, 'test_magento_') === 0;
});
sort($tests);

$count = 0;
foreach ($tests as $test) {
    $test();
    $count++;
    echo "PASS {$test}\n";
}

echo "OK {$count} tests\n";
