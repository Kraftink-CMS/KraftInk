@if ($gamerTag)
    <p>{{ __('minecraft::bedrock.confirm_account') }}</p>
    <p class="fw-semibold text-break">{{ $gamerTag }}</p>
    <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post"
        action="{{ route('games.minecraft.public.bedrock.complete') }}">
        @csrf
        <input type="hidden" name="challenge" value="{{ $challengeNonce }}">
        <button class="btn btn-primary" type="submit">{{ __('minecraft::bedrock.confirm') }}</button>
    </form>
@elseif ($command)
    <p>{{ __('minecraft::bedrock.join') }}</p>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3" role="group"
        aria-label="{{ __('minecraft::bedrock.command') }}">
        <code class="bg-body-tertiary border rounded text-body text-break user-select-all flex-grow-1 px-3 py-2"
            dir="ltr">{{ $command }}</code>
        <button class="btn btn-outline-secondary" type="button" data-copy="{{ $command }}"
            data-copied="{{ __('site.home.copied') }}">{{ __('common.actions.copy') }}</button>
    </div>
    <p class="small text-body-secondary">{{ __('minecraft::bedrock.keep_private') }}</p>
    @if ($checked)
        <p class="alert alert-info">{{ __('minecraft::bedrock.waiting') }}</p>
    @endif
    <a class="btn btn-primary" href="{{ route('games.minecraft.public.bedrock.show', ['check' => 1]) }}">
        {{ __('minecraft::bedrock.check') }}
    </a>
@else
    <p>{{ __('minecraft::bedrock.intro') }}</p>
@endif
@unless ($gamerTag)
    <p class="small text-body-secondary mt-3 mb-0">{{ __('minecraft::bedrock.linked_java') }}</p>
@endunless
<form class="d-flex flex-wrap align-items-center gap-3 mt-3" data-ajax="true" data-redirect-url="response"
    data-success-message="false" method="post" action="{{ route('games.minecraft.public.bedrock.start') }}">
    @csrf
    <button class="btn {{ $gamerTag || $command ? 'btn-outline-secondary' : 'btn-primary' }}"
        type="submit">{{ __($gamerTag || $command ? 'minecraft::bedrock.restart' : 'minecraft::bedrock.start') }}</button>
    <a href="{{ route('auth.games.verify') }}">{{ __('minecraft::bedrock.back') }}</a>
</form>
