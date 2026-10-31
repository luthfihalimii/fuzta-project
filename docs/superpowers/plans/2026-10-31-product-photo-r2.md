# Photo Produk + Cloudflare R2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Satu foto per produk (upload owner) yang diproses server-side (GD → JPEG ≤1200px), disimpan di disk yang bisa dikonfigurasikan (lokal `public` untuk dev/test, Cloudflare R2 di VPS), dan tampil di daftar produk admin, form produk, dan kartu POS.

**Architecture:** Kolom `products.image` menyimpan **key** disk (bukan URL); URL diturunkan saat render oleh satu method `Product::imageUrl()`. Pipeline upload = action `ProcessProductImage` (decode EXIF-orientasi, resize, re-encode) + `StoreProductImage` (key + disk). Controller tetap tipis: FileRequest memvalidasi, action mengerjakan.

**Tech Stack:** Laravel (PHP 8.3), GD (sudah ada), Flysystem (sudah ada) + `league/flysystem-aws-s3-v3` (baru, pin `^3.4`), Vue 3 + TypeScript + Inertia.

**Spec:** `docs/superpowers/specs/2026-10-31-product-photo-r2-design.md`

## Global Constraints

- Kolom `products.image` = **key** disk (misal `products/12/abc.jpg`), BUKAN URL. Ganti domain tidak menyentuh data.
- Validasi upload (FormRequest): `['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` (KB). File rusak/tak bisa decode = 422 error di `image`, never 500.
- Re-encode: JPEG, lebar maksimum **1200px**, quality **82**, terapkan EXIF orientation (foto HP). Key: `products/{product_id}/{Str::random(10)}.jpg`.
- Disk aktif via `config('images.disk')` (env `IMAGES_DISK`, default `public`). Kode **selalu** akses lewat `Storage::disk(config('images.disk'))` — satu-satunya titik, di action/helper, tidak tersebar.
- Store: proses file **sebelum** commit; disk gagal / decode gagal = produk TIDAK dibuat (tanpa duplikat saat retry). Update: DB ditulis dulu, key lama dihapus setelah; hapus key lama gagal = orphan, tidak fatal.
- Tanpa R2 nyata dalam tes: semua tes file lewat `Storage::fake('public')` + `config(['images.disk' => 'public'])`.
- R2 config: driver `s3`, `region 'auto'`, `use_path_style_endpoint => true`, env `R2_ACCESS_KEY_ID`/`R2_SECRET_ACCESS_KEY`/`R2_BUCKET`/`R2_ENDPOINT`/`R2_PUBLIC_BASE_URL`.
- CI butuh ekstensi PHP **gd** (workflow `tests.yml`); tanpa itu GD test crash.
- Jalankan `php artisan test` sebelum commit; jangan melemahkan tes yang gagal.

## Review Focus

1. File foto rusak/salah tipe → 422 di `image`, katalog utuh — ini bukan 500. → Tes Task 2.
2. Disk write gagal saat store → produk tidak dibuat (transaksi abort). → Tes Task 2 (gagal process sebelum commit; put disk exception di transaksi).
3. Ganti foto → key lama benar-benar hilang dari disk (tidak menumpuk) — asserted eksplisit. → Tes Task 2.
4. Non-owner (kasir) → 403 di route store/update dengan payload `image` (bukan hanya GET). → Tes Task 2.
5. `image_url` selalu URL disk yang aktif (bukan key mentah di UI); `imageUrl()` null-safe. → Tes Task 1 + asersi prop Task 3.

---

### Task 1: Fondasi — kolom, dependensi, config disk, `imageUrl()`

**Files:**

- Create: `database/migrations/2026_10_31_000001_add_image_to_products_table.php`
- Create: `config/images.php`
- Modify: `composer.json` + `composer.lock` (via `composer require`)
- Modify: `config/filesystems.php`
- Modify: `.env.example`
- Modify: `app/Modules/Catalog/Models/Product.php`
- Test: `tests/Feature/ProductImageTest.php` (create; Task 2 melanjutkan di file yang sama)

**Interfaces:**

- Produces: `Product::imageUrl(): ?string` (URL disk aktif dari key; `null` jika tak ada foto) — dipakai Task 3 di 3 tempat.
- Produces: `config('images.disk')` (`string`, default `'public'`) + disk `r2` di `config('filesystems.disks')`.

- [ ] **Step 1: Tulis tes yang gagal** — `tests/Feature/ProductImageTest.php` baru:

