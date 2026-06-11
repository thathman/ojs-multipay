<?php

/**
 * @file plugins/paymethod/multipay/classes/PaystackAdapter.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaystackAdapter
 *
 * @brief Paystack gateway adapter.
 */

namespace APP\plugins\paymethod\multipay\classes;

class PaystackAdapter implements GatewayAdapterInterface
{
    /** ISO-4217 currencies with no minor unit (exponent 0). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    /** ISO-4217 currencies with three minor digits (exponent 3). */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    protected string $publicKey;
    protected string $secretKey;
    protected HttpClient $httpClient;
    protected string $baseUrl = 'https://api.paystack.co';
    protected array $supportedCurrencies = ['NGN', 'USD', 'GHS', 'ZAR', 'KES'];

    public function __construct(string $publicKey, string $secretKey, ?HttpClient $httpClient = null)
    {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->httpClient = $httpClient ?: new HttpClient();
    }

    public function getName()
    {
        return 'paystack';
    }

    public function initializePayment(array $params)
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/transaction/initialize', [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ], [
            'email' => $params['email'],
            'amount' => $this->toMinorUnits((float) $params['amount'], (string) $params['currency']),
            'currency' => $params['currency'],
            'reference' => $params['reference'],
            'callback_url' => $params['callbackUrl'],
            'metadata' => $params['metadata'],
        ]);
        if (($response['body']['status'] ?? false) !== true) {
            throw new \Exception('Paystack initialize failed');
        }
        $data = $response['body']['data'] ?? [];
        return [
            'redirectUrl' => $data['authorization_url'] ?? '',
            'reference' => $data['reference'] ?? $params['reference'],
            'provider_tx_id' => $data['access_code'] ?? null,
            'raw' => $response['body'],
        ];
    }

    public function verifyTransaction($reference)
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/transaction/verify/' . rawurlencode($reference), [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ]);
        if (($response['body']['status'] ?? false) !== true) {
            throw new \Exception('Paystack verify failed');
        }
        $data = $response['body']['data'] ?? [];
        $vCurrency = strtoupper((string) ($data['currency'] ?? ''));
        return [
            'status' => ($data['status'] ?? '') === 'success' ? 'success' : 'failed',
            'amount' => isset($data['amount']) ? $this->fromMinorUnits($data['amount'], $vCurrency) : 0.0,
            'currency' => $vCurrency,
            'reference' => (string) ($data['reference'] ?? $reference),
            'provider_tx_id' => (string) ($data['id'] ?? ''),
            'raw' => $response['body'],
        ];
    }

    public function validateWebhook(string $payload, array $headers): bool
    {
        $signature = $headers['x-paystack-signature'] ?? $headers['X-Paystack-Signature'] ?? '';
        if (!$signature) {
            return false;
        }
        return $signature === hash_hmac('sha512', $payload, $this->secretKey);
    }

    public function normalizeEvent(array $payload)
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $nCurrency = strtoupper((string) ($data['currency'] ?? ''));
        return [
            'event' => (string) ($payload['event'] ?? 'unknown'),
            'reference' => (string) ($data['reference'] ?? ''),
            'amount' => isset($data['amount']) ? $this->fromMinorUnits($data['amount'], $nCurrency) : 0.0,
            'currency' => $nCurrency,
            'status' => (string) ($data['status'] ?? ''),
            'provider_tx_id' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    public function refund(string $providerTransactionId, float $amount, string $currency): array
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/refund', [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ], [
            'transaction' => $providerTransactionId,
            'amount' => $this->toMinorUnits($amount, $currency),
            'currency' => strtoupper($currency),
        ]);
        $ok = ($response['body']['status'] ?? false) === true;
        return [
            'status' => $ok ? 'success' : 'failed',
            'raw' => $response['body'],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies, true);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Multiplier from major units to the gateway's smallest unit for a currency.
     * 1 for zero-decimal (JPY, KRW…), 1000 for three-decimal (KWD, BHD…),
     * 100 otherwise. All of Paystack's currently supported currencies are
     * two-decimal, so this returns 100 for them; the table keeps the adapter
     * correct if the supported set ever widens.
     */
    private function minorUnitFactor(string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, self::ZERO_DECIMAL, true)) {
            return 1;
        }
        if (in_array($c, self::THREE_DECIMAL, true)) {
            return 1000;
        }
        return 100;
    }

    /** Convert a major-unit amount to the gateway's smallest integer unit. */
    private function toMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * $this->minorUnitFactor($currency));
    }

    /** Convert a smallest-unit amount back to major units. */
    private function fromMinorUnits($amount, string $currency): float
    {
        return (float) $amount / $this->minorUnitFactor($currency);
    }
}
