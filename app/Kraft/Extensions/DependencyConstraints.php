<?php

namespace App\Kraft\Extensions;

use Composer\Semver\VersionParser;
use RuntimeException;

class DependencyConstraints
{
    public const array PLATFORM = ['cms', 'php'];

    public function requirements(array $manifest): array
    {
        if (array_key_exists('cms', $manifest) || array_key_exists('dependencies', $manifest)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $requirements = $this->map($manifest['requires'] ?? null, true);
        foreach (self::PLATFORM as $id) {
            if (!array_key_exists($id, $requirements)) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
        }

        return $requirements;
    }

    public function dependencies(array $manifest): array
    {
        return array_map(
            fn ($dependency) => $dependency['constraint'],
            $this->extensions($this->requirements($manifest)),
        );
    }

    public function extensions(array $constraints): array
    {
        $extensions = [];
        foreach ($constraints as $reference => $constraint) {
            if (preg_match('/^(plugin|theme|game):([a-z][a-z0-9-]{1,49})$/D', $reference, $matches)) {
                $extensions[$matches[2]] = ['type' => $matches[1], 'constraint' => $constraint];
            }
        }

        return $extensions;
    }

    public function map(mixed $constraints, bool $platform = false): array
    {
        if (!is_array($constraints)) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        $extensions = [];
        foreach ($constraints as $reference => $constraint) {
            if (!is_string($reference)) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            if (preg_match('/^(plugin|theme|game):([a-z][a-z0-9-]{1,49})$/D', $reference, $matches)) {
                if (isset($extensions[$matches[2]])) {
                    throw new RuntimeException(__('system.extensions.invalid_manifest'));
                }
                $extensions[$matches[2]] = true;
            } elseif (!$platform || !in_array($reference, self::PLATFORM, true)) {
                throw new RuntimeException(__('system.extensions.invalid_manifest'));
            }
            $this->constraint($constraint);
        }

        return $constraints;
    }

    private function constraint(mixed $constraint): void
    {
        if (!is_string($constraint) || trim($constraint) === '' || strlen($constraint) > 200) {
            throw new RuntimeException(__('system.extensions.invalid_manifest'));
        }
        new VersionParser()->parseConstraints($constraint);
    }
}
