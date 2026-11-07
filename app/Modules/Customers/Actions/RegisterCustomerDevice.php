<?php

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerDevice;

/**
 * Token FCM unik global: token yang sama dipakai ulang (reinstall atau
 * ganti akun di perangkat yang sama) dipindahkan ke customer aktif,
 * sehingga notifikasi tidak pernah lintas akun.
 */
class RegisterCustomerDevice
{
    public function __invoke(Customer $customer, string $token, string $platform): CustomerDevice
    {
        $now = now();

        CustomerDevice::query()->upsert(
            [[
                'customer_id' => $customer->id,
                'token' => $token,
                'platform' => $platform,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['token'],
            ['customer_id', 'platform', 'last_seen_at', 'updated_at'],
        );

        return CustomerDevice::query()->where('token', $token)->firstOrFail();
    }
}
