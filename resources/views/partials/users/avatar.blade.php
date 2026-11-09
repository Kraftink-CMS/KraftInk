@use('App\Kraft\Auth\Avatars')
@php
    $avatarSize = $avatarSize ?? 24;
    $avatarUrl = $avatarUrl ?? (isset($avatarUser) ? app(Avatars::class)->url($avatarUser) : asset('images/avatar.svg'));
@endphp
@if ($avatarUrl === asset('images/avatar.svg'))
    <span class="d-inline-flex flex-shrink-0">
        <img src="{{ $avatarUrl }}" class="user-avatar-light rounded-circle object-fit-cover"
            width="{{ $avatarSize }}" height="{{ $avatarSize }}" alt="" loading="lazy" referrerpolicy="no-referrer">
        <img src="{{ asset('images/avatar-dark.svg') }}" class="user-avatar-dark rounded-circle object-fit-cover"
            width="{{ $avatarSize }}" height="{{ $avatarSize }}" alt="" loading="lazy" referrerpolicy="no-referrer">
    </span>
@else
    <img src="{{ $avatarUrl }}" class="rounded-circle object-fit-cover flex-shrink-0"
        width="{{ $avatarSize }}" height="{{ $avatarSize }}" alt="" loading="lazy" referrerpolicy="no-referrer">
@endif
