@extends('admin.layouts.layout')
@section('title', __('widgets.title'))
@section('description', __('widgets.description'))
@section('actions')
    @if ($definitions)
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#widget-add"><i
                class="bi bi-plus-lg me-1" aria-hidden="true"></i>{{ __('widgets.add') }}</button>
    @endif
@endsection
@section('content')
    <div id="admin-widgets-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <x-tables.controls :paginator="$instances" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="widget">{{ __('widgets.widget') }}</th>
                            <th scope="col" data-table-sort="zone">{{ __('widgets.zone') }}</th>
                            <th scope="col" data-table-sort="enabled">{{ __('common.labels.status') }}</th>
                            <th scope="col">{{ __('widgets.position') }}</th>
                            <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($instances as $instance)
                            @php($definition = $definitions[$instance['widget']] ?? null)
                            <tr>
                                <td>
                                    <strong>{{ $definition ? __($definition['label']) : __('widgets.unavailable') }}</strong>
                                    <small class="d-block text-body-secondary">{{ $instance['widget'] }}</small>
                                </td>
                                <td>{{ isset($zones[$instance['zone']]) ? __($zones[$instance['zone']]) : $instance['zone'] }}</td>
                                <td><span @class(['badge', 'text-bg-success' => $definition && $instance['enabled'], 'text-bg-secondary' => !$definition || !$instance['enabled']])>{{ !$definition ? __('widgets.unavailable') : ($instance['enabled'] ? __('common.statuses.visible') : __('common.statuses.hidden')) }}</span></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        @foreach (['up' => 'arrow-up', 'down' => 'arrow-down'] as $direction => $icon)
                                            <form method="post" action="{{ route('admin.widgets.move', ['widget' => $instance['id'], 'page' => $instances->currentPage() > 1 ? $instances->currentPage() : null]) }}"
                                                data-ajax="true" data-redirect-url="response" data-success-message="false">
                                                @csrf
                                                <input type="hidden" name="direction" value="{{ $direction }}">
                                                <button class="btn btn-sm btn-outline-secondary" type="submit"
                                                    aria-label="{{ __('widgets.move_' . $direction) }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></button>
                                            </form>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end flex-wrap gap-2">
                                        @if ($definition)
                                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.widgets.edit', $instance['id']) }}">{{ __('common.actions.edit') }}</a>
                                        @endif
                                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal"
                                            data-bs-target="#widget-delete-{{ $instance['id'] }}">{{ __('common.actions.delete') }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('widgets.empty')" :description="$definitions ? __('widgets.empty_help') : __('widgets.no_definitions')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $instances->links() }}
            </div>
        </section>
        @if ($definitions)
            <x-forms.modal id="widget-add" :title="__('widgets.add')" :action="route('admin.widgets.create')" method="GET" :submit="__('widgets.configure')">
                <label class="form-label" for="widget-type">{{ __('widgets.widget') }}</label>
                <select class="form-select" id="widget-type" name="widget" required>
                    @foreach ($definitions as $key => $definition)
                        <option value="{{ $key }}">{{ __($definition['label']) }}</option>
                    @endforeach
                </select>
            </x-forms.modal>
        @endif
        @foreach ($instances as $instance)
            <x-forms.modal :id="'widget-delete-' . $instance['id']" :title="__('widgets.delete')" :action="route('admin.widgets.destroy', ['widget' => $instance['id'], 'page' => $instances->currentPage() > 1 ? $instances->currentPage() : null])" method="DELETE" :submit="__('common.actions.delete')" size="sm">
                <p class="mb-0">{{ __('widgets.confirm_delete') }}</p>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
