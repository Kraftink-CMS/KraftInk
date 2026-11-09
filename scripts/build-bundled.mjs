import { lstat, mkdir, mkdtemp, readFile, readdir, realpath, rename, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = await realpath(fileURLToPath(new URL('../', import.meta.url)));
const source = join(root, 'extensions/source');
const output = join(root, 'extensions/bundled');
const work = join(root, '.work');
const packageEntries =
    /^(?:src|routes|config|database|lang|resources|public|manifest\.json|composer\.(?:json|lock)|scoper\.inc\.php|(?:LICENSE|NOTICE|README)(?:[.-][A-Za-z0-9_.-]+)?|_[A-Za-z0-9_.-]+\.scss)$/;

async function filesAt(directory, prefix = '') {
    const files = [];
    for (const entry of await readdir(directory, { withFileTypes: true })) {
        if (entry.name.startsWith('.') || (prefix === '' && !packageEntries.test(entry.name))) {
            continue;
        }
        const path = join(directory, entry.name);
        const relative = prefix + entry.name;
        if (entry.isDirectory()) {
            files.push(...(await filesAt(path, relative + '/')));
        } else if (entry.isFile()) {
            if (relative.startsWith('config/') && !relative.endsWith('.php')) {
                continue;
            }
            files.push([relative, await readFile(path)]);
        } else {
            throw new Error(`Invalid bundled package source: ${path}`);
        }
    }
    return files;
}

const inventory = JSON.parse(await readFile(join(root, 'manifest.json'), 'utf8')).bundled;
const automation = resolve(process.env.KRAFT_AUTOMATION_ROOT || join(root, '../automation'));
const { buildComposer } = await import(pathToFileURL(join(automation, 'scripts/releases/composer.mjs')));

await mkdir(work, { recursive: true });
if ((await realpath(work)) !== work) {
    throw new Error('The bundled build directory must stay inside the CMS workspace.');
}

for (const [type, ids] of Object.entries(inventory)) {
    if (!['plugin', 'theme', 'game'].includes(type)) {
        throw new Error(`Invalid bundled package type: ${type}`);
    }
    const group = `${type}s`;
    const candidates = await readdir(join(source, group), { withFileTypes: true }).catch((error) => {
        if (error.code !== 'ENOENT') {
            throw error;
        }
        return [];
    });
    for (const entry of candidates) {
        const id = entry.name;
        if (!entry.isDirectory() || !/^[a-z][a-z0-9-]{1,49}$/.test(id) || !ids.includes(id)) {
            throw new Error(`Undeclared bundled package source: ${group}/${id}`);
        }
        const input = join(source, group, id);
        const parent = join(output, group);
        const target = join(parent, id);
        if ((await realpath(input)) !== input || (await realpath(parent)) !== parent) {
            throw new Error(`Invalid bundled package path: ${group}/${id}`);
        }
        const files = await filesAt(input);
        const manifest = JSON.parse(new Map(files).get('manifest.json'));
        if (manifest.id !== id || manifest.type !== type || manifest.builtin !== true) {
            throw new Error(`Invalid bundled package manifest: ${group}/${id}`);
        }
        const prepared = await buildComposer(
            files.filter(([path]) => path !== 'scoper.inc.php'),
            files,
        );
        const generated = JSON.parse(new Map(prepared).get('manifest.json'));
        if (!generated.libraries) {
            throw new Error(`Bundled package sources must declare private libraries: ${group}/${id}`);
        }
        const exists = await lstat(target).catch((error) => {
            if (error.code !== 'ENOENT') {
                throw error;
            }
            return null;
        });
        if (exists && (!exists.isDirectory() || !JSON.parse(await readFile(join(target, 'manifest.json'))).libraries)) {
            throw new Error(`Cannot overwrite an unbuilt bundled package: ${group}/${id}`);
        }
        const stage = await mkdtemp(join(work, 'bundled-'));
        const next = join(stage, 'next');
        const previous = join(stage, 'previous');
        let preserveStage = false;
        try {
            for (const [path, contents] of prepared) {
                const file = join(next, path);
                await mkdir(dirname(file), { recursive: true });
                await writeFile(file, contents);
            }
            if (exists) {
                await rename(target, previous);
            }
            try {
                await rename(next, target);
            } catch (error) {
                if (exists) {
                    preserveStage = true;
                    await rename(previous, target);
                    preserveStage = false;
                }
                throw error;
            }
        } finally {
            if (!preserveStage) {
                await rm(stage, { recursive: true, force: true });
            }
        }
    }
}
