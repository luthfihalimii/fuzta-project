<?php

namespace App\Modules\Ordering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Modules\Branches\Support\BranchPresenter;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Ordering\Actions\CreateOrder;
use App\Modules\Ordering\Actions\ResolvePosBranch;
use App\Modules\Ordering\Data\CreateOrderData;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Models\Order;
use App\Modules\Ordering\Support\OrderPayload;
use App\Modules\Shifts\Actions\BuildShiftReminder;
use App\Modules\Shifts\Actions\ResolveShiftWindow;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Support\ShiftPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(
        Request $request,
        ResolvePosBranch $resolveBranch,
        ResolveShiftWindow $resolveWindow,
        BuildShiftReminder $buildReminder,
    ): Response {
        $user = $request->user();
        $requestedBranchId = $request->integer('branch');
        $branch = $resolveBranch($user, $requestedBranchId > 0 ? $requestedBranchId : null);

        $categories = [];
        $products = [];

        if ($branch !== null && $branch->is_active) {
            $products = Product::query()
                ->with([
                    'category',
                    'variants' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                    'modifierGroups' => fn ($query) => $query
                        ->orderBy('product_modifier_groups.sort_order'),
                    'modifierGroups.modifiers' => fn ($query) => $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->where('is_active', true)
                ->whereHas('category', fn ($query) => $query->where('is_active', true))
                ->whereHas('branches', fn ($query) => $query
                    ->where('branches.id', $branch->id)
                    ->where('product_branch_availability.is_available', true))
                ->orderBy('name')
                ->get()
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'category_name' => $product->category?->name,
                    'base_price' => $product->base_price,
                    'variants' => $product->variants
                        ->map(fn (ProductVariant $variant) => [
                            'id' => $variant->id,
                            'name' => $variant->name,
                            'price' => $variant->price,
                        ])
                        ->values()
                        ->all(),
                    'modifier_groups' => $product->modifierGroups
                        ->map(fn (ModifierGroup $group) => [
                            'id' => $group->id,
                            'name' => $group->name,
                            'min_select' => $group->min_select,
                            'max_select' => $group->max_select,
                            'is_required' => $group->is_required,
                            'modifiers' => $group->modifiers
                                ->map(fn (Modifier $modifier) => [
                                    'id' => $modifier->id,
                                    'name' => $modifier->name,
                                    'price_delta' => $modifier->price_delta,
                                ])
                                ->values()
                                ->all(),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();

            $categoryIds = collect($products)->pluck('category_id')->unique();

            $categories = Category::query()
                ->whereIn('id', $categoryIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])
                ->values()
                ->all();
        }

        $shift = $branch === null ? null : CashierShift::query()
            ->where('user_id', $user->id)
            ->where('branch_id', $branch->id)
            ->whereNull('closed_at')
            ->first();

        $cutoff = $shift?->scheduled_end_at?->copy()->addMinutes(
            (int) config('shifts.auto_close_grace_minutes', 15),
        );

        $staleUnpaidCount = $branch === null ? 0 : Order::query()
            ->where('branch_id', $branch->id)
            ->whereNotNull('shift_id')
            ->where('payment_status', 'unpaid')
            ->where('status', '!=', 'cancelled')
            ->count();

        $unreconciled = CashierShift::query()
            ->where('user_id', $user->id)
            ->where('close_type', 'auto')
            ->whereNull('reconciled_at')
            ->latest('closed_at')
            ->first();

        $window = ($resolveWindow)();

        return Inertia::render('pos/Index', [
            'branch' => $branch ? BranchPresenter::summary($branch) : null,
            'branches' => $user->isOwner() ? BranchPresenter::options() : [],
            'canChooseBranch' => $user->isOwner(),
            'categories' => $categories,
            'products' => $products,
            'ordersUrl' => $branch ? '/pos/orders?branch='.$branch->id : '/pos/orders',
            'shift' => $shift ? ShiftPresenter::summary($shift) : null,
            'shift_window' => $window === null ? null : [
                'key' => $window->key,
                'label' => $window->label,
                'start' => $window->startUtc->setTimezone(config('shifts.timezone'))->format('H:i'),
                'end' => $window->endUtc->setTimezone(config('shifts.timezone'))->format('H:i'),
            ],
            'shift_reminder' => ($buildReminder)($shift),
            'unreconciled_shift' => $unreconciled ? ShiftPresenter::summary($unreconciled) : null,
            'orders_cutoff_at' => $cutoff?->toIso8601String(),
            'can_create_orders' => $shift !== null && ($cutoff === null || now()->lessThan($cutoff)),
            'stale_unpaid_count' => $staleUnpaidCount,
        ]);
    }

    public function store(
        StoreOrderRequest $request,
        CreateOrder $createOrder,
        OrderPayload $payload,
    ): JsonResponse {
        $validated = $request->validated();
        $user = $request->user();

        $branchId = $user->isOwner()
            ? (int) $validated['branch_id']
            : (int) ($validated['branch_id'] ?? $user->branch_id);

        if ($branchId === 0) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang kasir belum diatur.',
            ]);
        }

        $order = $createOrder($user, new CreateOrderData(
            branchId: $branchId,
            channel: OrderChannel::from($validated['channel']),
            tableNumber: $validated['table_number'] ?? null,
            customerName: $validated['customer_name'] ?? null,
            note: $validated['note'] ?? null,
            items: array_values($validated['items']),
            idempotencyKey: $validated['idempotency_key'],
        ));

        return response()->json(
            ['order' => $payload($order)],
            $order->wasRecentlyCreated ? 201 : 200,
        );
    }
}
