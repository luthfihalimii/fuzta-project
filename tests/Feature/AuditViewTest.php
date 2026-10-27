<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AuditViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_audit_logs_with_safe_details(): void
    {
        $owner = User::factory()->owner()->create();
        $order = Order::factory()->unpaid()->create();

        app(WriteAuditLog::class)(
            $owner,
            'order.paid',
            $order,
            ['payment_status' => 'unpaid'],
            ['payment_status' => 'paid', 'password' => 'rahasia'],
            'Pembayaran diterima',
        );

        $response = $this->actingAs($owner)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/audit/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'order.paid')
                ->where('logs.data.0.actor_name', $owner->name)
                ->where('logs.data.0.target_type', 'Order')
                ->where('logs.data.0.target_id', $order->id)
                ->where('logs.data.0.reason', 'Pembayaran diterima')
                ->where('logs.data.0.after.payment_status', 'paid')
            );

        $response->assertDontSee('rahasia');
    }

    public function test_non_owner_cannot_view_audit_logs(): void
    {
        $kitchen = User::factory()->kitchen()->for(Branch::factory())->create();

        $this->actingAs($kitchen)
            ->get('/admin/audit')
            ->assertForbidden();
    }
}
