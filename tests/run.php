<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Compile every module source file, not just the ones the tests touch:
// Adobe's setup:di:compile loads all of them, so a defect in an untested file
// must fail HERE. Autoloader.php is excluded — the Marketplace package strips it.
$moduleIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    PAYMOS_M2_MODULE_DIR,
    FilesystemIterator::SKIP_DOTS
));
$moduleFiles = array();
foreach ($moduleIterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && $file->getBasename() !== 'Autoloader.php') {
        $moduleFiles[] = $file->getPathname();
    }
}
sort($moduleFiles);
foreach ($moduleFiles as $moduleFile) {
    require_once $moduleFile;
}


$testFiles = array(
    __DIR__ . '/ConfigTest.php',
    __DIR__ . '/EventStoreTest.php',
    __DIR__ . '/CheckoutProcessorTest.php',
    __DIR__ . '/OrderMapperTest.php',
    __DIR__ . '/WebhookProcessorTest.php',
    __DIR__ . '/ReconcilerTest.php',
    __DIR__ . '/ConnectionStatusTest.php',
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
