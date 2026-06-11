<?php

require_once dirname(__DIR__) . '/classes/GatewayAdapterInterface.php';
require_once dirname(__DIR__) . '/classes/HttpClient.php';
require_once dirname(__DIR__) . '/classes/PaystackAdapter.php';
require_once dirname(__DIR__) . '/classes/FlutterwaveAdapter.php';

$paystack = new \APP\plugins\paymethod\multipay\classes\PaystackAdapter('pk', 'sk');
$payload = '{"event":"charge.success"}';
$sig = hash_hmac('sha512', $payload, 'sk');
if (!$paystack->validateWebhook($payload, ['x-paystack-signature' => $sig])) {
    return false;
}
if ($paystack->supportsCurrency('NGN') !== true || $paystack->supportsCurrency('JPY') !== false) {
    return false;
}

$flutterwave = new \APP\plugins\paymethod\multipay\classes\FlutterwaveAdapter('pk', 'sk', 'whsec');
if (!$flutterwave->validateWebhook('{}', ['verif-hash' => 'whsec'])) {
    return false;
}
if ($flutterwave->supportsCurrency('USD') !== true || $flutterwave->supportsCurrency('JPY') !== false) {
    return false;
}
return true;

