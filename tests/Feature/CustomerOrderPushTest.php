<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerDevice;
use App\Modules\Notifications\Jobs\SendOrderStatusPush;
use App\Modules\Notifications\Support\FcmClient;
use App\Modules\Ordering\Enums\OrderStatus;
use App\Modules\Ordering\Events\OrderStatusChanged;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOrderPushTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $credentialsPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fcm.project_id' => 'pizza-fruzta-test',
            'services.fcm.credentials_path' => self::credentialsPath(),
        ]);

        Cache::flush();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$credentialsPath !== null && is_file(self::$credentialsPath)) {
            unlink(self::$credentialsPath);
        }

        self::$credentialsPath = null;

        parent::tearDownAfterClass();
    }

    public function test_accepted_status_pushes_to_all_customer_devices(): void
    {
        $this->fakeFcm();

        $customer = Customer::factory()->create();
        $devices = CustomerDevice::factory()->count(2)->create(['customer_id' => $customer->id]);
        $order = $this->customerOrder($customer, OrderStatus::Queued);

        event(new OrderStatusChanged($order, OrderStatus::Queued, OrderStatus::Accepted));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token');

        foreach ($devices as $device) {
            Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/v1/projects/pizza-fruzta-test/messages:send'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['message']['token'] === $device->token
                && str_contains($request['message']['notification']['title'], $order->order_number)
                && $request['message']['data']['status'] === 'accepted'
                && $request['message']['data']['order_token'] === $order->public_token);
        }

        Http::assertSentCount(3);
    }

    public function test_cancelled_status_pushes_with_cancelled_copy(): void
    {
        $this->fakeFcm();

        $customer = Customer::factory()->create();
        CustomerDevice::factory()->create(['customer_id' => $customer->id]);
        $order = $this->customerOrder($customer, OrderStatus::Queued);

        event(new OrderStatusChanged($order, OrderStatus::Queued, OrderStatus::Cancelled));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'messages:send')
            && str_contains($request['message']['notification']['title'], 'dibatalkan')
            && $request['message']['data']['status'] === 'cancelled');
    }

    public function test_guest_order_does_not_queue_push(): void
    {
        Queue::fake();

        $order = Order::factory()->create(['customer_id' => null]);

        event(new OrderStatusChanged($order, OrderStatus::Queued, OrderStatus::Accepted));

        Queue::assertNotPushed(SendOrderStatusPush::class);
    }

    public function test_status_outside_push_list_does_not_queue_push(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $order = $this->customerOrder($customer, OrderStatus::Accepted);

        event(new OrderStatusChanged($order, OrderStatus::Accepted, OrderStatus::Preparing));

        Queue::assertNotPushed(SendOrderStatusPush::class);
    }

    public function test_push_is_skipped_when_fcm_is_not_configured(): void
    {
        config([
            'services.fcm.project_id' => null,
            'services.fcm.credentials_path' => null,
        ]);

        Queue::fake();

        $customer = Customer::factory()->create();
        CustomerDevice::factory()->create(['customer_id' => $customer->id]);
        $order = $this->customerOrder($customer, OrderStatus::Queued);

        event(new OrderStatusChanged($order, OrderStatus::Queued, OrderStatus::Accepted));

        Queue::assertNotPushed(SendOrderStatusPush::class);
    }

    public function test_unregistered_token_is_deleted_after_fcm_rejects_it(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'code' => 404,
                    'status' => 'NOT_FOUND',
                    'details' => [[
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ]],
                ],
            ], 404),
        ]);

        $customer = Customer::factory()->create();
        $device = CustomerDevice::factory()->create(['customer_id' => $customer->id]);
        $order = $this->customerOrder($customer, OrderStatus::Queued);

        event(new OrderStatusChanged($order, OrderStatus::Queued, OrderStatus::Accepted));

        $this->assertDatabaseMissing('customer_devices', ['id' => $device->id]);
    }

    public function test_job_does_nothing_for_missing_order(): void
    {
        $this->fakeFcm();

        (new SendOrderStatusPush(999999, 'accepted'))->handle(app(FcmClient::class));

        Http::assertNothingSent();
    }

    private function fakeFcm(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://fcm.googleapis.com/*' => Http::response([
                'name' => 'projects/pizza-fruzta-test/messages/1',
            ]),
        ]);
    }

    /**
     * Kunci RSA test dibuat saat runtime supaya repo tidak menyimpan
     * private key (walau hanya untuk tes).
     */
    private static function credentialsPath(): string
    {
        if (self::$credentialsPath !== null) {
            return self::$credentialsPath;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Gagal membuat kunci RSA untuk tes FCM.');
        }

        $path = tempnam(sys_get_temp_dir(), 'fcm-test-');

        if ($path === false) {
            throw new \RuntimeException('Gagal membuat file kredensial FCM.');
        }

        file_put_contents($path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'pizza-fruzta-test',
            'private_key' => $pem,
            'client_email' => 'fcm-test@pizza-fruzta-test.iam.gserviceaccount.com',
        ]));

        return self::$credentialsPath = $path;
    }

    private function customerOrder(Customer $customer, OrderStatus $status): Order
    {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'public_token' => (string) Str::uuid(),
        ]);
    }
}
