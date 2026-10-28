<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Actions\RecordStockMovement;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\Ingredient;
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
}
