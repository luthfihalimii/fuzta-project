# Sales by Channel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah breakdown penjualan per order channel (dine-in / takeaway) di laporan Sales, melengkapi breakdown metode pembayaran yang sudah ada (PRD §7.10 P0).

**Architecture:** Satu groupBy baru (`by_channel`) di `DailySalesQuery` dengan filter PERSIS sama dengan summary yang ada, lalu satu section tabel baru di `Sales.vue` yang mirror markup section "Metode pembayaran". Tidak ada perubahan route/controller — prop `sales` Inertia sudah menyeluruh.

**Tech Stack:** Laravel (PHP 8.3, PostgreSQL), Vue 3 + TypeScript (Inertia), PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-10-31-sales-by-channel-design.md`

## Global Constraints

- Uang integer IDR; semua hasil agregasi DCast ke `(int)`. Tidak boleh pakai float.
- Filter `by_channel` PERSIS sama dengan summary: `orders.payment_status = 'paid'`, `orders.status != 'cancelled'`, `created_at` between range, filter `branch_id` optional.
- `sum(by_channel[].amount)` untuk filter yang sama HARUS == `gross_sales` (tes mengunci ini).
- Urutan baris: `orderBy('orders.channel')` deterministik (dine_in sebelum takeaway).
- Label dari `OrderChannel::tryFrom(...)?->label() ?? $row->channel` (pola sama dengan `by_payment_method`).
- Channel `dine_in`/`takeaway` satu-satunya yang ada; delivery out of scope.
- Jalankan `php artisan test` sebelum commit; jangan melemahkan/menghapus tes yang gagal.

## Review Focus

1. Order `unpaid`/`cancelled` (voided) tidak boleh muncul di `by_channel` — Rule filter identik dengan summary. → Tes Task 1 `test_daily_sales_includes_by_channel_breakdown_matching_gross_sales`.
2. Filter cabang: order cabang lain tidak boleh bocor ke baris cabang terpilih. → Tes sama (ada order cabang B, filter A).
3. Nilai channel tak dikenal (data legacy/korup) → label jatuh ke nilai mentah, tidak perlu crash. → Tes Task 1 `test_by_channel_falls_back_to_raw_value_for_unknown_channel`.
4. Konsistensi angka: total baris channel == gross sales. → assert Task 1.
5. Range kosong: `by_channel` bernilai `[]` (bukan null/absen) dan section UI tidak merender (sudah dijaga `v-else` pada `order_count === 0`). → Tes Task 1 + assert prop Task 2.

---

### Task 1: `by_channel` di `DailySalesQuery`

**Files:**

- Modify: `app/Modules/Reporting/Queries/DailySalesQuery.php`
- Test: `tests/Feature/ReportTest.php`

**Interfaces:**

- Produces: return array `DailySalesQuery` bertambah key `by_channel: list<array{channel: string, label: string, order_count: int, amount: int}>` (Task 2 memakai key + field ini).

- [ ] **Step 1: Tulis tes yang gagal** di `tests/Feature/ReportTest.php` (import `App\Modules\Ordering\Enums\OrderChannel` di atas):

```php
public function test_daily_sales_includes_by_channel_breakdown_matching_gross_sales(): void
{
    $branchA = Branch::factory()->create();
    $branchB = Branch::factory()->create();

    Order::factory()->paid()->for($branchA)->create(['total' => 120000, 'channel' => OrderChannel::DineIn]);
    Order::factory()->paid()->for($branchA)->create(['total' => 80000]); // default factory: takeaway
    Order::factory()->paid()->for($branchB)->create(['total' => 90000]); // branch lain, jangan bocor
    Order::factory()->unpaid()->for($branchA)->create(['total' => 5000, 'channel' => OrderChannel::DineIn]);
    Order::factory()->voided()->for($branchA)->create(['total' => 7000, 'channel' => OrderChannel::DineIn]);

    $result = app(DailySalesQuery::class)(
        CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
        $branchA->id,
    );

    $this->assertSame([
        ['channel' => 'dine_in', 'label' => 'Dine-in', 'order_count' => 1, 'amount' => 120000],
        ['channel' => 'takeaway', 'label' => 'Takeaway', 'order_count' => 1, 'amount' => 80000],
    ], $result['by_channel']);
    $this->assertSame(
        array_sum(array_column($result['by_channel'], 'amount')),
        $result['gross_sales'],
    );
}

public function test_by_channel_falls_back_to_raw_value_for_unknown_channel(): void
{
    $branch = Branch::factory()->create();
    $order = Order::factory()->paid()->for($branch)->create(['total' => 10000]);
    $order->forceFill(['channel' => 'whatsapp'])->save();

    $result = app(DailySalesQuery::class)(
        CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
        $branch->id,
    );

    $this->assertSame([
        ['channel' => 'whatsapp', 'label' => 'whatsapp', 'order_count' => 1, 'amount' => 10000],
    ], $result['by_channel']);
}

