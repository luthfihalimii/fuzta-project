<?php

namespace App\Modules\Ordering\Data;

use App\Modules\Ordering\Enums\OrderChannel;

readonly class CreateOrderData
{
    /**
     * @param  list<array{product_id: int, variant_id: ?int, modifier_ids: list<int>, quantity: int, note: ?string}>  $items
     */
    public function __construct(
        public int $branchId,
        public OrderChannel $channel,
        public ?string $tableNumber,
        public ?string $customerName,
        public ?string $note,
        public array $items,
        public string $idempotencyKey,
    ) {}
}
