@extends('admin.layouts.layout')
@section('title', __('network::admin.title'))
@section('content')
    @php
        $networkMode = old('mode', $network['mode']);
    @endphp
    <form data-ajax="true" class="card" method="post" action="{{ route('plugins.network.admin.update') }}">
        @csrf
        <div class="card-body">
            <p>{{ __('network::admin.help') }}</p>
            <p>{{ __('network::admin.current_ip', ['ip' => request()->ip()]) }}</p>
            <div class="mb-3">
                <label class="form-label" for="network-mode">{{ __('network::admin.mode') }}</label>
                <select class="form-select" id="network-mode" name="mode" data-fields-target="custom-proxies">
                    @foreach (['direct', 'cloudflare', 'custom'] as $mode)
                        <option value="{{ $mode }}" @if ($mode === 'custom') data-show-fields="1" @endif @selected($networkMode === $mode)>
                            {{ __('network::admin.modes.' . $mode) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <fieldset id="custom-proxies" @if ($networkMode !== 'custom') hidden disabled @endif>
                <label class="form-label" for="network-proxies">{{ __('network::admin.proxies') }}</label>
                <textarea class="form-control" id="network-proxies" name="proxies" rows="5" maxlength="10000" aria-describedby="network-proxies-help">{{ old('proxies', implode("\n", $network['proxies'])) }}</textarea>
                <small class="form-text" id="network-proxies-help">{{ __('network::admin.proxies_help') }}</small>
            </fieldset>
            <p class="form-text mb-0 mt-3">{{ __('network::admin.cloudflare_help') }}</p>
        </div>
        <div class="card-footer d-flex flex-wrap gap-2">
            <button class="btn btn-primary" type="submit">{{ __('network::admin.save') }}</button>
        </div>
    </form>
@endsection
