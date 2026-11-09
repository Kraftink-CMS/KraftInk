@extends('default.layouts.layout')
@section('title', __('site.auth.register.submit'))
@section('content')
    <section class="auth-page" data-auth-shell>
        <p class="eyebrow small text-body-secondary" data-auth-step-intro>{{ __('site.auth.register.intro') }}</p>
        <h1 data-auth-step-title tabindex="-1">{{ __('site.auth.register.heading') }}</h1>
        @include('default.user.auth.partials.register')
    </section>
@endsection
