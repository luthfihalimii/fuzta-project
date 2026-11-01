# Customer API (Katalog + Checkout + DOKU + Biteship) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** REST `/api/v1` untuk customer: lihat cabang+menu, checkout (pickup/delivery), bayar QRIS via DOKU, kirim via Biteship.

**Architecture:** Satu lapis bisnis (action) dipakai ulang dari POS (`PriceOrderItems`, `GenerateOrderNumber`). API controller tipis + FormRequest. Pembayaran online = payment `pending` → webhook DOKU `SUCCESS` → `paid` (order tetap `shift_id` null, tidak mengganggu kas shift). Delivery = quote Biteship saat checkout, shipment dibuat saat order `ready`, status via webhook.

**Tech Stack:** Laravel 13, PHP 8.x, PostgreSQL, PHPUnit, Vue 3 + Inertia (hanya halaman internal), `Illuminate\Support\Facades\Http` (tanpa SDK baru).

**Spec:** `docs/superpowers/specs/2026-11-01-customer-api-design.md` — baca spec sebelum task apa pun.

## Global Constraints

- Uang = integer IDR (`bigInteger`); fee Biteship (bisa float) → `(int) round($value)`; tidak ada `float` di DB/JSON uang.
- Server otoritatif: harga, ongkir, total dihitung ulang server; nilai client diabaikan.
- Idempotency checkout: header `Idempotency-Key`, unik per `(branch_id, idempotency_key)`.
- Tanpa dependency composer/npm baru; provider via `Http` facade (bisa `Http::fake`).
- Pesan error/validasi Bahasa Indonesia; format error = format Laravel default (`message`, `errors`).
- Jangan log nomor HP/alamat/nilai rahasia. Secret dari env saja.
- Waktu bisnis `Asia/Jakarta`; jadwal pakai `->timezone('Asia/Jakarta')` (pola `routes/console.php`).
- Jangan sentuh file dirty milik user (`AGENTS.md`, `bun.lock`, `package.json`, `.gitignore`, `e2e/`, `graphify-out/`, `playwright.config.ts`).
- `docs/` gitignored → `git add -f` untuk file docs. Format dokumen sebelum commit: `bun run check -- --fix <file>`.
- Perintah tes: `php artisan test --filter=<ClassName>`; JS: `bun run test:js`; gate akhir: `CI=true composer ci:check` (cek exit code, jangan pipe).

## Review Focus

1. Double-submit checkout dengan `Idempotency-Key` sama (termasuk konkuren) → tepat 1 order, kedua respons order yang sama, tidak pernah 500. Tes di Task 3.
2. Webhook DOKU replay/terlambat: `SUCCESS` ganda, `FAILED` setelah `SUCCESS`, `SUCCESS` setelah order di-expire → tidak pernah membatalkan paid, tidak menghidupkan order mati. Tes di Task 4.
3. Uang: ongkir Biteship float → integer; `total = subtotal + delivery_fee`; nominal DOKU = `order.total` persis (selisih 1 rupiah pun ditolak). Tes di Task 3 & 4.
4. Provider down saat checkout (Biteship quote / DOKU invoice) → tidak ada order setengah jadi yang menipu customer; retry key sama menyelesaikan/mengembalikan order yang sama. Tes di Task 3 & 4.
5. Keranjang basi: harga berubah, produk nonaktif/tidak tersedia di cabang, cabang tutup di antara browse dan checkout → 422 jelas atau harga server, bukan order dengan data lama. Tes di Task 3.

---

### Task 1: Schema, enum, model, field admin

**Files:**

- Create: `database/migrations/2026_11_01_000001_add_customer_checkout_fields_to_orders.php`, `..._000002_add_provider_fields_to_payments.php`, `..._000003_add_weight_to_products.php`, `..._000004_add_delivery_area_to_branches.php`
- Modify: `app/Modules/Ordering/Enums/OrderChannel.php`, `app/Modules/Payments/Enums/PaymentMethod.php`, `app/Modules/Payments/Enums/PaymentStatus.php`, `app/Modules/Ordering/Models/Order.php`, `app/Modules/Payments/Models/Payment.php`, `app/Modules/Catalog/Models/Product.php`, `app/Modules/Branches/Models/Branch.php`
- Modify: `app/Http/Requests/StoreProductRequest.php`, `app/Http/Requests/UpdateProductRequest.php`, `app/Modules/Branches/Http/Controllers/BranchController.php`, `resources/js/pages/admin/catalog/ProductEdit.vue`, `resources/js/pages/admin/branches/Index.vue`
- Test: `tests/Feature/ApiFoundationTest.php`

**Interfaces:**

