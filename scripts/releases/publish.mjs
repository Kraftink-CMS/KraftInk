import { createHash, createPublicKey, verify } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { createReadStream } from 'node:fs';
import { appendFile, lstat, mkdir, readFile, realpath, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { compareVersions, parseReleaseVersion, parseStableVersion, planVersion } from './version.mjs';
import { assertNoOpenStablePromotion } from './stable-promotion.mjs';
import { githubClientTools, sourceVersionTools } from './source-version.mjs';

const metadataLimit = 262144;
const archiveLimit = 536870912;
const hash = (bytes) => createHash('sha256').update(bytes).digest('hex');
const validSha = (value) => typeof value === 'string' && /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(value);
const validDigest = (value) => typeof value === 'string' && /^sha256:[a-f0-9]{64}$/.test(value);

export async function publicationNotes({
    root = process.cwd(),
    repository,
    plan,
    mode = 'auto',
    message = '',
    environment = process.env,
}) {
    const tools = await sourceVersionTools(environment);
    const selection = tools.normalizeReleaseNotes({ mode, message });
    const sha = plan.source_sha ?? plan.sha;
    let previousTag = plan.base_tag ?? null;
    if (plan.mode !== 'stable') {
        const tags = execFileSync('git', ['-C', root, 'tag', '--merged', sha], {
            encoding: 'utf8',
            shell: false,
            windowsHide: true,
            timeout: 30000,
            env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
            stdio: ['ignore', 'pipe', 'pipe'],
        }).trim().split(/\r?\n/).filter(Boolean);
        previousTag = tags.filter((tag) => {
            try {
                return tag.startsWith('v') && parseReleaseVersion(tag.slice(1)).channel === plan.mode
                    && compareVersions(tag.slice(1), plan.version) < 0;
            } catch {
                return false;
            }
        }).sort((a, b) => compareVersions(b.slice(1), a.slice(1)))[0] ?? previousTag;
    }
    const body = await tools.buildReleaseNotes({ root, repository, sha, previousTag, ...selection });
    return { schema: 1, source_sha: sha, previous_tag: previousTag, ...selection, body };
}

export function releaseAssetNames(version) {
    parseReleaseVersion(version);
    return [
        `KraftInk-${version}.zip`,
        `KraftInk-${version}.zip.sha256`,
        `kraftink-update-${version}.zip`,
        'kraftink-release.json',
        'kraftink-release.sig',
    ];
}

function assertPlan(plan, preview = false) {
    const preparing =
        preview &&
        plan?.mode === 'stable' &&
        plan.branch === 'dev' &&
        plan.master_sha === null &&
        plan.publish_allowed === false;
    if (
        !plan ||
        plan.schema !== 1 ||
        !['stable', 'alpha', 'beta'].includes(plan.mode) ||
        (!preparing && plan.branch !== (plan.mode === 'stable' ? 'master' : 'dev')) ||
        (plan.mode === 'stable' && !preparing && !validSha(plan.master_sha)) ||
        (!preparing && plan.publish_allowed !== true) ||
        typeof plan.create_tag !== 'boolean' ||
        !validSha(plan.sha)
    ) {
        throw new Error('A verified release plan on its publication branch is required.');
    }
    if (parseReleaseVersion(plan.version).channel !== plan.mode) {
        throw new Error('Release version does not match its channel.');
    }
    if (plan.tag !== `v${plan.version}`) {
        throw new Error('Release tag does not match its version.');
    }
}

function decodeBase64(value, size) {
    if (typeof value !== 'string') {
        throw new Error('An Ed25519 value is missing.');
    }
    const text = value.trim();
    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(text)) {
        throw new Error('Invalid Ed25519 base64 encoding.');
    }
    const bytes = Buffer.from(text, 'base64');
    if (bytes.length !== size || bytes.toString('base64') !== text) {
        throw new Error('Invalid Ed25519 value length.');
    }
    return bytes;
}

