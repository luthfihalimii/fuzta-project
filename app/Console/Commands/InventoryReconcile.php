<?php

namespace App\Console\Commands;

use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InventoryReconcile extends Command
{
    protected $signature = 'inventory:reconcile {--fix : Tulis ulang saldo sesuai jumlah ledger}';

    protected $description = 'Bandingkan stock_balances dengan jumlah stock_movements per cabang dan bahan';

    public function handle(WriteAuditLog $writeAuditLog): int
    {
        $ledger = DB::table('stock_movements')
            ->selectRaw('branch_id, ingredient_id, SUM(quantity) AS ledger')
            ->groupBy('branch_id', 'ingredient_id');

        // Full outer join: balance without movements → ledger 0; movement group without balance → saldo 0.
        $rows = DB::table('stock_balances as b')
            ->leftJoinSub($ledger, 'l', function ($join): void {
                $join->on('l.branch_id', '=', 'b.branch_id')
                    ->on('l.ingredient_id', '=', 'b.ingredient_id');
            })
            ->join('branches', 'branches.id', '=', 'b.branch_id')
            ->join('ingredients', 'ingredients.id', '=', 'b.ingredient_id')
            ->selectRaw('b.id, b.branch_id, b.ingredient_id, b.quantity AS saldo, COALESCE(l.ledger, 0) AS ledger, branches.name AS branch_name, ingredients.name AS ingredient_name')
            ->unionAll(
                DB::table('stock_movements as m')
                    ->leftJoin('stock_balances as b', function ($join): void {
                        $join->on('b.branch_id', '=', 'm.branch_id')
                            ->on('b.ingredient_id', '=', 'm.ingredient_id');
                    })
                    ->join('branches', 'branches.id', '=', 'm.branch_id')
                    ->join('ingredients', 'ingredients.id', '=', 'm.ingredient_id')
                    ->whereNull('b.id')
                    ->groupBy('m.branch_id', 'm.ingredient_id', 'branches.name', 'ingredients.name')
                    ->selectRaw('NULL AS id, m.branch_id, m.ingredient_id, 0 AS saldo, SUM(m.quantity) AS ledger, branches.name AS branch_name, ingredients.name AS ingredient_name'),
            )
            ->get()
            ->map(fn ($row): object => (object) [
                'id' => $row->id,
                'branch_id' => $row->branch_id,
                'ingredient_id' => $row->ingredient_id,
                'saldo' => (int) $row->saldo,
                'ledger' => (int) $row->ledger,
                'delta' => (int) $row->ledger - (int) $row->saldo,
                'branch_name' => $row->branch_name,
                'ingredient_name' => $row->ingredient_name,
            ])
            ->filter(fn (object $row): bool => $row->delta !== 0)
            ->sortBy([['branch_name', 'asc'], ['ingredient_name', 'asc']])
            ->values();

        if ($rows->isEmpty()) {
            $this->info('Saldo stok sesuai ledger: tidak ada selisih.');

            return self::SUCCESS;
        }

        $this->table(
            ['Cabang', 'Bahan', 'Saldo', 'Ledger', 'Delta'],
            $rows->map(fn ($row): array => [$row->branch_name, $row->ingredient_name, (int) $row->saldo, (int) $row->ledger, (int) $row->delta])->all(),
        );

        foreach ($rows as $row) {
            $this->line("Selisih: {$row->branch_name} | {$row->ingredient_name} | saldo {$row->saldo} | ledger {$row->ledger} | delta ".(int) $row->delta);
        }

        if (! $this->option('fix')) {
            $this->error("{$rows->count()} selisih ditemukan. Jalankan ulang dengan --fix untuk memperbaiki.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows, $writeAuditLog): void {
            foreach ($rows as $row) {
                $balance = $row->id === null
                    ? StockBalance::query()->create([
                        'branch_id' => $row->branch_id,
                        'ingredient_id' => $row->ingredient_id,
                        'quantity' => 0,
                    ])
                    : StockBalance::query()->lockForUpdate()->findOrFail($row->id);

                $before = (int) $balance->quantity;

                $balance->update(['quantity' => $row->ledger]);

                ($writeAuditLog)(null, 'stock.reconciled', $balance, ['quantity' => $before], ['quantity' => $row->ledger], 'inventory:reconcile --fix');
            }
        });

        $this->info("{$rows->count()} saldo diperbaiki sesuai ledger.");

        return self::SUCCESS;
    }
}
