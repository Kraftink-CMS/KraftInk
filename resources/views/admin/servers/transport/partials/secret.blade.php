<div class="mb-3">
    <label class="form-label" for="{{ $fieldId }}">{{ __($secretLabel) }}</label>
    <input class="form-control" id="{{ $fieldId }}" type="password" name="transport_secret"
        autocomplete="new-password" maxlength="2048" @required($required)
        @if ($configured) aria-describedby="{{ $fieldId }}-help" @endif>
    @if ($configured)
        <div class="form-text" id="{{ $fieldId }}-help">{{ __('admin.servers.transport.secret_help') }}</div>
        <small class="text-success">{{ __('admin.servers.transport.configured') }}</small>
    @endif
</div>
