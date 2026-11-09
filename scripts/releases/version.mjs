import { appendFile, readFile, realpath } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { sourceVersionTools, sourceVersionTree } from './source-version.mjs';

export function parseStableVersion(value) {
    if (
        typeof value !== 'string' ||
        value.trim() !== value ||
        !/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(value)
    ) {
        throw new Error('Expected a stable version in X.Y.Z format.');
    }
    const parts = value.split('.').map(Number);
    if (parts.some((part) => !Number.isSafeInteger(part))) {
        throw new Error('Version components exceed the supported integer range.');
    }
    return parts;
}

export function compareVersions(left, right) {
    const a = parseReleaseVersion(left);
    const b = parseReleaseVersion(right);
    for (let index = 0; index < 3; index++) {
        if (a.core[index] !== b.core[index]) {
            return a.core[index] < b.core[index] ? -1 : 1;
        }
    }
    const ranks = { alpha: 0, beta: 1, stable: 2 };
    if (a.channel !== b.channel) {
        return ranks[a.channel] < ranks[b.channel] ? -1 : 1;
    }
    if (a.number !== b.number) {
        return a.number < b.number ? -1 : 1;
    }
    return 0;
}

export function parseReleaseVersion(value) {
    if (typeof value !== 'string' || value.trim() !== value) {
        throw new Error('Expected a stable X.Y.Z or prerelease X.Y.Z-alpha.N / X.Y.Z-beta.N version.');
    }
    const match = /^(.*?)(?:-(alpha|beta)\.([1-9]\d*))?$/.exec(value);
    if (!match) {
        throw new Error('Expected a stable X.Y.Z or prerelease X.Y.Z-alpha.N / X.Y.Z-beta.N version.');
    }
    const core = parseStableVersion(match[1]);
    const number = match[3] === undefined ? null : Number(match[3]);
    if (number !== null && !Number.isSafeInteger(number)) {
        throw new Error('Prerelease numbers exceed the supported integer range.');
    }
    return { core, number, channel: match[2] ?? 'stable', target: match[1] };
}

export function bumpVersion(version, bump) {
    const parts = parseStableVersion(version);
    const index = ['major', 'minor', 'patch'].indexOf(bump);
    if (index === -1) {
        throw new Error('Unsupported version bump.');
    }
    parts[index]++;
    for (let offset = index + 1; offset < parts.length; offset++) {
        parts[offset] = 0;
    }
    const next = parts.join('.');
    parseStableVersion(next);
    return next;
}

function git(root, args, optional = false) {
    try {
        return execFileSync('git', ['--no-pager', '-C', root, ...args], {
            encoding: 'utf8',
            windowsHide: true,
            shell: false,
            timeout: 20000,
            maxBuffer: 8 * 1024 * 1024,
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
        }).trimEnd();
    } catch (error) {
        if (optional && error.status === 1) {
            return null;
        }
        throw new Error(`Git failed while reading ${args[0]}.`);
    }
}

async function policyFor(root) {
    const contents = await readFile(resolve(root, '.github/release-policy.json'), 'utf8');
    if (Buffer.byteLength(contents) > 65536) {
        throw new Error('Release policy is too large.');
    }
    let policy;
    try {
        policy = JSON.parse(contents);
    } catch {
        throw new Error('Release policy must contain valid JSON.');
    }
    if (!policy || Array.isArray(policy) || policy.schema !== 1) {
        throw new Error('Unsupported release policy.');
    }
    parseStableVersion(policy.initial_version);
    return policy;
}

async function releaseTarget(root, tags, initialVersion, sha) {
    const latest =
        tags.filter((tag) => tag.channel === 'stable').sort((a, b) => compareVersions(b.version, a.version))[0] ?? null;
    const { inspectSourceVersion } = await sourceVersionTools();
    const { version: sourceVersion } = await inspectSourceVersion({ root, sha });
    if (typeof sourceVersion !== 'string' || !sourceVersion.endsWith('-dev')) {
        throw new Error('The CMS development manifest must contain its X.Y.Z-dev release target.');
    }
    const target = sourceVersion.slice(0, -4);
    parseStableVersion(target);
    const base = latest?.version ?? initialVersion;
    const permitted = latest
        ? ['patch', 'minor', 'major'].map((bump) => bumpVersion(base, bump))
        : [initialVersion, bumpVersion(base, 'minor'), bumpVersion(base, 'major')];
    if (!permitted.includes(target)) {
        throw new Error('The development manifest must target an adjacent patch, minor or major release.');
    }
    const reference = git(
        root,
        ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/release-channels^{commit}'],
        true,
    );
    if (reference === null) {
        return target;
    }
    const contents = git(root, ['show', `${reference}:channels.json`]);
    if (Buffer.byteLength(contents) > 65536) {
        throw new Error('The release channel state is too large.');
    }
    let state;
    try {
        state = JSON.parse(contents);
    } catch {
        throw new Error('The release channel state must contain valid JSON.');
    }
    if (
        !state ||
        Array.isArray(state) ||
        state.schema !== 1 ||
        !state.channels ||
        typeof state.channels !== 'object' ||
        Array.isArray(state.channels) ||
        typeof state.next_version !== 'string'
    ) {
        throw new Error('Prepare a release target using the GitHub workflow before publishing.');
    }
    parseStableVersion(state.next_version);
    if (compareVersions(target, state.next_version) < 0) {
        throw new Error(
            'The release catalogue contradicts manifest.version. Retry Prepare Version to reconcile the target.',
        );
    }

    return target;
}

