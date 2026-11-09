@php
    $pageMessages = collect([
        'success' => [session('status'), session('success')],
        'error' => [session('error'), $__env->hasSection('feedback-errors-in-modal') ? [] : $errors->all()],
        'warning' => [session('warning')],
    ])
        ->map(
            fn($messages) => collect($messages)
                ->flatten()
                ->filter(fn($message) => is_string($message) && trim($message) !== '')
                ->values()
                ->all(),
        )
        ->filter();
@endphp
<div data-page-messages data-ajax-messages @if ($pageMessages->isEmpty()) hidden @endif>
    @foreach ($pageMessages as $type => $messages)
        @include('partials.feedback.alert', ['type' => $type, 'messages' => $messages])
    @endforeach
</div>
