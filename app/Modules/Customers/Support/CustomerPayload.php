<?php

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;

/**
 * Payload aman untuk customer (API / respons). Sengaja tanpa
 * is_active atau kolom internal lain.
 */
class CustomerPayload
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(Customer $customer, bool $withCount = false): array
    {
        $payload = [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];

        if ($withCount) {
            $payload['addresses_count'] = $customer->addresses_count ?? $customer->addresses()->count();
        }

        return $payload;
    }
}
