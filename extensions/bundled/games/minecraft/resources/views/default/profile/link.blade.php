<section class="panel card card-body p-4 mt-4">
    <h2 class="mb-3">{{ __('minecraft::messages.profile_title') }}</h2>
    <form data-ajax="true" data-redirect-url="response" data-external-redirect="oauth" method="post"
        action="{{ route('account.connections.store', ['provider' => $provider]) }}">
        @csrf
        @include('partials.forms.sensitive-fields')
        <button class="btn btn-outline-primary" type="submit">{{ __('minecraft::messages.profile_link') }}</button>
    </form>
</section>
