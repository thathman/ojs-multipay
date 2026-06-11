<?php

namespace APP\plugins\paymethod\multipay\classes\services;

use Illuminate\Support\Facades\DB;

class ReconciliationService
{
    public function snapshot(int $contextId): array
    {
        $rows = DB::table('multipay_transactions')
            ->selectRaw('gateway, currency, status, count(*) as total_count, sum(amount) as total_amount')
            ->where('context_id', $contextId)
            ->groupBy('gateway', 'currency', 'status')
            ->orderBy('gateway')
            ->orderBy('currency')
            ->get()
            ->all();
        return $rows;
    }
}