- Produces: `OrderChannel::Delivery` (`'delivery'`, label `'Delivery'`); `PaymentMethod::DokuQris` (`'doku_qris'`, label `'QRIS online (DOKU)'`); `PaymentStatus::Pending` (`'pending'`, label `'Menunggu pembayaran'`). Kolom `orders`: `public_token` (uuid, unique), `customer_phone`, `delivery_address`, `delivery_area_id`, `delivery_fee` (int, default 0), `biteship_order_id`, `courier_name`, `courier_service`, `delivery_status`, `tracking_url`; `placed_by_id` nullable. Kolom `payments`: `provider`, `provider_reference`, `payment_url`, `expires_at`. `products.weight_grams` (int nullable). `branches.delivery_area_id` (string nullable).

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/ApiFoundationTest.php` (PHPUnit, `RefreshDatabase`):

```php
public function test_new_enums_have_expected_values(): void
{
    $this->assertSame('delivery', OrderChannel::Delivery->value);
    $this->assertSame('Delivery', OrderChannel::Delivery->label());
    $this->assertSame('doku_qris', PaymentMethod::DokuQris->value);
    $this->assertSame('pending', PaymentStatus::Pending->value);
}

public function test_customer_order_columns_persist(): void
{
    $order = Order::factory()->create([
        'channel' => OrderChannel::Delivery,
        'placed_by_id' => null,
        'public_token' => (string) Str::uuid(),
        'customer_phone' => '081200000001',
        'delivery_address' => 'Jl. Contoh 1',
        'delivery_area_id' => 'IDNP10',
        'delivery_fee' => 15000,
    ])->refresh();

    $this->assertSame(OrderChannel::Delivery, $order->channel);
    $this->assertNull($order->placed_by_id);
    $this->assertSame(15000, $order->delivery_fee);
}

