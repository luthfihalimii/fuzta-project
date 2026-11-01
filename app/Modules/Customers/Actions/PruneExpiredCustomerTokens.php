<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\CustomerToken;

class PruneExpiredCustomerTokens
{
    /**
     * @return int jumlah baris token terhapus
     */
    public function __invoke(): int
    {
        // `<=` agar selaras middleware: token usable selama expires_at > now().
        return CustomerToken::query()
            ->where('expires_at', '<=', now())
            ->delete();
    }
}
