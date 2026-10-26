<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreModifierGroupRequest;
use App\Http\Requests\UpdateModifierGroupRequest;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Identity\Actions\WriteAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ModifierGroupController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/catalog/ModifierGroups', [
            'groups' => ModifierGroup::query()
                ->with('modifiers')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ModifierGroup $group) => $this->groupData($group)),
        ]);
    }

    public function store(StoreModifierGroupRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $audit): void {
            $group = ModifierGroup::create(Arr::except($data, ['modifiers']));

            $this->syncModifiers($group, $data['modifiers'] ?? null);

            $group->load('modifiers');

            $audit(
                $request->user(),
                'catalog.modifier_group.created',
                $group,
                [],
                $this->snapshot($group),
            );
        });

        return back()->with('success', 'Grup modifier berhasil ditambahkan.');
    }

    public function update(
        UpdateModifierGroupRequest $request,
        ModifierGroup $modifierGroup,
        WriteAuditLog $audit,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($modifierGroup, $data, $request, $audit): void {
            $before = $this->snapshot($modifierGroup);

            $modifierGroup->fill(Arr::except($data, ['modifiers']));
            $modifierGroup->save();

            if (array_key_exists('modifiers', $data)) {
                $this->syncModifiers($modifierGroup, $data['modifiers'] ?? []);
            }

            $modifierGroup->refresh()->load('modifiers');

            $audit(
                $request->user(),
                'catalog.modifier_group.updated',
                $modifierGroup,
                $before,
                $this->snapshot($modifierGroup),
            );
        });

        return back()->with('success', 'Grup modifier diperbarui.');
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $modifiers
     */
    private function syncModifiers(ModifierGroup $group, ?array $modifiers): void
    {
        if ($modifiers === null) {
            return;
        }

        $keep = [];

        foreach ($modifiers as $index => $row) {
            $id = $row['id'] ?? null;

            unset($row['id']);

            $row['sort_order'] ??= $index;
            $row['is_active'] ??= true;

            if ($id) {
                $modifier = $group->modifiers()->whereKey($id)->first();

                if ($modifier === null) {
                    continue;
                }

                $modifier->update($row);
                $keep[] = $modifier->id;

                continue;
            }

            $modifier = $group->modifiers()->create($row);
            $keep[] = $modifier->id;
        }

        if ($keep === []) {
            $group->modifiers()->delete();

            return;
        }

        $group->modifiers()->whereKeyNot($keep)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function groupData(ModifierGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'min_select' => $group->min_select,
            'max_select' => $group->max_select,
            'is_required' => $group->is_required,
            'sort_order' => $group->sort_order,
            'modifiers' => $group->modifiers
                ->sortBy('sort_order')
                ->values()
                ->map(fn (Modifier $modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'price_delta' => $modifier->price_delta,
                    'sort_order' => $modifier->sort_order,
                    'is_active' => $modifier->is_active,
                ])
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ModifierGroup $group): array
    {
        return [
            'name' => $group->name,
            'min_select' => $group->min_select,
            'max_select' => $group->max_select,
            'is_required' => $group->is_required,
            'sort_order' => $group->sort_order,
            'modifiers' => $group->modifiers
                ->sortBy('sort_order')
                ->values()
                ->map(fn (Modifier $modifier) => [
                    'name' => $modifier->name,
                    'price_delta' => $modifier->price_delta,
                    'sort_order' => $modifier->sort_order,
                    'is_active' => $modifier->is_active,
                ])
                ->all(),
        ];
    }
}
