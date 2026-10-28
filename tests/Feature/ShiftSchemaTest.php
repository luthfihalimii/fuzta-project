<?php

namespace Tests\Feature;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Ordering\Models\Order;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_stores_cash_and_snapshot_columns(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'opening_cash' => 200000,
        ]);

        $this->assertNull($shift->closed_at);
        $this->assertSame(200000, $shift->opening_cash);
        $this->assertNull($shift->expected_cash);
        $this->assertDatabaseHas('branches', ['id' => $shift->branch_id, 'cash_float' => 0]);
    }

    public function test_one_open_shift_per_user_and_branch(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $this->expectException(QueryException::class);

        CashierShift::factory()->open()->create([
            'user_id' => $shift->user_id,
            'branch_id' => $shift->branch_id,
        ]);
    }

    public function test_duplicate_window_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();

        $this->expectException(QueryException::class);

        CashierShift::factory()->open()->create([
            'user_id' => $shift->user_id,
            'branch_id' => $shift->branch_id,
            'window_key' => $shift->window_key,
            'scheduled_start_at' => $shift->scheduled_start_at,
            'scheduled_end_at' => $shift->scheduled_end_at,
        ]);
    }

    public function test_cash_movement_is_idempotent_per_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $movement = CashMovement::factory()->for($shift, 'shift')->create([
            'idempotency_key' => 'cash-1',
        ]);

        $this->assertSame('in', $movement->direction->value);

        $this->expectException(QueryException::class);

        CashMovement::factory()->for($shift, 'shift')->create([
            'idempotency_key' => 'cash-1',
        ]);
    }

    public function test_orders_link_to_shifts(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $order = Order::factory()->unpaid()->create([
            'branch_id' => $shift->branch_id,
            'shift_id' => $shift->id,
        ]);

        $this->assertSame($shift->id, $order->shift->id);
        $this->assertSame($shift->id, $order->shift_id);
    }

    public function test_audit_actor_can_be_null(): void
    {
        $log = AuditLog::create([
            'actor_id' => null,
            'branch_id' => null,
            'action' => 'shift.auto_closed',
            'target_type' => CashierShift::class,
            'target_id' => 1,
        ]);

        $this->assertNull($log->actor_id);
    }
}
