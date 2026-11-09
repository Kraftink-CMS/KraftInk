<?php

namespace App\Http\Controllers\Admin\System;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Trash\TrashRegistry;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Media\FileDeletionQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrashController extends Controller
{
    public function index(Request $request, TrashRegistry $registry): View
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
            'source' => 'nullable|string|max:80',
        ]);
        $sources = $registry->sources($request->user());

        return view('admin.trash.index', [
            'items' => TableQuery::paginate(
                $registry->query($request->user(), $data['source'] ?? null, $data['q'] ?? null),
                ['title', 'source', 'deleted_at'],
            ),
            'sources' => $sources,
            'purgeableCount' => $registry->purgeableQuery($request->user())->count(),
        ]);
    }

    public function restore(Request $request, TrashRegistry $registry, string $source, string $item): RedirectResponse
    {
        $registry->restore($request->user(), $source, $item);

        return redirect()
            ->route('admin.trash.index', ['source' => $source])
            ->with('status', __('trash.restored'));
    }

    public function purge(Request $request, TrashRegistry $registry, string $source, string $item): RedirectResponse
    {
        $definition = $registry->source($source, $request->user()) ?? throw new NotFoundHttpException();
        if (!$definition->canPurge($request->user())) {
            throw new HttpException(403);
        }
        $this->confirm($request);
        $registry->purge($request->user(), $source, $item);

        $response = redirect()
            ->route('admin.trash.index', ['source' => $source])
            ->with('status', __('trash.purged'));
        if (app(FileDeletionQueue::class)->hasDeferred()) {
            $response->with('warning', __('trash.files_deferred'));
        }

        return $response;
    }

    public function empty(Request $request, TrashRegistry $registry): RedirectResponse
    {
        if ($registry->purgeableSources($request->user()) === []) {
            throw new HttpException(403);
        }
        $this->confirm($request);
        $result = $registry->empty($request->user());
        $response = redirect()
            ->route('admin.trash.index')
            ->with('status', __('trash.emptied', ['count' => $result->purged]));
        if ($result->failed) {
            $response->with('warning', __('trash.empty_incomplete', ['count' => $result->failed]));
        }
        if (app(FileDeletionQueue::class)->hasDeferred()) {
            $response->with(
                'warning',
                array_values(
                    array_filter([
                        $result->failed ? __('trash.empty_incomplete', ['count' => $result->failed]) : null,
                        __('trash.files_deferred'),
                    ]),
                ),
            );
        }

        return $response;
    }

    private function confirm(Request $request): void
    {
        $request->validate(['confirmed' => 'accepted']);

        app(SensitiveAction::class)->confirm($request);
    }
}
