<?php

namespace APP\plugins\paymethod\multipay\classes\services;

class RoutingService
{
    public function parseAllowedCurrencies(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', strtoupper($raw));
        $parts = array_filter(array_map('trim', $parts));
        return array_values(array_unique($parts));
    }

    public function parseRoutingMap(?string $raw): array
    {
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $currency => $gateway) {
            if (!is_string($currency) || !is_string($gateway)) {
                continue;
            }
            $map[strtoupper(trim($currency))] = strtolower(trim($gateway));
        }
        return $map;
    }

    public function resolveGateway(array $eligibleGateways, string $currency, ?string $userSelectedGateway, array $currencyDefaultMap, ?string $fallbackGateway): ?string
    {
        $currency = strtoupper($currency);
        $eligibleSet = array_flip(array_map('strtolower', $eligibleGateways));
        $selected = $userSelectedGateway ? strtolower($userSelectedGateway) : null;
        if ($selected && isset($eligibleSet[$selected])) {
            return $selected;
        }
        $mapped = $currencyDefaultMap[$currency] ?? null;
        if ($mapped && isset($eligibleSet[strtolower($mapped)])) {
            return strtolower($mapped);
        }
        $fallback = $fallbackGateway ? strtolower($fallbackGateway) : null;
        if ($fallback && isset($eligibleSet[$fallback])) {
            return $fallback;
        }
        return null;
    }
}

