<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerToken;
use App\Modules\Customers\Support\PhoneNumber;
use App\Modules\Ordering\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomerFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_phone_unique_password_hashed_and_default_active(): void
    {
        $customer = Customer::factory()->create(['phone' => '081200000001']);

        $this->assertTrue(Hash::check('password', $customer->password));
        $this->assertNotSame('password', DB::table('customers')->value('password'));
        $this->assertTrue($customer->is_active);

        $this->expectException(QueryException::class);

        Customer::factory()->create(['phone' => '081200000001']);
    }

    public function test_one_default_address_per_customer(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        // Default boleh di customer berbeda.
        CustomerAddress::factory()->for($customer)->create(['is_default' => true]);
        CustomerAddress::factory()->for($other)->create(['is_default' => true]);

        $this->expectException(QueryException::class);

        CustomerAddress::factory()->for($customer)->create(['is_default' => true]);
    }

    public function test_order_belongs_to_customer_and_guest_orders_have_null_customer_id(): void
    {
        $customer = Customer::factory()->create();

        $linked = Order::factory()->create(['customer_id' => $customer->id]);
        $guest = Order::factory()->create();

        $this->assertTrue($linked->customer->is($customer));
        $this->assertNull($guest->customer_id);
    }

    public function test_phone_number_normalization(): void
    {
        $cases = [
            '+62 812-3456-7890' => '081234567890',
            '6281234567890' => '081234567890',
            '081234567890' => '081234567890',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, PhoneNumber::normalize($input), "input {$input}");
        }
    }

    public function test_customer_auth_middleware_guest_and_invalid_token(): void
    {
        $this->registerProbeRoute();

        // Tanpa header: mode optional lanjut sebagai guest.
        $this->getJson('/api/v1/_probe')
            ->assertOk()
            ->assertJson(['mode' => 'guest']);

        // Header ada tapi token ngawur: 401 walau mode optional.
        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer ngawur'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);

        // Header ada tapi rusak (tanpa token): 401, bukan guest.
        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
    }

    public function test_customer_auth_middleware_default_mode_required(): void
    {
        Route::middleware('customer.auth')->get('/api/v1/_probe_required', fn (Request $request) => response()->json([
            'mode' => $request->attributes->has('customer') ? 'customer' : 'guest',
        ]));

        // Tanpa parameter mode: default required, tanpa header → 401.
        $this->getJson('/api/v1/_probe_required')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);

        CustomerToken::create([
            'customer_id' => Customer::factory()->create()->id,
            'token_hash' => hash('sha256', 'token-required'),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/v1/_probe_required', ['Authorization' => 'Bearer token-required'])
            ->assertOk()
            ->assertJson(['mode' => 'customer']);
    }

    public function test_customer_auth_middleware_valid_expired_and_inactive_tokens(): void
    {
        $this->registerProbeRoute();

        $customer = Customer::factory()->create();

        CustomerToken::create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', 'token-valid'),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer token-valid'])
            ->assertOk()
            ->assertJson(['mode' => 'customer']);

        $this->assertNotNull(CustomerToken::query()->first()->last_used_at);

        // Throttle tulis last_used_at (1x/jam): masih baru (10 menit) → tidak ditulis ulang.
        $token = CustomerToken::query()->first();
        $recent = now()->subMinutes(10)->startOfSecond();
        $token->forceFill(['last_used_at' => $recent])->save();

        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer token-valid'])->assertOk();

        $this->assertTrue(CustomerToken::query()->first()->last_used_at->eq($recent));

        // Lebih dari 1 jam (2 jam) → diupdate.
        $old = now()->subHours(2)->startOfSecond();
        $token->forceFill(['last_used_at' => $old])->save();

        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer token-valid'])->assertOk();

        $this->assertTrue(CustomerToken::query()->first()->last_used_at->gt($old));

        CustomerToken::create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', 'token-expired'),
            'expires_at' => now()->subHour(),
        ]);

        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer token-expired'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);

        $inactive = Customer::factory()->create(['is_active' => false]);

        CustomerToken::create([
            'customer_id' => $inactive->id,
            'token_hash' => hash('sha256', 'token-inactive'),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/v1/_probe', ['Authorization' => 'Bearer token-inactive'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
    }

    public function test_cors_preflight_allows_patch_and_delete(): void
    {
        config(['cors.allowed_origins' => ['https://www.pizzafruzta.com']]);

        $response = $this->call('OPTIONS', '/api/v1/me', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.pizzafruzta.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        ]);

        $response->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.pizzafruzta.com')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    private function registerProbeRoute(): void
    {
        Route::middleware('customer.auth:optional')->get('/api/v1/_probe', fn (Request $request) => response()->json([
            'mode' => $request->attributes->has('customer') ? 'customer' : 'guest',
        ]));
    }
}
