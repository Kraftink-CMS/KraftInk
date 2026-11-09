@php
    $passkeys = app(\App\Kraft\Auth\Passkeys::class);
    $showPasskeys = $passkeys->enabled() && !$user->isServiceAccount();
    $accountPasskeys = $showPasskeys ? $user->passkeys()->latest()->get() : collect();
    $canAddPasskey = $showPasskeys && !$user->force_password_reset && $passkeys->available(request());
@endphp
@if ($accountPasskeys->isNotEmpty() || $canAddPasskey)
    <section class="panel card card-body p-4 mb-4" id="passkeys">
        <h2 class="mb-3">{{ __('passkeys.title') }}</h2>
        <p>{{ __('passkeys.help') }}</p>
        @if ($accountPasskeys->isNotEmpty())
            <ul class="list-group list-group-flush mb-3">
                @foreach ($accountPasskeys as $key)
                    <li class="list-group-item px-0 d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <span class="d-block fw-semibold">{{ $key->name ?? __('passkeys.default_name') }}</span>
                            <small class="text-body-secondary">
                                @if ($key->rp_id !== \App\Kraft\Auth\Passkeys::host())
                                    {{ $key->rp_id }} ·
                                @endif
                                {{ $key->last_used_at
                                    ? __('passkeys.last_used', ['date' => $key->last_used_at->translatedFormat('j F Y')])
                                    : __('passkeys.created', ['date' => $key->created_at->translatedFormat('j F Y')]) }}
                            </small>
                        </div>
                        <form method="post" action="{{ route('account.passkeys.destroy', ['key' => $key->id]) }}"
                            data-ajax="true" data-redirect-url="response">
                            @csrf
                            @method('DELETE')
                            @include('partials.forms.sensitive-fields')
                            <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('passkeys.remove') }}</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($canAddPasskey)
            <form method="post" action="{{ route('account.passkeys.store') }}" data-ajax="false" data-passkey-create hidden
                data-options="{{ route('account.passkeys.options') }}" data-error="{{ __('passkeys.invalid') }}">
                @csrf
                @include('partials.forms.sensitive-fields')
                <div class="mb-3">
                    <label class="form-label" for="passkey-name">{{ __('passkeys.name') }}</label>
                    <input class="form-control" id="passkey-name" name="name" maxlength="80"
                        placeholder="{{ __('passkeys.name_example') }}">
                </div>
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-key me-1" aria-hidden="true"></i>{{ __('passkeys.add') }}
                </button>
                <p class="alert alert-danger mt-2 mb-0" data-passkey-error role="alert" hidden></p>
            </form>
        @endif
    </section>
@endif
