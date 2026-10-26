<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Actions\WriteAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/catalog/Products', [
            'products' => Product::query()
                ->with(['category', 'branches'])
                ->withCount('variants')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category_name' => $product->category?->name,
                    'base_price' => $product->base_price,
                    'is_active' => $product->is_active,
                    'variants_count' => $product->variants_count,
                    'branch_availability' => $product->branches
                        ->map(fn (Branch $branch) => [
                            'branch_id' => $branch->id,
                            'is_available' => (bool) $branch->pivot->is_available,
                        ])
                        ->values()
                        ->all(),
                ]),
            'branches' => $this->branchOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/catalog/ProductEdit', [
            'product' => null,
            ...$this->options(),
        ]);
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('admin/catalog/ProductEdit', [
            'product' => $this->productData($product),
            ...$this->options(),
        ]);
    }

    public function store(StoreProductRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $audit): void {
            $product = Product::create([
                'category_id' => $data['category_id'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'base_price' => $data['base_price'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncVariants($product, $data['variants'] ?? []);

            if (array_key_exists('modifier_group_ids', $data)) {
                $this->syncModifierGroups($product, $data['modifier_group_ids'] ?? []);
            }

            if (array_key_exists('branch_availability', $data)) {
                $this->syncBranchAvailability($product, $data['branch_availability'] ?? []);
            }

            $product->load(['variants', 'modifierGroups', 'branches']);

            $audit(
                $request->user(),
                'catalog.product.created',
                $product,
                [],
                $this->snapshot($product),
            );
        });

        return redirect()
            ->route('admin.catalog.products.index')
            ->with('success', 'Produk berhasil ditambahkan.');
    }

    public function update(
        UpdateProductRequest $request,
        Product $product,
        WriteAuditLog $audit,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($product, $data, $request, $audit): void {
            $before = $this->snapshot($product);

            $product->fill(Arr::only($data, [
                'category_id',
                'name',
                'description',
                'base_price',
                'is_active',
            ]));
            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->syncVariants($product, $data['variants'] ?? []);
            }

            if (array_key_exists('modifier_group_ids', $data)) {
                $this->syncModifierGroups($product, $data['modifier_group_ids'] ?? []);
            }

            if (array_key_exists('branch_availability', $data)) {
                $this->syncBranchAvailability($product, $data['branch_availability'] ?? []);
            }

            $product->refresh()->load(['variants', 'modifierGroups', 'branches']);

            $audit(
                $request->user(),
                'catalog.product.updated',
                $product,
                $before,
                $this->snapshot($product),
            );
        });

        return redirect()
            ->route('admin.catalog.products.index')
            ->with('success', 'Produk diperbarui.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     */
    private function syncVariants(Product $product, array $variants): void
    {
        $keep = [];

        foreach ($variants as $index => $row) {
            $id = $row['id'] ?? null;

            unset($row['id']);

            $row['sort_order'] ??= $index;
            $row['is_active'] ??= true;

            if ($id) {
                $variant = $product->variants()->whereKey($id)->first();

                if ($variant === null) {
                    throw ValidationException::withMessages([
                        "variants.{$index}.id" => 'Varian tidak ditemukan untuk produk ini.',
                    ]);
                }

                $variant->update($row);
                $keep[] = $variant->id;

                continue;
            }

            $variant = $product->variants()->create($row);
            $keep[] = $variant->id;
        }

        if ($keep === []) {
            $product->variants()->delete();

            return;
        }

        $product->variants()->whereKeyNot($keep)->delete();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function syncModifierGroups(Product $product, array $ids): void
    {
        $sync = [];

        foreach ($ids as $index => $id) {
            $sync[$id] = ['sort_order' => $index];
        }

        $product->modifierGroups()->sync($sync);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function syncBranchAvailability(Product $product, array $rows): void
    {
        $sync = [];

        foreach ($rows as $row) {
            $sync[$row['branch_id']] = [
                'is_available' => (bool) ($row['is_available'] ?? false),
            ];
        }

        $product->branches()->sync($sync);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return [
            'categories' => Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ]),
            'modifierGroups' => ModifierGroup::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'min_select', 'max_select', 'is_required'])
                ->map(fn (ModifierGroup $group) => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'min_select' => $group->min_select,
                    'max_select' => $group->max_select,
                    'is_required' => $group->is_required,
                ]),
            'branches' => $this->branchOptions(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function branchOptions(): Collection
    {
        return Branch::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productData(Product $product): array
    {
        $product->loadMissing(['variants', 'modifierGroups', 'branches']);

        return [
            'id' => $product->id,
            'category_id' => $product->category_id,
            'name' => $product->name,
            'description' => $product->description,
            'base_price' => $product->base_price,
            'is_active' => $product->is_active,
            'variants' => $product->variants
                ->sortBy('sort_order')
                ->values()
                ->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'price' => $variant->price,
                    'sort_order' => $variant->sort_order,
                    'is_active' => $variant->is_active,
                ])
                ->all(),
            'modifier_group_ids' => $product->modifierGroups->pluck('id')->values()->all(),
            'branch_availability' => $product->branches
                ->map(fn (Branch $branch) => [
                    'branch_id' => $branch->id,
                    'is_available' => (bool) $branch->pivot->is_available,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $data = $this->productData($product);

        unset($data['id']);

        return $data;
    }
}
