<?php

namespace App\Console\Commands;

use App\Modules\Shifts\Actions\AutoCloseShift;
use Illuminate\Console\Command;

class AutoCloseStaleShifts extends Command
{
    protected $signature = 'shifts:auto-close';

    protected $description = 'Auto-close cashier shifts past the configured cutoff';

    public function handle(AutoCloseShift $autoCloseShift): int
    {
        $closed = $autoCloseShift();
        $this->info("Auto-closed {$closed} shift(s).");

        return self::SUCCESS;
    }
}
