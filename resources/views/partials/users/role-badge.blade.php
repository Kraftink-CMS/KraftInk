@use('App\Models\Accounts\Role')
@php
    /** @var Role $role */
    $badgeColors = $role->badgeColors();
@endphp
<span @class(['badge border', 'text-bg-secondary' => !$badgeColors]) @if ($badgeColors)
    style="background-color:{{ $badgeColors['background'] }};color:{{ $badgeColors['text'] }}" @endif>
    @if (preg_match('/^[a-z][a-z0-9-]*$/D', $role->icon ?? ''))
        <i class="bi bi-{{ $role->icon }} me-1" aria-hidden="true"></i>
    @endif
    {{ $role->displayText('name') }}
</span>