public function test_product_weight_and_branch_delivery_area_persist(): void
{
    $product = Product::factory()->create(['weight_grams' => 750])->refresh();
    $branch = Branch::factory()->create(['delivery_area_id' => 'IDNP10'])->refresh();

    $this->assertSame(750, $product->weight_grams);
    $this->assertSame('IDNP10', $branch->delivery_area_id);
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=ApiFoundationTest` → error kolom/enum belum ada.

- [ ] **Step 3: Implementasi** — tambah 3 case enum (nilai persis di Interfaces), 4 migrasi (kolom persis di Interfaces; `placed_by_id` `nullable()->change()`; index `orders(public_token)` unik), lalu `fillable` + `casts`: `Order` tambah 10 kolom (`delivery_fee` → `integer`), `Payment` tambah 4 (`expires_at` → `datetime`), `Product` `weight_grams` (`integer`), `Branch` `delivery_area_id`. Tambah validasi `'weight_grams' => ['nullable', 'integer', 'between:1,20000']` di `StoreProductRequest` + `UpdateProductRequest`, dan `'delivery_area_id' => ['nullable', 'string', 'max:64']` di validasi `store()` + `update()` `BranchController`. UI: input angka "Berat (gram)" di `ProductEdit.vue` (kirim `weight_grams`), input teks "Area Biteship" di `branches/Index.vue` (kirim `delivery_area_id`).

- [ ] **Step 4: Jalankan, pastikan lulus** — `php artisan test --filter=ApiFoundationTest` → PASS. Lalu `run: bun run typecheck` (Vue) → PASS.

- [ ] **Step 5: Commit** — `git add database/migrations app/Modules app/Http/Requests resources/js/pages/admin tests/Feature/ApiFoundationTest.php && git commit -m "feat(api): schema & enum fondasi customer checkout"`.

---

### Task 2: Scaffolding API + katalog + proxy area Biteship

**Files:**

- Create: `routes/api.php`, `config/cors.php`, `app/Modules/Branches/Support/OpeningHours.php`, `app/Modules/Branches/Http/Controllers/Api/BranchApiController.php`, `app/Modules/Catalog/Http/Controllers/Api/CatalogProductController.php`, `app/Modules/Catalog/Support/CatalogProductPayload.php`, `app/Modules/Delivery/Clients/BiteshipClient.php`, `app/Modules/Delivery/Http/Controllers/DeliveryAreaController.php`
- Modify: `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `config/services.php`
- Test: `tests/Unit/OpeningHoursTest.php`, `tests/Feature/CatalogApiTest.php`, `tests/Feature/DeliveryAreaApiTest.php`

**Interfaces:**

- Produces: `OpeningHours::isOpen(string $opensAt, string $closesAt, ?CarbonInterface $now = null): bool` (string `H:i`, `closes_at` eksklusif, zona `Asia/Jakarta`). `BiteshipClient::areas(string $query): array<int, array{id:string,name:string,city:?string,province:?string,postal_code:?string}>`. Route `GET /api/v1/branches`, `GET /api/v1/products?branch_id=`, `GET /api/v1/delivery/areas?query=`, limiter `customer-public` (60/menit per IP), `config('services.biteship')`.

- [ ] **Step 1: Tulis tes gagal**

`tests/Unit/OpeningHoursTest.php`:

```php
public function test_is_open_boundaries(): void
{
    $at = fn (string $time) => CarbonImmutable::parse($time, 'Asia/Jakarta');
    $this->assertTrue(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 09:00')));
    $this->assertTrue(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 21:00')->subMinute()));
    $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 21:00')));
    $this->assertFalse(OpeningHours::isOpen('09:00', '21:00', $at('2026-11-01 08:59')));
}
```

`tests/Feature/CatalogApiTest.php`:

```php
public function test_branches_endpoint_returns_active_branches_with_is_open(): void
{
    Carbon::setTestNow(Carbon::parse('2026-11-01 12:00', 'Asia/Jakarta'));
    $open = Branch::factory()->create(['is_active' => true, 'opens_at' => '09:00', 'closes_at' => '21:00']);
    Branch::factory()->create(['is_active' => false]);

    $this->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'branches')
        ->assertJsonPath('branches.0.id', $open->id)
        ->assertJsonPath('branches.0.is_open', true);
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

public function test_products_endpoint_rejects_missing_or_inactive_branch(): void
{
    $inactive = Branch::factory()->create(['is_active' => false]);
    $this->getJson("/api/v1/products?branch_id={$inactive->id}")->assertStatus(422);
    $this->getJson('/api/v1/products')->assertStatus(422);
}
```

`tests/Feature/DeliveryAreaApiTest.php`:

```php
public function test_areas_proxies_biteship_search(): void
{
    Http::fake(['api.biteship.com/v1/maps/areas*' => Http::response(['success' => true, 'areas' => [
        ['id' => 'IDNP10', 'name' => 'Kemang', 'administrative_division_level_2_name' => 'Jakarta Selatan', 'postal_code' => '12730'],
    ]])]);

    $this->getJson('/api/v1/delivery/areas?query=kemang')
        ->assertOk()
        ->assertJsonPath('areas.0.id', 'IDNP10');
}

public function test_areas_upstream_error_returns_502(): void
{
    Http::fake(['api.biteship.com/*' => Http::response(['error' => 'down'], 500)]);
    $this->getJson('/api/v1/delivery/areas?query=kemang')->assertStatus(502);
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=CatalogApiTest` dan `--filter=DeliveryAreaApiTest` → 404/kelas belum ada.

- [ ] **Step 3: Implementasi**
    - `bootstrap/app.php`: `api: __DIR__.'/../routes/api.php'` di `withRouting(...)`.
    - `routes/api.php`: `Route::prefix('v1')->group(...)`; rute baca pakai `->middleware('throttle:customer-public')`.
    - `AppServiceProvider::boot()`: `RateLimiter::for('customer-public', fn (Request $r) => Limit::perMinute(60)->by($r->ip()))`.
    - `config/cors.php`: `paths => ['api/*']`, `allowed_origins => explode(',', env('CORS_ALLOWED_ORIGINS', '*'))`, methods `['GET','POST','OPTIONS']`, `supports_credentials => false`.
    - `config/services.php`: `'biteship' => ['api_key' => env('BITESHIP_API_KEY'), 'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com')]`.
    - `OpeningHours::isOpen` — salin aturan `resources/js/lib/opening-hours.ts` ke PHP.
    - `BiteshipClient::areas($query)`: `Http::withToken(config('services.biteship.api_key'))->baseUrl(...)->get('/v1/maps/areas', ['countries' => 'ID', 'input' => $query])`; lempar `RuntimeException` bila gagal; map ke bentuk Interfaces.
    - `DeliveryAreaController::index(Request)` → validasi `query` wajib string min 2; kembalikan `['areas' => ...]`; `catch (RuntimeException)` → `response()->json(['message' => 'Layanan pengiriman sedang tidak tersedia.'], 502)`.
    - `BranchApiController::index()` → `branches` aktif, payload: `id, code, name, address, phone, opens_at, closes_at, is_open, supports_delivery` (`supports_delivery` = `delivery_area_id !== null`).
    - `CatalogProductController::index(Request)` → validasi `branch_id` required + branch aktif (422), muat `category, variants (aktif, urut sort_order), modifierGroups.modifiers (aktif?), branches`; hanya `products.is_active`; hanya yang pivot `is_available` di cabang itu; map via `CatalogProductPayload` (`id, name, description, category{id,name}, base_price, weight_grams, image_url, variants[{id,name,price}], modifier_groups[{id,name,modifiers[{id,name,price_delta}]}]`).
    - `.env.example`: tambah `BITESHIP_API_KEY=`, `BITESHIP_BASE_URL=`, `CORS_ALLOWED_ORIGINS=`.

- [ ] **Step 4: Jalankan, pastikan lulus** — tiga perintah tes di atas → PASS. `php artisan route:list --path=api` menampilkan 3 rute.

- [ ] **Step 5: Commit** — `git add bootstrap/app.php routes/api.php config app/Modules app/Providers tests .env.example && git commit -m "feat(api): katalog publik, jam buka, proxy area Biteship"`.

---

### Task 3: Quote ongkir + create order customer

**Files:**

- Create: `app/Modules/Ordering/Data/CustomerOrderData.php`, `app/Modules/Ordering/Actions/CreateCustomerOrder.php`, `app/Modules/Ordering/Support/CustomerOrderPayload.php`, `app/Modules/Ordering/Http/Controllers/Api/CheckoutController.php`, `app/Modules/Delivery/Actions/QuoteDelivery.php`, `app/Http/Requests/QuoteCustomerOrderRequest.php`, `app/Http/Requests/StoreCustomerOrderRequest.php`
- Modify: `app/Modules/Delivery/Clients/BiteshipClient.php` (tambah `rates()`), `routes/api.php`
- Test: `tests/Feature/CustomerCheckoutTest.php`

**Interfaces:**

- Consumes: `PriceOrderItems::__invoke(int $branchId, array $items): array`, `GenerateOrderNumber` (pola `CreateOrder`), `BiteshipClient::rates()`.
- Produces: `CustomerOrderData(branchId:int, fulfillment:string, customerName:string, customerPhone:string, note:?string, deliveryAddress:?string, deliveryAreaId:?string, deliveryFee:int, courierName:?string, courierService:?string, items:array, idempotencyKey:string)`; `CreateCustomerOrder::__invoke(CustomerOrderData): Order`; `QuoteDelivery::__invoke(int $branchId, string $destinationAreaId, array $items): array<int,array{courier_name:string,courier_service:string,fee:int,etd:?string}>`; `CustomerOrderPayload::__invoke(Order): array`; `POST /api/v1/checkout/quote`, `POST /api/v1/orders` (`throttle:customer-checkout`, 10/menit/IP).

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/CustomerCheckoutTest.php` (helper: `Branch::factory()`, produk+varian+modifier, `Http::fake` rates):

```php
public function test_pickup_quote_returns_zero_fee(): void
{
    $this->postJson('/api/v1/checkout/quote', [
        'branch_id' => Branch::factory()->create()->id,
        'fulfillment' => 'pickup',
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]],
    ])->assertOk()->assertJsonPath('options.0.fee', 0);
}

