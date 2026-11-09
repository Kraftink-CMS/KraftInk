@extends('admin.layouts.layout')
@section('title', __('system.imports.title'))
@section('description', __('system.imports.description'))
@section('content')
    @include('partials.imports.form')
@endsection
