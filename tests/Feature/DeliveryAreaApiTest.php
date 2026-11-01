<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
