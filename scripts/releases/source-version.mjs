import { execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);

function sourceVersionLocation(environment) {
    if (!environment.RELEASE_SOURCE_VERSION_MODULE) {
        throw new Error(
            'RELEASE_SOURCE_VERSION_MODULE must identify the checked-out automation source-version module.',
        );
    }
    return pathToFileURL(resolve(environment.RELEASE_SOURCE_VERSION_MODULE));
}

export function githubClientTools(environment = process.env) {
    return require(fileURLToPath(new URL('./github-client.mjs', sourceVersionLocation(environment))));
}

export async function sourceVersionTools(environment = process.env) {
    const location = sourceVersionLocation(environment);
    return {
        ...(await import(location.href)),
        ...(await import(new URL('./source-version-github.mjs', location).href)),
        ...(await import(new URL('./notes.mjs', location).href)),
    };
}

export async function sourceVersionTree(root, sha, version) {
    const { sourceVersionChanges } = await sourceVersionTools();
    const changes = await sourceVersionChanges({ root, sha, version });
    const directory = await mkdtemp(join(tmpdir(), 'kraftink-source-tree-'));
    const run = (args, input) =>
        execFileSync('git', ['-C', root, ...args], {
            encoding: 'utf8',
            shell: false,
            windowsHide: true,
            timeout: 30000,
            env: { ...process.env, GIT_INDEX_FILE: join(directory, 'index') },
            input,
            stdio: ['pipe', 'pipe', 'pipe'],
        }).trim();
    try {
        run(['read-tree', sha]);
        for (const file of changes.files) {
            const entry = run(['ls-tree', sha, '--', file.path]);
            const mode = entry.match(/^(100644|100755) blob [a-f0-9]+\t/)?.[1];
            if (!mode) throw new Error('A source version file must be a regular tracked file.');
            const blob = run(['hash-object', '-w', '--stdin'], file.after);
            run(['update-index', '--add', '--cacheinfo', mode, blob, file.path]);
        }
        return run(['write-tree']);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
}

export function sourceVersionClient(client, repository) {
    const prefix = `/repos/${repository}`;
    return {
        request: (method, path, ...args) => {
            if (!path.startsWith(`${prefix}/`))
                throw new Error('Source version access must remain in the release repository.');
            return client.request(method, path.slice(prefix.length), ...args);
        },
    };
}

export async function advanceDevelopmentVersion({ plan, client, target, environment = process.env }) {
    const tools = await sourceVersionTools(environment);
    return tools.advanceDevelopmentVersion({
        client: sourceVersionClient(client, environment.GITHUB_REPOSITORY),
        repository: environment.GITHUB_REPOSITORY,
        version: plan.version,
        releaseSha: plan.master_sha,
        sourceSha: plan.source_sha,
        target,
        validationContext: 'checks / Validate changes',
    });
}
