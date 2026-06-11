<?php

namespace APP\plugins\paymethod\multipay\classes\services;

class ReferenceService
{
    public function generateReference(int $queuedPaymentId): string
    {
        return 'OJSMP_' . $queuedPaymentId . '_' . time() . '_' . bin2hex(random_bytes(4));
    }

    public function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function callbackDedupeKey(string $gateway, string $reference): string
    {
        return 'return:' . strtolower($gateway) . ':' . $reference;
    }

    public function webhookDedupeKey(string $gateway, string $eventType, string $reference): string
    {
        return 'webhook:' . strtolower($gateway) . ':' . strtolower($eventType) . ':' . $reference;
    }
}

