@extends('default.layouts.layout')
@section('title', __('admin.settings.security.two_factor_title'))
@section('content')
    <section class="container py-5">
        <div class="mx-auto" style="max-width:640px">
            <h1 class="h3">{{ __('admin.settings.security.two_factor_title') }}</h1>
            <p>{{ __('admin.settings.security.two_factor_required') }}</p>
            <a class="btn btn-primary"
                href="{{ route('account.index') }}#two-factor">{{ __('admin.settings.security.setup_two_factor') }}</a>
        </div>
    </section>
@endsection
