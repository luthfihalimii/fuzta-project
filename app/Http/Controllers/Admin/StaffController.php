<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Identity\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', User::class);

        return Inertia::render('admin/staff/Index', [
            'staff' => User::query()
                ->with('branch')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role()->value,
                    'role_label' => $user->role()->label(),
                    'branch_id' => $user->branch_id,
                    'branch_name' => $user->branch?->name,
                    'is_active' => $user->is_active,
                ]),
            'branches' => Branch::query()->orderBy('code')->get(['id', 'name', 'code']),
            'roles' => collect(Role::cases())->map(fn (Role $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ]),
        ]);
    }

    public function store(StoreStaffRequest $request, WriteAuditLog $audit): RedirectResponse
    {
        $staff = DB::transaction(function () use ($request, $audit) {
            $staff = User::create($request->validated());

            $audit(
                $request->user(),
                'staff.created',
                $staff,
                [],
                $this->snapshot($staff),
            );

            return $staff;
        });

        return back()->with('success', "Staf {$staff->name} berhasil ditambahkan.");
    }

    public function update(
        UpdateStaffRequest $request,
        User $staff,
        WriteAuditLog $audit,
    ): RedirectResponse {
        $data = $request->validated();
        $reason = $data['reason'] ?? null;

        unset($data['reason']);

        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        if ($staff->is($request->user()) && ! (bool) $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'Anda tidak dapat menonaktifkan akun sendiri.',
            ]);
        }

        $newRole = $data['role'] ?? $staff->role()->value;

        if (
            $staff->isOwner()
            && ($newRole !== Role::Owner->value || ! (bool) $data['is_active'])
            && User::query()
                ->where('role', Role::Owner)
                ->where('is_active', true)
                ->whereKeyNot($staff->id)
                ->doesntExist()
        ) {
            throw ValidationException::withMessages([
                'role' => 'Minimal satu pemilik aktif harus tersisa.',
            ]);
        }

        DB::transaction(function () use ($staff, $data, $reason, $request, $audit) {
            $before = $this->snapshot($staff);
            $passwordChanged = array_key_exists('password', $data);

            $staff->fill($data);
            $staff->save();
            $staff->refresh();

            $after = $this->snapshot($staff);

            if ($passwordChanged || ($before['is_active'] && ! $after['is_active'])) {
                $this->invalidateSessions($staff);
                $staff->refresh();
            }

            if ($passwordChanged) {
                $audit($request->user(), 'staff.password_reset', $staff, [], [], $reason);
            }

            $action = match (true) {
                $before['is_active'] && ! $after['is_active'] => 'staff.deactivated',
                ! $before['is_active'] && $after['is_active'] => 'staff.activated',
                default => 'staff.updated',
            };

            $audit($request->user(), $action, $staff, $before, $after, $reason);
        });

        return back()->with('success', 'Data staf diperbarui.');
    }

    private function invalidateSessions(User $staff): void
    {
        $staff->forceFill(['remember_token' => null])->save();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $staff->id)
                ->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $staff): array
    {
        return [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $staff->role()->value,
            'branch_id' => $staff->branch_id,
            'is_active' => $staff->is_active,
        ];
    }
}
