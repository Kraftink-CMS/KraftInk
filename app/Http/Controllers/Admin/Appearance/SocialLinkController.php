<?php

namespace App\Http\Controllers\Admin\Appearance;

use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\NavigationTargets;
use App\Kraft\SocialLinks;
use App\Kraft\TextTranslations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SocialLinkController extends Controller
{
    public function index(Request $request, SocialLinks $social): View
    {
        $links = $social->all();
        $editing = collect($links)->firstWhere('id', $request->query('edit'));
        if ($request->filled('edit') && !$editing) {
            abort(404);
        }

        return view('admin.social.index', [
            'links' => TableQuery::paginate($links, ['title', 'type', 'enabled'], search: ['title', 'type', 'url']),
            'editing' => $editing,
            'services' => config('social.services'),
        ]);
    }

    public function store(Request $request, SocialLinks $social, ?string $link = null): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('social.services')))],
            'title' => 'required|string|max:80',
            'url' => [
                'required',
                'string',
                'max:2000',
                fn ($attribute, $value, $fail) => NavigationTargets::validUrl($value) ?:
                $fail(__('admin.social.invalid_url')),
            ],
            'icon' => 'required|string|max:60|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            'color' => 'required|string|regex:/^#[0-9a-fA-F]{6}$/D',
            'enabled' => 'required|boolean',
        ] + app(TextTranslations::class)->rules(SocialLinks::TRANSLATION_FIELDS));
        $data['enabled'] = $request->boolean('enabled');
        $social->store($link, $data);

        return to_route('admin.social.index')->with('status', __('admin.social.saved'));
    }

    public function destroy(Request $request, SocialLinks $social, string $link): RedirectResponse
    {
        $social->destroy($link);

        return to_route('admin.social.index', ['page' => $request->query('page')])->with(
            'status',
            __('admin.social.deleted'),
        );
    }

    public function move(Request $request, SocialLinks $social, string $link): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $social->move($link, $data['direction']);

        return to_route('admin.social.index', ['page' => $request->query('page')])->with(
            'status',
            __('admin.social.saved'),
        );
    }
}
