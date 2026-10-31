<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Actions\ProcessProductImage;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel 13: Storage::fake hanya mewarisi 'root'/'throw', bukan 'url'
        // dari disk asli; lewatkan base URL yang sama (turunan APP_URL).
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
        config(['images.disk' => 'public']);
    }

    public function test_image_url_is_null_when_product_has_no_image(): void
    {
        $product = Product::factory()->create();

        $this->assertNull($product->imageUrl());
    }

    public function test_image_url_points_to_active_disk(): void
    {
        $product = Product::factory()->create();
        $product->forceFill(['image' => 'products/1/abc.jpg'])->save();

        $base = rtrim((string) config('app.url'), '/');

        $this->assertSame($base.'/storage/products/1/abc.jpg', $product->imageUrl());
    }

    public function test_r2_disk_config_is_present(): void
    {
        $this->assertSame('s3', config('filesystems.disks.r2.driver'));
        $this->assertTrue((bool) config('filesystems.disks.r2.use_path_style_endpoint'));
        $this->assertSame('auto', config('filesystems.disks.r2.region'));
    }

    public function test_owner_can_store_product_with_image_reencoded_jpeg_max_1200(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($owner)->post('/admin/catalog/products', [
            'name' => 'Pizza Fruzta',
            'category_id' => $category->id,
            'base_price' => 50000,
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 900),
        ]);

        $response->assertRedirect();

        $product = Product::query()->firstOrFail();

        $this->assertMatchesRegularExpression('#^products/\d+/\w+\.jpg$#', (string) $product->image);
        $this->assertTrue(Storage::disk('public')->exists((string) $product->image));

        $decoded = imagecreatefromstring((string) Storage::disk('public')->get((string) $product->image));
        $this->assertNotFalse($decoded);
        $this->assertSame(1200, imagesx($decoded));
    }

    public function test_store_rejects_unsupported_image_type(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($owner)->postJson('/admin/catalog/products', [
            'name' => 'Pizza',
            'category_id' => $category->id,
            'base_price' => 30000,
            'image' => UploadedFile::fake()->create('foto.gif', 10),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_store_rejects_file_over_5mb(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($owner)->postJson('/admin/catalog/products', [
            'name' => 'Pizza',
            'category_id' => $category->id,
            'base_price' => 30000,
            'image' => UploadedFile::fake()->create('foto.jpg', 5121),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_replace_image_removes_previous_key(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $this->actingAs($owner)->post('/admin/catalog/products', [
            'name' => 'Pizza',
            'category_id' => $category->id,
            'base_price' => 30000,
            'image' => UploadedFile::fake()->image('lama.jpg', 800, 600),
        ]);

        $product = Product::query()->firstOrFail();
        $previous = (string) $product->image;

        $this->actingAs($owner)->patch("/admin/catalog/products/{$product->id}", [
            'image' => UploadedFile::fake()->image('baru.jpg', 800, 600),
        ])->assertRedirect();

        $product->refresh();

        $this->assertNotSame($previous, (string) $product->image);
        $this->assertFalse(Storage::disk('public')->exists($previous));
        $this->assertTrue(Storage::disk('public')->exists((string) $product->image));
    }

    public function test_remove_image_clears_column_and_disk_key(): void
    {
        $owner = User::factory()->owner()->create();
        $category = Category::factory()->create();

        $this->actingAs($owner)->post('/admin/catalog/products', [
            'name' => 'Pizza',
            'category_id' => $category->id,
            'base_price' => 30000,
            'image' => UploadedFile::fake()->image('foto.jpg', 800, 600),
        ]);

        $product = Product::query()->firstOrFail();
        $key = (string) $product->image;

        $this->actingAs($owner)->patch("/admin/catalog/products/{$product->id}", [
            'remove_image' => true,
        ])->assertRedirect();

        $this->assertNull($product->refresh()->image);
        $this->assertFalse(Storage::disk('public')->exists($key));
    }

    public function test_process_product_image_throws_validation_error_for_undecodable_file(): void
    {
        $garbage = UploadedFile::fake()->create('foto.jpg', 10);

        $this->expectException(ValidationException::class);

        app(ProcessProductImage::class)($garbage);
    }

    public function test_cashier_cannot_store_product_with_image(): void
    {
        $cashier = User::factory()->cashier()->create();
        $category = Category::factory()->create();

        $this->actingAs($cashier)->post('/admin/catalog/products', [
            'name' => 'Pizza',
            'category_id' => $category->id,
            'base_price' => 30000,
            'image' => UploadedFile::fake()->image('foto.jpg', 800, 600),
        ])->assertForbidden();
    }
}
