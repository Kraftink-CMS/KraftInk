<?php

namespace App\Kraft\Setup;

use App\Kraft\System\PhpBinary;
use PDO;
use Throwable;

class Requirements
{
    public const array DATABASE_PORTS = ['sqlite' => null, 'mysql' => 3306, 'pgsql' => 5432];

    public function check(): array
    {
        $deployment = 'https://laravel.com/docs/13.x/deployment';
        $php = 'https://www.php.net/manual/en/extensions.php';
        $items = [
            [
                'code' => 'php',
                'name' => __('system.install.requirements.php'),
                'passed' => PHP_VERSION_ID >= 80401,
                'required' => true,
                'scope' => null,
                'detected' => PHP_VERSION,
                'reason' => __('system.install.requirements.php_reason'),
                'message' => __('system.install.requirements.php_failure'),
                'docs' => $deployment,
            ],
        ];
        $items[] = [
            'code' => 'php.architecture',
            'name' => __('system.install.requirements.architecture'),
            'passed' => PHP_INT_SIZE >= 8,
            'required' => true,
            'scope' => null,
            'detected' => PHP_INT_SIZE * 8 .' bits',
            'reason' => __('system.install.requirements.architecture_reason'),
            'message' => __('system.install.requirements.architecture_failure'),
            'docs' => 'https://www.php.net/manual/en/language.types.integer.php',
        ];
        foreach (
            [
                'ctype',
                'curl',
                'dom',
                'fileinfo',
                'filter',
                'hash',
                'mbstring',
                'openssl',
                'pdo',
                'session',
                'sodium',
                'tokenizer',
                'xml',
                'zip',
            ] as $extension
        ) {
            $passed = extension_loaded($extension);
            $items[] = [
                'code' => $extension,
                'name' => $extension,
                'passed' => $passed,
                'required' => true,
                'scope' => null,
                'detected' => $passed
                    ? (phpversion($extension) ?:
                    __('system.install.requirements.enabled'))
                    : __('system.install.requirements.missing'),
                'reason' => __('system.install.requirements.extensions.'.$extension),
                'message' => __('system.install.requirements.extension_failure', ['name' => $extension]),
                'docs' => $php,
            ];
        }
        $paths = [
            'storage' => storage_path(),
            'cache' => base_path('bootstrap/cache'),
            'environment' => app()->environmentFilePath(),
            'database' => database_path(),
        ];
        foreach ($paths as $key => $path) {
            $passed =
                is_writable($path) && ($key !== 'environment' || (is_readable($path) && is_writable(dirname($path))));
            $items[] = [
                'code' => 'path.'.$key,
                'name' => __('system.install.requirements.paths.'.$key),
                'passed' => $passed,
                'required' => true,
                'scope' => $key === 'database' ? 'sqlite' : null,
                'detected' => $path,
                'reason' => __('system.install.requirements.path_reasons.'.$key),
                'message' => __('system.install.requirements.path_failure', ['path' => $path]),
                'docs' => $deployment.'#directory-permissions',
            ];
        }
        $drivers = PDO::getAvailableDrivers();
        $items[] = [
            'code' => 'database',
            'name' => __('system.install.requirements.database'),
            'passed' => (bool) array_intersect($drivers, array_keys(self::DATABASE_PORTS)),
            'required' => true,
            'scope' => null,
            'detected' => implode(', ', $drivers) ?: __('system.install.requirements.none'),
            'reason' => __('system.install.requirements.database_reason'),
            'message' => __('system.install.requirements.database_failure'),
            'docs' => 'https://www.php.net/manual/en/pdo.installation.php',
        ];
        foreach (array_keys(self::DATABASE_PORTS) as $driver) {
            $items[] = [
                'code' => 'pdo.'.$driver,
                'name' => 'PDO_'.strtoupper($driver),
                'passed' => in_array($driver, $drivers, true),
                'required' => true,
                'scope' => $driver,
                'detected' => in_array($driver, $drivers, true)
                    ? __('system.install.requirements.enabled')
                    : __('system.install.requirements.missing'),
                'reason' => __('system.install.requirements.driver_reason', ['driver' => $driver]),
                'message' => __('system.install.requirements.driver_failure', ['driver' => $driver]),
                'docs' => 'https://www.php.net/manual/en/pdo.installation.php',
            ];
        }
        $manifest = public_path('build/manifest.json');
        $items[] = [
            'code' => 'assets',
            'name' => __('system.install.requirements.assets'),
            'passed' => is_file($manifest) || app()->runningUnitTests(),
            'required' => true,
            'scope' => null,
            'detected' => is_file($manifest)
                ? __('system.install.requirements.present')
                : __('system.install.requirements.missing'),
            'reason' => __('system.install.requirements.assets_reason'),
            'message' => __('system.install.requirements.assets_failure'),
            'docs' => 'https://laravel.com/docs/13.x/vite',
        ];
        $items[] = [
            'code' => 'processes',
            'name' => __('system.install.requirements.processes'),
            'passed' => function_exists('proc_open'),
            'required' => true,
            'scope' => null,
            'detected' => function_exists('proc_open')
                ? __('system.install.requirements.enabled')
                : __('system.install.requirements.disabled'),
            'reason' => __('system.install.requirements.processes_reason'),
            'message' => __('system.install.requirements.processes_failure'),
            'docs' => 'https://www.php.net/manual/en/function.proc-open.php',
        ];

        try {
            $binary = app(PhpBinary::class)->path();
        } catch (Throwable) {
            $binary = null;
        }
        $items[] = [
            'code' => 'php.cli',
            'name' => __('system.install.requirements.cli'),
            'passed' => $binary !== null,
            'required' => true,
            'scope' => null,
            'detected' => $binary ?? __('system.install.requirements.missing'),
            'reason' => __('system.install.requirements.cli_reason'),
            'message' => __('system.install.requirements.cli_failure'),
            'docs' => 'https://www.php.net/manual/en/features.commandline.php',
        ];

        return $items;
    }
}
