@use('App\Kraft\Updates\UpdateLabels')
@use('Illuminate\Contracts\Pagination\Paginator')
@props([
    'runs' => [],
    'showEmpty' => true,
    'operation' => 'update',
    'historyId' => 'update-history',
    'heading' => null,
])
@php
    $paginated = $runs instanceof Paginator;
    $runItems = $paginated ? $runs->items() : $runs;
    $operationLabels = app(UpdateLabels::class)->all();
    $updateLabels = $operationLabels[$operation] ?? $operationLabels['update'];
    $updateLabels['operations'] = $operationLabels;
    if (
        $operation !== 'install' &&
        ($paginated || collect($runItems)->contains(fn($item) => ($item['operation'] ?? 'update') === 'install'))
    ) {
        $updateLabels['heading'] = __('system.updates.mixed_history');
    }
    $updateLabels['heading'] = $heading ?? $updateLabels['heading'];
@endphp
<div class="vstack gap-4" data-update-screen data-labels='@json($updateLabels)'>
    {{ $slot }}
    <div class="vstack gap-4" id="{{ $historyId }}" data-update-history-page
        @if ($paginated) data-ajax-pagination data-table data-page-size="{{ $runs->perPage() }}" data-page-name="{{ $runs->getPageName() }}" @endif
        tabindex="-1">
        <section class="card" data-update-history-shell @if (!$showEmpty && !$paginated && count($runs) === 0) hidden @endif>
            <div class="card-header">
                <h2 class="card-title" id="{{ $historyId }}-heading">{{ $updateLabels['heading'] }}</h2>
            </div>
            @if ($paginated)
                <x-tables.controls :paginator="$runs" container-class="card-body" />
            @endif
            <div class="card-body" data-update-history aria-labelledby="{{ $historyId }}-heading">
                @forelse($runs ?? [] as $run)
                    @php
                        $runState = $run['state'] ?? 'unknown';
                        $runProgress = max(0, min(100, (int) ($run['progress'] ?? 0)));
                        $progressToken =
                            session('updates.tokens.' . $run['id']) ?:
                            (isset($run['extension_id'])
                                ? session('updates.installation_tokens.' . $run['extension_id'])
                                : null);
                        $terminalState = in_array(
                            $runState,
                            ['completed', 'rolled_back', 'recovery_required', 'failed'],
                            true,
                        );
                        $runOperation = $run['operation'] ?? $operation;
                        $runOperation = isset($operationLabels[$runOperation]) ? $runOperation : 'update';
                        $runLabels = $operationLabels[$runOperation];
                    @endphp
                    <article class="border-bottom pb-4 mb-4" data-update-run="{{ $run['id'] }}"
                        data-update-operation="{{ $runOperation }}" data-update-state="{{ $runState }}"
                        data-progress-url="{{ asset('update-status.php') . '?' . http_build_query(['run' => $run['id']]) }}"
                        data-update-token="{{ $progressToken }}"
                        @if (!$terminalState && $runState !== 'staged') data-update-resume @endif>
                        <div class="d-flex justify-content-between gap-3 mb-2">
                            <strong
                                data-update-version>{{ $run['subject'] ?? ($run['version'] ?? $run['id']) }}</strong><span
                                class="badge text-bg-secondary"
                                data-update-status>{{ $runLabels['states'][$runState] ?? __('system.updates.unknown') }}</span>
                        </div>
                        <div class="d-flex justify-content-between small text-secondary mb-2">
                            <span data-update-id>{{ $run['id'] }}</span><span
                                data-update-percent>{{ $runProgress }}%</span>
                        </div>
                        <progress data-update-progress value="{{ $runProgress }}" max="100"
                            aria-label="{{ $runLabels['progress'] }}"></progress>
                        <p class="small text-secondary mt-2" data-update-message role="status" aria-live="polite">
                            {{ $runState === 'staged' ? $runLabels['ready'] : ($runState === 'failed' ? $runLabels['failure'] : ($terminalState ? $runLabels['finished'] : $runLabels['running'])) }}
                        </p>
                        <button class="mb-3 btn btn-outline-secondary btn-sm" type="button" data-update-retry
                            hidden>{{ __('system.updates.retry_tracking') }}</button>
                        <div class="mt-3 alert alert-danger" data-update-recovery role="alert"
                            @if ($runState !== 'recovery_required') hidden @endif>
                            <strong>{{ __('system.updates.state.recovery_required') }}</strong>
                            <p>{{ __('system.updates.recovery_help') }}</p>
                            <code>php artisan kraft:update --recover={{ $run['id'] }}</code>
                            <p class="mt-2 mb-0">{{ __('system.updates.recovery_log') }}</p>
                        </div>
                        @if ($runState === 'staged')
                            <form method="post"
                                action="{{ $run['run_url'] ?? route('admin.updates.run', ['id' => $run['id']]) }}"
                                data-update-start>
                                @csrf
                                <input type="hidden" data-sensitive-confirmation
                                    data-sensitive-password-field="password">
                                <button class="btn btn-primary" type="submit">{{ $runLabels['run'] }}</button>
                            </form>
                        @endif
                        @if (!$progressToken && !$terminalState)
                            <p class="small text-secondary">{{ $runLabels['tracking_unavailable'] }}</p>
                        @endif
                    </article>
                @empty
                    <div class="text-center py-5 px-4 text-body-secondary" data-update-empty>
                        <h3 class="h6">{{ $updateLabels['empty_title'] }}</h3>
                        <p>{{ $updateLabels['empty_help'] }}</p>
                    </div>
                @endforelse
            </div>
            @if ($paginated)
                <div class="card-footer">
                    {{ $runs->links() }}
                </div>
            @endif
        </section>
    </div>
    <template data-update-template>
        <article class="border-bottom pb-4 mb-4" data-update-run
            data-update-operation="{{ isset($operationLabels[$operation]) ? $operation : 'update' }}"
            data-update-state="staged" tabindex="-1">
            <div class="d-flex justify-content-between gap-3 mb-2">
                <strong data-update-version></strong><span class="badge text-bg-secondary" data-update-status></span>
            </div>
            <div class="d-flex justify-content-between small text-secondary mb-2">
                <span data-update-id></span><span data-update-percent>0%</span>
            </div>
            <progress data-update-progress value="0" max="100"
                aria-label="{{ $updateLabels['progress'] }}"></progress>
            <p class="small text-secondary mt-2" data-update-message role="status" aria-live="polite"></p>
            <button class="mb-3 btn btn-outline-secondary btn-sm" type="button" data-update-retry
                hidden>{{ __('system.updates.retry_tracking') }}</button>
            <div class="mt-3 alert alert-danger" data-update-recovery role="alert" hidden>
                <strong>{{ __('system.updates.state.recovery_required') }}</strong>
                <p>{{ __('system.updates.recovery_help') }}</p>
                <code data-update-recovery-command></code>
                <p class="mt-2 mb-0">{{ __('system.updates.recovery_log') }}</p>
            </div>
            <form method="post" data-update-start hidden>
                @csrf
                <input type="hidden" data-sensitive-confirmation data-sensitive-password-field="password">
                <button class="btn btn-primary" type="submit">{{ $updateLabels['run'] }}</button>
            </form>
        </article>
    </template>
</div>
