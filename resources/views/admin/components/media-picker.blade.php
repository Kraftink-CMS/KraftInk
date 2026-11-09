@use('App\Models\Media\MediaAsset')
@props([
    'name',
    'value' => null,
    'context' => 'library',
    'profile' => 'image',
    'label' => null,
    'help' => null,
    'emptyLabel' => null,
    'uploadName' => null,
    'publicUploadName' => null,
    'accept' => null,
    'valueMode' => 'id',
    'urlMaxLength' => 2048,
    'errorKey' => null,
    'id' => null,
    'asset' => null,
    'indexUrl' => null,
    'storeUrl' => null,
    'parameters' => [],
    'identifier' => 'integer',
    'privacy' => 'public',
    'privateStoreUrl' => null,
    'privateIndexUrl' => null,
    'errorKeys' => [],
    'draft' => false,
])
@php
    /** @var MediaAsset|int|string|null $value */
    $accept ??= match ($profile) {
        'favicon' => 'image/png',
        'logo', 'landscape' => 'image/jpeg,image/png,image/webp',
        default => 'image/jpeg,image/png,image/webp,image/gif,image/avif',
    };
    $fieldKey = $errorKey ?? trim(preg_replace('/\[([^\]]*)\]/', '.$1', $name), '.');
    $pickerId = $id ?? 'media-picker-' . trim(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name), '-');
    $emptyText = $emptyLabel ?? __('media.none');
    $labelText = $label ?? __('media.image');
    $isUrl = $valueMode === 'url';
    $defaultValue = $value instanceof MediaAsset ? $value->id : $value;
    $oldValue = old($fieldKey, $defaultValue);
    $selectedValue = is_scalar($oldValue) ? (string) $oldValue : '';
    $selectedAsset =
        $identifier !== 'uuid' && !$isUrl && filter_var($selectedValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            ? ($value instanceof MediaAsset && (string) $value->id === $selectedValue
                ? $value
                : MediaAsset::find((int) $selectedValue))
            : null;
    $customAsset = is_array($asset) && (string) ($asset['id'] ?? '') === $selectedValue ? $asset : null;
    $previewUrl = $customAsset['url'] ?? ($selectedAsset ? route('site.media.show', $selectedAsset) : ($isUrl ? $selectedValue : ''));
    $previewParts = $previewUrl !== '' ? parse_url($previewUrl) : false;
    if (
        !$previewParts ||
        isset($previewParts['user']) ||
        isset($previewParts['pass']) ||
        (isset($previewParts['scheme']) && !in_array(strtolower($previewParts['scheme']), ['https', 'http'], true)) ||
        str_starts_with($previewUrl, '//')
    ) {
        $previewUrl = '';
    }
    $assetData = $customAsset ?? ($selectedAsset
        ? [
            'id' => $selectedAsset->id,
            'name' => $selectedAsset->original_name,
            'url' => $previewUrl,
            'width' => $selectedAsset->width,
            'height' => $selectedAsset->height,
            'mime' => $selectedAsset->mime,
            'size' => $selectedAsset->size,
        ]
        : null);
    if ($assetData) {
        $assetData['url'] = $previewUrl;
    }
    $fileName =
        $assetData['name'] ??
        ($previewUrl !== '' ? basename(parse_url($previewUrl, PHP_URL_PATH) ?: $previewUrl) : $emptyText);
    $uploadKey = $uploadName ? trim(preg_replace('/\[([^\]]*)\]/', '.$1', $uploadName), '.') : null;
    $fieldErrors = array_merge($errors->get($fieldKey), $uploadKey ? $errors->get($uploadKey) : []);
    foreach ($errorKeys as $extraKey) {
        $fieldErrors = array_merge($fieldErrors, $errors->get($extraKey));
    }
    $describedBy = implode(
        ' ',
        array_filter([$help ? $pickerId . '-help' : null, $fieldErrors ? $pickerId . '-errors' : null]),
    );
@endphp
<div {{ $attributes->class(['media-picker']) }} data-media-picker data-media-context="{{ $context }}"
    data-media-mode="{{ $isUrl ? 'url' : 'id' }}" data-media-accept="{{ $accept }}"
    data-media-identifier="{{ $identifier }}" data-media-privacy="{{ $privacy }}"
    data-media-draft="{{ $draft ? 'true' : 'false' }}"
    data-media-parameters='@json($parameters)'
    @if ($indexUrl) data-media-index="{{ $indexUrl }}" @endif
    @if ($storeUrl) data-media-store="{{ $storeUrl }}" @endif
    @if ($privateStoreUrl) data-media-private-store="{{ $privateStoreUrl }}" @endif
    @if ($privateIndexUrl) data-media-private-index="{{ $privateIndexUrl }}" @endif
    @if ($uploadName) data-media-upload-name="{{ $uploadName }}" @endif
    @if ($publicUploadName) data-media-public-upload-name="{{ $publicUploadName }}" @endif
    data-media-empty="{{ $emptyText }}" data-media-label="{{ $labelText }}"
    data-media-asset='@json($assetData)' data-media-initial-value="{{ $selectedValue }}">
    <label class="form-label" for="{{ $pickerId }}-choose">{{ $labelText }}</label>
    <input type="hidden" id="{{ $pickerId }}" name="{{ $name }}" value="{{ $selectedValue }}"
        data-media-value @if ($help) aria-describedby="{{ $pickerId }}-help" @endif>
    <div class="d-flex align-items-center gap-3 border rounded p-3">
        <div
            class="media-picker-preview bg-body-tertiary border rounded d-flex align-items-center justify-content-center flex-shrink-0">
            <img class="w-100 h-100 object-fit-contain rounded" src="{{ $previewUrl ?: asset('images/avatar.svg') }}"
                alt="" width="80" height="80" data-media-preview
                @if ($previewUrl === '') hidden @endif>
            <i class="bi bi-image text-body-secondary fs-2" aria-hidden="true" data-media-placeholder
                @if ($previewUrl !== '') hidden @endif></i>
        </div>
        <div class="flex-grow-1 overflow-hidden">
            <p class="fw-semibold text-break mb-1" data-media-name>{{ $fileName }}</p>
            <p class="small text-warning-emphasis mb-1" data-media-local-status hidden>{{ __('media.pending_save') }}</p>
            @if ($privacy === 'mixed')
                <p class="small mb-1" data-media-source @if (!$assetData) hidden @endif>
                    {{ ($assetData['visibility'] ?? 'public') === 'private' ? __('media.source_private') : __('media.source_public') }}
                </p>
            @endif
            <p class="small text-body-secondary mb-2" data-media-dimensions
                @if (!$assetData) hidden @endif>
                @if ($assetData)
                    {{ $assetData['width'] }} × {{ $assetData['height'] }} px
                @endif
            </p>
            <div class="d-flex flex-wrap gap-2">
                <button @class([
                    'btn btn-outline-secondary btn-sm',
                    'is-invalid' => $fieldErrors !== [],
                ]) type="button" id="{{ $pickerId }}-choose" data-media-open
                    aria-controls="media-selection-modal" aria-haspopup="dialog"
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    @if ($fieldErrors) aria-invalid="true" @endif>
                    <i class="bi {{ $privacy === 'private' ? 'bi-shield-lock' : 'bi-images' }} me-1" aria-hidden="true"></i>{{ __('media.choose') }}
                </button>
                <button class="btn btn-outline-secondary btn-sm" type="button" data-media-clear
                    @if ($selectedValue === '') hidden @endif>{{ __('media.clear') }}</button>
            </div>
        </div>
    </div>
    @if ($help)
        <div class="form-text" id="{{ $pickerId }}-help" data-media-help>{{ $help }}</div>
    @endif
    @if ($isUrl)
        <details class="mt-2" data-media-external>
            <summary class="small">{{ __('media.external_url') }}</summary>
            <label class="form-label small mt-2" for="{{ $pickerId }}-url">{{ __('media.image_url') }}</label>
            <input class="form-control" id="{{ $pickerId }}-url" type="text" inputmode="url"
                value="{{ $selectedValue }}" data-media-url maxlength="{{ $urlMaxLength }}" autocomplete="off">
        </details>
    @endif
    @if ($uploadName)
        <div class="mt-2" data-media-direct-upload>
            <label class="form-label" for="{{ $pickerId }}-upload">{{ __('media.upload') }}</label>
            <input class="form-control" id="{{ $pickerId }}-upload" name="{{ $uploadName }}" type="file"
                accept="{{ $accept }}" data-media-file>
        </div>
    @endif
    @if ($fieldErrors)
        <div class="invalid-feedback d-block" id="{{ $pickerId }}-errors">
            @foreach ($fieldErrors as $message)
                <p class="mb-0">{{ $message }}</p>
            @endforeach
        </div>
    @endif
</div>
@once
    @push('scripts')
        <div class="modal fade" id="media-selection-modal" tabindex="-1" aria-labelledby="media-selection-title" aria-hidden="true"
            data-media-modal data-media-index="{{ route('admin.media.selection.index') }}"
            data-media-store="{{ route('admin.media.selection.store') }}" data-media-labels='@json(__('media'))'>
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="media-selection-title">{{ __('media.choose') }}</h2>
                        <button class="btn-close" type="button" data-bs-dismiss="modal"
                            aria-label="{{ __('common.actions.cancel') }}"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-body-secondary" data-media-modal-help>{{ __('media.selection_help') }}</p>
                        <div class="alert alert-danger" role="alert" data-media-error hidden></div>
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item" role="presentation" data-media-private-tab-item hidden>
                                <button class="nav-link" id="media-private-tab" type="button" data-bs-toggle="tab"
                                    data-bs-target="#media-private-pane" role="tab" aria-controls="media-private-pane"
                                    aria-selected="false">{{ __('media.private_library') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="media-library-tab" type="button" data-bs-toggle="tab"
                                    data-bs-target="#media-library-pane" role="tab" aria-controls="media-library-pane"
                                    aria-selected="true"><span data-media-library-label>{{ __('media.library') }}</span></button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="media-upload-tab" type="button" data-bs-toggle="tab"
                                    data-bs-target="#media-upload-pane" role="tab" aria-controls="media-upload-pane"
                                    aria-selected="false">{{ __('media.upload') }}</button>
                            </li>
                        </ul>
                        <div class="tab-content">
                            <div class="tab-pane fade" id="media-private-pane" role="tabpanel"
                                aria-labelledby="media-private-tab" tabindex="0"></div>
                            <div class="tab-pane fade show active" id="media-library-pane" role="tabpanel"
                                aria-labelledby="media-library-tab" tabindex="0">
                                <div data-media-collection>
                                <form class="d-flex gap-2 mb-3" data-media-search>
                                    <label class="visually-hidden" for="media-selection-search">{{ __('media.search') }}</label>
                                    <input class="form-control" id="media-selection-search" type="search" name="q"
                                        maxlength="100" placeholder="{{ __('media.search') }}" autocomplete="off"
                                        data-media-query>
                                    <button class="btn btn-outline-secondary"
                                        type="submit">{{ __('common.actions.search') }}</button>
                                </form>
                                <p class="small text-body-secondary" role="status" aria-live="polite" data-media-status></p>
                                <section class="mb-3" data-media-draft-section hidden>
                                    <h3 class="h6">{{ __('media.pending_save') }}</h3>
                                    <div class="row g-3" data-media-draft-results></div>
                                </section>
                                <div class="row g-3" data-media-results aria-busy="false"></div>
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
                                    <span class="small text-body-secondary" data-media-pagination-label></span>
                                    <nav aria-label="{{ __('media.pagination') }}" data-media-pagination>
                                        <div class="btn-group">
                                            <button class="btn btn-outline-secondary btn-sm" type="button" data-media-previous
                                                disabled>{{ __('media.previous') }}</button>
                                            <button class="btn btn-outline-secondary btn-sm" type="button" data-media-next
                                                disabled>{{ __('media.next') }}</button>
                                        </div>
                                    </nav>
                                </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="media-upload-pane" role="tabpanel" aria-labelledby="media-upload-tab"
                                tabindex="0">
                                <div class="alert alert-info" data-media-private-notice hidden>
                                    <div class="d-flex align-items-start gap-2">
                                        <i class="bi bi-shield-lock" aria-hidden="true"></i>
                                        <span>{{ __('media.private_notice') }}</span>
                                    </div>
                                </div>
                                <form data-media-upload method="post" action="{{ route('admin.media.selection.store') }}"
                                    enctype="multipart/form-data">
                                    @csrf
                                    <div class="form-check mb-3" data-media-public-upload-group hidden>
                                        <input class="form-check-input" id="media-upload-public" type="checkbox"
                                            data-media-upload-public>
                                        <label class="form-check-label" for="media-upload-public">{{ __('media.add_to_public_library') }}</label>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" for="media-selection-file">{{ __('media.file') }}</label>
                                        <input class="form-control" id="media-selection-file" type="file" name="file"
                                            required data-media-upload-file>
                                        <div class="form-text" data-media-upload-help></div>
                                    </div>
                                    <div class="border rounded p-3 mb-3" data-media-upload-preview hidden>
                                        <div class="d-flex align-items-center gap-3">
                                            <img class="object-fit-contain border rounded bg-body-tertiary" alt=""
                                                width="96" height="96" data-media-upload-image>
                                            <div class="text-break">
                                                <p class="fw-semibold mb-1" data-media-upload-name></p>
                                                <p class="small text-body-secondary mb-0" data-media-upload-source></p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="mb-3" data-media-alt-group>
                                        <label class="form-label" for="media-selection-alt">{{ __('media.alt') }}</label>
                                        <input class="form-control" id="media-selection-alt" type="text" name="alt"
                                            maxlength="255">
                                        <div class="form-text">{{ __('media.alt_help') }}</div>
                                    </div>
                                    <p class="small text-body-secondary mb-0" role="status" aria-live="polite"
                                        data-media-upload-status></p>
                                    <div class="mt-2" data-media-upload-progress hidden>
                                        <div class="progress" role="progressbar" aria-label="{{ __('media.uploading') }}">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <span class="small text-body-secondary me-auto text-break" data-media-pending></span>
                        <button class="btn btn-outline-secondary" type="button"
                            data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button class="btn btn-primary" type="button" data-media-select
                            disabled>{{ __('media.select') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endpush
@endonce