function releaseTags(root) {
    const rows = git(root, [
        'for-each-ref',
        '--format=%(refname:short)%00%(objectname)%00%(objecttype)%00%(*objectname)%00%(*objecttype)',
        'refs/tags',
    ]);
    if (!rows) {
        return [];
    }
    const lines = rows.split('\n');
    if (lines.length > 10000) {
        throw new Error('The release tag inventory exceeds the supported limit.');
    }
    const tags = [];
    for (const line of lines) {
        const [tag, object, kind, peeled, peeledKind] = line.replace(/\r$/, '').split('\0');
        if (!/^v(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:alpha|beta)\.[1-9]\d*)?$/.test(tag)) {
            continue;
        }
        const version = tag.slice(1);
        const parsed = parseReleaseVersion(version);
        let sha = kind === 'commit' ? object : peeledKind === 'commit' ? peeled : null;
        if (!sha && kind === 'tag') {
            sha = git(root, ['rev-parse', '--verify', `refs/tags/${tag}^{commit}`]);
        }
        if (!sha || !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(sha)) {
            throw new Error(`Release tag ${tag} does not point to a commit.`);
        }
        tags.push({ tag, version, sha, ...parsed });
    }
    return tags;
}

function isBranchCommit(root, sha, branch) {
    for (const reference of [`refs/heads/${branch}`, `refs/remotes/origin/${branch}`]) {
        const head = git(root, ['rev-parse', '--verify', '--quiet', `${reference}^{commit}`], true);
        if (head && git(root, ['merge-base', '--is-ancestor', sha, head], true) !== null) {
            return true;
        }
    }
    return false;
}