function signedMetadata(raw, signature, publicKey, version) {
    const key = createPublicKey({
        key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), decodeBase64(publicKey, 32)]),
        format: 'der',
        type: 'spki',
    });
    if (!verify(null, raw, key, decodeBase64(signature.toString('utf8'), 64))) {
        throw new Error('Release metadata signature rejected.');
    }
    let metadata;
    try {
        metadata = JSON.parse(raw.toString('utf8'));
    } catch {
        throw new Error('Release metadata contains invalid JSON.');
    }
    if (
        !metadata ||
        metadata.schema_version !== 1 ||
        metadata.version !== version ||
        metadata.channel !== parseReleaseVersion(version).channel ||
        !metadata.requirements ||
        typeof metadata.requirements.php !== 'string' ||
        typeof metadata.requirements.cms !== 'string' ||
        !metadata.requirements.php ||
        !metadata.requirements.cms
    ) {
        throw new Error('Release metadata does not match the plan.');
    }
    if (metadata.build !== undefined) {
        const build = metadata.build;
        if (
            !build ||
            typeof build.version !== 'string' ||
            !validSha(build.sha) ||
            parseReleaseVersion(build.version).channel !== 'alpha' ||
            (parseReleaseVersion(version).channel === 'stable'
                ? compareVersions(parseReleaseVersion(build.version).target, version) > 0
                : parseReleaseVersion(build.version).target !== parseReleaseVersion(version).target)
        ) {
            throw new Error('Invalid original alpha build provenance.');
        }
    }
    const archive = metadata.archive;
    if (
        !archive ||
        archive.name !== `kraftink-update-${version}.zip` ||
        !Number.isSafeInteger(archive.size) ||
        archive.size < 1 ||
        archive.size > archiveLimit ||
        typeof archive.sha256 !== 'string' ||
        !/^[a-f0-9]{64}$/.test(archive.sha256)
    ) {
        throw new Error('Invalid signed update archive metadata.');
    }
    return metadata;
}

function distributionChecksum(bytes, version) {
    const name = `KraftInk-${version}.zip`;
    const text = bytes.toString('utf8');
    const digest = text.slice(0, 64);
    if (!/^[a-f0-9]{64}$/.test(digest) || text !== `${digest}  ${name}\n`) {
        throw new Error('Distribution checksum does not match its expected filename.');
    }
    return digest;
}

function verifyBuildSource(metadata, plan) {
    if (!metadata.build) return;
    const expected = plan.source_sha ?? (plan.mode === 'stable' ? metadata.source?.sha : plan.sha);
    if (
        metadata.build.sha !== expected ||
        (plan.mode === 'alpha' && metadata.build.version !== plan.version) ||
        (plan.mode === 'stable' &&
            (!metadata.source ||
                !validSha(metadata.source.sha) ||
                metadata.source.sha !== expected ||
                typeof metadata.source.tag !== 'string' ||
                !metadata.source.tag.startsWith('v') ||
                !['alpha', 'beta'].includes(parseReleaseVersion(metadata.source.tag.slice(1)).channel) ||
                (plan.source_tag && metadata.source.tag !== plan.source_tag)))
    )
        throw new Error('Release build provenance does not match its immutable source commit.');
}

async function fileBytes(path, limit) {
    const stat = await lstat(path);
    if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > limit) {
        throw new Error('Release assets must be bounded regular files.');
    }
    const bytes = await readFile(path);
    if (bytes.length !== stat.size || bytes.length > limit) {
        throw new Error('Release asset changed while reading.');
    }
    return bytes;
}

async function fileDigest(path) {
    const digest = createHash('sha256');
    for await (const chunk of createReadStream(path)) {
        digest.update(chunk);
    }
    return digest.digest('hex');
}