public function test_delivery_quote_uses_biteship_rates_as_integer(): void
{
    Http::fake(['api.biteship.com/v1/rates' => Http::response(['success' => true, 'pricing' => [
        ['courier_name' => 'Gojek', 'courier_service_name' => 'Instant', 'price' => 12345.6, 'duration' => '1 - 2 hours'],
    ]])]);

    $this->postJson('/api/v1/checkout/quote', [
        'branch_id' => $branch->id, 'fulfillment' => 'delivery', 'destination_area_id' => 'IDNP10',
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
    ])->assertOk()->assertJsonPath('options.0.fee', 12346);
}

public function test_create_pickup_order_snapshots_items_and_returns_token(): void
{
    $response = $this->withHeader('Idempotency-Key', 'key-1')->postJson('/api/v1/orders', [...pickup payload...])
        ->assertCreated()
        ->assertJsonPath('order.channel', 'takeaway')
        ->assertJsonPath('order.placed_by_id', null)
        ->assertJsonPath('order.total', $expectedTotal);

    $order = Order::firstWhere('idempotency_key', 'key-1');
    $this->assertNotNull($order->public_token);
    $this->assertSame($expectedTotal, $order->total);
}

public function test_repeat_same_idempotency_key_returns_same_order(): void
{
    $first = $this->withHeader('Idempotency-Key', 'same')->postJson('/api/v1/orders', $payload)->assertCreated();
    $second = $this->withHeader('Idempotency-Key', 'same')->postJson('/api/v1/orders', $payload)->assertOk();
    $this->assertSame($first->json('order.id'), $second->json('order.id'));
    $this->assertSame(1, Order::count());
}

public function test_delivery_order_re_quotes_on_server_and_ignores_client_fee(): void
{
    // client kirim fee 1; Http::fake rates 20000 → order.delivery_fee = 20000, total = subtotal + 20000
}

public function test_checkout_rejects_closed_branch_inactive_product_and_missing_key(): void
{
    // branch is_active=false → 422; product is_active=false → 422; produk hanya tersedia di cabang lain → 422; tanpa header → 422
}
```

Tambahkan tes konkurensi mengikuti pola `tests/Feature/PurchaseConcurrencyTest.php`: 2 request paralel key sama → 1 order (bila pola itu berbasis proses; kalau tidak, tes `findExisting`-nya cukup).

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=CustomerCheckoutTest` → 404.

