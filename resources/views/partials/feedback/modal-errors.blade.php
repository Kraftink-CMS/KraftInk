@php
    $modalErrorBag = $errorBag ?? 'default';
    $modalErrors = $errors->getBag($modalErrorBag);
    $showModalErrors = $modalErrors->any() && ($modalErrorBag !== 'default' || old('_modal') === $id);
@endphp
@if ($showModalErrors && $modalErrorBag === 'default')
    @section('feedback-errors-in-modal', $id)
@endif
<div data-ajax-messages @if (!$showModalErrors) hidden @endif>
    @if ($showModalErrors)
        @include('partials.feedback.alert', [
            'type' => 'error',
            'messages' => $modalErrors->all(),
            'alertAttributes' => ['tabindex' => '-1', 'data-modal-errors' => ''],
        ])
    @endif
</div>
