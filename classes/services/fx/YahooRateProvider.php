<?php

/**
 * @file plugins/paymethod/multipay/classes/services/fx/YahooRateProvider.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class YahooRateProvider
 *
 * @brief Default exchange-rate source using Yahoo Finance's keyless chart
 *        endpoint (ticker "{BASE}{QUOTE}=X", e.g. USDUGX=X). Covers African
 *        currency pairs (UGX, NGN, GHS, KES). This is an unofficial/undocumented
 *        endpoint with no SLA, so every failure returns null and the estimate
 *        is simply hidden. Swappable via ConfigurableRateProvider.
 */

namespace APP\plugins\paymethod\multipay\classes\services\fx;

use APP\plugins\paymethod\multipay\classes\HttpClient;

class YahooRateProvider implements RateProvider
{
    protected HttpClient $httpClient;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?: new HttpClient();
    }

    public function getId(): string
    {
        return 'yahoo';
    }

    public function getRate(string $base, string $quote): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === '' || $quote === '') {
            return null;
        }
        if ($base === $quote) {
            return 1.0;
        }

        $symbol = $base . $quote . '=X';
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
            . '?interval=1d&range=1d';

        try {
            $response = $this->httpClient->request('GET', $url, [
                // Browser-like UA: the endpoint rejects some default agents.
                'User-Agent' => 'Mozilla/5.0 (compatible; OJS-MultiPay/1.1)',
                'Accept' => 'application/json',
            ]);
        } catch (\Throwable $e) {
            error_log('[multipay] Yahoo FX fetch failed: ' . $e->getMessage());
            return null;
        }

        if ((int) ($response['status'] ?? 0) !== 200) {
            return null;
        }
        $price = $response['body']['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        if (!is_numeric($price) || (float) $price <= 0) {
            return null;
        }
        return (float) $price;
    }
}