- [ ] **Step 3: Implementasi**
    - `CustomerOrderData` readonly class (field persis Interfaces).
    - `CreateCustomerOrder`: guard `branch` aktif + `OpeningHours::isOpen` (422 "Cabang sedang tutup."), cek `findExisting(branchId, key)` sebelum & di dalam transaksi (pola `CreateOrder`, termasuk `catch QueryException` kode `23505` + nama index `orders_branch_id_idempotency_key_unique` → ambil existing), `PriceOrderItems`, `GenerateOrderNumber`, buat `Order` (`placed_by_id => null`, `shift_id => null`, `channel => fulfillment === 'delivery' ? Delivery : Takeaway`, `public_token => (string) Str::uuid()`, `payment_status => Unpaid`, `status => Queued`, `delivery_fee`, `total => subtotal + delivery_fee`, kolom delivery/courier), item snapshot + modifier, `statusEvents()->create([... actor_id => null])`, audit `order.created` actor null.
    - `BiteshipClient::rates(string $originAreaId, string $destinationAreaId, array $items): array` → POST `/v1/rates`, body `origin_area_id`, `destination_area_id`, `couriers` (dari `config('services.biteship.couriers')`, default `'jne,jnt,sicepat,anteraja,gojek,grab,sentral'` — ops final), `items` `[['name','description','value','quantity','weight','height','length','width']]`; error → `RuntimeException`.
    - `QuoteDelivery`: pickup → `[['courier_name' => 'Ambil sendiri', 'courier_service' => 'pickup', 'fee' => 0, 'etd' => null]]` tanpa panggil Biteship; delivery → panggil `rates` (item dari `PriceOrderItems` untuk berat+value), map `courier_name`, `courier_service_name`, `fee` = `(int) round(price)`, `etd` = `duration`; kosong → 422 "Tidak ada kurir untuk alamat ini."
    - `StoreCustomerOrderRequest`: `fulfillment` `['required', Rule::in(['pickup','delivery'])]`, `branch_id` required exists, `customer_name` required max 255, `customer_phone` required regex `^08[0-9]{8,13}$`, `note` nullable max 500, `items` seperti `StoreOrderRequest`, `delivery_area_id`/`delivery_address`/`courier_name`/`courier_service` `required_if:fulfillment,delivery`.
    - `CheckoutController::store`: validasi header `Idempotency-Key` (wajib, max 100) → `QuoteDelivery` ulang untuk delivery + cocokkan `courier_name`+`courier_service` pilihan client (tidak ada → 422), fee dari server; panggil `CreateCustomerOrder`; respons 201/200 `['order' => payload, 'token' => $order->public_token]`. `::quote` → `['options' => ...]`.
    - `CustomerOrderPayload`: `order_number, token, channel, status, payment_status, subtotal, delivery_fee, total, customer_name, customer_phone, note, delivery_address, courier_name, courier_service, delivery_status, tracking_url, items[...], created_at` (tanpa `placed_by_id`/internal id branch selain `branch_id`).
    - Daftarkan rute.

- [ ] **Step 4: Jalankan, pastikan lulus** — `php artisan test --filter=CustomerCheckoutTest` → PASS.

- [ ] **Step 5: Commit** — `git add app routes/api.php tests/Feature/CustomerCheckoutTest.php && git commit -m "feat(api): quote ongkir & checkout order customer"`.

---

### Task 4: Pembayaran DOKU (invoice, webhook, expiry, guard)

**Files:**

- Create: `app/Modules/Payments/Clients/DokuClient.php`, `app/Modules/Payments/Actions/CreateDokuPayment.php`, `app/Modules/Payments/Actions/SettleDokuPayment.php`, `app/Modules/Payments/Http/Controllers/Api/DokuWebhookController.php`, `app/Console/Commands/ExpireUnpaidOrders.php`
- Modify: `app/Modules/Ordering/Http/Controllers/Api/CheckoutController.php`, `app/Modules/Payments/Actions/MarkOrderPaid.php`, `app/Modules/Ordering/Actions/VoidOrder.php`, `config/services.php`, `routes/api.php`, `routes/console.php`, `.env.example`
- Test: `tests/Unit/DokuSignatureTest.php`, `tests/Feature/DokuPaymentTest.php`

**Interfaces:**

- Consumes: `PaymentStatus::Pending`, kolom provider, `OrderPaymentChanged` (ShouldBroadcast).
- Produces: `DokuClient::signature(string $clientId, string $requestId, string $timestamp, string $requestTarget, string $rawBody, string $secretKey): string` → `HMACSHA256=base64`; `DokuClient::createPayment(Order $order): array{payment_url:string, invoice_number:string, expires_at:CarbonImmutable}`; `DokuClient::verifyNotification(Request $request): bool`; `CreateDokuPayment::__invoke(Order $order): Payment`; `SettleDokuPayment::__invoke(string $invoiceNumber, int $amount): void`; command `orders:expire-unpaid`.

- [ ] **Step 1: Tulis tes gagal**

`tests/Unit/DokuSignatureTest.php`:

```php
public function test_signature_matches_documented_algorithm(): void
{
    $raw = '{"order":{"invoice_number":"INV-1","amount":90000}}';
    $digest = base64_encode(hash('sha256', $raw, true));
    $components = "Client-Id:CLIENT\nRequest-Id:req-1\nRequest-Timestamp:2026-11-01T00:00:00Z\n"
        ."Request-Target:/api/v1/webhooks/doku\nDigest:{$digest}";
    $expected = 'HMACSHA256='.base64_encode(hash_hmac('sha256', $components, 'secret', true));

    $this->assertSame($expected, DokuClient::signature('CLIENT', 'req-1', '2026-11-01T00:00:00Z', '/api/v1/webhooks/doku', $raw, 'secret'));
}
```

`tests/Feature/DokuPaymentTest.php` (Http::fake DOKU; helper `$this->dokuNotification($order, 'SUCCESS')` membangun header signature valid dengan `DokuClient::signature` + `config('services.doku')`):

