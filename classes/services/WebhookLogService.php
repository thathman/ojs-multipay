<?php

namespace APP\plugins\paymethod\multipay\classes\services;

use Illuminate\Support\Facades\DB;

class WebhookLogService
{
    public function log(int $contextId, string $gateway, string $eventType, ?string $reference, bool $verified, string $payload): void
    {
        DB::table('multipay_webhook_logs')->insert([
            'context_id' => $contextId,
            'gateway' => $gateway,
            'event_type' => $eventType,
            'reference' => $reference,
            'verified' => $verified ? 1 : 0,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    public function listWebhooks(int $contextId, int $limit = 200): array
    {
        return DB::table('multipay_webhook_logs')
            ->where('context_id', $contextId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}

