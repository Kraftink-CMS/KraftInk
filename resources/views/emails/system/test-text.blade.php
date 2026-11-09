@use('App\Kraft\SiteDates')
@use('App\Kraft\Site\SiteIdentity')
@extends('emails.layouts.text')

@section('content')
{!! __('admin.settings.system.test_subject') !!}

{!! __('admin.settings.system.test_message', ['site' => app(SiteIdentity::class)->text('name')]) !!}
{!! __('admin.settings.system.test_time', ['date' => app(SiteDates::class)->format(now())]) !!}
@endsection