```php
public function test_checkout_creates_pending_doku_payment_with_url(): void
{
    Http::fake(['api-sandbox.doku.com/checkout/v1/payment' => Http::response([
        'response' => ['payment' => ['url' => 'https://sandbox.doku.com/pay/abc']],
    ])]);
    $response = $this->withHeader('Idempotency-Key', 'k-1')->postJson('/api/v1/orders', $payload)->assertCreated();
    $this->assertSame('https://sandbox.doku.com/pay/abc', $response->json('payment_url'));
    $payment = Order::firstWhere('idempotency_key', 'k-1')->payment;
    $this->assertSame(PaymentStatus::Pending, $payment->status);
    $this->assertSame(PaymentMethod::DokuQris, $payment->method);
    $this->assertNull($payment->shift_id);
}

public function test_valid_success_webhook_settles_order_once(): void
{
    Event::fake([OrderPaymentChanged::class]);
    // order + pending payment dengan provider_reference
    $this->withHeaders($this->dokuHeaders($body))->postJson('/api/v1/webhooks/doku', $body)->assertOk();
    $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    $this->assertCount(1, DB::table('audit_logs')->where('action', 'payment.paid')->get());

    $this->withHeaders($this->dokuHeaders($body))->postJson('/api/v1/webhooks/doku', $body)->assertOk();
    $this->assertCount(1, DB::table('audit_logs')->where('action', 'payment.paid')->get()); // replay aman
}

public function test_webhook_rejects_bad_signature_and_amount_mismatch(): void
{
    // signature salah → 403, order unpaid; amount 1 → 422, order unpaid
}

public function test_failed_and_unknown_status_never_mark_paid(): void
{
    // transaction.status FAILED → 200, tetap pending; SUCCESS setelah order cancelled → tetap cancelled
}

public function test_expire_command_cancels_unpaid_past_expiry_only(): void
{
    // unpaid expires_at kemarin → cancelled + status event; paid → tidak berubah
}

public function test_pos_cannot_pay_pending_online_order_and_void_rejects_online_payment(): void
{
    // POST /orders/{id}/pay (kasir) → 422; POST void (owner) → 422
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=DokuSignatureTest`, `--filter=DokuPaymentTest` → kelas/route belum ada.

- [ ] **Step 3: Implementasi**
    - `config/services.php` + `.env.example`: `doku.client_id`, `doku.secret_key`, `doku.base_url` (default `https://api-sandbox.doku.com`), `doku.notification_path` (default `/api/v1/webhooks/doku`), `doku.payment_due_minutes` (30).
    - `DokuClient`: `signature` persis tes; `createPayment` → header `Client-Id`, `Request-Id` (uuid), `Request-Timestamp` (UTC `Y-m-d\TH:i:s\Z`), `Signature`; body `order.amount` = `$order->total`, `order.invoice_number` = `$order->order_number`, `payment.payment_due_date` = menit; response `response.payment.url`; gagal → `RuntimeException`; `expires_at` = now + menit.
    - `CreateDokuPayment`: transaksi + lock order; payment existing `pending` + `payment_url` → kembalikan; existing `paid` → kembalikan; belum ada → panggil client (di luar lock/transaksi DB agar tidak menahan lock saat HTTP), lalu upsert payment (`provider => 'doku'`, `provider_reference`, `payment_url`, `expires_at`, `shift_id => null`, `status => Pending`).
    - `CheckoutController::store`: setelah `CreateCustomerOrder`, `try { $payment = app(CreateDokuPayment::class)($order); }` → `catch (RuntimeException)` → `response()->json(['message' => 'Pembayaran online sedang tidak tersedia. Coba lagi dengan kunci idempotensi yang sama.', 'token' => $order->public_token], 502)`; sukses → respons + `payment_url` + `payment_expires_at`.
    - `DokuWebhookController`: `verifyNotification` gagal → 403; `transaction.status === 'SUCCESS'` → `SettleDokuPayment(invoice_number, (int) amount)`; status lain → 200 tanpa efek; selalu 2xx untuk event valid.
    - `SettleDokuPayment`: lock order via `Order::whereHas('payment', fn ($q) => $q->where('provider_reference', $invoice))`; payment bukan `pending` → return; `amount !== order.total` → throw 422; set payment paid (`paid_at`), order `payment_status => Paid`, `OrderStatusEvent` (from null? gunakan event pembayaran bukan status), audit `payment.paid` (actor null, after `{provider: 'doku', invoice} `), `event(new OrderPaymentChanged(...))` setelah commit (`DB::afterCommit`).
    - `ExpireUnpaidOrders` (`orders:expire-unpaid`): order `placed_by_id = null` + `payment_status = unpaid` + `created_at < now()-30m` + status bukan cancelled → set `cancelled` + status event actor null + audit `order.expired`; daftarkan `Schedule::command('orders:expire-unpaid')->everyTenMinutes()->timezone('Asia/Jakarta')->withoutOverlapping()`.
    - `MarkOrderPaid`: bila `$existing` != null dan statusnya `Pending` → `ValidationException` (`'order' => 'Pesanan menunggu pembayaran online.'`).
    - `VoidOrder`: bila `$locked->payment?->provider !== null` → `ValidationException` (`'order' => 'Pembayaran online tidak dapat di-void. Refund manual lewat dashboard DOKU.'`).

- [ ] **Step 4: Jalankan, pastikan lulus** — dua perintah tes → PASS; `php artisan schedule:list | grep expire` menampilkan jadwal.

- [ ] **Step 5: Commit** — `git add app config routes .env.example tests && git commit -m "feat(payments): DOKU invoice, webhook idempotent, expiry, guard POS/void"`.

---

### Task 5: Biteship fulfillment + status delivered

**Files:**

