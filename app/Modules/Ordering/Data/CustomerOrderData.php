<?php

namespace App\Modules\Ordering\Data;

readonly class CustomerOrderData
{
    /**
     * @param  list<array{product_id: int, variant_id: ?int, modifier_ids: list<int>, quantity: int, note: ?string}>  $items
     */
    public function __construct(
        public int $branchId,
        public string $fulfillment,
        public string $customerName,
        public string $customerPhone,
        public ?string $note,
        public ?string $deliveryAddress,
        public ?string $deliveryAreaId,
        public int $deliveryFee,
        public ?string $courierName,
        public ?string $courierService,
        public array $items,
        public string $idempotencyKey,
        public ?int $customerId = null,
    ) {}
}
