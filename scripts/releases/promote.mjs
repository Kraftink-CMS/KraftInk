import { execFileSync } from 'node:child_process';
import { appendFile, mkdir, realpath } from 'node:fs/promises';
import { resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { compareVersions, parseReleaseVersion, planVersion } from './version.mjs';
import {
    createGitHubClient,
    inspectRelease,
    localBundle,
    publicationNotes,
    publishRelease,
    readPublishedRelease,
    validatePublicationPlan,
} from './publish.mjs';
import { updateChannels } from './channels.mjs';
import {
    assertNoOpenStablePromotion,
    authorizeStableActors,
    cleanupStablePromotion,
    prepareStablePromotion,
    promotionIntent,
    readPromotionNotes,
    refreshMaster,
} from './stable-promotion.mjs';
import { advanceDevelopmentVersion, sourceVersionTools } from './source-version.mjs';

function git(root, args) {
    return execFileSync('git', ['-C', root, ...args], {
        encoding: 'utf8',
        shell: false,
        windowsHide: true,
        timeout: 30000,
        env: { ...process.env, GIT_TERMINAL_PROMPT: '0' },
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

export function selectSourceTag({ mode, sourceTag, tags }) {
    if (!['beta', 'stable'].includes(mode) || typeof sourceTag !== 'string') {
        throw new Error('Select a beta or stable promotion and a published source.');
    }
    const selector = sourceTag === 'latest' ? (mode === 'beta' ? 'latest-alpha' : 'latest-beta') : sourceTag;
    const channel = selector === 'latest-alpha' ? 'alpha' : selector === 'latest-beta' ? 'beta' : null;
    let selected = selector;
    if (channel) {
        if (!Array.isArray(tags) || tags.length > 10000) {
            throw new Error('The release tag inventory exceeds its size limit.');
        }
        const candidates = tags
            .filter((tag) => {
                try {
                    return tag.startsWith('v') && parseReleaseVersion(tag.slice(1)).channel === channel;
                } catch {
                    return false;
                }
            })
            .sort((a, b) => compareVersions(b.slice(1), a.slice(1)));
        if (!candidates.length) {
            throw new Error(`No ${channel} release exists. Select another source in the workflow.`);
        }
        selected = candidates[0];
    }
    if (!selected.startsWith('v')) {
        throw new Error('Use latest-alpha, latest-beta or an exact vX.Y.Z-alpha.N / vX.Y.Z-beta.N tag.');
    }
    const parsed = parseReleaseVersion(selected.slice(1));
    if (parsed.channel === 'stable' || (mode === 'beta' && parsed.channel !== 'alpha')) {
        throw new Error('Beta publication requires an alpha. Stable publication accepts an alpha or beta.');
    }
    return selected;
}

export function parsePromotionArguments(args) {
    if (args[0] !== 'run') {
        throw new Error(
            'Use promote.mjs run --mode beta|stable --source-tag latest-alpha|latest-beta|vX.Y.Z-stage.N --directory path [--bump auto|patch|minor|major] [--prepare-only].',
        );
    }
    const options = {};
    const properties = { '--mode': 'mode', '--source-tag': 'sourceTag', '--directory': 'directory', '--bump': 'bump' };
    for (let index = 1; index < args.length; index++) {
        if (args[index] === '--prepare-only' && options.prepareOnly === undefined) {
            options.prepareOnly = true;
            continue;
        }
        const property = properties[args[index]];
        if (!property || options[property] !== undefined || !args[index + 1] || args[index + 1].startsWith('--')) {
            throw new Error('Invalid or duplicate promotion option.');
        }
        options[property] = args[++index];
    }
    if (!['beta', 'stable'].includes(options.mode) || !options.sourceTag || !options.directory) {
        throw new Error('Promotion mode, source tag and output directory are required.');
    }
    if (options.bump !== undefined && !['auto', 'patch', 'minor', 'major'].includes(options.bump)) {
        throw new Error('Choose auto, patch, minor or major for stable publication.');
    }
    if (options.prepareOnly && options.mode !== 'stable')
        throw new Error('Only Stable prepares a release pull request for validation.');
    return options;
}

export async function promoteRelease({
    root = process.cwd(),
    mode,
    sourceTag,
    bump = 'auto',
    prepareOnly = false,
    directory,
    environment = process.env,
    client = null,
    runPackage = null,
    refresh = refreshMaster,
    wait,
}) {
    const tools = await sourceVersionTools(environment);
    const requestedNotes = tools.normalizeReleaseNotes({
        mode: environment.RELEASE_NOTES_MODE || 'auto',
        message: environment.RELEASE_NOTES || '',
    });
    if (environment.GITHUB_REF !== 'refs/heads/dev') {
        throw new Error('Promotions must run from the original dev workflow ref.');
    }
    const repository = await realpath(root);
    if (git(repository, ['status', '--porcelain', '--untracked-files=normal']) !== '') {
        throw new Error('Promotion tooling requires a clean checkout.');
    }
    {
        const branch = git(repository, ['branch', '--show-current']);
        const containing = git(repository, [
            'for-each-ref',
            '--contains=HEAD',
            '--format=%(refname)',
            'refs/heads/dev',
            'refs/remotes/origin/dev',
        ]);
        if ((branch && branch !== 'dev') || !containing) {
            throw new Error('Promotion tooling requires a verified dev checkout.');
        }
    }
    const github =
        client ?? createGitHubClient({ repository: environment.GITHUB_REPOSITORY, token: environment.GITHUB_TOKEN });
    if (mode === 'stable') await authorizeStableActors({ client: github, environment });
    const continuation = await assertNoOpenStablePromotion({ client: github, environment, resumeRun: mode === 'stable' });
    if (continuation) bump = continuation.intent.bump;
    const pinnedNotes = continuation ? readPromotionNotes(continuation.pull.body, continuation.intent) : null;
    if (pinnedNotes) {
        const normalized = tools.normalizeReleaseNotes(pinnedNotes);
        if (normalized.mode !== pinnedNotes.mode || normalized.message !== pinnedNotes.message)
            throw new Error('The original stable release notes are not normalized.');
    }
    const tags = git(repository, ['tag', '--list']).split(/\r?\n/).filter(Boolean);
    const selected = continuation?.intent.source_tag ?? selectSourceTag({ mode, sourceTag, tags });
    const publicKey = environment.KRAFTINK_CMS_SIGNING_PUBLIC_KEY;
    const source = await readPublishedRelease({ tag: selected, client: github, publicKey, requireBuild: true });
    if (continuation && source.sha !== continuation.intent.source_sha)
        throw new Error('The original stable source tag changed. Resume only its immutable tested snapshot.');
    const baselineChannels = mode === 'beta' ? ['beta', 'stable'] : ['stable'];
    for (const channel of baselineChannels) {
        const previousTag = tags
            .filter((tag) => {
                try {
                    return tag.startsWith('v') && parseReleaseVersion(tag.slice(1)).channel === channel;
                } catch {
                    return false;
                }
            })
            .sort((a, b) => compareVersions(b.slice(1), a.slice(1)))[0];
        if (previousTag) {
            const previous = await readPublishedRelease({
                tag: previousTag,
                client: github,
                publicKey,
                requireBuild: true,
            });
            if (
                compareVersions(source.build.version, previous.build.version) < 0 ||
                (source.build.version === previous.build.version && source.build.sha !== previous.build.sha)
            ) {
                throw new Error(`The source snapshot precedes or conflicts with the last ${channel} release.`);
            }
        }
    }
    if (git(repository, ['rev-parse', '--verify', `refs/tags/${selected}^{commit}`]) !== source.sha) {
        throw new Error('The published source tag does not match the local immutable tag.');
    }
    const destination = resolve(directory);
    await mkdir(destination, { mode: 0o700 });
    const checkout = join(destination, 'source');
    let attached = false;
    let stableIntent = null;
    let promotion = null;
    try {
        git(repository, ['worktree', 'add', '--detach', checkout, source.sha]);
        attached = true;
        let plan = await planVersion({
            root: checkout,
            mode,
            branch: 'dev',
            sourceTag: selected,
            bump,
            version: continuation?.intent.version ?? null,
        });
        const notes = pinnedNotes ?? await publicationNotes({
            root: checkout,
            repository: environment.GITHUB_REPOSITORY,
            plan,
            ...requestedNotes,
            environment,
        });
        const input = join(destination, 'input');
        const output = join(destination, 'dist');
        let packaged = false;
        const packageSource = async () => {
            await readPublishedRelease({
                tag: selected,
                client: github,
                publicKey,
                directory: input,
                requireBuild: true,
            });
            if (runPackage) {
                await runPackage({ input, output, source, plan });
            } else {
                execFileSync(
                    environment.RELEASE_PHP_BINARY || 'php',
                    [
                        join(repository, 'scripts', 'releases', 'promote-package.php'),
                        `--source=${input}`,
                        `--source-version=${source.version}`,
                        `--version=${plan.version}`,
                        `--output=${output}`,
                        `--key-file=${environment.KRAFTINK_CMS_SIGNING_PRIVATE_KEY_FILE ?? ''}`,
                        `--source-tag=${source.tag}`,
                        `--source-sha=${source.sha}`,
                    ],
                    {
                        encoding: 'utf8',
                        shell: false,
                        windowsHide: true,
                        timeout: 300000,
                        maxBuffer: 1048576,
                        stdio: ['ignore', 'pipe', 'pipe'],
                    },
                );
            }
            packaged = true;
        };
        if (mode === 'stable') {
            const intent = promotionIntent({ sourceTag: selected, sourceSha: source.sha, version: plan.version, bump });
            stableIntent = intent;
            if (continuation && JSON.stringify(intent) !== JSON.stringify(continuation.intent))
                throw new Error('The original stable source selection or target changed.');
            await assertNoOpenStablePromotion({ client: github, environment, resumeRun: true });
            promotion = await prepareStablePromotion({
                root: repository,
                intent,
                client: github,
                environment,
                refresh,
                merge: false,
                notes,
                wait,
            });
            if (prepareOnly)
                return { source_sha: promotion.prepared_sha, version: intent.version,
                    source_tag: intent.source_tag, pull_number: promotion.pull_number };
            if (environment.KRAFTINK_CHECKED_SOURCE && environment.KRAFTINK_CHECKED_SOURCE !== promotion.prepared_sha)
                throw new Error('The successful checks belong to another stable source. Resume the original workflow run.');
            if (!promotion.merged) {
                await packageSource();
                await localBundle(plan, output, publicKey, { preview: true });
                if ((await github.tag(selected)) !== source.sha)
                    throw new Error('The source tag changed during promotion.');
                promotion = await prepareStablePromotion({
                    root: repository,
                    intent,
                    client: github,
                    environment,
                    refresh,
                    notes,
                    wait,
                });
            }
            plan = await planVersion({
                root: checkout,
                mode,
                branch: 'master',
                sourceTag: selected,
                bump,
                version: intent.version,
            });
        }
        await validatePublicationPlan(plan, checkout, environment);
        if (environment.GITHUB_STEP_SUMMARY) {
            await appendFile(
                environment.GITHUB_STEP_SUMMARY,
                `### Selected release\n\n| Source | Target | Commit |\n| --- | --- | --- |\n| \`${source.tag}\` | \`${plan.tag}\` | \`${plan.sha}\` |\n\nThe selected published package is reused without rebuilding dependencies or assets.\n\n`,
            );
        }
        const settings = { plan, client: github, publicKey, environment, body: notes.body };
        const inspected = await inspectRelease(settings);
        let result = { ...inspected, published: false };
        if (!inspected.complete) {
            if (!packaged) await packageSource();
            if (mode === 'stable') {
                await refresh({ root: repository, client: github, environment });
            }
            await validatePublicationPlan(plan, checkout, environment);
            if ((await github.tag(selected)) !== source.sha) {
                throw new Error('The source tag changed during promotion.');
            }
            if (mode === 'stable') await authorizeStableActors({ client: github, environment });
            result = await publishRelease({ ...settings, directory: output });
        }
        if (mode === 'stable') await authorizeStableActors({ client: github, environment });
        const channels = await updateChannels(settings);
        if (mode === 'stable') {
            await authorizeStableActors({ client: github, environment });
            await advanceDevelopmentVersion({ plan, client: github, target: channels.next_version, environment });
            const cleaned = await cleanupStablePromotion({ client: github, environment, intent: stableIntent, promotion });
            if (!cleaned && environment.GITHUB_STEP_SUMMARY)
                await appendFile(environment.GITHUB_STEP_SUMMARY, '\nThe promotion branch was retained because its current state could not be verified.\n');
        }
        if (environment.GITHUB_STEP_SUMMARY) {
            await appendFile(
                environment.GITHUB_STEP_SUMMARY,
                `### ${mode === 'stable' ? 'Stable' : 'Beta'} published\n\n[${plan.tag}](https://github.com/${environment.GITHUB_REPOSITORY}/releases/tag/${plan.tag})\n\nThe update channel catalogue has been refreshed.\n`,
            );
        }
        return result;
    } finally {
        if (attached) {
            git(repository, ['worktree', 'remove', checkout]);
        }
    }
}

async function main() {
    const options = parsePromotionArguments(process.argv.slice(2));
    if (options.mode === 'stable' && !options.prepareOnly
            && !/^[a-f0-9]{40}$/.test(process.env.KRAFTINK_CHECKED_SOURCE || ''))
        throw new Error('Stable publication requires the exact source from its successful checks job.');
    const result = await promoteRelease(options);
    if (options.prepareOnly && process.env.GITHUB_OUTPUT)
        await appendFile(process.env.GITHUB_OUTPUT,
            Object.entries(result).map(([name, value]) => `${name}=${value}\n`).join(''));
    process.stdout.write(JSON.stringify(result) + '\n');
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    main().catch((error) => {
        process.stderr.write(`Release promotion failed: ${error.message}\n`);
        process.exitCode = 1;
    });
}
