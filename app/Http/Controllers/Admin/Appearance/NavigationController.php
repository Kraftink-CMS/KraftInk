<?php

namespace App\Http\Controllers\Admin\Appearance;

use Illuminate\Support\Facades\Validator;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Audit;
use App\Kraft\ContentIcons;
use App\Kraft\LocalePolicy;
use App\Kraft\Navigation;
use App\Kraft\NavigationTargets;
use App\Models\Site\NavigationLink;
use App\Models\Accounts\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NavigationController extends Controller
{
    public function index(Request $request): View
    {
        $links = NavigationLink::with('roles')->orderBy('location')->orderBy('position')->orderBy('id')->get();
        $editing = $request->filled('edit') ? $links->firstWhere('id', $request->integer('edit')) : null;
        if ($request->filled('edit') && !$editing) {
            abort(404);
        }
        $requestedLocation = $request->old('location', $editing?->location ?? $request->query('location'));
        $locationLabels = app(Navigation::class)->locations();
        $locations = array_keys($locationLabels);
        $location = in_array($requestedLocation, $locations, true) ? $requestedLocation : $locations[0];
        $groups = [];
        foreach ($locations as $placement) {
            $groups[$placement] = TableQuery::paginate(
                $this->ordered($links->where('location', $placement)),
                [],
                pageName: 'navigation_'.$placement.'_page',
            )->appends(['location' => $placement]);
        }
        $contentTargets = app(NavigationTargets::class)->contents($request->user(), published: false);
        $routeTargets = app(NavigationTargets::class)->routes();
        $contentById = $contentTargets->keyBy('id');
        $destinations = [];
        /** @var NavigationLink $link */
        foreach ($links as $link) {
            $destinations[$link->id] = match ($link->destination_type) {
                'content' => $contentById->get($link->content_id)?->url(),
                'route' => isset($routeTargets[$link->route_name]) ? route($link->route_name) : null,
                default => $link->url,
            };
        }

        return view('admin.navigation.index', [
            'links' => $links,
            'groups' => $groups,
            'locations' => $locations,
            'locationLabels' => $locationLabels,
            'editingLink' => $editing,
            'newLocation' => $location,
            'newParent' => $request->integer('parent'),
            'contentTargets' => $contentTargets,
            'routeTargets' => $routeTargets,
            'destinations' => $destinations,
            'roles' => Role::query()->orderByDesc('position')->get(),
        ]);
    }

    public function store(Request $request, ?NavigationLink $link = null): RedirectResponse
    {
        if (is_string($request->input('url'))) {
            $request->merge(['url' => trim($request->input('url'))]);
        }
        $validator = Validator::make($request->all(), ['destination' => 'required|string|max:200']);
        $choice = $validator->validate()['destination'];
        if ($choice === 'url') {
            $request->merge(['destination_type' => 'url', 'content_id' => null, 'route_name' => null]);
        } elseif (preg_match('/^content:([1-9][0-9]*)$/D', $choice, $match)) {
            $request->merge([
                'destination_type' => 'content',
                'content_id' => $match[1],
                'route_name' => null,
                'url' => null,
            ]);
        } elseif (preg_match('/^route:([A-Za-z0-9_.-]+)$/D', $choice, $match)) {
            $request->merge([
                'destination_type' => 'route',
                'content_id' => null,
                'route_name' => $match[1],
                'url' => null,
            ]);
        } else {
            throw ValidationException::withMessages(['destination' => __('site.community.destination_invalid')]);
        }
        $rules = [
            'location' => ['required', Rule::in(array_keys(app(Navigation::class)->locations()))],
            'url' => 'nullable|required_if:destination_type,url|string|max:500',
            'destination_type' => ['required', Rule::in(['url', 'content', 'route'])],
            'content_id' => 'nullable|required_if:destination_type,content|integer|exists:content_entries,id',
            'route_name' => [
                'nullable',
                'required_if:destination_type,route',
                Rule::in(array_keys(app(NavigationTargets::class)->routes())),
            ],
            'icon' => [
                'nullable', 'string', 'max:'.ContentIcons::MAX_NAME_LENGTH, 'regex:/^'.ContentIcons::NAME_PATTERN.'$/D',
            ],
            'role_ids' => 'nullable|array|max:200',
            'role_ids.*' => 'integer|distinct|exists:roles,id',
            'parent_id' => 'nullable|integer|exists:navigation_links,id',
            'visibility' => ['required', Rule::in(['all', 'auth', 'guest'])],
            'permission' => 'nullable|string|max:100|regex:/^[a-z][a-z0-9_.-]+$/',
            'position' => 'required|integer|between:0,9999',
            'enabled' => 'boolean',
            'new_tab' => 'boolean',
            'labels' => 'required|array',
        ];
        $localePolicy = app(LocalePolicy::class);
        $locales = $localePolicy->allowsChoice()
            ? array_keys(config('kraft.locales'))
            : [$localePolicy->defaultLocale()];
        foreach ($locales as $locale) {
            $rules['labels.'.$locale] = 'nullable|string|max:80';
        }
        $rules['clear_role_restriction'] = 'boolean';
        $data = $request->validate($rules);
        if ($data['destination_type'] === 'url' && !NavigationTargets::validUrl($data['url'])) {
            throw ValidationException::withMessages(['url' => __('site.community.destination_invalid')]);
        }
        if (
            $data['destination_type'] === 'content' &&
            app(NavigationTargets::class)
                ->contents($request->user(), [(int) $data['content_id']], false)
                ->isEmpty()
        ) {
            throw ValidationException::withMessages(['content_id' => __('site.community.destination_invalid')]);
        }
        $roleIds = array_map('intval', $data['role_ids'] ?? []);
        unset($data['role_ids'], $data['clear_role_restriction']);
        $missingRoles = $link?->roles_restricted && !$link->roles()->exists();
        $data['roles_restricted'] = $roleIds !== [] || ($missingRoles && !$request->boolean('clear_role_restriction'));
        $data['url'] = $data['destination_type'] === 'url' ? $data['url'] : '/';
        $data['content_id'] = $data['destination_type'] === 'content' ? $data['content_id'] : null;
        $data['route_name'] = $data['destination_type'] === 'route' ? $data['route_name'] : null;
        $data['labels'] = array_filter(
            array_intersect_key($data['labels'], array_flip($locales)),
            fn ($value) => is_string($value) && trim($value) !== '',
        );
        if (!$data['labels']) {
            throw ValidationException::withMessages(['labels' => __('site.community.label_required')]);
        }
        if (!$localePolicy->allowsChoice()) {
            $data['labels'] += array_diff_key($link?->labels ?? [], array_flip($locales));
        }
        $parent = $data['parent_id'] ?? null;
        $depth = 0;
        while ($parent) {
            $ancestor = NavigationLink::query()->findOrFail($parent);
            if ($ancestor->id === $link?->id || $ancestor->location !== $data['location'] || ++$depth > 3) {
                throw ValidationException::withMessages(['parent_id' => __('site.community.invalid_parent')]);
            }
            $parent = $ancestor->parent_id;
        }
        if (
            $link &&
            $link->location !== $data['location'] &&
            NavigationLink::query()->where('parent_id', $link->id)->exists()
        ) {
            throw ValidationException::withMessages(['location' => __('site.community.move_children_first')]);
        }
        if ($link && $depth + $this->descendantDepth($link->id) > 3) {
            throw ValidationException::withMessages(['parent_id' => __('site.community.invalid_parent')]);
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['new_tab'] = $request->boolean('new_tab');
        $link = DB::transaction(function () use ($link, $data, $roleIds) {
            if ($link) {
                $link->update($data);
            } else {
                $link = NavigationLink::query()->create($data);
            }
            $link->roles()->sync($roleIds);

            return $link;
        }, 3);
        Cache::forget('kraft.navigation');
        Audit::record('navigation.saved');

        return redirect()
            ->route('admin.navigation.index', ['location' => $link->location])
            ->with('status', __('site.community.navigation_saved'));
    }

    public function destroy(Request $request, NavigationLink $link): RedirectResponse
    {
        DB::transaction(function () use ($link) {
            NavigationLink::query()
                ->where('parent_id', $link->id)
                ->update(['enabled' => false]);
            $link->delete();
        }, 3);
        Cache::forget('kraft.navigation');
        Audit::record('navigation.deleted', ['id' => $link->id]);

        return to_route(
            'admin.navigation.index',
            ['location' => $link->location] + $request->only(['navigation_header_page', 'navigation_footer_page']),
        );
    }

    public function move(Request $request, NavigationLink $link): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        DB::transaction(function () use ($link, $data) {
            $siblings = NavigationLink::query()
                ->where('location', $link->location)
                ->where('parent_id', $link->parent_id)
                ->orderBy('position')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $index = $siblings->search(fn ($sibling) => $sibling->id === $link->id);
            $target = $index + ($data['direction'] === 'up' ? -1 : 1);
            if ($index === false || $target < 0 || $target >= $siblings->count()) {
                return;
            }
            $ordered = $siblings->all();
            [$ordered[$index], $ordered[$target]] = [$ordered[$target], $ordered[$index]];
            foreach ($ordered as $position => $sibling) {
                $sibling->update(['position' => $position]);
            }
        }, 3);
        Cache::forget('kraft.navigation');
        Audit::record('navigation.reordered', ['id' => $link->id]);

        return to_route(
            'admin.navigation.index',
            ['location' => $link->location] + $request->only(['navigation_header_page', 'navigation_footer_page']),
        )->with('status', __('site.community.navigation_reordered'));
    }

    private function descendantDepth(int $id, array $visited = []): int
    {
        if (in_array($id, $visited, true) || count($visited) >= 4) {
            return 4;
        }
        $visited[] = $id;
        $depth = 0;
        foreach (NavigationLink::query()->where('parent_id', $id)->pluck('id') as $child) {
            $depth = max($depth, 1 + $this->descendantDepth($child, $visited));
        }

        return $depth;
    }

    private function ordered(Collection $links): array
    {
        $rows = [];
        $seen = [];
        $walk = function (?int $parent, int $depth) use (&$walk, &$rows, &$seen, $links): void {
            foreach ($links->where('parent_id', $parent) as $link) {
                if (isset($seen[$link->id])) {
                    continue;
                }
                $seen[$link->id] = true;
                $rows[] = ['link' => $link, 'depth' => $depth];
                if ($depth < 3) {
                    $walk($link->id, $depth + 1);
                }
            }
        };
        $walk(null, 0);
        foreach ($links as $link) {
            if (!isset($seen[$link->id])) {
                $rows[] = ['link' => $link, 'depth' => 0];
            }
        }

        return $rows;
    }
}
