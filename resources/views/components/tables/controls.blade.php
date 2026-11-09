@use('App\Kraft\System\TableQuery')
@props(['paginator', 'form' => true, 'containerClass' => null])
@php
    $tableOptions = TableQuery::options($paginator);
    $pageName = $paginator->getPageName();
    $parameters = [$pageName, $pageName . '_per_page', $pageName . '_search', $pageName . '_sort', $pageName . '_direction'];
    $tableId = 'table-' . $pageName;
@endphp
@if ($tableOptions['searchable'])
    @if ($containerClass)
        <div class="{{ $containerClass }}">
    @endif
    <{{ $form ? 'form' : 'div' }} @if ($form) method="get" action="{{ request()->url() }}" @endif
        {{ $attributes->class(['kraft-table-controls d-flex flex-wrap align-items-end gap-3']) }} data-table-filters>
        @if ($form)
            @foreach (TableQuery::fields(request()->query(), $parameters) as $field)
                <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
            @endforeach
            <input type="hidden" name="{{ $pageName }}_per_page" value="{{ $paginator->perPage() }}">
            <input type="hidden" name="{{ $pageName }}_sort" value="{{ $tableOptions['sort'] }}">
            <input type="hidden" name="{{ $pageName }}_direction" value="{{ $tableOptions['direction'] }}">
        @endif
        <div class="kraft-table-search flex-grow-1">
            <label class="form-label" for="{{ $tableId }}-search">{{ __('tables.search') }}</label>
            <input class="form-control" id="{{ $tableId }}-search" type="search" maxlength="100"
                @if ($form) name="{{ $pageName }}_search" @endif data-table-parameter="{{ $pageName }}_search"
                value="{{ $tableOptions['search'] }}" autocomplete="off">
        </div>
        @if ($form)
            <noscript>
                <button type="submit" class="btn btn-outline-secondary">{{ __('tables.apply') }}</button>
            </noscript>
        @endif
    </{{ $form ? 'form' : 'div' }}>
    @if ($containerClass)
        </div>
    @endif
@endif
