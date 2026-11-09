@extends('default.layouts.layout')
@section('title', __('site.auth.security.identity_required'))
@section('content')
    <section class="auth-page">
        <h1>{{ __('site.auth.security.identity_required') }}</h1>
        @include('default.user.auth.partials.verify-game')
    </section>
@endsection
