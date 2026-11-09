import { readdirSync, readFileSync, mkdirSync, writeFileSync, existsSync, realpathSync } from 'node:fs';
import { resolve, dirname, basename, extname, relative, isAbsolute, sep } from 'node:path';
import { compile } from 'sass';

const roots = ['bundled', 'installed'].flatMap((location) =>
    ['games', 'plugins', 'themes'].map((type) => resolve('extensions', location, type)),
);

for (const root of roots) {
    if (!existsSync(root)) {
        continue;
    }
    for (const entry of readdirSync(root, { withFileTypes: true })) {
        if (!entry.isDirectory()) {
            continue;
        }
        const extensionRoot = resolve(root, entry.name);
        const manifestPath = resolve(extensionRoot, 'manifest.json');
        if (!existsSync(manifestPath)) {
            continue;
        }
        const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
        for (const input of manifest.build?.scss ?? []) {
            const source = resolve(extensionRoot, input);
            const sourceRelative = relative(realpathSync(extensionRoot), realpathSync(source));
            if (
                isAbsolute(sourceRelative) ||
                sourceRelative === '..' ||
                sourceRelative.startsWith('..' + sep) ||
                extname(source) !== '.scss'
            ) {
                throw new Error('Invalid extension SCSS path.');
            }
            const filename = basename(source, '.scss').replace(/^_/, '') + '.css';
            if (!(manifest.assets?.styles ?? []).includes(filename)) {
                throw new Error('Built stylesheet is not declared in assets.');
            }
            const target = resolve(extensionRoot, 'public', filename);
            mkdirSync(dirname(target), { recursive: true });
            writeFileSync(target, compile(source, { style: 'compressed', charset: false }).css);
        }
    }
}
