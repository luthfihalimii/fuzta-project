<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerDevice;
use App\Modules\Customers\Models\CustomerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerDeviceTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    /**
     * @var array<string, string>
     */
    private array $payload = [
        'token' => 'fcm-token-abc',
        'platform' => 'android',
    ];

    public function test_registration_requires_customer_token(): void
    {
        $this->postJson('/api/v1/me/devices', $this->payload)
            ->assertUnauthorized();
    }

    public function test_customer_can_register_device(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $response = $this->postJson('/api/v1/me/devices', $this->payload, $this->headers($token))
            ->assertOk()
            ->assertJsonPath('device.platform', 'android');

        $this->assertArrayNotHasKey('token', $response->json('device'));

        $this->assertDatabaseHas('customer_devices', [
            'customer_id' => $customer->id,
            'token' => 'fcm-token-abc',
            'platform' => 'android',
        ]);

        $this->assertNotNull(CustomerDevice::query()->sole()->last_seen_at);
    }

    public function test_reregistering_same_token_updates_instead_of_duplicating(): void
    {
        [, $token] = $this->customerWithToken();

        $first = $this->postJson('/api/v1/me/devices', $this->payload, $this->headers($token))
            ->assertOk();
        $id = (int) $first->json('device.id');

        $this->travel(5)->minutes();

        $this->postJson('/api/v1/me/devices', array_merge($this->payload, ['platform' => 'ios']), $this->headers($token))
            ->assertOk()
            ->assertJsonPath('device.id', $id)
            ->assertJsonPath('device.platform', 'ios');

        $this->assertSame(1, CustomerDevice::query()->count());
        $this->assertSame('ios', CustomerDevice::query()->sole()->platform);
    }

    public function test_token_is_reassigned_to_the_current_customer(): void
    {
        [, $firstToken] = $this->customerWithToken();
        [$secondCustomer, $secondToken] = $this->customerWithToken();

        $this->postJson('/api/v1/me/devices', $this->payload, $this->headers($firstToken))
            ->assertOk();
        $this->postJson('/api/v1/me/devices', $this->payload, $this->headers($secondToken))
            ->assertOk();

        $this->assertSame(1, CustomerDevice::query()->count());
        $this->assertSame($secondCustomer->id, CustomerDevice::query()->sole()->customer_id);
    }

    public function test_registration_validates_token_and_platform(): void
    {
        [, $token] = $this->customerWithToken();

        $this->postJson('/api/v1/me/devices', ['platform' => 'android'], $this->headers($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token', 'platform' => 'blackberry'], $this->headers($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('platform');

        $this->postJson('/api/v1/me/devices', ['token' => str_repeat('x', 513), 'platform' => 'android'], $this->headers($token))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    }

    public function test_customer_can_delete_own_device(): void
    {
        [$customer, $token] = $this->customerWithToken();
        $device = CustomerDevice::factory()->create(['customer_id' => $customer->id]);

        $this->deleteJson('/api/v1/me/devices/'.$device->id, [], $this->headers($token))
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_devices', ['id' => $device->id]);
    }

    public function test_cannot_delete_other_customers_device(): void
    {
        [, $token] = $this->customerWithToken();
        $foreign = CustomerDevice::factory()->create();

        $this->deleteJson('/api/v1/me/devices/'.$foreign->id, [], $this->headers($token))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_devices', ['id' => $foreign->id]);
    }

    public function test_delete_requires_customer_token(): void
    {
        $device = CustomerDevice::factory()->create();

        $this->deleteJson('/api/v1/me/devices/'.$device->id)
            ->assertUnauthorized();
    }

    /**
     * @return array{0: Customer, 1: string}
     */
    private function customerWithToken(): array
    {
        $this->tokenSeq++;
        $customer = Customer::factory()->create();
        $raw = 'token-'.$this->tokenSeq;

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(30),
        ]);

        return [$customer, $raw];
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
