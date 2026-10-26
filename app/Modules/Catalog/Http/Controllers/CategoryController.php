<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Models\Category;
use App\Modules\Identity\Actions\WriteAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/catalog/Categories', [
            'categories' => Category::query()
                ->withCount('products')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'sort_order' => $category->sort_order,
                    'is_active' => $category->is_active,
                    'products_count' => $category->products_count,
                ]),
        ]);
    }

    public function store(StoreCategoryRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $audit): void {
            $category = Category::create($data);

            $audit(
                $request->user(),
                'catalog.category.created',
                $category,
                [],
                $this->snapshot($category),
            );
        });

        return back()->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function update(
        UpdateCategoryRequest $request,
        Category $category,
        WriteAuditLog $audit,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($category, $data, $request, $audit): void {
            $before = $this->snapshot($category);

            $category->fill($data);
            $category->save();
            $category->refresh();

            $audit(
                $request->user(),
                'catalog.category.updated',
                $category,
                $before,
                $this->snapshot($category),
            );
        });

        return back()->with('success', 'Kategori diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Category $category): array
    {
        return [
            'name' => $category->name,
            'sort_order' => $category->sort_order,
            'is_active' => $category->is_active,
        ];
    }
}
