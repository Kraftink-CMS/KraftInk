<p>{{ __('site.auth.security.identity_help') }}</p>
<div class="d-grid gap-3 mt-4">
    @foreach ($providers as $provider)
        <a class="button btn btn-primary"
            href="{{ route('auth.oauth.redirect', ['provider' => $provider]) }}">{{ __('site.account.connections.continue_with', ['provider' => $registry->metadata($provider)['name'] ?? $provider]) }}</a>
    @endforeach
    @foreach ($actions ?? [] as $action)
        <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
            action="{{ route($action['route']) }}">
            @csrf
            <button class="button btn btn-outline-primary w-100" type="submit">{{ __($action['label']) }}</button>
        </form>
    @endforeach
</div>