export async function stableMasterCommit(root, sha, version) {
    const master =
        git(root, ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/master^{commit}'], true) ??
        git(root, ['rev-parse', '--verify', '--quiet', 'refs/heads/master^{commit}'], true);
    if (!master || git(root, ['merge-base', '--is-ancestor', sha, master], true) === null) {
        throw new Error('The tested release source must belong to master before stable publication.');
    }
    if (git(root, ['rev-parse', `${master}^{tree}`]) !== (await sourceVersionTree(root, sha, version))) {
        throw new Error('The master tree must match the tested source with only its stable manifest version.');
    }
    return master;
}

export async function planVersion({
    root = process.cwd(),
    mode,
    bump = 'auto',
    version = null,
    branch = null,
    sourceTag = null,
}) {
    if (!['stable', 'alpha', 'beta', 'dev'].includes(mode)) {
        throw new Error('Mode must be stable, alpha, beta or dev.');
    }
    if (!['auto', 'patch', 'minor', 'major'].includes(bump) || (mode !== 'stable' && bump !== 'auto')) {
        throw new Error('Prepare the release target using the GitHub workflow instead of requesting a release bump.');
    }
    if (branch !== null && branch !== 'dev' && !(mode === 'stable' && branch === 'master')) {
        throw new Error('The release branch must be dev, or master for stable publication.');
    }
    const prerelease = ['alpha', 'beta'].includes(mode);
    const requested = version === null ? null : prerelease ? parseReleaseVersion(version) : null;
    if (requested && !['stable', mode].includes(requested.channel)) {
        throw new Error('An exact prerelease version must match its publication channel.');
    }
    if (version !== null && !prerelease) {
        parseStableVersion(version);
    }
    if (mode === 'dev' && version !== null) {
        throw new Error('An exact stable version cannot be requested in dev mode.');
    }
    const promotion = ['beta', 'stable'].includes(mode);
    if (promotion) {
        const expected = mode === 'beta' ? ['alpha'] : ['alpha', 'beta'];
        if (
            typeof sourceTag !== 'string' ||
            !sourceTag.startsWith('v') ||
            !expected.includes(parseReleaseVersion(sourceTag.slice(1)).channel)
        ) {
            throw new Error(`A published ${expected.join(' or ')} source tag is required for ${mode} promotion.`);
        }
    } else if (sourceTag !== null) {
        throw new Error('Only beta and stable promotions accept a source tag.');
    }
    const repository = await realpath(root);
    const top = await realpath(git(repository, ['rev-parse', '--show-toplevel']));
    if (repository !== top) {
        throw new Error('Run the planner from the CMS repository root.');
    }
    if (git(repository, ['rev-parse', '--is-shallow-repository']) !== 'false') {
        throw new Error('A complete Git history is required.');
    }
    const policy = await policyFor(repository);
    if (mode !== 'dev' && git(repository, ['status', '--porcelain', '--untracked-files=normal']) !== '') {
        throw new Error('Publication requires a clean working tree.');
    }
    let sha = git(repository, ['rev-parse', '--verify', 'HEAD^{commit}']);
    const attached = git(repository, ['symbolic-ref', '--quiet', '--short', 'HEAD'], true);
    if (branch && attached && branch !== attached) {
        throw new Error('The declared branch does not match the checked-out branch.');
    }
    const selectedBranch = branch ?? attached;
    if (!['dev', ...(mode === 'stable' ? ['master'] : [])].includes(selectedBranch)) {
        throw new Error('Version planning requires a verified dev commit or a stable master source.');
    }
    let masterSha = null;
    let masterVersion = null;
    if (selectedBranch === 'dev' && !isBranchCommit(repository, sha, 'dev')) {
        throw new Error('Version planning requires a verified dev commit.');
    }
    const tags = releaseTags(repository);
    const ancestorNames = new Set(
        git(repository, ['for-each-ref', '--merged=HEAD', '--format=%(refname:short)', 'refs/tags']).split('\n'),
    );
    const ancestors = tags
        .filter((tag) => tag.channel === 'stable' && ancestorNames.has(tag.tag))
        .sort((a, b) => compareVersions(b.version, a.version));
    const base = ancestors[0] ?? null;
    const source = promotion ? tags.find((tag) => tag.tag === sourceTag) : null;
    if (promotion && (!source || (source.sha !== sha && !(mode === 'stable' && selectedBranch === 'master')))) {
        throw new Error('The source release tag must resolve to the checked-out commit.');
    }
    if (mode === 'dev') {
        const target = await releaseTarget(repository, tags, policy.initial_version, sha);
        return {
            schema: 1,
            mode,
            branch: selectedBranch,
            master_sha: masterSha,
            sha,
            version: `${target}-dev`,
            tag: null,
            base_version: base?.version ?? policy.initial_version,
            base_tag: base?.tag ?? null,
            bump: null,
            already_tagged: false,
            create_tag: false,
            publish_allowed: false,
            source_tag: null,
            source_sha: null,
            requested_bump: bump,
        };
    }
    if (mode === 'stable') {
        const master =
            git(repository, ['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/master^{commit}'], true) ??
            git(repository, ['rev-parse', '--verify', '--quiet', 'refs/heads/master^{commit}'], true);
        const published = tags.find((tag) => tag.channel === 'stable' && tag.sha === master);
        if (selectedBranch === 'master' || (published && (version === null || version === published.version))) {
            try {
                const { inspectSourceVersion } = await sourceVersionTools();
                const candidate = (await inspectSourceVersion({ root: repository, sha: master })).version;
                parseStableVersion(candidate);
                if (version !== null && version !== candidate)
                    throw new Error('Master does not contain the requested stable manifest version.');
                const verified = await stableMasterCommit(repository, source.sha, candidate);
                if (selectedBranch === 'master' && sha !== source.sha && sha !== verified) {
                    throw new Error(
                        'The stable checkout must identify its tested source or the verified master commit.',
                    );
                }
                masterVersion = candidate;
                masterSha = verified;
                sha = verified;
            } catch (error) {
                if (selectedBranch === 'master') throw error;
            }
        }
    }
    const atHead = tags.filter((tag) => tag.sha === sha && tag.channel === mode);
    const previousRelease = (version) =>
        tags
            .filter((tag) => ancestorNames.has(tag.tag) && compareVersions(tag.version, version) < 0)
            .sort((a, b) => compareVersions(b.version, a.version))[0] ?? null;
    const releasePlan = (selectedVersion, selectedBump, existing = null) => {
        const previousStable = ancestors.find((tag) => compareVersions(tag.version, selectedVersion) < 0) ?? null;
        return {
            schema: 1,
            mode,
            branch: selectedBranch,
            master_sha: masterSha,
            sha,
            version: selectedVersion,
            tag: `v${selectedVersion}`,
            base_version: previousStable?.version ?? policy.initial_version,
            base_tag: previousStable?.tag ?? null,
            previous_tag: previousRelease(selectedVersion)?.tag ?? null,
            bump: selectedBump,
            already_tagged: existing !== null,
            create_tag: existing === null,
            publish_allowed: mode !== 'stable' || selectedBranch === 'master',
            source_tag: source?.tag ?? null,
            source_sha: source?.sha ?? null,
            requested_bump: bump,
        };
    };
    const stableTags = tags
        .filter((tag) => tag.channel === 'stable')
        .sort((a, b) => compareVersions(b.version, a.version));
    let promotedAtHead = promotion ? atHead.filter((tag) => tag.target === source.target) : [];
    if (mode === 'stable' && version !== null) {
        promotedAtHead = atHead.filter(
            (tag) => tag.version === version && compareVersions(tag.target, source.target) >= 0,
        );
    } else if (mode === 'stable' && bump !== 'auto') {
        promotedAtHead = atHead
            .filter((tag) => {
                const previous = stableTags.find((candidate) => compareVersions(candidate.version, tag.version) < 0);

                return (
                    compareVersions(tag.target, source.target) >= 0 &&
                    bumpVersion(previous?.version ?? policy.initial_version, bump) === tag.version
                );
            })
            .sort((a, b) => compareVersions(a.version, b.version))
            .slice(0, 1);
    }
    if (promotedAtHead.length > 1) {
        throw new Error(`HEAD has multiple ${mode} release versions for this target.`);
    }
    if (promotedAtHead.length === 1) {
        const existing = promotedAtHead[0];
        if (version !== null && version !== existing.version && !(prerelease && version === existing.target)) {
            throw new Error(`HEAD already has a different ${mode} release version.`);
        }
        if (mode === 'beta' && source.target !== existing.target) {
            throw new Error(`HEAD already has a different ${mode} release target.`);
        }
        return releasePlan(existing.version, 'existing', existing);
    }
    const requestedTarget = prerelease ? (requested?.target ?? null) : version;
    if (mode === 'alpha' && requestedTarget !== null) {
        const matching = atHead.filter((tag) => tag.target === requestedTarget);
        if (matching.length > 1) {
            throw new Error('HEAD has multiple alpha release versions for this target.');
        }
        if (matching.length === 1) {
            const existing = matching[0];
            if (version !== existing.version && version !== existing.target) {
                throw new Error('HEAD already has a different alpha release version.');
            }
            return releasePlan(existing.version, 'existing', existing);
        }
    }
    const selectedBump = promotion ? 'promotion' : 'target';
    let next = masterVersion ?? source?.target ?? (await releaseTarget(repository, tags, policy.initial_version, sha));
    if (mode === 'stable' && bump !== 'auto' && masterVersion === null) {
        next = bumpVersion(stableTags[0]?.version ?? policy.initial_version, bump);
    }
    if (mode === 'stable' && compareVersions(next, source.target) < 0) {
        throw new Error('The stable version cannot be lower than its original alpha build target.');
    }
    if (!promotion && requestedTarget !== null && requestedTarget !== next) {
        throw new Error('The alpha target must match the target prepared by the GitHub release workflow.');
    }
    if (promotion && requestedTarget !== null && requestedTarget !== next) {
        throw new Error('The requested version must match the selected promotion target.');
    }
    if (mode === 'stable' && tags.some((tag) => tag.tag === `v${next}` && tag.sha !== sha)) {
        throw new Error(`Release tag v${next} already points to another commit.`);
    }
    if (promotion && stableTags[0] && compareVersions(next, stableTags[0].version) <= 0) {
        throw new Error('A promotion target must be greater than the last stable release.');
    }
    if (mode === 'beta' && tags.some((tag) => tag.channel === 'alpha' && compareVersions(tag.target, next) > 0)) {
        throw new Error('A beta must belong to the current alpha release target.');
    }
    if (mode === 'stable') {
        const reference = stableTags[0]?.version ?? policy.initial_version;
        const permitted = ['patch', 'minor', 'major'].map((level) => bumpVersion(reference, level));
        if (!stableTags[0]) {
            permitted.push(policy.initial_version);
        }
        if (!permitted.includes(next)) {
            throw new Error('A stable release must use the next patch, minor or major version.');
        }
    }
    if (mode === 'alpha') {
        const open =
            tags
                .filter(
                    (tag) =>
                        tag.channel === 'alpha' &&
                        ancestorNames.has(tag.tag) &&
                        (!base || compareVersions(tag.target, base.version) > 0),
                )
                .sort((a, b) => compareVersions(b.target, a.target))[0] ?? null;
        if (open && compareVersions(next, open.target) < 0) {
            throw new Error(
                'Prepare a target at or above the current development release target using the GitHub workflow.',
            );
        }
    }
    if (mode === 'alpha' && tags.some((tag) => tag.channel === 'stable' && compareVersions(next, tag.target) <= 0)) {
        throw new Error('Prepare a target above the latest stable using the GitHub release workflow.');
    }
    if (mode === 'alpha') {
        const matching = atHead.filter((tag) => tag.target === next);
        if (matching.length > 1) {
            throw new Error('HEAD has multiple alpha release versions for this target.');
        }
        if (matching.length === 1) {
            const existing = matching[0];
            if (version !== null && version !== existing.version && version !== existing.target) {
                throw new Error('HEAD already has a different alpha release version.');
            }
            return releasePlan(existing.version, 'existing', existing);
        }
        const latest =
            tags.filter((tag) => tag.channel === 'alpha').sort((a, b) => compareVersions(b.version, a.version))[0] ??
            null;
        if (latest && git(repository, ['merge-base', '--is-ancestor', latest.sha, sha], true) === null) {
            throw new Error(
                'A newer alpha source has already been published. Publish from its latest dev descendant instead.',
            );
        }
    }
    if (prerelease) {
        const previews = tags.filter((existing) => existing.channel === mode && existing.target === next);
        const number = Math.max(0, ...previews.map((existing) => existing.number)) + 1;
        if (!Number.isSafeInteger(number)) {
            throw new Error('Prerelease numbers exceed the supported integer range.');
        }
        next = `${next}-${mode}.${number}`;
        if (requested?.number !== null && requested?.number !== undefined && version !== next) {
            throw new Error('An exact prerelease version must match the next available prerelease number.');
        }
    }
    const tag = `v${next}`;
    const collision = tags.find((existing) => existing.tag === tag);
    if (collision && collision.sha !== sha) {
        throw new Error(`Release tag ${tag} already points to another commit.`);
    }
    return releasePlan(next, selectedBump);
}

export function parseArguments(args) {
    if (args[0] !== 'plan') {
        throw new Error(
            'Usage: version.mjs plan --mode stable|alpha|beta|dev [--source-tag vX.Y.Z-alpha.N|vX.Y.Z-beta.N] [--bump auto|patch|minor|major] [--version X.Y.Z[-alpha.N|-beta.N]] [--branch dev|master] [--github-output]',
        );
    }
    const options = { bump: 'auto', version: null, branch: null, sourceTag: null, githubOutput: false };
    const seen = new Set();
    for (let index = 1; index < args.length; index++) {
        const name = args[index];
        if (seen.has(name)) {
            throw new Error(`Duplicate option ${name}.`);
        }
        seen.add(name);
        if (name === '--github-output') {
            options.githubOutput = true;
            continue;
        }
        const property = {
            '--mode': 'mode',
            '--bump': 'bump',
            '--version': 'version',
            '--branch': 'branch',
            '--source-tag': 'sourceTag',
        }[name];
        if (!property || !args[index + 1] || args[index + 1].startsWith('--')) {
            throw new Error(`Unknown or incomplete option ${name}.`);
        }
        options[property] = args[++index];
    }
    return options;
}

async function main() {
    const options = parseArguments(process.argv.slice(2));
    const plan = await planVersion(options);
    if (options.githubOutput) {
        if (!process.env.GITHUB_OUTPUT) {
            throw new Error('GITHUB_OUTPUT must be set when --github-output is requested.');
        }
        const lines =
            Object.entries(plan)
                .map(([key, value]) => `${key}=${value ?? ''}`)
                .join('\n') + '\n';
        if (Object.values(plan).some((value) => typeof value === 'string' && /[\r\n]/.test(value))) {
            throw new Error('Unsafe multiline workflow output.');
        }
        await appendFile(process.env.GITHUB_OUTPUT, lines, { mode: 0o600 });
    }
    process.stdout.write(JSON.stringify(plan) + '\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`Version planning failed: ${error.message}\n`);
        process.exitCode = 1;
    });
}
