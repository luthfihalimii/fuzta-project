<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Branches\Models\Branch;
use App\Modules\Identity\Actions\WriteAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_entry_records_actor_action_target_and_reason(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        $log = app(WriteAuditLog::class)(
            $owner,
            'staff.deactivated',
            $cashier,
            ['is_active' => true],
            ['is_active' => false],
            'Tidak masuk tanpa kabar'
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'actor_id' => $owner->id,
            'action' => 'staff.deactivated',
            'target_type' => User::class,
            'target_id' => $cashier->id,
            'reason' => 'Tidak masuk tanpa kabar',
        ]);
    }

    public function test_audit_entry_uses_target_branch_and_filters_secrets(): void
    {
        $owner = User::factory()->owner()->create();
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();

        $log = app(WriteAuditLog::class)(
            $owner,
            'staff.updated',
            $cashier,
            ['password' => 'old-secret', 'name' => 'Lama'],
            ['password' => 'new-secret', 'name' => 'Baru', 'remember_token' => 'token'],
        );

        $this->assertSame($branch->id, $log->branch_id);
        $this->assertSame(['name' => 'Lama'], $log->before);
        $this->assertSame(['name' => 'Baru'], $log->after);
    }

    public function test_audit_log_target_relationship_returns_the_model(): void
    {
        $owner = User::factory()->owner()->create();
        $cashier = User::factory()->cashier()->create();

        app(WriteAuditLog::class)($owner, 'staff.deactivated', $cashier);

        $log = AuditLog::firstOrFail();

        $this->assertTrue($log->target->is($cashier));
    }
}
