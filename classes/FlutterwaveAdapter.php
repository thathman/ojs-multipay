<?php

/**
 * @file plugins/paymethod/multipay/classes/FlutterwaveAdapter.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class FlutterwaveAdapter
 *
 * @brief Flutterwave gateway adapter.
 */

namespace APP\plugins\paymethod\multipay\classes;

class FlutterwaveAdapter implements GatewayAdapterInterface
{
    protected string $publicKey;
    protected string $secretKey;
    protected string $webhookSecret;
    protected HttpClient $httpClient;
    protected string $baseUrl = 'https://api.flutterwave.com/v3';
    protected array $supportedCurrencies = ['NGN', 'USD', 'EUR', 'GBP', 'GHS', 'KES', 'ZAR', 'XAF', 'XOF', 'UGX', 'RWF', 'TZS', 'EGP', 'MWK'];

    public function __construct(string $publicKey, string $secretKey, string $webhookSecret, ?HttpClient $httpClient = null)
    {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->webhookSecret = $webhookSecret;
        $this->httpClient = $httpClient ?: new HttpClient();
    }

    public function getName()
    {
        return 'flutterwave';
    }

    public function initializePayment(array $params)
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/payments', [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ], [
            'tx_ref' => $params['reference'],
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'redirect_url' => $params['callbackUrl'],
            'payment_options' => 'card,mobilemoney,ussd',
            'meta' => $params['metadata'],
            'customer' => [
                'email' => $params['email'],
            ],
            'customizations' => [
                'title' => 'Payment',
                'description' => 'Payment for services',
            ],
        ]);
        if (($response['body']['status'] ?? '') !== 'success') {
            throw new \Exception('Flutterwave initialize failed');
        }
        $data = $response['body']['data'] ?? [];
        return [
            'redirectUrl' => $data['link'] ?? '',
            'reference' => $params['reference'],
            'provider_tx_id' => null,
            'raw' => $response['body'],
        ];
    }

    public function verifyTransaction($reference)
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '/transactions/verify_by_reference?tx_ref=' . rawurlencode($reference), [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ]);
        if (($response['body']['status'] ?? '') !== 'success') {
            throw new \Exception('Flutterwave verify failed');
        }
        $data = $response['body']['data'] ?? [];
        $status = strtolower((string) ($data['status'] ?? ''));
        return [
            'status' => $status === 'successful' ? 'success' : 'failed',
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'reference' => (string) ($data['tx_ref'] ?? $reference),
            'provider_tx_id' => (string) ($data['id'] ?? ''),
            'raw' => $response['body'],
        ];
    }

    public function validateWebhook(string $payload, array $headers): bool
    {
        $signature = $headers['verif-hash'] ?? $headers['Verif-Hash'] ?? '';
        if (!$signature || !$this->webhookSecret) {
            return false;
        }
        return hash_equals($this->webhookSecret, $signature);
    }

    public function normalizeEvent(array $payload)
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        return [
            'event' => (string) ($payload['event'] ?? 'unknown'),
            'reference' => (string) ($data['tx_ref'] ?? ''),
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? '')),
            'status' => (string) ($data['status'] ?? ''),
            'provider_tx_id' => isset($data['id']) ? (string) $data['id'] : null,
        ];
    }

    public function refund(string $providerTransactionId, float $amount, string $currency): array
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . '/transactions/' . rawurlencode($providerTransactionId) . '/refund', [
            'Authorization' => 'Bearer ' . $this->secretKey,
        ], [
            'amount' => $amount,
            'currency' => strtoupper($currency),
        ]);
        $ok = ($response['body']['status'] ?? '') === 'success';
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
}
