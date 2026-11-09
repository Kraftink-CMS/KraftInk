@extends('default.layouts.layout')
@section('title', __('notifications.preferences'))
@section('content')
    <div class="container py-4">
        <h1 class="h3">{{ __('notifications.preferences') }}</h1>
        <p class="text-body-secondary">{{ __('notifications.help') }}</p>
        <form class="card" method="post" action="{{ route('account.notifications.preferences.update') }}" data-ajax="true">
            @csrf
            <div class="card-body">
                @forelse ($types as $type => $definition)
                    <fieldset class="mb-3">
                        <legend class="h6 float-none">{{ __($definition['label']) }}</legend>
                        <input type="hidden" name="preferences[{{ $loop->index }}][type]" value="{{ $type }}">
                        @php($preferenceIndex = $loop->index)
                        @foreach ($definition['channels'] as $channel)
                            <input type="hidden" name="preferences[{{ $preferenceIndex }}][channels][{{ $channel }}]" value="0">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="notification-{{ $type }}-{{ $channel }}"
                                    name="preferences[{{ $preferenceIndex }}][channels][{{ $channel }}]" value="1"
                                    @checked(old('preferences.' . $preferenceIndex . '.channels.' . $channel, $definition['values'][$channel]))>
                                <label class="form-check-label" for="notification-{{ $type }}-{{ $channel }}">{{ __('notifications.channels.'.$channel) }}</label>
                            </div>
                        @endforeach
                    </fieldset>
                @empty
                    <p class="text-body-secondary mb-0">{{ __('notifications.empty') }}</p>
                @endforelse
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                <a class="btn btn-outline-secondary" href="{{ route('account.notifications.index') }}">{{ __('notifications.back') }}</a>
            </div>
        </form>
    </div>
@endsection
