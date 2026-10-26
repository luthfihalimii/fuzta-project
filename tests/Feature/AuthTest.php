<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_owner_can_login(): void
    {
        $owner = User::factory()->owner()->create(['password' => 'secret123']);

        $this->post('/login', [
            'email' => $owner->email,
            'password' => 'secret123',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($owner);
    }

    public function test_active_cashier_is_redirected_to_pos(): void
    {
        $cashier = User::factory()->cashier()->create(['password' => 'secret123']);

        $this->post('/login', [
            'email' => $cashier->email,
            'password' => 'secret123',
        ])->assertRedirect('/pos');

        $this->assertAuthenticatedAs($cashier);
    }

    public function test_active_kitchen_user_is_redirected_to_kitchen(): void
    {
        $kitchen = User::factory()->kitchen()->create(['password' => 'secret123']);

        $this->post('/login', [
            'email' => $kitchen->email,
            'password' => 'secret123',
        ])->assertRedirect('/kitchen');

        $this->assertAuthenticatedAs($kitchen);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->cashier()->inactive()->create(['password' => 'secret123']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_wrong_password_cannot_login(): void
    {
        $user = User::factory()->cashier()->create(['password' => 'secret123']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/staff')->assertRedirect('/login');
    }

    public function test_user_can_logout(): void
    {
        $cashier = User::factory()->cashier()->create();

        $this->actingAs($cashier)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
