@extends('admin.layouts.layout')
@section('title', __('site.community.media_title'))
@section('description', __('site.community.media_description'))
@section('actions')
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#media-upload">
        {{ __('site.community.upload_image') }}</button>
@endsection
@section('content')
    <div id="admin-media-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <form method="get" action="{{ route('admin.media.index') }}" class="d-flex gap-2">
                    <input class="form-control" name="q" value="{{ request('q') }}" maxlength="100"
                        placeholder="{{ __('site.community.search_images') }}"
                        aria-label="{{ __('site.community.search_images') }}">
                    <button class="btn btn-outline-secondary" type="submit">{{ __('common.actions.search') }}</button>
                </form>
            </div>
        </section>
        @if ($assets->isEmpty())
            <section class="card">
                <div class="card-body p-0 table-responsive">
                    <table class="table mb-0">
                        <tbody>
                            <x-tables.empty :colspan="1" :message="__('site.community.no_images')" :description="__('site.community.upload_first_image')" :action="__('site.community.upload_image')"
                                modal="media-upload" />
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <div class="row g-4">
                @foreach ($assets as $asset)
                    <div class="col-md-6 col-xl-4">
                        <article class="card h-100">
                            <div class="ratio ratio-16x9 bg-body-tertiary">
                                <img class="object-fit-contain p-3" src="{{ route('site.media.show', $asset) }}"
                                    alt="{{ $asset->alt }}" loading="lazy" width="{{ $asset->width }}"
                                    height="{{ $asset->height }}">
                            </div>
                            <div class="card-body">
                                <strong class="d-block text-break">{{ $asset->original_name }}</strong>
                                <p class="text-body-secondary small mb-0">{{ $asset->width }} × {{ $asset->height }} ·
                                    {{ number_format($asset->size / 1024, 1) }} KB</p>
                            </div>
                            <div class="card-footer d-flex justify-content-between align-items-center">
                                <button class="btn btn-outline-secondary btn-sm" type="button"
                                    data-copy="{{ route('site.media.show', $asset) }}"
                                    data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown"
                                        aria-expanded="false">{{ __('common.labels.actions') }}</button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <button class="dropdown-item" type="button" data-bs-toggle="modal"
                                                data-bs-target="#media-edit-{{ $asset->id }}">{{ __('common.actions.edit') }}</button>
                                        </li>
                                        <li><a class="dropdown-item" href="{{ route('site.media.show', $asset) }}"
                                                target="_blank" rel="noopener">{{ __('site.community.image_address') }}</a>
                                        </li>
                                        <li>
                                            <button class="dropdown-item text-danger" type="button" data-bs-toggle="modal"
                                                data-bs-target="#media-delete-{{ $asset->id }}">{{ __('common.actions.delete') }}</button>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </article>
                    </div>
                @endforeach
            </div>
            {{ $assets->links() }}
        @endif
        <x-forms.modal id="media-upload" :redirect-url="route('admin.media.index')" :title="__('site.community.upload_image')" :action="route('admin.media.store')" :submit="__('site.community.upload_image')"
            :multipart="true">
            <div class="mb-3">
                <label class="form-label" for="media_file">{{ __('site.community.choose_image') }}</label>
                <input class="form-control" id="media_file" type="file" name="file"
                    accept="image/jpeg,image/png,image/webp,image/gif,image/avif" required>
                <div class="form-text">
                    {{ __('site.community.allowed_images') }}
                </div>
            </div>
            <div>
                <label class="form-label" for="media_alt">{{ __('site.community.image_alt') }}</label>
                <input class="form-control" id="media_alt" name="alt" maxlength="255"
                    value="{{ old('_modal') === 'media-upload' ? old('alt') : '' }}">
                <div class="form-text">
                    {{ __('site.community.image_alt_help') }}
                </div>
            </div>
            <x-admin::translation-fields id="media-upload-translations" :fields="['alt' => ['label' => __('site.community.image_alt'), 'max' => 255]]" :use-old="old('_modal') === 'media-upload'" />
        </x-forms.modal>
        @foreach ($assets as $asset)
            <x-forms.modal :id="'media-edit-' . $asset->id" :title="$asset->original_name" :action="route('admin.media.update', $asset)">
                <div class="mb-3">
                    <label class="form-label" for="alt_{{ $asset->id }}">{{ __('site.community.image_alt') }}</label>
                    <input class="form-control" id="alt_{{ $asset->id }}" name="alt"
                        value="{{ old('_modal') === 'media-edit-' . $asset->id ? old('alt', $asset->alt) : $asset->alt }}"
                        maxlength="255">
                    <div class="form-text">
                        {{ __('site.community.image_alt_help') }}
                    </div>
                </div>
                <div>
                    <x-admin::translation-fields :id="'media-translations-' . $asset->id" :fields="['alt' => ['label' => __('site.community.image_alt'), 'max' => 255]]" :values="$asset->translations ?? []" :use-old="old('_modal') === 'media-edit-' . $asset->id" />
                    <label class="form-label" for="url_{{ $asset->id }}">{{ __('site.community.image_address') }}</label>
                    <input class="form-control" id="url_{{ $asset->id }}" value="{{ route('site.media.show', $asset) }}"
                        readonly>
                </div>
            </x-forms.modal>
            <x-forms.modal :id="'media-delete-' . $asset->id" :title="__('common.actions.delete')" :action="route('admin.media.destroy', $asset)" method="DELETE" :submit="__('common.actions.delete')"
                size="sm">
                <p>{{ __('site.community.confirm_delete_image') }}</p>
                <strong class="text-break">{{ $asset->original_name }}</strong>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
