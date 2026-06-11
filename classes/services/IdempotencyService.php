<?php

/**
 * @file plugins/paymethod/multipay/classes/services/IdempotencyService.php
 *
 * Copyright (c) 2026 Hendrix Nwaokolo, Airix Media
 * Website: https://ojs.airixmedia.com
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class IdempotencyService
 *
 * @brief Single-claim guard for gateway callbacks/webhooks. A successful insert
 *        means "this caller owns the event"; a duplicate-key collision means
 *        "already claimed by an earlier delivery".
 */

namespace APP\plugins\paymethod\multipay\classes\services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    /**
     * Attempt to claim a (context, gateway, dedupeKey) tuple.
     *
     * @return bool true  => claimed now; caller should process the event.
     *              false => already claimed (duplicate delivery); caller skips.
     *
     * @throws QueryException for any database error OTHER than a unique-key
     *         collision. This is deliberate: a transient DB failure must NOT be
     *         silently reported as "already processed", because callers treat
     *         false as "skip fulfilment" (and ack the webhook with HTTP 200).
     *         Letting the error propagate makes the webhook return non-2xx so
     *         the gateway retries, instead of dropping a real payment.
     */
    public function claim(int $contextId, string $gateway, string $dedupeKey): bool
    {
        try {
            DB::table('multipay_idempotency')->insert([
                'context_id' => $contextId,
                'gateway' => $gateway,
                'dedupe_key' => $dedupeKey,
                'created_at' => now(),
            ]);
            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // Genuine duplicate: an earlier delivery already claimed it.
                return false;
            }
            error_log('[multipay] idempotency claim failed (rethrown): ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Detect a unique/primary-key constraint violation across drivers.
     * SQLSTATE 23000 (MySQL integrity constraint) / 23505 (PostgreSQL unique).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }
        // Fallback: MySQL driver error 1062, SQLite "UNIQUE constraint failed".
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 1062 || $driverCode === 19) {
            return true;
        }
        return stripos($e->getMessage(), 'unique') !== false
            || stripos($e->getMessage(), 'duplicate') !== false;
    }
}
