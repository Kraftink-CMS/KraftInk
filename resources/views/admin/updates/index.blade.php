@use('App\Kraft\SiteDates')
@use('App\Kraft\Updates\ReleaseVersion')
@extends('admin.layouts.layout')
@section('title', __('system.updates.title'))
@section('description', __('system.updates.description'))
@section('content')
    @php
        $versionChannel ??= ReleaseVersion::channel($version ?? config('manifest.version')) ?? 'development';
        $updateChannel ??= $settings['updates.channel'] ?? 'stable';
        $releaseChannel = $release['channel'] ?? ReleaseVersion::channel($release['version'] ?? null);
        $channelColors = ['stable' => 'success', 'beta' => 'warning', 'alpha' => 'danger'];
        $releaseState = $release['state'] ?? 'unchecked';
        $releaseColor = ['available' => 'primary', 'current' => 'success'][$releaseState] ?? 'info';
        $releaseIcon = ['available' => 'download', 'current' => 'check-circle'][$releaseState] ?? 'cloud-arrow-down';
        $noCompatibleUpdate = $releaseState === 'incompatible';
        $blockedRelease = $noCompatibleUpdate ? $release : $release['blocked'] ?? null;
        $requirementNames = ['cms' => 'KraftInk', 'php' => 'PHP'];
        $showArchive = old('_update_source') === 'archive' && $errors->any();
        $checkedAt = $release['checked_at'] ?? null;
    @endphp
    <div class="row g-3">
        <div class="col-md-4">
            <div class="info-box h-100 mb-0">
                <span class="info-box-icon text-bg-primary">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('system.updates.installed_version') }}</span>
                    <span class="info-box-number text-break">{{ $version ?? config('manifest.version') }}</span>
                    <div>
                        <span
                            class="badge text-bg-{{ $channelColors[$versionChannel] ?? 'secondary' }}">{{ __('system.updates.channels.' . $versionChannel) }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box h-100 mb-0">
                <span class="info-box-icon text-bg-{{ $channelColors[$updateChannel] ?? 'secondary' }}">
                    <i class="bi bi-broadcast" aria-hidden="true"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('system.updates.selected_channel') }}</span>
                    <span class="info-box-number">{{ __('system.updates.channels.' . $updateChannel) }}</span>
                    <span
                        class="small text-body-secondary">{{ __('system.updates.channel_includes.' . $updateChannel) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box h-100 mb-0">
                <span class="info-box-icon text-bg-secondary">
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                </span>
                <div class="info-box-content">
                    <span class="info-box-text">{{ __('system.updates.last_checked') }}</span>
                    @if ($checkedAt)
                        <time class="info-box-number"
                            datetime="{{ app(SiteDates::class)->iso($checkedAt) }}">{{ app(SiteDates::class)->format($checkedAt) }}</time>
                    @else
                        <span class="info-box-number">{{ __('system.updates.never_checked') }}</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="row g-4 align-items-start">
        <div class="col-lg-7 col-xl-8 d-flex flex-column gap-4">
            <x-admin::updates.screen :show-empty="false" history-id="update-progress" :heading="__('system.updates.progress')">
                <section class="card mb-0">
                    <div class="card-body">
                        <div class="row align-items-center g-3">
                            <div class="col-12 col-sm">
                                <h2 class="h5 mb-0">{{ __('system.updates.official_release') }}</h2>
                                @if ($releaseState !== 'unchecked' && !$noCompatibleUpdate)
                                    <p class="text-body-secondary mb-0 mt-2">
                                        <i class="bi bi-{{ $releaseIcon }} text-{{ $releaseColor }} me-1"
                                            aria-hidden="true"></i>{{ __('system.updates.remote_state.' . $releaseState, ['version' => $release['version'] ?? '']) }}
                                        @if ($releaseChannel !== null)
                                            <span
                                                class="badge text-bg-{{ $channelColors[$releaseChannel] ?? 'secondary' }} ms-1">{{ __('system.updates.channels.' . $releaseChannel) }}</span>
                                        @endif
                                    </p>
                                @endif
                                @if (!$noCompatibleUpdate && isset($release['release_url']))
                                    <a class="d-inline-block mt-2 small" href="{{ $release['release_url'] }}"
                                        target="_blank" rel="noopener noreferrer">
                                        {{ __('system.updates.release_notes', ['version' => $release['version']]) }}<i
                                            class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                    </a>
                                @endif
                            </div>
                            <form data-ajax="true" data-redirect-url="response" class="col-12 col-sm-auto" method="post"
                                action="{{ route('admin.updates.check') }}" data-update-check>
                                @csrf
                                <button class="btn btn-outline-primary btn-sm w-100" type="submit">
                                    <i class="bi bi-arrow-clockwise me-1"
                                        aria-hidden="true"></i>{{ __('system.updates.check') }}
                                </button>
                            </form>
                        </div>
                        @if ($releaseState === 'available')
                            <form class="mt-3" method="post" action="{{ route('admin.updates.prepare') }}"
                                data-update-prepare>
                                @csrf
                                <input type="hidden" name="version" value="{{ $release['version'] }}">
                                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                                <button class="btn btn-primary" type="submit">
                                    <i class="bi bi-download me-1"
                                        aria-hidden="true"></i>{{ __('system.updates.update_now', ['version' => $release['version']]) }}
                                </button>
                                <p class="alert alert-danger mt-3 mb-0" data-update-error role="alert" hidden></p>
                                <p class="small text-body-secondary mt-2 mb-0">
                                    <i class="bi bi-shield-check me-1"
                                        aria-hidden="true"></i>{{ __('system.updates.install_help') }}
                                </p>
                            </form>
                        @endif
                    </div>
                    @if ($blockedRelease)
                        <section id="update-release-prerequisites" @class([
                            'card-body pt-0' => $noCompatibleUpdate,
                            'card-footer py-3' => !$noCompatibleUpdate,
                        ])
                            aria-labelledby="update-release-prerequisites-title">
                            <div @class([
                                'd-flex align-items-start gap-2',
                                'alert alert-warning py-2 mb-0' => $noCompatibleUpdate,
                            ])>
                                <i @class([
                                    'bi',
                                    'bi-exclamation-triangle' => $noCompatibleUpdate,
                                    'bi-info-circle text-body-secondary' => !$noCompatibleUpdate,
                                ]) aria-hidden="true"></i>
                                <div>
                                    <h3 class="fs-6 fw-semibold mb-1" id="update-release-prerequisites-title">
                                        {{ __($noCompatibleUpdate ? 'system.updates.remote_state.incompatible' : 'system.updates.newer_release_requirements', ['version' => $blockedRelease['version']]) }}
                                    </h3>
                                    @foreach ($blockedRelease['unmet'] ?? [] as $name => $requirement)
                                        <p class="small mb-0">
                                            {{ __('system.updates.unmet_requirement', ['name' => $requirementNames[$name], 'required' => $requirement['required'], 'current' => $requirement['current']]) }}
                                        </p>
                                    @endforeach
                                    @if ($noCompatibleUpdate)
                                        <p class="small mt-1 mb-0">{{ __('system.updates.no_compatible_release') }}</p>
                                    @endif
                                    @if (isset($blockedRelease['release_url']))
                                        <a @class([
                                            'd-inline-block small mt-1',
                                            'alert-link' => $noCompatibleUpdate,
                                        ]) href="{{ $blockedRelease['release_url'] }}"
                                            target="_blank" rel="noopener noreferrer">
                                            {{ __('system.updates.release_notes', ['version' => $blockedRelease['version']]) }}<i
                                                class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </section>
                    @endif
                </section>
                <section class="card mb-0">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <h2 class="card-title">
                            <i class="bi bi-file-earmark-zip me-2"
                                aria-hidden="true"></i>{{ __('system.updates.advanced_installation') }}
                        </h2>
                        <div class="card-tools ms-auto">
                            <button class="btn btn-tool" type="button" data-bs-toggle="collapse"
                                data-bs-target="#update-archive-panel"
                                aria-expanded="{{ $showArchive ? 'true' : 'false' }}" aria-controls="update-archive-panel"
                                aria-label="{{ __('system.updates.archive_title') }}">
                                <i class="bi bi-chevron-expand" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div @class(['collapse', 'show' => $showArchive]) id="update-archive-panel">
                        <form method="post" action="{{ route('admin.updates.stage') }}" enctype="multipart/form-data"
                            data-update-prepare>
                            @csrf
                            <input type="hidden" name="_update_source" value="archive">
                            <div class="card-body">
                                <p class="text-body-secondary">{{ __('system.updates.archive_help') }}</p>
                                <div class="mb-3">
                                    <label class="form-label" for="release">{{ __('system.updates.archive') }}</label>
                                    <input class="form-control" id="release" type="file" name="archive"
                                        accept=".zip,application/zip" required>
                                </div>
                                <input type="hidden" data-sensitive-confirmation
                                    data-sensitive-password-field="password">
                                <p class="alert alert-danger mt-3" data-update-error role="alert" hidden></p>
                                <p class="small text-body-secondary mt-3 mb-0">{{ __('system.updates.install_help') }}</p>
                            </div>
                            <div class="card-footer">
                                <button class="btn btn-outline-primary" type="submit">
                                    <i class="bi bi-upload me-1"
                                        aria-hidden="true"></i>{{ __('system.updates.install_archive') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </section>
            </x-admin::updates.screen>
            <div class="vstack gap-4" id="update-history" data-ajax-pagination data-table tabindex="-1"
                data-page-name="history_page">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('system.updates.mixed_history') }}</h2>
                    </div>
                    <x-tables.controls :paginator="$runs" container-class="card-body" />
                    <div class="card-body p-0 table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="text-nowrap">
                                <tr>
                                    <th scope="col" data-table-sort="subject">
                                        {{ __('system.updates.history_subject') }}</th>
                                    <th scope="col" data-table-sort="operation">{{ __('common.labels.type') }}</th>
                                    <th scope="col" data-table-sort="state">{{ __('common.labels.status') }}</th>
                                    <th scope="col" data-table-sort="created_at">{{ __('common.labels.date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($runs as $run)
                                    <tr data-update-history-entry="{{ $run['id'] }}">
                                        <td>
                                            <strong>{{ $run['subject'] ?? ($run['version'] ?? $run['id']) }}</strong>
                                            <small class="d-block text-body-secondary">{{ $run['id'] }}</small>
                                        </td>
                                        <td>{{ __('system.updates.operations.' . $run['operation']) }}</td>
                                        <td><span
                                                class="badge text-bg-{{ $run['status_color'] }}">{{ $run['status_label'] }}</span>
                                        </td>
                                        <td>
                                            @if (!empty($run['created_at']))
                                                <time
                                                    datetime="{{ app(SiteDates::class)->iso($run['created_at']) }}">{{ app(SiteDates::class)->format($run['created_at']) }}</time>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <x-tables.empty :colspan="4" :message="__('system.updates.history_empty')" />
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer">
                        {{ $runs->links() }}
                    </div>
                </section>
            </div>
        </div>
        <aside class="col-lg-5 col-xl-4">
            <section class="card card-outline card-secondary mb-0">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="bi bi-sliders me-2" aria-hidden="true"></i>{{ __('system.updates.channel_title') }}
                    </h2>
                </div>
                <form data-ajax="true" method="post" action="{{ route('admin.updates.channel') }}">
                    @csrf
                    <div class="card-body">
                        <p class="text-body-secondary">{{ __('system.updates.channel_intro') }}</p>
                        <fieldset class="border-0 p-0 mb-0" aria-describedby="updates_channel_help">
                            <legend class="visually-hidden">{{ __('system.updates.channel_label') }}</legend>
                            @foreach ($channelColors as $channel => $color)
                                <div @class(['form-check', 'mb-3 pb-3 border-bottom' => !$loop->last])>
                                    <input class="form-check-input" id="updates_channel_{{ $channel }}"
                                        type="radio" name="channel" value="{{ $channel }}"
                                        @checked(old('channel', $updateChannel) === $channel)>
                                    <label class="form-check-label d-block" for="updates_channel_{{ $channel }}">
                                        <span
                                            class="badge text-bg-{{ $color }}">{{ __('system.updates.channels.' . $channel) }}</span>
                                        <span
                                            class="d-block mt-2">{{ __('system.updates.channel_descriptions.' . $channel) }}</span>
                                        <span
                                            class="d-block small text-body-secondary mt-1">{{ __('system.updates.channel_includes.' . $channel) }}</span>
                                    </label>
                                </div>
                            @endforeach
                        </fieldset>
                        <p class="form-text mb-0 mt-3" id="updates_channel_help">{{ __('system.updates.channel_help') }}
                        </p>
                    </div>
                    <div class="card-footer">
                        <button class="btn btn-secondary" type="submit">{{ __('system.updates.save_channel') }}</button>
                    </div>
                </form>
            </section>
        </aside>
    </div>
@endsection
