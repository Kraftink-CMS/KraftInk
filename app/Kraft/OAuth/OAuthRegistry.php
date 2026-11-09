<?php

namespace App\Kraft\OAuth;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\OAuth\Contracts\OAuthAdapter;
use App\Kraft\Secrets;
use App\Kraft\Settings;
use Laravel\Socialite\Contracts\Provider;
use Throwable;

class OAuthRegistry
{
    public function __construct(private readonly Settings $settings, private readonly Secrets $secrets)
    {
    }

    public function definitions(): array
    {
        $catalogue = config('oauth.providers', []);
        foreach ($this->settings->get('oauth.providers', []) as $id => $provider) {
            $defaults =
                collect($catalogue)->first(
                    fn ($metadata) => ($metadata['adapter'] ?? null) === ($provider['adapter'] ?? null),
                ) ?? [];
            $catalogue[$id] = array_replace($defaults, $provider);
        }

        return $catalogue;
    }

    public function all(): array
    {
        return array_keys($this->definitions());
    }

    public function metadata(string $id): array
    {
        return $this->definitions()[$id] ?? [];
    }

    public function adapters(): array
    {
        return array_keys(config('oauth.adapters'));
    }

    public function enabledForAdapter(string $adapter): array
    {
        return array_values(
            array_filter($this->enabled(), fn ($id) => ($this->metadata($id)['adapter'] ?? null) === $adapter),
        );
    }

    public function globallyEnabled(): bool
    {
        return (bool) $this->settings->get('oauth.enabled', true);
    }

    public function hasSavedSecret(string $id): bool
    {
        try {
            return $this->secrets->get('oauth.'.$id.'.client_secret') !== '';
        } catch (Throwable) {
            return false;
        }
    }

    public function configured(string $id): bool
    {
        try {
            $metadata = $this->metadata($id);
            if (!isset(config('oauth.adapters')[$metadata['adapter'] ?? '']) || !$this->callbackAllowed($id)) {
                return false;
            }
            if (
                (($metadata['credentials']['client_id'] ?? true) &&
                    trim((string) $this->settings->get('oauth.'.$id.'.client_id', '')) === '') ||
                (($metadata['credentials']['client_secret'] ?? true) &&
                    $this->secrets->get('oauth.'.$id.'.client_secret') === '')
            ) {
                return false;
            }
            $fields = $metadata['fields'] ?? [];
            $values = [];
            $rules = [];
            foreach ($fields as $key => $field) {
                $values[$key] = $this->settings->get('oauth.'.$id.'.'.$key, $field['default'] ?? null);
                $rules[$key] = $field['rules'] ?? ['nullable', 'string', 'max:255'];
            }

            return !validator($values, $rules)->fails();
        } catch (Throwable) {
            return false;
        }
    }

    public function callbackAllowed(string $id): bool
    {
        return !($this->metadata($id)['requires_https'] ?? false) ||
            str_starts_with(route('auth.oauth.callback', ['provider' => $id]), 'https://');
    }

    public function enabled(): array
    {
        if (!$this->globallyEnabled()) {
            return [];
        }

        return array_values(
            array_filter(
                $this->all(),
                fn ($id) => $this->settings->get('oauth.'.$id.'.enabled', false) && $this->configured($id),
            ),
        );
    }

    public function adapter(string $id): OAuthAdapter
    {
        $adapter = $this->metadata($id)['adapter'] ?? null;
        if (!$adapter || !isset(config('oauth.adapters')[$adapter])) {
            throw new NotFoundHttpException();
        }

        return app(config('oauth.adapters')[$adapter]);
    }

    public function driver(string $id): Provider
    {
        if (!in_array($id, $this->enabled(), true)) {
            throw new NotFoundHttpException();
        }
        $configuration = [
            'client_id' => $this->settings->get('oauth.'.$id.'.client_id'),
            'client_secret' => $this->secrets->get('oauth.'.$id.'.client_secret'),
            'redirect' => route('auth.oauth.callback', ['provider' => $id]),
            'guzzle' => ['timeout' => 15, 'connect_timeout' => 5],
        ];
        foreach ($this->metadata($id)['fields'] ?? [] as $key => $field) {
            $configuration[$key] = $this->settings->get('oauth.'.$id.'.'.$key, $field['default'] ?? null);
        }

        return $this->adapter($id)->provider($configuration);
    }
}
