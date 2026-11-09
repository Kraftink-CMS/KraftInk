@use('App\Kraft\Site\SeoConfiguration')
@php
    $fieldPrefix = $fieldPrefix ?? 'seo';
    $useOld = $useOld ?? true;
    $primaryNames = $pageEditor ?? false ? ['title', 'description'] : ['title'];
    $primaryFields = array_intersect_key($seoFields, array_flip($primaryNames));
    $advancedFields = array_diff_key($seoFields, $primaryFields);
    $advancedInvalid =
        $useOld && collect([...array_keys($advancedFields), 'robots'])->contains(fn($field) => $errors->has($field));
@endphp
@include('admin.seo.partials.text-fields', ['seoFields' => $primaryFields])
<details class="mb-3" @if ($advancedInvalid) open @endif>
    <summary class="text-primary">{{ __($pageEditor ?? false ? 'seo.advanced_page' : 'seo.advanced_global') }}</summary>
    <div class="mt-3">
        @include('admin.seo.partials.text-fields', ['seoFields' => $advancedFields])
        <div class="mb-3">
            <label class="form-label" for="{{ $fieldPrefix }}-robots">{{ __('seo.fields.robots') }}</label>
            <select class="form-select" id="{{ $fieldPrefix }}-robots" name="robots"
                aria-describedby="{{ $fieldPrefix }}-robots-help">
                @if ($pageEditor ?? false)
                    <option value="">{{ __('seo.inherit') }}</option>
                @endif
                @foreach (SeoConfiguration::ROBOTS as $robots)
                    @php
                        $robotsValue = $useOld
                            ? old('robots', $values['robots'] ?? ($pageEditor ?? false ? '' : 'index,follow'))
                            : $values['robots'] ?? ($pageEditor ?? false ? '' : 'index,follow');
                    @endphp
                    <option value="{{ $robots }}" @selected($robotsValue === $robots)>{{ __('seo.robots.' . $robots) }}
                    </option>
                @endforeach
            </select>
            @if ($useOld && $errors->has('robots'))
                <div class="invalid-feedback d-block">{{ $errors->first('robots') }}</div>
            @endif
            <div class="form-text" id="{{ $fieldPrefix }}-robots-help">{{ __('seo.robots_help') }}</div>
        </div>
    </div>
</details>
<x-admin::translation-fields :id="$fieldPrefix . '-translations'" :fields="$seoFields" :values="$values['translations'] ?? []" :use-old="$useOld" />