- Create: `app/Modules/Delivery/Actions/CreateBiteshipShipment.php`, `app/Modules/Delivery/Jobs/CreateShipmentJob.php`, `app/Modules/Delivery/Listeners/QueueShipmentOnReady.php`, `app/Modules/Delivery/Http/Controllers/BiteshipWebhookController.php`, `app/Modules/Delivery/Actions/HandleBiteshipWebhook.php`, `app/Modules/Delivery/Http/Controllers/RetryShipmentController.php`
- Modify: `app/Modules/Delivery/Clients/BiteshipClient.php` (`createOrder`), `app/Modules/Kitchen/Actions/TransitionOrderStatus.php` (`?User $actor`), `app/Modules/Ordering/Support/OrderPayload.php` (field delivery), `app/Providers/AppServiceProvider.php` (event listener), `routes/api.php`, `routes/web.php`, `resources/js/lib/order.ts`, `resources/js/pages/pos/Orders.vue`
- Test: `tests/Feature/BiteshipDeliveryTest.php`

**Interfaces:**

- Consumes: `OrderStatusChanged` (event), `TransitionOrderStatus`, kolom delivery.
- Produces: `BiteshipClient::createOrder(array $payload): array{id:string,courier_name:string,courier_service:string,tracking_url:?string}`; `CreateBiteshipShipment::__invoke(Order $order): void`; `HandleBiteshipWebhook::__invoke(array $payload): void`; rute `POST /api/v1/webhooks/biteship`, `POST /admin/orders/{order}/delivery/retry` (owner).

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/BiteshipDeliveryTest.php`:

```php
public function test_ready_paid_delivery_order_queues_shipment_job(): void
{
    Queue::fake();
    // order delivery paid status Ready? transisi via action dari Accepted→Preparing→Ready (actor kitchen)
    $this->assertPushed(CreateShipmentJob::class, fn ($job) => $job->orderId === $order->id);
}

public function test_pickup_ready_does_not_queue_shipment(): void
{
    Queue::fake();
    // order pickup ready → Queue::assertNotPushed(CreateShipmentJob::class)
}

public function test_job_creates_shipment_and_stores_fields(): void
{
    Http::fake(['api.biteship.com/v1/orders' => Http::response(['success' => true, 'id' => 'BIT-1', 'courier' => [
        'tracking_id' => 'TRK1', 'waybill_id' => 'WB1',
    ], 'status' => 'confirmed'])]);
    (new CreateShipmentJob($order->id))->handle(app(CreateBiteshipShipment::class));
    Http::assertSent(fn ($req) => $req->data()['reference_id'] === $order->order_number
        && $req->data()['delivery_type'] === 'now'
        && $req->data()['destination_area_id'] === $order->delivery_area_id);
    $this->assertSame('BIT-1', $order->fresh()->biteship_order_id);
}

public function test_shipment_failure_marks_failed_without_corrupting_order(): void
{
    Http::fake(['api.biteship.com/v1/orders' => Http::response(['error' => 'x'], 500)]);
    // handle job → delivery_status failed, order tetap paid/ready
}

public function test_webhook_requires_configured_headers_and_delivered_completes_order(): void
{
    // header salah → 403
    // header benar body {status:'delivered'} → delivery_status delivered + order completed + status event actor null
    // tidak ada tambahan stock movement (hitung sebelum/sesudah)
}