export async function localBundle(plan, directory, publicKey, { preview = false } = {}) {
    assertPlan(plan, preview);
    const root = await realpath(directory);
    const names = releaseAssetNames(plan.version);
    const raw = await fileBytes(resolve(root, names[3]), metadataLimit);
    const signature = await fileBytes(resolve(root, names[4]), 1024);
    const checksum = await fileBytes(resolve(root, names[1]), 4096);
    const metadata = signedMetadata(raw, signature, publicKey, plan.version);
    verifyBuildSource(metadata, plan);
    const expectedDistribution = distributionChecksum(checksum, plan.version);
    const assets = new Map();
    for (const name of names) {
        const path = resolve(root, name);
        const stat = await lstat(path);
        if (!stat.isFile() || stat.isSymbolicLink() || stat.size < 1 || stat.size > archiveLimit) {
            throw new Error('Release assets must be bounded regular files.');
        }
        assets.set(name, { name, path, size: stat.size, sha256: await fileDigest(path) });
    }
    if (
        assets.get(names[0]).sha256 !== expectedDistribution ||
        assets.get(names[2]).sha256 !== metadata.archive.sha256 ||
        assets.get(names[2]).size !== metadata.archive.size ||
        assets.get(names[3]).sha256 !== hash(raw) ||
        assets.get(names[4]).sha256 !== hash(signature) ||
        assets.get(names[1]).sha256 !== hash(checksum)
    ) {
        throw new Error('Release files do not match their signed metadata and checksums.');
    }
    return assets;
}

export function createGitHubClient(options) {
    return githubClientTools().createGitHubClient(options);
}

export async function githubTransport(options) {
    return githubClientTools().githubTransport(options);
}

function assertAsset(asset) {
    if (
        !asset ||
        !Number.isSafeInteger(asset.id) ||
        asset.id < 1 ||
        !Number.isSafeInteger(asset.size) ||
        asset.size < 1 ||
        asset.size > archiveLimit ||
        asset.state !== 'uploaded' ||
        !validDigest(asset.digest)
    ) {
        throw new Error('GitHub asset has no verifiable SHA-256 digest or is incomplete.');
    }
}

function expectedAssets(assets, names, required = true) {
    const mapped = new Map();
    for (const name of names) {
        const found = assets.filter((asset) => asset.name === name);
        if (found.length > 1 || (required && found.length !== 1)) {
            throw new Error('The published release asset set is incomplete or ambiguous.');
        }
        if (found.length === 1) {
            mapped.set(name, found[0]);
        }
    }
    return mapped;
}

async function verifyRemote(plan, release, assets, client, publicKey) {
    if (
        release.tag_name !== plan.tag ||
        release.prerelease !== (plan.mode !== 'stable') ||
        (plan.mode === 'stable' && release.target_commitish !== plan.sha) ||
        typeof release.draft !== 'boolean'
    ) {
        throw new Error('GitHub release identity does not match the release plan.');
    }
    const names = releaseAssetNames(plan.version);
    const mapped = expectedAssets(assets, names);
    for (const asset of mapped.values()) {
        assertAsset(asset);
    }
    const raw = await client.bytes(mapped.get(names[3]), metadataLimit);
    const signature = await client.bytes(mapped.get(names[4]), 1024);
    const checksum = await client.bytes(mapped.get(names[1]), 4096);
    const metadata = signedMetadata(raw, signature, publicKey, plan.version);
    verifyBuildSource(metadata, plan);
    if (
        mapped.get(names[0]).digest !== `sha256:${distributionChecksum(checksum, plan.version)}` ||
        mapped.get(names[2]).digest !== `sha256:${metadata.archive.sha256}` ||
        mapped.get(names[2]).size !== metadata.archive.size
    ) {
        throw new Error('GitHub archives do not match their metadata and checksums.');
    }
    return mapped;
}

export async function readPublishedRelease({ tag, client, publicKey, directory = null, requireBuild = false }) {
    if (typeof tag !== 'string' || !tag.startsWith('v')) {
        throw new Error('Select an immutable release tag.');
    }
    const version = tag.slice(1);
    const mode = parseReleaseVersion(version).channel;
    const sha = await client.tag(tag);
    const release = await client.release(tag);
    if (!sha || !release || release.draft !== false) {
        throw new Error('The source release must be completely published.');
    }
    const assets = await verifyRemote(
        { tag, version, mode, sha },
        release,
        await client.assets(release),
        client,
        publicKey,
    );
    let build = null;
    if (requireBuild) {
        const metadata = signedMetadata(
            await client.bytes(assets.get('kraftink-release.json'), metadataLimit),
            await client.bytes(assets.get('kraftink-release.sig'), 1024),
            publicKey,
            version,
        );
        if (!metadata.build) {
            throw new Error('The source release has no signed original alpha build provenance.');
        }
        build = metadata.build;
    }
    if (directory !== null) {
        await mkdir(directory, { recursive: true, mode: 0o700 });
        for (const [name, asset] of assets) {
            const bytes = await client.bytes(asset, name.endsWith('.zip') ? archiveLimit : metadataLimit);
            await writeFile(resolve(directory, name), bytes, { flag: 'wx', mode: 0o600 });
        }
    }
    return { tag, version, mode, sha, release, assets, build };
}