```php
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

        Storage::fake('public');
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
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter ProductImageTest`
Expected: FAIL — `imageUrl()` undefined / kolom `image` tak ada.

- [ ] **Step 3: Implementasi**

**Migrasi** (file create):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
```

**Composer** — jalankan (pin caret sesuai gaya repo; lock file ikut commit):

```bash
composer require league/flysystem-aws-s3-v3:"^3.4"
```

**`config/filesystems.php`** — tambah di dalam `'disks' => [...]`, setelah blok `s3` (style sama persis):

```php
'r2' => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => env('R2_REGION', 'auto'),
    'bucket' => env('R2_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'url' => env('R2_PUBLIC_BASE_URL'),
    'throw' => false,
    'report' => false,
],
```

**`config/images.php`** (file create) — satu key:

```php
<?php

return [

    /*
    | Disk aktif untuk foto produk. 'public' untuk dev/test
    | (tanpa kredensial); 'r2' di VPS: set IMAGES_DISK=r2 + env R2_*.
    |
    */

    'disk' => env('IMAGES_DISK', 'public'),

];
```

**`.env.example`** — setelah blok `FILESYSTEM_DISK`, tambah:

```
IMAGES_DISK=public
# R2 (foto produk di produksi): buat bucket PUBLIC, token API, endpoint https://<ACCOUNT_ID>.r2.cloudflarestorage.com
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET=
R2_ENDPOINT=
R2_PUBLIC_BASE_URL=
```

**`Product` model** — (`app/Modules/Catalog/Models/Product.php`):

- Attribut `#[Fillable([...])]`: tambah `'image'`.
- Import `Illuminate\Support\Facades\Storage`.
- Method baru (satu-satunya titik URL foto):

```php
public function imageUrl(): ?string
{
    if ($this->image === null) {
        return null;
    }

    return Storage::disk(config('images.disk'))->url($this->image);
}
```

- [ ] **Step 4: Jalankan, pastikan lulus**

