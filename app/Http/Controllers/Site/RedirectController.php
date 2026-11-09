<?php

namespace App\Http\Controllers\Site;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\Redirects;
use App\Kraft\Settings;
use App\Models\Site\SiteRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RedirectController extends Controller
{
    public function index(Request $request): View
    {
        $request->query->set('tab', 'redirects');
        if ($request->query->has('edit')) {
            $request->query->set('redirect', $request->query('edit'));
            $request->query->remove('edit');
        }

        return app(SeoController::class)->index($request, app(Settings::class));
    }

    public function data(Request $request): array
    {
        $query = SiteRedirect::query()->orderBy('source');
        if ($request->filled('q')) {
            TableQuery::whereContains($query, ['source'], $request->string('q')->limit(100)->toString());
        }

        return [
            'redirects' => TableQuery::paginate($query, ['source', 'destination', 'status', 'enabled'])
                ->withPath(route('admin.seo.index'))
                ->appends(['tab' => 'redirects', 'q' => $request->query('q')]),
            'editing' => $request->filled('redirect')
                ? SiteRedirect::query()->findOrFail($request->integer('redirect'))
                : null,
        ];
    }

    public function store(Request $request, Redirects $redirects, ?SiteRedirect $redirect = null): RedirectResponse
    {
        $source = is_string($request->input('source')) ? $redirects->source($request->input('source')) : null;
        $request->merge(['source' => $source ?? '']);
        if ($source === null) {
            throw ValidationException::withMessages(['source' => __('admin.redirects.invalid_source')]);
        }
        $data = $request->validate([
            'source' => 'required|string|max:500',
            'destination' => 'required|string|max:1000',
            'status' => ['required', Rule::in([301, 302])],
            'enabled' => 'required|boolean',
        ]);
        if (!$redirects->validDestination($data['destination'])) {
            throw ValidationException::withMessages(['destination' => __('admin.redirects.invalid_destination')]);
        }
        $validator = validator(
            ['source' => $source],
            ['source' => Rule::unique('site_redirects')->ignore($redirect?->id)],
        );
        $validator->validate();

        $data['source'] = $source;
        $data['automatic'] = false;
        DB::transaction(function () use ($data, $redirects, $redirect) {
            $redirects->assertNoLoop($data['source'], $data['destination'], $redirect?->id);
            $redirect ? $redirect->update($data) : SiteRedirect::query()->create($data);
        }, 3);
        Audit::record('redirects.saved', ['source' => $source, 'status' => $data['status']]);

        return redirect()
            ->route('admin.seo.index', ['tab' => 'redirects'])
            ->with('status', __('admin.redirects.redirect_saved'));
    }

    public function destroy(SiteRedirect $redirect): RedirectResponse
    {
        $source = $redirect->source;
        $redirect->delete();
        Audit::record('redirects.deleted', ['source' => $source]);

        return redirect()
            ->route('admin.seo.index', ['tab' => 'redirects'])
            ->with('status', __('admin.redirects.redirect_deleted'));
    }

    public function resolve(Request $request, Redirects $redirects): RedirectResponse
    {
        return $redirects->resolve($request) ?? throw new NotFoundHttpException();
    }
}
