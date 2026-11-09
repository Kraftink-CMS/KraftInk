@use('App\Kraft\Widgets\WidgetLayout')
@props(['name', 'context' => []])
{{ app(WidgetLayout::class)->render($name, $context) }}
