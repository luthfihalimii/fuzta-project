<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomerAddress
{
    public function __invoke(Customer $customer, CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $address, $data): CustomerAddress {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            // Ambil ulang scoped ke customer: ownership sudah dijaga controller.
            $address = $customer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            $hasDefault = array_key_exists('is_default', $data);
            $isDefault = $hasDefault ? (bool) $data['is_default'] : $address->is_default;

            if ($hasDefault && $isDefault === false && $address->is_default) {
                throw ValidationException::withMessages([
                    'is_default' => 'Pilih alamat default lain sebelum menonaktifkan alamat ini.',
                ]);
            }

            if ($isDefault) {
                $customer->addresses()
                    ->where('is_default', true)
                    ->whereKeyNot($address->id)
                    ->update(['is_default' => false]);
            }

            // Hanya kunci yang ada di $data yang ditulis (semantik PATCH).
            if ($hasDefault) {
                $data['is_default'] = $isDefault;
            } else {
                unset($data['is_default']);
            }

            $address->fill($data)->save();

            return $address;
        });
    }
}
