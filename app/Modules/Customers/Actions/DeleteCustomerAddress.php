<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

class DeleteCustomerAddress
{
    public function __invoke(Customer $customer, CustomerAddress $address): void
    {
        DB::transaction(function () use ($customer, $address): void {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            // Ambil ulang scoped ke customer: ownership sudah dijaga controller.
            $address = $customer->addresses()->whereKey($address->id)->lockForUpdate()->firstOrFail();
            $wasDefault = $address->is_default;

            $address->delete();

            // Default terhapus: terlama di antara sisa jadi default (bila masih ada).
            if ($wasDefault) {
                $customer->addresses()
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->first()?->update(['is_default' => true]);
            }
        });
    }
}
