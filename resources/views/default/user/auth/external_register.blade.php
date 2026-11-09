@extends('default.layouts.layout')
@section('title', __('site.auth.security.complete'))
@section('content')
    <section class="auth-page">
        <h1>{{ __('site.auth.security.complete') }}</h1>
        @include('default.user.auth.partials.external-register')
    </section>
@endsection
