<?php

/**
 * @file plugins/paymethod/multipay/classes/services/LocaleCurrencyService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class LocaleCurrencyService
 *
 * @brief Best-effort detection of a payer's likely country and currency, used
 *        only to show an advisory local-currency estimate and suggest a gateway.
 *        Priority: OJS user profile country -> Cloudflare CF-IPCountry header ->
 *        Accept-Language region -> none. Degrades silently when signals absent.
 */

namespace APP\plugins\paymethod\multipay\classes\services;

class LocaleCurrencyService
{
    /**
     * Compact default ISO country -> ISO currency map. Intentionally focused on
     * the currencies the supported gateways handle plus common ones; staff can
     * extend/override it via the geoCountryCurrencyMap setting (JSON).
     */
    public const DEFAULT_MAP = [
        'NG' => 'NGN', 'GH' => 'GHS', 'KE' => 'KES', 'ZA' => 'ZAR',
        'UG' => 'UGX', 'TZ' => 'TZS', 'RW' => 'RWF', 'US' => 'USD',
        'GB' => 'GBP', 'CA' => 'CAD', 'AU' => 'AUD',
        'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR',
        'NL' => 'EUR', 'IE' => 'EUR', 'PT' => 'EUR',
    ];

    /** @var array<string,string> */
    protected array $map;

    /**
     * @param string $overrideJson optional JSON object {"NG":"NGN",...} merged
     *        over the default map.
     */
    public function __construct(string $overrideJson = '')
    {
        $map = self::DEFAULT_MAP;
        if (trim($overrideJson) !== '') {
            $decoded = json_decode($overrideJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cc => $cur) {
                    $map[strtoupper((string) $cc)] = strtoupper((string) $cur);
                }
            }
        }
        $this->map = $map;
    }

    /**
     * Detect the payer's likely ISO country code, or '' if unknown.
     */
    public function detectCountry($request): string
    {
        // 1) Logged-in user's profile country.
        $user = $request->getUser();
        if ($user && method_exists($user, 'getCountry')) {
            $country = (string) $user->getCountry();
            if ($country !== '') {
                return strtoupper($country);
            }
        }
        // 2) Cloudflare edge header (only present when proxied through Cloudflare).
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            $cc = strtoupper((string) $_SERVER['HTTP_CF_IPCOUNTRY']);
            if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX') {
                return $cc;
            }
        }
        // 3) Accept-Language region subtag (e.g. en-UG -> UG).
        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($accept !== '' && preg_match('/[a-z]{2,3}[-_]([A-Z]{2})/', $accept, $m)) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * Detect the payer's likely ISO currency, or '' if unknown.
     */
    public function detectCurrency($request): string
    {
        $country = $this->detectCountry($request);
        if ($country === '') {
            return '';
        }
        return $this->map[$country] ?? '';
    }

    public function countryName(string $cc): string
    {
        $cc = strtoupper($cc);
        if (class_exists('Locale') && $cc !== '') {
            $name = \Locale::getDisplayRegion('-' . $cc, 'en');
            if ($name && $name !== $cc) {
                return $name;
            }
        }
        return $cc;
    }
}
