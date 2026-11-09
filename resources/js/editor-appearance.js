const presets = {
    light: { skin: 'oxide', content: 'default', color: '#24302d' },
    dark: { skin: 'oxide-dark', content: 'dark', color: '#fff' },
};

export function createEditorAppearance(baseUrl) {
    const config = JSON.parse(document.getElementById('kraft-editor-appearance')?.textContent || '{}');
    const root = document.documentElement;
    const forcedMode = config.appearance === 'light' || config.appearance === 'dark' ? config.appearance : null;
    const currentMode = () => forcedMode ?? (root.dataset.bsTheme === 'dark' ? 'dark' : 'light');
    const modes = forcedMode === null ? Object.keys(presets) : [forcedMode];
    const sheets = new Map();
    const frames = new Set();

    function media(mode) {
        return mode === currentMode() ? 'all' : 'not all';
    }

    function addSheet(target, mode, { href, css }, wait = false) {
        const sheet = target.createElement(href ? 'link' : 'style');
        let ready;
        if (href) {
            sheet.rel = 'stylesheet';
            sheet.href = href;
            if (wait && mode === currentMode()) {
                ready = new Promise((resolve) => {
                    sheet.addEventListener('load', resolve, { once: true });
                    sheet.addEventListener('error', resolve, { once: true });
                });
            }
        } else {
            sheet.textContent = css;
        }
        sheet.media = media(mode);
        sheets.set(sheet, mode);
        target.head.append(sheet);

        return { sheet, ready };
    }

    if (forcedMode === null) {
        const observer = new MutationObserver(() => {
            for (const [sheet, mode] of sheets) {
                sheet.media = media(mode);
            }
            for (const frame of frames) {
                frame.documentElement.style.colorScheme = currentMode();
            }
        });
        observer.observe(root, { attributes: true, attributeFilter: ['data-bs-theme'] });
    }

    const loading = modes.flatMap((mode) => {
        const builtIn = baseUrl + '/skins/ui/' + presets[mode].skin + '/skin.min.css';
        const custom = config[mode]?.ui;
        const urls = custom ? [builtIn, custom] : [builtIn];

        return urls.map((href) => addSheet(document, mode, { href }, true).ready);
    });

    return {
        ready: Promise.all(loading),
        apply(instance) {
            instance.on('PreInit', () => {
                const frame = instance.getDoc();
                frame.documentElement.style.colorScheme = currentMode();
                frames.add(frame);
                const contentSheets = modes.flatMap((mode) => {
                    const preset = presets[mode];
                    const rules = [
                        { href: baseUrl + '/skins/ui/' + preset.skin + '/content.min.css' },
                        { href: baseUrl + '/skins/content/' + preset.content + '/content.min.css' },
                        {
                            css:
                                'body{font-family:system-ui,sans-serif;line-height:1.7;padding:12px;color:' +
                                preset.color +
                                '}img{max-width:100%;height:auto}',
                        },
                    ];
                    if (config[mode]?.content) {
                        rules.push({ href: config[mode].content });
                    }

                    return rules.map((rule) => addSheet(frame, mode, rule).sheet);
                });
                instance.on('remove', () => {
                    for (const sheet of contentSheets) {
                        sheets.delete(sheet);
                    }
                    frames.delete(frame);
                });
            });
        },
    };
}
