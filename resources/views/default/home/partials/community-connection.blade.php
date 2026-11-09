@php
    $publicAddress = $publicSite['communityAddress'];
    $onlinePlayers = $communityConnection['players_online'] ?? null;
    $playerCapacity = $communityConnection['players_max'] ?? null;
@endphp
@if ($publicAddress || $onlinePlayers !== null)
    <section class="card" id="community-connection" aria-labelledby="community-connection-title">
        <div class="card-body p-4">
            <h2 class="h5" id="community-connection-title">
                {{ $publicAddress ? __('site.home.join_in_game') : __('site.home.players_online') }}</h2>
            @if ($onlinePlayers !== null)
                <p class="text-body-secondary">
                    <strong class="text-body">
                        {{ number_format($onlinePlayers) }}
                        @if ($playerCapacity !== null)
                            / {{ number_format($playerCapacity) }}
                        @endif
                    </strong>
                    {{ __('site.home.players_online') }}
                </p>
            @endif
            @if ($publicAddress)
                <div class="input-group">
                    <input class="form-control" readonly aria-label="{{ __('site.home.public_connection_address') }}"
                        value="{{ $publicAddress }}">
                    <button class="btn btn-outline-primary" type="button" data-copy="{{ $publicAddress }}"
                        data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                </div>
            @endif
        </div>
    </section>
@endif