public function test_by_channel_is_empty_array_when_no_qualifying_orders(): void
{
    $branch = Branch::factory()->create();

    $result = app(DailySalesQuery::class)(
        CarbonImmutable::now('Asia/Jakarta')->startOfDay(),
        CarbonImmutable::now('Asia/Jakarta')->endOfDay(),
        $branch->id,
    );

    $this->assertSame([], $result['by_channel']);
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter test_daily_sales_includes_by_channel_breakdown_matching_gross_sales`
Expected: FAIL — key `by_channel` belum ada di return array.

- [ ] **Step 3: Implementasi di `app/Modules/Reporting/Queries/DailySalesQuery.php`**

- Import `use App\Modules\Ordering\Enums\OrderChannel;`.
- phpdoc return array: tambah baris `by_channel: list<array{channel: string, label: string, order_count: int, amount: int}>`.
- Salin pattern `$byPaymentMethod` (baris 40-58) sebagai `$byChannel`, tapi dari tabel `orders` langsung (tanpa join payments), filter persis seperti `$summary` (baris 29-33), group/order:

```php
$byChannel = DB::table('orders')
    ->where('payment_status', PaymentStatus::Paid->value)
    ->where('status', '!=', OrderStatus::Cancelled->value)
    ->whereBetween('created_at', [$fromUtc, $toUtc])
    ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
    ->groupBy('channel')
    ->orderBy('channel')
    ->selectRaw('channel, COUNT(*) as order_count, COALESCE(SUM(total), 0) as amount')
    ->get()
    ->map(fn (object $row) => [
        'channel' => (string) $row->channel,
        'label' => OrderChannel::tryFrom($row->channel)?->label() ?? $row->channel,
        'order_count' => (int) $row->order_count,
        'amount' => (int) $row->amount,
    ])
    ->values()
    ->all();
```

- Tambah key `'by_channel' => $byChannel,` di return array.

- [ ] **Step 4: Jalankan semua tes ReportTest, pastikan lulus**

Run: `php artisan test --filter ReportTest`
Expected: PASS (semua test di kelas, termasuk yang lama).

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Reporting/Queries/DailySalesQuery.php tests/Feature/ReportTest.php
git commit -m "feat(reporting): breakdown sales by order channel"
```

---

### Task 2: Section "Order per channel" di Sales.vue + PRD

**Files:**

- Modify: `resources/js/pages/admin/reports/Sales.vue`
- Modify: `tests/Feature/ReportTest.php` (tes halaman yang sudah ada)
- Modify: `PRD.md` (§7.10)

**Interfaces:**

- Consumes: `props.sales.by_channel` dari Task 1 — item `{channel: string, label: string, order_count: number, amount: number}`.

- [ ] **Step 1: Perluas tes halaman yang ada** di `tests/Feature/ReportTest.php`, method `test_owner_can_view_sales_and_stock_report_pages` — tambah 3 baris assert di blok `->assertInertia(...)` (order yang dibuat di tes itu: 1 pesanan lunas, default factory = takeaway):

```php
->where('sales.by_channel.0.channel', 'takeaway')
->where('sales.by_channel.0.label', 'Takeaway')
->where('sales.by_channel.0.amount', 100000)
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter test_owner_can_view_sales_and_stock_report_pages`
Expected: PASS — prop `by_channel` sudah dikirim controller otomatis (Task 1); ini pengunci agar UI tidak balik menghilangkan field.

- [ ] **Step 3: Implementasi UI di `resources/js/pages/admin/reports/Sales.vue`**

- Tipe TS: tambah di bawah `PaymentSplit`:

```ts
type ChannelSplit = {
    channel: string;
    label: string;
    order_count: number;
    amount: number;
};
```

dan baris `by_channel: ChannelSplit[];` di `SalesSummary`.

- Section baru, sisipkan **setelah** section "Metode pembayaran" (penutupnya `</section>` sebelum section "Produk terjual"). Indentasi 4 spasi, markup mirror persis section metode:

```vue
<section class="rounded-2xl border border-line bg-white">
    <div class="border-b border-line px-5 py-4">
        <h2 class="font-semibold text-ink">
            Order per channel
        </h2>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-sm">
            <thead
                class="bg-canvas text-xs tracking-wide text-muted uppercase"
            >
                <tr>
                    <th class="px-5 py-3">Channel</th>
                    <th class="px-5 py-3 text-right">
                        Pesanan
                    </th>
                    <th class="px-5 py-3 text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="row in props.sales.by_channel" :key="row.channel">
                    <td class="px-5 py-4 font-medium text-ink">
                        {{ row.label }}
                    </td>
                    <td
                        class="px-5 py-4 text-right text-muted tabular-nums"
                    >
                        {{ row.order_count }}
                    </td>
                    <td
                        class="px-5 py-4 text-right font-semibold text-ink tabular-nums"
                    >
                        {{ formatRupiah(row.amount) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</section>
```

- [ ] **Step 4: Verifikasi**

Run: `php artisan test --filter ReportTest && $HOME/.bun/bin/bun run typecheck`
Expected: PASS semua + typecheck bersih.

- [ ] **Step 5: Tandai PRD.md §7.10** — sisipkan paragraf baru setelah daftar P0/P1 (setelah baris "- **P1** Export permitted reports to CSV.") dan sebelum paragraf "Reports must clearly distinguish...":

```
Implemented in the current release: sales breakdown by order channel (dine-in / takeaway) alongside the existing payment-method breakdown on the Sales page, filtered by date range and branch; only paid, non-cancelled orders are counted, so channel row amounts sum to gross sales. Cost/margin, waste reporting, and CSV export remain P1. Detail: `docs/superpowers/specs/2026-10-31-sales-by-channel-design.md`.
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/admin/reports/Sales.vue tests/Feature/ReportTest.php PRD.md
git commit -m "feat(reporting): channel breakdown section on Sales page"
```
