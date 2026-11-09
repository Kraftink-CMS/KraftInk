@extends('admin.layouts.layout')
@php
    $operation = $run['operation'] ?? 'update';
    $gameModule = ($run['extension_type'] ?? null) === 'game';
    $operationTitleKey = match ($operation) {
        'install' => 'system.extensions.operation_title',
        'uninstall' => 'system.extensions.operation_uninstall_title',
        default => 'system.extensions.operation_update_title',
    };
    $operationCompletedKey = match ($operation) {
        'install' => 'system.extensions.operation_completed',
        'uninstall' => 'system.extensions.uninstalled',
        default => 'system.extensions.operation_update_completed',
    };
@endphp
@section('title', __($operationTitleKey))
@section('description', __($operation === 'uninstall' ? 'system.extensions.operation_uninstall_help' :
    'system.extensions.operation_help'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route($gameModule ? 'admin.games.index' : 'admin.extensions.index') }}">
        {{ __($gameModule ? 'site.games.back_to_games' : 'system.extensions.back_to_list') }}
    </a>
@endsection
@section('content')
    @if ($run['state'] === 'staged')
        <div class="alert alert-info">
            {{ __($operation === 'uninstall' ? 'system.extensions.confirm_uninstall_operation' : 'system.extensions.confirm_operation') }}
        </div>
    @endif
    @if ($run['state'] === 'completed')
        <div class="alert alert-success">
            {{ __($gameModule ? 'site.games.module_ready' : $operationCompletedKey) }}
        </div>
    @endif
    <x-admin::updates.screen :runs="[$run]" :operation="$run['operation'] ?? 'update'" />
    @if (in_array($run['state'], ['recovery_required', 'rolling_back', 'backing_up', 'applying', 'migrating', 'verifying']))
        <form data-ajax="true" data-redirect-url="{{ route('admin.extensions.operations.show', $run['id']) }}" class="card" method="post" action="{{ route('admin.extensions.operations.run', $run['id']) }}">
            <div class="card-body">
                <h2 class="h5">{{ __('system.extensions.recover_title') }}</h2>
                <p>{{ __('system.extensions.recover_help') }}</p>
                @csrf
                <input type="hidden" name="recover" value="1">
                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-warning" type="submit">{{ __('system.extensions.recover_title') }}</button>
            </div>
        </form>
    @endif
@endsection
