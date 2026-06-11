<?php

require_once dirname(__DIR__) . '/classes/services/RoutingService.php';

$service = new \APP\plugins\paymethod\multipay\classes\services\RoutingService();
$allowed = $service->parseAllowedCurrencies('ngn, usd; eur');
if ($allowed !== ['NGN', 'USD', 'EUR']) {
    return false;
}
$map = $service->parseRoutingMap('{"NGN":"paystack","USD":"flutterwave"}');
if (($map['NGN'] ?? null) !== 'paystack' || ($map['USD'] ?? null) !== 'flutterwave') {
    return false;
}
$resolved = $service->resolveGateway(['paystack', 'flutterwave'], 'NGN', null, $map, 'flutterwave');
if ($resolved !== 'paystack') {
    return false;
}
$fallback = $service->resolveGateway(['flutterwave'], 'GBP', null, $map, 'flutterwave');
if ($fallback !== 'flutterwave') {
    return false;
}
return true;

