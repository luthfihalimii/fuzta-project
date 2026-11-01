<?php

namespace App\Console\Commands;

use App\Modules\Customers\Actions\PruneExpiredCustomerTokens;
use Illuminate\Console\Command;

class PruneCustomerTokens extends Command
{
    protected $signature = 'customers:prune-tokens';

    protected $description = 'Hapus token customer yang sudah kedaluwarsa';

    public function handle(PruneExpiredCustomerTokens $pruneExpiredCustomerTokens): int
    {
        $deleted = $pruneExpiredCustomerTokens();

        $this->info("Terhapus {$deleted} token kedaluwarsa.");

        return self::SUCCESS;
    }
}
