@extends('default.layouts.layout')
@section('title', __('site.auth.password.new'))
@section('content')
    <section class="auth-page">
        <p class="eyebrow small text-body-secondary">{{ __('site.auth.password.fresh_start') }}</p>
        <h1 class="mb-4">{{ __('site.auth.password.choose') }}</h1>
        @include('default.user.auth.partials.reset')
    </section>
@endsection
