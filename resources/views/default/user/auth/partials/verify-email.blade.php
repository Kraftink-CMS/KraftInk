@if (auth()->user()->email)
    <p class="auth-intro">{{ __('site.auth.verification.help') }}</p>
    <form data-ajax="true" method="post" action="{{ route('verification.send') }}">
        @csrf
        <button class="button btn btn-primary" type="submit">{{ __('site.auth.verification.resend') }} ↗</button>
    </form>
@else
    <p class="auth-intro">{{ __('auth_flow.email.add_first') }}</p>
@endif
<p class="form-foot text-center mt-4"><a href="{{ route('account.index') }}">{{ __('site.auth.account.title') }}</a></p>
