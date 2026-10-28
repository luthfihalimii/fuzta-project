<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Shifts\Actions\BuildShiftReminder;
use App\Modules\Shifts\Models\CashierShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileShiftTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_reconciles_auto_closed_shift(): void
    {
        $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 200000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
            'counted_cash' => 195000,
            'cash_deposited' => 95000,
            'reconciliation_note' => 'Kurang 5000',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame(-5000, $shift->variance);
        $this->assertNotNull($shift->reconciled_at);
        $this->assertSame($owner->id, $shift->reconciled_by_id);
        $this->assertSame('auto', $shift->close_type->value);
    }

    public function test_note_required_on_variance(): void
    {
        $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 200000]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
            'counted_cash' => 195000, 'cash_deposited' => 95000,
        ])->assertSessionHasErrors('reconciliation_note');
    }

    public function test_cashier_reconciles_own_shift_only(): void
    {
        $shift = CashierShift::factory()->autoClosed()->create(['expected_cash' => 0]);
        $other = User::factory()->cashier()->for($shift->branch)->create();

        $this->actingAs($other)->post("/pos/shifts/{$shift->id}/reconcile", [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertForbidden();

        $this->actingAs($shift->user)->post("/pos/shifts/{$shift->id}/reconcile", [
            'counted_cash' => 0, 'cash_deposited' => 0, 'reconciliation_note' => 'Beres',
        ])->assertRedirect();
    }

    public function test_manual_shift_cannot_be_reconciled(): void
    {
        $shift = CashierShift::factory()->closed()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post("/admin/shifts/{$shift->id}/reconcile", [
            'counted_cash' => 0, 'cash_deposited' => 0,
        ])->assertSessionHasErrors('shift');
    }

    public function test_reminder_levels(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'scheduled_end_at' => now()->addMinutes(20),
        ]);
        $this->assertSame('ending', app(BuildShiftReminder::class)($shift)['level']);

        $shift->update(['scheduled_end_at' => now()->subMinutes(1)]);
        $this->assertSame('overdue', app(BuildShiftReminder::class)($shift)['level']);

        $shift->update(['scheduled_end_at' => now()->subMinutes(16)]);
        $this->assertSame('cutoff', app(BuildShiftReminder::class)($shift)['level']);
    }
}
