<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Purchasing\Models\Purchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasePageTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = Branch::factory()->create();
    }

    public function test_cashier_can_open_pos_receive_page(): void
    {
        $cashier = User::factory()->cashier()->for($this->branchA)->create();

        $this->actingAs($cashier)
            ->get('/pos/purchases')
            ->assertOk();
    }

    public function test_kitchen_can_open_kitchen_receive_page(): void
    {
        $kitchen = User::factory()->kitchen()->for($this->branchA)->create();

        $this->actingAs($kitchen)
            ->get('/kitchen/purchases')
            ->assertOk();
    }

    public function test_owner_can_open_admin_receive_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/purchases/create')
            ->assertOk();
    }

    public function test_owner_can_open_admin_purchases_index(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/purchases')
            ->assertOk();
    }

    public function test_owner_can_open_admin_purchase_detail(): void
    {
        $owner = User::factory()->owner()->create();
        $purchase = Purchase::factory()->for($this->branchA)->create();

        $this->actingAs($owner)
            ->get("/admin/purchases/{$purchase->id}")
            ->assertOk();
    }

    public function test_owner_can_open_admin_suppliers_index(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/suppliers')
            ->assertOk();
    }

    public function test_cashier_cannot_open_admin_purchases_index(): void
    {
        $cashier = User::factory()->cashier()->for($this->branchA)->create();

        $this->actingAs($cashier)
            ->get('/admin/purchases')
            ->assertForbidden();
    }
}
