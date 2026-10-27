<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_first_owner(): void
    {
        $this->artisan('app:create-owner', [
            'email' => 'owner@example.test',
            '--name' => 'Pemilik Baru',
            '--password' => 'secret123',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.test',
            'name' => 'Pemilik Baru',
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_refuses_when_an_active_owner_exists(): void
    {
        User::factory()->owner()->create(['email' => 'existing@example.test']);

        $this->artisan('app:create-owner', [
            'email' => 'owner@example.test',
            '--password' => 'secret123',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_applies_the_configured_password_policy(): void
    {
        Password::defaults(fn (): Password => Password::min(12));

        $this->artisan('app:create-owner', [
            'email' => 'owner@example.test',
            '--password' => 'short12',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }
}
