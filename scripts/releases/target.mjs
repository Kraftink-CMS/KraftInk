import { execFileSync } from 'node:child_process';
import { appendFile, readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { bumpVersion, compareVersions, parseReleaseVersion, parseStableVersion } from './version.mjs';
import { createGitHubClient } from './publish.mjs';
import { channelReference, readChannels, writeChannels } from './channels.mjs';
import { sourceVersionClient, sourceVersionTools } from './source-version.mjs';

export async function prepareTarget({
    bump,
    version = null,
    client,
    initialVersion = '0.0.1',
    tags = [],
    repository = process.env.GITHUB_REPOSITORY,
}) {
    parseStableVersion(initialVersion);
    if (!['patch', 'minor', 'major'].includes(bump) || !Array.isArray(tags) || tags.length > 10000) {
        throw new Error('Choose patch, minor or major for the next release target.');
    }
    if (version !== null && version !== '') {
        throw new Error('An exact version cannot bypass the patch, minor or major progression.');
    }
    const parsed = tags.flatMap((tag) => {
        try {
            return tag.startsWith('v') ? [{ tag, version: tag.slice(1), ...parseReleaseVersion(tag.slice(1)) }] : [];
        } catch {
            return [];
        }
    });
    const currentRef = await channelReference(client);
    const current = (await readChannels(client)) ?? { schema: 1, channels: {} };
    for (const tag of Object.values(current.channels)) {
        if (!parsed.some((candidate) => candidate.tag === tag)) {
            parsed.push({ tag, version: tag.slice(1), ...parseReleaseVersion(tag.slice(1)) });
        }
    }
    const stables = parsed.filter((tag) => tag.channel === 'stable').map((tag) => tag.version);
    if (current.channels.stable) {
        stables.push(current.channels.stable.slice(1));
    }
    const stable = stables.sort((a, b) => compareVersions(b, a))[0] ?? null;
    const next = !stable && bump === 'patch' ? initialVersion : bumpVersion(stable ?? initialVersion, bump);
    parseStableVersion(next);
    if (stable && compareVersions(next, stable) <= 0) {
        throw new Error('The next target must be greater than the last stable release.');
    }
    if (current.next_version && compareVersions(next, current.next_version) < 0) {
        throw new Error('The next target cannot move backwards. Choose the current target or a greater version.');
    }
    if (parsed.some((tag) => compareVersions(tag.target, next) > 0)) {
        throw new Error('The next target cannot precede an already published release target.');
    }
    const dev = await client.request('GET', '/git/ref/heads/dev');
    if (dev?.ref !== 'refs/heads/dev' || dev.object?.type !== 'commit' || !/^[a-f0-9]{40}$/.test(dev.object.sha)) {
        throw new Error('The development version requires an existing dev branch.');
    }
    const tools = await sourceVersionTools();
    const updated = current.next_version !== next;
    if (updated) {
        await writeChannels({
            client,
            currentRef,
            catalogue: { ...current, next_version: next },
            message: `chore: prepare release target ${next}`,
        });
    }
    const source = await tools.updateSourceVersion({
        validationContext: 'checks / Validate changes',
        client: sourceVersionClient(client, repository),
        repository,
        branch: 'dev',
        expectedSha: dev.object.sha,
        version: (value) => {
            if (typeof value !== 'string' || !value.endsWith('-dev') || compareVersions(value.slice(0, -4), next) > 0) {
                throw new Error('The development manifest cannot move backwards or overwrite a stable source.');
            }
            return `${next}-dev`;
        },
    });
    return { updated: updated || source.changed, next_version: next, source_sha: source.sha };
}

export function parseTargetArguments(args) {
    if (args[0] !== 'prepare') {
        throw new Error('Use target.mjs prepare --bump patch|minor|major.');
    }
    const options = { version: null };
    const seen = new Set();
    for (let index = 1; index < args.length; index += 2) {
        const name = args[index];
        const property = { '--bump': 'bump' }[name];
        if (!property || seen.has(name) || args[index + 1] === undefined || args[index + 1].startsWith('--')) {
            throw new Error('Invalid or duplicate release target option.');
        }
        seen.add(name);
        options[property] = args[index + 1];
    }
    return options;
}

async function main() {
    if (process.env.GITHUB_REF !== 'refs/heads/dev') {
        throw new Error('Prepare version must run from the dev workflow ref.');
    }
    const options = parseTargetArguments(process.argv.slice(2));
    const policy = JSON.parse(await readFile('.github/release-policy.json', 'utf8'));
    if (policy.schema !== 1) {
        throw new Error('Unsupported release policy.');
    }
    const tags = execFileSync('git', ['tag', '--list'], {
        encoding: 'utf8',
        shell: false,
        windowsHide: true,
        timeout: 30000,
    })
        .trim()
        .split(/\r?\n/)
        .filter(Boolean);
    const client = createGitHubClient({ repository: process.env.GITHUB_REPOSITORY, token: process.env.GITHUB_TOKEN });
    const result = await prepareTarget({ ...options, client, initialVersion: policy.initial_version, tags });
    if (process.env.GITHUB_STEP_SUMMARY) {
        await appendFile(
            process.env.GITHUB_STEP_SUMMARY,
            `### Release target prepared\n\nNext target: **${result.next_version}**\n\nPush changes to \`dev\` to publish alphas of this target. Beta keeps this target. In Stable, keep auto or choose patch, minor or major.\n`,
        );
    }
    process.stdout.write(JSON.stringify(result) + '\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`Release target preparation failed: ${error.message}\n`);
        process.exitCode = 1;
    });
}
