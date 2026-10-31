<?php

namespace Tests\Feature;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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
}
