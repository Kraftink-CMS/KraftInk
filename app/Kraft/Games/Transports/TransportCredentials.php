<?php

namespace App\Kraft\Games\Transports;

use App\Models\Servers\Server;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransportCredentials
{
    public function validate(Request $request, array $metadata, ?Server $server): array
    {
        $rules = [
            'transport_options' => 'sometimes|array',
            'transport_secret' => ['nullable', 'string', 'max:2048', 'not_regex:/\x00/'],
        ];
        $fields = $metadata['fields'] ?? [];
        foreach ($fields as $key => $field) {
            $rules['transport_options.'.$key] = $field['rules'];
        }
        $input = $request->validate($rules);
        $options = array_intersect_key($input['transport_options'] ?? [], $fields);
        $savedOptions = $server?->transport_options ?? [];
        foreach ($fields as $key => $field) {
            if (isset($field['shown_when'], $savedOptions[$key]) && !array_key_exists($key, $options)) {
                $options[$key] = $savedOptions[$key];
            }
        }

        $requiresSecret = !empty($metadata['secret']);
        $secret = $requiresSecret && $request->filled('transport_secret') ? $input['transport_secret'] : null;
        if ($requiresSecret && $secret === null && blank($server?->secret)) {
            throw ValidationException::withMessages([
                'transport_secret' => __('admin.servers.transport.secret_required', [
                    'field' => __($metadata['secret_label'] ?? 'admin.servers.transport.secret'),
                ]),
            ]);
        }

        return ['transport_options' => $options, 'transport_secret' => $secret];
    }

    public function requiresConfirmation(?Server $server, array $data, array $metadata, ?string $secret = null): bool
    {
        $stored = $server?->secret;
        $hasStored = is_string($stored) && $stored !== '';
        if (empty($metadata['secret'])) {
            return $hasStored;
        }
        if (!$hasStored) {
            return true;
        }
        if ($secret !== null && $secret !== '' && !hash_equals($stored, $secret)) {
            return true;
        }
        if (
            array_any(
                ['game', 'connection', 'host', 'port'],
                fn ($field) => !$this->sameValue($data[$field] ?? null, $server->{$field}),
            )
        ) {
            return true;
        }
        foreach ($metadata['fields'] ?? [] as $key => $field) {
            $previous = $server->transport_options[$key] ?? ($field['default'] ?? null);
            if (!$this->sameValue($data['transport_options'][$key] ?? ($field['default'] ?? null), $previous)) {
                return true;
            }
        }

        return false;
    }

    private function sameValue(mixed $first, mixed $second): bool
    {
        if ((is_scalar($first) || $first === null) && (is_scalar($second) || $second === null)) {
            return (string) $first === (string) $second;
        }

        return $first === $second;
    }
}
