<?php

/**
 * @file plugins/paymethod/multipay/classes/services/ExchangeRateService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ExchangeRateService
 *
 * @brief Display-only currency conversion. Serves rates from the
 *        multipay_exchange_rates cache within a TTL, otherwise fetches from the
 *        configured RateProvider and caches the result. A signed markup percent
 *        is applied to the raw rate for display. Conversion output is ADVISORY
 *        ONLY: the gateway is always charged the journal currency and amount.
 */

namespace APP\plugins\paymethod\multipay\classes\services;

use APP\plugins\paymethod\multipay\classes\Money;
use APP\plugins\paymethod\multipay\classes\services\fx\RateProvider;
use Illuminate\Support\Facades\DB;

class ExchangeRateService
{
    protected RateProvider $provider;
    protected float $markupPercent;
    protected int $cacheTtlSeconds;

    public function __construct(RateProvider $provider, float $markupPercent = 0.0, int $cacheTtlSeconds = 43200)
    {
        $this->provider = $provider;
        $this->markupPercent = $markupPercent;
        $this->cacheTtlSeconds = max(0, $cacheTtlSeconds);
    }

    /**
     * Raw (un-marked-up) rate for 1 $base = ? $quote, cached. Null if unavailable.
     */
    public function getRawRate(string $base, string $quote): ?float
    {
        $base = strtoupper($base);
        $quote = strtoupper($quote);
        if ($base === '' || $quote === '') {
            return null;
        }
        if ($base === $quote) {
            return 1.0;
        }

        $cached = $this->readCache($base, $quote);
        if ($cached !== null) {
            return $cached;
        }

        $rate = $this->provider->getRate($base, $quote);
        if ($rate === null || $rate <= 0) {
            return null;
        }
        $this->writeCache($base, $quote, $rate);
        return $rate;
    }

    /**
     * Display rate = raw rate adjusted by the signed markup percent.
     */
    public function getDisplayRate(string $base, string $quote): ?float
    {
        $raw = $this->getRawRate($base, $quote);
        if ($raw === null) {
            return null;
        }
        return $raw * (1 + ($this->markupPercent / 100));
    }

    /**
     * Convert an amount for display. Returns null when no rate is available so
     * the UI can hide the estimate rather than show a wrong/zero figure.
     *
     * @return array{amount: float, formatted: string, rate: float, raw: float}|null
     */
    public function convertForDisplay(float $amount, string $from, string $to, ?string $locale = null): ?array
    {
        $raw = $this->getRawRate($from, $to);
        if ($raw === null) {
            return null;
        }
        $rate = $raw * (1 + ($this->markupPercent / 100));
        $converted = $amount * $rate;
        return [
            'amount' => $converted,
            'formatted' => Money::format($converted, $to, $locale),
            'rate' => $rate,
            'raw' => $raw,
        ];
    }

    protected function readCache(string $base, string $quote): ?float
    {
        try {
            $row = DB::table('multipay_exchange_rates')
                ->where('base', $base)
                ->where('quote', $quote)
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $age = time() - strtotime((string) $row->fetched_at);
        if ($age > $this->cacheTtlSeconds) {
            return null;
        }
        return (float) $row->rate;
    }

    protected function writeCache(string $base, string $quote, float $rate): void
    {
        try {
            DB::table('multipay_exchange_rates')->updateOrInsert(
                ['base' => $base, 'quote' => $quote],
                [
                    'rate' => $rate,
                    'provider' => $this->provider->getId(),
                    'fetched_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            error_log('[multipay] FX cache write failed: ' . $e->getMessage());
        }
    }
}
