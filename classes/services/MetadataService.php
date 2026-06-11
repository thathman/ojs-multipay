<?php

namespace APP\plugins\paymethod\multipay\classes\services;

use Illuminate\Support\Facades\DB;

class MetadataService
{
    public function createTransaction(array $data): void
    {
        DB::table('multipay_transactions')->insert([
            'context_id' => $data['context_id'],
            'queued_payment_id' => $data['queued_payment_id'],
            'completed_payment_id' => $data['completed_payment_id'] ?? null,
            'gateway' => $data['gateway'],
            'reference' => $data['reference'],
            'provider_tx_id' => $data['provider_tx_id'] ?? null,
            'status' => $data['status'],
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency']),
            'trace_id' => $data['trace_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function upsertTransactionStatus(int $contextId, int $queuedPaymentId, string $gateway, string $reference, string $status, ?string $providerTxId = null): void
    {
        $existing = DB::table('multipay_transactions')
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->first();
        if ($existing) {
            DB::table('multipay_transactions')
                ->where('id', $existing->id)
                ->update([
                    'status' => $status,
                    'provider_tx_id' => $providerTxId ?: $existing->provider_tx_id,
                    'updated_at' => now(),
                ]);
            return;
        }
        $this->createTransaction([
            'context_id' => $contextId,
            'queued_payment_id' => $queuedPaymentId,
            'gateway' => $gateway,
            'reference' => $reference,
            'provider_tx_id' => $providerTxId,
            'status' => $status,
            'amount' => 0,
            'currency' => 'XXX',
        ]);
    }

    public function markCompleted(string $gateway, string $reference, int $completedPaymentId): void
    {
        DB::table('multipay_transactions')
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->update([
                'status' => 'completed',
                'completed_payment_id' => $completedPaymentId,
                'updated_at' => now(),
            ]);
    }

    public function syncTransactionReferenceByQueuedPayment(
        int $contextId,
        int $queuedPaymentId,
        string $gateway,
        string $reference,
        ?string $providerTxId = null,
        ?string $status = null
    ): void {
        $query = DB::table('multipay_transactions')
            ->where('context_id', $contextId)
            ->where('queued_payment_id', $queuedPaymentId)
            ->where('gateway', $gateway);

        $existing = $query->orderByDesc('id')->first();
        if ($existing) {
            DB::table('multipay_transactions')
                ->where('id', $existing->id)
                ->update([
                    'reference' => $reference,
                    'provider_tx_id' => $providerTxId ?: $existing->provider_tx_id,
                    'status' => $status ?: $existing->status,
                    'updated_at' => now(),
                ]);
            return;
        }

        $this->createTransaction([
            'context_id' => $contextId,
            'queued_payment_id' => $queuedPaymentId,
            'gateway' => $gateway,
            'reference' => $reference,
            'provider_tx_id' => $providerTxId,
            'status' => $status ?: 'verified',
            'amount' => 0,
            'currency' => 'XXX',
        ]);
    }

    public function markCompletedByQueuedPayment(
        int $contextId,
        int $queuedPaymentId,
        string $gateway,
        string $reference,
        int $completedPaymentId,
        ?string $providerTxId = null
    ): void {
        DB::table('multipay_transactions')
            ->where('context_id', $contextId)
            ->where('queued_payment_id', $queuedPaymentId)
            ->where('gateway', $gateway)
            ->update([
                'reference' => $reference,
                'provider_tx_id' => $providerTxId,
                'status' => 'completed',
                'completed_payment_id' => $completedPaymentId,
                'updated_at' => now(),
            ]);
    }

    public function findByReference(string $gateway, string $reference): ?object
    {
        $row = DB::table('multipay_transactions')
            ->where('gateway', $gateway)
            ->where('reference', $reference)
            ->first();
        return $row ?: null;
    }

    public function hasCompletedQueuedPayment(int $queuedPaymentId): bool
    {
        return DB::table('multipay_transactions')
            ->where('queued_payment_id', $queuedPaymentId)
            ->where('status', 'completed')
            ->exists();
    }

    public function listTransactions(int $contextId, int $limit = 200): array
    {
        return DB::table('multipay_transactions')
            ->where('context_id', $contextId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}

