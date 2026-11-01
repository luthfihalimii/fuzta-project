<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerToken;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CustomerTokenPruneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan jam (pola CatalogApiTest/CustomerCheckoutTest) agar
        // batas "tepat jatuh tempo" deterministik.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 03:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_prune_deletes_only_expired_tokens_and_reports_count(): void
    {
        $customer = Customer::factory()->create();

        $expiredId = $this->createToken($customer, 'token-kedaluwarsa', now()->subDay());
        $borderId = $this->createToken($customer, 'token-tepat-jatuh-tempo', now());
        $validId = $this->createToken($customer, 'token-aktif', now()->addDay());

        $status = Artisan::call('customers:prune-tokens');

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Terhapus 2 token kedaluwarsa.', Artisan::output());
        $this->assertFalse(CustomerToken::query()->whereKey($expiredId)->exists());
        // expires_at == now() sudah tidak bisa dipakai (middleware butuh > now()).
        $this->assertFalse(CustomerToken::query()->whereKey($borderId)->exists());
        $this->assertTrue(CustomerToken::query()->whereKey($validId)->exists());
    }

    public function test_prune_without_expired_tokens_is_successful_noop(): void
    {
        $customer = Customer::factory()->create();
        $validId = $this->createToken($customer, 'token-aktif', now()->addDays(30));

        $status = Artisan::call('customers:prune-tokens');

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Terhapus 0 token kedaluwarsa.', Artisan::output());
        $this->assertTrue(CustomerToken::query()->whereKey($validId)->exists());
    }

    private function createToken(Customer $customer, string $raw, CarbonImmutable $expiresAt): int
    {
        return (int) CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => $expiresAt,
        ])->id;
    }
}
