@extends('admin.layouts.layout')
@section('title', __('admin.operations.cache'))
@section('description', __('admin.operations.cache_description'))
@section('content')
    <div class="alert alert-info">
        {{ __('admin.operations.cache_safety') }}
    </div>
    <p>{{ __('admin.operations.config_state') }}
        <strong>{{ $cached['config'] ? __('common.statuses.active') : __('common.statuses.inactive') }}</strong> ·
        {{ __('admin.operations.routes_state') }}
        <strong>{{ $cached['routes'] ? __('common.statuses.active') : __('common.statuses.inactive') }}</strong>
    </p>
    <section class="card">
        <div class="card-body p-0 table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">{{ __('common.labels.action') }}</th>
                        <th scope="col">{{ __('common.labels.description') }}</th>
                        <th scope="col">{{ __('common.labels.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($actions as $action)
                        <tr>
                            <th scope="row">{{ __('admin.operations.actions.' . $action . '.title') }}</th>
                            <td>{{ __('admin.operations.actions.' . $action . '.description') }}</td>
                            <td>
                                <form data-ajax="true" method="post" action="{{ route('admin.operations.cache.run') }}">
                                    @csrf
                                    <input type="hidden" name="operation" value="{{ $action }}">
                                    <button class="btn btn-outline-primary btn-sm"
                                        type="submit">{{ __('admin.operations.run') }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