async function requireDraft(client, release, plan, body) {
    const current = await client.request('GET', `/releases/${release.id}`);
    if (
        !current ||
        current.id !== release.id ||
        current.tag_name !== release.tag_name ||
        current.draft !== true ||
        current.prerelease !== (plan.mode !== 'stable') ||
        current.body !== body
    ) {
        throw new Error('The release is no longer a mutable draft matching its channel and original notes.');
    }
}

async function requirePublishedParent(plan, client) {
    const parents = new Set(
        [plan.base_tag, plan.previous_tag, plan.source_tag].filter((tag) => tag !== undefined && tag !== null),
    );
    for (const tag of parents) {
        if (typeof tag !== 'string' || !tag.startsWith('v')) {
            throw new Error('Invalid parent release tag.');
        }
        const version = tag.slice(1);
        const parentChannel = parseReleaseVersion(version).channel;
        if (compareVersions(version, plan.version) >= 0) {
            throw new Error('The parent release must precede the planned version.');
        }
        const sha = await client.tag(tag);
        const parent = await client.release(tag);
        if (
            !sha ||
            !parent ||
            parent.tag_name !== tag ||
            parent.draft !== false ||
            parent.prerelease !== (parentChannel !== 'stable')
        ) {
            throw new Error('The parent release is incomplete. Resume it before publishing another version.');
        }
    }
}

async function canBecomeLatest(plan, client) {
    if (plan.mode !== 'stable') {
        return false;
    }
    const latest = await client.request('GET', '/releases/latest');
    if (!latest) {
        return true;
    }
    if (
        latest.draft !== false ||
        latest.prerelease !== false ||
        typeof latest.tag_name !== 'string' ||
        !latest.tag_name.startsWith('v')
    ) {
        throw new Error('GitHub latest is not a valid stable release.');
    }
    const version = latest.tag_name.slice(1);
    parseStableVersion(version);
    return compareVersions(plan.version, version) >= 0;
}

export async function inspectRelease({ plan, client, publicKey }) {
    assertPlan(plan);
    decodeBase64(publicKey, 32);
    const sha = await client.tag(plan.tag);
    if (sha && sha !== plan.sha) {
        throw new Error('GitHub release tag points to another commit.');
    }
    const release = await client.release(plan.tag);
    if (!release) {
        await requirePublishedParent(plan, client);
        return {
            complete: false,
            tag_exists: sha !== null,
            draft: false,
            version: plan.version,
            tag: plan.tag,
            sha: plan.sha,
        };
    }
    if (release.tag_name !== plan.tag || !sha || release.prerelease !== (plan.mode !== 'stable')) {
        throw new Error('GitHub release has no matching commit tag.');
    }
    if (release.draft === true) {
        await requirePublishedParent(plan, client);
        return { complete: false, tag_exists: true, draft: true, version: plan.version, tag: plan.tag, sha: plan.sha };
    }
    await verifyRemote(plan, release, await client.assets(release), client, publicKey);
    return { complete: true, tag_exists: true, draft: false, version: plan.version, tag: plan.tag, sha: plan.sha };
}

async function requireStableMaster(plan, client) {
    if (plan.mode === 'stable') {
        const master = await client.request('GET', '/git/ref/heads/master');
        if (
            master?.ref !== 'refs/heads/master' ||
            master.object?.type !== 'commit' ||
            master.object.sha !== plan.master_sha
        ) {
            throw new Error('The master branch moved after the stable publication plan was verified.');
        }
    }
}

