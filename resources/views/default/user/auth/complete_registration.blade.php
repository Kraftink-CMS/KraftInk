@extends('default.layouts.layout')
@section('title', __('auth_flow.game.registration_title'))
@section('content')
    <section class="auth-page">
        <h1>{{ __('auth_flow.game.registration_title') }}</h1>
        @include('default.user.auth.partials.complete-registration')
    </section>
@endsection
