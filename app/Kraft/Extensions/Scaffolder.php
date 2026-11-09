<?php

namespace App\Kraft\Extensions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use App\Kraft\System\OperationLock;

class Scaffolder
{
    public function create(
        string $type,
        string $name,
        ?string $id = null,
        ?string $namespace = null,
        ?string $target = null,
    ): array {
        return app(OperationLock::class)->shared(
            fn () => $this->generate($type, $name, $id, $namespace, $target),
        );
    }

    private function generate(string $type, string $name, ?string $id, ?string $namespace, ?string $themeTarget): array
    {
        if (
            !in_array($type, ['plugin', 'theme', 'game'], true) ||
            trim($name) === '' ||
            mb_strlen($name) > 120 ||
            ($themeTarget !== null && ($type !== 'theme' || !in_array($themeTarget, ['site', 'admin'], true)))
        ) {
            throw new InvalidArgumentException(__('system.console.scaffold_invalid'));
        }
        $id ??= Str::slug($name);
        if (!preg_match('/^[a-z][a-z0-9-]{1,49}$/D', $id)) {
            throw new InvalidArgumentException(__('system.console.scaffold_id'));
        }
        if (app(BuiltinExtensions::class)->contains($id)) {
            throw new InvalidArgumentException(__('system.extensions.builtin_managed'));
        }
        $kind = ['plugin' => 'Plugins', 'theme' => 'Themes', 'game' => 'Games'][$type];
        $namespace = rtrim($namespace ?? 'KraftInk\\'.$kind.'\\'.Str::studly($id), '\\').'\\';
        if (
            strlen($namespace) > 160 ||
            !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+$/D', $namespace) ||
            preg_match('/^(?:App|Illuminate|Symfony|Composer|Laravel)\\\\/i', $namespace)
        ) {
            throw new InvalidArgumentException(__('system.console.scaffold_namespace'));
        }
        $root = config(
            ['plugin' => 'kraft.extensions.installed.plugins', 'theme' => 'kraft.extensions.installed.themes', 'game' => 'kraft.extensions.installed.games'][$type],
        );
        $this->assertPath($root);
        File::ensureDirectoryExists($root);
        $root = realpath($root);
        $target = $root.DIRECTORY_SEPARATOR.$id;
        $stage = $root.DIRECTORY_SEPARATOR.'.kraft-new-'.Str::uuid();
        $this->assertPath(storage_path('app/extensions.lock'));
        File::ensureDirectoryExists(storage_path('app'));
        $lock = fopen(storage_path('app/extensions.lock'), 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException(__('system.extensions.update_busy'));
        }
        try {
            if (file_exists($target) || is_link($target) || isset(app(InstallationReceipts::class)->all()[$id])) {
                throw new RuntimeException(__('system.console.scaffold_exists'));
            }
            foreach (
                array_replace(app(ExtensionManager::class)->all(), app(PackageRetirement::class)->all()) as $extension
            ) {
                if ($extension['id'] === $id || strcasecmp($extension['namespace'] ?? '', $namespace) === 0) {
                    throw new RuntimeException(__('system.console.scaffold_exists'));
                }
            }
            $manifest = [
                'id' => $id,
                'name' => trim($name),
                'type' => $type,
                'version' => '1.0.0',
                'description' => $id.'::messages.description',
                'market' => [
                    'description' => $id.'::messages.description',
                ],
                'requires' => [
                    'cms' => '>='.explode('-', config('manifest.version'), 2)[0],
                    'php' => '^8.4.1',
                ],
                'update' => ['preserve' => []],
            ];
            $files = [
                '.gitignore' => "/vendor/\n/node_modules/\n/dist/\n",
            ];
            if ($type === 'theme') {
                $manifest['target'] = $themeTarget ?? 'site';
                $manifest['theme_color'] = '#c04f1c';
                $manifest['assets'] = ['styles' => ['theme.css'], 'scripts' => []];
                $manifest['build'] = ['scss' => ['resources/scss/theme.scss']];
                $files['resources/scss/theme.scss'] = '';
                $files['public/theme.css'] = '';
                $files['package.json'] = File::get(resource_path('stubs/extensions/theme/package.json.stub'));
                $files['package-lock.json'] = File::get(resource_path('stubs/extensions/theme/package-lock.json.stub'));
                if ($manifest['target'] === 'site') {
                    $manifest['capabilities'] = ['appearance' => ['light', 'dark', 'auto']];
                    $files['resources/views/default/layouts/layout.blade.php'] = $this->stub(
                        'theme/site-layout.blade',
                        $id,
                        $namespace,
                    );
                    $files['resources/views/default/home/index.blade.php'] = File::get(
                        resource_path('views/default/home/index.blade.php'),
                    );
                } else {
                    $files['resources/views/admin/layouts/layout.blade.php'] = File::get(
                        resource_path('views/admin/layouts/layout.blade.php'),
                    );
                }
            } else {
                $class = $type === 'plugin' ? 'PluginServiceProvider' : 'GameServiceProvider';
                $manifest['namespace'] = $namespace;
                $manifest['provider'] = $namespace.'Providers\\'.$class;
                $files['src/Providers/'.$class.'.php'] = $this->stub($type.'/provider.php', $id, $namespace);
                if ($type === 'plugin') {
                    $manifest['games'] = ['supported' => ['*']];
                    $permission = 'plugins.'.$id.'.manage';
                    $viewPermission = 'plugins.'.$id.'.view';
                    $manifest['permissions'] = [
                        $viewPermission => [
                            'label' => $id.'::messages.view',
                            'default' => ['visitor' => true, 'member' => true],
                        ],
                        $permission => ['label' => $id.'::messages.manage', 'default' => ['admin' => true]],
                    ];
                    $manifest['configuration'] = [
                        'route' => 'plugins.'.$id.'.admin.dashboard.index',
                        'permission' => $permission,
                    ];
                    $manifest['navigation'] = [
                        'public' => [
                            [
                                'label' => $id.'::messages.title',
                                'route' => 'plugins.'.$id.'.public.home.index',
                                'permission' => $viewPermission,
                            ],
                        ],
                        'admin' => [
                            [
                                'label' => $id.'::messages.title',
                                'route' => 'plugins.'.$id.'.admin.dashboard.index',
                                'permission' => $permission,
                                'icon' => 'puzzle',
                            ],
                        ],
                    ];
                    $files['routes/web.php'] = $this->stub('plugin/routes/web.php', $id, $namespace);
                    $files['routes/admin.php'] = $this->stub('plugin/routes/admin.php', $id, $namespace);
                    $files['src/Http/Controllers/PublicController.php'] = $this->stub(
                        'plugin/controller.php',
                        $id,
                        $namespace,
                        ['%CLASS%' => 'PublicController', '%SUBNAMESPACE%' => '', '%VIEW%' => 'default.home.index'],
                    );
                    $files['src/Http/Controllers/Admin/AdminController.php'] = $this->stub(
                        'plugin/controller.php',
                        $id,
                        $namespace,
                        [
                            '%CLASS%' => 'AdminController',
                            '%SUBNAMESPACE%' => '\\Admin',
                            '%VIEW%' => 'admin.dashboard.index',
                        ],
                    );
                    $files['resources/views/default/home/index.blade.php'] = $this->stub(
                        'plugin/view.blade',
                        $id,
                        $namespace,
                        [
                            '%LAYOUT%' => 'default.layouts.layout',
                        ],
                    );
                    $files['resources/views/admin/dashboard/index.blade.php'] = $this->stub(
                        'plugin/view.blade',
                        $id,
                        $namespace,
                        [
                            '%LAYOUT%' => 'admin.layouts.layout',
                        ],
                    );
                } else {
                    $files['src/Adapters/GameAdapter.php'] = $this->stub('game/adapter.php', $id, $namespace);
                }
            }
            foreach (['fr', 'en'] as $locale) {
                $files['lang/'.$locale.'/messages.php'] = $this->stub('lang/'.$locale.'.php', $id, $namespace, [
                    '%NAME%' => var_export(trim($name), true),
                    '%DISPLAY_NAME%' => str_replace(['\\', "'"], ['\\\\', "\\'"], trim($name)),
                ]);
            }
            $files['manifest.json'] =
                json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                )."\n";
            File::ensureDirectoryExists($stage);
            foreach ($files as $path => $content) {
                File::ensureDirectoryExists(dirname($stage.'/'.$path));
                if (File::put($stage.'/'.$path, $content) === false) {
                    throw new RuntimeException(__('system.console.scaffold_write_failed'));
                }
            }
            app(Manifest::class)->read($stage, $type, $id);
            if (file_exists($target) || !@rename($stage, $target)) {
                throw new RuntimeException(__('system.console.scaffold_write_failed'));
            }

            return ['id' => $id, 'type' => $type, 'path' => $target];
        } finally {
            if (is_dir($stage) && !is_link($stage) && dirname($stage) === $root) {
                File::deleteDirectory($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function stub(string $name, string $id, string $namespace, array $extra = []): string
    {
        return strtr(
            File::get(resource_path('stubs/extensions/'.$name.'.stub')),
            ['%ID%' => $id, '%NAMESPACE%' => rtrim($namespace, '\\')] + $extra,
        );
    }

    private function assertPath(string $path): void
    {
        $cursor = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        while ($cursor !== '' && $cursor !== '.') {
            if (is_link($cursor)) {
                throw new RuntimeException(__('system.extensions.update_boundary'));
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                break;
            }
            $cursor = $parent;
        }
    }
}
