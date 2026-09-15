<?php

namespace Database\Factories;

use App\Models\Condominium;
use App\Models\Role;
use App\Models\User;
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
            'role_id' => Role::idFor(Role::SINDICO),
            'condominium_id' => Condominium::factory(),
            'is_active' => true,
        ];
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

    /**
     * Platform super admin, not bound to any condominium.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::idFor(Role::SUPER_ADMIN),
            'condominium_id' => null,
        ]);
    }

    public function sindico(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::idFor(Role::SINDICO),
        ]);
    }

    public function zelador(): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::idFor(Role::ZELADOR),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
