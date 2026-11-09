<?php

namespace App\Http\Controllers\Admin\Appearance;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Kraft\System\TableQuery;
use App\Http\Controllers\Controller;
use App\Kraft\Widgets\WidgetLayout;
use App\Kraft\Widgets\WidgetRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WidgetController extends Controller
{
    public function index(WidgetLayout $layout, WidgetRegistry $widgets): View
    {
        return view('admin.widgets.index', [
            'instances' => TableQuery::paginate(
                $layout->all(),
                ['widget', 'zone', 'enabled'],
                search: ['widget', 'zone'],
            ),
            'definitions' => $widgets->all(),
            'zones' => $widgets->zones(),
        ]);
    }

    public function edit(Request $request, WidgetLayout $layout, WidgetRegistry $widgets, ?string $widget = null): View
    {
        $instance = $widget === null ? null : collect($layout->all())->firstWhere('id', $widget);
        if ($widget !== null && !$instance) {
            throw new NotFoundHttpException();
        }
        $key = $instance['widget'] ?? $request->query('widget');
        $definition = (is_string($key) ? $widgets->get($key) : null) ?? throw new NotFoundHttpException();

        return view('admin.widgets.edit', [
            'instance' => $instance,
            'key' => $key,
            'definition' => $definition,
            'configuration' => $instance['config'] ?? $layout->configuration($definition, []),
            'zones' => array_intersect_key($widgets->zones(), array_flip($definition['zones'])),
            'locales' => config('kraft.locales', []),
        ]);
    }

    public function store(Request $request, WidgetLayout $layout, ?string $widget = null): RedirectResponse
    {
        $layout->store($widget, $request->only(['widget', 'zone', 'enabled', 'width', 'locales', 'config']));

        return to_route('admin.widgets.index')->with('status', __('widgets.saved'));
    }

    public function move(Request $request, WidgetLayout $layout, string $widget): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $layout->move($widget, $data['direction']);

        return to_route('admin.widgets.index', ['page' => $request->query('page')]);
    }

    public function destroy(Request $request, WidgetLayout $layout, string $widget): RedirectResponse
    {
        $layout->destroy($widget);

        return to_route('admin.widgets.index', ['page' => $request->query('page')])->with(
            'status',
            __('widgets.deleted'),
        );
    }
}
