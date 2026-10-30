<?php

namespace Tests\Feature;

use App\Modules\Purchasing\Models\Purchase;
use App\Modules\Purchasing\Models\PurchaseItem;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PurchaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_documents_and_items_are_immutable(): void
    {
        $purchase = Purchase::factory()->create();
        $item = PurchaseItem::factory()->for($purchase)->create();

        try {
            $purchase->update(['reference' => 'x']);
            $this->fail('Purchase update was accepted.');
        } catch (LogicException $e) {
            $this->assertSame('Purchases are immutable.', $e->getMessage());
        }

        try {
            $item->update(['quantity' => 2]);
            $this->fail('Purchase item update was accepted.');
        } catch (LogicException $e) {
            $this->assertSame('Purchase items are immutable.', $e->getMessage());
        }
    }

    public function test_purchase_documents_and_items_cannot_be_deleted(): void
    {
        $item = PurchaseItem::factory()->create();

        try {
            $item->purchase->delete();
            $this->fail('Purchase delete was accepted.');
        } catch (LogicException $e) {
            $this->assertSame('Purchases are immutable.', $e->getMessage());
        }

        try {
            $item->delete();
            $this->fail('Purchase item delete was accepted.');
        } catch (LogicException $e) {
            $this->assertSame('Purchase items are immutable.', $e->getMessage());
        }
    }

    public function test_idempotency_key_unique_per_branch(): void
    {
        $purchase = Purchase::factory()->create();
        Purchase::factory()->create([
            'idempotency_key' => $purchase->idempotency_key,
        ]);
        $this->assertDatabaseCount('purchases', 2);

        try {
            Purchase::factory()->for($purchase->branch)->create([
                'idempotency_key' => $purchase->idempotency_key,
            ]);
            $this->fail('Duplicate idempotency key for the same branch was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }
    }

    public function test_supplier_name_unique(): void
    {
        $name = fake()->unique()->company();
        Supplier::factory()->create(['name' => $name]);

        try {
            Supplier::factory()->create(['name' => $name]);
            $this->fail('Duplicate supplier name was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }
    }

    public function test_relations_and_casts(): void
    {
        $purchase = Purchase::factory()->create();
        $item = PurchaseItem::factory()->for($purchase)->create(['quantity' => 5]);

        $this->assertSame($purchase->supplier_id, $purchase->supplier->id);
        $this->assertSame($purchase->recorded_by, $purchase->recordedBy->id);
        $this->assertSame($item->id, $purchase->items->first()->id);
        $this->assertSame($item->ingredient_id, $item->ingredient->id);
        $this->assertSame($item->unit_id, $item->unit->id);
        $this->assertSame(5, $item->quantity);
        $this->assertSame(0, $purchase->total);
        $this->assertSame($purchase->received_at->toDateString(), $purchase->received_at->format('Y-m-d'));
        $this->assertTrue($purchase->supplier->is_active);
        $this->assertIsBool($purchase->supplier->is_active);
    }
}
