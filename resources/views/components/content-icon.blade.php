@props(['name' => null])

@php($icon = app(\App\Kraft\ContentIcons::class)->resolve($name))

@if (isset($icon['url']))
    <span {{ $attributes->class('content-icon')->merge(['style' => "--content-icon: url('".$icon['url']."')", 'aria-hidden' => 'true']) }}></span>
@elseif (isset($icon['class']))
    <i {{ $attributes->class($icon['class'])->merge(['aria-hidden' => 'true']) }}></i>
@endif
