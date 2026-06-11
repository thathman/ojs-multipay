<?php

require_once dirname(__DIR__) . '/classes/services/ReferenceService.php';

$service = new \APP\plugins\paymethod\multipay\classes\services\ReferenceService();
$reference = $service->generateReference(42);
if (!preg_match('/^OJSMP_42_\d+_[a-f0-9]{8}$/', $reference)) {
    return false;
}
$trace = $service->generateTraceId();
if (!preg_match('/^[a-f0-9]{32}$/', $trace)) {
    return false;
}
$callback = $service->callbackDedupeKey('PayStack', 'ABC');
if ($callback !== 'return:paystack:ABC') {
    return false;
}
$webhook = $service->webhookDedupeKey('FlutterWave', 'charge.success', 'REF');
if ($webhook !== 'webhook:flutterwave:charge.success:REF') {
    return false;
}
return true;

