<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_records_cash_in_and_audit(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in',
            'amount' => 50000,
            'reason' => 'Tambahan kembalian',
            'idempotency_key' => 'cm-1',
        ])->assertRedirect();

        $this->assertDatabaseHas('cash_movements', [
            'cashier_shift_id' => $shift->id,
            'direction' => 'in',
            'amount' => 50000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.cash_movement']);
    }

    public function test_cashier_is_forbidden(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $this->actingAs($shift->user)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in', 'amount' => 1000, 'reason' => 'x', 'idempotency_key' => 'cm-2',
        ])->assertForbidden();
    }

    public function test_closed_shift_is_rejected(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'in', 'amount' => 1000, 'reason' => 'x', 'idempotency_key' => 'cm-3',
        ])->assertSessionHasErrors('shift');
    }

    public function test_cash_out_above_available_is_rejected(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 100000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", [
            'direction' => 'out', 'amount' => 100001, 'reason' => 'Kas kecil', 'idempotency_key' => 'cm-4',
        ])->assertSessionHasErrors('amount');
    }

    public function test_same_key_returns_existing_movement(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $owner = User::factory()->owner()->create();
        $payload = [
            'direction' => 'in', 'amount' => 25000, 'reason' => 'Koreksi', 'idempotency_key' => 'cm-5',
        ];

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", $payload);
        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/cash-movements", $payload);

        $this->assertSame(1, CashMovement::count());
    }
}
