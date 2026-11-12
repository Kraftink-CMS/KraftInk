@use('App\Kraft\SocialLinks')
@extends('admin.layouts.layout')
@section('title', __('admin.social.title'))
@section('description', __('admin.social.description'))
@section('actions')
    @if ($editing)
        <a class="btn btn-primary" href="{{ route('admin.social.index', ['create' => 1]) }}">
            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('admin.social.add') }}</a>
    @else
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#social-editor"><i
                class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('admin.social.add') }}</button>
    @endif
@endsection
@section('content')
    <div id="admin-social-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$links" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th data-table-sort="title">{{ __('admin.social.link') }}</th>
                            <th data-table-sort="enabled">{{ __('common.labels.status') }}</th>
                            <th>{{ __('site.community.link_position') }}</th>
                            <th class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($links as $index => $link)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <x-content-icon :name="$link['icon']" class="fs-4" :style="'color: '.$link['color']" />
                                        <div>
                                            <strong>{{ $link['title'] }}</strong><small
                                                class="d-block text-body-secondary text-break">{{ $link['url'] }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td><span
                                        @class([
                                            'badge',
                                            'text-bg-success' => $link['enabled'],
                                            'text-bg-secondary' => !$link['enabled'],
                                        ])>{{ $link['enabled'] ? __('common.statuses.visible') : __('common.statuses.hidden') }}</span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group"
                                        aria-label="{{ __('site.community.link_position') }}">
                                        @foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $direction => $icon)
                                            <form data-ajax="true" data-redirect-url="response" data-success-message="false"
                                                method="post" action="{{ route('admin.social.move', ['link' => $link['id'], 'page' => $links->currentPage() > 1 ? $links->currentPage() : null]) }}">
                                                @csrf
                                                <input type="hidden" name="direction" value="{{ $direction }}">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    aria-label="{{ __('site.community.move_' . $direction) }}"
                                                    @disabled($direction === 'up' ? $links->firstItem() + $loop->parent->index === 1 : $links->firstItem() + $loop->parent->index === $links->total())><i class="bi bi-{{ $icon }}"
                                                        aria-hidden="true"></i></button>
                                            </form>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                            data-bs-toggle="dropdown"
                                            aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item"
                                                    href="{{ route('admin.social.index', ['edit' => $link['id']]) }}">{{ __('common.actions.edit') }}</a>
                                            </li>
                                            <li>
                                                <hr class="dropdown-divider">
                                            </li>
                                            <li>
                                                <form data-ajax="true" data-redirect-url="response" method="post"
                                                    action="{{ route('admin.social.destroy', ['link' => $link['id'], 'page' => $links->currentPage() > 1 ? $links->currentPage() : null]) }}"
                                                    data-confirm="{{ __('admin.social.confirm_delete') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="dropdown-item text-danger"
                                                        type="submit">{{ __('common.actions.delete') }}</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="4" :message="__('admin.social.empty')" :description="__('admin.social.empty_help')" :action="__('admin.social.add')"
                                modal="social-editor" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $links->links() }}
            </div>
        </section>
        <x-forms.modal id="social-editor" :title="$editing ? __('admin.social.edit') : __('admin.social.add')" :action="route('admin.social.store', ['link' => $editing['id'] ?? null])" :open="(bool) $editing || request()->boolean('create')" data-social-form="">
            <div class="mb-3">
                <label class="form-label" for="social-type">{{ __('admin.social.service') }}</label>
                <select class="form-select" id="social-type" name="type" required data-social-type>
                    @foreach ($services as $type => $service)
                        <option value="{{ $type }}" @selected(old('type', $editing['type'] ?? 'discord') === $type)
                            data-social-title="{{ $service['name'] ?? __($service['label']) }}"
                            data-social-icon="{{ $service['icon'] }}" data-social-color="{{ $service['color'] }}">
                            {{ $service['name'] ?? __($service['label']) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="social-title">{{ __('admin.social.label') }}</label>
                <input class="form-control" id="social-title" name="title"
                    value="{{ old('title', $editing['title'] ?? 'Discord') }}" required maxlength="80">
                <div class="form-text">
                    {{ __('admin.social.label_help') }}
                </div>
                <x-admin::translation-fields id="social-translations" :fields="[
                    'title' => ['label' => __('admin.social.label')] + SocialLinks::TRANSLATION_FIELDS['title'],
                ]" :values="$editing['translations'] ?? []" />
            </div>
            <div class="mb-3">
                <label class="form-label" for="social-url">{{ __('admin.social.url') }}</label>
                <input class="form-control" id="social-url" name="url" value="{{ old('url', $editing['url'] ?? '') }}"
                    required maxlength="2000" placeholder="https://…" autocomplete="off">
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-8">
                    <x-admin::icon-picker id="social-icon" name="icon" :label="__('admin.social.icon')"
                        :value="old('icon', $editing['icon'] ?? 'discord')" required />
                </div>
                <div class="col-sm-4">
                    <label class="form-label" for="social-color">{{ __('admin.social.color') }}</label>
                    <input class="form-control form-control-color w-100" id="social-color" name="color" type="color"
                        value="{{ old('color', $editing['color'] ?? '#5865f2') }}" required>
                </div>
            </div>
            <input type="hidden" name="enabled" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" id="social-enabled" type="checkbox" name="enabled" value="1"
                    @checked(old('enabled', $editing['enabled'] ?? true))>
                <label class="form-check-label" for="social-enabled">{{ __('admin.social.enabled') }}</label>
            </div>
        </x-forms.modal>
    </div>
@endsection
