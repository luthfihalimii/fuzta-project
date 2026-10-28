<?php

namespace App\Modules\Shifts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Identity\Enums\Role;
use App\Modules\Shifts\Actions\CloseShift;
use App\Modules\Shifts\Actions\ReconcileShift;
use App\Modules\Shifts\Actions\RecordCashMovement;
use App\Modules\Shifts\Enums\CashMovementDirection;
use App\Modules\Shifts\Http\Requests\CloseShiftRequest;
use App\Modules\Shifts\Http\Requests\ReconcileShiftRequest;
use App\Modules\Shifts\Http\Requests\RecordCashMovementRequest;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Queries\ShiftDailySummaryQuery;
use App\Modules\Shifts\Queries\ShiftReportQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminShiftController extends Controller
{
    public function index(
        Request $request,
        ShiftReportQuery $report,
        ShiftDailySummaryQuery $dailySummary,
    ): Response {
        $this->authorize('viewAny', CashierShift::class);

        $filters = [
            'from' => $request->string('from')->toString(),
            'to' => $request->string('to')->toString(),
            'branch' => $request->integer('branch') ?: null,
            'user' => $request->integer('user') ?: null,
            'status' => $request->string('status')->toString() ?: null,
        ];

        $view = $request->string('view')->toString() === 'daily' ? 'daily' : 'shifts';
        $users = User::query()
            ->whereIn('role', [Role::Cashier, Role::Owner])
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('admin/shifts/Index', [
            'view' => $view,
            'filters' => $filters,
            'branches' => BranchPresenter::options(),
            'users' => $users,
            'shifts' => $view === 'shifts' ? $report->list($filters) : null,
            'daily' => $view === 'daily' ? ['rows' => $dailySummary($filters)] : null,
        ]);
    }

    public function show(CashierShift $shift, ShiftReportQuery $report): Response
    {
        $this->authorize('view', $shift);

        return Inertia::render('admin/shifts/Show', [
            ...$report->detail($shift),
            'users' => User::query()
                ->whereIn('role', [Role::Cashier, Role::Owner])
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function close(
        CloseShiftRequest $request,
        CashierShift $shift,
        CloseShift $closeShift,
    ): RedirectResponse {
        $this->authorize('close', $shift);
        $validated = $request->validated();

        $closeShift(
            $shift,
            $request->user(),
            (int) $validated['counted_cash'],
            (int) $validated['cash_deposited'],
            $validated['closing_note'] ?? null,
        );

        return back()->with('success', 'Shift berhasil ditutup.');
    }

    public function reconcile(
        ReconcileShiftRequest $request,
        CashierShift $shift,
        ReconcileShift $reconcileShift,
    ): RedirectResponse {
        $this->authorize('reconcile', $shift);
        $validated = $request->validated();

        $reconcileShift(
            $shift,
            $request->user(),
            (int) $validated['counted_cash'],
            (int) $validated['cash_deposited'],
            $validated['reconciliation_note'] ?? null,
        );

        return back()->with('success', 'Shift berhasil direkonsiliasi.');
    }

    public function storeCashMovement(
        RecordCashMovementRequest $request,
        CashierShift $shift,
        RecordCashMovement $recordCashMovement,
    ): RedirectResponse {
        $this->authorize('recordCashMovement', CashierShift::class);

        $validated = $request->validated();

        $recordCashMovement(
            $shift,
            $request->user(),
            CashMovementDirection::from($validated['direction']),
            (int) $validated['amount'],
            $validated['reason'],
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Kas shift berhasil dicatat.');
    }
}
