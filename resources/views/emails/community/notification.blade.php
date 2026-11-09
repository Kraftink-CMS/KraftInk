@extends('emails.layouts.layout')

@section('subject', $title)

@section('content')
    <h1>{{ $title }}</h1>
    <p>{!! nl2br(e($text)) !!}</p>
    @if ($actionUrl)
        @include('emails.partials.button', ['action' => __('common.actions.open')])
    @endif
@endsection