export async function publishRelease({ plan, directory, client, publicKey, environment = process.env, body = `Source commit: ${plan?.sha}` }) {
    if (typeof body !== 'string' || Buffer.byteLength(body) > 65536)
        throw new Error('Release notes must be bounded Markdown text.');
    const local = await localBundle(plan, directory, publicKey);
    await requireStableMaster(plan, client);
    let sha = await client.tag(plan.tag);
    if (sha && sha !== plan.sha) {
        throw new Error('GitHub release tag points to another commit.');
    }
    let release = await client.release(plan.tag);
    if (release && (release.tag_name !== plan.tag || !sha || release.prerelease !== (plan.mode !== 'stable'))) {
        throw new Error('GitHub release has no matching commit tag.');
    }
    if (release && release.draft !== true) {
        const remote = await verifyRemote(plan, release, await client.assets(release), client, publicKey);
        for (const [name, asset] of local) {
            if (remote.get(name).digest !== `sha256:${asset.sha256}` || remote.get(name).size !== asset.size) {
                throw new Error('Published assets differ from the local release. Published assets cannot be replaced.');
            }
        }
        return { complete: true, published: false, version: plan.version, tag: plan.tag, sha: plan.sha };
    }
    if (release && release.body !== body)
        throw new Error('The draft release notes differ from this original publication. Resume its original workflow run.');
    await requirePublishedParent(plan, client);
    if (plan.mode !== 'stable') await assertNoOpenStablePromotion({ client, environment });
    if (!sha) {
        if (!plan.create_tag) {
            throw new Error('The existing planned tag is missing remotely and cannot be recreated.');
        }
        await client.request('POST', '/git/refs', { ref: `refs/tags/${plan.tag}`, sha: plan.sha }, 201);
        sha = await client.tag(plan.tag);
        if (sha !== plan.sha) {
            throw new Error('The created GitHub tag does not match the release commit.');
        }
    }
    if (!release) {
        release = await client.request(
            'POST',
            '/releases',
            {
                tag_name: plan.tag,
                target_commitish: plan.sha,
                name: `KraftInk ${plan.version}`,
                body,
                draft: true,
                prerelease: plan.mode !== 'stable',
                make_latest: 'false',
                generate_release_notes: false,
            },
            201,
        );
    }
    if (release.tag_name !== plan.tag || release.draft !== true || release.prerelease !== (plan.mode !== 'stable') || release.body !== body) {
        throw new Error('A matching draft release is required before uploads.');
    }
    let remote = expectedAssets(await client.assets(release), [...local.keys()], false);
    for (const [name, asset] of local) {
        const existing = remote.get(name);
        if (
            existing &&
            existing.state === 'uploaded' &&
            existing.digest === `sha256:${asset.sha256}` &&
            existing.size === asset.size
        ) {
            continue;
        }
        await requireDraft(client, release, plan, body);
        if (existing) {
            if (!Number.isSafeInteger(existing.id) || existing.id < 1) {
                throw new Error('Invalid draft asset identifier.');
            }
            await client.request('DELETE', `/releases/assets/${existing.id}`, undefined, 204);
        }
        await client.upload(release, asset);
    }
    remote = await verifyRemote(plan, release, await client.assets(release), client, publicKey);
    for (const [name, asset] of local) {
        if (remote.get(name).digest !== `sha256:${asset.sha256}` || remote.get(name).size !== asset.size) {
            throw new Error('Draft assets changed before publication.');
        }
    }
    if ((await client.tag(plan.tag)) !== plan.sha) {
        throw new Error('GitHub tag changed before publication.');
    }
    await requirePublishedParent(plan, client);
    await requireDraft(client, release, plan, body);
    const makeLatest = await canBecomeLatest(plan, client);
    await requireStableMaster(plan, client);
    const published = await client.request('PATCH', `/releases/${release.id}`, {
        draft: false,
        prerelease: plan.mode !== 'stable',
        make_latest: makeLatest ? 'true' : 'false',
    });
    if (
        published.id !== release.id ||
        published.draft !== false ||
        published.prerelease !== (plan.mode !== 'stable') ||
        published.tag_name !== plan.tag
    ) {
        throw new Error('GitHub did not confirm release publication.');
    }
    return { complete: true, published: true, version: plan.version, tag: plan.tag, sha: plan.sha };
}

