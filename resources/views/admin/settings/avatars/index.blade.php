@extends('admin.layouts.layout')
@section('title', __('admin.settings.avatars.title'))
@section('description', __('admin.settings.avatars.description'))
@section('content')
    <form data-ajax="true" class="d-flex flex-column gap-4" method="post"
        action="{{ route('admin.settings.avatars.update') }}" data-avatar-settings>
        @csrf
        <input type="hidden" name="game" value="{{ $game }}">
        <section class="card mb-0">
            <div class="card-body">
                <input type="hidden" name="allow_uploads" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="avatar-uploads" name="allow_uploads" value="1"
                        role="switch" @checked($allowUploads) data-avatar-uploads>
                    <label class="form-check-label"
                        for="avatar-uploads">{{ __('admin.settings.avatars.allow_uploads') }}</label>
                </div>
            </div>
        </section>
        <x-admin::game-settings-card class="mb-0" :title="__('admin.settings.avatars.automatic')" :game="$gameName"
            :heading-level="2">
            <div class="mb-3">
                <label class="form-label" for="avatar-provider">{{ __('admin.settings.avatars.provider') }}</label>
                <select class="form-select" id="avatar-provider" name="provider" data-avatar-provider
                    data-select-help="avatar-provider-help" aria-describedby="avatar-provider-help">
                    <option value="" data-help="{{ $noneDescription }}" @selected($selectedProvider === null)>
                        {{ __('admin.settings.avatars.none') }}</option>
                    @if ($providerUnavailable)
                        <option value="{{ $selectedProvider }}" data-help="{{ __('admin.settings.avatars.unavailable_description') }}" selected>
                            {{ __('admin.settings.avatars.unavailable_provider', ['provider' => $selectedProvider]) }}</option>
                    @endif
                    @foreach ($providerOptions as $id => $provider)
                        <option value="{{ $id }}" data-help="{{ $provider['description'] }}" data-avatar-available="1" @selected($selectedProvider === $id)>
                            {{ $provider['name'] }}</option>
                    @endforeach
                </select>
                <div class="form-text" id="avatar-provider-help" aria-live="polite" @if ($selectedDescription === '') hidden @endif>
                    {{ $selectedDescription }}
                </div>
                @error('provider')
                    <p class="text-danger small mt-2">{{ $message }}</p>
                @enderror
            </div>
            @error('game')
                <p class="text-danger small mt-2">{{ $message }}</p>
            @enderror
            <p class="form-text" data-avatar-priority @if (!$showUploadPriority) hidden @endif>
                {{ __('admin.settings.avatars.uploads_priority') }}
            </p>
            @foreach ($providerOptions as $id => $provider)
                <details class="small" data-avatar-provider-details="{{ $id }}" @if ($selectedProvider !== $id) hidden @endif>
                    <summary>{{ __('admin.settings.avatars.provider_details') }}</summary>
                    <p class="text-body-secondary mt-2 mb-1">{{ __('admin.settings.avatars.shared_data') }}</p>
                    @if ($provider['website'])
                        <a href="{{ $provider['website'] }}" target="_blank" rel="noopener noreferrer">{{ __('admin.settings.avatars.provider_website') }}</a>
                    @endif
                </details>
            @endforeach
        </x-admin::game-settings-card>
        <div>
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
        </div>
    </form>
@endsection
