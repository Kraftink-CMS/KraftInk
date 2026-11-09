@use('App\Kraft\Auth\AccountIdentity')
@use('App\Kraft\Auth\AccountPolicy')
@extends('default.layouts.layout')
@section('title', __('site.auth.account.title'))
@section('content')
    @php
        $localPassword = $user->hasLocalPassword();
        $roles = $user->effectiveRoles();
        $usernamePresentation = AccountIdentity::presentation($user);
    @endphp
    <header class="page-heading">
        <div class="d-flex align-items-center gap-3 gap-sm-4 mb-4">
            @if ($avatarControls->canUpload)
                <button class="account-avatar account-avatar-edit" type="button" data-bs-toggle="modal"
                    data-bs-target="#account-avatar-modal" aria-haspopup="dialog"
                    aria-label="{{ __('site.account.settings.edit_avatar') }}">
                    @include('partials.users.avatar', [
                        'avatarUrl' => $avatarControls->url,
                        'avatarSize' => 96,
                    ])
                    <span class="account-avatar-overlay" aria-hidden="true">
                        <i class="bi bi-camera"></i>
                        <span>{{ __('common.actions.edit') }}</span>
                    </span>
                    <span class="account-avatar-badge" aria-hidden="true"><i class="bi bi-camera-fill"></i></span>
                </button>
            @else
                <span class="account-avatar">
                    @include('partials.users.avatar', [
                        'avatarUrl' => $avatarControls->url,
                        'avatarSize' => 96,
                    ])
                </span>
            @endif
            <div class="account-identity">
                <p class="eyebrow small text-body-secondary mb-1">{{ __('site.auth.account.heading') }}</p>
                <h1 class="mb-2">{{ $user->username }}</h1>
                @if ($roles->isNotEmpty())
                    <div class="d-flex flex-wrap gap-2" aria-label="{{ __('admin.members.roles') }}">
                        @foreach ($roles as $role)
                            @include('partials.users.role-badge', ['role' => $role])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        <nav class="account-nav" aria-label="{{ __('site.auth.account.title') }}">
            <a href="{{ route('account.privacy.index') }}">{{ __('site.account.privacy.title') }} ↗</a>
            @if ($capabilities->canManageGames)
                <a href="{{ route('account.games.index') }}">{{ __('site.games.my_profiles') }} ↗</a>
            @endif
            @if ($capabilities->canManageConnections)
                <a href="{{ route('account.connections.index') }}">{{ __('site.account.connections.title') }} ↗</a>
            @endif
            @if ($capabilities->canManageTokens)
                <a href="{{ route('account.tokens.index') }}">{{ __('admin.integrations.auth_api.my_tokens') }} ↗</a>
            @endif
            <a href="{{ route('account.wallet.index') }}">{{ __('site.account.wallet.title') }} ↗</a>
            @if ($capabilities->canAccessAdmin)
                <a href="{{ url('/admin') }}" target="_blank">{{ __('site.auth.account.administration') }} ↗</a>
            @endif
            <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                action="{{ url('/logout') }}">
                @csrf
                <button class="text-button" type="submit">{{ __('site.auth.account.sign_out') }}</button>
            </form>
        </nav>
    </header>
    @if ($avatarControls->canUpload)
        <div class="modal fade" id="account-avatar-modal" tabindex="-1" aria-labelledby="account-avatar-title"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="account-avatar-title">
                            {{ __('site.account.settings.edit_avatar') }}</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal"
                            aria-label="{{ __('common.actions.cancel') }}"></button>
                    </div>
                    <form data-avatar-form data-ajax="true" data-redirect-url="response" data-close-modal-on-success="true" method="post"
                        action="{{ route('account.avatar.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="modal-body">
                            <div class="text-center mb-4">
                                <img src="{{ $avatarControls->url }}"
                                    class="rounded-circle object-fit-cover bg-body-tertiary" width="128" height="128"
                                    alt="" referrerpolicy="no-referrer" data-avatar-preview>
                            </div>
                            <label class="form-label"
                                for="avatar_upload">{{ __('site.account.settings.avatar_upload') }}</label>
                            <input class="form-control" type="file" id="avatar_upload" name="avatar" data-avatar-file
                                accept="image/png,image/jpeg,image/webp" aria-describedby="avatar-help" required>
                            <p class="form-text mb-0" id="avatar-help">{{ __('site.account.settings.avatar_help') }}</p>
                        </div>
                        <div class="modal-footer">
                            @if ($avatarControls->canDelete)
                                <button class="btn btn-outline-danger me-auto" type="submit" form="account-avatar-delete">
                                    {{ __('site.account.settings.remove_avatar') }}
                                </button>
                            @endif
                            <button class="btn btn-outline-secondary" type="button"
                                data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                            <button class="btn btn-primary" type="submit" data-avatar-save
                                disabled>{{ __('common.actions.save') }}</button>
                        </div>
                    </form>
                    @if ($avatarControls->canDelete)
                        <form id="account-avatar-delete" data-ajax="true" data-redirect-url="response" data-close-modal-on-success="true" method="post"
                            action="{{ route('account.avatar.destroy') }}">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
    @unless ($localPassword)
        <div class="notice alert alert-light border">
            {{ __('site.auth.account.recent_sign_in_help') }}
        </div>
    @endunless
    <div @class(['mb-4', 'account-grid' => $canSetPassword])>
        <section class="panel card card-body p-4">
            <h2 class="mb-3">{{ __('site.auth.account.details') }}</h2>
            <form class="d-flex flex-column flex-grow-1" data-ajax="true" data-redirect-url="response" method="post"
                action="{{ url('/account/profile') }}" data-profile-form data-original-email="{{ $user->email }}">
                @csrf
                <div class="form-field mb-3">
                    <label class="form-label" for="username">{{ __('site.auth.account.username') }}</label>
                    <input class="form-control" id="username" name="username" value="{{ old('username', $user->username) }}"
                        required minlength="2" maxlength="40"
                        pattern="{{ $usernamePresentation['pattern'] }}" autocomplete="username"
                        autocapitalize="none" spellcheck="false" @readonly(!$capabilities->canEditUsername)>
                    <div class="form-text">
                        {{ $capabilities->canEditUsername ? $usernamePresentation['help'] : __('site.account.settings.username_locked') }}
                    </div>
                </div>
                <div class="form-field mb-3">
                    <label class="form-label" for="profile_email">{{ __('admin.settings.identity.email') }}</label>
                    <input class="form-control" id="profile_email" type="email" name="email"
                        value="{{ old('email', $user->email) }}" autocomplete="email" data-profile-email
                        @required($user->email || app(AccountPolicy::class)->requiresVerifiedEmail())>
                    @if (!$user->email)
                        <div class="form-text">{{ __('auth_flow.email.profile_help') }}</div>
                    @endif
                </div>
                <div data-profile-confirmation @if (!$capabilities->requiresEmailConfirmation) hidden @endif>
                    <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password"
                        data-profile-confirmation-field @disabled(!$capabilities->requiresEmailConfirmation)>
                </div>
                @if ($capabilities->canChooseLocale)
                    <div class="form-field mb-3">
                        <label class="form-label" for="locale">{{ __('site.auth.account.language') }}</label>
                        <select class="form-select" id="locale" name="locale">
                            @foreach (config('kraft.locales') as $locale => $label)
                                <option value="{{ $locale }}" @selected(old('locale', $user->locale) === $locale)>{{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($user->email && !$user->hasVerifiedEmail())
                    <p class="form-foot text-center mt-4"><a
                            href="{{ url('/verify-email') }}">{{ __('site.auth.verification.submit') }}</a></p>
                @endif
                <div class="border-top pt-3 mt-auto">
                    <button class="button btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                </div>
            </form>
        </section>
        @if ($canSetPassword)
            <section class="panel card card-body p-4">
                <h2 class="mb-3">{{ $localPassword ? __('site.auth.account.password') : __('site.auth.password.set') }}</h2>
                <form class="d-flex flex-column flex-grow-1" data-ajax="true" method="post"
                    action="{{ url('/account/password') }}"
                    @if ($localPassword) data-reset="true" @else data-redirect-url="response" @endif>
                    @csrf
                    <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="current_password">
                    <div class="form-field mb-3">
                        <label class="form-label" for="new_password">{{ __('site.auth.password.new') }}</label>
                        <input class="form-control" id="new_password" type="password" name="password" required
                            minlength="12" autocomplete="new-password">
                    </div>
                    <div class="form-field mb-3">
                        <label class="form-label"
                            for="new_password_confirmation">{{ __('site.auth.password.confirm') }}</label>
                        <input class="form-control" id="new_password_confirmation" type="password"
                            name="password_confirmation" required autocomplete="new-password">
                    </div>
                    <div class="border-top pt-3 mt-auto">
                        <button class="button btn btn-primary"
                            type="submit">{{ $localPassword ? __('site.auth.account.change_password') : __('site.auth.password.set') }}</button>
                    </div>
                </form>
            </section>
        @endif
    </div>
    @include('default.user.account.passkeys.index')
    <section class="panel card card-body p-4 mb-4" id="two-factor">
        <h2>{{ __('site.auth.two_factor.title') }}</h2>
        <p class="text-muted mb-3">{{ __('site.auth.two_factor.help') }}</p>
        @if (!empty($qr))
            <div class="qr-code mb-3">
                {!! $qr !!}
            </div>
            <p class="mb-3"><code>{{ $secret }}</code></p>
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ url('/account/two-factor/confirm') }}">
                @csrf
                <div class="form-field mb-3">
                    <label class="form-label" for="confirm_code">{{ __('site.auth.two_factor.code_help') }}</label>
                    <input class="form-control" id="confirm_code" name="code" inputmode="numeric"
                        autocomplete="one-time-code" maxlength="6" required>
                </div>
                <button class="button btn btn-primary" type="submit">{{ __('site.auth.two_factor.confirm') }}</button>
            </form>
        @elseif($user->two_factor_confirmed_at)
            <div class="notice notice-success alert alert-light border alert-success">
                {{ __('site.auth.two_factor.active') }}
            </div>
            @unless ($capabilities->canDisableTwoFactor)
                <p>{{ __('admin.settings.security.two_factor_cannot_disable') }}</p>
            @else
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ url('/account/two-factor') }}"
                    data-confirm="{{ __('site.auth.two_factor.disable_confirm') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                    <button class="button button-outline btn btn-outline-secondary"
                        type="submit">{{ __('site.auth.two_factor.disable') }}</button>
                </form>
            @endunless
        @else
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ url('/account/two-factor') }}">
                @csrf
                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                <button class="button btn btn-primary" type="submit">{{ __('site.auth.two_factor.setup') }}</button>
            </form>
        @endif
        @if (!empty($recoveryCodes))
            <div class="notice alert alert-light border mt-4">
                <strong>{{ __('site.auth.two_factor.recovery_help') }}</strong>
                <p>{{ __('site.auth.two_factor.recovery_once') }}</p>
            </div>
            <ul class="recovery-codes">
                @foreach ($recoveryCodes as $recoveryCode)
                    <li>{{ $recoveryCode }}</li>
                @endforeach
            </ul>
        @endif
    </section>
    <x-extensions.slot name="account.sections" :context="['user' => $user]" />
@endsection
