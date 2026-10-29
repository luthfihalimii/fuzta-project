<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Kitchen\Actions\TransitionOrderStatus;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Support\OrderPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KitchenController extends Controller
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

        return Inertia::render('kitchen/Index', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'kitchenLateMinutes' => (int) config('kitchen.late_minutes', 15),
        ]);
    }

    public function orders(
        Request $request,
        ResolvePosBranch $resolveBranch,
    ): JsonResponse {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        if ($branch === null) {
            return response()->json(['orders' => []]);
        }

        $status = null;

        if ($request->filled('status')) {
            $rawStatus = $request->query('status');
            $status = is_string($rawStatus)
                ? OrderStatus::tryFrom($rawStatus)
                : null;

            abort_if(
                $status === null || $status === OrderStatus::Cancelled,
                422,
                'Status pesanan tidak valid.',
            );
        }

        $orders = Order::query()
            ->with(['branch', 'items.modifiers', 'payment'])
            ->visibleToKitchen($branch->id)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when(
                $status === OrderStatus::Completed,
                fn ($query) => $query
                    ->orderByDesc('updated_at')
                    ->orderByDesc('id')
                    ->limit(100),
                fn ($query) => $query->orderBy('created_at')->orderBy('id'),
            )
            ->get()
            ->map(fn (Order $order) => ($this->payload)($order))
            ->values()
            ->all();

        return response()->json(['orders' => $orders]);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        TransitionOrderStatus $transition,
    ): JsonResponse {
        $this->authorize('transition', $order);

        $order = ($transition)(
            $order,
            $request->user(),
            OrderStatus::from($request->validated('status')),
        );

        return response()->json([
            'order' => ($this->payload)($order),
        ]);
    }
}
