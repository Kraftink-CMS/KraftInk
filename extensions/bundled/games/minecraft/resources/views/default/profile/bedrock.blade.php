@extends('default.layouts.layout')
@section('title', __('minecraft::bedrock.title'))
@section('robots', 'noindex,nofollow')
@section('content')
    <section class="row justify-content-center py-5">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body p-4">
                    <h1 class="h3 mb-3">{{ __('minecraft::bedrock.title') }}</h1>
                    @include('minecraft::default.profile.partials.bedrock')
                </div>
            </div>
        </div>
    </section>
@endsection
