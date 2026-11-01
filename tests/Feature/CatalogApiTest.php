<?php

namespace Tests\Feature;

use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Modifier;
use App\Modules\Catalog\Models\ModifierGroup;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Bekukan ke 12:00 WIB agar is_open deterministik (pola OrderCreationTest).
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_branches_endpoint_returns_active_branches_with_is_open(): void
    {
        $open = Branch::factory()->create(['is_active' => true, 'opens_at' => '09:00', 'closes_at' => '21:00']);

        Branch::factory()->create(['is_active' => false]);

        $this->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.id', $open->id)
            ->assertJsonPath('branches.0.is_open', true);
    }

    public function test_branches_endpoint_is_open_false_outside_hours_and_closes_at_exclusive(): void
    {
        $lateOpening = Branch::factory()->create(['is_active' => true, 'opens_at' => '14:00', 'closes_at' => '21:00']);
        $closingNow = Branch::factory()->create(['is_active' => true, 'opens_at' => '09:00', 'closes_at' => '12:00']);

        Branch::factory()->create(['is_active' => false]);

        $byId = collect($this->getJson('/api/v1/branches')->assertOk()->json('branches'))->keyBy('id');

        $this->assertCount(2, $byId);
        $this->assertFalse($byId[$lateOpening->id]['is_open']);
        $this->assertFalse($byId[$closingNow->id]['is_open']);
    }

    public function test_products_endpoint_filters_by_branch_availability_and_active(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $available = Product::factory()->create(['weight_grams' => 500]);
        $available->branches()->attach($branchA, ['is_available' => true]);
        $inactive = Product::factory()->create(['is_active' => false]);
        $inactive->branches()->attach($branchA, ['is_available' => true]);
        $other = Product::factory()->create();
        $other->branches()->attach($branchB, ['is_available' => true]);

        $response = $this->getJson("/api/v1/products?branch_id={$branchA->id}")->assertOk();

        $ids = collect($response->json('products'))->pluck('id')->all();
        $this->assertSame([$available->id], $ids);
        $this->assertArrayHasKey('variants', $response->json('products.0'));
        $this->assertArrayHasKey('modifier_groups', $response->json('products.0'));
    }

    public function test_products_endpoint_returns_full_catalog_payload(): void
    {
        $branch = Branch::factory()->create();
        $product = Product::factory()->create(['base_price' => 60000, 'weight_grams' => 500]);
        $product->branches()->attach($branch, ['is_available' => true]);

        $variant = ProductVariant::factory()->for($product)->create(['price' => 10000, 'sort_order' => 0]);
        ProductVariant::factory()->for($product)->create(['price' => 99999, 'is_active' => false, 'sort_order' => 1]);

        $group = ModifierGroup::factory()->create();
        $product->modifierGroups()->attach($group->id, ['sort_order' => 0]);
        $modifier = Modifier::factory()->for($group)->create(['price_delta' => 5000]);
        Modifier::factory()->for($group)->create(['price_delta' => 88888, 'is_active' => false]);

        $json = $this->getJson("/api/v1/products?branch_id={$branch->id}")
            ->assertOk()
            ->json('products.0');

        $this->assertSame($product->id, $json['id']);
        $this->assertSame($product->name, $json['name']);
        $this->assertSame(60000, $json['base_price']);
        $this->assertSame(500, $json['weight_grams']);
        $this->assertSame($product->category->id, $json['category']['id']);
        $this->assertSame($product->category->name, $json['category']['name']);
        $this->assertSame([
            ['id' => $variant->id, 'name' => $variant->name, 'price' => 10000],
        ], $json['variants']);
        $this->assertSame([
            ['id' => $group->id, 'name' => $group->name, 'modifiers' => [
                ['id' => $modifier->id, 'name' => $modifier->name, 'price_delta' => 5000],
            ]],
        ], $json['modifier_groups']);
    }

    public function test_products_endpoint_excludes_product_not_available_at_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $product = Product::factory()->create();
        $product->branches()->attach($branchA, ['is_available' => false]);
        $product->branches()->attach($branchB, ['is_available' => true]);

        $response = $this->getJson("/api/v1/products?branch_id={$branchA->id}")->assertOk();

        $this->assertSame([], collect($response->json('products'))->keys()->all());
    }

    public function test_products_endpoint_rejects_missing_or_inactive_branch(): void
    {
        $inactive = Branch::factory()->create(['is_active' => false]);

        $this->getJson("/api/v1/products?branch_id={$inactive->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');

        $this->getJson('/api/v1/products')
            ->assertStatus(422)
            ->assertJsonValidationErrors('branch_id');
    }

    public function test_catalog_is_public_without_auth_and_allows_cors_origin(): void
    {
        Branch::factory()->create();

        // Tidak ada actingAs: middleware auth/CSRF tidak boleh menolak.
        $this->withHeaders(['Origin' => 'https://www.pizzafruzta.com'])
            ->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'branches')
            ->assertHeader('Access-Control-Allow-Origin', '*');

        // Preflight (OPTIONS) harus dijawab HandleCors sebelum routing.
        $this->call('OPTIONS', '/api/v1/branches', [], [], [], [
            'HTTP_ORIGIN' => 'https://www.pizzafruzta.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])
            ->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }

    public function test_customer_public_limiter_blocks_after_60_requests_per_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/v1/branches')->assertOk();
        }

        $this->getJson('/api/v1/branches')->assertStatus(429);
    }
}
