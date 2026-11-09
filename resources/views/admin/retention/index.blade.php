@extends('admin.layouts.layout')
@section('title', __('retention.title'))
@section('description', __('retention.description'))
@section('content')
    <form class="card" data-ajax="true" data-redirect-url="response" method="post"
        action="{{ route('admin.retention.store') }}">
        @csrf
        <div class="card-body vstack gap-4">
            <section aria-labelledby="retention-history-title">
                <h2 class="h5 mb-3" id="retention-history-title">{{ __('retention.history_title') }}</h2>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="retention-history">{{ __('retention.fields.history.label') }}</label>
                        <input class="form-control @error('history_days') is-invalid @enderror"
                            id="retention-history" name="history_days" type="number" min="1" max="3650"
                            value="{{ old('history_days', $days['history']) }}" required
                            aria-describedby="retention-history-help">
                        <div class="form-text" id="retention-history-help">{{ __('retention.fields.history.help') }}</div>
                        @error('history_days')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="retention-history-limit">{{ __('retention.history_limit') }}</label>
                        <input class="form-control @error('history_limit') is-invalid @enderror"
                            id="retention-history-limit" name="history_limit" type="number" min="1" max="1000"
                            value="{{ old('history_limit', $historyLimit) }}" required
                            aria-describedby="retention-history-limit-help">
                        <div class="form-text" id="retention-history-limit-help">{{ __('retention.history_limit_help') }}</div>
                        @error('history_limit')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <p class="small text-body-secondary mt-3 mb-0">{{ __('retention.preserved_help') }}</p>
            </section>
            <details @if ($errors->hasAny(['temporary_days', 'technical_days'])) open @endif>
                <summary class="fw-semibold">{{ __('retention.other_cleanups') }}</summary>
                <div class="mt-3">
                    <div class="row g-3">
                        @foreach (['temporary', 'technical'] as $category)
                            <div class="col-md-6">
                                <label class="form-label" for="retention-{{ $category }}">{{ __('retention.fields.' . $category . '.label') }}</label>
                                <input class="form-control @error($category . '_days') is-invalid @enderror"
                                    id="retention-{{ $category }}" name="{{ $category }}_days" type="number" min="1" max="3650"
                                    value="{{ old($category . '_days', $days[$category]) }}" required
                                    aria-describedby="retention-{{ $category }}-help">
                                <div class="form-text" id="retention-{{ $category }}-help">{{ __('retention.fields.' . $category . '.help') }}</div>
                                @error($category . '_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                    <p class="small mt-3 mb-0">
                        <a href="{{ route('admin.operations.audit.index') }}">{{ __('retention.audit_link') }}</a>
                    </p>
                </div>
            </details>
            <p class="small text-body-secondary mb-0">{{ __('retention.schedule_help') }}</p>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
        </div>
    </form>
@endsection
