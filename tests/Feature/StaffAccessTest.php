<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_cannot_open_staff_admin(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/staff')->assertForbidden();
    }

    public function test_kitchen_user_cannot_open_staff_admin(): void
    {
        $kitchen = User::factory()->kitchen()->create();

        $this->actingAs($kitchen)->get('/admin/staff')->assertForbidden();
    }

    public function test_owner_can_open_staff_admin(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/staff')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/staff/Index'));
    }

    public function test_owner_can_deactivate_staff_without_deleting(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($owner)
            ->patch("/admin/staff/{$cashier->id}", ['is_active' => false])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'is_active' => false]);
    }

    public function test_owner_cannot_deactivate_self(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->patch("/admin/staff/{$owner->id}", ['is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->assertDatabaseHas('users', ['id' => $owner->id, 'is_active' => true]);
    }

    public function test_cashier_cannot_update_staff(): void
    {
        $cashier = User::factory()->cashier()->create();
        $other = User::factory()->cashier()->create();

        $this->actingAs($cashier)
            ->patch("/admin/staff/{$other->id}", ['is_active' => false])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $other->id, 'is_active' => true]);
    }

    public function test_owner_can_create_staff_with_role_and_branch(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->post('/admin/staff', [
            'name' => 'Kasir Baru',
            'email' => 'kasir.baru@example.test',
            'password' => 'secret123',
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'kasir.baru@example.test',
            'role' => 'cashier',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_owner_can_open_branch_admin(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->get('/admin/branches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/branches/Index'));
    }

    public function test_cashier_cannot_open_branch_admin(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->get('/admin/branches')->assertForbidden();
    }

    public function test_owner_can_create_branch(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/admin/branches', [
            'name' => 'Cabang Ketiga',
            'code' => 'PF-03',
            'address' => 'Jl. Contoh 3',
            'phone' => '08123456789',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', ['code' => 'PF-03', 'name' => 'Cabang Ketiga']);
    }

    public function test_cashier_cannot_create_branch(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)->post('/admin/branches', [
            'name' => 'Cabang Tidak Sah',
            'code' => 'PF-99',
        ])->assertForbidden();

        $this->assertDatabaseMissing('branches', ['code' => 'PF-99']);
    }
}
