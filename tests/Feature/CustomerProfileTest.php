<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    public function test_get_me_without_token_is_unauthorized(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
    }

    public function test_get_me_returns_exactly_profile_shape(): void
    {
        [$customer, $token] = $this->customerWithToken();
        CustomerAddress::factory()->count(2)->create(['customer_id' => $customer->id]);

        $response = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $payload = $response->json('customer');

        // Tepat lima kunci: tanpa is_active, password, atau field internal lain.
        $this->assertSame(['id', 'name', 'phone', 'created_at', 'addresses_count'], array_keys($payload));
        $this->assertSame($customer->id, $payload['id']);
        $this->assertSame($customer->name, $payload['name']);
        $this->assertSame($customer->phone, $payload['phone']);
        $this->assertSame(2, $payload['addresses_count']);
        $this->assertNotNull($payload['created_at']);
    }

    public function test_patch_me_updates_name(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $this->patchJson('/api/v1/me', ['name' => 'Budi'], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('customer.name', 'Budi');

        $this->assertSame('Budi', $customer->fresh()->name);
    }

    public function test_patch_me_ignores_other_fields(): void
    {
        [$customer, $token] = $this->customerWithToken();
        $phone = $customer->phone;

        $this->patchJson('/api/v1/me', [
            'name' => 'Budi',
            'phone' => '081200000009',
            'is_active' => false,
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('customer.name', 'Budi')
            ->assertJsonPath('customer.phone', $phone);

        $fresh = $customer->fresh();
        $this->assertSame('Budi', $fresh->name);
        $this->assertSame($phone, $fresh->phone);
        $this->assertTrue($fresh->is_active);
    }

    public function test_patch_me_requires_name(): void
    {
        [, $token] = $this->customerWithToken();

        $this->patchJson('/api/v1/me', ['phone' => '081200000009'], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /**
     * Customer aktif + token bearer (pola insert hash sama dengan
     * CustomerAuthTest), agar tester tak tergantung alur register/login.
     *
     * @return array{0: Customer, 1: string}
     */
    private function customerWithToken(): array
    {
        $this->tokenSeq++;
        $customer = Customer::factory()->create();
        $raw = 'token-'.($this->tokenSeq);

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(30),
        ]);

        return [$customer, $raw];
    }
}
