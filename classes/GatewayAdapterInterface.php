<?php

/**
 * @file plugins/paymethod/multipay/classes/GatewayAdapterInterface.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @interface GatewayAdapterInterface
 *
 * @brief Interface for MultiPay gateway adapters.
 */

namespace APP\plugins\paymethod\multipay\classes;

interface GatewayAdapterInterface
{
    public function getName();
    public function initializePayment(array $params);
    public function verifyTransaction($reference);
    public function validateWebhook(string $payload, array $headers): bool;
    public function normalizeEvent(array $payload);
    public function refund(string $providerTransactionId, float $amount, string $currency): array;
    public function supportsCurrency(string $currency): bool;
    public function supportsRefunds(): bool;

    /** @return string[] ISO-4217 codes this gateway can settle. */
    public function getSupportedCurrencies(): array;
}
