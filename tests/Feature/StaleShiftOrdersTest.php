<?php

namespace Tests\Feature;

use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleShiftOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_order_from_another_shift_is_reachable(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $other = CashierShift::factory()->closed()->create([
            'branch_id' => $shift->branch_id,
        ]);
        $stale = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $other->id,
        ]);
        $stale->forceFill(['created_at' => now()->subDays(4)])->save();
        $cancelled = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $other->id,
            'status' => 'cancelled',
        ]);
        $cancelled->forceFill(['created_at' => now()->subDays(4)])->save();
        $paid = Order::factory()->paid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $other->id,
        ]);
        $paid->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->actingAs($shift->user)->get('/pos/orders')
            ->assertOk()
            ->assertInertia(function ($page) use ($stale): void {
                $ids = collect($page->toArray()['props']['orders'])->pluck('id');

                $this->assertTrue($ids->contains($stale->id));
                $this->assertSame(1, $ids->count());
            });
    }
}
