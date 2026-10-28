<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderReasonRequest;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Ordering\Actions\CancelOrder;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use App\Modules\Ordering\Actions\VoidOrder;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Support\OrderPayload;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Support\ShiftPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderPayload $payload,
    ) {}

    public function index(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): Response {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        $shift = null;
        $orders = [];
        $requestedShiftId = $request->integer('shift');

        if ($branch !== null) {
            if ($requestedShiftId > 0) {
                if (! $user->isOwner()) {
                    throw ValidationException::withMessages([
                        'shift' => 'Anda tidak dapat melihat shift kasir lain.',
                    ]);
                }

                $shift = CashierShift::query()
                    ->where('branch_id', $branch->id)
                    ->findOrFail($requestedShiftId);
            } else {
                $shift = CashierShift::query()
                    ->where('user_id', $user->id)
                    ->where('branch_id', $branch->id)
                    ->whereNull('closed_at')
                    ->first();
            }

            $query = Order::query()
                ->with(['branch', 'items.modifiers', 'payment'])
                ->where('branch_id', $branch->id);

            if ($requestedShiftId > 0 && $shift !== null) {
                $query->where('shift_id', $shift->id);
            } else {
                $timezone = (string) config('shifts.timezone', 'Asia/Jakarta');
                $start = now($timezone)->startOfDay()->utc();
                $end = now($timezone)->endOfDay()->utc();

                $query->where(function ($query) use ($shift, $start, $end): void {
                    $query->whereBetween('created_at', [$start, $end]);

                    if ($shift !== null) {
                        $query->orWhere('shift_id', $shift->id)
                            ->orWhere(function ($query): void {
                                $query->whereNotNull('shift_id')
                                    ->where('payment_status', 'unpaid')
                                    ->where('status', '!=', 'cancelled');
                            });
                    }
                });
            }

            $orders = $query
                ->orderByDesc('id')
                ->get()
                ->map(fn (Order $order) => [
                    ...($this->payload)($order),
                    'can' => [
                        'pay' => $user->can('pay', $order),
                        'cancel' => $user->can('cancel', $order) && $order->canBeCancelled(),
                        'void' => $user->can('void', $order),
                    ],
                ])
                ->values()
                ->all();
        }

        $cutoff = $shift?->scheduled_end_at?->copy()->addMinutes(
            (int) config('shifts.auto_close_grace_minutes', 15),
        );

        return Inertia::render('pos/Orders', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'orders' => $orders,
            'today' => now((string) config('shifts.timezone', 'Asia/Jakarta'))->toDateString(),
            'posUrl' => $branch ? '/pos?branch='.$branch->id : '/pos',
            'shift' => $shift ? ShiftPresenter::summary($shift) : null,
            'orders_cutoff_at' => $cutoff?->toIso8601String(),
            'can_create_orders' => $shift !== null && ($cutoff === null || now()->lessThan($cutoff)),
        ]);
    }

    public function cancel(
        OrderReasonRequest $request,
        Order $order,
        CancelOrder $cancelOrder,
    ): JsonResponse|RedirectResponse {
        $this->authorize('cancel', $order);

        if (! $order->canBeCancelled()) {
            abort(422, 'Pesanan sudah diproses dan tidak dapat dibatalkan.');
        }

        ($cancelOrder)($order, $request->user(), $request->validated('reason'));

        return $this->respond($request, $order->refresh(), 'Pesanan dibatalkan.');
    }

    public function void(
        OrderReasonRequest $request,
        Order $order,
        VoidOrder $voidOrder,
    ): JsonResponse|RedirectResponse {
        $this->authorize('void', $order);

        ($voidOrder)($order, $request->user(), $request->validated('reason'));

        return $this->respond($request, $order->refresh(), 'Pesanan di-void.');
    }

    private function respond(
        Request $request,
        Order $order,
        string $message,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'order' => ($this->payload)($order),
            ]);
        }

        return back()->with('success', $message);
    }
}
