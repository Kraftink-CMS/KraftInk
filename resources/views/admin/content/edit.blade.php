@extends('admin.layouts.layout')
@section('title', __('admin.' . $contentSection . ($entry ? '.edit' : '.create')))
@section('description', __('admin.' . $contentSection . '.description'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route($contentRoutes . '.index') }}">
        {{ __('admin.' . $contentSection . '.all') }}</a>
    @if ($entry?->revisions_exists)
        <a class="btn btn-outline-secondary ms-2"
            href="{{ route($contentRoutes . '.history', $entry) }}">{{ __('site.content.history') }}</a>
    @endif
@endsection
@section('content')
    @php
        $coverVisibility = old('cover_visibility', $entry?->cover_media_id ? 'public' : 'private');
        $privateCover = $selectedPrivateCover ?? null;
        $privateCoverAsset = $privateCover
            ? [
                'id' => $privateCover->id,
                'name' => $privateCover->original_name,
                'url' => route('site.content.images.show', $privateCover),
                'width' => $privateCover->width,
                'height' => $privateCover->height,
                'mime' => $privateCover->mime,
                'size' => $privateCover->size,
                'visibility' => 'private',
            ]
            : null;
        $publicCover = $selectedCover ?? null;
        $publicCoverAsset = $publicCover
            ? [
                'id' => $publicCover->id,
                'name' => $publicCover->original_name,
                'url' => route('site.media.show', $publicCover),
                'width' => $publicCover->width,
                'height' => $publicCover->height,
                'mime' => $publicCover->mime,
                'size' => $publicCover->size,
                'visibility' => 'public',
            ]
            : null;
        $coverAsset = $coverVisibility === 'private' ? $privateCoverAsset : $publicCoverAsset;
    @endphp
    <form data-ajax="true" data-redirect-on-upload="true"
        @if (!$entry) data-redirect-url="response"
        @elseif (!$entry->revisions_exists) data-redirect-url="{{ route($contentRoutes . '.edit', $entry) }}" @endif
        method="post"
        action="{{ route($contentRoutes . '.store', ['entry' => $entry]) }}" enctype="multipart/form-data" data-content-form
        data-content-kind="{{ $contentKind }}"
        data-site-url="{{ url('/') }}" data-content-image-limit="{{ min(100, max(1, (int) ini_get('max_file_uploads'))) }}">
        @csrf
        <div class="row g-4">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="title">{{ __('common.labels.title') }}</label>
                            <input class="form-control @error('title') is-invalid @enderror" id="title" name="title"
                                value="{{ old('title', $entry?->title) }}" required maxlength="200" autofocus
                                aria-describedby="content-title-help" data-content-title>
                            <div class="form-text" id="content-title-help">
                                {{ __('site.content.title_help') }}
                                @can('settings.manage')
                                    <a href="{{ route('admin.seo.index') }}" target="_blank">{{ __('seo.title') }}</a>
                                @endcan
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="summary">{{ __('site.content.summary') }}</label>
                            <textarea class="form-control" id="summary" name="summary" rows="3" maxlength="1000"
                                aria-describedby="content-summary-help">{{ old('summary', $entry?->summary) }}</textarea>
                            <div class="form-text" id="content-summary-help">
                                {{ __('site.content.summary_help') }}
                            </div>
                        </div>
                        <div>
                            <label class="form-label" for="body">{{ __('common.labels.content') }}</label>
                            <textarea class="form-control" id="body" name="body" rows="15" data-editor
                                data-editor-upload-url="{{ route('admin.content.images.store') }}">{{ old('body', $entry?->body) }}</textarea>
                        </div>
                    </div>
                </section>
                <section class="card">
                    <div class="card-body" data-content-cover>
                        <input type="hidden" name="cover_visibility" value="{{ $coverVisibility }}" data-cover-visibility>
                        <input type="hidden" name="cover_image_id"
                            value="{{ old('cover_image_id', $entry?->cover_image_id) }}" data-cover-private>
                        <input type="hidden" name="cover_media_id"
                            value="{{ old('cover_media_id', $entry?->cover_media_id) }}" data-cover-public>
                        <input type="hidden" name="cover_local_image" value="{{ old('cover_local_image') }}" data-cover-local>
                        <x-admin::media-picker name="cover_selection" :value="$coverAsset['id'] ?? null" :asset="$coverAsset"
                            identifier="mixed" privacy="mixed" profile="image" context="content-cover" :draft="true"
                            :private-store-url="route('admin.content.covers.store')"
                            :private-index-url="$entry ? route('admin.content.covers.index') : null"
                            :parameters="$entry ? ['entry' => $entry->id] : []" upload-name="cover_private_upload"
                            public-upload-name="cover_upload" :error-keys="['cover_image_id', 'cover_media_id', 'cover_upload', 'cover_local_image', 'content_images']" :label="__('site.content.cover')"
                            :help="__('site.content.cover_selection_help')"
                            :empty-label="__('site.content.no_cover')" />
                    </div>
                </section>
                <section class="card" data-content-seo-preview data-preview-url="{{ route('admin.content.seo.preview') }}"
                    data-loading="{{ __('seo.preview_loading') }}" data-error="{{ __('seo.preview_error') }}"
                    data-empty="{{ __('seo.preview_empty') }}">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('seo.content_preview_title') }}</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-body-secondary">{{ __('seo.content_preview_help') }}</p>
                        <div class="d-flex gap-3 align-items-start" data-content-seo-result hidden>
                            <img class="rounded object-fit-cover flex-shrink-0" width="96" height="96" alt=""
                                data-content-seo-image hidden>
                            <div class="flex-grow-1 text-break">
                                <p class="small mb-1" data-content-seo-url></p>
                                <p class="h5 text-primary mb-1" data-content-seo-title></p>
                                <p class="text-body-secondary mb-0" data-content-seo-description></p>
                            </div>
                        </div>
                        <p class="small text-body-secondary mb-0" data-content-seo-status role="status" aria-live="polite">
                            {{ __('seo.preview_loading') }}</p>
                    </div>
                    @can('settings.manage')
                        <div class="card-footer">
                            <a class="btn btn-outline-secondary btn-sm"
                                href="{{ route('admin.seo.index', ['tab' => 'pages', 'target' => $contentKind === 'page' ? 'site.pages.show' : 'site.news.show']) }}"
                                target="_blank" data-content-seo-models>{{ __('seo.configure_models') }}</a>
                        </div>
                    @endcan
                </section>
            </div>
            <aside class="col-lg-4 d-flex flex-column gap-4">
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('common.labels.publication') }}</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="locale">{{ __('common.labels.language') }}</label>
                            <select class="form-select" id="locale" name="locale">
                                @foreach (config('kraft.locales') as $code => $label)
                                    <option value="{{ $code }}" @selected(old('locale', $entry?->locale ?? app()->getLocale()) === $code)>{{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="slug">{{ __('admin.' . $contentSection . '.slug.label') }}</label>
                            <div class="input-group">
                                <span class="input-group-text"
                                    data-content-prefix>{{ $contentKind === 'page' ? '/p/' : '/news/' }}</span>
                                <input class="form-control @error('slug') is-invalid @enderror" id="slug"
                                    name="slug" value="{{ old('slug', $entry?->slug) }}" required maxlength="160"
                                    aria-describedby="content-address-help content-address-preview" data-content-slug>
                            </div>
                            <div class="form-text" id="content-address-help">
                                {{ __('admin.' . $contentSection . '.slug.help') }}
                            </div>
                            <p class="small text-break mt-2 mb-0" id="content-address-preview" aria-live="polite"><span
                                    class="text-body-secondary">{{ __('admin.content.slug.preview') }}</span><br><a
                                    data-content-preview href="{{ $entry?->url() ?? url($contentKind === 'page' ? '/p/' : '/news/') }}" target="_blank"
                                    rel="noopener noreferrer">{{ $entry?->url() ?? url($contentKind === 'page' ? '/p/' : '/news/') }}</a></p>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"
                                for="published_at">{{ __('admin.content.publication_date') }}</label>
                            <x-forms.local-datetime name="published_at" id="published_at" :value="$entry?->published_at" />
                            <div class="form-text">
                                {{ __('admin.content.publication_help') }}
                            </div>
                        </div>
                        @if ($contentKind === 'news')
                            <div data-news-settings>
                                @foreach (['is_pinned' => 'pinned', 'comments_enabled' => 'comments_enabled', 'reactions_enabled' => 'reactions_enabled', 'comments_moderated' => 'comments_moderated'] as $field => $label)
                                    <input type="hidden" name="{{ $field }}" value="0">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" id="{{ $field }}" name="{{ $field }}"
                                            type="checkbox" value="1" @checked(old($field, $entry?->{$field} ?? false))>
                                        <label class="form-check-label"
                                            for="{{ $field }}">{{ __('site.content.' . $label) }}</label>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="card-footer d-flex flex-wrap gap-2">
                        <button class="btn btn-primary" type="submit">{{ __('admin.' . $contentSection . '.save') }}</button>
                        <a class="btn btn-outline-secondary"
                            href="{{ route($contentRoutes . '.index') }}">{{ __('common.actions.cancel') }}</a>
                    </div>
                </section>
                <section class="card">
                    <div class="card-header">
                        <h2 class="card-title">{{ __('site.content.visibility') }}</h2>
                    </div>
                    <div class="card-body">
                        <label class="visually-hidden" for="visibility">{{ __('site.content.visibility') }}</label>
                        <select class="form-select mb-3" id="visibility" name="visibility" data-content-visibility>
                            @foreach (['public', 'auth', 'restricted'] as $visibility)
                                <option value="{{ $visibility }}" @selected(old('visibility', $entry?->visibility ?? 'public') === $visibility)>
                                    {{ __('site.content.visibility_' . $visibility) }}</option>
                            @endforeach
                        </select>
                        <fieldset class="mb-3" data-content-roles>
                            <legend class="fs-6">{{ __('site.content.roles') }}</legend>
                            @foreach ($roles as $role)
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox"
                                        id="content-role-{{ $role->id }}" name="role_ids[]"
                                        value="{{ $role->id }}" @checked(in_array($role->id, array_map('intval', old('role_ids', $entry?->roles->pluck('id')->all() ?? []))))>
                                    <label class="form-check-label"
                                        for="content-role-{{ $role->id }}">{{ $role->name }}</label>
                                </div>
                            @endforeach
                            <div class="form-text">
                                {{ __('site.content.roles_help') }}
                            </div>
                        </fieldset>
                        <details>
                            <summary>{{ __('site.content.required_permission') }}</summary>
                            <label class="visually-hidden"
                                for="required_permission">{{ __('site.content.required_permission') }}</label>
                            <input class="form-control mt-2" id="required_permission" name="required_permission"
                                value="{{ old('required_permission', $entry?->required_permission) }}" maxlength="100">
                            <div class="form-text">
                                {{ __('site.content.permission_help') }}
                            </div>
                        </details>
                    </div>
                </section>
            </aside>
        </div>
    </form>
    @if ($entry?->attachments->isNotEmpty())
        <section class="card">
            <div class="card-header">
                <h2 class="card-title">{{ __('site.content.attachments') }}</h2>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush mb-0">
                    @foreach ($entry->attachments as $file)
                        <li class="list-group-item px-0 d-flex justify-content-between gap-3"><a
                                href="{{ route($contentRoutes . '.attachments.download', ['entry' => $entry, 'attachment' => $file->id]) }}">{{ $file->original_name }}</a>
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route($contentRoutes . '.attachments.destroy', ['entry' => $entry, 'attachment' => $file->id]) }}"
                                data-confirm="{{ __('site.content.remove_attachment') }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"
                                    type="submit">{{ __('common.actions.delete') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
@endsection
