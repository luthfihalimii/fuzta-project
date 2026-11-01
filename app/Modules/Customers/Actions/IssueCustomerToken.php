<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class IssueCustomerToken
{
    /**
     * Token mentah hanya dikembalikan sekali (di respons); DB menyimpan
     * hash SHA-256-nya saja.
     *
     * @return array{token: string, expires_at: CarbonImmutable}
     */
    public function __invoke(Customer $customer): array
    {
        $expiresAt = now()->addDays(30);
        $raw = Str::random(40);

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $raw,
            'expires_at' => $expiresAt,
        ];
    }
}
