@props([
    'id',
    'title',
    'action',
    'method' => 'POST',
    'submit' => null,
    'size' => 'lg',
    'multipart' => false,
    'open' => false,
    'variant' => null,
    'ajax' => true,
    'redirectUrl' => 'response',
    'closeOnSuccess' => true,
    'errorBag' => 'default',
])
@php
    $modalMethod = strtoupper($method);
@endphp
@php
    $buttonVariant = in_array($variant, ['primary', 'secondary', 'danger', 'warning', 'success'], true)
        ? $variant
        : ($modalMethod === 'DELETE'
            ? 'danger'
            : 'primary');
@endphp
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title"
    aria-hidden="true" @if ($open || ($errors->getBag($errorBag)->any() && ($errorBag !== 'default' || old('_modal') === $id))) data-kraft-modal-open @endif>
    <div @class([
        'modal-dialog modal-dialog-centered modal-dialog-scrollable',
        'modal-' . $size => in_array($size, ['sm', 'lg', 'xl'], true),
    ])>
        <form method="{{ $modalMethod === 'GET' ? 'get' : 'post' }}" action="{{ $action }}"
            @if ($ajax && $modalMethod !== 'GET')
                data-ajax="true"
                data-close-modal-on-success="{{ $closeOnSuccess ? 'true' : 'false' }}"
                @if ($redirectUrl)
                    data-redirect-url="{{ $redirectUrl }}"
                @endif
            @endif
            @if ($multipart)
                enctype="multipart/form-data"
            @endif
            {{ $attributes->class(['modal-content']) }}>
            @if ($modalMethod !== 'GET')
                @csrf
            @endif
            @if (!in_array($modalMethod, ['GET', 'POST'], true))
                @method($modalMethod)
            @endif
            <input type="hidden" name="_modal" value="{{ $id }}">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="{{ $id }}-title">{{ $title }}</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal"
                    aria-label="{{ __('common.actions.cancel') }}"></button>
            </div>
            <div class="modal-body">
                @include('partials.feedback.modal-errors', ['id' => $id, 'errorBag' => $errorBag])
                {{ $slot }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                {{ $footer ?? '' }}
                <button type="submit"
                    class="btn btn-{{ $buttonVariant }}">{{ $submit ?? __('common.actions.save') }}</button>
            </div>
        </form>
    </div>
</div>
