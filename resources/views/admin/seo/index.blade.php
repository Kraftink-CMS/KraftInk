@use('App\Kraft\Site\SeoConfiguration')
@extends('admin.layouts.layout')
@section('title', __('seo.title'))
@section('description', __('seo.description'))
@section('content')
    <ul class="nav nav-tabs mb-4" role="tablist">
        @foreach (['general', 'pages', 'redirects'] as $section)
            <li class="nav-item" role="presentation">
                @if ($section === 'redirects' && $activeSection !== 'redirects')
                    <a class="nav-link" href="{{ route('admin.seo.index', ['tab' => 'redirects']) }}" role="tab"
                        aria-selected="false">{{ __('seo.sections.redirects') }}</a>
                @else
                    <button @class(['nav-link', 'active' => $activeSection === $section]) id="seo-{{ $section }}-tab" type="button" data-bs-toggle="tab"
                        data-bs-target="#seo-{{ $section }}-panel" role="tab"
                        aria-controls="seo-{{ $section }}-panel"
                        aria-selected="{{ $activeSection === $section ? 'true' : 'false' }}">{{ __('seo.sections.' . $section) }}</button>
                @endif
            </li>
        @endforeach
    </ul>
    <div class="tab-content">
        <div @class([
            'tab-pane fade',
            'show active' => $activeSection === 'general',
        ]) id="seo-general-panel" role="tabpanel" aria-labelledby="seo-general-tab"
            tabindex="0">
            <div class="row g-4" data-seo-editor data-seo-base-url="{{ url('/') }}"
                data-seo-default-title="{{ SeoConfiguration::DEFAULT_TITLE }}"
                data-seo-variables="{{ json_encode($previewVariables) }}"
                data-seo-defaults="{{ json_encode($previewDefaults) }}">
                <div class="col-lg-8">
                    <form data-ajax="true" data-redirect-url="response" class="card" method="post" action="{{ route('admin.seo.update') }}" data-seo-form>
                        @csrf
                        <input type="hidden" name="_seo_section" value="general">
                        <div class="card-header">
                            <h2 class="card-title" id="seo-templates-heading">{{ __('seo.templates') }}</h2>
                        </div>
                        <div class="card-body">
                            <section aria-labelledby="seo-templates-heading">
                                <p class="text-body-secondary">{{ __('seo.defaults_help') }}</p>
                                @include('admin.seo.partials.fields', [
                                    'useOld' => old('_seo_section', 'general') === 'general',
                                ])
                            </section>
                            <hr>
                            <x-admin::media-picker name="sharing_image" :value="$values['sharing_image']" value-mode="url"
                                context="theme-image" profile="image" :url-max-length="500" :label="__('seo.sharing_image')"
                                :help="__('seo.sharing_image_help')" />
                            <p class="small text-body-secondary mt-3 mb-0">{{ __('seo.priority_help') }}</p>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                        </div>
                    </form>
                </div>
                <aside class="col-lg-4 d-flex flex-column gap-4">
                    @include('admin.seo.partials.reference', ['variableDialog' => $variableDialogs['general']])
                </aside>
            </div>
        </div>
        <div @class(['tab-pane fade', 'show active' => $activeSection === 'pages']) id="seo-pages-panel" role="tabpanel" aria-labelledby="seo-pages-tab"
            tabindex="0">
            <div class="vstack gap-4">
                <div class="row g-4" data-seo-editor data-seo-base-url="{{ url('/') }}"
                    data-seo-default-title="{{ SeoConfiguration::DEFAULT_TITLE }}"
                    data-seo-variables="{{ json_encode($previewVariables) }}" data-seo-targets="{{ json_encode($seoTargets) }}"
                    data-seo-defaults="{{ json_encode($pagePreviewDefaults) }}">
                    <div class="col-lg-8 d-flex flex-column gap-4">
                        <form class="card" method="get" action="{{ route('admin.seo.index') }}" data-seo-loader
                            data-dirty-confirm="{{ __('seo.unsaved_changes') }}">
                            <input type="hidden" name="tab" value="pages">
                            <div class="card-body">
                                <label class="form-label" for="seo-target-selector">{{ __('seo.target') }}</label>
                                <div class="input-group">
                                    <select class="form-select @error('target') is-invalid @enderror" id="seo-target-selector"
                                        name="target" required aria-describedby="seo-target-help">
                                        <option value="">{{ __('seo.choose_target') }}</option>
                                        @foreach ($seoTargets as $key => $target)
                                            <option value="{{ $key }}" @selected($selectedTarget === $key)>{{ $target['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-outline-primary" type="submit">{{ __('seo.load_page') }}</button>
                                </div>
                                @error('target')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                                <div class="form-text" id="seo-target-help">{{ __('seo.target_help') }}</div>
                                <div class="form-text">{{ __('seo.load_page_help') }}</div>
                            </div>
                        </form>
                        @if ($hasSelectedTarget)
                            <form data-ajax="true" data-redirect-url="response" class="card" method="post" action="{{ route('admin.seo.pages.store', $page['id'] ?? null) }}"
                                data-seo-form>
                                @csrf
                                <input type="hidden" name="_seo_section" value="pages">
                                <input type="hidden" name="target" value="{{ $selectedTarget }}" data-seo-target>
                                <div class="card-header">
                                    <h2 class="card-title">{{ $seoTargets[$selectedTarget]['label'] }}</h2>
                                </div>
                                <div class="card-body">
                                    <p class="text-body-secondary">{{ __('seo.page_help') }}</p>
                                    @include('admin.seo.partials.fields', [
                                        'seoFields' => $pageFields,
                                        'previewDefaults' => $pagePreviewDefaults,
                                        'values' => $pageValues,
                                        'pageEditor' => true,
                                        'fieldPrefix' => 'seo-page',
                                        'useOld' => old('_seo_section') === 'pages',
                                    ])
                                    <p class="small text-body-secondary mb-0">{{ __('seo.priority_help') }}</p>
                                </div>
                                <div class="card-footer">
                                    <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                                </div>
                            </form>
                        @endif
                    </div>
                    @if ($hasSelectedTarget)
                        <aside class="col-lg-4 d-flex flex-column gap-4">
                            @include('admin.seo.partials.reference', ['variableDialog' => $variableDialogs['pages']])
                        </aside>
                    @endif
                </div>
                <div id="admin-seo-pages" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                    <section class="card" id="pages">
                        <div class="card-header">
                            <h2 class="card-title">{{ __('seo.pages') }}</h2>
                            <div class="card-tools">
                                <a class="btn btn-outline-primary btn-sm"
                                    href="{{ route('admin.seo.index', ['tab' => 'pages']) }}">{{ __('seo.add_page') }}</a>
                            </div>
                        </div>
                        <x-tables.controls :paginator="$pages" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th scope="col" data-table-sort="path">{{ __('seo.target') }}</th>
                                        <th scope="col" data-table-sort="title">{{ __('seo.fields.title') }}</th>
                                        <th scope="col" data-table-sort="robots">{{ __('seo.fields.robots') }}</th>
                                        <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($pages as $configuredPage)
                                        <tr>
                                            <td class="text-break">
                                                {{ $seoTargets[$configuredPage['target'] ?? '']['label'] ?? __('seo.unavailable_target') }}
                                                <small
                                                    class="d-block text-body-secondary"><code>{{ $configuredPage['path'] }}</code></small>
                                            </td>
                                            <td class="text-break">{{ $configuredPage['title'] ?: __('seo.automatic') }}
                                            </td>
                                            <td>{{ $configuredPage['robots'] ? __('seo.robots.' . $configuredPage['robots']) : __('seo.inherit') }}
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end flex-wrap gap-2">
                                                    <a class="btn btn-outline-secondary btn-sm"
                                                        href="{{ route('admin.seo.index', ['edit' => $configuredPage['id']]) }}">{{ __('common.actions.edit') }}</a>
                                                    <button class="btn btn-outline-danger btn-sm" type="button"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#seo-delete-{{ $configuredPage['id'] }}">{{ __('common.actions.delete') }}</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="4" :message="__('seo.empty')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $pages->links() }}
                        </div>
                    </section>
                    @foreach ($pages as $configuredPage)
                        <x-forms.modal :id="'seo-delete-' . $configuredPage['id']" :title="__('common.actions.delete')" :action="route('admin.seo.pages.destroy', $configuredPage['id'])" method="DELETE"
                            :submit="__('common.actions.delete')" size="sm">
                            <p>{{ __('seo.confirm_delete') }}</p>
                            <code>{{ $configuredPage['path'] }}</code>
                        </x-forms.modal>
                    @endforeach
                </div>

            </div>
        </div>
        <div @class([
            'tab-pane fade',
            'show active' => $activeSection === 'redirects',
        ]) id="seo-redirects-panel" role="tabpanel" aria-labelledby="seo-redirects-tab"
            tabindex="0">
            @if ($redirectData !== null)
                <div id="admin-seo-redirects" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                    <section class="card">
                        <div class="card-header">
                            <h2 class="card-title">{{ __('admin.redirects.redirects') }}</h2>
                            <div class="card-tools">
                                <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal"
                                    data-bs-target="#redirect-new">{{ __('admin.redirects.add_redirect') }}</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <x-tables.controls :paginator="$redirectData['redirects']" class="mb-3" />
                            <form class="d-flex gap-2" method="get" action="{{ route('admin.seo.index') }}">
                                <input type="hidden" name="tab" value="redirects">
                                <input class="form-control" name="q" value="{{ request('q') }}" maxlength="100"
                                    aria-label="{{ __('admin.redirects.search_source') }}"
                                    placeholder="{{ __('admin.redirects.search_source') }}">
                                <noscript>
                                    <button class="btn btn-outline-secondary" type="submit">{{ __('common.actions.search') }}</button>
                                </noscript>
                            </form>
                        </div>
                        <div class="card-body p-0 table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th data-table-sort="source">{{ __('admin.redirects.source') }}</th>
                                        <th data-table-sort="destination">{{ __('admin.redirects.destination') }}</th>
                                        <th data-table-sort="status">{{ __('admin.redirects.response_code') }}</th>
                                        <th data-table-sort="enabled">{{ __('common.labels.status') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($redirectData['redirects'] as $redirect)
                                        <tr>
                                            <td class="text-break">{{ $redirect->source }}</td>
                                            <td class="text-break">{{ $redirect->destination }}</td>
                                            <td>{{ $redirect->status }}</td>
                                            <td><span
                                                    @class([
                                                        'badge',
                                                        'text-bg-success' => $redirect->enabled,
                                                        'text-bg-secondary' => !$redirect->enabled,
                                                    ])>{{ $redirect->enabled ? __('common.statuses.active') : __('common.statuses.inactive') }}</span>
                                            </td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                                        data-bs-toggle="dropdown"
                                                        aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item"
                                                                href="{{ route('admin.seo.index', ['tab' => 'redirects', 'redirect' => $redirect->id]) }}">{{ __('common.actions.edit') }}</a>
                                                        </li>
                                                        <li>
                                                            <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal"
                                                                data-bs-target="#redirect-delete-{{ $redirect->id }}">{{ __('common.actions.delete') }}</button>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <x-tables.empty :colspan="5" :message="__('admin.redirects.no_redirects')" :action="__('admin.redirects.add_redirect')" modal="redirect-new" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $redirectData['redirects']->links() }}
                        </div>
                    </section>
                    <x-forms.modal id="redirect-new" :title="__('admin.redirects.add_redirect')" :action="route('admin.seo.redirects.store')">
                        @include('admin.seo.redirects.partials.fields', ['editingRedirect' => null])
                    </x-forms.modal>
                    @if ($redirectData['editing'])
                        <x-forms.modal :id="'redirect-edit-' . $redirectData['editing']->id" :title="__('admin.redirects.edit_redirect')" :action="route('admin.seo.redirects.store', $redirectData['editing'])" :open="true">
                            @include('admin.seo.redirects.partials.fields', ['editingRedirect' => $redirectData['editing']])
                        </x-forms.modal>
                    @endif
                    @foreach ($redirectData['redirects'] as $redirect)
                        <x-forms.modal :id="'redirect-delete-' . $redirect->id" :title="__('common.actions.delete')" :action="route('admin.seo.redirects.destroy', $redirect)" method="DELETE" :submit="__('common.actions.delete')"
                            size="sm">
                            <p>{{ __('admin.redirects.confirm_delete') }}</p>
                            <code>{{ $redirect->source }}</code>
                        </x-forms.modal>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
