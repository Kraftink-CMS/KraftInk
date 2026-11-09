@once
    @php
        $formDefaults = config('forms', []);
        $formContext = request()->routeIs('admin.*') ? 'admin' : 'site';
        $formOptions = array_replace_recursive(
            $formDefaults,
            [
                'messages' => __('common.forms'),
                'confirmation' => array_merge(__('common.confirmation'), [
                    'api_path' => parse_url(url('/api'), PHP_URL_PATH),
                ]),
                'pagination' => [
                    'error' => __('pagination.error'),
                    'retry' => __('pagination.retry'),
                ],
                'tables' => __('tables'),
                'titles' => [
                    'success' => __('common.forms.success_title'),
                    'error' => __('common.forms.error_title'),
                    'warning' => __('common.forms.warning_title'),
                ],
            ],
            $formDefaults['contexts'][$formContext] ?? [],
            $formConfig ?? [],
        );
        unset($formOptions['contexts']);
    @endphp
    <script type="application/json" id="kraft-form-config">{!! json_encode($formOptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endonce
