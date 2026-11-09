<?php

namespace App\Http\Controllers\Admin\System;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Models\Imports\ImportRun;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Auth\AuthPolicy;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Migration\ImportManager;
use App\Kraft\Migration\ImportExtensionCatalogue;
use App\Kraft\Migration\ImportReport;
use App\Kraft\Setup\BundledExtensions;
use App\Exceptions\OperationBusy;
use App\Kraft\System\ListingPagination;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Pdo\Sqlite;
use Throwable;
use JsonException;

final class AdminImportController extends Controller
{
    public function index(Request $request, ImportManager $manager): View
    {
        $pending = $request->session()->get('imports.pending_review');
        if (is_array($pending)) {
            try {
                $source = json_decode(
                    Crypt::decryptString($request->session()->get('imports.source', '')),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $this->connect($source['connection']);
                $plan = $manager->analyze($source['source'], 'legacy_import', $pending['options']);
                $request->session()->put('imports.plan', $plan);
                $request->session()->put('imports.approved_at', time());
                $request->session()->forget('imports.pending_review');
                Audit::record('import.analyzed', [
                    'source' => $source['source'],
                    'mode' => $pending['options']['mode'],
                    'tables' => count($plan['tables']),
                    'after_extension_installation' => true,
                ]);
            } catch (OperationBusy $exception) {
                throw $exception;
            } catch (Throwable) {
                $request->session()->forget(['imports.pending_review', 'imports.plan', 'imports.approved_at']);
                $request->session()->flash('error', __('system.imports.connection_failed'));
            } finally {
                DB::purge('legacy_import');
            }
        }
        $installation = $request->boolean('installation') || $request->session()->get('imports.installation', false);

        $runs = ImportRun::query()->latest('created_at')->limit(15)->get();
        if (!$manager->isRunning()) {
            /** @var ImportRun $run */
            foreach ($runs as $run) {
                if (in_array($run->status, ['prepared', 'snapshotting', 'mapping'], true)) {
                    $run->status = 'interrupted';
                }
            }
        }

        return view($installation ? 'install.imports' : 'admin.imports.index', [
            'plan' => $request->session()->get('imports.plan'),
            'runs' => $runs,
            'installation' => $installation,
            'catalogueOutcome' => $request->session()->get('imports.extension_catalogue'),
        ]);
    }

    /**
     * @throws JsonException
     */
    public function analyze(Request $request, ImportManager $manager, ExtensionManager $extensions): RedirectResponse
    {
        $sourcePassword = (string) $request->input('source_password', '');
        $request->offsetUnset('source_password');
        if (strlen($sourcePassword) > 1024) {
            throw ValidationException::withMessages(['source_password' => __('system.imports.password_too_long')]);
        }
        $installPlugins = $request->boolean('install_plugins');
        if ($installPlugins && !$request->user()->hasPermission('extensions.manage')) {
            throw new HttpException(403);
        }
        if ($installPlugins) {
            app(SensitiveAction::class)->confirm($request);
        } else {
            app(AuthPolicy::class)->confirmCredentials($request);
        }
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(config('imports.adapters')))],
            'driver' => ['required', Rule::in(['mysql', 'pgsql', 'sqlite'])],
            'host' => 'nullable|string|max:253|regex:/^[a-zA-Z0-9.:-]+$/D',
            'port' => 'nullable|integer|between:1,65535',
            'database' => 'required|string|max:1024',
            'username' => 'nullable|string|max:128',
            'prefix' => 'nullable|string|max:40|regex:/^[a-zA-Z0-9_]*$/D',
            'mode' => ['required', Rule::in(['merge', 'replace'])],
            'existing_users' => ['required', Rule::in(['preserve', 'reject'])],
            'installation' => 'boolean',
            'install_plugins' => 'boolean',
        ]);
        $connection = $this->sourceConfiguration($data, $sourcePassword);
        $request
            ->session()
            ->put(
                'imports.source',
                Crypt::encryptString(
                    json_encode(['source' => $data['source'], 'connection' => $connection], JSON_THROW_ON_ERROR),
                ),
            );
        $request->session()->put('imports.installation', $request->boolean('installation'));
        $this->connect($connection);
        $bundlePlan = [];
        $cataloguePlan = [];
        $request->session()->forget('imports.extension_catalogue');
        try {
            $options = ['mode' => $data['mode'], 'existing_users' => $data['existing_users']];
            $plan = $manager->analyze($data['source'], 'legacy_import', $options);
            if ($request->boolean('install_plugins') && $plan['errors'] === []) {
                $discovery = app(ImportExtensionCatalogue::class)->discover(
                    $data['source'],
                    DB::connection('legacy_import'),
                    $plan['tables'],
                );
                $cataloguePlan = $discovery['candidates'];
                $request->session()->put('imports.extension_catalogue', $discovery);
                $available = $extensions->all();
                $plugins = [];
                foreach ($plan['required_plugins'] as $plugin) {
                    if (
                        isset($available[$plugin]) &&
                        $available[$plugin]['type'] === 'plugin' &&
                        !$available[$plugin]['enabled']
                    ) {
                        $plugins[] = $plugin;
                    }
                }
                $theme = $plan['extensions']['theme'] ?? null;
                if ($plugins !== [] || $theme !== null) {
                    $bundles = app(BundledExtensions::class);
                    $bundlePlan = $bundles->plan($plugins, $theme, app(GameRegistry::class)->activeId());
                    $bundles->reserveProgress($bundlePlan);
                    $request->session()->put('imports.pending_review', ['options' => $options]);
                }
                if ($cataloguePlan !== []) {
                    $request->session()->put('imports.pending_review', ['options' => $options]);
                }
            }
            $request->session()->put('imports.plan', $plan);
            $request->session()->put('imports.approved_at', time());
            Audit::record('import.analyzed', [
                'source' => $data['source'],
                'mode' => $data['mode'],
                'tables' => count($plan['tables']),
            ]);
        } catch (ValidationException $exception) {
            $request->session()->forget('imports.plan');
            throw $exception;
        } catch (OperationBusy $exception) {
            throw $exception;
        } catch (Throwable) {
            $request->session()->forget('imports.plan');
            throw ValidationException::withMessages(['database' => __('system.imports.connection_failed')]);
        } finally {
            DB::purge('legacy_import');
        }
        if ($bundlePlan !== []) {
            app(BundledExtensions::class)->install($bundlePlan);
        }
        if ($cataloguePlan !== []) {
            $outcome = app(ImportExtensionCatalogue::class)->install($cataloguePlan);
            $request->session()->put('imports.extension_catalogue.result', $outcome);
            if ($outcome['failed'] !== []) {
                $request->session()->flash('error', __('migration.catalogue_error'));
            }
        }

        return redirect()->route('admin.imports.index', ['installation' => $request->boolean('installation') ? 1 : 0]);
    }

    /**
     * @throws JsonException
     */
    public function execute(Request $request, ImportManager $manager): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'confirmed' => 'accepted',
            'hash' => 'nullable|string|size:64',
            'resume' => 'nullable|uuid',
            'selection' => 'boolean',
            'tables' => 'array',
            'tables.*' => 'string|max:100',
        ]);
        $encrypted = $request->session()->get('imports.source');
        if (!$encrypted || $request->session()->get('imports.approved_at', 0) < time() - 1800) {
            throw ValidationException::withMessages(['database' => __('system.imports.reconnect')]);
        }
        $source = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        $plan = $request->session()->get('imports.plan');
        if (!empty($data['resume'])) {
            $run = ImportRun::query()->where('id', $data['resume'])->first();
            if ($run === null) {
                abort(404);
            }
            $plan = $run->plan;
        } elseif (!$plan || !hash_equals($plan['hash'], $data['hash'] ?? '')) {
            throw ValidationException::withMessages(['hash' => __('system.imports.preview_changed')]);
        }
        $this->connect($source['connection']);
        try {
            if (empty($data['resume'])) {
                $current = $manager->analyze($source['source'], 'legacy_import', $plan['options']);
                if (!hash_equals($current['hash'], $plan['hash'])) {
                    throw ValidationException::withMessages(['hash' => __('system.imports.preview_changed')]);
                }
                if ($request->boolean('selection')) {
                    $plan = $manager->analyze(
                        $source['source'],
                        'legacy_import',
                        array_merge($plan['options'], ['tables' => $data['tables'] ?? []]),
                    );
                }
                if ($plan['errors'] !== []) {
                    $request->session()->put('imports.plan', $plan);
                    throw ValidationException::withMessages(['import' => implode(' ', $plan['errors'])]);
                }
            }

            $report = $manager->execute(
                $source['source'],
                'legacy_import',
                $plan['hash'],
                $data['resume'] ?? null,
                options: $plan['options'],
            );
        } catch (ValidationException|OperationBusy $exception) {
            throw $exception;
        } catch (Throwable) {
            $latest = ImportRun::query()->where('plan->hash', $plan['hash'])->latest('created_at')->value('id');
            throw ValidationException::withMessages([
                'import' => __('system.imports.interrupted').' '.($latest ?? ''),
            ]);
        } finally {
            DB::purge('legacy_import');
        }
        Audit::record('import.executed', ['id' => $report['id'], 'source' => $source['source']]);
        $request->session()->forget('imports.plan');
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'data' => ['id' => $report['id'], 'report' => $report]]);
        }

        return redirect()->route('admin.imports.index')->with('status', __('system.imports.completed'));
    }

    public function progress(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => 'nullable|uuid', 'hash' => 'nullable|string|size:64']);
        $query = ImportRun::query();
        if (!empty($data['id'])) {
            $query->where('id', $data['id']);
        } elseif (!empty($data['hash'])) {
            $query->where('plan->hash', $data['hash']);
        } else {
            throw new HttpException(422);
        }
        $run = $query->latest('created_at')->first();
        if ($run === null) {
            return response()->json(['success' => true, 'data' => ['status' => 'waiting']]);
        }
        $progressPath =
            config('imports.directory').DIRECTORY_SEPARATOR.$run->id.DIRECTORY_SEPARATOR.'progress.json';
        $live = is_file($progressPath) ? json_decode(file_get_contents($progressPath), true) : null;

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'id' => $run->id,
                    'status' => $run->status === 'failed' ? 'failed' : $live['status'] ?? $run->status,
                    'report' => $live['report'] ?? ($run->report ?? []),
                    'error' => $run->error
                        ? __($run->error === 'imports.interrupted' ? 'system.imports.interrupted' : $run->error)
                        : null,
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }

    public function report(Request $request, string $id, ImportReport $presenter): View
    {
        $run = ImportRun::query()->where('id', $id)->first();
        if ($run === null) {
            abort(404);
        }
        $plan = $run->plan;
        $report = $run->report ?? [];
        $pagination = app(ListingPagination::class);
        $notices = TableQuery::paginate(
            $report['notices'] ?? [],
            ['table', 'path'],
            pageName: 'notices_page',
            search: ['table', 'plugin', 'field', 'path', 'name'],
        );
        $notices->setCollection(
            collect($presenter->destinations(['notices' => $notices->items()], $run->id)['notices']),
        );

        return view('admin.imports.report', [
            'run' => $run,
            'plan' => $plan,
            'report' => $report,
            'tables' => TableQuery::paginate(
                collect($plan['tables'] ?? [])->map(fn ($details, $name) => ['name' => $name] + $details),
                ['name', 'count'],
                pageName: 'tables_page',
                search: ['name'],
            ),
            'notices' => $notices,
            'unavailableExtensions' => $pagination->paginate(
                $report['extensions']['unavailable'] ?? [],
                $request,
                'unavailable_page',
            ),
            'presenter' => $presenter,
        ]);
    }

    public function downloadReport(string $id): JsonResponse
    {
        $run = ImportRun::query()->where('id', $id)->first();
        if ($run === null) {
            abort(404);
        }

        return response()
            ->json([
                'id' => $run->id,
                'source' => $run->source,
                'status' => $run->status,
                'report' => app(ImportReport::class)->destinations($run->report ?? [], $run->id),
            ])
            ->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="kraftink-import-'.$run->id.'.json"');
    }

    public function finish(Request $request): RedirectResponse
    {
        $request
            ->session()
            ->forget([
                'imports.source',
                'imports.plan',
                'imports.approved_at',
                'imports.installation',
                'imports.pending_review',
                'imports.extension_catalogue',
            ]);

        return redirect('/admin');
    }

    private function connect(array $configuration): void
    {
        DB::purge('legacy_import');
        config(['database.connections.legacy_import' => $configuration]);
    }

    private function sourceConfiguration(array $data, string $password): array
    {
        $configuration = [
            'driver' => $data['driver'],
            'database' => $data['database'],
            'prefix' => $data['prefix'] ?? '',
            'password' => $password,
        ];
        if ($data['driver'] === 'sqlite') {
            $path = realpath($data['database']);
            if ($path === false || !is_file($path)) {
                throw ValidationException::withMessages(['database' => __('system.imports.sqlite_required')]);
            }
            $configuration['database'] = $path;
            $configuration['options'] = [Sqlite::ATTR_OPEN_FLAGS => Sqlite::OPEN_READONLY];
            $configuration['foreign_key_constraints'] = false;

            return $configuration;
        }
        if (!preg_match('/^[a-zA-Z0-9_$.-]+$/D', $data['database'])) {
            throw ValidationException::withMessages(['database' => __('system.imports.database_invalid')]);
        }
        $configuration += [
            'host' => $data['host'] ?? '127.0.0.1',
            'port' => $data['port'] ?? ($data['driver'] === 'pgsql' ? 5432 : 3306),
            'username' => $data['username'] ?? '',
            'charset' => $data['driver'] === 'pgsql' ? 'utf8' : 'utf8mb4',
        ];
        if ($data['driver'] === 'mysql') {
            $configuration['collation'] = 'utf8mb4_unicode_ci';
        }

        return $configuration;
    }
}
