<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_and_open_shift_detail(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/admin/shifts')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/shifts/Index')
                ->has('shifts.data', 1));

        $this->actingAs($owner)->get("/admin/shifts/{$shift->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/shifts/Show')
                ->where('shift.id', $shift->id));
    }

    public function test_cashier_and_kitchen_cannot_view_reports(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();
        $kitchen = User::factory()->kitchen()->for(Branch::factory())->create();

        $this->actingAs($cashier)->get('/admin/shifts')->assertForbidden();
        $this->actingAs($kitchen)->get('/admin/shifts')->assertForbidden();
    }

    public function test_filters_by_branch_and_date(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $old = CashierShift::factory()->closed()->create(['branch_id' => $branchA->id]);
        $old->forceFill(['opened_at' => CarbonImmutable::now('Asia/Jakarta')->subDays(3)->utc()])->save();
        $today = CashierShift::factory()->closed()->create(['branch_id' => $branchA->id]);
        CashierShift::factory()->closed()->create(['branch_id' => $branchB->id]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get("/admin/shifts?branch={$branchA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('shifts.data', 2));

        $this->actingAs($owner)->get("/admin/shifts?branch={$branchA->id}&from={$today->opened_at->toDateString()}&to={$today->opened_at->toDateString()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('shifts.data', 1));
    }

    public function test_order_count_uses_creator_shift_and_cash_uses_payer_shift(): void
    {
        $shiftA = CashierShift::factory()->open()->create();
        $shiftB = CashierShift::factory()->open()->create();
        $order = Order::factory()->paid()->create([
            'branch_id' => $shiftA->branch_id,
            'shift_id' => $shiftA->id,
            'total' => 50000,
        ]);
        $order->payment->forceFill([
            'shift_id' => $shiftB->id, 'method' => 'cash', 'amount' => 50000,
        ])->save();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get("/admin/shifts/{$shiftA->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.order_count', 1));

        $this->actingAs($owner)->get("/admin/shifts/{$shiftB->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.order_count', 0)
                ->where('totals.cash_sales_total', 50000));
    }
}
