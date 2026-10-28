<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_out_requires_negative_quantity(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(ValidationException::class);

        app(RecordStockMovement::class)(
            $owner,
            $branch->id,
            $ingredient->id,
            5,
            StockMovementType::TransferOut,
            'Konkur'
        );
    }

    public function test_transfer_in_requires_positive_quantity(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->expectException(ValidationException::class);

        app(RecordStockMovement::class)(
            $owner,
            $branch->id,
            $ingredient->id,
            -5,
            StockMovementType::TransferIn,
            'Konkur'
        );
    }

    public function test_transfer_factory_states(): void
    {
        $dispatched = StockTransfer::factory()->dispatched()->create();
        $this->assertSame(TransferStatus::Dispatched, $dispatched->status);
        $this->assertNotNull($dispatched->dispatched_at);
        $this->assertNull($dispatched->received_at);
        $this->assertNull($dispatched->cancel_reason);

        $received = StockTransfer::factory()->received()->create();
        $this->assertSame(TransferStatus::Received, $received->status);
        $this->assertNotNull($received->dispatched_at);
        $this->assertNotNull($received->received_at);
        $this->assertNull($received->cancel_reason);

        $cancelled = StockTransfer::factory()->cancelled('rusak')->create();
        $this->assertSame(TransferStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->dispatched_at);
        $this->assertNull($cancelled->received_at);
        $this->assertSame('rusak', $cancelled->cancel_reason);

        $draft = StockTransfer::factory()->create();
        $this->assertSame(TransferStatus::Draft, $draft->status);
        $this->assertNotSame($draft->origin_branch_id, $draft->destination_branch_id);

        $item = StockTransferItem::factory()->for($dispatched, 'transfer')->create(['quantity' => 7]);

        $this->assertInstanceOf(StockTransferItem::class, $dispatched->items()->first());
        $this->assertSame(7, $dispatched->items()->first()->quantity);
        $this->assertSame($dispatched->id, $item->transfer->id);
    }

    public function test_cashier_cannot_create_transfer(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $cashier = User::factory()->cashier()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($cashier)
            ->post('/admin/stock/transfers', [
                'origin_branch_id' => $origin->id,
                'destination_branch_id' => $destination->id,
                'items' => [
                    ['ingredient_id' => $ingredient->id, 'quantity' => 5],
                ],
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_draft_rejects_same_origin_and_destination(): void
    {
        $branch = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [
                'origin_branch_id' => $branch->id,
                'destination_branch_id' => $branch->id,
                'items' => [
                    ['ingredient_id' => $ingredient->id, 'quantity' => 5],
                ],
            ])
            ->assertSessionHasErrors('destination_branch_id');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_draft_rejects_invalid_items(): void
    {
        [$origin, $destination] = Branch::factory()->count(2)->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $base = [
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
        ];

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [...$base, 'items' => [
                ['ingredient_id' => $ingredient->id, 'quantity' => 1],
                ['ingredient_id' => $ingredient->id, 'quantity' => 2],
            ]])
            ->assertSessionHasErrors('items.1.ingredient_id');

        $this->assertDatabaseCount('stock_transfers', 0);

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [...$base, 'items' => [
                ['ingredient_id' => $ingredient->id, 'quantity' => 0],
            ]])
            ->assertSessionHasErrors('items.0.quantity');

        $this->assertDatabaseCount('stock_transfers', 0);

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [...$base, 'items' => [
                ['ingredient_id' => 999999, 'quantity' => 1],
            ]])
            ->assertSessionHasErrors('items.0.ingredient_id');

        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_draft_creates_transfer_without_touching_stock(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        [$ingA, $ingB] = Ingredient::factory()->count(2)->create();

        StockBalance::factory()->for($origin)->for($ingA, 'ingredient')->create(['quantity' => 100]);
        StockBalance::factory()->for($destination)->for($ingA, 'ingredient')->create(['quantity' => 0]);
        StockBalance::factory()->for($origin)->for($ingB, 'ingredient')->create(['quantity' => 100]);
        StockBalance::factory()->for($destination)->for($ingB, 'ingredient')->create(['quantity' => 0]);

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [
                'origin_branch_id' => $origin->id,
                'destination_branch_id' => $destination->id,
                'items' => [
                    ['ingredient_id' => $ingA->id, 'quantity' => 10],
                    ['ingredient_id' => $ingB->id, 'quantity' => 5],
                ],
            ])
            ->assertStatus(302);

        $transfer = StockTransfer::query()->sole();

        $this->assertSame(TransferStatus::Draft, $transfer->status);
        $this->assertSame($owner->id, $transfer->created_by);
        $this->assertSame($origin->id, $transfer->origin_branch_id);
        $this->assertSame($destination->id, $transfer->destination_branch_id);

        $this->assertDatabaseHas('stock_transfer_items', [
            'transfer_id' => $transfer->id,
            'ingredient_id' => $ingA->id,
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('stock_transfer_items', [
            'transfer_id' => $transfer->id,
            'ingredient_id' => $ingB->id,
            'quantity' => 5,
        ]);

        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingA->id, 'quantity' => 100]);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'ingredient_id' => $ingA->id, 'quantity' => 0]);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingB->id, 'quantity' => 100]);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'ingredient_id' => $ingB->id, 'quantity' => 0]);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.created',
            'target_type' => StockTransfer::class,
            'target_id' => $transfer->id,
        ]);
    }

    public function test_dispatch_reduces_origin_stock_and_writes_transfer_out_movements(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingA = Ingredient::factory()->create(['name' => 'Tepung']);
        $ingB = Ingredient::factory()->create(['name' => 'Saus']);

        StockBalance::factory()->for($origin)->for($ingA, 'ingredient')->create(['quantity' => 100]);
        StockBalance::factory()->for($origin)->for($ingB, 'ingredient')->create(['quantity' => 50]);

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingA, 'ingredient')->create(['quantity' => 30]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingB, 'ingredient')->create(['quantity' => 10]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/dispatch")
            ->assertStatus(302);

        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingA->id, 'quantity' => 70]);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingB->id, 'quantity' => 40]);

        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::TransferOut->value)->count());

        foreach ([[$ingA, -30], [$ingB, -10]] as [$ingredient, $quantity]) {
            $this->assertDatabaseHas('stock_movements', [
                'branch_id' => $origin->id,
                'ingredient_id' => $ingredient->id,
                'type' => StockMovementType::TransferOut->value,
                'quantity' => $quantity,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transfer->id,
                'actor_id' => $owner->id,
            ]);
        }

        $transfer->refresh();
        $this->assertSame(TransferStatus::Dispatched, $transfer->status);
        $this->assertNotNull($transfer->dispatched_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.dispatched',
            'target_type' => StockTransfer::class,
            'target_id' => $transfer->id,
        ]);
    }

    public function test_dispatch_shortfall_rejects_and_changes_nothing(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Tepung']);

        StockBalance::factory()->for($origin)->for($ingredient, 'ingredient')->create(['quantity' => 20]);

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 30]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/dispatch")
            ->assertSessionHasErrors('stock');

        $this->assertStringContainsString('Tepung', session('errors')->first('stock'));

        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingredient->id, 'quantity' => 20]);
        $this->assertDatabaseCount('stock_movements', 0);

        $transfer->refresh();
        $this->assertSame(TransferStatus::Draft, $transfer->status);
        $this->assertNull($transfer->dispatched_at);
    }

    public function test_second_dispatch_is_rejected(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create(['name' => 'Keju']);

        StockBalance::factory()->for($origin)->for($ingredient, 'ingredient')->create(['quantity' => 100]);

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 40]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/dispatch")
            ->assertStatus(302);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/dispatch")
            ->assertSessionHasErrors('status');

        $this->assertSame(1, StockMovement::query()->where('type', StockMovementType::TransferOut->value)->count());
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $origin->id, 'ingredient_id' => $ingredient->id, 'quantity' => 60]);

        $this->assertSame(TransferStatus::Dispatched, $transfer->fresh()->status);
    }
}
