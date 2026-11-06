<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateCustomerAddress
{
    public const MAX_ADDRESSES = 10;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data): CustomerAddress {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $count = $locked->addresses()->count();

            if ($count >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages([
                    'addresses' => 'Maksimal 10 alamat tersimpan.',
                ]);
            }

            // Alamat pertama selalu default; default baru menimpa yang lama.
            $isDefault = $count === 0 || (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                // Clear dulu, lalu set: partial unique index
                // customer_addresses_one_default_per_customer tak boleh dilanggar.
                $locked->addresses()->where('is_default', true)->update(['is_default' => false]);
            }

            return $locked->addresses()->create([
                'label' => $data['label'],
                'recipient_name' => $data['recipient_name'],
                'phone' => $data['phone'],
                'address' => $data['address'],
                'area_id' => $data['area_id'],
                'notes' => $data['notes'] ?? null,
                'is_default' => $isDefault,
            ]);
        });
    }
}
