const initialized = new WeakSet();

export function initRoleAppearance(root = document) {
    root.querySelectorAll('[data-role-appearance]').forEach((container) => {
        if (initialized.has(container)) {
            return;
        }
        initialized.add(container);
        const ownerDocument = container.ownerDocument ?? root;
        const background = container.querySelector('[name="color"]');
        const text = container.querySelector('[name="text_color"]');
        const automatic = container.querySelector('[type="checkbox"][name="automatic_text_color"]');
        const name = container.querySelector('[name="name"]');
        const icon = container.querySelector('[name="icon"]');
        const preview = container.querySelector('[data-role-badge-preview]');
        const badge = preview.querySelector('.badge');
        const warning = container.querySelector('[data-role-contrast-warning]');
        const luminance = (color) => {
            const channels = [1, 3, 5].map((offset) => {
                const value = parseInt(color.slice(offset, offset + 2), 16) / 255;
                return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
            });
            return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
        };
        const update = () => {
            const backgroundLuminance = luminance(background.value);
            const foreground = automatic.checked ? (backgroundLuminance > 0.179 ? '#000000' : '#ffffff') : text.value;
            text.disabled = automatic.checked;
            badge.style.backgroundColor = background.value;
            badge.style.color = foreground;
            badge.replaceChildren();
            if (/^[a-z][a-z0-9-]*$/.test(icon.value)) {
                const symbol = ownerDocument.createElement('i');
                symbol.className = `bi bi-${icon.value} me-1`;
                symbol.setAttribute('aria-hidden', 'true');
                badge.append(symbol);
            }
            badge.append(ownerDocument.createTextNode(name.value.trim() || preview.dataset.placeholder));
            const textLuminance = luminance(foreground);
            const contrast =
                (Math.max(backgroundLuminance, textLuminance) + 0.05) /
                (Math.min(backgroundLuminance, textLuminance) + 0.05);
            warning.hidden = automatic.checked || contrast >= 4.5;
        };
        [background, text, automatic, name, icon].forEach((field) => {
            field.addEventListener('input', update);
            field.addEventListener('change', update);
        });
        container.closest('form')?.addEventListener('reset', () => setTimeout(update, 0));
        update();
    });
}
