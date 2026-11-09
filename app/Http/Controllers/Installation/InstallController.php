<?php

namespace App\Http\Controllers\Installation;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Models\Setting;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Auth\Avatars\AvatarProviders;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\BuiltinSynchronizer;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Setup\Installation;
use App\Kraft\NavigationTargets;
use App\Kraft\Settings;
use App\Kraft\Setup\BundledExtensions;
use App\Kraft\Setup\DatabaseFailure;
use App\Kraft\Setup\EnvironmentFile;
use App\Kraft\Setup\InstallationJournal;
use App\Kraft\Setup\InstallationFiles;
use App\Kraft\Setup\Requirements;
use App\Kraft\Setup\StarterContent;
use App\Kraft\SiteDates;
use App\Models\Accounts\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;
use PDO;
use RuntimeException;
use App\Kraft\Auth\BuiltinRoles;

class InstallController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (app(Installation::class)->installed()) {
            return redirect('/');
        }

        $timezone = $request->query('timezone', $request->session()->get('installation.timezone'));
        $timezone = is_string($timezone) && in_array($timezone, SiteDates::zones(), true) ? $timezone : null;
        if ($timezone !== null) {
            $request->session()->put('installation.timezone', $timezone);
        }

        $bundledExtensions = app(BundledExtensions::class)->catalogue();
        $availableDrivers = PDO::getAvailableDrivers();
        $databaseDrivers = [];
        foreach (Requirements::DATABASE_PORTS as $driver => $port) {
            $databaseDrivers[$driver] = ['available' => in_array($driver, $availableDrivers, true), 'port' => $port];
        }

        return view('install.index', [
            'timezone' => $timezone,
            'requirements' => $this->requirements(),
            'installed' => false,
            'games' => app(GameRegistry::class)->all(),
            'bundledExtensions' => $bundledExtensions,
            'bundledPlugins' => collect($bundledExtensions)->where('type', 'plugin')->all(),
            'bundledThemes' => collect($bundledExtensions)->where('type', 'theme')->all(),
            'databaseDrivers' => $databaseDrivers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (app(Installation::class)->installed()) {
            throw new HttpException(403);
        }
        AccountIdentity::normalize($request);
        $data = $request->validate([
            'site_name' => 'required|string|max:120',
            'url' => [
                'required',
                'url:http,https',
                'max:255',
                function ($attribute, $value, $fail) use ($request) {
                    if (!is_string($value) || !NavigationTargets::validUrl($value)) {
                        $request->merge(['url' => '']);
                        $fail(__('validation.url', ['attribute' => __('system.install.website_address')]));
                    }
                },
            ],
            'locale' => ['required', Rule::in(array_keys(config('kraft.locales')))],
            'timezone' => ['sometimes', 'required', Rule::in(SiteDates::zones())],
            'game' => [
                app(GameRegistry::class)->all() ? 'required' : 'nullable',
                Rule::in(array_keys(app(GameRegistry::class)->all())),
            ],
            'database' => ['required', Rule::in(array_keys(Requirements::DATABASE_PORTS))],
            'db_host' => [
                'required_if:database,mysql,pgsql',
                'nullable',
                'string',
                'max:255',
                Rule::when($request->input('database') === 'pgsql', ['regex:/\A[a-z0-9_.:-]+\z/i']),
            ],
            'db_port' => 'nullable|integer|between:1,65535',
            'db_database' => [
                'required_if:database,mysql,pgsql',
                'nullable',
                'string',
                'max:100',
                Rule::when($request->input('database') === 'pgsql', ['regex:/\A[a-z0-9_.-]+\z/i']),
            ],
            'db_username' => [
                'required_if:database,mysql,pgsql',
                'nullable',
                'string',
                'max:100',
                Rule::when($request->input('database') === 'pgsql', ['regex:/\A[a-z0-9_.-]+\z/i']),
            ],
            'db_password' => [
                'nullable',
                'string',
                'max:255',
                Rule::when($request->input('database') === 'pgsql', ['not_regex:/[\x00\r\n]/']),
            ],
            'username' => AccountIdentity::usernameRules(unique: false),
            'email' => 'required|email|max:255',
            'password' => ['required', 'confirmed', app(AuthPolicy::class)->password()],
            'extensions' => 'sometimes|array|max:100',
            'extensions.*' => 'required|string|distinct|regex:/^[a-z][a-z0-9-]{1,49}$/D',
            'theme' => 'nullable|string|regex:/^[a-z][a-z0-9-]{1,49}$/D',
        ]);
        app()->setLocale($data['locale']);
        $extensions = app(BundledExtensions::class);
        $plan = $extensions->plan($data['extensions'] ?? [], $data['theme'] ?? null, $data['game'] ?? null);
        foreach ($this->requirements() as $requirement) {
            if (
                ($requirement['required'] ?? true) &&
                (empty($requirement['scope']) || $requirement['scope'] === $data['database'])
            ) {
                if (!$requirement['passed']) {
                    throw new HttpException(422, $requirement['message']);
                }
            }
        }
        $lock = InstallationFiles::lock(storage_path());
        if (!$lock) {
            throw new HttpException(409);
        }
        $original = null;
        $connected = false;
        $journal = app(InstallationJournal::class);
        try {
            if (app(Installation::class)->installed(true)) {
                throw new HttpException(403);
            }
            $original = File::get(app()->environmentFilePath());
            $driver = $data['database'];
            $host = '127.0.0.1';
            $port = Requirements::DATABASE_PORTS[$driver] ?? 3306;
            $username = '';
            $password = '';
            if ($driver === 'sqlite') {
                $database = database_path('database.sqlite');
                if (!file_exists($database)) {
                    $mask = umask(0077);
                    try {
                        $handle = fopen($database, 'x+b');
                    } finally {
                        umask($mask);
                    }
                    if (!$handle) {
                        throw new RuntimeException('Cannot create the SQLite database file.');
                    }
                    fclose($handle);
                    if (!chmod($database, 0600)) {
                        throw new RuntimeException('Cannot protect the SQLite database file.');
                    }
                }
                config(['database.connections.sqlite.database' => $database]);
            } else {
                $database = $data['db_database'];
                $host = $data['db_host'];
                $port = (int) ($data['db_port'] ?? $port);
                $username = $data['db_username'];
                $password = $data['db_password'] ?? '';
                config([
                    'database.connections.'.$driver.'.host' => $host,
                    'database.connections.'.$driver.'.port' => $port,
                    'database.connections.'.$driver.'.database' => $database,
                    'database.connections.'.$driver.'.username' => $username,
                    'database.connections.'.$driver.'.password' => $password,
                ]);
                if ($driver === 'mysql') {
                    config(['database.connections.mysql.unix_socket' => '']);
                }
            }
            config(['database.default' => $driver, 'database.connections.'.$driver.'.url' => null]);
            DB::purge();
            DB::connection()->getPdo();
            $connected = true;
            $journal->start(DB::connection());
            $existingOwner = null;
            if (DB::connection()->getSchemaBuilder()->hasTable('users') && User::query()->exists()) {
                if (DB::connection()->getSchemaBuilder()->hasTable('roles') && User::query()->count() === 1) {
                    $candidate = User::query()->where('email', strtolower($data['email']))->first();
                    if (
                        $candidate &&
                        password_verify($data['password'], $candidate->password) &&
                        $candidate->roles()->where('slug', 'owner')->exists()
                    ) {
                        $existingOwner = $candidate;
                    }
                }
                if (!$existingOwner) {
                    return back()
                        ->withErrors(['database' => __('system.install.messages.empty_database')])
                        ->withInput($request->except('password', 'password_confirmation', 'db_password'));
                }
            }
            $values = [
                'APP_NAME' => $data['site_name'],
                'APP_URL' => $data['url'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => false,
                'APP_LOCALE' => $data['locale'],
                'DB_CONNECTION' => $driver,
                'DB_URL' => '',
                'DB_SOCKET' => '',
                'DB_DATABASE' => $database,
                'DB_HOST' => $host,
                'DB_PORT' => $port,
                'DB_USERNAME' => $username,
                'DB_PASSWORD' => $password,
                'SESSION_COOKIE' => $request->session()->getName(),
                'SESSION_DRIVER' => 'file',
                'CACHE_STORE' => 'file',
            ];
            $env = app(EnvironmentFile::class)->render($original, $values);
            File::replace(app()->environmentFilePath(), $env, 0600);
            $migrate = function () {
                if (
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => [base_path('database/migrations')],
                        '--realpath' => true,
                    ]) !== 0
                ) {
                    throw new RuntimeException(Artisan::output());
                }
            };
            in_array($driver, ['sqlite', 'pgsql'], true) ? DB::transaction($migrate) : $migrate();
            app(BuiltinSynchronizer::class)->synchronize();
            DB::transaction(function () use ($data, $existingOwner) {
                $game = $data['game'] ?? null;
                $avatarProviders = app(AvatarProviders::class);
                $availableAvatars = $game ? $avatarProviders->forGame($game) : [];
                $defaultAvatar = $game ? $avatarProviders->defaultId($game) : null;
                $avatarProvider = isset($availableAvatars[$defaultAvatar])
                    ? $defaultAvatar
                    : array_key_first($availableAvatars);
                $owner = BuiltinRoles::create()['owner'];
                $user =
                    $existingOwner ??
                    User::query()->create([
                        'username' => $data['username'],
                        'email' => strtolower($data['email']),
                        'password' => $data['password'],
                        'locale' => $data['locale'],
                    ]);
                $user->forceFill(['email_verified_at' => now()])->save();
                $user->roles()->syncWithoutDetaching([$owner->id]);
                foreach (
                    [
                        'site.name' => $data['site_name'],
                        'site.url' => $data['url'],
                        'site.locale' => $data['locale'],
                        'locale.user_choice' => false,
                        'theme' => null,
                        'seo.description' => '',
                        'registration.enabled' => true,
                        'avatars.allow_uploads' => $avatarProvider === null,
                    ] as $key => $value
                ) {
                    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
                }
                if (!empty($data['game'])) {
                    Setting::query()->updateOrCreate(['key' => 'games.default'], ['value' => $data['game']]);
                    Setting::query()->updateOrCreate(
                        ['key' => 'avatars.providers.'.$game],
                        ['value' => $avatarProvider],
                    );
                }
                Setting::query()->updateOrCreate(['key' => 'site.timezone'], ['value' => $data['timezone'] ?? 'UTC']);
                app(StarterContent::class)->create($user, $data['locale']);
            });
            Artisan::call('config:clear');
            $journal->ready();
            app(Installation::class)->complete(config('manifest.version'));
        } catch (Throwable $exception) {
            if ($original !== null) {
                File::replace(app()->environmentFilePath(), $original, 0600);
            }
            report($exception);

            return back()
                ->withErrors([
                    'database' => __(
                        app(DatabaseFailure::class)->translationKey($exception, $data['database'], $connected),
                    ),
                ])
                ->withInput($request->except('password', 'password_confirmation', 'db_password'));
        } finally {
            $journal->release();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        app(Settings::class)->refresh();
        app(InstallationReceipts::class)->refresh();
        $user = User::query()->where('email', strtolower($data['email']))->firstOrFail();
        $request->session()->forget(['auth.pending', 'auth.identity_pending', 'password_hash_web', 'url.intended']);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.version', (int) $user->auth_session_version);
        $request->session()->put('auth.confirmed_at', time());
        Audit::record('installation.completed', [
            'extensions' => array_column($plan, 'id'),
            'import' => $request->boolean('import_after_install'),
        ]);
        $destination = $request->boolean('import_after_install') ? '/admin/imports?installation=1' : '/admin';
        if ($plan === []) {
            return redirect($destination)->with('status', __('system.install.messages.completed'));
        }
        $extensions->reserveProgress($plan);
        $request->session()->put('installation.result', ['plan' => $plan, 'destination' => $destination]);
        $extensions->install($plan);

        return redirect()->route('install.finish');
    }

    public function finish(Request $request, BundledExtensions $extensions): RedirectResponse
    {
        if (!app(Installation::class)->installed()) {
            throw new NotFoundHttpException();
        }
        $result = $request->session()->pull('installation.result');
        if (
            !is_array($result) ||
            !in_array($result['destination'] ?? null, ['/admin', '/admin/imports?installation=1'], true) ||
            !is_array($result['plan'] ?? null)
        ) {
            return redirect('/login');
        }
        $failed = $extensions->incomplete($result['plan']);
        $response = redirect($result['destination'])->with('status', __('system.install.messages.completed'));

        return $failed
            ? $response->with('warning', __('system.install.extensions.failed', ['names' => implode(', ', $failed)]))
            : $response;
    }

    private function requirements(): array
    {
        return app(Requirements::class)->check();
    }
}
