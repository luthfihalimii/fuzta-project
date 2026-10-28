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
use Illuminate\Support\Facades\DB;
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

    public function test_receive_at_sent_quantity_increments_destination(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                    ['item_id' => $itemB->id, 'received_quantity' => 10, 'discrepancy_reason' => null],
                ],
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'ingredient_id' => $itemA->ingredient_id, 'quantity' => 30]);
        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'ingredient_id' => $itemB->ingredient_id, 'quantity' => 10]);

        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());

        foreach ([[$itemA, 30], [$itemB, 10]] as [$item, $quantity]) {
            $this->assertDatabaseHas('stock_movements', [
                'branch_id' => $destination->id,
                'ingredient_id' => $item->ingredient_id,
                'unit_id' => $item->ingredient->base_unit_id,
                'type' => StockMovementType::TransferIn->value,
                'quantity' => $quantity,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transfer->id,
                'actor_id' => $owner->id,
            ]);
        }

        $transfer->refresh();
        $this->assertSame(TransferStatus::Received, $transfer->status);
        $this->assertNotNull($transfer->received_at);

        $this->assertDatabaseHas('stock_transfer_items', ['id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null]);
        $this->assertDatabaseHas('stock_transfer_items', ['id' => $itemB->id, 'received_quantity' => 10, 'discrepancy_reason' => null]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.received',
            'target_type' => StockTransfer::class,
            'target_id' => $transfer->id,
        ]);
    }

    public function test_receive_shortfall_without_reason_is_rejected(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemB->id, 'received_quantity' => 9, 'discrepancy_reason' => null],
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                ],
            ])
            ->assertSessionHasErrors('received_items.0.discrepancy_reason');

        $this->assertSame(TransferStatus::Dispatched, $transfer->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_receive_shortfall_with_reason_records_discrepancy(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                    ['item_id' => $itemB->id, 'received_quantity' => 9, 'discrepancy_reason' => 'leps hilang 1'],
                ],
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('stock_transfer_items', [
            'id' => $itemB->id,
            'received_quantity' => 9,
            'discrepancy_reason' => 'leps hilang 1',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $destination->id,
            'ingredient_id' => $itemB->ingredient_id,
            'type' => StockMovementType::TransferIn->value,
            'quantity' => 9,
            'reason' => "Penerimaan transfer #{$transfer->id} (selisih: leps hilang 1)",
        ]);

        $row = DB::table('audit_logs')
            ->where('action', 'transfer.received')
            ->where('target_id', $transfer->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertStringContainsString('leps hilang 1', json_encode($row->after));
    }

    public function test_receive_more_than_sent_is_allowed(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                    ['item_id' => $itemB->id, 'received_quantity' => 12, 'discrepancy_reason' => null],
                ],
            ])
            ->assertStatus(302);

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $destination->id,
            'ingredient_id' => $itemB->ingredient_id,
            'type' => StockMovementType::TransferIn->value,
            'quantity' => 12,
        ]);

        $this->assertDatabaseHas('stock_balances', ['branch_id' => $destination->id, 'ingredient_id' => $itemB->ingredient_id, 'quantity' => 12]);
    }

    public function test_receive_all_zero_closes_without_movements(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 0, 'discrepancy_reason' => 'habis di jalan'],
                    ['item_id' => $itemB->id, 'received_quantity' => 0, 'discrepancy_reason' => 'rusak semua'],
                ],
            ])
            ->assertStatus(302);

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());

        $this->assertDatabaseHas('stock_transfer_items', ['id' => $itemA->id, 'received_quantity' => 0, 'discrepancy_reason' => 'habis di jalan']);
        $this->assertDatabaseHas('stock_transfer_items', ['id' => $itemB->id, 'received_quantity' => 0, 'discrepancy_reason' => 'rusak semua']);

        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_receive_rejects_items_from_other_transfer(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $transferA = StockTransfer::factory()->dispatched()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transferA, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 10]);

        $transferB = StockTransfer::factory()->dispatched()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        $itemB = StockTransferItem::factory()->for($transferB, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 5]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transferA->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemB->id, 'received_quantity' => 5, 'discrepancy_reason' => null],
                ],
            ])
            ->assertSessionHasErrors('received_items');

        $this->assertSame(TransferStatus::Dispatched, $transferA->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());
    }

    public function test_receive_rejects_missing_or_duplicate_rows(): void
    {
        [$origin, $destination, $owner, $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                ],
            ])
            ->assertSessionHasErrors('received_items');

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                    ['item_id' => $itemA->id, 'received_quantity' => 10, 'discrepancy_reason' => null],
                ],
            ])
            ->assertSessionHasErrors('received_items.1.item_id');

        $this->assertSame(TransferStatus::Dispatched, $transfer->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());
    }

    public function test_receive_from_wrong_status_is_rejected(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $draft = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        $draftItem = StockTransferItem::factory()->for($draft, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 10]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$draft->id}/receive", [
                'received_items' => [
                    ['item_id' => $draftItem->id, 'received_quantity' => 10, 'discrepancy_reason' => null],
                ],
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(TransferStatus::Draft, $draft->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());

        [, , , $transfer, $itemA, $itemB] = $this->makeDispatchedTransfer();

        $payload = [
            'received_items' => [
                ['item_id' => $itemA->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                ['item_id' => $itemB->id, 'received_quantity' => 10, 'discrepancy_reason' => null],
            ],
        ];

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", $payload)
            ->assertStatus(302);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", $payload)
            ->assertSessionHasErrors('status');

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);
        $this->assertSame(2, StockMovement::query()->where('type', StockMovementType::TransferIn->value)->count());
    }

    public function test_cancel_draft_transfer(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingredient = Ingredient::factory()->create();

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        StockTransferItem::factory()->for($transfer, 'transfer')->for($ingredient, 'ingredient')->create(['quantity' => 10]);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/cancel", ['cancel_reason' => 'salah ketik'])
            ->assertStatus(302);

        $transfer->refresh();
        $this->assertSame(TransferStatus::Cancelled, $transfer->status);
        $this->assertSame('salah ketik', $transfer->cancel_reason);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'transfer.cancelled',
            'target_type' => StockTransfer::class,
            'target_id' => $transfer->id,
        ]);
    }

    public function test_cancel_requires_reason(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();

        $transfer = StockTransfer::factory()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->patchJson("/admin/stock/transfers/{$transfer->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors('cancel_reason');

        $transfer->refresh();
        $this->assertSame(TransferStatus::Draft, $transfer->status);
        $this->assertNull($transfer->cancel_reason);
    }

    public function test_cancel_dispatched_is_rejected(): void
    {
        [, , $owner, $transfer] = $this->makeDispatchedTransfer();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/cancel", ['cancel_reason' => 'berubah pikiran'])
            ->assertSessionHasErrors('status');

        $this->assertSame(TransferStatus::Dispatched, $transfer->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_full_cycle_reconciles_to_ledger(): void
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        $ingA = Ingredient::factory()->create();
        $ingB = Ingredient::factory()->create();

        app(RecordStockMovement::class)($owner, $origin->id, $ingA->id, 100, StockMovementType::Opening, 'Stok awal');
        app(RecordStockMovement::class)($owner, $origin->id, $ingB->id, 50, StockMovementType::Opening, 'Stok awal');

        $this->actingAs($owner)
            ->post('/admin/stock/transfers', [
                'origin_branch_id' => $origin->id,
                'destination_branch_id' => $destination->id,
                'items' => [
                    ['ingredient_id' => $ingA->id, 'quantity' => 30],
                    ['ingredient_id' => $ingB->id, 'quantity' => 10],
                ],
            ])
            ->assertStatus(302);

        $transfer = StockTransfer::query()->sole();

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/dispatch")
            ->assertStatus(302);

        $items = $transfer->items()->orderBy('id')->get();
        $shortItem = $items->firstWhere('ingredient_id', $ingB->id);
        $fullItem = $items->firstWhere('ingredient_id', $ingA->id);

        $this->actingAs($owner)
            ->patch("/admin/stock/transfers/{$transfer->id}/receive", [
                'received_items' => [
                    ['item_id' => $fullItem->id, 'received_quantity' => 30, 'discrepancy_reason' => null],
                    ['item_id' => $shortItem->id, 'received_quantity' => 9, 'discrepancy_reason' => 'leps hilang 1'],
                ],
            ])
            ->assertStatus(302);

        $this->assertSame(TransferStatus::Received, $transfer->fresh()->status);

        $this->artisan('inventory:reconcile')
            ->expectsOutputToContain('tidak ada selisih')
            ->assertExitCode(0);

        $this->assertSame(0, StockBalance::where('quantity', '<', 0)->count());
    }

    /**
     * @return array{Branch, Branch, User, StockTransfer, StockTransferItem, StockTransferItem}
     */
    private function makeDispatchedTransfer(int $quantityA = 30, int $quantityB = 10): array
    {
        $origin = Branch::factory()->create();
        $destination = Branch::factory()->create();
        $owner = User::factory()->owner()->create();
        [$ingA, $ingB] = Ingredient::factory()->count(2)->create();

        $transfer = StockTransfer::factory()->dispatched()->create([
            'origin_branch_id' => $origin->id,
            'destination_branch_id' => $destination->id,
            'created_by' => $owner->id,
        ]);
        $itemA = StockTransferItem::factory()->for($transfer, 'transfer')->for($ingA, 'ingredient')->create(['quantity' => $quantityA]);
        $itemB = StockTransferItem::factory()->for($transfer, 'transfer')->for($ingB, 'ingredient')->create(['quantity' => $quantityB]);

        return [$origin, $destination, $owner, $transfer, $itemA, $itemB];
    }
}
