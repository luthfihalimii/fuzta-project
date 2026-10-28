<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 09:30:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_cashier_opens_shift_with_branch_float(): void
    {
        $branch = Branch::factory()->create(['cash_float' => 250000]);
        $cashier = User::factory()->cashier()->for($branch)->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('cashier_shifts', [
            'user_id' => $cashier->id,
            'branch_id' => $branch->id,
            'window_key' => 'morning',
            'opening_cash' => 250000,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'shift.opened']);
    }

    public function test_opening_twice_returns_the_same_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $shift->update([
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-28 15:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertRedirect();

        $this->assertSame(1, CashierShift::count());
    }

    public function test_outside_window_is_rejected(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28 22:00:00', 'Asia/Jakarta'));
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');

        $this->assertSame(0, CashierShift::count());
    }

    public function test_confirmation_is_required(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [])
            ->assertSessionHasErrors('opening_cash_confirmed');
    }

    public function test_cashier_cannot_open_for_another_branch(): void
    {
        $cashier = User::factory()->cashier()->for(Branch::factory())->create();
        $other = Branch::factory()->create();

        $this->actingAs($cashier)->post('/pos/shift/open', [
            'branch_id' => $other->id,
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('branch_id');
    }

    public function test_stale_shift_blocks_new_window(): void
    {
        $shift = CashierShift::factory()->open()->create([
            'window_key' => 'evening',
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-27 15:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-27 21:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');
    }

    public function test_closed_window_cannot_be_reopened(): void
    {
        $shift = CashierShift::factory()->closed()->create([
            'window_key' => 'morning',
            'scheduled_start_at' => CarbonImmutable::parse('2026-10-28 09:00', 'Asia/Jakarta')->utc(),
            'scheduled_end_at' => CarbonImmutable::parse('2026-10-28 15:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->actingAs($shift->user)->post('/pos/shift/open', [
            'opening_cash_confirmed' => true,
        ])->assertSessionHasErrors('shift');
    }

    public function test_branch_float_is_validated(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
            'cash_float' => -1,
        ])->assertSessionHasErrors('cash_float');

        $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
            'cash_float' => 300000,
        ])->assertRedirect();

        $this->assertSame(300000, $branch->refresh()->cash_float);
    }
}
