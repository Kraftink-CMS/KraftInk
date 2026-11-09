<?php

namespace App\Http\Controllers\Extensions;

use App\Exceptions\MarketRequestFailed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Extensions\ExtensionInstaller;
use App\Kraft\Extensions\ExtensionCatalogue;
use App\Kraft\Extensions\ExtensionManager;
use App\Kraft\Extensions\InstallationReceipts;
use App\Kraft\Extensions\Market;
use App\Kraft\Extensions\PackageOperations;
use App\Kraft\Extensions\PackageRetirement;
use App\Kraft\Extensions\PackageUninstaller;
use App\Kraft\System\SensitiveAction;
use App\Kraft\System\ListingPagination;
use App\Kraft\Updates\UpdateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;
use RuntimeException;
use JsonException;
use Random\RandomException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class ExtensionOperationController extends Controller
{
    public function plugins(ExtensionManager $manager): View
    {
        return $this->listing($manager, 'plugin');
    }

    public function themes(ExtensionManager $manager): View
    {
        return $this->listing($manager, 'theme');
    }

    private function listing(ExtensionManager $manager, string $type): View
    {
        $extensions = array_filter($manager->all(), fn ($extension) => $extension['type'] === $type);
        $catalogue = request()->boolean('catalogue') ? app(ExtensionCatalogue::class)->for($type) : null;
        if ($catalogue !== null) {
            $catalogue['items'] = app(ListingPagination::class)
                ->paginate($catalogue['items'], request(), 'catalogue_page')
                ->appends(['catalogue' => 1]);
        }

        return view('admin.extensions.index', [
            'type' => $type,
            'extensions' => $extensions,
            'catalogue' => $catalogue,
            'retired' => app(ListingPagination::class)
                ->paginate(
                    collect(app(PackageRetirement::class)->all())->where('type', $type)->sortBy('name'),
                    request(),
                    'retired_page',
                )
                ->fragment('retired-extensions'),
            'manager' => $manager,
            'selectedThemes' => $manager->selectedThemes(),
            'diagnostics' => array_intersect_key($manager->diagnostics(), $extensions),
        ]);
    }

    public function retire(Request $request, PackageRetirement $packages, string $id): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $request->validate(['confirm' => 'required|accepted']);

        $installed = isset(app(InstallationReceipts::class)->all()[$id]);
        $packages->retire($id);
        Audit::record($installed ? 'extension.retired' : 'extension.files_deleted', ['id' => $id]);

        return back()->with('status', __($installed ? 'system.extensions.retired' : 'system.extensions.files_deleted'));
    }

    public function restore(Request $request, PackageRetirement $packages, string $id): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $packages->restore($id);
        Audit::record('extension.restored', ['id' => $id]);

        return back()->with('status', __('system.extensions.restored'));
    }

    public function uninstall(Request $request, PackageUninstaller $packages, string $id): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $request->validate(['confirm' => 'required|accepted']);

        $run = $packages->stage($id);
        Audit::record('extension.uninstall_prepared', ['id' => $id, 'run' => $run]);

        return redirect()->route('admin.extensions.operations.show', $run);
    }

    public function selectTheme(Request $request, ExtensionManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'theme' => ['nullable', 'regex:/^[a-z][a-z0-9-]{1,49}$/D'],
            'target' => ['required', 'string', 'in:site,admin'],
        ]);
        $target = $data['target'];
        $manager->selectTheme($data['theme'] ?? null, $target);
        Audit::record('extension.theme_selected', ['id' => $data['theme'] ?? null, 'target' => $target]);

        return redirect()
            ->route('admin.extensions.themes.index')
            ->with(
                'status',
                __($target === 'admin' ? 'system.extensions.admin_theme_selected' : 'system.extensions.theme_selected'),
            );
    }

    public function prepare(
        Request $request,
        PackageOperations $packages,
        ExtensionInstaller $installer,
    ): RedirectResponse|JsonResponse {
        if ($request->input('action') === 'refresh_market') {
            $data = $request->validate([
                'catalogue_source' => ['nullable', 'regex:/^[a-z][a-z0-9-]{0,49}$/D'],
            ]);
            app(Market::class)->refresh($data['catalogue_source'] ?? null);

            return back()->with('status', __('system.extensions.market_refreshed'));
        }
        $data = $request->validate([
            'archive' => 'nullable|file|mimes:zip|max:51200',
            'extension_id' => ['nullable', 'regex:/^[a-z][a-z0-9-]{1,49}$/D'],
            'catalogue_id' => 'nullable|string|max:101',
            'catalogue_version' => 'nullable|string|max:80',
            'catalogue_source' => ['nullable', 'required_with:catalogue_id', 'regex:/^[a-z][a-z0-9-]{0,49}$/D'],
        ]);
        if (
            (int) $request->hasFile('archive') +
                (int) $request->filled('extension_id') +
                (int) $request->filled('catalogue_id') !==
            1
        ) {
            throw ValidationException::withMessages(['extension' => __('system.extensions.install_source_required')]);
        }
        try {
            if ($request->hasFile('archive')) {
                $id = $packages->archive($request->file('archive')->getRealPath());
            } elseif ($request->filled('catalogue_id')) {
                $id = $packages->market(
                    $data['catalogue_id'],
                    $data['catalogue_version'] ?? null,
                    $data['catalogue_source'],
                );
            } else {
                $id = $installer->stage($data['extension_id']);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            $messages = array_filter(__('system.extensions'), 'is_string');
            throw ValidationException::withMessages([
                'extension' => $exception instanceof MarketRequestFailed || in_array($exception->getMessage(), $messages, true)
                    ? $exception->getMessage()
                    : __('system.extensions.update_failed'),
            ]);
        }
        Audit::record('extension.operation_prepared', ['run' => $id]);

        return $request->expectsJson()
            ? response()->json([
                'success' => true,
                'data' => ['redirect_url' => route('admin.extensions.operations.show', $id)],
            ])
            : redirect()->route('admin.extensions.operations.show', $id);
    }

    /**
     * @throws JsonException
     * @throws FileNotFoundException
     */
    public function show(UpdateManager $updates, string $id): View
    {
        $run = $updates->status($id);
        if (!isset($run['extension_id'])) {
            throw new NotFoundHttpException();
        }
        $run['run_url'] = route('admin.extensions.operations.run', $id);

        return view('admin.extensions.operation', ['run' => $run]);
    }

    /**
     * @throws JsonException
     * @throws FileNotFoundException
     * @throws RandomException
     */
    public function run(Request $request, UpdateManager $updates, string $id): JsonResponse|RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $status = $updates->status($id);
        if (!isset($status['extension_id'])) {
            throw new HttpException(403);
        }
        $recover = $request->boolean('recover');
        if (
            !($recover
                ? in_array(
                    $status['state'],
                    ['recovery_required', 'rolling_back', 'backing_up', 'applying', 'migrating', 'verifying'],
                    true,
                )
                : $status['state'] === 'staged')
        ) {
            throw new HttpException(409);
        }
        ignore_user_abort(true);
        set_time_limit(0);
        Audit::record($recover ? 'extension.recovery_started' : 'extension.operation_started', ['run' => $id]);
        $process = $updates->run($id, $recover);
        app(InstallationReceipts::class)->refresh();

        if ($request->expectsJson()) {
            $result = $updates->status($id);
            if ($process->isSuccessful()) {
                return response()->json(['success' => true, 'data' => $result]);
            }

            return response()->json(['success' => false, 'data' => $result], 422);
        }

        return redirect()
            ->route('admin.extensions.operations.show', $id)
            ->with(
                $process->isSuccessful() ? 'status' : 'error',
                __($process->isSuccessful() ? 'system.updates.finished' : 'system.updates.failure'),
            );
    }

    public function toggle(Request $request, ExtensionManager $manager, string $id): RedirectResponse
    {
        $request->validate(['enabled' => 'required|boolean']);

        try {
            $manager->toggle($id, $request->boolean('enabled'));
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['extension' => $error->getMessage()]);
        }
        Audit::record('extension.toggled', ['id' => $id, 'enabled' => $request->boolean('enabled')]);

        return back()->with(
            'status',
            __($request->boolean('enabled') ? 'system.extensions.activated' : 'system.extensions.deactivated'),
        );
    }
}
