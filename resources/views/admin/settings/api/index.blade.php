@extends('admin.layouts.layout')
@section('title', __('admin.integrations.auth_api.title'))
@section('description', __('admin.integrations.auth_api.description'))
@section('actions')
    @if (auth()->user()->hasPermission('service-accounts.manage'))
        <a class="btn btn-outline-primary" href="{{ route('admin.service_accounts.index') }}">{{ __('service_accounts.title') }}</a>
    @endif
@endsection
@section('content')
    <div class="callout callout-info mb-0">
        <p class="mb-0">{{ __('admin.integrations.auth_api.setup_example') }}</p>
    </div>
    <div class="row g-4">
        <div class="col-12 col-lg-5">
            <form data-ajax="true" class="card mb-0" method="post" action="{{ route('admin.settings.api.update') }}">
                @csrf
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <h2 class="card-title">{{ __('admin.integrations.auth_api.settings_title') }}</h2>
                    <span data-api-status data-enabled-label="{{ __('admin.integrations.auth_api.status_enabled') }}"
                        data-disabled-label="{{ __('admin.integrations.auth_api.status_disabled') }}"
                        @class([
                            'badge',
                            'text-bg-success' => $settings['auth.api.enabled'] ?? false,
                            'text-bg-secondary' => !($settings['auth.api.enabled'] ?? false),
                        ])>{{ $settings['auth.api.enabled'] ?? false ? __('admin.integrations.auth_api.status_enabled') : __('admin.integrations.auth_api.status_disabled') }}</span>
                </div>
                <div class="card-body">
                    <input type="hidden" name="enabled" value="0">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" id="api_enabled" type="checkbox" name="enabled" value="1"
                            role="switch" aria-describedby="api_enabled_help" @checked(old('enabled', $settings['auth.api.enabled'] ?? false))>
                        <label class="form-check-label" for="api_enabled">{{ __('admin.integrations.auth_api.enable') }}</label>
                        <div class="form-text" id="api_enabled_help">{{ __('admin.integrations.auth_api.enable_help') }}</div>
                    </div>
                    <p class="small text-body-secondary">{{ __('admin.integrations.auth_api.disable_help') }}</p>
                    <hr>
                    <div class="mb-3">
                        <label class="form-label" for="api_lifetime">{{ __('admin.integrations.auth_api.lifetime') }}</label>
                        <div class="input-group">
                            <input @class(['form-control', 'is-invalid' => $errors->has('lifetime')]) id="api_lifetime" type="number" name="lifetime"
                                value="{{ old('lifetime', $settings['auth.api.lifetime'] ?? 1440) }}" min="15"
                                max="43200" required
                                aria-describedby="api_lifetime_unit api_lifetime_help api_lifetime_range @error('lifetime') api_lifetime_error @enderror"
                                aria-invalid="{{ $errors->has('lifetime') ? 'true' : 'false' }}">
                            <span class="input-group-text" id="api_lifetime_unit">{{ __('admin.integrations.auth_api.minutes') }}</span>
                        </div>
                        @error('lifetime')
                            <div class="invalid-feedback d-block" id="api_lifetime_error">{{ $message }}</div>
                        @enderror
                        <div class="form-text" id="api_lifetime_range">{{ __('admin.integrations.auth_api.lifetime_range') }}</div>
                        <div class="form-text" id="api_lifetime_help">{{ __('admin.integrations.auth_api.lifetime_help') }}</div>
                    </div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
                </div>
            </form>
        </div>
        <div class="col-12 col-lg-7">
            <section class="card mb-0">
                <div class="card-header">
                    <h2 class="card-title">{{ __('admin.integrations.auth_api.setup_title') }}</h2>
                </div>
                <div class="card-body">
                    <ol class="ps-3 mb-3">
                        <li class="mb-2">{{ __('admin.integrations.auth_api.setup_steps.enable') }}</li>
                        <li class="mb-2">{{ __('admin.integrations.auth_api.setup_steps.integrate') }}</li>
                        <li>{{ __('admin.integrations.auth_api.setup_steps.sign_in') }}</li>
                    </ol>
                    <div class="mb-3">
                        <label class="form-label" for="auth_api_address">{{ __('admin.integrations.auth_api.api_address') }}</label>
                        <div class="input-group">
                            <input class="form-control" id="auth_api_address" value="{{ url('/api/auth') }}"
                                aria-describedby="auth_api_address_help" readonly>
                            <button class="btn btn-outline-secondary" type="button" data-copy="{{ url('/api/auth') }}"
                                data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                        </div>
                        <div class="form-text" id="auth_api_address_help">{{ __('admin.integrations.auth_api.api_address_help') }}</div>
                    </div>
                    <hr>
                    <h3 class="h6">{{ __('admin.integrations.auth_api.requirements_title') }}</h3>
                    <p class="small mb-1">{{ __('admin.integrations.auth_api.local_login_help') }}</p>
                    <p class="small text-body-secondary">{{ __('admin.integrations.auth_api.https_help') }}</p>
                    <div class="callout callout-info mb-0">
                        <h3 class="h6">{{ __('admin.integrations.auth_api.member_access_title') }}</h3>
                        <p class="small mb-0">{{ __('admin.integrations.auth_api.member_access_help') }}</p>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <div class="accordion" id="auth-api-developer">
        <div class="accordion-item">
            <h2 class="accordion-header">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                    data-bs-target="#auth-api-integration" aria-expanded="false" aria-controls="auth-api-integration">
                    <i class="bi bi-code-slash me-2" aria-hidden="true"></i>{{ __('admin.integrations.auth_api.developer_title') }}
                </button>
            </h2>
            <div class="accordion-collapse collapse" id="auth-api-integration">
                <div class="accordion-body">
                    <p>{{ __('admin.integrations.auth_api.developer_help') }}</p>
                    <div class="row g-4">
                        <div class="col-12 col-xl-7">
                            <h3 class="h6">{{ __('admin.integrations.auth_api.endpoints_title') }}</h3>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('admin.integrations.auth_api.endpoint_request') }}</th>
                                            <th scope="col">{{ __('admin.integrations.auth_api.endpoint_purpose') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ([
                                            'authenticate' => 'POST /api/auth/authenticate',
                                            'challenge' => 'POST /api/auth/challenge',
                                            'verify' => 'POST /api/auth/verify',
                                            'me' => 'GET /api/auth/me',
                                            'games' => 'GET /api/auth/games',
                                            'rotate' => 'POST /api/auth/rotate',
                                            'logout' => 'POST /api/auth/logout',
                                        ] as $endpoint => $endpointRequest)
                                            <tr>
                                                <td><code class="text-nowrap">{{ $endpointRequest }}</code></td>
                                                <td>{{ __('admin.integrations.auth_api.endpoints.' . $endpoint) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-12 col-xl-5">
                            <h3 class="h6">{{ __('admin.integrations.auth_api.scopes_title') }}</h3>
                            <div class="table-responsive mb-3">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">{{ __('admin.integrations.auth_api.scope_name') }}</th>
                                            <th scope="col">{{ __('admin.integrations.auth_api.scope_purpose') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($scopes as $scope => $definition)
                                            <tr>
                                                <td><code>{{ $scope }}</code></td>
                                                <td>{{ __($definition['description']) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="small text-body-secondary">{{ __('admin.integrations.auth_api.scopes_help') }}</p>
                            <p class="small mb-1">{{ __('admin.integrations.auth_api.token_header') }}</p>
                            <code>Authorization: Bearer &lt;access_token&gt;</code>
                        </div>
                    </div>
                    <hr>
                    <p class="small mb-1">{{ __('admin.integrations.auth_api.rotation_help') }}</p>
                    <p class="small text-body-secondary mb-0">{{ __('admin.integrations.auth_api.policy_help') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
