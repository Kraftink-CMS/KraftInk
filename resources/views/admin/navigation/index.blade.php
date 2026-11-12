@use('App\Kraft\LocalePolicy')
@use('App\Kraft\Navigation')
@use('Illuminate\Support\Str')
@extends('admin.layouts.layout')
@section('title', __('site.community.navigation_title'))
@section('description', __('site.community.navigation_description'))
@section('content')
    <section class="card">
        <div class="card-header">
            <div class="nav nav-tabs card-header-tabs" role="tablist" aria-label="{{ __('site.community.link_location') }}">
                @foreach ($locations as $location)
                    <button @class(['nav-link', 'active' => $newLocation === $location]) id="navigation-tab-{{ $location }}" type="button" role="tab"
                        data-bs-toggle="tab" data-bs-target="#navigation-location-{{ $location }}"
                        aria-controls="navigation-location-{{ $location }}"
                        aria-selected="{{ $newLocation === $location ? 'true' : 'false' }}">
                        {{ $locationLabels[$location] }}</button>
                @endforeach
            </div>
        </div>
        <div class="card-body border-bottom">
            <p class="text-body-secondary mb-0">{{ __('site.community.navigation_list_help') }}</p>
        </div>
        <div class="tab-content">
            @foreach ($locations as $location)
                <div @class(['tab-pane fade', 'show active' => $newLocation === $location]) id="navigation-location-{{ $location }}" role="tabpanel"
                    aria-labelledby="navigation-tab-{{ $location }}" tabindex="0">
                    <div class="card-body border-bottom d-flex justify-content-end">
                        @if ($editingLink)
                            <a class="btn btn-primary"
                                href="{{ route('admin.navigation.index', ['location' => $location, 'create' => 1]) }}">
                                <i class="bi bi-plus-lg me-1"
                                    aria-hidden="true"></i>{{ __('site.community.add_link') }}</a>
                        @else
                            <button class="btn btn-primary" type="button" data-bs-toggle="modal"
                                data-bs-target="#navigation-editor" data-navigation-create-location="{{ $location }}"
                                data-navigation-create-position="{{ ($links->where('location', $location)->whereNull('parent_id')->max('position') ?? -1) + 1 }}"><i
                                    class="bi bi-plus-lg me-1"
                                    aria-hidden="true"></i>{{ __('site.community.add_link') }}</button>
                        @endif
                    </div>
                    <div id="navigation-{{ $location }}-list" data-ajax-pagination data-table tabindex="-1" class="vstack">
                        <x-tables.controls :paginator="$groups[$location]" container-class="card-body pb-0" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th>{{ __('site.community.link_label') }}</th>
                                        <th>{{ __('site.community.link_visibility') }}</th>
                                        <th>{{ __('site.community.link_position') }}</th>
                                        <th class="text-end">{{ __('common.labels.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($groups[$location] as $row)
                                        @php
                                            $link = $row['link'];
                                            $siblings = $links
                                                ->where('location', $location)
                                                ->where('parent_id', $link->parent_id)
                                                ->values();
                                            $linkLabel = app(Navigation::class)->label($link->labels);
                                        @endphp
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    @for ($level = 0; $level < $row['depth']; $level++)
                                                        <span class="ms-3" aria-hidden="true"></span>
                                                    @endfor
                                                    @if ($row['depth'])
                                                        <span class="text-body-secondary"
                                                            aria-label="{{ __('site.community.submenu') }}">↳</span>
                                                    @endif
                                                    <x-content-icon :name="$link->icon" />
                                                    <div>
                                                        <strong>{{ $linkLabel }}</strong>
                                                        @if ($link->parent_id && ($parentLink = $links->firstWhere('id', $link->parent_id)))
                                                            <small class="d-block text-body-secondary">↳ {{ app(Navigation::class)->label($parentLink->labels) }}</small>
                                                        @endif
                                                        @unless ($link->enabled)
                                                            <span
                                                                class="badge text-bg-secondary ms-2">{{ __('common.statuses.hidden') }}</span>
                                                        @endunless
                                                        <small class="d-block text-body-secondary text-break">
                                                            {{ Str::limit($destinations[$link->id] ?? __('site.community.destination_unavailable'), 70) }}</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span
                                                    class="badge text-bg-light border">{{ __('site.community.visibility_' . $link->visibility) }}</span>
                                                @if ($link->roles_restricted)
                                                    <small
                                                        class="d-block text-body-secondary mt-1">{{ __('site.community.restricted_roles') }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group"
                                                    aria-label="{{ __('site.community.link_position') }}">
                                                    @foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $direction => $icon)
                                                        <form data-ajax="true" data-redirect-url="response"
                                                            data-success-message="false" method="post"
                                                            action="{{ route('admin.navigation.move', ['link' => $link] + request()->only(['navigation_header_page', 'navigation_footer_page'])) }}">
                                                            @csrf
                                                            <input type="hidden" name="direction" value="{{ $direction }}">
                                                            <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                                aria-label="{{ __('site.community.move_' . $direction) }}"
                                                                @disabled($direction === 'up' ? $siblings->first()?->getKey() === $link->id : $siblings->last()?->getKey() === $link->id)><i
                                                                    class="bi bi-{{ $icon }}"
                                                                    aria-hidden="true"></i></button>
                                                        </form>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="text-end">
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                        type="button" data-bs-toggle="dropdown"
                                                        aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item"
                                                                href="{{ route('admin.navigation.index', ['edit' => $link->id]) }}">{{ __('common.actions.edit') }}</a>
                                                        </li>
                                                        @if ($row['depth'] < 3)
                                                            <li><a class="dropdown-item"
                                                                    href="{{ route('admin.navigation.index', ['location' => $location, 'parent' => $link->id, 'create' => 1]) }}">{{ __('site.community.add_child') }}</a>
                                                            </li>
                                                        @endif
                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                        <li>
                                                            <form data-ajax="true" data-redirect-url="response" method="post"
                                                                action="{{ route('admin.navigation.destroy', ['link' => $link] + request()->only(['navigation_header_page', 'navigation_footer_page'])) }}"
                                                                data-confirm="{{ __('site.community.confirm_delete_link') }}">
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
                                        <x-tables.empty :colspan="4" :message="__('site.community.empty_menu')" :description="__('site.community.empty_menu_help')" />
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $groups[$location]->links() }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    <x-forms.modal id="navigation-editor" :title="$editingLink ? __('site.community.edit_link') : __('site.community.add_link')" :action="route('admin.navigation.store', ['link' => $editingLink])" :submit="$editingLink ? __('common.actions.save') : __('site.community.add_link')" :open="(bool) $editingLink || request()->boolean('create')"
        data-navigation-form="" data-site-url="{{ url('/') }}"
        data-label-required="{{ __('site.community.label_required') }}">
        @php
            $linkKey = $editingLink?->id ?? 'new';
            $siteLocale = app(LocalePolicy::class)->defaultLocale();
            $linkLocation = old('location', $editingLink?->location ?? $newLocation);
            $linkParent = old('parent_id', $editingLink?->parent_id ?? $newParent);
            $linkUrl = old('url', $editingLink?->url ?? '');
            $selectedRoles = array_map('intval', old('role_ids', $editingLink?->roles->modelKeys() ?? []));
            $choice = old(
                'destination',
                $editingLink
                    ? match ($editingLink->destination_type) {
                        'content' => 'content:' . $editingLink->content_id,
                        'route' => 'route:' . $editingLink->route_name,
                        default => 'url',
                    }
                    : '',
            );
            $excludedParents = $editingLink ? [$editingLink->id] : [];
            for ($level = 0; $level < 4; $level++) {
                foreach ($links as $candidate) {
                    if (in_array($candidate->parent_id, $excludedParents, true)) {
                        $excludedParents[] = $candidate->id;
                    }
                }
            }
        @endphp
        @if (app(LocalePolicy::class)->allowsChoice())
            <p class="text-body-secondary">{{ __('site.community.labels_help') }}</p>
            <div class="accordion mb-3">
                @foreach (config('kraft.locales') as $locale => $localeName)
                    @php
                        $labelValue = old('labels.' . $locale, $editingLink?->labels[$locale] ?? '') ?? '';
                        $labelValue = is_string($labelValue) ? $labelValue : '';
                        $labelFilled = trim($labelValue) !== '';
                        $labelError =
                            $errors->has('labels.' . $locale) || ($locale === $siteLocale && $errors->has('labels'));
                    @endphp
                    <div class="accordion-item" data-completion-group>
                        <h3 class="accordion-header">
                            <button @class(['accordion-button', 'collapsed' => !$labelError]) type="button"
                                id="navigation-label-heading-{{ $linkKey }}-{{ $locale }}"
                                data-bs-toggle="collapse"
                                data-bs-target="#navigation-label-panel-{{ $linkKey }}-{{ $locale }}"
                                aria-controls="navigation-label-panel-{{ $linkKey }}-{{ $locale }}"
                                aria-expanded="{{ $labelError ? 'true' : 'false' }}">
                                <span class="d-flex flex-wrap align-items-center gap-2">
                                    <span>{{ $localeName }}</span>
                                    <span @class([
                                        'badge',
                                        'text-bg-success' => $labelFilled,
                                        'text-bg-secondary' => !$labelFilled,
                                    ]) data-completion-status
                                        data-filled-label="{{ __('common.translations.filled') }}"
                                        data-empty-label="{{ __('common.translations.empty') }}"
                                        aria-live="polite">{{ __($labelFilled ? 'common.translations.filled' : 'common.translations.empty') }}</span>
                                </span>
                            </button>
                        </h3>
                        <div @class(['accordion-collapse collapse', 'show' => $labelError])
                            id="navigation-label-panel-{{ $linkKey }}-{{ $locale }}"
                            aria-labelledby="navigation-label-heading-{{ $linkKey }}-{{ $locale }}">
                            <div class="accordion-body">
                                <label class="form-label" for="label_{{ $linkKey }}_{{ $locale }}">
                                    {{ __('site.community.link_label') }} <span
                                        class="text-body-secondary">{{ __('common.labels.optional') }}</span>
                                </label>
                                <input @class(['form-control', 'is-invalid' => $labelError]) id="label_{{ $linkKey }}_{{ $locale }}"
                                    name="labels[{{ $locale }}]" value="{{ $labelValue }}" maxlength="80"
                                    lang="{{ $locale }}"
                                    @if ($locale === $siteLocale) data-navigation-label @endif
                                    data-navigation-translation
                                    @if ($labelError) aria-invalid="true" aria-describedby="navigation-label-error-{{ $linkKey }}-{{ $locale }}" @endif>
                                @if ($labelError || $locale === $siteLocale)
                                    <div class="invalid-feedback"
                                        id="navigation-label-error-{{ $linkKey }}-{{ $locale }}"
                                        @if ($locale === $siteLocale) data-navigation-label-feedback @endif>
                                        {{ $errors->first('labels.' . $locale) ?: ($errors->first('labels') ?: __('site.community.label_required')) }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="mb-3">
                <label class="form-label"
                    for="label_{{ $linkKey }}_{{ $siteLocale }}">{{ __('site.community.link_label') }}</label>
                <input @class([
                    'form-control',
                    'is-invalid' =>
                        $errors->has('labels') || $errors->has('labels.' . $siteLocale),
                ]) id="label_{{ $linkKey }}_{{ $siteLocale }}"
                    name="labels[{{ $siteLocale }}]"
                    value="{{ old('labels.' . $siteLocale, $editingLink?->labels[$siteLocale] ?? '') }}" maxlength="80"
                    autofocus data-navigation-label data-navigation-translation
                    aria-describedby="navigation-label-error-{{ $linkKey }}-{{ $siteLocale }}">
                <div class="invalid-feedback" id="navigation-label-error-{{ $linkKey }}-{{ $siteLocale }}"
                    data-navigation-label-feedback>
                    {{ $errors->first('labels.' . $siteLocale) ?: ($errors->first('labels') ?: __('site.community.label_required')) }}
                </div>
            </div>
        @endif
        <div class="mb-3">
            <label class="form-label"
                for="destination_{{ $linkKey }}">{{ __('site.community.destination_choice') }}</label>
            <select class="form-select" id="destination_{{ $linkKey }}" name="destination" required
                data-navigation-choice>
                <option value="">{{ __('site.community.choose_destination') }}</option>
                <optgroup label="{{ __('site.community.destination_route') }}">
                    @foreach ($routeTargets as $name => $target)
                        <option value="route:{{ $name }}" data-url="{{ route($name) }}"
                            data-label="{{ __($target['label'], [], $siteLocale) }}" @selected($choice === 'route:' . $name)>
                            {{ __($target['label']) }}</option>
                    @endforeach
                </optgroup>
                @if ($contentTargets->isNotEmpty())
                    <optgroup label="{{ __('site.community.destination_content') }}">
                        @foreach ($contentTargets as $content)
                            <option value="content:{{ $content->id }}" data-url="{{ $content->url() }}"
                                data-label="{{ $content->title }}" @selected($choice === 'content:' . $content->id)>
                                {{ $content->title }}
                                @if (app(LocalePolicy::class)->allowsChoice())
                                    · {{ strtoupper($content->locale) }}
                                @endif
                            </option>
                        @endforeach
                    </optgroup>
                @endif
                <option value="url" @selected($choice === 'url')>{{ __('site.community.destination_url') }}</option>
            </select>
        </div>
        <fieldset data-navigation-custom>
            <div class="mb-3">
                <label class="form-label"
                    for="link_url_{{ $linkKey }}">{{ __('site.community.link_address') }}</label>
                <input class="form-control" id="link_url_{{ $linkKey }}" name="url"
                    value="{{ $choice === 'url' ? $linkUrl : '' }}" maxlength="500" placeholder="https://exemple.fr"
                    data-navigation-url>
                <div class="form-text">
                    {{ __('site.community.custom_address_help') }}
                </div>
            </div>
        </fieldset>
        <div class="form-text mb-3" data-navigation-preview-container hidden>
            <a class="text-break" data-navigation-preview target="_blank" rel="noopener noreferrer"></a>
        </div>
        <details @if (
            $linkParent ||
                $editingLink?->roles_restricted ||
                $errors->has('parent_id') ||
                $errors->has('permission') ||
                $errors->has('icon') ||
                $errors->has('role_ids')) open @endif>
            <summary>{{ __('site.community.link_options') }}</summary>
            <div class="pt-3">
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label"
                            for="location_{{ $linkKey }}">{{ __('site.community.link_location') }}</label>
                        <select class="form-select" id="location_{{ $linkKey }}" name="location"
                            data-navigation-location>
                            @foreach ($locations as $location)
                                <option value="{{ $location }}" @selected($linkLocation === $location)>
                                    {{ $locationLabels[$location] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label"
                            for="parent_{{ $linkKey }}">{{ __('site.community.link_parent') }}</label>
                        <select class="form-select" id="parent_{{ $linkKey }}" name="parent_id"
                            data-navigation-parent data-option-filter="location">
                            <option value="">{{ __('site.community.no_parent') }}</option>
                            @foreach ($links as $parent)
                                @unless (in_array($parent->id, $excludedParents, true))
                                    <option value="{{ $parent->id }}" data-option-group="{{ $parent->location }}"
                                        @selected((int) $linkParent === $parent->id) @if ($parent->location !== $linkLocation) hidden disabled @endif>
                                        {{ app(Navigation::class)->label($parent->labels) }}</option>
                                @endunless
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"
                        for="visibility_{{ $linkKey }}">{{ __('site.community.link_visibility') }}</label>
                    <select class="form-select" id="visibility_{{ $linkKey }}" name="visibility">
                        @foreach (['all', 'guest', 'auth'] as $visibility)
                            <option value="{{ $visibility }}" @selected(old('visibility', $editingLink?->visibility ?? 'all') === $visibility)>
                                {{ __('site.community.visibility_' . $visibility) }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="mb-3">
                    <legend class="fs-6">{{ __('site.community.link_roles') }}</legend>
                    <div class="row g-2">
                        @foreach ($roles as $role)
                            <div class="col-sm-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="nav_role_{{ $role->id }}"
                                        name="role_ids[]" value="{{ $role->id }}" @checked(in_array($role->id, $selectedRoles, true))>
                                    <label class="form-check-label"
                                        for="nav_role_{{ $role->id }}">{{ $role->name }}</label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-text">
                        {{ __('site.community.link_roles_help') }}
                    </div>
                    @if ($editingLink?->roles_restricted && $editingLink->roles->isEmpty())
                        <div class="alert alert-warning mt-3 mb-0">
                            <p>{{ __('site.community.missing_roles') }}</p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="clear_role_restriction"
                                    name="clear_role_restriction" value="1" @checked(old('clear_role_restriction'))>
                                <label class="form-check-label"
                                    for="clear_role_restriction">{{ __('site.community.clear_role_restriction') }}</label>
                            </div>
                        </div>
                    @endif
                </fieldset>
                <div class="mb-3">
                    <x-admin::icon-picker id="icon_{{ $linkKey }}" name="icon" :label="__('site.community.link_icon')"
                        :value="old('icon', $editingLink?->icon)" />
                </div>
                <input type="hidden" name="enabled" value="0">
                <div class="form-check mb-3">
                    <input class="form-check-input" id="link_enabled_{{ $linkKey }}" type="checkbox"
                        name="enabled" value="1" @checked(old('enabled', $editingLink?->enabled ?? true))>
                    <label class="form-check-label"
                        for="link_enabled_{{ $linkKey }}">{{ __('site.community.link_enabled') }}</label>
                    <div class="form-text">
                        {{ __('site.community.link_enabled_help') }}
                    </div>
                </div>
                <input type="hidden" name="new_tab" value="0">
                <div class="form-check mb-3">
                    <input class="form-check-input" id="link_new_tab_{{ $linkKey }}" type="checkbox"
                        name="new_tab" value="1" @checked(old('new_tab', $editingLink?->new_tab ?? false))>
                    <label class="form-check-label"
                        for="link_new_tab_{{ $linkKey }}">{{ __('site.community.link_new_tab') }}</label>
                    <div class="form-text">
                        {{ __('site.community.link_new_tab_help') }}
                    </div>
                </div>
                <details>
                    <summary>{{ __('site.community.link_permission') }}</summary>
                    <label class="visually-hidden"
                        for="permission_{{ $linkKey }}">{{ __('site.community.link_permission') }}</label>
                    <input class="form-control mt-3" id="permission_{{ $linkKey }}" name="permission"
                        value="{{ old('permission', $editingLink?->permission) }}" maxlength="100">
                    <div class="form-text">
                        {{ __('site.community.link_permission_help') }}
                    </div>
                </details>
            </div>
        </details>
        <input type="hidden" name="position"
            value="{{ old('position',$editingLink?->position ??$links->where('location', $linkLocation)->where('parent_id', $linkParent ?: null)->max('position') +1) }}">
    </x-forms.modal>
@endsection
