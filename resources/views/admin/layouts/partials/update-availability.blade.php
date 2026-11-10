@if ($canUpdateCms || $canUpdateExtensions || ($canUpdateKraftLink ?? false))
    <div class="vstack gap-3" data-update-availability data-endpoint="{{ $availabilityEndpoint }}"
        data-cache-key="{{ $availabilityCacheKey }}" data-can-cms="{{ $canUpdateCms ? '1' : '0' }}"
        data-can-extensions="{{ $canUpdateExtensions ? '1' : '0' }}"
        data-can-kraftlink="{{ $canUpdateKraftLink ?? false ? '1' : '0' }}" data-skip="{{ $availabilitySkip }}" hidden>
        @if ($canUpdateCms)
            <div class="bg-primary-subtle border border-primary-subtle rounded px-3 py-2" role="status"
                data-update-availability-notice="cms" hidden>
                <div class="d-flex flex-wrap align-items-center gap-2 gap-sm-3">
                    <i class="bi bi-arrow-up-circle text-primary-emphasis fs-5" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <strong>{{ __('system.updates.availability.cms_available') }}</strong>
                            <span class="badge rounded-pill text-bg-primary" data-update-availability-cms
                                data-label="{{ __('system.updates.availability.cms', ['version' => ':version']) }}"
                                hidden></span>
                        </div>
                        <p class="small text-body-secondary mb-0" data-update-availability-cms-blocked
                            data-label="{{ __('system.updates.newer_release_requirements', ['version' => ':version']) }}"
                            hidden></p>
                    </div>
                    <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ route('admin.updates.index') }}">
                        {{ __('system.updates.availability.cms_action') }}<i class="bi bi-arrow-right ms-1"
                            aria-hidden="true"></i>
                    </a>
                </div>
            </div>
        @endif
        @if ($canUpdateKraftLink ?? false)
            <div class="bg-body-tertiary border rounded px-3 py-2" role="status"
                data-update-availability-notice="kraftlink" hidden>
                <div class="d-flex flex-wrap align-items-center gap-2 gap-sm-3">
                    <i class="bi bi-plug text-body-secondary fs-5" aria-hidden="true"></i>
                    <div class="d-flex flex-wrap align-items-center flex-grow-1 gap-2">
                        <strong>{{ __('system.updates.kraftlink.available') }}</strong>
                        <span class="badge rounded-pill text-bg-secondary" data-update-availability-kraftlink
                            data-label=":version" hidden></span>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <a class="btn btn-sm btn-outline-secondary"
                            href="https://github.com/Kraftink-CMS/KraftLink/releases/latest" target="_blank"
                            rel="noopener noreferrer" data-update-availability-kraftlink-link
                            data-release-base="https://github.com/Kraftink-CMS/KraftLink/releases/tag/v">
                            {{ __('system.updates.kraftlink.action') }}<i class="bi bi-box-arrow-up-right ms-1"
                                aria-hidden="true"></i>
                        </a>
                        <button class="btn-close" type="button" data-update-availability-kraftlink-dismiss
                            data-endpoint="{{ route('admin.updates.kraftlink.dismiss') }}"
                            data-error="{{ __('system.updates.kraftlink.dismiss_failed') }}"
                            aria-label="{{ __('system.updates.kraftlink.dismiss') }}"></button>
                    </div>
                </div>
                <p class="small text-danger-emphasis mt-2 mb-0" role="alert" data-update-availability-kraftlink-error
                    hidden></p>
            </div>
        @endif
        @if ($canUpdateExtensions)
            <div class="alert alert-info mb-0" role="status" data-update-availability-notice="extensions" hidden>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                    <span>{{ __('system.updates.availability.extensions_available') }}</span>
                    <a class="alert-link" href="{{ route('admin.extensions.plugins.index') }}"
                        data-update-availability-plugins
                        data-label="{{ __('system.updates.availability.plugins', ['count' => ':count']) }}" hidden></a>
                    <a class="alert-link" href="{{ route('admin.extensions.themes.index') }}"
                        data-update-availability-themes
                        data-label="{{ __('system.updates.availability.themes', ['count' => ':count']) }}" hidden></a>
                    <a class="alert-link" href="{{ route('admin.games.index') }}" data-update-availability-games
                        data-label="{{ __('system.updates.availability.games', ['count' => ':count']) }}" hidden></a>
                </div>
            </div>
        @endif
    </div>
@endif
