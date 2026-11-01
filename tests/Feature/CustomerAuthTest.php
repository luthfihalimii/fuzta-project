<?php

namespace Tests\Feature;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_customer_and_returns_raw_token_once(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'phone' => '+62 812-3456-7890',
            'password' => 'rahasia123',
        ])->assertCreated()
            ->assertJsonPath('customer.name', 'Budi Santoso')
            ->assertJsonPath('customer.phone', '081234567890')
            ->assertJsonMissingPath('customer.password');

        $token = (string) $response->json('token');
        $this->assertSame(40, strlen($token));
        $this->assertIsString($response->json('expires_at'));
        $this->assertNotNull($response->json('customer.id'));

        // DB hanya menyimpan hash SHA-256, bukan token mentah.
        $this->assertTrue(CustomerToken::query()->where('token_hash', hash('sha256', $token))->exists());

        $customer = Customer::query()->sole();
        $this->assertSame('081234567890', $customer->phone);
        $this->assertTrue(Hash::check('rahasia123', $customer->password));
        $this->assertNotNull($customer->last_login_at);
    }

    public function test_register_rejects_duplicate_phone_in_other_format(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'password' => 'rahasia123',
        ])->assertCreated();

        // Bentuk `62...` dari nomor yang sama tetap duplikat.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Lain',
            'phone' => '6281234567890',
            'password' => 'rahasia123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_register_rejects_short_password_and_malformed_phone(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'phone' => '081234567890',
            'password' => 'rahasia',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'phone' => '0812',
            'password' => 'rahasia123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_login_returns_token_and_updates_last_login(): void
    {
        $customer = Customer::factory()->create(['password' => 'rahasia123']);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => $customer->phone,
            'password' => 'rahasia123',
        ])->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonMissingPath('customer.password');

        $token = (string) $response->json('token');
        $this->assertSame(40, strlen($token));
        $this->assertIsString($response->json('expires_at'));
        $this->assertTrue(CustomerToken::query()->where('token_hash', hash('sha256', $token))->exists());

        $this->assertNotNull($customer->fresh()->last_login_at);
    }

    public function test_login_wrong_password_gives_same_message_as_unknown_phone(): void
    {
        $customer = Customer::factory()->create(['password' => 'rahasia123']);

        $wrong = $this->postJson('/api/v1/auth/login', [
            'phone' => $customer->phone,
            'password' => 'password-salah',
        ])->assertUnprocessable()->json('errors');

        $unknown = $this->postJson('/api/v1/auth/login', [
            'phone' => '081299999999',
            'password' => 'password-salah',
        ])->assertUnprocessable()->json('errors');

        $this->assertSame($wrong, $unknown);
        $this->assertSame(['Nomor HP atau password salah.'], $wrong['phone']);
    }

    public function test_login_inactive_customer_is_rejected(): void
    {
        $customer = Customer::factory()->create(['password' => 'rahasia123', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'phone' => $customer->phone,
            'password' => 'rahasia123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Akun tidak aktif.']);
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $body = ['phone' => '081299999999', 'password' => 'password-salah'];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $body)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $body)->assertTooManyRequests();
    }

    public function test_logout_revokes_only_the_used_token(): void
    {
        $customer = Customer::factory()->create();

        foreach (['token-alpha', 'token-beta'] as $raw) {
            CustomerToken::query()->create([
                'customer_id' => $customer->id,
                'token_hash' => hash('sha256', $raw),
                'expires_at' => now()->addDays(30),
            ]);
        }

        // Logout dengan token A: 204, hanya baris A yang terhapus.
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer token-alpha'])
            ->assertNoContent();

        $this->assertFalse(CustomerToken::query()->where('token_hash', hash('sha256', 'token-alpha'))->exists());
        $this->assertTrue(CustomerToken::query()->where('token_hash', hash('sha256', 'token-beta'))->exists());

        // Token B masih valid: sanggup dipakai (dan ikut terhapus oleh logout-nya sendiri).
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer token-beta'])
            ->assertNoContent();

        // Dua-duanya sudah logout: 401.
        foreach (['token-alpha', 'token-beta'] as $raw) {
            $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$raw])
                ->assertUnauthorized()
                ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
        }
    }

    public function test_expired_token_is_rejected(): void
    {
        $customer = Customer::factory()->create();

        CustomerToken::query()->create([
            'customer_id' => $customer->id,
            'token_hash' => hash('sha256', 'token-kedaluwarsa'),
            'expires_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer token-kedaluwarsa'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Silakan masuk terlebih dahulu.']);
    }
}
