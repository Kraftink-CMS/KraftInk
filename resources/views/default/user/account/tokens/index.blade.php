@use('App\Kraft\SiteDates')
@extends('default.layouts.layout')
@section('title', __('admin.integrations.auth_api.my_tokens'))
@section('content')
    <header class="page-heading">
        <p class="eyebrow small text-body-secondary">{{ __('site.auth.account.title') }}</p>
        <h1>{{ __('admin.integrations.auth_api.my_tokens') }}</h1>
        <p>{{ __('admin.integrations.auth_api.tokens_help') }}</p>
        <a class="back-link d-inline-block mb-3" href="{{ url('/account') }}">←
            {{ __('site.auth.account.title') }}</a>
    </header>
    @if ($canCreate)
        <form data-ajax="true" data-redirect-url="response" class="card mb-4" method="post" action="{{ route('account.tokens.store') }}">
            @csrf
            <div class="card-body">
                <h2 class="h4">{{ __('api.create') }}</h2>
                <p class="text-body-secondary">{{ __('api.create_help') }}</p>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="api-name">{{ __('api.name') }}</label>
                        <input class="form-control" id="api-name" name="name" value="{{ old('name') }}"
                            maxlength="120" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="api-lifetime">{{ __('api.lifetime') }}</label>
                        <input class="form-control" id="api-lifetime" name="lifetime" type="number"
                            value="{{ old('lifetime', $lifetime) }}" min="15"
                            max="{{ min(43200, max(15, $lifetime)) }}" required aria-describedby="api-lifetime-help">
                    </div>
                    <div class="col-12">
                        <p class="form-text mb-0" id="api-lifetime-help">{{ __('api.lifetime_help') }}</p>
                    </div>
                    <fieldset class="col-12">
                        <legend class="h6">{{ __('api.rights') }}</legend>
                        @foreach ($scopes as $scope => $definition)
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="api-scope-{{ $loop->index }}"
                                    name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true))>
                                <label class="form-check-label"
                                    for="api-scope-{{ $loop->index }}">{{ __($definition['label']) }}
                                    <code>{{ $scope }}</code></label>
                                <div class="form-text">{{ __($definition['description']) }}</div>
                            </div>
                        @endforeach
                        <p class="form-text mb-0">{{ __('api.rights_help') }}</p>
                    </fieldset>
                    <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('api.create') }}</button>
            </div>
        </form>
    @else
        <p class="alert alert-info">{{ __('api.unavailable') }}</p>
    @endif
    <div id="account-token-list" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        <section class="card mb-4">
            <div class="card-body">
                @forelse ($tokens as $token)
                    <article @class(['pb-4 mb-4 border-bottom' => !$loop->last])>
                        <h2 class="h4 mb-3">{{ $token->name }}</h2>
                        <p>{{ __('admin.integrations.auth_api.expires') }}
                            {{ app(SiteDates::class)->format($token->expires_at, 'd M Y H:i T') }}</p>
                        <p>{{ __('admin.integrations.auth_api.last_used') }}
                            {{ $token->last_used_at?->diffForHumans() ?? __('admin.integrations.auth_api.never') }}</p>
                        <p><code>{{ implode(', ', $token->scopes) }}</code></p>
                        <form data-ajax="true" data-redirect-url="response" method="post"
                            action="{{ route('account.tokens.revoke') }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="token" value="{{ $token->id }}">
                            <button type="submit"
                                class="btn btn-outline-secondary">{{ __('admin.integrations.auth_api.revoke') }}</button>
                        </form>
                    </article>
                @empty
                    <p class="mb-0">{{ __('admin.integrations.auth_api.no_tokens') }}</p>
                @endforelse
            </div>
            @if ($tokens->isNotEmpty())
                <div class="card-footer">
                    <form data-ajax="true" data-redirect-url="response" method="post"
                        action="{{ route('account.tokens.revoke') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-danger"
                            type="submit">{{ __('admin.integrations.auth_api.revoke_all') }}</button>
                    </form>
                </div>
            @endif
        </section>
        {{ $tokens->links() }}
    </div>
@endsection
