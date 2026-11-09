@extends('emails.layouts.text')

@section('content')
{!! $title !!}

{!! $text !!}
@if ($actionUrl)

{!! $actionUrl !!}
@endif
@endsection
