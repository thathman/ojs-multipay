<?php

$tests = [
    __DIR__ . '/RoutingServiceTest.php',
    __DIR__ . '/ReferenceServiceTest.php',
    __DIR__ . '/AdapterValidationTest.php',
    __DIR__ . '/PaystackAmountTest.php',
];

$failed = [];
foreach ($tests as $test) {
    $ok = (bool) include $test;
    if (!$ok) {
        $failed[] = basename($test);
    }
}

if (!empty($failed)) {
    fwrite(STDERR, 'Failed tests: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'All MultiPay tests passed.' . PHP_EOL;
return 0;

