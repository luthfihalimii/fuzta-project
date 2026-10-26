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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $orders = [];

        if ($branch !== null) {
            $start = now('Asia/Jakarta')->startOfDay()->utc();
            $end = now('Asia/Jakarta')->endOfDay()->utc();

            $orders = Order::query()
                ->with(['branch', 'items.modifiers', 'payment'])
                ->where('branch_id', $branch->id)
                ->whereBetween('created_at', [$start, $end])
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

        return Inertia::render('pos/Orders', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'orders' => $orders,
            'today' => now('Asia/Jakarta')->toDateString(),
            'posUrl' => $branch ? '/pos?branch='.$branch->id : '/pos',
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