export function parsePublishArguments(args) {
    const command = args[0];
    if (!['inspect', 'publish'].includes(command)) {
        throw new Error('Use inspect --plan=path [--github-output] or publish --plan=path --directory=dist.');
    }
    const options = { command, githubOutput: false };
    const seen = new Set();
    for (let index = 1; index < args.length; index++) {
        const token = args[index];
        const equal = token.indexOf('=');
        const name = equal === -1 ? token : token.slice(0, equal);
        if (seen.has(name)) {
            throw new Error('Duplicate publisher option.');
        }
        seen.add(name);
        if (name === '--github-output' && equal === -1) {
            options.githubOutput = true;
            continue;
        }
        const property = { '--plan': 'planPath', '--directory': 'directory' }[name];
        const value = equal === -1 ? args[++index] : token.slice(equal + 1);
        if (!property || !value || value.startsWith('--')) {
            throw new Error('Unknown or incomplete publisher option.');
        }
        options[property] = value;
    }
    if (
        !options.planPath ||
        (command === 'publish' && !options.directory) ||
        (command === 'inspect' && options.directory)
    ) {
        throw new Error('Publisher command arguments are incomplete.');
    }
    return options;
}

export async function validatePublicationPlan(plan, root = process.cwd(), environment = process.env) {
    assertPlan(plan);
    const current = await planVersion({
        root,
        mode: plan.mode,
        version: plan.version,
        branch: plan.branch,
        sourceTag: plan.source_tag ?? null,
        bump: plan.requested_bump ?? 'auto',
    });
    if (
        current.sha !== plan.sha ||
        current.version !== plan.version ||
        current.tag !== plan.tag ||
        current.create_tag !== plan.create_tag ||
        current.base_tag !== plan.base_tag ||
        current.base_version !== plan.base_version ||
        (current.previous_tag ?? null) !== (plan.previous_tag ?? null) ||
        (current.source_tag ?? null) !== (plan.source_tag ?? null) ||
        (current.source_sha ?? null) !== (plan.source_sha ?? null) ||
        (current.requested_bump ?? 'auto') !== (plan.requested_bump ?? 'auto') ||
        (current.master_sha ?? null) !== (plan.master_sha ?? null)
    ) {
        throw new Error('The release plan no longer matches this checkout.');
    }
    const allowedRefs = plan.mode === 'stable' ? ['refs/heads/dev', 'refs/heads/master'] : ['refs/heads/dev'];
    if (!allowedRefs.includes(environment.GITHUB_REF)) {
        throw new Error(`Publication requires the ${plan.branch} workflow ref.`);
    }
}

async function main() {
    if (process.argv.length === 3 && process.argv[2] === 'guard') {
        await assertNoOpenStablePromotion({
            client: createGitHubClient({
                repository: process.env.GITHUB_REPOSITORY,
                token: process.env.GITHUB_TOKEN,
            }),
        });
        return;
    }
    const options = parsePublishArguments(process.argv.slice(2));
    if (options.githubOutput && !process.env.GITHUB_OUTPUT) {
        throw new Error('GITHUB_OUTPUT is required.');
    }
    const plan = JSON.parse((await fileBytes(resolve(options.planPath), 65536)).toString('utf8'));
    await validatePublicationPlan(plan);
    const client = createGitHubClient({ repository: process.env.GITHUB_REPOSITORY, token: process.env.GITHUB_TOKEN });
    const settings = { plan, client, publicKey: process.env.KRAFTINK_CMS_SIGNING_PUBLIC_KEY };
    const result =
        options.command === 'inspect'
            ? await inspectRelease(settings)
            : await publishRelease({ ...settings, directory: resolve(options.directory), body: (await publicationNotes({
                plan, repository: process.env.GITHUB_REPOSITORY,
                mode: process.env.RELEASE_NOTES_MODE || 'auto', message: process.env.RELEASE_NOTES || '',
            })).body });
    if (options.githubOutput) {
        await appendFile(
            process.env.GITHUB_OUTPUT,
            Object.entries(result)
                .map(([key, value]) => `${key}=${value}`)
                .join('\n') + '\n',
            { mode: 0o600 },
        );
    }
    process.stdout.write(JSON.stringify(result) + '\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`Release operation failed: ${error.message}\n`);
        process.exitCode = 1;
    });
}
