@use('App\Kraft\Audit')
@use('App\Kraft\Auth\AccountIdentity')
@use('App\Kraft\Settings')
@use('App\Kraft\SiteDates')
@use('Carbon\Carbon')
@extends('admin.layouts.layout')
@section('title', $member ? __('admin.members.edit') . ' · ' . $member->username : __('admin.members.create'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.users.index') }}">
        {{ __('common.labels.members') }}</a>
    @if ($member)
        <x-extensions.slot name="admin.users.edit.actions" :context="['member' => $member]" />
    @endif
@endsection
@section('content')
    @php
        $canEdit = !$member || (!$member->anonymized_at && $hierarchy->canManage(auth()->user(), $member));
        $canViewPersonal = auth()->user()->hasPermission('users.view-personal');
        $canEditPersonal = auth()->user()->hasPermission('users.edit-personal');
        $usernamePresentation = AccountIdentity::presentation($member);
    @endphp
    @if ($member?->anonymized_at)
        <p class="alert alert-secondary">{{ __('admin.members.closed_readonly') }}</p>
    @elseif(!$canEdit)
        <p class="alert alert-secondary">{{ __('admin.members.no_manage') }}</p>
    @endif
    <div class="row g-4 align-items-start">
        <div class="col-xl-8 d-flex flex-column gap-4">
            <form data-ajax="true" data-redirect-url="response" class="card" method="post"
                action="{{ route('admin.users.store', ['user' => $member?->id]) }}">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.members.identity') }}</h2>
                </div>
                <div class="card-body">
                    @csrf
                    <fieldset @disabled(!$canEdit)>
                        <div class="mb-3">
                            <label class="form-label" for="member_username">{{ __('site.auth.account.username') }}</label>
                            <input class="form-control" id="member_username" name="username"
                                value="{{ old('username', $member?->username) }}" minlength="2" maxlength="40"
                                pattern="{{ $usernamePresentation['pattern'] }}" required
                                autocomplete="username" autocapitalize="none" spellcheck="false">
                            <div class="form-text">
                                {{ $usernamePresentation['help'] }}
                            </div>
                        </div>
                        @if (!$member || ($canViewPersonal && $canEditPersonal))
                            <div class="mb-3">
                                <label class="form-label" for="member_email">{{ __('admin.settings.identity.email') }}</label>
                                <input class="form-control" id="member_email" type="email" name="email"
                                    value="{{ old('email', $member?->email) }}" maxlength="255"
                                    @if (!$member || $member->email !== null) required @endif>
                            </div>
                        @elseif($canViewPersonal)
                            <div class="mb-3">
                                <label class="form-label" for="member_email">{{ __('admin.settings.identity.email') }}</label>
                                <input class="form-control" id="member_email" type="email" value="{{ $member->email }}"
                                    readonly>
                                <div class="form-text">
                                    {{ __('admin.members.personal_edit_denied') }}
                                </div>
                            </div>
                        @else
                            <p class="text-body-secondary">{{ __('admin.members.personal_hidden') }}</p>
                        @endif
                        <div class="mb-3">
                            <label class="form-label" for="member_locale">{{ __('common.labels.language') }}</label>
                            <select class="form-select" id="member_locale" name="locale">
                                @foreach (config('kraft.locales', []) as $code => $label)
                                    <option value="{{ $code }}" @selected(old('locale', $member?->locale ?? app()->getLocale()) === $code)>
                                        {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        @unless ($member)
                            <div class="row g-3">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" for="new_password">{{ __('admin.members.new_password') }}</label>
                                    <input class="form-control" id="new_password" name="new_password" type="password"
                                        autocomplete="new-password" required
                                        minlength="{{ app(Settings::class)->get('auth.password.min', 12) }}">
                                    <div class="form-text">
                                        {{ __('admin.members.new_password_help') }}
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"
                                        for="new_password_confirmation">{{ __('site.auth.password.confirm') }}</label>
                                    <input class="form-control" id="new_password_confirmation" name="new_password_confirmation"
                                        type="password" autocomplete="new-password" required>
                                </div>
                            </div>
                        @endunless
                        <fieldset class="mb-3">
                            <legend class="fs-6">{{ __('admin.members.roles') }}</legend>
                            @foreach ($roles as $role)
                                @if (!$canEdit || $hierarchy->canManageRole(auth()->user(), $role))
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" id="member_role_{{ $role->id }}"
                                            type="checkbox" name="roles[]" value="{{ $role->id }}"
                                            @checked(in_array($role->id, array_map('intval', old('roles', $member?->roles->modelKeys() ?? [])), true))>
                                        <label class="form-check-label" for="member_role_{{ $role->id }}">
                                            @include('partials.users.role-badge', ['role' => $role])
                                        </label>
                                    </div>
                                @endif
                            @endforeach
                        </fieldset>
                    </fieldset>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    @if ($canEdit)
                        <button class="btn btn-primary" type="submit">{{ __('admin.members.save') }}</button>
                    @endif
                    <a class="btn btn-outline-secondary"
                        href="{{ route('admin.users.index') }}">{{ __('common.actions.cancel') }}</a>
                </div>
            </form>
            @if ($member)
                <div id="admin-member-bans" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                    <section class="card" id="member-bans">
                        <div class="card-header">
                            <h2 class="card-title">{{ __('admin.members.ban_history') }}</h2>
                        </div>
                        <x-tables.controls :paginator="$bans" container-class="card-body" />
                        <div class="card-body p-0 table-responsive">
                            <table class="table mb-0">
                                <thead class="text-nowrap">
                                    <tr>
                                        <th data-table-sort="reason">{{ __('admin.members.reason') }}</th>
                                        <th>{{ __('admin.members.author') }}</th>
                                        <th data-table-sort="expires_at">{{ __('admin.members.expires') }}</th>
                                        <th data-table-sort="revoked_at">{{ __('admin.members.status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($bans as $ban)
                                        <tr>
                                            <td class="text-break">{{ $ban->reason }}<small
                                                    class="d-block text-body-secondary">{{ app(SiteDates::class)->format($ban->created_at) }}</small>
                                            </td>
                                            <td>{{ $ban->author_name ?? '—' }}</td>
                                            <td>{{ $ban->expires_at ? app(SiteDates::class)->format($ban->expires_at) : __('admin.members.permanent') }}
                                            </td>
                                            <td>
                                                @if ($ban->revoked_at)
                                                    {{ __('admin.members.revoked') }}
                                                    {{ app(SiteDates::class)->format($ban->revoked_at) }}
                                                @elseif($ban->expires_at && Carbon::parse($ban->expires_at)->isPast())
                                                    {{ __('admin.members.expired') }}
                                                @else
                                                    {{ __('admin.members.banned') }}
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-body-secondary p-3">{{ __('admin.members.no_bans') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            {{ $bans->links() }}
                        </div>
                    </section>
                </div>
                @if ($canViewPersonal)
                    <div id="admin-member-activity" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                        <section class="card" id="member-activity">
                            <div class="card-header">
                                <h2 class="card-title">{{ __('admin.members.activity') }}</h2>
                            </div>
                            <x-tables.controls :paginator="$activity" container-class="card-body" />
                            <div class="card-body p-0 table-responsive">
                                <table class="table mb-0">
                                    <thead class="text-nowrap">
                                        <tr>
                                            <th data-table-sort="created_at">{{ __('common.labels.date') }}</th>
                                            <th data-table-sort="action">{{ __('common.labels.action') }}</th>
                                            <th data-table-sort="ip">{{ __('admin.members.ip') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($activity as $entry)
                                            <tr>
                                                <td>{{ app(SiteDates::class)->format($entry->created_at) }}</td>
                                                <td>{{ app(Audit::class)->label($entry->action) }}</td>
                                                <td>{{ $entry->ip ?? '—' }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="text-body-secondary p-3">
                                                    {{ __('admin.members.no_activity') }}</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer">
                                {{ $activity->links() }}
                            </div>
                        </section>
                    </div>

                @endif
            @endif
        </div>
        @if ($member)
            <div class="col-xl-4 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.members.status') }}</h2>
                    </div>
                    <div class="card-body">
                        @include('admin.users.partials.status')
                        <dl class="mt-3 mb-0">
                            <dt>{{ __('admin.members.created') }}</dt>
                            <dd>{{ app(SiteDates::class)->format($member->created_at, 'd/m/Y H:i T') }}</dd>
                            <dt>{{ __('admin.members.last_login') }}</dt>
                            <dd>{{ $member->last_login_at ? app(SiteDates::class)->format($member->last_login_at, 'd/m/Y H:i T') : __('admin.members.never_logged_in') }}
                            </dd>
                            @if ($canViewPersonal && $member->last_login_ip)
                                <dt>{{ __('admin.members.ip') }}</dt>
                                <dd>{{ $member->last_login_ip }}</dd>
                            @endif
                            <dt>
                                {{ __('admin.members.two_factor') }}</dt>
                            <dd>{{ $member->two_factor_confirmed_at ? __('admin.members.enabled') : __('admin.members.disabled') }}
                            </dd>
                        </dl>
                    </div>
                </section>
                @if ($canEdit)
                    <form data-ajax="true" data-redirect-url="response" class="card" method="post"
                        action="{{ route('admin.users.actions.store', $member) }}" data-member-security>
                        <div class="card-header">
                            <h2 class="card-title">{{ __('admin.members.security') }}</h2>
                        </div>
                        <div class="card-body">
                            <p class="text-body-secondary">{{ __('admin.members.security_help') }}</p>
                            @csrf
                            <div class="mb-3">
                                <label class="form-label" for="member_action">{{ __('common.labels.action') }}</label>
                                <select class="form-select" id="member_action" name="action" required>
                                    <option value="">{{ __('admin.members.choose_action') }}</option>
                                    @if (auth()->user()->hasPermission('users.security'))
                                        @foreach (['verify', 'reset-password', 'reset-two-factor', 'revoke-sessions', 'unlink'] as $action)
                                            <option value="{{ $action }}">
                                                {{ __('admin.members.' . str_replace('-', '_', $action)) }}</option>
                                        @endforeach
                                    @endif
                                    @if (auth()->user()->hasPermission('users.ban'))
                                        <option value="ban">{{ __('admin.members.ban') }}</option>
                                        <option value="unban">{{ __('admin.members.unban') }}</option>
                                    @endif
                                    @if (auth()->user()->hasPermission('users.delete'))
                                        <option value="close">{{ __('admin.members.close') }}</option>
                                    @endif
                                </select>
                            </div>
                            <div data-member-fields="ban" hidden>
                                <div class="mb-3">
                                    <label class="form-label" for="ban_reason">{{ __('admin.members.reason') }}</label>
                                    <textarea class="form-control" id="ban_reason" name="reason" minlength="3" maxlength="2000" rows="3"
                                        data-required-for-action></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="ban_expires">{{ __('admin.members.expires') }}</label>
                                    <x-forms.local-datetime name="expires_at" id="ban_expires" />
                                    <div class="form-text">
                                        {{ __('admin.members.expires_help') }}
                                    </div>
                                </div>
                            </div>
                            <div data-member-fields="unlink" class="mb-3" hidden>
                                <label class="form-label" for="member_connection">{{ __('admin.members.connection') }}</label>
                                <select class="form-select" id="member_connection" name="connection"
                                    data-required-for-action>
                                    <option value="">{{ __('admin.members.connection') }}</option>
                                    @foreach ($connections as $connection)
                                        <option value="{{ $connection->id }}">{{ $connection->provider }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div data-member-fields="close" class="mb-3" hidden>
                                <p class="alert alert-warning">{{ __('admin.members.close_warning') }}</p>
                                <div class="form-check">
                                    <input class="form-check-input" id="member_close_confirm" name="confirmation"
                                        type="checkbox" value="1" data-required-for-action>
                                    <label class="form-check-label"
                                        for="member_close_confirm">{{ __('admin.members.close') }}</label>
                                </div>
                            </div>
                            @include('partials.forms.sensitive-fields', [
                                'user' => auth()->user(),
                                'formKey' => 'member_security',
                            ])
                        </div>
                        <div class="card-footer d-flex flex-wrap gap-2">
                            <button class="btn btn-outline-danger" type="submit">{{ __('admin.members.execute') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </div>
@endsection
