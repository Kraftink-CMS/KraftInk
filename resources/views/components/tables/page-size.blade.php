@use('App\Kraft\System\TableQuery')
@props(['paginator', 'form' => true])
@php
    $tableOptions = TableQuery::options($paginator);
    $pageName = $paginator->getPageName();
    $tableId = 'table-' . $pageName;
@endphp
<{{ $form ? 'form' : 'div' }} @if ($form) method="get" action="{{ request()->url() }}" @endif
    class="d-flex flex-wrap align-items-center gap-2" data-table-page-size>
    @if ($form)
        @foreach (TableQuery::fields(request()->query(), [$pageName, $pageName . '_per_page']) as $field)
            <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}">
        @endforeach
    @endif
    <label class="small text-body-secondary mb-0" for="{{ $tableId }}-size">{{ __('tables.per_page') }}</label>
    <select class="form-select form-select-sm w-auto" id="{{ $tableId }}-size"
        @if ($form) name="{{ $pageName }}_per_page" @endif data-table-parameter="{{ $pageName }}_per_page">
        @foreach ($tableOptions['sizes'] as $size)
            <option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>
        @endforeach
    </select>
    @if ($form)
        <noscript>
            <button type="submit" class="btn btn-outline-secondary btn-sm">{{ __('tables.apply') }}</button>
        </noscript>
    @endif
</{{ $form ? 'form' : 'div' }}>
