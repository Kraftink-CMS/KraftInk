@extends('install.layouts.layout')
@section('title', __('system.imports.installation_title'))
@section('content')
    <header class="page-heading" style="padding-top:0">
        <p class="eyebrow small text-body-secondary">{{ __('system.imports.installation_step') }}</p>
        <h1>{{ __('system.imports.installation_heading') }}</h1>
        <p>{{ __('system.imports.installation_hint') }}</p>
        <a href="{{ url('/admin') }}" class="button button-outline btn btn-outline-secondary"
            style="margin-top:20px">{{ __('system.imports.skip') }} ↗</a>
    </header>
    @include('partials.imports.form')
@endsection
