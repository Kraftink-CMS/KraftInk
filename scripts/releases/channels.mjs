import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { bumpVersion, compareVersions, parseReleaseVersion, parseStableVersion } from './version.mjs';
import { createGitHubClient, readPublishedRelease } from './publish.mjs';

const branch = 'release-channels';
const shaPattern = /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/;

export function parseChannels(value) {
    if (
        !value ||
        value.schema !== 1 ||
        !value.channels ||
        Array.isArray(value.channels) ||
        typeof value.channels !== 'object'
    ) {
        throw new Error('Invalid release channel catalogue.');
    }
    if (value.next_version !== undefined) {
        parseStableVersion(value.next_version);
    }
    for (const [channel, tag] of Object.entries(value.channels)) {
        if (
            !['alpha', 'beta', 'stable'].includes(channel) ||
            typeof tag !== 'string' ||
            !tag.startsWith('v') ||
            parseReleaseVersion(tag.slice(1)).channel !== channel
        ) {
            throw new Error('A release channel points to an invalid tag.');
        }
    }
    return value;
}

export async function readChannels(client) {
    const response = await client.request('GET', `/contents/channels.json?ref=${branch}`);
    if (response === null) {
        return null;
    }
    if (
        response.type !== 'file' ||
        response.encoding !== 'base64' ||
        typeof response.content !== 'string' ||
        response.content.length > 32768
    ) {
        throw new Error('Invalid release channel catalogue file.');
    }
    return parseChannels(JSON.parse(Buffer.from(response.content, 'base64').toString('utf8')));
}

export async function channelReference(client) {
    const currentRef = await client.request('GET', `/git/ref/heads/${branch}`);
    if (
        currentRef &&
        (currentRef.ref !== `refs/heads/${branch}` ||
            currentRef.object?.type !== 'commit' ||
            !shaPattern.test(currentRef.object.sha))
    ) {
        throw new Error('Invalid channel catalogue branch.');
    }
    return currentRef;
}

export async function writeChannels({ client, currentRef, catalogue, message }) {
    parseChannels(catalogue);
    const tree = await client.request(
        'POST',
        '/git/trees',
        {
            tree: [
                {
                    path: 'channels.json',
                    mode: '100644',
                    type: 'blob',
                    content: JSON.stringify(catalogue, null, 4) + '\n',
                },
            ],
        },
        201,
    );
    if (!shaPattern.test(tree?.sha)) {
        throw new Error('GitHub did not create the channel catalogue tree.');
    }
    const commit = await client.request(
        'POST',
        '/git/commits',
        {
            message,
            tree: tree.sha,
            parents: currentRef ? [currentRef.object.sha] : [],
        },
        201,
    );
    if (!shaPattern.test(commit?.sha)) {
        throw new Error('GitHub did not create the channel catalogue commit.');
    }
    if (currentRef) {
        await client.request('PATCH', `/git/refs/heads/${branch}`, { sha: commit.sha, force: false });
    } else {
        await client.request('POST', '/git/refs', { ref: `refs/heads/${branch}`, sha: commit.sha }, 201);
    }
}

export async function updateChannels({ plan, client, publicKey }) {
    const published = await readPublishedRelease({ tag: plan.tag, client, publicKey });
    if (
        published.sha !== plan.sha ||
        published.mode !== plan.mode ||
        published.version !== plan.version ||
        plan.tag !== `v${plan.version}`
    ) {
        throw new Error('Only a verified published release can update a channel.');
    }
    const currentRef = await channelReference(client);
    const current = (await readChannels(client)) ?? { schema: 1, channels: {} };
    const previous = current.channels[plan.mode];
    const channels = { ...current.channels };
    if (!previous || compareVersions(previous.slice(1), plan.version) < 0) {
        channels[plan.mode] = plan.tag;
    }
    let next = current.next_version ?? parseReleaseVersion(plan.version).target;
    const target = parseReleaseVersion(plan.version).target;
    if (compareVersions(next, target) < 0) {
        next = target;
    }
    if (channels.stable) {
        const following = bumpVersion(channels.stable.slice(1), 'patch');
        if (compareVersions(next, following) < 0) {
            next = following;
        }
    }
    const catalogue = { ...current, schema: 1, next_version: next, channels };
    if (
        JSON.stringify(catalogue.channels) === JSON.stringify(current.channels) &&
        catalogue.next_version === current.next_version
    ) {
        return { updated: false, channel: plan.mode, tag: channels[plan.mode], next_version: next };
    }
    await writeChannels({
        client,
        currentRef,
        catalogue,
        message: `chore: publish ${plan.mode} channel ${plan.version}`,
    });
    return { updated: true, channel: plan.mode, tag: channels[plan.mode], next_version: next };
}

async function main() {
    if (process.argv[2] !== 'update' || process.argv[3] !== '--plan' || !process.argv[4] || process.argv.length !== 5) {
        throw new Error('Use channels.mjs update --plan release-plan.json.');
    }
    const plan = JSON.parse(await readFile(resolve(process.argv[4]), 'utf8'));
    const expectedBranch = plan.mode === 'stable' ? 'master' : 'dev';
    const allowedRefs = plan.mode === 'stable' ? ['refs/heads/dev', 'refs/heads/master'] : ['refs/heads/dev'];
    if (
        !allowedRefs.includes(process.env.GITHUB_REF) ||
        plan.branch !== expectedBranch ||
        plan.publish_allowed !== true ||
        plan.tag !== `v${plan.version}`
    ) {
        throw new Error('A published release plan on its publication branch is required.');
    }
    const client = createGitHubClient({ repository: process.env.GITHUB_REPOSITORY, token: process.env.GITHUB_TOKEN });
    process.stdout.write(
        JSON.stringify(await updateChannels({ plan, client, publicKey: process.env.KRAFTINK_CMS_SIGNING_PUBLIC_KEY })) + '\n',
    );
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`Channel publication failed: ${error.message}\n`);
        process.exitCode = 1;
    });
}