Run: `php artisan test --filter ProductImageTest`
Expected: PASS (3/3).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_31_000001_add_image_to_products_table.php config/images.php composer.json composer.lock config/filesystems.php .env.example app/Modules/Catalog/Models/Product.php tests/Feature/ProductImageTest.php
git commit -m "feat(catalog): product image column, r2 disk config, image url helper"
```

---

### Task 2: Pipeline upload — action GD, store/update, validasi

**Files:**

- Create: `app/Modules/Catalog/Actions/ProcessProductImage.php`
- Create: `app/Modules/Catalog/Actions/StoreProductImage.php`
- Modify: `app/Http/Requests/StoreProductRequest.php`
- Modify: `app/Http/Requests/UpdateProductRequest.php`
- Modify: `app/Modules/Catalog/Http/Controllers/ProductController.php`
- Modify: `.github/workflows/tests.yml`
- Test: `tests/Feature/ProductImageTest.php` (extend dari Task 1)

**Interfaces:**

- Consumes: `Product` kolom `image` + `Storage::disk(config('images.disk'))` dari Task 1.
- Produces: route `POST /admin/catalog/products` terima `image` (file) opsional; `PATCH /admin/catalog/products/{product}` terima `image` + `remove_image`; kolom `image` terisi key `products/{id}/{rand}.jpg`.

- [ ] **Step 1: Tulis tes yang gagal** — tambahkan di `tests/Feature/ProductImageTest.php` (import `User`, `Category`, `ValidationException`, `App\Modules\Catalog\Actions\ProcessProductImage`, `Illuminate\Http\UploadedFile`):

```php
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

    $response = $this->actingAs($owner)->post('/admin/catalog/products', [
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

    $response = $this->actingAs($owner)->post('/admin/catalog/products', [
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
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter ProductImageTest`
Expected: FAIL — aturan `image` belum ada / kolom tak terisi.

- [ ] **Step 3: Implementasi**

**`ProcessProductImage.php`** (action decode → orientation → resize → JPEG):

```php
<?php

namespace App\Modules\Catalog\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class ProcessProductImage
{
    private const int MAX_WIDTH = 1200;

    private const int JPEG_QUALITY = 82;

    /**
     * Re-encode upload sebagai JPEG (lebar <= 1200px, quality 82),
     * EXIF orientation diterapkan (foto dari HP sering sideways).
     *
     * @throws ValidationException 422 jika file tak bisa didecode
     */
    public function __invoke(UploadedFile $file): string
    {
        $image = @imagecreatefromstring($file->get());

        if ($image === false) {
            throw ValidationException::withMessages([
                'image' => 'File gambar rusak atau format tidak didukung.',
            ]);
        }

        $image = $this->applyExifOrientation($image, $file);

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > self::MAX_WIDTH) {
            $targetHeight = max(1, (int) round($height * self::MAX_WIDTH / $width));

            $resized = imagecreatetruecolor(self::MAX_WIDTH, $targetHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, self::MAX_WIDTH, $targetHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, self::JPEG_QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, UploadedFile $file)
    {
        $exif = @exif_read_data($file->getRealPath());

        if ($exif === false || !isset($exif['Orientation'])) {
            return $image;
        }

        // shortcut: mapping standar 8 orientation EXIF; format non-EXIF tak terpengaruh.
        return match ((int) $exif['Orientation']) {
            8 => imagerotate($image, 270),
            3 => imagerotate($image, 180),
            6 => imagerotate($image, 90),
            2 => imagemirror($image),
            5 => imagerotate(imagemirror($image), 270),
            7 => imagerotate(imagemirror($image), 90),
            4 => imagerotate(imagetranspose($image), 180),
            default => $image,
        };
    }
}
```

**`StoreProductImage.php`** (titik satu-satunya akses disk buat key foto):

```php
<?php

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class StoreProductImage
{
    public function __construct(
        private readonly ProcessProductImage $processImage,
    ) {}

    /**
     * Proses + simpan foto baru; mengembalikan key (misal products/3/a1b2c3d4e5.jpg).
     */
    public function store(UploadedFile $file, Product $product): string
    {
        $bytes = ($this->processImage)($file);

        $key = sprintf('products/%d/%s.jpg', $product->id, Str::random(10));

        Storage::disk(config('images.disk'))->put($key, $bytes);

        return $key;
    }

    /**
     * Hapus key lama dari disk; gagal = orphan (tidak fatal).
     */
    public function forget(?string $key): void
    {
        if ($key === null) {
            return;
        }

        try {
            Storage::disk(config('images.disk'))->delete($key);
        } catch (Throwable) {
            // orphan key; tidak merusak data — disk log sudah mencatat.
        }
    }
}
```

**`StoreProductRequest`** — di `rules()`, setelah `'is_active'`:

```php
'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
```

**`UpdateProductRequest`** — di `rules()`, setelah `'is_active'`:

```php
'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
'remove_image' => ['sometimes', 'boolean'],
```

**`ProductController`**:

- Import `App\Modules\Catalog\Actions\StoreProductImage`.
- `store()`: tambah parameter `StoreProductImage $images`. Di dalam `DB::transaction`, tepat setelah blok `$this->syncBranchAvailability(...)` dan sebelum `$product->load(...)`:

```php
if (($file = $request->file('image')) !== null) {
    $product->image = $images->store($file, $product);
    $product->save();
}
```

- `update()`: tambah parameter `StoreProductImage $images`. Di dalam transaction, tepat sebelum `$product->fill(Arr::only([...]))`:

```php
$previousImage = $product->image;
$file = $request->file('image');
```

Tepat setelah `$product->save()` yang sudah ada (fill eksisting):

```php
if ($file !== null) {
    $product->image = $images->store($file, $product);
    $product->save();
    $images->forget($previousImage);
}

if ($file === null && $request->boolean('remove_image')) {
    $product->image = null;
    $product->save();
    $images->forget($previousImage);
}
```

(Urutan: proses/put baru → tulis kolom → save → hapus key lama. Gagal di `store()`/`put` = exception sebelum save → foto lama utuh; hapus key lama gagal = catch di `forget`. Kalau `image` + `remove_image` dikirim bareng: `image` menang.)

- Audit snapshot otomatis ikut `image_url` setelah Task 3 (`productData()` ditambah `image_url` di sana).

**`.github/workflows/tests.yml`** — baris `extensions:`:

```yaml
extensions: pdo_pgsql, mbstring, xml, curl, zip, gd
```

- [ ] **Step 4: Jalankan, pastikan lulus**

Run: `php artisan test --filter ProductImageTest`
Expected: PASS semua (10 test). Lalu sekali: `php artisan test` (full suite) harus tetap hijau.

- [ ] **Step 5: Commit**

Satu commit untuk semua file step ini:

```bash
git add app/Modules/Catalog/Actions/ProcessProductImage.php app/Modules/Catalog/Actions/StoreProductImage.php app/Http/Requests/StoreProductRequest.php app/Http/Requests/UpdateProductRequest.php app/Modules/Catalog/Http/Controllers/ProductController.php .github/workflows/tests.yml tests/Feature/ProductImageTest.php
git commit -m "feat(catalog): product photo upload pipeline (gd resize, disk key, replace/remove)"
```

---

### Task 3: Permukaan URL + UI + dokumen

**Files:**

- Modify: `app/Modules/Catalog/Http/Controllers/ProductController.php` (index + `productData`)
- Modify: `app/Modules/Ordering/Http/Controllers/PosController.php`
- Modify: `resources/js/pages/admin/catalog/Products.vue`
- Modify: `resources/js/pages/admin/catalog/ProductEdit.vue`
- Modify: `resources/js/pages/pos/Index.vue`
- Modify: `tests/Feature/CatalogTest.php`, `tests/Feature/OrderCreationTest.php`
- Modify: `PRD.md`, `README.md`, `docs/operations/deployment.md`

**Interfaces:**

- Consumes: `Product::imageUrl(): ?string` dari Task 1.
- Produces: prop `image_url: string | null` di payload `products` (admin index, `productData` untuk edit, POS) — TS di 3 halaman mengikuti.

- [ ] **Step 1: Perluas tes (gagal dulu)**

`tests/Feature/CatalogTest.php`, pada test yang assert halaman products (cari assert Inertia komponen `admin/catalog/Products`) + test edit (`/admin/catalog/products/.../edit`) — fixture di kedua tes itu tak punya foto, jadi kunci nilainya:

```php
->where('products.0.image_url', null)
```

dan di blok edit:

```php
->where('product.image_url', null)
```

`tests/Feature/OrderCreationTest.php`, di `->get('/pos')` (test page): tambah di assert Inertia yang sudah ada:

```php
->where('products.0.image_url', null)
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter "CatalogTest|OrderCreationTest"`
Expected: FAIL — prop `image_url` belum dikirim controller.

- [ ] **Step 3: PHP props**

- `ProductController::index` mapping produk: tambah `'image_url' => $product->imageUrl(),`.
- `ProductController::productData()`: tambah `'image_url' => $product->imageUrl(),`.
- `PosController` mapping produk (baris `'category_name' => ...` area itu): tambah `'image_url' => $product->imageUrl(),`.

- [ ] **Step 4: UI admin — `Products.vue`**

- Tipe `ProductRow`: tambah `image_url: string | null;`.
- Sel nama (`<td class="px-5 py-4 font-medium text-ink">{{ product.name }}</td>`) diganti:

```vue
<td class="px-5 py-4">
    <div class="flex items-center gap-3">
        <span
            v-if="product.image_url"
            class="h-10 w-10 shrink-0 overflow-hidden rounded-lg border border-line"
        >
            <img
                :src="product.image_url"
                :alt="product.name"
                loading="lazy"
                class="h-full w-full object-cover"
            />
        </span>
        <span
            v-else
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-canvas text-sm font-semibold text-muted"
        >
            {{ product.name.charAt(0).toUpperCase() }}
        </span>
        <span class="font-medium text-ink">{{ product.name }}</span>
    </div>
</td>
```

- [ ] **Step 5: UI form — `ProductEdit.vue`**

- Tipe `ProductData`: tambah `image_url: string | null;`.
- Setelah `form` dibuat, tambah state + handler (dekat `errors`):

```ts
const previewUrl = ref<string | null>(null);

function pickImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    form.image = file ?? null;

    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    previewUrl.value = file ? URL.createObjectURL(file) : null;

    if (file) {
        form.remove_image = false;
    }
}

function hideImage(): void {
    form.remove_image = true;
    form.image = null;
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
    previewUrl.value = null;
}
```

- `useForm`: tambah field `image: null as File | null` dan `remove_image: false`; **ubah** `submit()`: `form.patch('/admin/catalog/products/...', { preserveScroll: true, multipart: true })` dan `form.post(..., { multipart: true })` (coba blok submit yang ada; tambah opsi `multipart: true`).
- Di section "Informasi produk", setelah blok input nama (sebelum deskripsi boleh), tambah blok foto:

```vue
<div>
    <span class="mb-1.5 block text-sm font-medium text-ink">
        Foto produk (opsional)
    </span>
    <div class="flex items-start gap-4">
        <span
            class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-canvas text-xs text-muted"
        >
            <img
                v-if="previewUrl"
                :src="previewUrl"
                alt="Pratinjau foto"
                class="h-full w-full object-cover"
            />
            <img
                v-else-if="!form.remove_image && props.product?.image_url"
                :src="props.product.image_url"
                alt="Foto produk"
                class="h-full w-full object-cover"
            />
            <span v-else>
                {{ props.product?.name ? props.product.name.charAt(0).toUpperCase() : '—' }}
            </span>
        </span>
        <div class="space-y-2">
            <label
                class="inline-block cursor-pointer rounded-lg border border-line bg-white px-3 py-2 text-sm font-medium text-ink hover:border-brand/40"
            >
                {{ props.product?.image_url && !form.remove_image && !previewUrl ? 'Ganti foto' : 'Pilih foto' }}
                <input
                    type="file"
                    accept=".jpg,.jpeg,.png,.webp"
                    class="hidden"
                    @change="pickImage"
                />
            </label>
            <p class="text-xs text-muted">JPG, PNG, atau WebP; maksimal 5 MB.</p>
            <Button
                v-if="props.product?.image_url"
                type="button"
                variant="danger"
                @click="hideImage"
            >
                Hapus foto
            </Button>
            <p v-if="form.errors.image" class="text-sm text-danger">
                {{ form.errors.image }}
            </p>
        </div>
    </div>
</div>
```

(Cek prop `variant` di `Button.vue`; bila tak punya `danger`, pakai class `class="text-danger"` di Button atau tombol label biasa — sesuaikan pola Button yang ada, jangan bikin varian baru.)

- Import `ref` sudah ada; pastikan `pickImage`/`hideImage` dipakai (tidak dead code).

- [ ] **Step 6: UI POS — `pos/Index.vue`**

- Tipe produk POS (cari type produk yang punya `category_name: string | null`): tambah `image_url: string | null;`.
- Di kartu produk (`v-for="product in filteredProducts"`, `<button ... class="flex min-h-32 ...">`), sebelum `<span class="text-sm font-semibold text-ink">`, sisipkan:

```vue
<img
    v-if="product.image_url"
    :src="product.image_url"
    :alt="product.name"
    loading="lazy"
    class="mb-2 h-20 w-full rounded-lg object-cover"
/>
<span
    v-else
    class="mb-2 flex h-20 w-full items-center justify-center rounded-lg bg-canvas text-[11px] text-muted"
>
    Tanpa foto
</span>
```

- [ ] **Step 7: Verifikasi**

Run: `php artisan test --filter "CatalogTest|OrderCreationTest|ProductImageTest" && $HOME/.bun/bin/bun run typecheck`
Expected: PASS semua + typecheck bersih.

- [ ] **Step 8: Dokumen**

- `PRD.md`: artikel bagian catalog P0 (baris "Admin can manage categories, products, descriptions, images, and availability"). Setelah daftar di bagian itu (gaya §7.9 purchasing), sisipkan:

```
Implemented in the current release: one photo per product, uploaded by the owner, re-encoded server-side as JPEG (max width 1200px) and stored on a configurable disk (`IMAGES_DISK`; Cloudflare R2 in production, local `public` disk in development), with the product/table listing, product form, and POS card all deriving display URLs from the stored disk key. Multi-photo, per-variant photos, and custom photo domains remain out of scope. Detail: `docs/superpowers/specs/2026-10-31-product-photo-r2-design.md`.
```

- `README.md`: seksi baru `## Product photos` (pendek, gaya seksi lain): di mana upload (admin katalog), env disk (`IMAGES_DISK`; default `public`), checklist R2 produksi buat owner (buat bucket **public** di Cloudflare, API token R2 = Access Key ID + Secret, Account ID untuk endpoint `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, catat `pub-xxx.r2.dev` ke `R2_PUBLIC_BASE_URL`), catatan: HEIC (foto asli iPhone) tidak didukung — kirim via WA/TG dulu, auto-jadi JPG.
- `docs/operations/deployment.md`: (a) daftar paket PHP — tambah `php8.3-gd`; (b) php.ini FPM — `upload_max_filesize = 8M` + `post_max_size = 8M`; (c) env VPS baru: `IMAGES_DISK=r2`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_PUBLIC_BASE_URL`.

- [ ] **Step 9: Commit**

```bash
git add resources/js/pages/admin/catalog/Products.vue resources/js/pages/admin/catalog/ProductEdit.vue resources/js/pages/pos/Index.vue app/Modules/Catalog/Http/Controllers/ProductController.php app/Modules/Ordering/Http/Controllers/PosController.php tests/Feature/CatalogTest.php tests/Feature/OrderCreationTest.php PRD.md README.md docs/operations/deployment.md
git commit -m "feat(catalog): product photo surfaces (list, form, POS) + docs"
```
