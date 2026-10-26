<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Identity\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Cashier,
            'branch_id' => null,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Owner,
            'branch_id' => null,
        ]);
    }

    public function cashier(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Cashier,
        ]);
    }

    public function kitchen(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Kitchen,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
