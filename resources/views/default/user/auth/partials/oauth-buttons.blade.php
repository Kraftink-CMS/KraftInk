@if (!empty($externalLogins))
    <div class="d-flex align-items-center gap-3 my-3">
        <hr class="flex-grow-1 my-0">
        <span class="small text-body-secondary">{{ __('auth_flow.providers.' . ($capabilities->canLoginLocally ? 'alternative' : 'heading')) }}</span>
        <hr class="flex-grow-1 my-0">
    </div>
    <div class="d-flex flex-wrap gap-2">
        @foreach ($externalLogins as $externalLogin)
            <a class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center gap-2 flex-fill"
                href="{{ $externalLogin['url'] }}">
                <i class="bi bi-{{ $externalLogin['icon'] }}" aria-hidden="true"></i>
                {{ ($externalLoginLabels[$externalLogin['label']] ?? 0) > 1 ? $externalLogin['name'] : $externalLogin['label'] }}
            </a>
        @endforeach
    </div>
@endif
