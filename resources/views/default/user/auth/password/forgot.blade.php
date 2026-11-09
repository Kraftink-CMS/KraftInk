@extends('default.layouts.layout')
@section('title', __('site.auth.reset.title'))
@section('content')
    <section class="auth-page">
        <p class="eyebrow small text-body-secondary">{{ __('site.auth.reset.heading') }}</p>
        <h1>{{ __('site.auth.reset.back') }}</h1>
        @include('default.user.auth.partials.forgot')
    </section>
@endsection
