<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Branches\Models\Branch;
use App\Modules\Ordering\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $user = User::factory()->cashier()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_login_rate_limit_is_scoped_by_email_and_ip(): void
    {
        $user = User::factory()->cashier()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->post('/login', [
            'email' => 'other@pizzafruzta.test',
            'password' => 'wrong',
        ])->assertRedirect();
    }

    public function test_guest_is_redirected_from_pos(): void
    {
        $this->get('/pos')->assertRedirect('/login');
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_header_is_sent_on_secure_requests(): void
    {
        $this->get('https://localhost/login')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_cors_rejects_unknown_origins_by_default(): void
    {
        $this->call('OPTIONS', '/api/v1/orders', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_order_creation_is_rate_limited_per_user(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->cashier()->for($branch)->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($cashier)
                ->postJson('/pos/orders', [])
                ->assertStatus(422);
        }

        $this->actingAs($cashier)
            ->postJson('/pos/orders', [])
            ->assertStatus(429);
    }

    public function test_session_cookie_defaults_are_hardened(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_payment_actions_are_rate_limited_per_user(): void
    {
        $order = Order::factory()->unpaid()->create();
        $cashier = User::factory()->cashier()->for($order->branch)->create();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($cashier)
                ->postJson("/orders/{$order->id}/pay", [])
                ->assertStatus(422);
        }

        $this->actingAs($cashier)
            ->postJson("/orders/{$order->id}/pay", [])
            ->assertStatus(429);
    }

    public function test_inactive_user_session_is_rejected(): void
    {
        $cashier = User::factory()->cashier()->inactive()->create();

        $this->actingAs($cashier)
            ->get('/pos')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
