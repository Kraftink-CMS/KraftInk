<p class="text-body-secondary">
    {{ __($floodgateEnabled ? 'minecraft::messages.floodgate_profile_help' : 'minecraft::messages.profile_help') }}
</p>
<form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('games.minecraft.public.profile.store') }}">
    @csrf
    <div class="form-field mb-3">
        <label class="form-label" for="{{ $authInputPrefix ?? '' }}minecraft_name">{{ __('site.games.username') }}</label>
        <input class="form-control" id="{{ $authInputPrefix ?? '' }}minecraft_name" name="username"
            value="{{ old('username') }}" minlength="2" maxlength="17" autocomplete="off" required>
        <div class="form-text">
            {{ __($floodgateEnabled ? 'minecraft::messages.floodgate_profile_choose' : 'minecraft::messages.profile_choose') }}
        </div>
    </div>
    <button class="btn btn-primary" type="submit">{{ __('minecraft::messages.profile_continue') }}</button>
</form>
