@extends('admin.layouts.layout')
@section('title', __('admin.settings.system.title'))
@section('description', __('admin.settings.system.description'))
@section('content')
    @php
        $mailTransport = old('transport', $mail['transport'] ?: 'smtp');
    @endphp
    <div class="row g-4 align-items-start">
        <div class="col-lg-8">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.settings.system.mail_title') }}</h2>
                </div>
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('admin.settings.system.mail.update') }}" data-mail-settings
                    data-mail-original='@json($mailOriginal)'
                    data-mail-has-password="{{ $hasMailPassword ? '1' : '0' }}">
                    @csrf
                    <div class="card-body">
                        <input type="hidden" name="enabled" value="0">
                        <div class="form-check form-switch mb-4">
                            <input class="form-check-input" type="checkbox" id="mail-enabled" name="enabled" value="1"
                                @checked(old('enabled', $mail['enabled']))>
                            <label class="form-check-label"
                                for="mail-enabled">{{ __('admin.settings.system.mail_use_settings') }}</label>
                        </div>
                        <div class="mb-4">
                            <label class="form-label"
                                for="mail-transport">{{ __('admin.settings.system.mail_transport') }}</label>
                            <select class="form-select" name="transport" id="mail-transport" data-mail-transport
                                data-select-help="mail-transport-help" aria-describedby="mail-transport-help">
                                <option value="smtp" data-help="{{ __('admin.settings.system.smtp_help') }}"
                                    @selected($mailTransport === 'smtp')>{{ __('admin.settings.system.mail_smtp') }}</option>
                                <option value="sendmail" data-help="{{ __('admin.settings.system.sendmail_help') }}"
                                    @selected($mailTransport === 'sendmail') @disabled(!$sendmailAvailable && $mailTransport !== 'sendmail')>
                                    {{ __('admin.settings.system.mail_sendmail') }}{{ $sendmailAvailable ? '' : ' · ' . __('admin.settings.system.unavailable') }}
                                </option>
                            </select>
                            <div class="form-text" id="mail-transport-help" aria-live="polite">
                                {{ __($mailTransport === 'sendmail' ? 'admin.settings.system.sendmail_help' : 'admin.settings.system.smtp_help') }}
                            </div>
                        </div>
                        <div class="alert alert-secondary" data-mail-sendmail
                            @if ($mailTransport !== 'sendmail') hidden @endif>
                            {{ $sendmailAvailable ? __('admin.settings.system.sendmail_sender_help') : __('admin.settings.system.sendmail_unavailable') }}
                        </div>
                        <fieldset data-mail-smtp @if ($mailTransport !== 'smtp') hidden disabled @endif>
                            <div class="row g-3">
                                <div class="col-md-8 mb-3">
                                    <label class="form-label"
                                        for="mail_host">{{ __('admin.settings.system.mail_host') }}</label>
                                    <input class="form-control" id="mail_host" name="host"
                                        value="{{ old('host', $mail['host']) }}" maxlength="255"
                                        placeholder="smtp.example.org">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"
                                        for="mail_port">{{ __('admin.settings.system.mail_port') }}</label>
                                    <input class="form-control" id="mail_port" type="number" name="port"
                                        value="{{ old('port', $mail['port'] ?: 587) }}" min="1" max="65535">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label"
                                        for="mail_security">{{ __('admin.settings.system.mail_security') }}</label>
                                    <select class="form-select" id="mail_security" name="security">
                                        @foreach (['starttls', 'smtps', 'none'] as $security)
                                            <option value="{{ $security }}" @selected(old('security', $mail['security'] ?: 'starttls') === $security)>
                                                {{ __('admin.settings.system.security_' . $security) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"
                                        for="mail_username">{{ __('admin.settings.system.mail_username') }}</label>
                                    <input class="form-control" id="mail_username" name="username"
                                        value="{{ old('username', $mail['username']) }}" maxlength="255"
                                        autocomplete="off">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"
                                        for="mail_password">{{ __('admin.settings.system.mail_password') }}</label>
                                    <input class="form-control" id="mail_password" type="password" name="mail_password"
                                        autocomplete="new-password" maxlength="1024">
                                    <div class="form-text">
                                        {{ $hasMailPassword ? __('admin.settings.system.password_saved') : __('admin.settings.system.password_empty') }}
                                    </div>
                                </div>
                            </div>
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="clear-mail-password"
                                    name="clear_password" value="1" @checked(old('clear_password'))>
                                <label class="form-check-label"
                                    for="clear-mail-password">{{ __('admin.settings.system.clear_password') }}</label>
                            </div>
                        </fieldset>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"
                                    for="from_address">{{ __('admin.settings.system.from_address') }}</label>
                                <input class="form-control" id="from_address" type="email" name="from_address"
                                    value="{{ old('from_address', $mail['from_address'] ?: auth()->user()->email) }}"
                                    maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"
                                    for="from_name">{{ __('admin.settings.system.from_name') }}</label>
                                <input class="form-control" id="from_name" name="from_name"
                                    value="{{ old('from_name', $mail['from_name'] ?: config('app.name')) }}"
                                    maxlength="120">
                            </div>
                        </div>
                        <fieldset class="d-none" data-mail-confirmation
                            @if (!$errors->has('password') && !$errors->has('code')) hidden disabled @endif>
                            @include('partials.forms.confirm-action', [
                                'confirmationId' => 'mail-settings',
                            ])
                        </fieldset>
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button type="submit"
                            class="btn btn-primary">{{ __('admin.settings.system.save_mail') }}</button>
                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal"
                            data-bs-target="#mail-test"
                            @disabled(!$mail['enabled'])>{{ __('admin.settings.system.send_test') }}</button>
                    </div>
                </form>
            </section>
        </div>
        <x-forms.modal id="mail-test" :redirect-url="null" :title="__('admin.settings.system.send_test')" :action="route('admin.settings.system.mail.test')" :submit="__('admin.settings.system.send_test')"
            size="sm">
            <p class="text-body-secondary">{{ __('admin.settings.system.test_help') }}</p>
            <label class="form-label" for="recipient">{{ __('admin.settings.system.test_recipient') }}</label>
            <input class="form-control" id="recipient" type="email" name="recipient"
                value="{{ auth()->user()->email }}" required>
        </x-forms.modal>
        <aside class="col-lg-4">
            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.settings.system.diagnostics') }}</h2>
                </div>
                <div class="card-body">
                    <dl>
                        @foreach (['kraftink', 'laravel', 'php', 'database', 'cache_driver', 'queue', 'session_driver'] as $item)
                            <dt class="small text-secondary mt-3">{{ __('admin.settings.system.diagnostic_' . $item) }}
                            </dt>
                            <dd class="mb-0">{{ $diagnostics[$item] }}</dd>
                        @endforeach
                        <dt class="small text-secondary mt-3">
                            {{ __('admin.settings.system.visitor_ip') }}</dt>
                        <dd class="mb-0">{{ request()->ip() }}</dd>
                    </dl>
                    <p><span @class([
                        'badge text-bg-secondary',
                        'opacity-50' => !$diagnostics['cache_ok'],
                    ])>{{ __('admin.settings.system.cache_check') }} ·
                            {{ $diagnostics['cache_ok'] ? __('admin.settings.system.ok') : __('admin.settings.system.failed') }}</span>
                    </p>
                    <p><span @class([
                        'badge text-bg-secondary',
                        'opacity-50' => !$diagnostics['storage_ok'],
                    ])>{{ __('admin.settings.system.storage_check') }} ·
                            {{ $diagnostics['storage_ok'] ? __('admin.settings.system.ok') : __('admin.settings.system.failed') }}</span>
                    </p>
                    @if ($diagnostics['queued_jobs'] !== null)
                        <p>{{ __('admin.settings.system.queued_jobs') }} : {{ $diagnostics['queued_jobs'] }}</p>
                    @endif
                    @if ($diagnostics['failed_jobs'] !== null)
                        <p>{{ __('admin.settings.system.failed_jobs') }} : {{ $diagnostics['failed_jobs'] }}</p>
                    @endif
                    <h3 class="h6 mt-4">
                        {{ __('admin.settings.system.php_extensions') }}</h3>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach ($diagnostics['extensions'] as $name => $available)
                            <span @class(['badge text-bg-secondary', 'opacity-50' => !$available])>{{ $name }} {{ $available ? '✓' : '×' }}</span>
                        @endforeach
                    </div>
                    <p class="small text-secondary mt-4 mb-0">{{ __('admin.settings.system.diagnostics_help') }}</p>
                </div>
            </section>
        </aside>
    </div>
@endsection
