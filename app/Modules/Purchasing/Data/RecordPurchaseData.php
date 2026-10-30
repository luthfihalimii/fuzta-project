<?php

namespace App\Modules\Purchasing\Data;

final class RecordPurchaseData
{
    /**
     * @param  list<array{ingredient_id: int, unit_id: int, quantity: int, unit_price: int}>  $items
     */
    public function __construct(
        public readonly int $branchId,
        public readonly int $supplierId,
        public readonly string $receivedAt, // 'YYYY-MM-DD'
        public readonly ?string $reference,
        public readonly string $idempotencyKey,
        public readonly array $items,
    ) {}
}
