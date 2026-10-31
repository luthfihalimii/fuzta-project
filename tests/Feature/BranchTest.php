<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_branch_gets_default_opening_hours(): void
    {
        $branch = Branch::factory()->create();

        $this->assertSame('09:00', $branch->fresh()->opens_at);
        $this->assertSame('21:00', $branch->fresh()->closes_at);
    }

    public function test_owner_can_store_branch_with_opening_hours(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post('/admin/branches', [
            'name' => 'Cabang Timur',
            'code' => 'PF-03',
            'opens_at' => '08:00',
            'closes_at' => '22:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('branches', [
            'code' => 'PF-03',
            'opens_at' => '08:00',
            'closes_at' => '22:00',
        ]);
    }

    public function test_store_rejects_invalid_time_format(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/admin/branches', [
            'name' => 'Cabang', 'code' => 'PF-03', 'opens_at' => '25:00',
        ])->assertStatus(422)->assertJsonValidationErrors('opens_at');

        $this->actingAs($owner)->postJson('/admin/branches', [
            'name' => 'Cabang', 'code' => 'PF-03', 'closes_at' => '0900',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $this->assertDatabaseCount('branches', 0);
    }

    public function test_store_rejects_closes_not_after_opens(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->postJson('/admin/branches', [
            'name' => 'Cabang', 'code' => 'PF-03',
            'opens_at' => '21:00', 'closes_at' => '09:00',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $this->assertDatabaseCount('branches', 0);
    }

    public function test_update_rejects_inverted_hours_against_db_value(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create(); // 09:00–21:00

        // satu field saja: bandingkan terhadap nilai DB yang tidak dikirim
        $this->actingAs($owner)->patchJson("/admin/branches/{$branch->id}", [
            'closes_at' => '08:00',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $this->actingAs($owner)->patchJson("/admin/branches/{$branch->id}", [
            'opens_at' => '22:00',
        ])->assertStatus(422)->assertJsonValidationErrors('closes_at');

        $branch->refresh();
        $this->assertSame('09:00', $branch->opens_at);
        $this->assertSame('21:00', $branch->closes_at);
    }

    public function test_cashier_cannot_update_branch_hours(): void
    {
        $cashier = User::factory()->cashier()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($cashier)->patch("/admin/branches/{$branch->id}", [
            'closes_at' => '23:00',
        ])->assertForbidden();
    }

    public function test_update_audit_includes_hours_before_after(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->patch("/admin/branches/{$branch->id}", [
            'closes_at' => '22:00',
        ])->assertRedirect();

        $log = AuditLog::query()->where('action', 'branch.updated')->latest('id')->firstOrFail();

        $this->assertSame('21:00', $log->before['closes_at']);
        $this->assertSame('22:00', $log->after['closes_at']);
    }

    public function test_branches_page_sends_opening_hours(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();

        $this->actingAs($owner)->get('/admin/branches')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('admin/branches/Index')
                ->where('branches.0.opens_at', '09:00')
                ->where('branches.0.closes_at', '21:00')
            );
    }
}
