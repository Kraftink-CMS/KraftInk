<?php

namespace App\Http\Controllers\Admin\System;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Settings;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Updates\GitHubReleaseRepository;
use App\Kraft\Updates\KraftLinkUpdates;
use App\Kraft\Updates\UpdateManager;
use App\Kraft\Updates\UpdateLabels;
use App\Kraft\Updates\UpdateAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;
use App\Kraft\Updates\ReleaseVersion;
use App\Kraft\Updates\UpdateChannel;

class UpdateController extends Controller
{
    public function index(KraftLinkUpdates $kraftLink): View
    {
        $path = storage_path('app/updates');
        $journals = glob($path.'/*/journal.json') ?: [];
        rsort($journals, SORT_STRING);
        $history = [];
        $historyLabels = app(UpdateLabels::class)->all();
        foreach ($journals as $journal) {
            $data = json_decode(file_get_contents($journal), true);
            if (
                !is_array($data) ||
                !is_string($data['id'] ?? null) ||
                !in_array($data['state'] ?? '', ['completed', 'rolled_back', 'failed'], true)
            ) {
                continue;
            }
            $run = array_intersect_key($data, array_flip(['id', 'state', 'progress', 'version', 'created_at']));
            if (!empty($data['extension']['name'])) {
                $run['extension_id'] = $data['extension']['id'];
                $run['operation'] = $data['extension']['operation'] ?? 'update';
                $run['subject'] = __($data['extension']['name']).' · '.($run['version'] ?? '');
            }

            $run['operation'] ??= 'update';
            $run['status_label'] =
                ($historyLabels[$run['operation']] ?? $historyLabels['update'])['states'][$run['state']] ??
                __('system.updates.unknown');
            $run['status_color'] =
                ['completed' => 'success', 'rolled_back' => 'warning', 'failed' => 'danger'][$run['state']] ??
                'secondary';
            $history[] = $run;
        }
        $runs = TableQuery::paginate(
            $history,
            ['subject', 'operation', 'state', 'created_at'],
            pageName: 'history_page',
            search: ['subject', 'id', 'version'],
        )->fragment('update-history');

        return view('admin.updates.index', [
            'runs' => $runs,
            'version' => config('manifest.version'),
            'settings' => app(Settings::class)->all(),
            'release' => app(GitHubReleaseRepository::class)->snapshot(),
            'updateChannel' => app(UpdateChannel::class)->current(),
            'versionChannel' => ReleaseVersion::channel(config('manifest.version')) ?? 'development',
            'kraftLinkUpdatesSupported' => $kraftLink->supportsActiveGame(),
            'kraftLinkNotificationsEnabled' => $kraftLink->enabled(),
            'kraftLinkIgnoredVersion' => $kraftLink->ignoredVersion(),
        ]);
    }

    public function availability(Request $request, UpdateAvailability $availability): JsonResponse
    {
        $user = $request->user();
        if (!($user->hasPermission('updates.manage') || $user->hasPermission('extensions.manage'))) {
            throw new HttpException(403);
        }
        ignore_user_abort(true);

        return response()
            ->json(['success' => true, 'data' => $availability->forUser($user)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function channel(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate(['channel' => 'required|string|in:stable,beta,alpha']);
        $settings->set('updates.channel', $data['channel']);

        return back()->with('status', __('system.updates.channel_saved'));
    }

    public function kraftLinkPreferences(Request $request, KraftLinkUpdates $kraftLink): RedirectResponse
    {
        abort_unless($kraftLink->supportsActiveGame(), 403);
        $data = $request->validate(['enabled' => 'required|boolean']);
        $kraftLink->setEnabled((bool) $data['enabled']);

        return back()->with('status', __('system.updates.kraftlink.saved'));
    }

    public function dismissKraftLink(
        Request $request,
        KraftLinkUpdates $kraftLink,
        UpdateAvailability $availability,
    ): JsonResponse {
        abort_unless($kraftLink->canNotify(), 403);
        $data = $request->validate(['version' => 'required|string|max:40']);
        abort_unless(ReleaseVersion::channel($data['version']) === 'stable', 422);
        abort_unless($availability->dismissKraftLink($data['version']), 409);

        return response()->json(['success' => true]);
    }

    public function check(GitHubReleaseRepository $repository): RedirectResponse
    {
        try {
            $repository->check(true);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['release' => __('system.updates.check_failed')]);
        }

        return back()->with('status', __('system.updates.checked'));
    }

    public function prepare(
        Request $request,
        GitHubReleaseRepository $repository,
        UpdateManager $manager,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate(['version' => 'required|string|max:40']);
        $archive = null;
        try {
            $archive = $repository->download($data['version']);
            $id = $manager->stage($archive, $data['version']);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['release' => __('system.updates.stage_failed')]);
        } finally {
            if ($archive) {
                File::delete($archive);
            }
        }
        Audit::record('update.staged', ['id' => $id, 'source' => $repository->repository()]);

        return $this->prepared($request, $manager, $id);
    }

    public function stage(Request $request, UpdateManager $manager): RedirectResponse|JsonResponse
    {
        $request->validate(['archive' => 'required|file|mimes:zip|max:524288']);

        try {
            $id = $manager->stage($request->file('archive')->getRealPath());
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['archive' => __('system.updates.stage_failed')]);
        }
        Audit::record('update.staged', ['id' => $id]);

        return $this->prepared($request, $manager, $id);
    }

    public function run(Request $request, UpdateManager $manager, string $id): JsonResponse|RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        $status = $manager->status($id);
        if (isset($status['extension_id'])) {
            throw new HttpException(403);
        }
        if ($status['state'] !== 'staged') {
            throw new HttpException(409);
        }
        ignore_user_abort(true);
        set_time_limit(0);
        Audit::record('update.started', ['id' => $id]);
        $process = $manager->run($id);
        if ($request->expectsJson()) {
            $result = $manager->status($id);
            if ($process->isSuccessful()) {
                return response()->json(['success' => true, 'data' => $result]);
            }

            return response()->json(['success' => false, 'data' => $result], 422);
        }

        return redirect('/admin/updates')->with(
            $process->isSuccessful() ? 'status' : 'error',
            __($process->isSuccessful() ? 'system.updates.finished' : 'system.updates.failure'),
        );
    }

    private function prepared(Request $request, UpdateManager $manager, string $id): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $manager->status($id) + [
                    'run_url' => route('admin.updates.run', ['id' => $id]),
                    'progress_url' => asset('update-status.php').'?'.http_build_query(['run' => $id]),
                    'token' => $request->session()->get('updates.tokens.'.$id),
                ],
            ]);
        }

        return back()->with('status', __('system.updates.staged'));
    }
}
