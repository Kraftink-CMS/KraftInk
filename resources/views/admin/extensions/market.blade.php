@extends('admin.layouts.layout')
@section('title', __('market.settings.title'))
@section('description', __('market.settings.description'))
@section('content')
    <div class="vstack gap-4">
        @forelse ($catalogues as $source)
            @php
                $editingKey = old('source') === $source['id'] && $errors->hasAny(['site_key', 'password', 'code']);
            @endphp
            <section class="card mb-0" id="market-catalogue-{{ $source['id'] }}">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="card-title">{{ $source['name'] }}</h2>
                    <span @class(['badge', 'text-bg-success' => $source['has_key'], 'text-bg-secondary' => !$source['has_key']])>
                        {{ __($source['has_key'] ? 'market.settings.key_saved' : 'market.settings.public_access') }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="small text-body-secondary text-break mb-0">{{ $source['url'] }}</p>
                    @if ($source['error'])
                        <div class="alert alert-warning mt-3 mb-0" role="status">{{ $source['error']['message'] }}</div>
                    @elseif (!empty($source['access']['message']))
                        <div class="alert alert-info mt-3 mb-0" role="status">{{ $source['access']['message'] }}</div>
                    @endif
                    @if (!empty($source['access']['scope']))
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('market.settings.scope_' . $source['access']['scope']) }}</p>
                    @elseif ($source['state'] === 'unavailable')
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('market.settings.unavailable') }}</p>
                    @elseif (!$source['supports_site_key'])
                        <p class="small text-body-secondary mt-3 mb-0">{{ __('market.settings.public_help') }}</p>
                    @endif
                </div>
                @if ($source['supports_site_key'])
                    @if ($source['has_key'])
                        <div class="card-body border-top">
                            <div class="border rounded p-3">
                                <div class="d-flex align-items-start gap-3">
                                    <i class="bi bi-check-circle-fill text-success fs-4" aria-hidden="true"></i>
                                    <div>
                                        <h3 class="h6 mb-1">{{ __('market.settings.key_configured') }}</h3>
                                        <p class="small text-body-secondary mb-0">{{ __('market.settings.key_private') }}</p>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#market-key-editor-{{ $source['id'] }}" aria-controls="market-key-editor-{{ $source['id'] }}"
                                        aria-expanded="{{ $editingKey ? 'true' : 'false' }}">{{ __('market.settings.replace_key') }}</button>
                                    <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal"
                                        data-bs-target="#market-key-remove-{{ $source['id'] }}">{{ __('market.settings.remove_key') }}</button>
                                </div>
                            </div>
                        </div>
                    @endif
                    <form @class(['collapse' => $source['has_key'], 'show' => $editingKey]) id="market-key-editor-{{ $source['id'] }}" method="post"
                        action="{{ route('admin.extensions.market.update') }}" data-ajax="true"
                        data-redirect-url="response" data-market-settings @if ($source['has_key']) data-market-key-editor @endif>
                        @csrf
                        <input type="hidden" name="source" value="{{ $source['id'] }}">
                        <fieldset class="card-body border-top" data-market-key-fields @disabled($source['has_key'] && !$editingKey)>
                            <div class="mb-3">
                                <label class="form-label" for="market-key-{{ $source['id'] }}">{{ __($source['has_key'] ? 'market.settings.new_key' : 'market.settings.site_key') }}</label>
                                <input @class(['form-control', 'is-invalid' => old('source') === $source['id'] && $errors->has('site_key')])
                                    id="market-key-{{ $source['id'] }}" type="password" name="site_key" minlength="16" maxlength="512"
                                    autocomplete="new-password" required
                                    aria-describedby="{{ 'market-key-help-' . $source['id'] . (old('source') === $source['id'] && $errors->has('site_key') ? ' market-key-error-' . $source['id'] : '') }}"
                                    aria-invalid="{{ old('source') === $source['id'] && $errors->has('site_key') ? 'true' : 'false' }}" data-market-site-key>
                                @if (old('source') === $source['id'])
                                    @error('site_key')
                                        <div class="invalid-feedback" id="market-key-error-{{ $source['id'] }}">{{ $message }}</div>
                                    @enderror
                                @endif
                                <div class="form-text" id="market-key-help-{{ $source['id'] }}">{{ __('market.settings.key_help') }}</div>
                            </div>
                            <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password" disabled>
                        </fieldset>
                        <div class="card-footer d-flex flex-wrap gap-2">
                            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                            @if ($source['has_key'])
                                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#market-key-editor-{{ $source['id'] }}">{{ __('common.actions.cancel') }}</button>
                            @endif
                        </div>
                    </form>
                @endif
            </section>
            @if ($source['supports_site_key'] && $source['has_key'])
                <x-forms.modal :id="'market-key-remove-' . $source['id']" :title="__('market.settings.remove_key') . ' · ' . $source['name']"
                    :action="route('admin.extensions.market.update')" :submit="__('market.settings.remove_key')" variant="danger" size="sm">
                    <p class="mb-0">{{ __('market.settings.remove_key_confirm') }}</p>
                    <input type="hidden" name="source" value="{{ $source['id'] }}">
                    <input type="hidden" name="remove_key" value="1">
                </x-forms.modal>
            @endif
        @empty
            <div class="alert alert-info mb-0">{{ __('market.settings.no_sources') }}</div>
        @endforelse
    </div>
@endsection
