@if ($captcha)
    <div class="mb-3">
        <div class="{{ $captcha['class'] }}" data-captcha-provider="{{ $captcha['id'] }}"
            data-captcha-script="{{ $captcha['script'] }}" data-captcha-api="{{ $captcha['javascript_api'] ?? '' }}"
            data-sitekey="{{ $captcha['site_key'] }}" data-action="{{ $captchaAction }}"
            data-language="{{ app()->getLocale() }}">
        </div>
    </div>
@endif
