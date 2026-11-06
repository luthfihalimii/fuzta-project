<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Catalog\Models\Product;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderPaymentChanged;
use App\Modules\Ordering\Models\Order;
use App\Modules\Payments\Clients\DokuClient;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shifts\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DokuPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const DOKU = [
        'client_id' => 'CLIENT-TEST',
        'secret_key' => 'SECRET-TEST',
        'base_url' => 'https://api-sandbox.doku.com',
        'notification_path' => '/api/v1/webhooks/doku',
        'payment_due_minutes' => 30,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Tanpa stub DOKU di sini: Http::fake menyelesaikan stub sesuai urutan
        // registrasi, jadi stub setUp akan mengalahkan re-fake di test.
        // Tiap test yang melakukan checkout menyediakan stub sendiri.
        config(['services.doku' => self::DOKU]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'Asia/Jakarta'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ============ checkout ============

    public function test_checkout_creates_pending_doku_payment_with_url(): void
    {
        Http::fake(['api-sandbox.doku.com/*' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/TEST']],
        ])]);
        $branch = Branch::factory()->create(['opens_at' => '09:00', 'closes_at' => '21:00']);
        $product = $this->availableProductAt($branch);

        $response = $this->withHeader('Idempotency-Key', 'doku-1')
            ->postJson('/api/v1/orders', $this->pickupPayload($branch, $product))
            ->assertCreated()
            ->assertJsonPath('order.payment_status', 'unpaid')
            ->assertJsonPath('payment_url', 'https://sandbox.doku.com/checkout-link-v2/TEST');

        $this->assertNotNull($response->json('payment_expires_at'));

        /** @var Order $order */
        $order = Order::firstWhere('idempotency_key', 'doku-1');
        $payment = $order->payment;

        $this->assertNotNull($payment);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentMethod::DokuQris, $payment->method);
        $this->assertNull($payment->shift_id);
        $this->assertSame('doku', $payment->provider);
        $this->assertSame($order->order_number, $payment->provider_reference);
        $this->assertSame($order->total, $payment->amount);
        $this->assertSame('https://sandbox.doku.com/checkout-link-v2/TEST', $payment->payment_url);
        $this->assertNotNull($payment->expires_at);

        // Invoice ke DOKU: signature di header, nominal integer, due date dari config.
        Http::assertSent(function (Request $request) use ($order): bool {
            $body = json_decode((string) $request->body(), true) ?? [];

            return $request->method() === 'POST'
                && $request->url() === 'https://api-sandbox.doku.com/checkout/v1/payment'
                && ($body['order']['amount'] ?? null) === $order->total
                && ($body['order']['invoice_number'] ?? null) === $order->order_number
                && ($body['payment']['payment_due_date'] ?? null) === self::DOKU['payment_due_minutes']
                && in_array(self::DOKU['client_id'], $request->header('Client-Id'), true)
                && str_starts_with((string) ($request->header('Signature')[0] ?? ''), 'HMACSHA256=');
        });
    }

    public function test_midflight_cancel_during_invoice_returns_422_without_live_invoice(): void
    {
        // Simulasi cancel staf yang mendarat DI TENGAH panggilan invoice DOKU
        // (antara baca status dan persist) via side effect di fake HTTP.
        Http::fake(['api-sandbox.doku.com/*' => function (Request $request) {
            $order = Order::query()->where('idempotency_key', 'mid-cancel')->firstOrFail();
            $order->forceFill(['status' => OrderStatus::Cancelled])->save();

            return Http::response([
                'message' => ['SUCCESS'],
                'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/MID']],
            ]);
        }]);
        $branch = Branch::factory()->create(['opens_at' => '09:00', 'closes_at' => '21:00']);
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $response = $this->withHeader('Idempotency-Key', 'mid-cancel')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('order')
            ->assertJsonPath('errors.order.0', 'Pesanan sudah dibatalkan.');

        // Tidak ada invoice hidup untuk order mati; order tetap cancelled.
        $this->assertNull($response->json('payment_url'));
        $this->assertSame(OrderStatus::Cancelled, Order::firstWhere('idempotency_key', 'mid-cancel')->fresh()->status);
        $this->assertSame(0, Payment::count());

        // Retry key sama: order sudah dibatalkan → tetap 422, tanpa re-invoice.
        $this->withHeader('Idempotency-Key', 'mid-cancel')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'Pesanan sudah dibatalkan.');

        $this->assertSame(0, Payment::count());
    }

    public function test_replay_same_key_returns_existing_payment_without_new_invoice(): void
    {
        Http::fake(['api-sandbox.doku.com/*' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/TEST']],
        ])]);
        $branch = Branch::factory()->create(['opens_at' => '09:00', 'closes_at' => '21:00']);
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $first = $this->withHeader('Idempotency-Key', 'replay-doku')
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();
        $second = $this->withHeader('Idempotency-Key', 'replay-doku')
            ->postJson('/api/v1/orders', $payload)
            ->assertOk();

        $this->assertSame($first->json('payment_url'), $second->json('payment_url'));
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(PaymentStatus::Pending, Payment::first()->status);

        // Hanya SATU invoice boleh terbit (replay tidak boleh bikin invoice baru).
        Http::assertSentCount(1);
    }

    public function test_checkout_doku_down_returns_502_and_same_key_recovers_with_payment(): void
    {
        // Sequence: panggilan pertama DOKU down (500), retry key sama sukses.
        // (Http::fake cek stub sesuai urutan registrasi — re-fake beberapa kali
        //  tidak akan men-cover stub yang lebih dulu terdaftar.)
        Http::fake(['api-sandbox.doku.com/*' => Http::sequence()
            ->push(['error' => 'down'], 500)
            ->push([
                'message' => ['SUCCESS'],
                'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/RETRY']],
            ])]);
        $branch = Branch::factory()->create(['opens_at' => '09:00', 'closes_at' => '21:00']);
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $response = $this->withHeader('Idempotency-Key', 'down-retry')
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(502)
            ->assertJsonPath('message', 'Pembayaran online sedang tidak tersedia. Coba lagi dengan Idempotency-Key yang sama.');

        // Order tetap ada & unpaid; tidak ada payment palsu/phantom paid.
        $this->assertSame($response->json('token'), Order::firstWhere('idempotency_key', 'down-retry')->public_token);
        $this->assertSame(PaymentStatus::Unpaid, Order::firstWhere('idempotency_key', 'down-retry')->payment_status);
        $this->assertSame(0, Payment::count());

        // DOKU pulih: key sama menyelesaikan checkout dengan payment yang aktif.
        $this->withHeader('Idempotency-Key', 'down-retry')
            ->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('order.payment_status', 'unpaid')
            ->assertJsonPath('payment_url', 'https://sandbox.doku.com/checkout-link-v2/RETRY');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(PaymentStatus::Pending, Payment::first()->status);
        $this->assertSame('https://sandbox.doku.com/checkout-link-v2/RETRY', Payment::first()->payment_url);
    }

    public function test_stale_expired_pending_invoice_is_reissued_on_retry(): void
    {
        Http::fake(['api-sandbox.doku.com/*' => Http::response([
            'message' => ['SUCCESS'],
            'response' => ['payment' => ['url' => 'https://sandbox.doku.com/checkout-link-v2/NEW']],
        ])]);
        $branch = Branch::factory()->create(['opens_at' => '09:00', 'closes_at' => '21:00']);
        $product = $this->availableProductAt($branch);
        $payload = $this->pickupPayload($branch, $product);

        $this->withHeader('Idempotency-Key', 'stale-1')
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('payment_url', 'https://sandbox.doku.com/checkout-link-v2/NEW');

        // Invoice lama kadaluarsa di sisi DOKU: retry key sama membuat invoice baru
        // di baris payment yang sama (tetap satu payment per order).
        Payment::first()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeader('Idempotency-Key', 'stale-1')
            ->postJson('/api/v1/orders', $payload)
            ->assertOk()
            ->assertJsonPath('payment_url', 'https://sandbox.doku.com/checkout-link-v2/NEW');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Payment::count());
        $this->assertNull(Payment::first()->fresh()->voided_at);
        $this->assertSame(PaymentStatus::Pending, Payment::first()->fresh()->status);
        $this->assertTrue(Payment::first()->fresh()->expires_at->isFuture());

        // Persis dua invoice: satu pertama, satu re-invoice (bukan lebih banyak).
        Http::assertSentCount(2);
    }

    // ============ webhook ============

    public function test_valid_success_webhook_settles_order_once(): void
    {
        Event::fake([OrderPaymentChanged::class]);

        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $payment = $this->dokuPendingPayment($order);

        $body = $this->dokuWebhookBody($order, 'SUCCESS');

        $this->postSignedWebhook($body)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.paid')->count());
        Event::assertDispatched(OrderPaymentChanged::class, fn (OrderPaymentChanged $e): bool => $e->order->is($order));
        Http::assertNothingSent();

        // Replay notifikasi yang sama (DOKU retry) → 200 tanpa efek ganda.
        $this->postSignedWebhook($body)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.paid')->count());
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        Event::assertDispatched(OrderPaymentChanged::class, 1); // tidak double broadcast
        Http::assertNothingSent();
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null]);
        $this->dokuPendingPayment($order);

        $body = $this->dokuWebhookBody($order, 'SUCCESS');
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->dokuWebhookHeaders($raw);
        $headers['Signature'] = DokuClient::signature(
            self::DOKU['client_id'],
            'req-1',
            '2026-11-01T05:30:00Z',
            self::DOKU['notification_path'],
            $raw,
            'WRONG-SECRET',
        );

        $this->postDokuWebhook($raw, $headers)->assertForbidden();

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(0, DB::table('audit_logs')->count());
        Http::assertNothingSent();
    }

    public function test_webhook_tampered_body_fails_verification(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null]);
        $this->dokuPendingPayment($order);

        $body = $this->dokuWebhookBody($order, 'SUCCESS');
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->dokuWebhookHeaders($raw);

        // Body diubah setelah signature dibuat (nominal dinaikkan).
        $body['order']['amount'] = $order->total + 999999;
        $tampered = json_encode($body, JSON_THROW_ON_ERROR);

        $this->postDokuWebhook($tampered, $headers)->assertForbidden();

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
    }

    public function test_webhook_amount_mismatch_is_rejected_and_stays_unpaid(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $payment = $this->dokuPendingPayment($order);

        $this->postSignedWebhook($this->dokuWebhookBody($order, 'SUCCESS', 50001))
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'payment.paid')->count());
    }

    public function test_failed_expired_and_unknown_statuses_do_not_mark_paid(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null]);
        $payment = $this->dokuPendingPayment($order);

        foreach (['FAILED', 'EXPIRED', 'SOMETHING_ELSE'] as $status) {
            $this->postSignedWebhook($this->dokuWebhookBody($order, $status))->assertOk();
        }

        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_success_without_known_invoice_is_rejected(): void
    {
        Order::factory()->unpaid()->create(['placed_by_id' => null]);

        $this->postSignedWebhook([
            'transaction' => ['status' => 'SUCCESS'],
            'order' => ['invoice_number' => 'PF-TIDAK-ADA', 'amount' => 10000],
        ])->assertStatus(422);
    }

    public function test_late_payment_audit_is_written_once_per_payment(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $payment = $this->dokuPendingPayment($order);

        // Order sudah dibatalkan + payment voided sebelum notifikasi DOKU
        // (mis. oleh scheduler expiry) — notifikasi SUCCESS datang telat.
        $payment->forceFill(['status' => PaymentStatus::Voided, 'voided_at' => now()])->save();
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        // DOKU retry notifikasi: body persis sama dua kali.
        $body = $this->dokuWebhookBody($order, 'SUCCESS');
        $this->postSignedWebhook($body)->assertOk();
        $this->postSignedWebhook($body)->assertOk();

        // Audit payment.late_payment harus PERSIS SATU baris, target = payment.
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.late_payment')->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.late_payment',
            'target_type' => Payment::class,
            'target_id' => $payment->id,
        ]);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
    }

    public function test_late_payment_records_reconciliation_event_once(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $payment = $this->dokuPendingPayment($order);

        $payment->forceFill(['status' => PaymentStatus::Voided, 'voided_at' => now()])->save();
        $order->forceFill(['status' => OrderStatus::Cancelled])->save();

        $body = $this->dokuWebhookBody($order, 'SUCCESS');
        $this->postSignedWebhook($body)->assertOk();
        $this->postSignedWebhook($body)->assertOk();

        $this->assertSame(1, $payment->events()->where('type', 'late_paid')->count());
        $this->assertDatabaseHas('payment_events', [
            'payment_id' => $payment->id,
            'type' => 'late_paid',
            'amount' => 50000,
        ]);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_webhook_rejects_malformed_invoice_number_or_amount(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $this->dokuPendingPayment($order);

        $badOrderFields = [
            ['invoice_number' => ['nested' => 'array'], 'amount' => 50000],
            ['invoice_number' => 'PF-1', 'amount' => '12.5'],
            ['invoice_number' => 'PF-1', 'amount' => 12.5],
            ['invoice_number' => 'PF-1', 'amount' => '1e4'],
        ];

        foreach ($badOrderFields as $fields) {
            $this->postSignedWebhook([
                'transaction' => ['status' => 'SUCCESS'],
                'order' => $fields,
            ])->assertStatus(422);
        }

        // Tanpa efek: tidak paid, tidak ada audit.
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_success_after_staff_cancel_keeps_cancelled_and_audits_late_payment(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null]);
        $cashier = User::factory()->cashier()->for($order->branch)->create();
        $payment = $this->dokuPendingPayment($order);

        $this->actingAs($cashier)
            ->post("/orders/{$order->id}/cancel", ['reason' => 'Customer batal'])
            ->assertRedirect();

        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'payment.voided_on_cancel',
            'target_id' => $payment->id,
            'actor_id' => $cashier->id,
        ]);

        // DOKU SUCCESS datang telat setelah order dibatalkan: tidak boleh menghidupkan order.
        $this->postSignedWebhook($this->dokuWebhookBody($order, 'SUCCESS'))->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.late_payment')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'payment.paid')->count());
    }

    public function test_success_after_expiry_command_keeps_cancelled_and_audits_late_payment(): void
    {
        $order = Order::factory()->unpaid()->create(['placed_by_id' => null, 'total' => 50000]);
        $payment = $this->dokuPendingPayment($order);
        $payment->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $payment->fresh()->status);

        $this->postSignedWebhook($this->dokuWebhookBody($order, 'SUCCESS'))
            ->assertOk();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payment.late_payment')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'payment.paid')->count());
    }

    // ============ guard POS / void ============

    public function test_pos_cannot_pay_order_with_pending_online_payment(): void
    {
        $order = Order::factory()->unpaid()->create(['total' => 170000]);
        $payment = $this->dokuPendingPayment($order);
        $cashier = User::factory()->cashier()->for($order->branch)->create();
        $this->openShiftFor($cashier);

        $this->actingAs($cashier)
            ->postJson("/orders/{$order->id}/pay", ['method' => 'cash', 'cash_received' => 200000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order')
            ->assertJsonPath('errors.order.0', 'Pesanan menunggu pembayaran online.');

        // Kasir tidak boleh menganggap pesanan lunas: tetap pending + unpaid.
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    public function test_owner_cannot_void_order_with_online_payment(): void
    {
        $order = Order::factory()->paid()->create(['total' => 170000]);
        $order->payment->update([
            'provider' => 'doku',
            'provider_reference' => $order->order_number,
        ]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)
            ->postJson("/orders/{$order->id}/void", ['reason' => 'Salah input'])
            ->assertStatus(422)
            ->assertJsonPath('errors.order.0', 'Pembayaran online tidak dapat di-void. Refund manual lewat dashboard DOKU.');

        $this->assertSame(OrderStatus::Queued, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'order.voided')->count());
    }

    // ============ scheduler expiry ============

    public function test_expire_command_cancels_only_expired_unpaid_customer_orders(): void
    {
        $branch = Branch::factory()->create();

        // Kadaluarsa: payment pending lewat expires_at.
        $expired = Order::factory()->unpaid()->create(['branch_id' => $branch->id, 'placed_by_id' => null, 'total' => 50000]);
        $expiredPayment = $this->dokuPendingPayment($expired);
        $expiredPayment->forceFill(['expires_at' => now()->subMinute()])->save();

        // Belum kadaluarsa: payment pending masih aktif.
        $fresh = Order::factory()->unpaid()->create(['branch_id' => $branch->id, 'placed_by_id' => null]);
        $this->dokuPendingPayment($fresh);

        // POS (placed_by_id terisi): di luar jangkauan scheduler walau sudah lama.
        /** @var Order $pos */
        $pos = Order::factory()->unpaid()->create(['branch_id' => $branch->id]);
        $pos->forceFill(['created_at' => now()->subHours(2)])->save();

        // Customer tanpa payment row, dibuat lebih dari due minutes: ikut expired.
        /** @var Order $noPayment */
        $noPayment = Order::factory()->unpaid()->create(['branch_id' => $branch->id, 'placed_by_id' => null]);
        $noPayment->forceFill(['created_at' => now()->subHours(2)])->save();

        // Payment pending tapi expires_at NULL + sudah lama: fallback
        // created_at + due minutes, tetap bisa expired.
        $nullExpire = Order::factory()->unpaid()->create(['branch_id' => $branch->id, 'placed_by_id' => null, 'total' => 50000]);
        $nullExpirePayment = $this->dokuPendingPayment($nullExpire);
        $nullExpirePayment->forceFill(['expires_at' => null])->save();
        $nullExpire->forceFill(['created_at' => now()->subHours(2)])->save();

        // Paid: tidak boleh disentuh.
        $orderPaid = Order::factory()->paid()->create(['branch_id' => $branch->id, 'placed_by_id' => null]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $expired->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $noPayment->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $nullExpire->fresh()->status);
        $this->assertSame(OrderStatus::Queued, $fresh->fresh()->status);
        $this->assertSame(OrderStatus::Queued, $pos->fresh()->status);
        $this->assertSame(OrderStatus::Queued, $orderPaid->fresh()->status);

        $this->assertSame(PaymentStatus::Voided, $expiredPayment->fresh()->status);
        $this->assertNotNull($expiredPayment->fresh()->voided_at);
        $this->assertSame(PaymentStatus::Voided, $nullExpirePayment->fresh()->status);
        $this->assertSame(3, DB::table('audit_logs')->where('action', 'order.expired')->count());

        foreach ([$expired, $noPayment, $nullExpire] as $order) {
            $this->assertDatabaseHas('order_status_events', [
                'order_id' => $order->id,
                'to_status' => 'cancelled',
                'actor_id' => null,
            ]);
        }
    }

    // ============ helpers ============

    private function availableProductAt(Branch $branch, array $attributes = []): Product
    {
        $product = Product::factory()->create(['weight_grams' => 500] + $attributes);
        $product->branches()->attach($branch, ['is_available' => true]);

        return $product;
    }

    private function pickupPayload(Branch $branch, Product $product): array
    {
        return [
            'branch_id' => $branch->id,
            'fulfillment' => 'pickup',
            'customer_name' => 'Budi Santoso',
            'customer_phone' => '081234567890',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ];
    }

    private function openShiftFor(User $user): CashierShift
    {
        return CashierShift::factory()->open()->for($user, 'user')->create([
            'branch_id' => $user->branch_id,
        ]);
    }

    private function dokuPendingPayment(Order $order): Payment
    {
        return Payment::create([
            'order_id' => $order->id,
            'shift_id' => null,
            'method' => PaymentMethod::DokuQris,
            'amount' => $order->total,
            'status' => PaymentStatus::Pending,
            'provider' => 'doku',
            'provider_reference' => $order->order_number,
            'payment_url' => 'https://sandbox.doku.com/checkout-link-v2/TEST',
            'expires_at' => now()->addMinutes(self::DOKU['payment_due_minutes']),
        ]);
    }

    private function dokuWebhookBody(Order $order, string $status, ?int $amount = null): array
    {
        return [
            'service' => ['id' => 'QRIS'],
            'acquirer' => ['id' => 'QRIS'],
            'channel' => ['id' => 'QRIS'],
            'transaction' => [
                'status' => $status,
                'date' => '2026-11-01T05:30:00Z',
            ],
            'order' => [
                'invoice_number' => $order->order_number,
                'amount' => $amount ?? $order->total,
            ],
        ];
    }

    /**
     * Header webhook DOKU non-SNAP dengan signature valid (dibangun dengan
     * DokuClient::signature — membuktikan verifikasi benaran jalan).
     */
    private function dokuWebhookHeaders(string $rawBody): array
    {
        $requestId = (string) Str::uuid();
        $timestamp = '2026-11-01T05:30:00Z';

        return [
            'Client-Id' => self::DOKU['client_id'],
            'Request-Id' => $requestId,
            'Request-Timestamp' => $timestamp,
            'Signature' => DokuClient::signature(
                self::DOKU['client_id'],
                $requestId,
                $timestamp,
                self::DOKU['notification_path'],
                $rawBody,
                self::DOKU['secret_key'],
            ),
        ];
    }

    /**
     * Ucarkan webhook ber-signature dari body (byte persis = hasil encoding).
     *
     * @param  array<string, mixed>  $body
     */
    private function postSignedWebhook(array $body, ?array $headerOverrides = null): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $headerOverrides ?? $this->dokuWebhookHeaders($raw);

        return $this->postDokuWebhook($raw, $headers);
    }

    /**
     * Ucarkan webhook dengan byte body + header eksplisit (untuk kasus
     * signature-vs-body yang sengaja dibuat tidak cocok).
     */
    private function postDokuWebhook(string $raw, array $headers): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call('POST', '/api/v1/webhooks/doku', [], [], [], $server, $raw);
    }
}
