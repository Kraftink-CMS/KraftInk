@extends('emails.layouts.layout')

@section('subject', $subject)
@section('preview', $intro)

@section('content')
    <h1>{{ $subject }}</h1>
    <p>{{ $greeting }}</p>
    <p>{{ $intro }}</p>
    @include('emails.partials.button')
    @if ($expires)
        <p>{{ $expires }}</p>
    @endif
    <p class="mail-muted">{{ $ignore }}</p>
@endsection
