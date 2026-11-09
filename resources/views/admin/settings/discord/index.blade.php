@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Facades\Lang')
@extends('admin.layouts.layout')
@section('title', __('admin.integrations.discord.title'))
@section('description', __('admin.integrations.discord.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('admin.settings.oauth.index') }}">
        <i class="bi bi-person-lock me-1" aria-hidden="true"></i>{{ __('admin.integrations.oauth.title') }}
    </a>
@endsection
@section('content')
    <div class="callout callout-info mb-0">
        <p class="mb-0">{{ __('admin.integrations.discord.setup_intro') }}</p>
    </div>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <form data-ajax="true" data-redirect-url="response" class="vstack gap-4" method="post" action="{{ route('admin.settings.discord.update') }}">
                @csrf
                <section class="card mb-0">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.integrations.discord.application_step') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label"
                                for="discord_roles_provider">{{ __('admin.integrations.discord.application') }}</label>
                            <select class="form-select" id="discord_roles_provider" name="provider"
                                aria-describedby="discord_roles_provider_help">
                                <option value="">{{ __('admin.integrations.discord.choose_application') }}</option>
                                @foreach ($providers as $id => $provider)
                                    @php
                                        $applicationState = !$registry->configured($id)
                                            ? 'application_unconfigured'
                                            : (in_array($id, $enabledProviders, true)
                                                ? 'application_active'
                                                : 'application_inactive');
                                    @endphp
                                    <option value="{{ $id }}" @selected(old('provider', $settings->get('discord.roles.provider')) === $id)>
                                        {{ $provider['name'] }} · {{ __('admin.integrations.discord.' . $applicationState) }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text" id="discord_roles_provider_help">
                                {{ $hasActiveApplication ? __('admin.integrations.discord.application_help') : __('admin.integrations.discord.application_required') }}
                            </div>
                            <a class="d-inline-block mt-2" href="{{ route('admin.settings.oauth.index') }}">
                                {{ __('admin.integrations.discord.application_setup') }}
                            </a>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="discord_roles_bot">{{ __('admin.integrations.discord.bot_token') }}</label>
                            <input class="form-control" id="discord_roles_bot" name="bot_token" type="password"
                                value="" autocomplete="new-password" maxlength="8192"
                                aria-describedby="discord_roles_bot_help">
                            <div class="form-text" id="discord_roles_bot_help">
                                {{ $hasToken ? __('admin.integrations.discord.keep_token') : __('admin.integrations.discord.new_token') }}
                            </div>
                        </div>
                        <a href="https://discord.com/developers/applications" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-box-arrow-up-right me-1"
                                aria-hidden="true"></i>{{ __('admin.integrations.discord.developer_portal') }}
                        </a>
                        <hr>
                        <h3 class="h6">{{ __('admin.integrations.discord.portal_addresses') }}</h3>
                        <div class="row g-3">
                            @foreach (['callback' => route('account.discord.roles.callback'), 'verification' => route('account.discord.roles.index')] as $field => $address)
                                <div class="col-12">
                                    <label class="form-label"
                                        for="discord_roles_{{ $field }}">{{ __('admin.integrations.discord.' . $field) }}</label>
                                    <div class="input-group">
                                        <input class="form-control" id="discord_roles_{{ $field }}"
                                            value="{{ $address }}"
                                            aria-describedby="discord_roles_{{ $field }}_help" readonly>
                                        <button class="btn btn-outline-secondary" type="button"
                                            data-copy="{{ $address }}"
                                            data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                                    </div>
                                    <div class="form-text" id="discord_roles_{{ $field }}_help">
                                        {{ __('admin.integrations.discord.' . $field . '_help') }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
                <section class="card mb-0">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('admin.integrations.discord.information_step') }}</h2>
                    </div>
                    <div class="card-body">
                        <fieldset class="border-0 p-0 mb-3">
                            <legend class="visually-hidden">{{ __('admin.integrations.discord.criteria') }}</legend>
                            <p class="mb-1">{{ __('admin.integrations.discord.criteria_help') }}</p>
                            <p class="small text-body-secondary mb-3">{{ __('admin.integrations.discord.criteria_limit') }}</p>
                            <div class="vstack gap-3">
                                @foreach ($criteria as $key => $criterion)
                                    <div class="form-check">
                                        <input class="form-check-input" id="discord_field_{{ $key }}"
                                            name="fields[]" type="checkbox" value="{{ $key }}"
                                            aria-describedby="discord_field_{{ $key }}_help"
                                            @checked(in_array($key, old('fields', $settings->get('discord.roles.fields', ['role_id', 'role_power'])), true))>
                                        <label class="form-check-label fw-semibold"
                                            for="discord_field_{{ $key }}">{{ __($criterion['name']) }}</label>
                                        <div class="form-text mt-0" id="discord_field_{{ $key }}_help">
                                            {{ Lang::has('admin.integrations.discord.admin_metadata.' . $key) ? __('admin.integrations.discord.admin_metadata.' . $key) : __($criterion['description']) }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </fieldset>
                        <p class="small text-body-secondary">{{ __('admin.integrations.discord.criteria_change_help') }}</p>
                        <hr>
                        <input type="hidden" name="enabled" value="0">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" id="discord_roles_enabled" name="enabled" type="checkbox"
                                value="1" role="switch" aria-describedby="discord_roles_enabled_help"
                                @checked(old('enabled', $settings->get('discord.roles.enabled', false)))>
                            <label class="form-check-label"
                                for="discord_roles_enabled">{{ __('admin.integrations.discord.enable') }}</label>
                            <div class="form-text" id="discord_roles_enabled_help">{{ __('admin.integrations.discord.disable_help') }}
                            </div>
                        </div>
                        @include('partials.forms.sensitive-fields', [
                            'user' => auth()->user(),
                            'formKey' => 'discord_roles_config',
                        ])
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-primary"
                            type="submit">{{ __('admin.integrations.discord.save_configuration') }}</button>
                    </div>
                </section>
            </form>
        </div>
        <div class="col-12 col-xl-5">
            <section class="card card-outline card-primary mb-0">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.integrations.discord.discord_step') }}</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <span @class([
                            'badge',
                            'text-bg-success' => $ready,
                            'text-bg-secondary' => !$ready,
                        ])>
                            {{ $ready ? __('admin.integrations.discord.ready') : __('admin.integrations.discord.not_published') }}
                        </span>
                        @if ($ready)
                            <p class="small text-body-secondary mt-2 mb-0">{{ __('admin.integrations.discord.readiness_help') }}</p>
                        @endif
                    </div>
                    <p>{{ __('admin.integrations.discord.publish_help') }}</p>
                    <p class="small">
                        <i @class([
                            'bi me-1',
                            'bi-check-circle text-success' => $published,
                            'bi-clock text-body-secondary' => !$published,
                        ]) aria-hidden="true"></i>
                        {{ $published ? __('admin.integrations.discord.publish_current') : __('admin.integrations.discord.publish_pending') }}
                    </p>
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal"
                        data-bs-target="#discord-publish" aria-describedby="discord_publish_help"
                        @disabled(!$canPublish)>
                        <i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>{{ __('admin.integrations.discord.publish') }}
                    </button>
                    <p class="form-text mb-0" id="discord_publish_help">
                        {{ $canPublish ? __('admin.integrations.discord.publish_update') : __('admin.integrations.discord.publish_required') }}
                    </p>
                    <hr>
                    <h3 class="h6">{{ __('admin.integrations.discord.discord_setup_title') }}</h3>
                    <ol class="ps-3 mb-3">
                        <li class="mb-2">{{ __('admin.integrations.discord.discord_setup_bot') }}</li>
                        <li class="mb-2">{{ __('admin.integrations.discord.discord_setup_role') }}</li>
                        <li>{{ __('admin.integrations.discord.discord_setup_condition') }}</li>
                    </ol>
                    <div class="accordion mb-3" id="discord-reference">
                        <div class="accordion-item">
                            <h4 class="accordion-header">
                                <button @class(['accordion-button', 'collapsed' => !request()->has('roles_page')]) type="button" data-bs-toggle="collapse"
                                    data-bs-target="#discord-rank-values" aria-expanded="{{ request()->has('roles_page') ? 'true' : 'false' }}"
                                    aria-controls="discord-rank-values">
                                    {{ __('admin.integrations.discord.rank_reference') }}
                                </button>
                            </h4>
                            <div @class(['accordion-collapse collapse', 'show' => request()->has('roles_page')]) id="discord-rank-values">
                                <div class="accordion-body">
                                    <p class="small">{{ __('admin.integrations.discord.rank_reference_help') }}</p>
                                    <div id="discord-role-reference" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
                                        <x-tables.controls :paginator="$roles" :form="false" />
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0">
                                                <thead class="text-nowrap">
                                                    <tr>
                                                        <th scope="col" data-table-sort="name">{{ __('common.labels.name') }}</th>
                                                        <th scope="col" data-table-sort="id">{{ __('admin.integrations.discord.rank_identifier') }}</th>
                                                        <th scope="col" data-table-sort="position">{{ __('admin.integrations.discord.rank_priority') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($roles as $role)
                                                        <tr>
                                                            <td>{{ $role->name }}</td>
                                                            <td>{{ $role->id }}</td>
                                                            <td>{{ $role->position }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        {{ $roles->links(data: ['tableForm' => false]) }}
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="callout callout-info mb-0">
                        <h3 class="h6">{{ __('admin.integrations.discord.member_journey_title') }}</h3>
                        <p class="small mb-0">{{ __('admin.integrations.discord.member_journey') }}</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <div id="admin-discord-revocations" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card mb-0">
            <div class="card-header">
                <h2 class="card-title">{{ __('admin.integrations.discord.operations_title') }}</h2>
            </div>
            <div class="card-body">
                <x-tables.controls :paginator="$revocations" class="mb-3" />
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                    <p class="mb-0">{{ __('admin.integrations.discord.connections_count', ['count' => $connectionCount]) }}</p>
                    <span @class([
                        'badge',
                        'text-bg-success' => $ready,
                        'text-bg-secondary' => !$ready,
                    ])>
                        {{ $ready ? __('admin.integrations.discord.ready') : __('admin.integrations.discord.not_published') }}
                    </span>
                </div>
                <details>
                    <summary>{{ __('admin.integrations.discord.technical_details') }}</summary>
                    <p class="small mt-2 mb-1">{{ __('admin.integrations.discord.scheduler_help') }}</p>
                    <p class="small mb-1">{{ __('admin.integrations.discord.scheduler_command_help') }}</p>
                    <code>php artisan schedule:run</code>
                </details>
                <hr>
                <h3 class="h6">{{ __('admin.integrations.discord.removal_log') }}</h3>
                <p class="small text-body-secondary mb-0">{{ __('admin.integrations.discord.removal_help_admin') }}</p>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="username">{{ __('common.labels.members') }}</th>
                            <th scope="col" data-table-sort="reason">{{ __('admin.integrations.discord.reason') }}</th>
                            <th scope="col" data-table-sort="state">{{ __('common.labels.status') }}</th>
                            <th scope="col" data-table-sort="created_at">{{ __('admin.integrations.discord.requested_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($revocations as $removal)
                            <tr>
                                <td>{{ $removal->user_username ?: '—' }}</td>
                                <td>{{ Lang::has('admin.integrations.discord.reasons.' . $removal->reason) ? __('admin.integrations.discord.reasons.' . $removal->reason) : __('admin.integrations.discord.reasons.configuration_changed') }}
                                </td>
                                <td>
                                    <span
                                        @class([
                                            'badge',
                                            'text-bg-secondary' => $removal->state === 'pending',
                                            'text-bg-warning' => $removal->state === 'authorization_required',
                                            'text-bg-success' => $removal->state === 'completed',
                                        ])>{{ __('admin.integrations.discord.states.' . $removal->state) }}</span>
                                    @if ($removal->last_error)
                                        <small
                                            class="d-block text-body-secondary">{{ __('admin.integrations.discord.errors.' . $removal->last_error) }}</small>
                                    @endif
                                </td>
                                <td>{{ app(SiteDates::class)->format($removal->created_at) }}</td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="4" :message="__('admin.integrations.discord.no_removals')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $revocations->links() }}
                <button class="btn btn-outline-secondary mt-3" type="button" data-bs-toggle="modal"
                    data-bs-target="#discord-retry">
                    <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>{{ __('admin.integrations.discord.retry') }}
                </button>
            </div>
        </section>
    </div>
    <x-forms.modal id="discord-publish" :title="__('admin.integrations.discord.publish_title')" :action="route('admin.settings.discord.publish')" :submit="__('admin.integrations.discord.publish')">
        <p>{{ __('admin.integrations.discord.publish_confirmation') }}</p>
        @include('partials.forms.sensitive-fields', [
            'user' => auth()->user(),
            'formKey' => 'discord_publish',
        ])
    </x-forms.modal>
    <x-forms.modal id="discord-retry" :title="__('admin.integrations.discord.retry')" :action="route('admin.settings.discord.retry')" :submit="__('admin.integrations.discord.retry')">
        <p>{{ __('admin.integrations.discord.retry_help') }}</p>
        @include('partials.forms.sensitive-fields', [
            'user' => auth()->user(),
            'formKey' => 'discord_retry',
        ])
    </x-forms.modal>
@endsection
