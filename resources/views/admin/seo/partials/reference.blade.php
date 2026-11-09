<section class="card">
    <div class="card-header">
        <h2 class="card-title">{{ __('seo.preview') }}</h2>
    </div>
    <div class="card-body">
        <p class="small text-body-secondary">{{ __('seo.preview_help') }}</p>
        <p class="small text-break mb-1" data-seo-preview="url">{{ $previewVariables['url'] }}</p>
        <p class="h5 text-primary text-break mb-1" data-seo-preview="title">
            {{ $previewTitle }}
        </p>
        <p class="text-body-secondary text-break mb-0" data-seo-preview="description">
            {{ $previewVariables['description'] }}</p>
    </div>
    <div class="card-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal"
            data-bs-target="#{{ $variableDialog['id'] }}">{{ __('seo.variables_title') }}</button>
    </div>
</section>
<div class="modal fade" id="{{ $variableDialog['id'] }}" tabindex="-1" aria-labelledby="{{ $variableDialog['id'] }}-title"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="{{ $variableDialog['id'] }}-title">{{ __('seo.variables_title') }}</h2>
                <button class="btn-close" type="button" data-bs-dismiss="modal"
                    aria-label="{{ __('common.actions.cancel') }}"></button>
            </div>
            <div class="modal-body">
                <p class="text-body-secondary">{{ __('seo.variables_help') }}</p>
                @foreach ($variableDialog['groups'] as $key => $variables)
                    <div @if ($key !== 'common') data-seo-variable-group="{{ $key }}" hidden @endif>
                        @if ($key !== 'common')
                            <h3 class="h6 mt-3">{{ $seoTargets[$key]['label'] }}</h3>
                        @endif
                        <dl class="mb-0">
                            @foreach ($variables as $variable => $label)
                                <dt>
                                    <button class="btn btn-outline-secondary btn-sm" type="button"
                                        data-seo-insert="{{ '{' . $variable . '}' }}" data-bs-dismiss="modal"
                                        aria-label="{{ __('seo.insert_variable', ['variable' => '{' . $variable . '}']) }}"><code>{{ '{' . $variable . '}' }}</code></button>
                                </dt>
                                <dd class="mt-1">{{ $label }}</dd>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button"
                    data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
