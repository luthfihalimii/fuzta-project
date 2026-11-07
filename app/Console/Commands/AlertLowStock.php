<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Actions\SendOperationalAlert;
use App\Modules\Reporting\Queries\StockReportQuery;
use Illuminate\Console\Command;

class AlertLowStock extends Command
{
    protected $signature = 'operations:alert-low-stock';

    protected $description = 'Email harian bahan yang stoknya di bawah ambang';

    public function handle(StockReportQuery $stockReport, SendOperationalAlert $send): int
    {
        // Ambang 0 berarti alert dimatikan: tanpa filter ini setiap bahan
        // yang belum pernah punya saldo di cabang mana pun ikut dianggap rendah.
        $rows = array_values(array_filter(
            ($stockReport)()['low_stock'],
            static fn (array $row): bool => $row['low_stock_threshold'] > 0,
        ));

        if ($rows === []) {
            $this->info('Tidak ada stok rendah.');

            return self::SUCCESS;
        }

        $lines = array_map(
            static fn (array $row): string => sprintf(
                '%s — %s: sisa %d %s (ambang %d)',
                $row['branch_name'],
                $row['ingredient_name'],
                $row['quantity'],
                $row['unit'],
                $row['low_stock_threshold'],
            ),
            $rows,
        );

        $sent = $send('Peringatan stok rendah', 'Stok bahan di bawah ambang', $lines);

        $this->info("{$sent} email alert stok terkirim.");

        return self::SUCCESS;
    }
}
