<?php

namespace App\Modules\Ordering\Actions;

use App\Modules\Branches\Models\Branch;
use Illuminate\Support\Facades\DB;

class GenerateOrderNumber
{
    public function __invoke(Branch $branch): string
    {
        $date = now('Asia/Jakarta');

        $row = DB::selectOne(
            <<<'SQL'
                INSERT INTO order_sequences (branch_id, sequence_date, last_number)
                VALUES (?, ?, 1)
                ON CONFLICT (branch_id, sequence_date)
                DO UPDATE SET last_number = order_sequences.last_number + 1
                RETURNING last_number
            SQL,
            [$branch->id, $date->toDateString()],
        );

        return sprintf(
            'PF-%s-%s-%04d',
            $branch->code,
            $date->format('Ymd'),
            (int) $row->last_number,
        );
    }
}
