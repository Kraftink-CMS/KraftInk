@extends('default.layouts.layout')
@section('title', __('minecraftbedrock::messages.identity_title'))
@section('robots', 'noindex,nofollow')

@section('content')
    <section class="row justify-content-center py-5">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h1 class="h3 mb-3">{{ __('minecraftbedrock::messages.identity_title') }}</h1>
                    @if ($gamertag !== null)
                        <p class="text-break">{{ __('minecraftbedrock::messages.identity_received', ['gamertag' => $gamertag]) }}</p>
                        <form method="post" action="{{ route('games.minecraftbedrock.identity.confirm') }}">
                            @csrf
                            <input type="hidden" name="challenge" value="{{ $nonce }}">
                            <button class="btn btn-primary" type="submit">{{ __('minecraftbedrock::messages.identity_confirm') }}</button>
                        </form>
                    @elseif ($command !== null)
                        <p>{{ __('minecraftbedrock::messages.identity_instructions', ['server' => $server->name]) }}</p>
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <code class="bg-body-tertiary border rounded text-body text-break user-select-all flex-grow-1 px-3 py-2"
                                dir="ltr">{{ $command }}</code>
                            <button class="btn btn-outline-secondary" type="button" data-copy="{{ $command }}"
                                data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                        </div>
                        <a class="btn btn-primary" href="{{ route('games.minecraftbedrock.identity.index') }}">
                            {{ __('minecraftbedrock::messages.identity_check') }}
                        </a>
                    @elseif ($server !== null)
                        <p>{{ __('minecraftbedrock::messages.identity_intro') }}</p>
                    @else
                        <p>{{ __('minecraftbedrock::messages.identity_unavailable') }}</p>
                    @endif

                    <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
                        @if ($server !== null)
                            <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
                                action="{{ route('games.minecraftbedrock.identity.start') }}">
                                @csrf
                                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                                <button class="btn {{ $gamertag !== null || $command !== null ? 'btn-outline-secondary' : 'btn-primary' }}" type="submit">
                                    {{ __($gamertag !== null || $command !== null ? 'minecraftbedrock::messages.identity_create_new_code' : 'minecraftbedrock::messages.identity_create_code') }}
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('account.games.index') }}">{{ __('site.games.my_profiles') }}</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
