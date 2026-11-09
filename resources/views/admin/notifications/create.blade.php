@extends('admin.layouts.layout')
@section('title', __('site.community.create_notice'))
@section('description', __('site.community.notices_description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.notifications.index') }}">
        {{ __('site.community.notices_title') }}</a>
@endsection
@section('content')
    <section class="card">
        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.notifications.store') }}"
            data-notice-form>
            @csrf
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="notice_title">{{ __('common.labels.title') }}</label>
                    <input class="form-control" id="notice_title" name="title" value="{{ old('title') }}" required
                        maxlength="160">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="notice_body">{{ __('site.community.notice_message') }}</label>
                    <textarea class="form-control" id="notice_body" name="body" data-editor="tinymce" rows="12">{{ old('body') }}</textarea>
                </div>
                <x-admin::translation-fields id="notice-translations" :fields="[
                    'title' => ['label' => __('common.labels.title'), 'max' => 160],
                    'body' => [
                        'label' => __('site.community.notice_message'),
                        'max' => 50000,
                        'type' => 'richtext',
                        'rows' => 8,
                    ],
                ]" />
                <div class="mb-3">
                    <label class="form-label" for="notice_url">{{ __('site.community.notice_url') }}</label>
                    <input class="form-control" id="notice_url" name="url" value="{{ old('url') }}" maxlength="500">
                    <div class="form-text">
                        {{ __('site.community.link_address_help') }}
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6">
                        <fieldset>
                            <legend class="fs-6 fw-semibold">{{ __('site.community.notice_audience') }}</legend>
                            <div class="mb-3">
                                <label class="visually-hidden"
                                    for="audience">{{ __('site.community.notice_audience') }}</label>
                                <select class="form-select" id="audience" name="audience" data-notice-audience>
                                    @foreach (['all', 'role', 'user'] as $audience)
                                        <option value="{{ $audience }}" @selected(old('audience', 'all') === $audience)>
                                            {{ __('site.community.audience_' . $audience) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </fieldset>
                        <fieldset class="mb-3" data-notice-target="role"
                            @if (old('audience', 'all') !== 'role') hidden disabled @endif>
                            <label class="form-label" for="notice_role">{{ __('site.community.target_role') }}</label>
                            <select class="form-select" id="notice_role" name="role_id">
                                <option value="">{{ __('site.community.choose_role') }}</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected((string) old('role_id') === (string) $role->id)>{{ $role->name }}
                                    </option>
                                @endforeach
                            </select>
                        </fieldset>
                        <fieldset class="mb-3" data-notice-target="user"
                            @if (old('audience', 'all') !== 'user') hidden disabled @endif>
                            <label class="form-label" for="notice_email">{{ __('site.community.target_email') }}</label>
                            <input class="form-control" id="notice_email" type="email" name="email"
                                value="{{ old('email') }}" maxlength="255">
                        </fieldset>
                    </div>
                    <div class="col-lg-6">
                        <fieldset>
                            <legend class="fs-6 fw-semibold">{{ __('site.community.notice_publication') }}</legend>
                            <div class="mb-3">
                                <label class="form-label"
                                    for="notice_publication">{{ __('site.community.notice_publication') }}</label>
                                <x-forms.local-datetime name="published_at" id="notice_publication" />
                                <div class="form-text">
                                    {{ __('site.community.publish_now_help') }}
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label"
                                    for="notice_expiration">{{ __('site.community.notice_expiration') }}</label>
                                <x-forms.local-datetime name="expires_at" id="notice_expiration" />
                                <div class="form-text">
                                    {{ __('site.community.no_expiration_help') }}
                                </div>
                            </div>
                        </fieldset>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('site.community.publish_notice') }}</button>
                <a class="btn btn-outline-secondary"
                    href="{{ route('admin.notifications.index') }}">{{ __('common.actions.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
