<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeliveryAreaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_areas_proxies_biteship_search(): void
    {
        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
            ['id' => 'IDNP10', 'name' => 'Kemang', 'administrative_division_level_2_name' => 'Jakarta Selatan', 'postal_code' => '12730'],
        ]])]);

        $this->getJson('/api/v1/delivery/areas?query=kemang')
            ->assertOk()
            ->assertJsonPath('areas.0.id', 'IDNP10');
    }

    public function test_areas_maps_division_fields_to_city_and_province(): void
    {
        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
            ['id' => 'IDNP11', 'name' => 'Gading', 'administrative_division_level_1_name' => 'DKI Jakarta', 'administrative_division_level_2_name' => 'Jakarta Utara', 'postal_code' => '14210'],
        ]])]);

        $json = $this->getJson('/api/v1/delivery/areas?query=gading')->assertOk();

        $this->assertSame(
            ['id' => 'IDNP11', 'name' => 'Gading', 'city' => 'Jakarta Utara', 'province' => 'DKI Jakarta', 'postal_code' => '14210'],
            $json->json('areas.0'),
        );
    }

    public function test_areas_upstream_error_returns_502(): void
    {
        Http::fake(['api.biteship.com/*' => Http::response(['error' => 'down'], 500)]);

        $this->getJson('/api/v1/delivery/areas?query=kemang')->assertStatus(502);
    }

    public function test_areas_connection_failure_returns_502(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->getJson('/api/v1/delivery/areas?query=kemang')
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Layanan pengiriman sedang tidak tersedia.']);
    }

    public function test_areas_request_sends_bearer_key_and_query_params(): void
    {
        config(['services.biteship.api_key' => 'test-biteship-key']);

        Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => []])]);

        $this->getJson('/api/v1/delivery/areas?query=bandung')
            ->assertOk()
            ->assertJsonPath('areas', []);

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Bearer test-biteship-key'
            && str_contains($request->url(), 'https://api.biteship.com/v1/maps/areas')
            && str_contains($request->url(), 'countries=ID')
            && str_contains($request->url(), 'input=bandung'));
    }

    public function test_areas_requires_query_min_2(): void
    {
        $this->getJson('/api/v1/delivery/areas')
            ->assertStatus(422)
            ->assertJsonValidationErrors('query');

        $this->getJson('/api/v1/delivery/areas?query=k')
            ->assertStatus(422)
            ->assertJsonValidationErrors('query');
    }
}
