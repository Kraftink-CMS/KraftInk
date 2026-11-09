@extends('emails.layouts.text')

@section('content')
{!! $subject !!}

{!! $greeting !!}

{!! $intro !!}

{!! $action !!}: {!! $actionUrl !!}

@if ($expires)
{!! $expires !!}

@endif
{!! $ignore !!}
@endsection
