@use('App\Kraft\SiteDates')
@use('App\Kraft\Site\SiteIdentity')
@extends('emails.layouts.layout')

@section('subject', __('admin.settings.system.test_subject'))

@section('content')
    <h1>{{ __('admin.settings.system.test_subject') }}</h1>
    <p>{{ __('admin.settings.system.test_message', ['site' => app(SiteIdentity::class)->text('name')]) }}</p>
    <p class="mail-muted">{{ __('admin.settings.system.test_time', ['date' => app(SiteDates::class)->format(now())]) }}</p>
@endsection
