<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerPasswordResetCode;
use App\Modules\Customers\Models\CustomerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private int $tokenSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mailketing.token' => 'test-token',
            'services.mailketing.from_email' => 'noreply@pizzafruzta.test',
            'services.mailketing.from_name' => 'Pizza Fruzta',
            'services.mailketing.base_url' => 'https://api.mailketing.co.id/api/v2',
        ]);

        Http::fake([
            'https://api.mailketing.co.id/*' => Http::response([
                'success' => true,
                'data' => ['message_id' => '<api.test@pizzafruzta.test>'],
                'message' => 'Email queued successfully',
            ]),
        ]);
    }

    public function test_forgot_password_sends_otp_email_to_registered_address(): void
    {
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Budi@Example.com'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Jika email terdaftar, kode reset sudah dikirim.');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.mailketing.co.id/api/v2/send'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['from_email'] === 'noreply@pizzafruzta.test'
                && $request['recipient'] === 'budi@example.com'
                && str_contains((string) $request['content'], 'Pizza Fruzta');
        });

        $record = CustomerPasswordResetCode::query()->sole();
        $this->assertSame($customer->id, $record->customer_id);
        $this->assertTrue($record->expires_at->between(now()->addMinutes(9), now()->addMinutes(11)));

        $code = $this->codeFromEmail();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertFalse(Hash::check($code, $record->code_hash) && $record->code_hash === $code);
    }

    public function test_forgot_password_unknown_email_is_generic_and_sends_nothing(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'tidak-ada@example.com'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Jika email terdaftar, kode reset sudah dikirim.');

        Http::assertNothingSent();
        $this->assertSame(0, CustomerPasswordResetCode::query()->count());
    }

    public function test_forgot_password_is_throttled_per_account_for_one_minute(): void
    {
        Customer::factory()->create(['email' => 'budi@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'budi@example.com'])->assertStatus(202);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'budi@example.com'])->assertStatus(202);

        Http::assertSentCount(1);
        $this->assertSame(1, CustomerPasswordResetCode::query()->count());
    }

    public function test_forgot_password_still_returns_generic_response_when_provider_fails(): void
    {
        Http::fake([
            'https://api.mailketing.co.id/*' => Http::response(['success' => false], 500),
        ]);

        Customer::factory()->create(['email' => 'budi@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'budi@example.com'])
            ->assertStatus(202);
    }

    public function test_forgot_password_validates_email_format(): void
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'bukan-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_reset_password_with_valid_otp_updates_password_and_revokes_tokens(): void
    {
        [$customer, $oldToken] = $this->customerWithToken();
        $code = $this->requestCode($customer);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => $code,
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertOk()
            ->assertJsonPath('message', 'Password berhasil diubah. Silakan masuk kembali.');

        $this->assertTrue(Hash::check('passwordbaru123', $customer->fresh()->password));
        $this->assertSame(0, CustomerToken::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, CustomerPasswordResetCode::query()->where('customer_id', $customer->id)->count());

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$oldToken])
            ->assertUnauthorized();
    }

    public function test_reset_password_rejects_wrong_code_without_changing_password(): void
    {
        [$customer] = $this->customerWithToken();
        $code = $this->requestCode($customer);
        $wrong = $this->differentCode($code);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => $wrong,
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode reset tidak valid atau sudah kedaluwarsa.']);

        $this->assertTrue(Hash::check('password', $customer->fresh()->password));
        $this->assertSame(1, CustomerPasswordResetCode::query()->sole()->attempts);
    }

    public function test_reset_password_rejects_expired_code(): void
    {
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);

        CustomerPasswordResetCode::query()->create([
            'customer_id' => $customer->id,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => '123456',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode reset tidak valid atau sudah kedaluwarsa.']);

        $this->assertSame(0, CustomerPasswordResetCode::query()->count());
        $this->assertTrue(Hash::check('password', $customer->fresh()->password));
    }

    public function test_reset_password_deletes_code_after_five_wrong_attempts(): void
    {
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);

        CustomerPasswordResetCode::query()->create([
            'customer_id' => $customer->id,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/reset-password', [
                'email' => $customer->email,
                'code' => '000000',
                'password' => 'passwordbaru123',
                'password_confirmation' => 'passwordbaru123',
            ])->assertUnprocessable();
        }

        $this->assertSame(0, CustomerPasswordResetCode::query()->count());

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => '123456',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertUnprocessable();
    }

    public function test_reset_password_validates_password_confirmation_and_length(): void
    {
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);
        $code = $this->requestCode($customer);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => $code,
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $customer->email,
            'code' => $code,
            'password' => 'passwordbaru123',
            'password_confirmation' => 'beda123456',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_reset_password_unknown_email_uses_same_generic_error(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'tidak-ada@example.com',
            'code' => '123456',
            'password' => 'passwordbaru123',
            'password_confirmation' => 'passwordbaru123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'Kode reset tidak valid atau sudah kedaluwarsa.']);
    }

    /**
     * @return array{0: Customer, 1: string}
     */
    private function customerWithToken(): array
    {
        $this->tokenSeq++;
        $customer = Customer::factory()->create(['email' => 'budi@example.com']);
        $raw = 'token-'.$this->tokenSeq;

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', $raw),
            'expires_at' => now()->addDays(30),
        ]);

        return [$customer, $raw];
    }

    private function requestCode(Customer $customer): string
    {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $customer->email])
            ->assertStatus(202);

        return $this->codeFromEmail();
    }

    private function codeFromEmail(): string
    {
        $code = null;

        Http::assertSent(function (Request $request) use (&$code): bool {
            if ($request->url() !== 'https://api.mailketing.co.id/api/v2/send') {
                return false;
            }

            preg_match('/\b(\d{6})\b/', (string) $request['content'], $matches);
            $code = $matches[1] ?? null;

            return true;
        });

        $this->assertIsString($code, 'Kode OTP tidak ditemukan di email.');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }

    private function differentCode(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
