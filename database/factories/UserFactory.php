<?php

namespace Database\Factories;

use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => 'member-'.Str::lower(Str::random(12)),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => (static::$password ??= Hash::make('password')),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(
            fn (array $attributes) => [
                'email_verified_at' => null,
            ],
        );
    }

    public function withGameIdentity(string $game, string $uuid, ?string $username = null): static
    {
        return $this->afterCreating(
            fn (User $user) => $user->gameIdentities()->create([
                'game' => $game,
                'uuid' => $uuid,
                'username' => $username ?? $user->username,
            ]),
        );
    }
}
