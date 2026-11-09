@extends('default.layouts.layout')
@section('title', __('common.labels.login'))
@section('content')
    <section class="auth-page" data-auth-shell>
        <p class="eyebrow small text-body-secondary" data-auth-step-intro>{{ __('site.auth.login.welcome') }}</p>
        <h1 data-auth-step-title tabindex="-1">{{ __('site.auth.login.heading') }}</h1>
        @include('default.user.auth.partials.login')
    </section>
@endsection
