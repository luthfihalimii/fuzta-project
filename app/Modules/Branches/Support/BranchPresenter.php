<?php

namespace App\Modules\Branches\Support;

use App\Modules\Branches\Models\Branch;

class BranchPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'code' => $branch->code,
            'name' => $branch->name,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'cash_float' => $branch->cash_float,
            'is_active' => $branch->is_active,
            'opens_at' => $branch->opens_at,
            'closes_at' => $branch->closes_at,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function options(): array
    {
        return Branch::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ])
            ->values()
            ->all();
    }
}
