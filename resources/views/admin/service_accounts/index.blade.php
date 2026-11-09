@use('App\Kraft\SiteDates')
@use('Illuminate\Support\Carbon')
@use('Illuminate\Support\Str')
@extends('admin.layouts.layout')
@section('title', __('service_accounts.title'))
@section('description', __('service_accounts.description'))
@section('actions')
    <a class="btn btn-primary" href="{{ route('admin.service_accounts.create') }}">{{ __('service_accounts.add') }}</a>
@endsection
@section('content')
    <div id="admin-service-accounts-list" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <section class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$services" class="mb-3" />
                <form class="row g-2" method="get" action="{{ route('admin.service_accounts.index') }}">
                    <div class="col-12 col-md-6">
                        <label class="form-label visually-hidden" for="service-search">{{ __('service_accounts.search') }}</label>
                        <input class="form-control" id="service-search" name="q" value="{{ request('q') }}"
                            maxlength="100" placeholder="{{ __('service_accounts.search') }}">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label visually-hidden" for="service-state">{{ __('service_accounts.state') }}</label>
                        <select class="form-select" id="service-state" name="state">
                            <option value="">{{ __('service_accounts.all_states') }}</option>
                            <option value="active" @selected(request('state') === 'active')>{{ __('service_accounts.active') }}</option>
                            <option value="disabled" @selected(request('state') === 'disabled')>{{ __('service_accounts.disabled') }}</option>
                        </select>
                    </div>
                    <noscript>
                        <div class="col-12 col-md-2">
                            <button class="btn btn-outline-secondary w-100"
                                type="submit">{{ __('service_accounts.filter') }}</button>
                        </div>
                    </noscript>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="username">{{ __('service_accounts.username') }}</th>
                            <th scope="col">{{ __('service_accounts.owner') }}</th>
                            <th scope="col">{{ __('service_accounts.state') }}</th>
                            <th scope="col" data-table-sort="active_tokens">{{ __('service_accounts.active_tokens') }}</th>
                            <th scope="col" data-table-sort="token_last_used_at">{{ __('service_accounts.last_used') }}</th>
                            <th scope="col" class="text-end">{{ __('common.labels.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($services as $service)
                            <tr>
                                <td>
                                    {{ $service->username }}
                                    @if ($service->service_description)
                                        <small
                                            class="d-block text-body-secondary">{{ Str::limit($service->service_description, 100) }}</small>
                                    @endif
                                </td>
                                <td>{{ $owners[$service->service_created_by] ?? __('service_accounts.unknown_owner') }}</td>
                                <td><span
                                        class="badge {{ $service->service_disabled_at ? 'text-bg-secondary' : 'text-bg-success' }}">{{ __($service->service_disabled_at ? 'service_accounts.disabled' : 'service_accounts.active') }}</span>
                                </td>
                                <td>{{ $service->active_tokens }}</td>
                                <td>{{ $service->token_last_used_at ? app(SiteDates::class)->format(Carbon::parse($service->token_last_used_at), 'd M Y H:i T') : __('service_accounts.never') }}
                                </td>
                                <td class="text-end">
                                    @if ($accounts->canManage(auth()->user(), $service))
                                        <a class="btn btn-outline-secondary btn-sm"
                                            href="{{ route('admin.service_accounts.edit', $service) }}">{{ __('service_accounts.manage') }}</a>
                                    @else
                                        <span
                                            class="small text-body-secondary">{{ __('service_accounts.cannot_manage') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="6" :message="__('service_accounts.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $services->links() }}
            </div>
        </section>
    </div>
@endsection
