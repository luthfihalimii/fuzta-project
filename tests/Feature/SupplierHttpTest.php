<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SupplierHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
        $this->cashier = User::factory()->cashier()->create();
    }

    public function test_owner_creates_supplier_with_audit(): void
    {
        $this->assertTrue(Route::has('admin.suppliers.index'));

        $this->actingAs($this->owner)
            ->post('/admin/suppliers', ['name' => 'CV Sumber Pangan', 'phone' => '0812-3456'])
            ->assertRedirect();

        $supplier = Supplier::sole();
        $this->assertSame('CV Sumber Pangan', $supplier->name);
        $this->assertSame('0812-3456', $supplier->phone);
        $this->assertTrue($supplier->is_active);

        $log = AuditLog::query()->where('action', 'supplier.created')->sole();
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame(Supplier::class, $log->target_type);
        $this->assertSame($supplier->id, $log->target_id);
        $this->assertSame([], $log->before);
        $this->assertSame(
            ['name' => 'CV Sumber Pangan', 'phone' => '0812-3456', 'is_active' => true],
            $log->after,
        );
    }

    public function test_duplicate_name_rejected(): void
    {
        Supplier::factory()->create(['name' => 'CV Sumber Pangan']);

        $this->actingAs($this->owner)
            ->postJson('/admin/suppliers', ['name' => 'CV Sumber Pangan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_owner_updates_supplier_with_before_after_audit(): void
    {
        $supplier = Supplier::factory()->create([
            'name' => 'CV Sumber Pangan',
            'phone' => '0812-3456',
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->patch("/admin/suppliers/{$supplier->id}", [
                'name' => 'CV Sumber Baru',
                'phone' => '0899-0000',
                'is_active' => false,
            ])
            ->assertRedirect();

        $supplier->refresh();
        $this->assertSame('CV Sumber Baru', $supplier->name);
        $this->assertSame('0899-0000', $supplier->phone);
        $this->assertFalse($supplier->is_active);

        $log = AuditLog::query()->where('action', 'supplier.updated')->sole();
        $this->assertSame(
            ['name' => 'CV Sumber Pangan', 'phone' => '0812-3456', 'is_active' => true],
            $log->before,
        );
        $this->assertSame(
            ['name' => 'CV Sumber Baru', 'phone' => '0899-0000', 'is_active' => false],
            $log->after,
        );
    }

    public function test_non_owner_forbidden(): void
    {
        $this->actingAs($this->cashier)
            ->post('/admin/suppliers', ['name' => 'CV Ilegal'])
            ->assertForbidden();

        $this->assertDatabaseCount('suppliers', 0);
    }
}
