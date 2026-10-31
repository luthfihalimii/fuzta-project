<?php

namespace App\Modules\Branches\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Branch::class);

        return Inertia::render('admin/branches/Index', [
            'branches' => Branch::query()
                ->withCount('users')
                ->orderBy('code')
                ->get()
                ->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'address' => $branch->address,
                    'phone' => $branch->phone,
                    'cash_float' => $branch->cash_float,
                    'is_active' => $branch->is_active,
                    'opens_at' => $branch->opens_at,
                    'closes_at' => $branch->closes_at,
                    'users_count' => $branch->users_count,
                ]),
        ]);
    }

    public function store(Request $request, WriteAuditLog $audit): RedirectResponse
    {
        $this->authorize('create', Branch::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:branches,code'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:30'],
            'cash_float' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'opens_at' => ['sometimes', 'date_format:H:i'],
            'closes_at' => ['sometimes', 'date_format:H:i'],
        ]);

        if (($data['closes_at'] ?? '21:00') <= ($data['opens_at'] ?? '09:00')) {
            throw ValidationException::withMessages([
                'closes_at' => 'Jam tutup harus setelah jam buka.',
            ]);
        }

        $branch = DB::transaction(function () use ($data, $request, $audit) {
            $branch = Branch::create($data);

            $audit(
                $request->user(),
                'branch.created',
                $branch,
                [],
                $this->snapshot($branch),
            );

            return $branch;
        });

        return back()->with('success', "Cabang {$branch->name} berhasil ditambahkan.");
    }

    public function update(
        Request $request,
        Branch $branch,
        WriteAuditLog $audit,
    ): RedirectResponse {
        $this->authorize('update', $branch);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('branches', 'code')->ignore($branch->id),
            ],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'cash_float' => ['sometimes', 'integer', 'min:0', 'max:1000000000'],
            'is_active' => ['sometimes', 'boolean'],
            'opens_at' => ['sometimes', 'date_format:H:i'],
            'closes_at' => ['sometimes', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? null;

        unset($data['reason']);

        if (($data['closes_at'] ?? $branch->closes_at) <= ($data['opens_at'] ?? $branch->opens_at)) {
            throw ValidationException::withMessages([
                'closes_at' => 'Jam tutup harus setelah jam buka.',
            ]);
        }

        DB::transaction(function () use ($branch, $data, $reason, $request, $audit) {
            $before = $this->snapshot($branch);

            $branch->fill($data);
            $branch->save();
            $branch->refresh();

            $audit(
                $request->user(),
                'branch.updated',
                $branch,
                $before,
                $this->snapshot($branch),
                $reason,
            );
        });

        return back()->with('success', 'Data cabang diperbarui.');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Branch $branch): array
    {
        return [
            'name' => $branch->name,
            'code' => $branch->code,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'cash_float' => $branch->cash_float,
            'is_active' => $branch->is_active,
            'opens_at' => $branch->opens_at,
            'closes_at' => $branch->closes_at,
        ];
    }
}
