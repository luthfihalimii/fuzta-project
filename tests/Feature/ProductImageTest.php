<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Actions\ProcessProductImage;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use GdImage;
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

    public function test_apply_orientation_matches_exif_canonical_transforms(): void
    {
        $action = app(ProcessProductImage::class);

        // Setiap baris: orientation => [ [lebar, tinggi] hasil, [TL, TR, BL, BR] warna pojok ]
        $cases = [
            1 => [[4, 2], [[255, 0, 0], [255, 0, 255], [0, 255, 255], [0, 128, 128]]],
            2 => [[4, 2], [[255, 0, 255], [255, 0, 0], [0, 128, 128], [0, 255, 255]]],
            3 => [[4, 2], [[0, 128, 128], [0, 255, 255], [255, 0, 255], [255, 0, 0]]],
            4 => [[4, 2], [[0, 255, 255], [0, 128, 128], [255, 0, 0], [255, 0, 255]]],
            5 => [[2, 4], [[255, 0, 0], [0, 255, 255], [255, 0, 255], [0, 128, 128]]],
            6 => [[2, 4], [[0, 255, 255], [255, 0, 0], [0, 128, 128], [255, 0, 255]]],
            7 => [[2, 4], [[0, 128, 128], [255, 0, 255], [0, 255, 255], [255, 0, 0]]],
            8 => [[2, 4], [[255, 0, 255], [0, 128, 128], [255, 0, 0], [0, 255, 255]]],
        ];

        foreach ($cases as $orientation => [$dims, $corners]) {
            $result = $action->applyOrientation($this->orientationFixture(), $orientation);

            $this->assertSame($dims[0], imagesx($result), "orientation {$orientation}: lebar");
            $this->assertSame($dims[1], imagesy($result), "orientation {$orientation}: tinggi");
            $this->assertSame($corners, $this->cornerColors($result), "orientation {$orientation}: sudut TL/TR/BL/BR");

            imagedestroy($result);
        }
    }

    public function test_apply_orientation_returns_image_unchanged_when_no_exif(): void
    {
        $action = app(ProcessProductImage::class);

        $result = $action->applyOrientation($this->orientationFixture(), 6, hasExif: false);

        $this->assertSame(4, imagesx($result));
        $this->assertSame(2, imagesy($result));
        $this->assertSame([[255, 0, 0], [255, 0, 255], [0, 255, 255], [0, 128, 128]], $this->cornerColors($result));

        imagedestroy($result);
    }

    public function test_transparent_png_is_flattened_on_white(): void
    {
        $img = imagecreatetruecolor(2, 2);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, 1, 1, $transparent);

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        $tmp = tempnam(sys_get_temp_dir(), 'gd');
        file_put_contents($tmp, $png);
        try {
            $bytes = app(ProcessProductImage::class)(new UploadedFile($tmp, 'foto.png', 'image/png', null, true));
        } finally {
            @unlink($tmp);
        }

        $this->assertNotSame('', $bytes);

        $decoded = imagecreatefromstring($bytes);
        $this->assertNotFalse($decoded);
        $c = imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0));
        $this->assertSame(255, $c['red']);
        $this->assertSame(255, $c['green']);
        $this->assertSame(255, $c['blue']);

        imagedestroy($decoded);
    }

    /**
     * Fixture 4x2: 4 warna pojok + 4 warna dalam, semua berbeda,
     * jadi transformasi yang salah pasti berbeda pada sudutnya.
     */
    private function orientationFixture(): GdImage
    {
        $image = imagecreatetruecolor(4, 2);
        $pixels = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 0, 255], [0, 255, 255], [255, 255, 0], [128, 0, 0], [0, 128, 128]];

        foreach ($pixels as $i => [$r, $g, $b]) {
            imagesetpixel($image, $i % 4, intdiv($i, 4), imagecolorallocate($image, $r, $g, $b));
        }

        return $image;
    }

    /**
     * @return array<array{int, int, int}> warna [TL, TR, BL, BR]
     */
    private function cornerColors(GdImage $image): array
    {
        $at = function (int $x, int $y) use ($image): array {
            $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

            return [$c['red'], $c['green'], $c['blue']];
        };

        $w = imagesx($image);
        $h = imagesy($image);

        return [
            $at(0, 0),
            $at($w - 1, 0),
            $at(0, $h - 1),
            $at($w - 1, $h - 1),
        ];
    }
}
