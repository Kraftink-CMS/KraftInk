@use('Illuminate\Contracts\Pagination\Paginator')
@php
    /** @var Paginator $paginator */
    $tableOptions = method_exists($paginator, 'getPageName')
        ? request()->attributes->get('kraft.tables.' . $paginator->getPageName())
        : null;
@endphp
@if ($tableOptions || $paginator->hasPages())
<div @class(['d-flex flex-wrap align-items-center justify-content-between gap-3', 'kraft-table-controls kraft-table-footer' => $tableOptions])
    @if ($tableOptions) data-table-controls data-page-name="{{ $paginator->getPageName() }}"
        data-table-sorts="{{ json_encode($tableOptions['sorts']) }}"
        data-table-current-sort="{{ $tableOptions['sort'] }}" data-table-direction="{{ $tableOptions['direction'] }}" @endif>
    @if ($tableOptions)
        <div class="d-flex flex-wrap align-items-center gap-3">
            <x-tables.page-size :paginator="$paginator" :form="$tableForm ?? true" />
            <p class="small text-body-secondary mb-0" role="status" aria-live="polite">
                {{ __('pagination.summary', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}
            </p>
        </div>
    @elseif (method_exists($paginator, 'total'))
        <p class="small text-body-secondary mb-0">
            {{ __('pagination.summary', ['first' => $paginator->firstItem() ?? 0, 'last' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}
        </p>
    @endif
    @if ($paginator->hasPages())
    <nav aria-label="{{ __('pagination.navigation') }}">
    <ul class="pagination mb-0 flex-wrap">
        <li @class(['page-item', 'disabled' => $paginator->onFirstPage()])>@if($paginator->onFirstPage())<span class="page-link" aria-disabled="true">{{ __('pagination.previous') }}</span>@else<a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('pagination.previous') }}</a>@endif</li>
        @foreach($elements ?? [] as $element)
            @if(is_string($element))<li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>
            @else
                @foreach($element as $page => $url)
                    <li @class(['page-item', 'active' => $page === $paginator->currentPage()])>@if($page === $paginator->currentPage())<span class="page-link" aria-current="page"><span class="visually-hidden">{{ __('pagination.page', ['page' => $page]) }}</span><span aria-hidden="true">{{ $page }}</span></span>@else<a class="page-link" href="{{ $url }}" aria-label="{{ __('pagination.page', ['page' => $page]) }}">{{ $page }}</a>@endif</li>
                @endforeach
            @endif
        @endforeach
        <li @class(['page-item', 'disabled' => !$paginator->hasMorePages()])>@if($paginator->hasMorePages())<a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('pagination.next') }}</a>@else<span class="page-link" aria-disabled="true">{{ __('pagination.next') }}</span>@endif</li>
    </ul>
    </nav>
    @endif
</div>
@endif
