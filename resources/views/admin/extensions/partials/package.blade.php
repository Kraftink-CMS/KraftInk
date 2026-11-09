@use('App\Kraft\System\ListingPagination')
@use('Illuminate\Pagination\LengthAwarePaginator')
<div class="modal fade" id="extension-package" tabindex="-1" aria-labelledby="extension-package-title" aria-hidden="true"
    @if (request()->boolean('catalogue') || ($errors->any() && old('_modal') === 'extension-package')) data-kraft-modal-open @endif>
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="extension-package-title">{{ __('admin.extensions.install') }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.cancel') }}"></button>
            </div>
            <div class="modal-body">
                @include('partials.feedback.modal-errors', ['id' => 'extension-package'])
                <div id="extension-marketplace-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
                    <section class="mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h3 class="h5 mb-0">{{ __('system.extensions.catalogue_title') }}</h3>
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.extensions.market.index') }}">{{ __('market.configure') }}</a>
                        </div>
                        @if (($catalogue ?? null) === null)
                            <p class="text-body-secondary">{{ __('system.extensions.catalogue_help') }}</p>
                            <a class="btn btn-outline-primary" href="{{ request()->fullUrlWithQuery(['catalogue' => 1]) }}">{{ __('system.extensions.browse_catalogue') }}</a>
                        @else
                            @php
                                $cataloguePage = $catalogue['items'] instanceof LengthAwarePaginator
                                    ? $catalogue['items']
                                    : app(ListingPagination::class)->paginate($catalogue['items'], request(), 'catalogue_page')->appends(['catalogue' => 1]);
                            @endphp
                            <form data-ajax="true" data-redirect-url="response" class="mb-3" method="post" action="{{ route('admin.extensions.install') }}">
                                @csrf
                                <input type="hidden" name="action" value="refresh_market">
                                <button class="btn btn-outline-secondary btn-sm" type="submit">{{ __('system.extensions.refresh_catalogue') }}</button>
                            </form>
                            @if ($catalogue['state'] === 'unconfigured')
                                <p class="text-body-secondary">{{ __('system.extensions.catalogue_unconfigured') }}</p>
                            @elseif (in_array($catalogue['state'], ['stale', 'unavailable']))
                                <p class="alert alert-warning">{{ __('system.extensions.market_install_unavailable') }}</p>
                            @endif
                            @foreach ($catalogue['sources'] ?? [] as $source)
                                @if (($source['error']['message'] ?? null) || ($source['access']['message'] ?? null))
                                    <div class="alert alert-warning">
                                        <strong>{{ $source['name'] ?? $source['url'] }}</strong>
                                        <p class="mb-0">{{ $source['error']['message'] ?? $source['access']['message'] }}</p>
                                    </div>
                                @endif
                            @endforeach
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                        <tr>
                                            <th>{{ __('common.labels.name') }}</th>
                                            <th>{{ __('system.extensions.package_version') }}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($cataloguePage as $item)
                                            <tr>
                                                <td>
                                                    <strong>{{ $item['name'] }}</strong>
                                                    <small class="d-block text-body-secondary">{{ $item['author'] }}</small>
                                                    <p class="small mb-0">{{ $item['description'] }}</p>
                                                    <small class="d-block text-body-secondary">{{ $item['price'] }}</small>
                                                    @if (($item['games'] ?? null) !== null)
                                                        <small class="d-block text-body-secondary">
                                                            {{ __('system.extensions.supported_games') }}: {{ $item['games'] }}
                                                        </small>
                                                    @endif
                                                    @if ($item['reason'] ?? null)
                                                        <p class="text-warning-emphasis small mb-0">{{ $item['reason'] }}</p>
                                                    @endif
                                                </td>
                                                <td>{{ $item['version'] ?? '—' }}</td>
                                                <td class="text-end">
                                                    @if ($item['purchase_incompatibility'] ?? null)
                                                        <span class="text-warning-emphasis small">{{ $item['purchase_incompatibility'] }}</span>
                                                    @elseif ($item['manual'] && $item['url'])
                                                        <a class="btn btn-outline-secondary btn-sm" href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ __('system.extensions.get_package') }}</a>
                                                    @elseif ($item['state'] !== 'fresh')
                                                        <span class="text-body-secondary small">{{ __('system.extensions.market_install_unavailable') }}</span>
                                                    @elseif ($item['denied'] ?? false)
                                                        @if ($item['url'])
                                                            <a class="btn btn-outline-secondary btn-sm" href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ __('system.extensions.get_package') }}</a>
                                                        @else
                                                            <span class="text-body-secondary small">{{ __('market.errors.resource_denied') }}</span>
                                                        @endif
                                                    @elseif ($item['available'])
                                                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.extensions.install') }}">
                                                            @csrf
                                                            <input type="hidden" name="_modal" value="extension-package">
                                                            <input type="hidden" name="catalogue_id" value="{{ $item['id'] }}">
                                                            <input type="hidden" name="catalogue_source" value="{{ $item['source'] }}">
                                                            <input type="hidden" name="catalogue_version" value="{{ $item['version'] }}">
                                                            <button class="btn btn-primary btn-sm" type="submit">{{ __($item['update'] ? 'common.actions.update' : 'common.actions.install') }}</button>
                                                        </form>
                                                    @else
                                                        <span class="text-body-secondary small">{{ __($item['version'] ? 'system.extensions.up_to_date' : 'system.extensions.market_no_compatible_version') }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <x-tables.empty :colspan="3" :message="__('system.extensions.catalogue_empty')" />
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            {{ $cataloguePage->links() }}
                        @endif
                    </section>
                </div>
                <section>
                    <h3 class="h5">{{ __('system.extensions.archive_title') }}</h3>
                    <p class="text-body-secondary">{{ __('system.extensions.archive_help') }}</p>
                    <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.extensions.install') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="_modal" value="extension-package">
                        <label class="form-label" for="extension-archive">{{ __('admin.extensions.archive') }}</label>
                        <input class="form-control" id="extension-archive" type="file" name="archive" accept=".zip,application/zip" required>
                        <p class="form-text">{{ __('system.extensions.prepare_help') }}</p>
                        <button class="btn btn-primary" type="submit">{{ __('system.extensions.prepare_package') }}</button>
                    </form>
                </section>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
