<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Actions\CalculateShiftTotals;
use App\Modules\Shifts\Actions\ResolveOpenShift;
use App\Modules\Shifts\Models\CashierShift;
use App\Modules\Shifts\Models\CashMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftTotalsTest extends TestCase
{
    use RefreshDatabase;

    private function paidPayment(CashierShift $shift, int $amount, string $method): Payment
    {
        $order = Order::factory()->paid()->for($shift->branch)->create([
            'shift_id' => $shift->id,
            'total' => $amount,
        ]);
        $payment = $order->payment;
        $payment->forceFill(['shift_id' => $shift->id, 'amount' => $amount, 'method' => $method])->save();

        return $payment->refresh();
    }

    public function test_totals_include_cash_sales_and_exclude_qris(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 200000]);
        $this->paidPayment($shift, 100000, 'cash');
        $this->paidPayment($shift, 75000, 'qris_static');

        CashMovement::factory()->for($shift, 'shift')->create([
            'direction' => 'in', 'amount' => 50000,
        ]);
        CashMovement::factory()->for($shift, 'shift')->create([
            'direction' => 'out', 'amount' => 20000,
        ]);

        $totals = app(CalculateShiftTotals::class)($shift);

        $this->assertSame(100000, $totals['cash_sales_total']);
        $this->assertSame(75000, $totals['qris_sales_total']);
        $this->assertSame(50000, $totals['cash_in_total']);
        $this->assertSame(20000, $totals['cash_out_total']);
        $this->assertSame(330000, $totals['expected_cash']);
    }

    public function test_voided_payment_is_excluded(): void
    {
        $shift = CashierShift::factory()->open()->create(['opening_cash' => 0]);
        $payment = $this->paidPayment($shift, 100000, 'cash');
        $payment->forceFill(['status' => 'voided', 'voided_at' => now()])->save();

        $totals = app(CalculateShiftTotals::class)($shift);

        $this->assertSame(0, $totals['cash_sales_total']);
    }

    public function test_resolve_open_shift_returns_actor_shift(): void
    {
        $shift = CashierShift::factory()->open()->create();

        $found = app(ResolveOpenShift::class)($shift->user, $shift->branch_id);

        $this->assertSame($shift->id, $found->id);
    }

    public function test_resolve_open_shift_rejects_without_shift(): void
    {
        $user = User::factory()->cashier()->for(Branch::factory())->create();

        $this->expectException(ValidationException::class);

        app(ResolveOpenShift::class)($user, $user->branch_id);
    }

    public function test_resolve_open_shift_is_scoped_to_branch(): void
    {
        $shift = CashierShift::factory()->open()->create();
        $otherBranch = Branch::factory()->create();

        $this->expectException(ValidationException::class);

        app(ResolveOpenShift::class)($shift->user, $otherBranch->id);
    }
}
