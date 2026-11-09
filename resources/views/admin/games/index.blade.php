@use('App\Kraft\Extensions\ExtensionManager')
@extends('admin.layouts.layout')
@section('title', __('site.games.title'))
@section('description', __('site.games.description'))
@section('actions')
    @if (auth()->user()->hasPermission('extensions.manage'))
        <button class="btn btn-primary" type="button" data-bs-toggle="modal"
            data-bs-target="#extension-package">{{ __('site.games.add_game') }}</button>
    @endif
@endsection
@section('content')
    @if ($safeMode)
        <div class="alert alert-secondary">{{ __('system.extensions.safe_mode') }}</div>
    @endif
    @if ($games && auth()->user()->hasPermission('settings.manage'))
        @php
            $selectedGame = old('default_game', $settings['games.default'] ?? array_key_first($games));
            if (!array_key_exists($selectedGame, $games)) {
                $selectedGame = array_key_first($games);
            }
        @endphp
        <form data-ajax="true" class="card" method="post" action="{{ route('admin.games.update') }}" data-game-form
            data-original-game="{{ $settings['games.default'] ?? array_key_first($games) }}">
            @csrf
            <div class="card-body">
                <div class="mb-4">
                    <label class="form-label" for="default_game">{{ __('site.games.default_game') }}</label>
                    <select class="form-select" id="default_game" name="default_game" data-active-game
                        aria-describedby="default_game_help" required>
                        @foreach ($games as $id => $game)
                            <option value="{{ $id }}" @selected($selectedGame === $id)>{{ $game['name'] }}</option>
                        @endforeach
                    </select>
                    <div class="form-text" id="default_game_help">
                        {{ __('site.games.single_game_help') }}
                    </div>
                </div>
                @foreach ($games as $id => $game)
                    <fieldset class="border-0 p-0 m-0" data-game-settings="{{ $id }}"
                        @if ($selectedGame !== $id) hidden disabled @endif>
                        <legend class="float-none w-auto fs-5 fw-semibold">{{ $game['name'] }}</legend>
                        <p class="text-body-secondary">{{ __($game['description']) }}</p>
                        <section class="border-bottom pb-3 mb-4">
                            <h2 class="h6 mb-3">{{ __('site.games.features') }}</h2>
                            <div class="row g-3">
                                @foreach ($gameCapabilities[$id] ?? [] as $feature => $capability)
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="bi {{ $capability['available'] ? 'bi-check-circle text-success' : 'bi-dash-circle text-body-secondary' }} mt-1"
                                                aria-hidden="true"></i>
                                            <div>
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <strong>{{ __('site.games.features_' . $feature) }}</strong>
                                                    <span
                                                        class="visually-hidden">{{ __($capability['available'] ? 'site.games.feature_available' : 'site.games.feature_unavailable') }}</span>
                                                    @if (!$capability['available'])
                                                        <span class="badge text-bg-secondary"
                                                            aria-hidden="true">{{ __('site.games.feature_unavailable') }}</span>
                                                    @endif
                                                    @if ($capability['available'] && !($capability['ready'] ?? true))
                                                        <span
                                                            class="badge text-bg-secondary">{{ __('site.games.feature_setup') }}</span>
                                                    @endif
                                                </div>
                                                <p class="text-body-secondary small mb-0">
                                                    {{ __($capability['help'] ?? 'site.games.features_' . $feature . '_help') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                        @if ($game['settings'])
                            @include('admin.games.partials.settings-fields', [
                                'fieldValues' => $gameFieldValues[$id] ?? [],
                                'inlineSettings' => true,
                            ])
                        @endif
                        @if (!empty($game['has_site_access']) && $id === ($settings['games.default'] ?? array_key_first($games)))
                            <a href="{{ route('admin.settings.accounts.index') }}#connexion-inscription"
                                class="btn btn-outline-secondary btn-sm">{{ __('auth_flow.admin.open_auth_settings') }}</a>
                        @endif
                        @if (
                            $id === ($settings['games.default'] ?? array_key_first($games)) &&
                                !empty($game['connections']) &&
                                auth()->user()->hasPermission('servers.manage'))
                            <a href="{{ route('admin.servers.index') }}"
                                class="btn btn-outline-secondary btn-sm">{{ __('site.games.configure_servers') }}</a>
                        @endif
                        @if ($id === ($settings['games.default'] ?? array_key_first($games)) && auth()->user()->hasPermission('settings.manage'))
                            <a href="{{ route('admin.settings.avatars.index') }}"
                                class="btn btn-outline-secondary btn-sm">{{ __('site.games.configure_avatars') }}</a>
                        @elseif ($id !== ($settings['games.default'] ?? array_key_first($games)))
                            <p class="form-text mb-0">{{ __('site.games.save_before_configuration') }}</p>
                        @endif
                    </fieldset>
                @endforeach
                <p class="form-text mt-3 mb-0">{{ __('site.games.change_preserves_data') }}</p>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ __('common.actions.save') }}</button>
            </div>
        </form>
    @endif
    <h2 class="h5">{{ __('site.games.modules') }}</h2>
    @include('admin.extensions.partials.list', [
        'extensions' => $gameModules,
        'type' => 'game',
        'activeGame' => $settings['games.default'] ?? array_key_first($games),
        'manager' => app(ExtensionManager::class),
    ])
    @if (auth()->user()->hasPermission('extensions.manage'))
        @include('admin.extensions.partials.package')
    @endif
@endsection
