@extends('default.layouts.layout')
@section('title', __('site.auth.two_factor.title'))
@section('content')
    <section class="auth-page">
        <p class="eyebrow small text-body-secondary">{{ __('site.auth.two_factor.heading') }}</p>
        <h1>{{ __('site.auth.two_factor.step') }}</h1>
        @include('default.user.auth.partials.two-factor')
    </section>
@endsection
