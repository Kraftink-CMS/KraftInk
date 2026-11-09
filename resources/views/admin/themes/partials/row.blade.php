<div class="border rounded p-3" data-theme-row>
    <div class="d-flex justify-content-end gap-2 mb-3">
        <button class="btn btn-outline-secondary btn-sm" type="button"
            data-theme-move="up">{{ __('themes.move_up') }}</button>
        <button class="btn btn-outline-secondary btn-sm" type="button"
            data-theme-move="down">{{ __('themes.move_down') }}</button>
        <button class="btn btn-outline-danger btn-sm" type="button"
            data-theme-remove>{{ __('themes.remove_item') }}</button>
    </div>
    <div class="row g-3">
        @foreach ($field['fields'] as $childKey => $childField)
            @include('admin.themes.partials.fields', [
                'fieldKey' => $childKey,
                'field' => $childField,
                'fieldName' => $fieldName . '[' . $rowId . '][' . $childKey . ']',
                'fieldPath' => $fieldPath . '.' . $rowId . '.' . $childKey,
                'fieldValue' => $rowValue[$childKey] ?? ($childField['default'] ?? null),
            ])
        @endforeach
    </div>
</div>
