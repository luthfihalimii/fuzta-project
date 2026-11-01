<?php

namespace App\Modules\Branches\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Branches\Support\OpeningHours;
use Illuminate\Http\JsonResponse;

class BranchApiController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (Branch $branch): array => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
                'address' => $branch->address,
                'phone' => $branch->phone,
                'opens_at' => $branch->opens_at,
                'closes_at' => $branch->closes_at,
                'is_open' => OpeningHours::isOpen((string) $branch->opens_at, (string) $branch->closes_at),
                'supports_delivery' => $branch->delivery_area_id !== null,
            ])
            ->values()
            ->all();

        return response()->json(['branches' => $branches]);
    }
}
