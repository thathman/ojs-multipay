<?php

/**
 * @file plugins/paymethod/multipay/tests/PaystackAmountTest.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Verifies PaystackAdapter converts amounts to the correct smallest unit per
 * ISO-4217 exponent (2-decimal => x100, zero-decimal => x1, 3-decimal => x1000)
 * and round-trips verify/normalize amounts back to major units.
 */

require_once dirname(__DIR__) . '/classes/GatewayAdapterInterface.php';
require_once dirname(__DIR__) . '/classes/HttpClient.php';
require_once dirname(__DIR__) . '/classes/PaystackAdapter.php';

use APP\plugins\paymethod\multipay\classes\HttpClient;
use APP\plugins\paymethod\multipay\classes\PaystackAdapter;

/** Capturing fake: records the outbound body and returns canned responses. */
$fakeHttp = new class extends HttpClient {
    public array $lastBody = [];
    public array $nextResponse = ['status' => 200, 'body' => []];
    public function request(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $this->lastBody = $body ?? [];
        return $this->nextResponse;
    }
};

$adapter = new PaystackAdapter('pk', 'sk', $fakeHttp);

// --- Outbound conversion (initializePayment) ---
$cases = [
    ['NGN', 1500.50, 150050], // 2-decimal -> x100
    ['USD', 10.00, 1000],     // 2-decimal -> x100
    ['JPY', 1500.0, 1500],    // zero-decimal -> x1
    ['KWD', 12.345, 12345],   // 3-decimal -> x1000
];
foreach ($cases as [$currency, $amount, $expectedMinor]) {
    $fakeHttp->nextResponse = ['status' => 200, 'body' => [
        'status' => true,
        'data' => ['authorization_url' => 'https://pay', 'reference' => 'ref', 'access_code' => 'ac'],
    ]];
    $adapter->initializePayment([
        'email' => 'a@b.c', 'amount' => $amount, 'currency' => $currency,
        'reference' => 'ref', 'callbackUrl' => 'https://cb', 'metadata' => [],
    ]);
    if ((int) $fakeHttp->lastBody['amount'] !== $expectedMinor) {
        fwrite(STDERR, "PaystackAmountTest: $currency $amount expected $expectedMinor got {$fakeHttp->lastBody['amount']}\n");
        return false;
    }
}

// --- Inbound conversion (verifyTransaction round-trips back to major units) ---
$fakeHttp->nextResponse = ['status' => 200, 'body' => [
    'status' => true,
    'data' => ['status' => 'success', 'amount' => 150050, 'currency' => 'NGN', 'reference' => 'ref', 'id' => '99'],
]];
$verified = $adapter->verifyTransaction('ref');
if (abs($verified['amount'] - 1500.50) > 0.001) {
    fwrite(STDERR, 'PaystackAmountTest: verify NGN 150050 should be 1500.50, got ' . $verified['amount'] . "\n");
    return false;
}

$fakeHttp->nextResponse = ['status' => 200, 'body' => [
    'status' => true,
    'data' => ['status' => 'success', 'amount' => 1500, 'currency' => 'JPY', 'reference' => 'ref', 'id' => '99'],
]];
$verifiedJpy = $adapter->verifyTransaction('ref');
if (abs($verifiedJpy['amount'] - 1500.0) > 0.001) {
    fwrite(STDERR, 'PaystackAmountTest: verify JPY 1500 should be 1500, got ' . $verifiedJpy['amount'] . "\n");
    return false;
}

return true;
