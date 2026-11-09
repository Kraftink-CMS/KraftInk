@use('App\Kraft\Extensions\SlotRegistry')
@props(['name', 'context' => []])
{{ app(SlotRegistry::class)->render($name, $context, (string) $slot) }}
