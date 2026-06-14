<?php

/**
 * @file plugins/paymethod/multipay/classes/services/fx/ConfigurableRateProvider.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ConfigurableRateProvider
 *
 * @brief Generic exchange-rate source driven by a staff-supplied URL template,
 *        so the FX provider can be swapped without code if Yahoo's unofficial
 *        endpoint breaks. The URL template may contain {BASE}, {QUOTE} and {KEY}
 *        placeholders. The rate is read from the response via a configurable
 *        dot-path (default "rate"), with common shapes auto-detected.
 */

namespace APP\plugins\paymethod\multipay\classes\services\fx;

use APP\plugins\paymethod\multipay\classes\HttpClient;

class ConfigurableRateProvider implements RateProvider
{
    protected HttpClient $httpClient;
    protected string $urlTemplate;
    protected string $apiKey;
    protected string $ratePath;

    public function __construct(string $urlTemplate, string $apiKey = '', string $ratePath = 'rate', ?HttpClient $httpClient = null)
    {
        $this->urlTemplate = $urlTemplate;
        $this->apiKey = $apiKey;
        $this->ratePath = $ratePath !== '' ? $ratePath : 'rate';
        $this->httpClient = $httpClient ?: new HttpClient();
    }

    public function getId(): string
    {
        return 'custom';
    }

    public function getRate(string $base, string $quote): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($this->urlTemplate === '' || $base === '' || $quote === '') {
            return null;
        }
        if ($base === $quote) {
            return 1.0;
        }

        $url = strtr($this->urlTemplate, [
            '{BASE}' => rawurlencode($base),
            '{QUOTE}' => rawurlencode($quote),
            '{KEY}' => rawurlencode($this->apiKey),
        ]);

        try {
            $response = $this->httpClient->request('GET', $url, ['Accept' => 'application/json']);
        } catch (\Throwable $e) {
            error_log('[multipay] Custom FX fetch failed: ' . $e->getMessage());
            return null;
        }
        if ((int) ($response['status'] ?? 0) !== 200) {
            return null;
        }

        $body = $response['body'] ?? [];
        $value = $this->dig($body, $this->ratePath);
        // Common alternative shapes (e.g. exchangerate-style {"rates":{"UGX":..}}).
        if (!is_numeric($value)) {
            $value = $body['rates'][$quote] ?? $body['data'][$quote] ?? null;
        }
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        return (float) $value;
    }

    /** Read a dot-path (e.g. "data.rate") out of a nested array. */
    private function dig($data, string $path)
    {
        foreach (explode('.', $path) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                return null;
            }
        }
        return $data;
    }
}
