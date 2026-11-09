@use('Illuminate\Support\Arr')
@use('Illuminate\View\ComponentAttributeBag')
@php
    $alertType = in_array($type, ['success', 'error', 'warning'], true) ? $type : 'error';
    $feedbackDefaults = config('forms', []);
    $feedbackContext = request()->routeIs('admin.*') ? 'admin' : 'site';
    $feedbackOptions = array_replace_recursive(
        $feedbackDefaults,
        $feedbackDefaults['contexts'][$feedbackContext] ?? [],
        $formConfig ?? [],
    );
    $alertMessages = collect(Arr::wrap($messages ?? []))
        ->flatten()
        ->filter(fn ($message) => is_string($message) && trim($message) !== '')
        ->map(fn ($message) => __(trim($message)))
        ->unique()
        ->values();
    $alertClass = $feedbackOptions['classes'][$alertType] ?? ($alertType === 'error' ? 'alert-danger' : 'alert-' . $alertType);
@endphp
@if ($alertMessages->isNotEmpty())
    <div class="{{ $feedbackOptions['classes']['message'] ?? 'alert' }} {{ $alertClass }} d-flex align-items-start gap-3"
        role="{{ $alertType === 'error' ? 'alert' : 'status' }}" {{ new ComponentAttributeBag($alertAttributes ?? []) }}>
        <i class="{{ $feedbackOptions['icons'][$alertType] ?? 'bi bi-info-circle-fill' }} flex-shrink-0 fs-4 lh-1 mt-1"
            aria-hidden="true"></i>
        <div class="flex-grow-1 text-break">
            <strong class="d-block mb-1">{{ $feedbackOptions['titles'][$alertType] ?? __('common.forms.' . $alertType . '_title') }}</strong>
            <div>
                @if ($alertMessages->count() === 1)
                    {{ $alertMessages->first() }}
                @else
                    <ul class="mb-0">
                        @foreach ($alertMessages as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endif
