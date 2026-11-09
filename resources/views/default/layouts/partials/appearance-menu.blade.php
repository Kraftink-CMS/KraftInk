@if ($appearance['selectable'])
    <details class="navigation-dropdown appearance-menu">
        <summary class="nav-link dropdown-toggle"><i class="bi bi-circle-half me-1"
                aria-hidden="true"></i><span>{{ __('admin.settings.appearance.title') }}</span></summary>
        <div class="dropdown-menu dropdown-menu-end">
            @foreach ($appearance['modes'] as $mode)
                <form data-ajax="true" data-redirect-url="response" data-success-message="false" method="post" action="{{ route('site.appearance.update') }}">
                    @csrf
                    <input type="hidden" name="appearance" value="{{ $mode }}">
                    <button class="dropdown-item d-flex justify-content-between align-items-center gap-3" type="submit"
                        aria-pressed="{{ $appearance['choice'] === $mode ? 'true' : 'false' }}"><span>{{ __('admin.settings.appearance.' . $mode) }}</span>
                        @if ($appearance['choice'] === $mode)
                            <i class="bi bi-check2" aria-hidden="true"></i>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </details>
@endif
