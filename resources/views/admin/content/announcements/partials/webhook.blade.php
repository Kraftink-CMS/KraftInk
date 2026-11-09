<div class="mb-3">
    <label class="form-label" for="announcement-webhook">
        {{ $replacing ? __('admin.integrations.announcements.new_webhook') : __('admin.integrations.announcements.webhook') }}
    </label>
    <input @class(['form-control', 'is-invalid' => $errors->has('webhook_url')]) id="announcement-webhook" name="webhook_url" type="password" maxlength="500"
        autocomplete="new-password" placeholder="https://discord.com/api/webhooks/…"
        aria-describedby="announcement-setup @error('webhook_url') announcement-webhook-error @enderror"
        aria-invalid="{{ $errors->has('webhook_url') ? 'true' : 'false' }}" data-announcement-webhook>
    @error('webhook_url')
        <div class="invalid-feedback" id="announcement-webhook-error">{{ $message }}</div>
    @enderror
    <div class="form-text" id="announcement-setup">
        {{ __('admin.integrations.announcements.setup') }}
        <a href="https://support.discord.com/hc/en-us/articles/228383668-Intro-to-Webhooks" target="_blank"
            rel="noopener noreferrer">{{ __('admin.integrations.announcements.guide') }}</a>
    </div>
</div>
<fieldset class="d-none" data-announcement-proof hidden disabled>
    @include('partials.forms.confirm-action', ['confirmationId' => 'announcement-settings'])
</fieldset>
