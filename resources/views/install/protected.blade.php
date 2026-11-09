@extends('install.layouts.layout')
@section('title', __('system.install.access.title'))
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border shadow-none">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">{{ __('system.install.access.title') }}</h1>
                    <p>{{ __($unavailable ? 'system.install.access.unavailable' : 'system.install.access.description') }}</p>
                    <p class="mb-2">{{ __('system.install.access.recovery') }}</p>
                    <p><code>{{ $recoveryFile }}</code></p>
                    <p class="text-body-secondary mb-4">{{ __('system.install.access.warning') }}</p>
                    <a class="btn btn-primary"
                        href="{{ route('install.index', ['locale' => app()->getLocale()]) }}">{{ __('system.install.access.refresh') }}</a>
                </div>
            </div>
        </div>
    </div>
@endsection
