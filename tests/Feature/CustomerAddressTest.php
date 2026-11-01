<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    /**
     * Body POST alamat yang valid; `notes` tak dikirim agar default null.
     *
     * @var array<string, mixed>
     */
    private array $payload = [
        'label' => 'Rumah',
        'recipient_name' => 'Ibu',
        'phone' => '081234567890',
        'address' => 'Jl. Kenanga No. 1',
        'area_id' => 'area-001',
    ];

    public function test_first_address_becomes_default_automatically(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('address.is_default', true)
            ->assertJsonPath('address.label', 'Rumah');

        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_first_address_forces_default_even_if_false_requested(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $response = $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['is_default' => false]), ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);

        $id = (int) $response->json('address.id');
        $this->assertDatabaseHas('customer_addresses', ['id' => $id, 'is_default' => true]);
        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_only_one_default_survives_repeated_default_posts(): void
    {
        [, $token] = $this->customerWithToken();
        $customer = Customer::query()->sole();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/me/addresses', $this->payload, $headers)
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);

        $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['label' => 'Kantor', 'is_default' => true]), $headers)
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);

        $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['label' => 'Depo', 'is_default' => true]), $headers)
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);

        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_eleventh_address_is_rejected(): void
    {
        [$customer, $token] = $this->customerWithToken();
        CustomerAddress::factory()->count(10)->create(['customer_id' => $customer->id]);

        $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('addresses');

        $this->assertSame(10, CustomerAddress::query()->where('customer_id', $customer->id)->count());
    }

    public function test_index_orders_default_first_then_oldest(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $oldest = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Terlama',
            'created_at' => now()->subDays(3),
        ]);
        $middle = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Tengah',
            'created_at' => now()->subDay(),
        ]);
        $default = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Default',
            'is_default' => true,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/me/addresses', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('addresses.0.label', 'Default')
            ->assertJsonPath('addresses.0.id', $default->id)
            ->assertJsonPath('addresses.1.label', 'Terlama')
            ->assertJsonPath('addresses.1.id', $oldest->id)
            ->assertJsonPath('addresses.2.label', 'Tengah')
            ->assertJsonPath('addresses.2.id', $middle->id);
    }

    public function test_update_on_other_customer_address_is_not_found(): void
    {
        [, $token] = $this->customerWithToken();
        $foreign = CustomerAddress::factory()->create();

        $this->patchJson('/api/v1/me/addresses/'.$foreign->id, ['label' => 'Curi'], ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();

        // Alamat orang lain tak terentuh.
        $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id, 'label' => 'Rumah']);
    }

    public function test_delete_on_other_customer_address_is_not_found(): void
    {
        [, $token] = $this->customerWithToken();
        $foreign = CustomerAddress::factory()->create();

        $this->deleteJson('/api/v1/me/addresses/'.$foreign->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertNotFound();

        // Alamat orang lain tak terhapus.
        $this->assertDatabaseHas('customer_addresses', ['id' => $foreign->id]);
    }

    public function test_cannot_unset_the_only_default(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $id = CustomerAddress::query()->sole()->id;

        $this->patchJson('/api/v1/me/addresses/'.$id, ['is_default' => false], ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_default');

        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_move_default_via_patch(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['label' => 'Kantor']), ['Authorization' => 'Bearer '.$token])
            ->assertCreated()
            ->assertJsonPath('address.is_default', false);

        $second = CustomerAddress::query()->where('customer_id', $customer->id)->where('label', 'Kantor')->sole()->id;

        $this->patchJson('/api/v1/me/addresses/'.$second, ['is_default' => true], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('address.is_default', true);

        $this->assertSame(1, $this->defaultCount($customer));
        $this->assertDatabaseHas('customer_addresses', ['id' => $second, 'is_default' => true]);
    }

    public function test_partial_update_keeps_other_fields_intact(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $response = $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertCreated();
        $id = (int) $response->json('address.id');

        // Hanya `notes` dikirim: field lain tak boleh berubah atau kena null.
        $this->patchJson('/api/v1/me/addresses/'.$id, ['notes' => 'Gerbang biru'], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('address.notes', 'Gerbang biru');

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $id,
            'label' => 'Rumah',
            'recipient_name' => 'Ibu',
            'phone' => '081234567890',
            'address' => 'Jl. Kenanga No. 1',
            'area_id' => 'area-001',
            'notes' => 'Gerbang biru',
        ]);
    }

    public function test_delete_non_default_keeps_default(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $this->postJson('/api/v1/me/addresses', $this->payload, ['Authorization' => 'Bearer '.$token])
            ->assertCreated();
        $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['label' => 'Kantor']), ['Authorization' => 'Bearer '.$token])
            ->assertCreated();

        $defaultId = CustomerAddress::query()->where('customer_id', $customer->id)->where('is_default', true)->sole()->id;
        $otherId = CustomerAddress::query()->where('customer_id', $customer->id)->where('is_default', false)->sole()->id;

        $this->deleteJson('/api/v1/me/addresses/'.$otherId, [], ['Authorization' => 'Bearer '.$token])
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $otherId]);
        $this->assertDatabaseHas('customer_addresses', ['id' => $defaultId, 'is_default' => true]);
        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_delete_default_promotes_oldest_remaining(): void
    {
        [$customer, $token] = $this->customerWithToken();

        $oldest = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Terlama',
            'created_at' => now()->subDays(2),
        ]);
        CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Tengah',
            'created_at' => now()->subDay(),
        ]);
        $default = CustomerAddress::factory()->create([
            'customer_id' => $customer->id,
            'label' => 'Default',
            'is_default' => true,
            'created_at' => now(),
        ]);

        $this->deleteJson('/api/v1/me/addresses/'.$default->id, [], ['Authorization' => 'Bearer '.$token])
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $default->id]);
        $this->assertDatabaseHas('customer_addresses', ['id' => $oldest->id, 'is_default' => true]);
        $this->assertSame(1, $this->defaultCount($customer));
    }

    public function test_mutations_without_token_are_unauthorized(): void
    {
        $foreign = CustomerAddress::factory()->create();

        $this->patchJson('/api/v1/me/addresses/'.$foreign->id, ['label' => 'X'])
            ->assertUnauthorized();

        $this->deleteJson('/api/v1/me/addresses/'.$foreign->id, [])
            ->assertUnauthorized();
    }

    public function test_index_without_token_is_unauthorized(): void
    {
        $this->getJson('/api/v1/me/addresses')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
    }

    public function test_store_rejects_non_string_phone_with_422(): void
    {
        [, $token] = $this->customerWithToken();

        // Array bukan string: tak boleh jadi 500 "Array to string conversion".
        $this->postJson('/api/v1/me/addresses', array_merge($this->payload, ['phone' => ['x']]), ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    /**
     * Customer aktif + token bearer.
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

    private function defaultCount(Customer $customer): int
    {
        return CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('is_default', true)
            ->count();
    }
}
