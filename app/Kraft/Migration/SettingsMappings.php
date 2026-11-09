<?php

namespace App\Kraft\Migration;

use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\NavigationTargets;
use DateTimeZone;
use JsonException;

final readonly class SettingsMappings
{
    public function __construct(private MappingRegistry $registry)
    {
    }

    /**
     * @throws JsonException
     */
    public function convert(
        array $settings,
        ImportContext $context,
        string $table,
        int|string $id,
        array $mapping,
        array $mailMapping,
    ): array {
        $converted = [];
        foreach ($settings as $key => $value) {
            if (in_array($key, ['condition', 'conditions'], true) && is_string($value) && trim($value) !== '') {
                if (filter_var($value, FILTER_VALIDATE_URL) && NavigationTargets::validUrl($value)) {
                    $context->setting('account.terms_url', $value);
                } else {
                    $json = $context->target->table('settings')->where('key', 'account.terms')->value('value');
                    $terms = json_decode($json ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    $terms[$context->options['locale'] ?? config('imports.locale', 'fr')] = ImportedHtml::clean($value);
                    $context->setting('account.terms', $terms);
                }
                $converted[] = $key;

                continue;
            }
            if ($this->mailSetting($key, $value, $id, $context, $mailMapping, $table)) {
                $converted[] = $key;

                continue;
            }
            if (isset($mapping[$key])) {
                $target = $mapping[$key];
                if ($target === 'site.locale') {
                    $value = ImportContext::normalizeLocale($value);
                }
                $valid = match ($target) {
                    'site.locale' => is_string($value) && array_key_exists($value, config('kraft.locales', [])),
                    'site.timezone' => is_string($value) && in_array($value, DateTimeZone::listIdentifiers(), true),
                    'contact.email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL),
                    default => is_scalar($value) || $value === null,
                };
                if ($valid) {
                    $context->setting(
                        $target,
                        $target === 'registration.enabled'
                            ? filter_var($value, FILTER_VALIDATE_BOOL)
                            : $value,
                    );
                    $converted[] = $key;

                    continue;
                }
            }
            $before = $context->settingsVersion();
            foreach ($this->registry->settingHandlers($context->adapter->id()) as $contribution) {
                if ($this->contributionSelected($contribution['plugin'], $context)) {
                    $contribution['handler'](['key' => $key, 'value' => $value], $context);
                }
            }
            if ($context->settingsVersion() !== $before) {
                $converted[] = $key;

                continue;
            }
            if (in_array($value, [null, '', false, 0, '0'], true)) {
                continue;
            }
            if (!preg_match('/password|secret|token|salt|smtp|captcha|key|webhook|oauth/i', $key)) {
                $context->report('configuration', [
                    'table' => $table,
                    'id' => $id,
                    'field' => $key,
                    'reason' => $key === 'theme' ? 'theme_configuration_review' : 'source_setting_archived',
                ]);
            } else {
                $context->report('reconnect', [
                    'table' => $table,
                    'id' => $id,
                    'field' => $key,
                    'reason' => 'provider_credentials_required',
                ]);
            }
        }

        return ['metadata' => ['converted_settings' => $converted]];
    }

    /**
     * @throws JsonException
     */
    private function mailSetting(
        string $key,
        mixed $value,
        int|string $id,
        ImportContext $context,
        array $mapping,
        string $table,
    ): bool {
        if (!isset($mapping[$key]) || $value === null || $value === '') {
            return false;
        }
        $enabled = $context->target->table('settings')->where('key', 'mail.enabled')->value('value');
        if (
            json_decode($enabled ?? 'false', true) ||
            $context->target->table('settings')->where('key', 'secret.mail.password')->exists()
        ) {
            return false;
        }
        $target = $mapping[$key];
        if ($target === 'mail.port') {
            if (!filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
                return false;
            }
            $value = (int) $value;
        } elseif ($target === 'mail.security') {
            if (!in_array($value, ['smtp', 'smtps'], true)) {
                return false;
            }
            $value = $value === 'smtps' ? 'smtps' : 'starttls';
        } elseif (!is_string($value) || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return false;
        }
        $context->setting('mail.enabled', false);
        $context->setting('mail.transport', 'smtp');
        $context->setting($target, $value);
        $context->report('reconnect', [
            'table' => $table,
            'id' => $id,
            'field' => $key,
            'reason' => 'provider_credentials_required',
        ]);

        return true;
    }

    private function contributionSelected(?string $plugin, ImportContext $context): bool
    {
        if ($plugin === null) {
            return true;
        }
        $extension = app(ExtensionManager::class)->all()[$plugin] ?? null;
        if (!$extension || !$extension['enabled']) {
            return false;
        }
        if (($context->options['tables'] ?? null) === null) {
            return true;
        }

        return array_any(
            $this->registry->mappings($context->adapter->id()),
            fn ($mapping, $table) => $mapping['plugin'] === $plugin &&
                in_array($table, $context->options['tables'], true),
        );
    }
}
