@use('App\Models\Accounts\Role')
@php
    /** @var Role $role */
    $badgeColors = $role->badgeColors();
@endphp
<span @class(['badge border', 'text-bg-secondary' => !$badgeColors]) @if ($badgeColors)
    style="background-color:{{ $badgeColors['background'] }};color:{{ $badgeColors['text'] }}" @endif>
    <x-content-icon :name="$role->icon" class="me-1" />
    {{ $role->displayText('name') }}
</span>
