<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Inventory\Models\Ingredient;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Ordering\Enums\OrderChannel;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailketing.token' => 'test-token',
            'services.mailketing.from_email' => 'noreply@pizzafruzta.test',
            'services.mailketing.from_name' => 'Pizza Fruzta',
            'services.mailketing.base_url' => 'https://api.mailketing.co.id/api/v2',
        ]);

        Http::fake([
            'https://api.mailketing.co.id/*' => Http::response([
                'success' => true,
                'message' => 'Email queued successfully',
            ]),
        ]);

        Cache::flush();
    }

    public function test_low_stock_alert_emails_active_owners_only(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        User::factory()->cashier()->create(['email' => 'kasir@example.com']);
        User::factory()->owner()->inactive()->create(['email' => 'nonaktif@example.com']);

        $branch = Branch::factory()->create(['name' => 'Cabang Pusat']);
        $ingredient = Ingredient::factory()->create([
            'name' => 'Mozzarella',
            'low_stock_threshold' => 100,
        ]);
        StockBalance::factory()->create([
            'branch_id' => $branch->id,
            'ingredient_id' => $ingredient->id,
            'quantity' => 25,
        ]);

        $this->artisan('operations:alert-low-stock')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['recipient'] === 'owner@example.com'
            && str_contains((string) $request['content'], 'Cabang Pusat')
            && str_contains((string) $request['content'], 'Mozzarella')
            && str_contains((string) $request['content'], '25'));

        Http::assertSentCount(1);
    }

    public function test_low_stock_alert_ignores_zero_threshold(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $ingredient = Ingredient::factory()->create(['low_stock_threshold' => 0]);
        StockBalance::factory()->create([
            'branch_id' => Branch::factory(),
            'ingredient_id' => $ingredient->id,
            'quantity' => 0,
        ]);

        $this->artisan('operations:alert-low-stock')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_low_stock_alert_sends_nothing_when_stock_is_healthy(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $ingredient = Ingredient::factory()->create(['low_stock_threshold' => 100]);
        StockBalance::factory()->create([
            'branch_id' => Branch::factory(),
            'ingredient_id' => $ingredient->id,
            'quantity' => 500,
        ]);

        $this->artisan('operations:alert-low-stock')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_failed_delivery_is_alerted_once_per_order(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $order = $this->failedDeliveryOrder();

        $this->artisan('operations:alert-failures')->assertSuccessful();
        $this->artisan('operations:alert-failures')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request['recipient'] === 'owner@example.com'
            && str_contains((string) $request['subject'], 'integrasi')
            && str_contains((string) $request['content'], $order->order_number));

        Http::assertSentCount(1);
    }

    public function test_cancelled_failed_delivery_is_not_alerted(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $this->failedDeliveryOrder(['status' => OrderStatus::Cancelled]);

        $this->artisan('operations:alert-failures')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_failed_queue_job_is_alerted_once(): void
    {
        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $this->failedJob('App\\Modules\\Notifications\\Jobs\\SendOrderStatusPush');

        $this->artisan('operations:alert-failures')->assertSuccessful();
        $this->artisan('operations:alert-failures')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => str_contains(
            (string) $request['content'],
            'SendOrderStatusPush',
        ));

        Http::assertSentCount(1);
    }

    public function test_alerts_are_skipped_when_mailketing_is_not_configured(): void
    {
        config(['services.mailketing.token' => null]);

        User::factory()->owner()->create(['email' => 'owner@example.com']);
        $this->failedDeliveryOrder();

        $this->artisan('operations:alert-failures')->assertSuccessful();

        Http::assertNothingSent();
    }

    private function failedDeliveryOrder(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'channel' => OrderChannel::Delivery,
            'status' => OrderStatus::Ready,
            'payment_status' => PaymentStatus::Paid,
            'delivery_status' => 'failed',
        ], $attributes));
    }

    private function failedJob(string $displayName): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode(['displayName' => $displayName, 'uuid' => (string) Str::uuid()]),
            'exception' => 'RuntimeException: provider down',
            'failed_at' => now(),
        ]);
    }
}
