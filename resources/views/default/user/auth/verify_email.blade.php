@extends('default.layouts.layout')
@section('title', __('site.auth.verification.title'))
@section('content')
    <section class="auth-page">
        <h1>{{ __('site.auth.verification.heading') }}</h1>
        @include('default.user.auth.partials.verify-email')
    </section>
@endsection
