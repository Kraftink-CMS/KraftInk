@use('App\Kraft\SiteDates')
@extends('default.layouts.layout')
@section('title', __('admin.integrations.discord.title'))
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="d-flex flex-wrap justify-content-between gap-3 align-items-center mb-4">
                <h1 class="h3 mb-0">{{ __('admin.integrations.discord.title') }}</h1>
                <a class="btn btn-outline-secondary"
                    href="{{ route('account.connections.index') }}">{{ __('site.account.connections.title') }}</a>
            </div>
            <p>{{ __('admin.integrations.discord.account_intro') }}</p>
            <div id="account-discord-revocations" data-ajax-pagination tabindex="-1" class="vstack gap-4">
                @foreach ($revocations as $revocation)
                    <div class="alert alert-warning">
                        <strong>{{ __('admin.integrations.discord.states.' . $revocation->state) }}</strong>
                        <p class="mb-0">
                            {{ $revocation->state === 'authorization_required' ? __('admin.integrations.discord.reauthorize_help') : __('admin.integrations.discord.removal_help') }}
                        </p>
                    </div>
                @endforeach
                {{ $revocations->links() }}
            </div>
            <div id="account-discord-connections" data-ajax-pagination tabindex="-1" class="vstack gap-4">
                @foreach ($connections as $connection)
                    <section class="card mb-4">
                        <div class="card-body p-4">
                            <h2 class="h5 mb-3">{{ __('admin.integrations.discord.current_connection') }}</h2>
                            <dl class="row mb-3">
                                <dt class="col-sm-5">{{ __('admin.integrations.discord.consent_date') }}</dt>
                                <dd class="col-sm-7">{{ app(SiteDates::class)->format($connection->consented_at) }}
                                </dd>
                                <dt class="col-sm-5">{{ __('admin.integrations.discord.last_sync') }}</dt>
                                <dd class="col-sm-7">
                                    {{ $connection->last_synced_at ? app(SiteDates::class)->format($connection->last_synced_at) : __('admin.integrations.discord.waiting') }}
                                </dd>
                            </dl>
                            @if ($connection->last_error)
                                <p class="text-warning">
                                    {{ __('admin.integrations.discord.errors.' . $connection->last_error) }}</p>
                            @endif
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('account.discord.roles.destroy', $connection->id) }}">
                                @csrf
                                @method('DELETE')
                                @include('partials.forms.sensitive-fields', [
                                    'user' => auth()->user(),
                                    'formKey' => 'discord_remove_' . $connection->id,
                                ])
                                <button class="btn btn-outline-danger"
                                    type="submit">{{ __('admin.integrations.discord.disconnect') }}</button>
                            </form>
                        </div>
                    </section>
                @endforeach
                {{ $connections->links() }}
            </div>
            @if ($ready)
                <section class="card">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">
                            {{ $connections->total() === 0 ? __('admin.integrations.discord.authorize') : __('admin.integrations.discord.authorize_again') }}
                        </h2>
                        <p>{{ __('admin.integrations.discord.shared_data') }}</p>
                        <dl class="row mb-4">
                            @foreach ($selected as $key)
                                <dt class="col-sm-5">{{ __($criteria[$key]['name']) }}</dt>
                                <dd class="col-sm-7"><span>{{ $preview[$key] ?? '—' }}</span><small
                                        class="d-block text-body-secondary">{{ __($criteria[$key]['description']) }}</small>
                                </dd>
                            @endforeach
                        </dl>
                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('account.discord.roles.start') }}"
                            data-external-redirect="oauth">
                            @csrf
                            <input type="hidden" name="consent" value="0">
                            <div class="form-check mb-3">
                                <input class="form-check-input" id="discord_roles_consent" name="consent" type="checkbox"
                                    value="1" required>
                                <label class="form-check-label"
                                    for="discord_roles_consent">{{ __('admin.integrations.discord.consent') }}</label>
                            </div>
                            @include('partials.forms.sensitive-fields', [
                                'user' => auth()->user(),
                                'formKey' => 'discord_authorize',
                            ])
                            <button class="btn btn-primary"
                                type="submit">{{ __('admin.integrations.discord.continue_discord') }}</button>
                        </form>
                    </div>
                </section>
            @else
                <div class="alert alert-light border">
                    {{ __('admin.integrations.discord.not_available') }}
                </div>
            @endif
        </div>
    </div>
@endsection
