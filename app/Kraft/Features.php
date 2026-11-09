<?php

namespace App\Kraft;

use App\Models\Accounts\User;
use Illuminate\Support\Facades\Route;

class Features
{
    private array $resolvers = [];

    public function register(string $feature, callable $resolver): void
    {
        $this->resolvers[$feature] = $resolver;
    }

    public function allows(string $feature, ?User $user): bool
    {
        return isset($this->resolvers[$feature]) && $this->resolvers[$feature]($user);
    }

    public function all(?User $user): array
    {
        $features = [];
        foreach ($this->resolvers as $feature => $resolver) {
            $features[$feature] = $this->allows($feature, $user);
        }

        return $features;
    }

    public function routeAvailable(string $name, ?User $user, ?array $features = null): bool
    {
        $route = Route::getRoutes()->getByName($name);
        if (!$route) {
            return false;
        }
        $allows = fn (string $feature) => $features === null
            ? $this->allows($feature, $user)
            : $features[$feature] ?? false;

        return array_all(
            $route->gatherMiddleware(),
            fn ($middleware) => !str_starts_with($middleware, 'capability:extension,') ||
                $allows(substr($middleware, 21)),
        );
    }
}
