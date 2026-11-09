@if ($canUpdateCms || $canUpdateExtensions)
    <div class="vstack gap-3" data-update-availability data-endpoint="{{ $availabilityEndpoint }}"
        data-cache-key="{{ $availabilityCacheKey }}"
        data-can-cms="{{ $canUpdateCms ? '1' : '0' }}" data-can-extensions="{{ $canUpdateExtensions ? '1' : '0' }}"
        data-skip="{{ $availabilitySkip }}" hidden>
        @if ($canUpdateCms)
            <div class="alert alert-warning mb-0" role="status" data-update-availability-notice="cms" hidden>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-arrow-up-circle-fill" aria-hidden="true"></i>
                    <strong>{{ __('system.updates.availability.cms_available') }}</strong>
                    <a class="alert-link" href="{{ route('admin.updates.index') }}" data-update-availability-cms
                        data-label="{{ __('system.updates.availability.cms', ['version' => ':version']) }}" hidden></a>
                </div>
            </div>
        @endif
        @if ($canUpdateExtensions)
            <div class="alert alert-info mb-0" role="status" data-update-availability-notice="extensions" hidden>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>{{ __('system.updates.availability.extensions_available') }}</span>
                    <a class="alert-link" href="{{ route('admin.extensions.plugins.index') }}" data-update-availability-plugins
                        data-label="{{ __('system.updates.availability.plugins', ['count' => ':count']) }}" hidden></a>
                    <a class="alert-link" href="{{ route('admin.extensions.themes.index') }}" data-update-availability-themes
                        data-label="{{ __('system.updates.availability.themes', ['count' => ':count']) }}" hidden></a>
                    <a class="alert-link" href="{{ route('admin.games.index') }}" data-update-availability-games
                        data-label="{{ __('system.updates.availability.games', ['count' => ':count']) }}" hidden></a>
                </div>
            </div>
        @endif
    </div>
@endif
