@extends('default.layouts.layout')
@section('title', __('site.games.my_profiles'))
@section('content')
    <header class="my-4">
        <a href="{{ url('/account') }}" class="d-inline-block mb-3">← {{ __('site.auth.account.title') }}</a>
        <h1 class="h3">{{ __('site.games.my_profiles') }}</h1>
        <p class="text-body-secondary">{{ __('site.games.profile_description') }}</p>
    </header>
    <div class="row g-4 pb-5">
        @foreach ($games as $id => $game)
            @php
                $identity = $identities[$id] ?? null;
            @endphp
            <section class="col-lg-7">
                <div class="card">
                    <div class="card-body p-4">
                        <h2 class="h4">{{ $game['name'] }}</h2>
                        <p class="text-body-secondary">{{ __($game['description']) }}</p>
                        @if ($identity)
                            <span
                                class="badge text-bg-secondary mb-3">{{ $identity->verified_at ? __('site.games.verified') : __('site.games.self_declared') }}</span>
                            <p>
                                <strong>{{ $identity->username }}</strong>
                                @unless ($game['username_identity'] ?? false)
                                    <br><code>{{ $identity->uuid }}</code>
                                @endunless
                            </p>
                        @endif
                        @if ($identity?->verified_at)
                            <p class="text-body-secondary">{{ __('site.games.verified_profile_help') }}</p>
                        @else
                            <div class="alert alert-light border">
                                <p class="mb-1">{{ __('site.games.declaration_help') }}</p>
                                <small>{{ __('site.games.no_ownership_proof') }}</small>
                            </div>
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('account.games.identity', $id) }}">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label"
                                        for="username_{{ $id }}">{{ __($game['username_label'] ?? 'site.games.username') }}</label>
                                    <input class="form-control" id="username_{{ $id }}" name="username"
                                        value="{{ old('username', $identity?->username) }}" required maxlength="60">
                                </div>
                                @unless ($game['username_identity'] ?? false)
                                    <div class="mb-3">
                                        <label class="form-label"
                                            for="uuid_{{ $id }}">{{ __($game['identity_label'] ?? 'site.games.uuid') }}</label>
                                        <input class="form-control" id="uuid_{{ $id }}" name="uuid"
                                            value="{{ old('uuid', $identity?->uuid) }}" required maxlength="64">
                                    </div>
                                @endunless
                                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                                <button class="btn btn-primary" type="submit">{{ __('site.games.save_profile') }}</button>
                            </form>
                        @endif
                        @if ($game['connection_url'])
                            <a class="btn btn-outline-secondary mt-3"
                                href="{{ url($game['connection_url']) }}">{{ __('site.games.verify_ownership') }}</a>
                        @endif
                    </div>
                </div>
            </section>
        @endforeach
    </div>
@endsection
