<?php

/**
 * @file plugins/paymethod/multipay/classes/PaypalAdapter.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PaypalAdapter
 *
 * @brief PayPal gateway adapter (experimental).
 *
 * Uses the Omnipay PayPal_Rest driver (PayPal's v1 /payments API). Asynchronous
 * webhooks are intentionally rejected (validateWebhook() returns false), so this
 * adapter relies on the synchronous return/verify flow only; refunds are not
 * supported. Treat as experimental until migrated to PayPal Orders v2.
 */

namespace APP\plugins\paymethod\multipay\classes;

use Omnipay\Omnipay;

class PaypalAdapter implements GatewayAdapterInterface
{
    /** Currencies accepted by PayPal for payments. */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
        'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB',
        'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];
    /** PayPal currencies that must be sent with no decimal places. */
    private const ZERO_DECIMAL = ['HUF', 'JPY', 'TWD'];

    protected string $clientId;
    protected string $secret;
    protected bool $testMode;

    public function __construct(string $clientId, string $secret, bool $testMode)
    {
        $this->clientId = $clientId;
        $this->secret = $secret;
        $this->testMode = $testMode;
    }

    public function getName()
    {
        return 'paypalpayment';
    }

    public function initializePayment(array $params)
    {
        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->initialize([
            'clientId' => $this->clientId,
            'secret' => $this->secret,
            'testMode' => $this->testMode,
        ]);
        $currency = (string) ($params['currency'] ?? 'USD');
        $transaction = $gateway->purchase([
            'amount' => $this->formatAmount((float) ($params['amount'] ?? 0), $currency),
            'currency' => $currency,
            'description' => (string) ($params['description'] ?? 'OJS Payment'),
            'returnUrl' => (string) ($params['callbackUrl'] ?? ''),
            'cancelUrl' => (string) ($params['cancelUrl'] ?? $params['callbackUrl'] ?? ''),
        ]);
        $response = $transaction->send();
        if (!$response->isRedirect()) {
            throw new \Exception((string) $response->getMessage());
        }
        return [
            'redirectUrl' => $response->getRedirectUrl(),
            'provider_tx_id' => null,
            'raw' => method_exists($response, 'getData') ? $response->getData() : [],
        ];
    }

    public function verifyTransaction($reference)
    {
        $parts = explode('|', (string) $reference, 2);
        if (count($parts) !== 2) {
            throw new \Exception('Missing PayPal payer reference.');
        }
        $paymentId = $parts[0];
        $payerId = $parts[1];

        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->initialize([
            'clientId' => $this->clientId,
            'secret' => $this->secret,
            'testMode' => $this->testMode,
        ]);
        $transaction = $gateway->completePurchase([
            'payer_id' => $payerId,
            'transactionReference' => $paymentId,
        ]);
        $response = $transaction->send();
        if (!$response->isSuccessful()) {
            return [
                'status' => 'failed',
                'amount' => 0,
                'currency' => '',
                'provider_tx_id' => $paymentId,
                'raw' => method_exists($response, 'getData') ? $response->getData() : [],
            ];
        }
        $data = $response->getData();
        $tx = $data['transactions'][0] ?? [];
        return [
            'status' => ($data['state'] ?? '') === 'approved' ? 'success' : 'failed',
            'amount' => (float) ($tx['amount']['total'] ?? 0),
            'currency' => strtoupper((string) ($tx['amount']['currency'] ?? '')),
            'provider_tx_id' => $paymentId,
            'raw' => $data,
        ];
    }

    public function validateWebhook($payload, $headers)
    {
        return false;
    }

    public function normalizeEvent(array $payload)
    {
        return [
            'event' => (string) ($payload['event_type'] ?? 'unknown'),
            'reference' => (string) ($payload['resource']['id'] ?? ''),
            'amount' => (float) ($payload['resource']['amount']['total'] ?? 0),
            'currency' => strtoupper((string) ($payload['resource']['amount']['currency'] ?? '')),
            'status' => (string) ($payload['resource']['state'] ?? ''),
        ];
    }

    public function refund(string $providerTransactionId, float $amount, string $currency): array
    {
        return [
            'status' => 'unsupported',
            'provider_refund_id' => null,
            'raw' => ['message' => 'Refunds are not supported by this adapter.'],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    /** Format an amount for PayPal: no decimals for zero-decimal currencies. */
    private function formatAmount(float $amount, string $currency): string
    {
        $decimals = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
        return number_format($amount, $decimals, '.', '');
    }
}
