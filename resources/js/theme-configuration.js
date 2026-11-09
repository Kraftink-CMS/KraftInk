document.querySelectorAll('[data-theme-repeater]').forEach((repeater) => {
    const rows = repeater.querySelector('[data-theme-rows]');
    const template = repeater.querySelector('[data-theme-row-template]');
    const add = repeater.querySelector('[data-theme-add]');
    let nextIndex = Number(repeater.dataset.nextIndex);

    const update = () => {
        add.disabled = rows.children.length >= Number(repeater.dataset.max);
        [...rows.children].forEach((row, index) => {
            row.querySelector('[data-theme-move="up"]').disabled = index === 0;
            row.querySelector('[data-theme-move="down"]').disabled = index === rows.children.length - 1;
        });
    };

    const move = (row, before) => {
        row.querySelectorAll('textarea[data-editor]').forEach((field) => {
            const editor = window.tinymce?.get(field.id);
            editor?.save();
            editor?.remove();
            delete field.dataset.editorReady;
        });
        rows.insertBefore(row, before);
        document.dispatchEvent(new Event('kraft:content-added'));
    };

    repeater.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button || !repeater.contains(button)) {
            return;
        }
        if (button === add && rows.children.length < Number(repeater.dataset.max)) {
            const content = template.content.cloneNode(true);
            content.querySelectorAll('[name], [id], [for], [aria-describedby]').forEach((field) => {
                for (const attribute of ['name', 'id', 'for', 'aria-describedby']) {
                    if (field.hasAttribute(attribute)) {
                        field.setAttribute(
                            attribute,
                            field.getAttribute(attribute).replaceAll('__ROW__', String(nextIndex)),
                        );
                    }
                }
            });
            nextIndex += 1;
            rows.append(content);
            document.dispatchEvent(new Event('kraft:content-added'));
        }
        const row = button.closest('[data-theme-row]');
        if (row && button.hasAttribute('data-theme-remove')) {
            row.querySelectorAll('textarea[data-editor]').forEach((field) => window.tinymce?.get(field.id)?.remove());
            row.remove();
        }
        if (row && button.dataset.themeMove === 'up' && row.previousElementSibling) {
            move(row, row.previousElementSibling);
        }
        if (row && button.dataset.themeMove === 'down' && row.nextElementSibling) {
            move(row, row.nextElementSibling.nextElementSibling);
        }
        update();
    });

    update();
});
