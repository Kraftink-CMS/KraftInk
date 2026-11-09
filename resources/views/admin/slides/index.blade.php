@extends('admin.layouts.layout')
@section('title', __('admin.slides.title'))
@section('description', __('admin.slides.description'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.slides.create') }}">
        {{ __('admin.slides.create') }}</a>
@endsection
@section('content')
    <div id="admin-slides-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$slides" container-class="card-body" />
            <div class="card-body p-0">
                @if ($slides->isNotEmpty())
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="text-nowrap">
                                <tr>
                                    <th scope="col">{{ __('admin.slides.image') }}</th>
                                    <th scope="col" data-table-sort="title">{{ __('common.labels.title') }}</th>
                                    <th scope="col" data-table-sort="locale">{{ __('common.labels.language') }}</th>
                                    <th scope="col">{{ __('common.labels.publication') }}</th>
                                    <th scope="col" data-table-sort="position">{{ __('admin.slides.order') }}</th>
                                    <th scope="col">{{ __('common.labels.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($slides as $slide)
                                    <tr>
                                        <td>
                                            @if ($slide->media)
                                                <img src="{{ route('site.media.show', $slide->media) }}" alt=""
                                                    width="120" height="68" class="object-fit-cover rounded">
                                            @else
                                                <span class="text-body-secondary">{{ __('admin.slides.missing_image') }}</span>
                                            @endif
                                        </td>
                                        <th scope="row"><a
                                                href="{{ route('admin.slides.edit', $slide) }}">{{ $slide->title ?: $slide->alt }}</a>
                                        </th>
                                        <td>{{ $slide->locale ? config('kraft.locales.' . $slide->locale, $slide->locale) : __('admin.slides.all_languages') }}
                                        </td>
                                        <td><span
                                                @class([
                                                    'badge',
                                                    'text-bg-success' => $slide->publicationState() === 'visible',
                                                    'text-bg-secondary' => $slide->publicationState() !== 'visible',
                                                ])>{{ __('admin.slides.state.' . $slide->publicationState()) }}</span>
                                            <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post" action="{{ route('admin.slides.visibility', $slide) }}"
                                                class="mt-2">
                                                @csrf
                                                <input type="hidden" name="enabled" value="{{ $slide->enabled ? '0' : '1' }}">
                                                <button class="btn btn-outline-secondary btn-sm"
                                                    type="submit">{{ $slide->enabled ? __('common.actions.disable') : __('common.actions.enable') }}</button>
                                            </form>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span>{{ $slide->position }}</span>
                                                @foreach (['up', 'down'] as $direction)
                                                    <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post" action="{{ route('admin.slides.move', $slide) }}">
                                                        @csrf
                                                        <input type="hidden" name="direction" value="{{ $direction }}">
                                                        <button class="btn btn-outline-secondary btn-sm" type="submit"
                                                            aria-label="{{ __('admin.slides.move_' . $direction, ['title' => $slide->title ?: $slide->alt]) }}">{{ $direction === 'up' ? '↑' : '↓' }}</button>
                                                    </form>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <a class="btn btn-outline-primary btn-sm"
                                                    href="{{ route('admin.slides.edit', $slide) }}">{{ __('common.actions.edit') }}</a>
                                                <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('admin.slides.destroy', $slide) }}"
                                                    data-confirm="{{ __('admin.slides.confirm_delete') }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-outline-danger btn-sm"
                                                        type="submit">{{ __('common.actions.delete') }}</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-body-secondary p-4 mb-0">{{ __('admin.slides.empty') }}</p>
                @endif
            </div>
            <div class="card-footer">
                {{ $slides->links() }}
            </div>
        </section>
    </div>
@endsection
