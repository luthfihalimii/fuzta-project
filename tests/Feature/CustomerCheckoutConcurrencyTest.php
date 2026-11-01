<?php

namespace Tests\Feature;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Actions\CreateCustomerOrder;
use App\Modules\Ordering\Data\CustomerOrderData;
use App\Modules\Ordering\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pola PurchaseConcurrencyTest: dua proses nyata dengan Idempotency-Key sama
 * harus menghasilkan tepat satu order (cek unique constraint + findExisting).
 */
class CustomerCheckoutConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_checkouts_with_same_key_create_one_order(): void
    {
        $branch = Branch::factory()->create(['opens_at' => '00:00', 'closes_at' => '23:59']);
        $product = Product::factory()->create(['weight_grams' => 500, 'base_price' => 50000]);
        $product->branches()->attach($branch, ['is_available' => true]);

        $pids = [];
        $exitCodes = [];

        foreach ([1, 2] as $_) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                try {
                    DB::purge();
                    DB::reconnect();
                    Date::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Jakarta'));

                    app(CreateCustomerOrder::class)(new CustomerOrderData(
                        branchId: $branch->id,
                        fulfillment: 'pickup',
                        customerName: 'Budi',
                        customerPhone: '081234567890',
                        note: null,
                        deliveryAddress: null,
                        deliveryAreaId: null,
                        deliveryFee: 0,
                        courierName: null,
                        courierService: null,
                        items: [['product_id' => $product->id, 'quantity' => 2]],
                        idempotencyKey: 'concurrent-key',
                    ));
                } catch (\Throwable) {
                    exit(1);
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame([0, 0], $exitCodes);
        $this->assertSame(1, Order::where('idempotency_key', 'concurrent-key')->count());
        $this->assertSame(1, Order::first()->items()->count());
        $this->assertSame(100000, Order::first()->total);
    }
}
