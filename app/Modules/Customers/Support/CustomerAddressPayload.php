<?php

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\CustomerAddress;

/**
 * Payload alamat customer (API). Tanpa `customer_id`/timestamp:
 * client cukup memakai `id` plus scope token.
 */
class CustomerAddressPayload
{
    /**
     * @return array{id: int, label: string, recipient_name: string, phone: string, address: string, area_id: string, notes: ?string, is_default: bool}
     */
    public function __invoke(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'address' => $address->address,
            'area_id' => $address->area_id,
            'notes' => $address->notes,
            'is_default' => $address->is_default,
        ];
    }
}
