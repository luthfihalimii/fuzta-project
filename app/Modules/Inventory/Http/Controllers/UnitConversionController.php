<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnitConversionRequest;
use App\Http\Requests\StoreUnitRequest;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\UnitConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnitConversionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stock/Units', [
            'units' => Unit::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'conversions' => UnitConversion::query()
                ->with(['fromUnit:id,code,name', 'toUnit:id,code,name'])
                ->latest()
                ->get()
                ->map(fn (UnitConversion $conversion) => [
                    'id' => $conversion->id,
                    'from_unit_id' => $conversion->from_unit_id,
                    'to_unit_id' => $conversion->to_unit_id,
                    'from_code' => $conversion->fromUnit?->code,
                    'to_code' => $conversion->toUnit?->code,
                    'from_quantity' => $conversion->from_quantity,
                    'to_quantity' => $conversion->to_quantity,
                ]),
        ]);
    }

    public function storeUnit(StoreUnitRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $audit): void {
            $unit = Unit::create($data);

            $audit(
                $request->user(),
                'unit.created',
                $unit,
                [],
                ['name' => $unit->name, 'code' => $unit->code],
            );
        });

        return back()->with('success', 'Satuan berhasil ditambahkan.');
    }

    public function updateUnit(Request $request, Unit $unit, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($unit, $data, $request, $audit): void {
            $before = ['name' => $unit->name, 'code' => $unit->code];

            $unit->update(['name' => $data['name']]);

            $audit(
                $request->user(),
                'unit.updated',
                $unit,
                $before,
                ['name' => $unit->name, 'code' => $unit->code],
            );
        });

        return back()->with('success', 'Satuan diperbarui.');
    }

    public function destroyUnit(Request $request, Unit $unit, WriteAuditLog $audit): RedirectResponse
    {
        $used = Ingredient::query()->where('base_unit_id', $unit->id)->exists()
            || StockMovement::query()->where('unit_id', $unit->id)->exists()
            || UnitConversion::query()
                ->where('from_unit_id', $unit->id)
                ->orWhere('to_unit_id', $unit->id)
                ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'unit' => 'Satuan sudah terpakai.',
            ]);
        }

        DB::transaction(function () use ($request, $unit, $audit): void {
            $audit(
                $request->user(),
                'unit.deleted',
                $unit,
                ['name' => $unit->name, 'code' => $unit->code],
                [],
            );

            $unit->delete();
        });

        return back()->with('success', 'Satuan dihapus.');
    }

    public function storeConversion(StoreUnitConversionRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        $duplicate = UnitConversion::query()
            ->where(fn ($query) => $query
                ->where('from_unit_id', $data['from_unit_id'])
                ->where('to_unit_id', $data['to_unit_id']))
            ->orWhere(fn ($query) => $query
                ->where('from_unit_id', $data['to_unit_id'])
                ->where('to_unit_id', $data['from_unit_id']))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'to_unit_id' => 'Konversi arah ini sudah ada.',
            ]);
        }

        DB::transaction(function () use ($data, $request, $audit): void {
            $conversion = UnitConversion::create($data);

            $audit(
                $request->user(),
                'unit_conversion.created',
                $conversion,
                [],
                $this->conversionSnapshot($conversion),
            );
        });

        return back()->with('success', 'Konversi berhasil ditambahkan.');
    }

    public function destroyConversion(Request $request, UnitConversion $unitConversion, WriteAuditLog $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $unitConversion, $audit): void {
            $audit(
                $request->user(),
                'unit_conversion.deleted',
                $unitConversion,
                $this->conversionSnapshot($unitConversion),
                [],
            );

            $unitConversion->delete();
        });

        return back()->with('success', 'Konversi dihapus.');
    }

    /**
     * @return array{from: string|null, to: string|null, from_quantity: int, to_quantity: int}
     */
    private function conversionSnapshot(UnitConversion $conversion): array
    {
        $conversion->loadMissing('fromUnit', 'toUnit');

        return [
            'from' => $conversion->fromUnit?->code,
            'to' => $conversion->toUnit?->code,
            'from_quantity' => $conversion->from_quantity,
            'to_quantity' => $conversion->to_quantity,
        ];
    }
}
