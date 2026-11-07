<?php

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\CustomerDevice;

class CustomerDevicePayload
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(CustomerDevice $device): array
    {
        return [
            'id' => $device->id,
            'platform' => $device->platform,
            'last_seen_at' => $device->last_seen_at->toIso8601String(),
        ];
    }
}
