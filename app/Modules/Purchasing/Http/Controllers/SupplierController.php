<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/suppliers/Index', [
            'suppliers' => Supplier::query()
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'is_active'])
                ->all(),
        ]);
    }

    public function store(StoreSupplierRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] ??= true;

        $supplier = DB::transaction(function () use ($data, $request, $audit): Supplier {
            $supplier = Supplier::create($data);

            $audit($request->user(), 'supplier.created', $supplier, [], $this->snapshot($supplier));

            return $supplier;
        });

        return back()->with('success', "Supplier {$supplier->name} ditambahkan.");
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        WriteAuditLog $audit,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $supplier, $audit): void {
            $before = $this->snapshot($supplier);

            $supplier->update($request->validated());

            $audit($request->user(), 'supplier.updated', $supplier, $before, $this->snapshot($supplier));
        });

        return back()->with('success', 'Supplier diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'is_active' => $supplier->is_active,
        ];
    }
}
