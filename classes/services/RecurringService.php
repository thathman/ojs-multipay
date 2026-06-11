<?php

namespace APP\plugins\paymethod\multipay\classes\services;

use Illuminate\Support\Facades\DB;

class RecurringService
{
    public function listRecurringCandidates(int $contextId, int $limit = 100): array
    {
        return DB::table('multipay_transactions')
            ->where('context_id', $contextId)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }
}

