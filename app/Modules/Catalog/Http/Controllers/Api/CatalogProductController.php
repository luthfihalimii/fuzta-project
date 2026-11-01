<?php

namespace App\Modules\Catalog\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CatalogProductPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogProductController extends Controller
{
    public function index(Request $request, CatalogProductPayload $payload): JsonResponse
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id,is_active,true'],
        ]);

        $branchId = (int) $request->query('branch_id');

        $products = Product::query()
            ->with([
                'category',
                'variants' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                'modifierGroups' => fn ($query) => $query
                    ->orderBy('product_modifier_groups.sort_order'),
                'modifierGroups.modifiers' => fn ($query) => $query
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->where('is_active', true)
            ->whereHas('branches', fn ($query) => $query
                ->where('branches.id', $branchId)
                ->where('product_branch_availability.is_available', true))
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => $payload($product))
            ->values()
            ->all();

        return response()->json(['products' => $products]);
    }
}