public function test_retry_endpoint_owner_only_and_only_when_failed(): void
{
    // owner + failed → job requeued (Queue::assertPushed); kasir → 403; status lain → 422
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=BiteshipDeliveryTest` → kelas belum ada.

- [ ] **Step 3: Implementasi**
    - `BiteshipClient::createOrder(array $payload)`: POST `/v1/orders` dengan `Authorization: Bearer`; error → `RuntimeException`.
    - `CreateBiteshipShipment`: payload `courier_company` (simpan kode perusahaan di `courier_name`? gunakan `courier_name` = `courier_company`, `courier_service` = `courier_type`), `delivery_type => 'now'`, origin dari branch (`name`, `phone`, `address`, `delivery_area_id`), destination (`customer_name`, `customer_phone`, `delivery_address`, `delivery_area_id`), `items` (dari order items: `name`, `value` = line_total, `quantity`, `weight` = produk `weight_grams` fallback 500 × qty), `reference_id` = `order_number`, `order_note` = `note`. Simpan `biteship_order_id`, `tracking_url` (dari response bila ada), `delivery_status => 'requested'`; gagal → update `delivery_status => 'failed'` + `Log::warning` (tanpa PII).
    - `CreateShipmentJob` (`ShouldQueue`, database queue): `public int $orderId`; `handle` → muat order, panggil action.
    - `QueueShipmentOnReady`: listener `OrderStatusChanged`; bila `to === Ready` && `order.channel === Delivery` && `payment_status === Paid` && `delivery_status === null` → `CreateShipmentJob::dispatch($order->id)->afterCommit()`; daftarkan `Event::listen(OrderStatusChanged::class, QueueShipmentOnReady::class)` di `AppServiceProvider::boot`.
    - `TransitionOrderStatus`: `?User $actor`; `actor_id => $actor?->id`; konsumsi bahan tetap hanya `Preparing` (actor selalu ada saat preparing).
    - `BiteshipWebhookController`: header `X-Signature-Key`/`X-Signature-Secret` (nama persis mengikuti dashboard Biteship; simpan di `services.biteship.webhook_key`/`webhook_secret`) → `hash_equals`; gagal 403. `HandleBiteshipWebhook`: cari order by `biteship_order_id`; `status` → `delivery_status`; `delivered` → `TransitionOrderStatus($order, null, OrderStatus::Completed)` bila status belum completed; `waybill_id` → `tracking_url` `https://track.biteship.com/{id}`.
    - `OrderPayload` (POS/kitchen): tambah `delivery_status`, `courier_name`, `courier_service`, `tracking_url`, `delivery_fee` (tanpa phone/alamat). `resources/js/lib/order.ts` tipe ikut; `pos/Orders.vue` badge `Online/QRIS` + `Delivery: {status}`.
    - Retry route di grup `role:owner`: `RetryShipmentController` → order `delivery_status === 'failed'` (else 422), `CreateShipmentJob::dispatch($order->id)`, audit `order.delivery_retried` (aktor owner).

- [ ] **Step 4: Jalankan, pastikan lulus** — `php artisan test --filter=BiteshipDeliveryTest` PASS; `bun run typecheck && bun run test:js` PASS.

- [ ] **Step 5: Commit** — `git add app routes resources/js tests && git commit -m "feat(delivery): shipment Biteship, webhook tracking, delivered→completed"`.

---

### Task 6: Lookup customer, docs API, .env/README, gate

**Files:**

- Create: `app/Modules/Ordering/Http/Controllers/Api/CustomerOrderController.php`, `docs/api.md`
- Modify: `routes/api.php`, `README.md`, `.env.example`
- Test: `tests/Feature/CustomerOrderLookupTest.php`

**Interfaces:**

- Consumes: `CustomerOrderPayload`.
- Produces: `GET /api/v1/orders/{token}` → `{order: CustomerOrderPayload}`; kontrak API lengkap di `docs/api.md`.

- [ ] **Step 1: Tulis tes gagal** — `tests/Feature/CustomerOrderLookupTest.php`:

```php
public function test_lookup_by_public_token_returns_customer_safe_payload(): void
{
    $order = Order::factory()->create(['public_token' => (string) Str::uuid(), 'customer_phone' => '0812']);
    $this->getJson("/api/v1/orders/{$order->public_token}")
        ->assertOk()
        ->assertJsonPath('order.token', $order->public_token)
        ->assertJsonPath('order.customer_phone', '0812');
}

public function test_unknown_token_returns_404(): void
{
    $this->getJson('/api/v1/orders/not-a-real-token')->assertNotFound();
}

public function test_lookup_is_rate_limited(): void
{
    for ($i = 0; $i < 60; $i++) { $this->getJson('/api/v1/orders/x'); }
    $this->getJson('/api/v1/orders/x')->assertStatus(429);
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `php artisan test --filter=CustomerOrderLookupTest` → 404/route belum ada.

- [ ] **Step 3: Implementasi** — route `GET /api/v1/orders/{token}` (`throttle:customer-public`), controller query `Order::where('public_token', $token)->firstOrFail()`, payload `CustomerOrderPayload`. Tulis `docs/api.md` (Indonesia): daftar 8 endpoint, contoh request/response JSON, aturan `Idempotency-Key`, status order/payment/delivery, tangan error (`message`/`errors`), catatan rate limit, alur webhook (bukan untuk frontend), env yang dibutuhkan. Update `README.md` (env DOKU/Biteship/CORS, webhook URL, `php artisan queue:work`, scheduler) dan `.env.example`.

- [ ] **Step 4: Jalankan semua + gate** — `php artisan test --filter=Customer` PASS; `bun run check` PASS; `CI=true composer ci:check` (cek exit code) → 0. Format dokumen: `bun run check -- --fix docs/api.md docs/superpowers/plans/2026-11-01-customer-api.md`.

- [ ] **Step 5: Commit** — `git add -f app routes docs/api.md docs/superpowers/plans/2026-11-01-customer-api.md README.md .env.example tests && git commit -m "feat(api): lookup customer, dokumentasi kontrak API"`.

---

## Self-review (dijalankan penulis plan)

- **Cakupan spec:** katalog (T2), quote+checkout (T3), DOKU (T4), Biteship (T5), lookup+docs (T6), schema/enum/admin (T1), guard POS/void (T4), kitchen unpaid = tidak perlu (sudah ada), laporan channel delivery = otomatis via `tryFrom` (T1 enum). Prasyarat ops = di luar kode.
- **Konsistensi tipe:** `CustomerOrderData` dipakai T3; `payment_url` ditambahkan T4 di respons yang sama; `CreateShipmentJob::$orderId` dipakai T5 listener+retry; `TransitionOrderStatus(?User)` dipakai T5 webhook.
- **Risiko review:** (1) `CreateDokuPayment` memanggil HTTP setelah transaksi order (bukan di dalam), retry key sama harus mencoba ulang invoice; (2) nama header webhook Biteship mengikuti dashboard — implementer verifikasi saat membuat webhook; (3) `couriers` Biteship di config; daftar final ops.
