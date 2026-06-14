<?php

/**
 * @file plugins/paymethod/multipay/classes/services/fx/RateProvider.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @interface RateProvider
 *
 * @brief Pluggable exchange-rate source. Implementations fetch a single raw
 *        (un-marked-up) rate for a currency pair, returning null on any failure
 *        so the caller can hide the display-only estimate rather than show a
 *        wrong number.
 */

namespace APP\plugins\paymethod\multipay\classes\services\fx;

interface RateProvider
{
    /**
     * Fetch the raw conversion rate: 1 unit of $base = ? units of $quote.
     *
     * @return float|null the rate, or null if unavailable.
     */
    public function getRate(string $base, string $quote): ?float;

    /** Short provider identifier stored alongside cached rates. */
    public function getId(): string;
}
