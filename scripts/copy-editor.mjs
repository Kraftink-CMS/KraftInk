import { cpSync, mkdirSync, readdirSync, rmSync } from 'node:fs';
import { resolve, dirname } from 'node:path';

const target = resolve('public/vendor/tinymce');
if (dirname(target) !== resolve('public/vendor')) {
    throw new Error('Invalid editor output path.');
}
rmSync(target, { recursive: true, force: true });
mkdirSync(target, { recursive: true });
for (const file of ['tinymce.min.js', 'license.md']) {
    cpSync('node_modules/tinymce/' + file, target + '/' + file);
}
for (const directory of ['icons', 'models', 'themes', 'skins']) {
    cpSync('node_modules/tinymce/' + directory, target + '/' + directory, { recursive: true });
}
for (const plugin of ['autolink', 'lists', 'link', 'image', 'table', 'code', 'wordcount', 'fullscreen']) {
    mkdirSync(target + '/plugins/' + plugin, { recursive: true });
    cpSync(
        'node_modules/tinymce/plugins/' + plugin + '/plugin.min.js',
        target + '/plugins/' + plugin + '/plugin.min.js',
    );
}
mkdirSync(target + '/langs', { recursive: true });
for (const file of readdirSync('resources/vendor/tinymce')) {
    if (/^[a-z]{2}(?:-[A-Z]{2})?\.js$/.test(file)) {
        cpSync('resources/vendor/tinymce/' + file, target + '/langs/' + file);
    }
}
cpSync('resources/vendor/tinymce/language-packs.LICENSE.md', target + '/langs/LICENSE.md');
