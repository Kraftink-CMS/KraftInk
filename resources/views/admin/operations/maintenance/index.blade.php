@extends('admin.layouts.layout')
@section('title', __('admin.operations.maintenance'))
@section('description', __('admin.operations.maintenance_description'))
@section('content')
    <form data-ajax="true" method="post" action="{{ route('admin.operations.maintenance.store') }}">
        @csrf
        <section class="card">
            <div class="card-body">
                <input type="hidden" name="enabled" value="0">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" id="enabled" type="checkbox" name="enabled" value="1"
                        @checked(old('enabled', $values['enabled']))>
                    <label class="form-check-label" for="enabled">{{ __('admin.operations.maintenance_enabled') }}</label>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="message">{{ __('admin.operations.maintenance_message') }}</label>
                    <textarea class="form-control" id="message" name="message" rows="3" maxlength="2000">{{ old('message', $values['message']) }}</textarea>
                    <x-admin::translation-fields id="maintenance-translations" :fields="[
                        'message' => [
                            'label' => __('admin.operations.maintenance_message'),
                            'type' => 'textarea',
                            'rows' => 3,
                            'max' => 2000,
                        ],
                    ]" :values="$values['translations']" />
                </div>
                <div class="mb-3">
                    <label class="form-label" for="mode">{{ __('admin.operations.maintenance_scope') }}</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="all" @selected(old('mode', $values['mode']) === 'all')>{{ __('admin.operations.scope_all') }}</option>
                        <option value="paths" @selected(old('mode', $values['mode']) === 'paths')>{{ __('admin.operations.scope_paths') }}
                        </option>
                    </select>
                </div>
                <p class="form-text">{{ __('admin.operations.maintenance_urls_help') }}</p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="paths">{{ __('admin.operations.maintenance_paths') }}</label>
                        <textarea class="form-control" id="paths" name="paths" rows="5" maxlength="10000">{{ old('paths', implode("\n", $values['paths'])) }}</textarea>
                        <div class="form-text">
                            {{ __('admin.operations.paths_help') }}
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"
                            for="exclusions">{{ __('admin.operations.maintenance_exclusions') }}</label>
                        <textarea class="form-control" id="exclusions" name="exclusions" rows="5" maxlength="10000">{{ old('exclusions', implode("\n", $values['exclusions'])) }}</textarea>
                        <div class="form-text">
                            {{ __('admin.operations.exclusions_help') }}
                        </div>
                    </div>
                </div>
                <p class="form-text mt-3 mb-0">{{ __('admin.operations.maintenance_bypass_help') }}</p>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </section>
    </form>
@endsection
