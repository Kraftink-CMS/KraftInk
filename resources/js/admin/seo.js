const initializedEditors = new WeakSet();

export function renderSeoTemplate(template, variables) {
    return template.replace(/\{([a-z][a-z0-9_]*)\}/g, (match, name) => variables[name] ?? match);
}

export function initSeoPreview(root = document) {
    const editors = [...root.querySelectorAll('[data-seo-editor]')];
    if (root.matches?.('[data-seo-editor]')) {
        editors.unshift(root);
    }
    editors.forEach((editor) => {
        if (initializedEditors.has(editor)) {
            return;
        }
        const variables = JSON.parse(editor.dataset['seoVariables']);
        const defaults = JSON.parse(editor.dataset['seoDefaults']);
        const targets = JSON.parse(editor.dataset['seoTargets'] || '{}');
        const form = editor.querySelector('[data-seo-form]');
        if (!form) {
            return;
        }
        initializedEditors.add(editor);
        const target = form.querySelector('[data-seo-target]');
        const loader = editor.querySelector('[data-seo-loader]');
        const snapshot = () =>
            new URLSearchParams(Array.from(new FormData(form), ([name, value]) => [name, String(value)])).toString();
        const originalValues = snapshot();
        const update = () => {
            const values = { ...variables };
            const selected = targets[target?.value];
            for (const [name, label] of Object.entries(selected?.variables || {})) {
                values[name] = `[${label}]`;
            }
            editor.querySelectorAll('[data-seo-variable-group]').forEach((group) => {
                group.hidden = group.dataset['seoVariableGroup'] !== target?.value;
            });
            const path = selected?.path;
            if (path?.startsWith('/') && !path.startsWith('//')) {
                values.path = path.replace(/\/\*$/, '/example');
                values.url = new URL(editor.dataset['seoBaseUrl'].replace(/\/$/, '') + values.path).href;
            }
            for (const field of ['title', 'description']) {
                if (selected?.defaults?.[field]) {
                    values[field] = renderSeoTemplate(selected.defaults[field], values);
                }
            }
            const title = form.querySelector('[data-seo-field="title"]').value.trim();
            const structure = form.querySelector('[data-seo-field="title_template"]');
            const titleValues = { ...values };
            let titleTemplate = title || defaults.title;
            if (structure) {
                titleValues.title = title ? renderSeoTemplate(title, values) : values.title;
                titleTemplate = structure.value.trim() || defaults.title_template;
            }
            editor.querySelector('[data-seo-preview="title"]').textContent =
                titleValues.title === values.website_name && titleTemplate === editor.dataset['seoDefaultTitle']
                    ? titleValues.title
                    : renderSeoTemplate(titleTemplate, titleValues);
            const descriptionTemplate =
                form.querySelector('[data-seo-field="description"]').value.trim() || defaults.description;
            editor.querySelector('[data-seo-preview="description"]').textContent = renderSeoTemplate(
                descriptionTemplate,
                values,
            );
            editor.querySelector('[data-seo-preview="url"]').textContent = values.url;
        };
        const changed = () => {
            update();
            if (loader) {
                const dirty = snapshot() !== originalValues;
                loader.dataset.confirm = dirty ? loader.dataset['dirtyConfirm'] : '';
            }
        };
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        let activeField = form.querySelector('[data-seo-field="title"]');
        form.addEventListener('focusin', (event) => {
            if (event.target.matches('[data-seo-field]')) {
                activeField = event.target;
            }
        });
        editor.querySelectorAll('[data-seo-insert]').forEach((button) => {
            button.addEventListener('click', () => {
                activeField.setRangeText(
                    button.dataset['seoInsert'],
                    activeField.selectionStart,
                    activeField.selectionEnd,
                    'end',
                );
                activeField.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        update();
    });
}

if (typeof document !== 'undefined') {
    initSeoPreview();
    document.addEventListener('kraft:content-added', (event) => initSeoPreview(event.target));
}
