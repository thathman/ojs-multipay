<?php

/**
 * @file plugins/paymethod/multipay/classes/Money.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class Money
 *
 * @brief Shared currency helpers: ISO-4217 minor-unit conversion and display
 *        formatting. Extracted so every adapter and the checkout/receipt UI use
 *        one source of truth for per-currency decimal places.
 */

namespace APP\plugins\paymethod\multipay\classes;

class Money
{
    /** ISO-4217 currencies with no minor unit (exponent 0). */
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** ISO-4217 currencies with three minor digits (exponent 3). */
    public const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** Fallback symbol map used when the intl extension is unavailable. */
    private const SYMBOLS = [
        'NGN' => '\u{20A6}', 'USD' => '$', 'GBP' => '\u{00A3}', 'EUR' => '\u{20AC}',
        'GHS' => '\u{20B5}', 'ZAR' => 'R', 'KES' => 'KSh', 'UGX' => 'USh',
        'JPY' => '\u{00A5}', 'KRW' => '\u{20A9}',
    ];

    /**
     * Number of decimal places used by a currency (0, 2, or 3).
     */
    public static function decimals(string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($c, self::THREE_DECIMAL, true)) {
            return 3;
        }
        return 2;
    }

    /**
     * Multiplier from major units to the gateway's smallest unit for a currency.
     */
    public static function minorUnitFactor(string $currency): int
    {
        return (int) (10 ** self::decimals($currency));
    }

    /** Convert a major-unit amount to the gateway's smallest integer unit. */
    public static function toMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * self::minorUnitFactor($currency));
    }

    /** Convert a smallest-unit amount back to major units. */
    public static function fromMinorUnits($amount, string $currency): float
    {
        return (float) $amount / self::minorUnitFactor($currency);
    }

    /**
     * Format a major-unit amount for display, e.g. "\u{20A6}15,000.00" or "USh 55,000".
     * Uses the intl NumberFormatter when available; otherwise falls back to a
     * symbol map + decimal-aware number_format.
     */
    public static function format(float $amount, string $currency, ?string $locale = null): string
    {
        $currency = strtoupper($currency);
        $locale = $locale ?: 'en_US';

        if (class_exists('NumberFormatter')) {
            $fmt = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $out = $fmt->formatCurrency($amount, $currency);
            if ($out !== false && $out !== null) {
                return $out;
            }
        }

        $decimals = self::decimals($currency);
        $number = number_format($amount, $decimals);
        $symbol = self::SYMBOLS[$currency] ?? '';
        if ($symbol === '') {
            return $currency . ' ' . $number;
        }
        // Symbols stored as PHP escape sequences; decode once.
        $symbol = self::decodeSymbol($symbol);
        return $symbol . $number;
    }

    private static function decodeSymbol(string $symbol): string
    {
        if (str_contains($symbol, '\u{')) {
            return preg_replace_callback('/\\\\u\{([0-9A-Fa-f]+)\}/', function ($m) {
                return mb_convert_encoding(pack('N', hexdec($m[1])), 'UTF-8', 'UTF-32BE');
            }, $symbol);
        }
        return $symbol;
    }
}
