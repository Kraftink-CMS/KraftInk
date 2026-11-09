import { execFileSync } from 'node:child_process';
import { compareVersions, parseReleaseVersion, parseStableVersion, stableMasterCommit } from './version.mjs';
import { sourceVersionClient, sourceVersionTools } from './source-version.mjs';

const shaPattern = /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/;
const marker = /<!-- kraftink-stable-promotion\n([^]*?)\n-->/g;

function sameRun(pull, environment) {
    const entries = typeof pull.body === 'string' ? [...pull.body.matchAll(/<!-- kraftink-stable-run\n([^]*?)\n-->/g)] : [];
    let run;
    try { if (entries.length === 1) run = JSON.parse(entries[0][1]); } catch {}
    return environment.GITHUB_ACTIONS === 'true' && run?.schema === 1
        && run.repository?.toLowerCase() === environment.GITHUB_REPOSITORY?.toLowerCase()
        && run.run_id === Number(environment.GITHUB_RUN_ID)
        && Number.isSafeInteger(run.run_attempt) && run.run_attempt > 0
        && run.run_attempt <= Number(environment.GITHUB_RUN_ATTEMPT)
        && pull.user?.type === 'Bot' && pull.user.login === 'github-actions[bot]';
}

export async function authorizeStableActors({ client, environment = process.env }) {
    if (environment.GITHUB_ACTIONS !== 'true') return;
    const id = Number(environment.GITHUB_RUN_ID);
    const attempt = Number(environment.GITHUB_RUN_ATTEMPT);
    if (!Number.isSafeInteger(id) || id < 1 || !Number.isSafeInteger(attempt) || attempt < 1
            || environment.GITHUB_EVENT_NAME !== 'workflow_dispatch' || environment.GITHUB_REF !== 'refs/heads/dev')
        throw new Error('Stable publication requires its original manual dev workflow run.');
    const run = await client.request('GET', `/actions/runs/${id}/attempts/${attempt}`);
    const repository = environment.GITHUB_REPOSITORY;
    if (run?.id !== id || run.run_attempt !== attempt || run.event !== 'workflow_dispatch' || run.head_branch !== 'dev'
            || run.repository?.full_name?.toLowerCase() !== repository?.toLowerCase()
            || run.head_repository?.id !== run.repository?.id || !Number.isSafeInteger(run.repository?.id)
            || !['.github/workflows/stable.yml', '.github/workflows/stable.yml@dev',
                '.github/workflows/stable.yml@refs/heads/dev'].includes(run.path))
        throw new Error('The stable publication workflow provenance changed.');
    for (const actor of [run.actor, run.triggering_actor]) {
        if (actor?.type !== 'User' || !Number.isSafeInteger(actor.id) || actor.id < 1
                || !/^[A-Za-z0-9-]{1,39}$/.test(actor.login || ''))
            throw new Error('Both stable release initiators must be identified human repository writers.');
        const access = await client.request('GET', `/collaborators/${actor.login}/permission`);
        if (access?.user?.id !== actor.id || access.user.type !== 'User'
                || access.user.login?.toLowerCase() !== actor.login.toLowerCase()
                || !['write', 'maintain', 'admin'].includes(access.permission))
            throw new Error('A stable release initiator no longer has repository write access.');
    }
}

export async function assertNoOpenStablePromotion({ client, environment = process.env, resumeRun = false }) {
    let requestedAt = null;
    let own = null;
    let previous = null;
    let complete = false;
    if (environment.GITHUB_ACTIONS === 'true') {
        const id = Number(environment.GITHUB_RUN_ID);
        if (!Number.isSafeInteger(id) || id < 1) throw new Error('The publication workflow run identity is invalid.');
        const run = await client.request('GET', `/actions/runs/${id}`);
        requestedAt = Date.parse(run?.created_at);
        if (run?.id !== id || !Number.isFinite(requestedAt))
            throw new Error('The publication workflow request time could not be verified.');
    }
    for (let page = 1; page <= 10; page++) {
        const pulls = await client.request('GET', `/pulls?state=all&base=master&per_page=100&page=${page}`);
        if (!Array.isArray(pulls) || pulls.length > 100)
            throw new Error('The stable release pull request inventory is invalid.');
        for (const pull of pulls) {
            if (pull.head?.repo?.full_name?.toLowerCase() !== environment.GITHUB_REPOSITORY?.toLowerCase()) continue;
            if (
                !pull.head?.ref?.startsWith('release/stable-') &&
                !(typeof pull.body === 'string' && pull.body.includes('<!-- kraftink-stable-promotion'))
            )
                continue;
            if (resumeRun && sameRun(pull, environment)) {
                const intent = readPromotionIntent(pull.body);
                checkPull(pull, intent, environment.GITHUB_REPOSITORY);
                if (own) throw new Error('Multiple stable promotions belong to this workflow run.');
                own = { pull, intent };
                continue;
            }
            if (pull.state !== 'open' || pull.merged_at) {
                if (pull.merged_at) {
                    const intent = readPromotionIntent(pull.body);
                    if (!previous || compareVersions(intent.version, previous.version) > 0) previous = intent;
                }
                if (requestedAt === null) continue;
                const openedAt = Date.parse(pull.created_at);
                const closedAt = Date.parse(pull.closed_at);
                if (!Number.isFinite(openedAt) || !Number.isFinite(closedAt))
                    throw new Error('The stable release pull request lifetime could not be verified.');
                if (requestedAt < openedAt || requestedAt >= closedAt) continue;
            }
            if (!Number.isSafeInteger(pull.number) || pull.number < 1)
                throw new Error('The open stable release pull request has an invalid identity.');
            throw new Error(
                `A stable release pull request blocks this publication request: https://github.com/${environment.GITHUB_REPOSITORY}/pull/${pull.number}`,
            );
        }
        if (pulls.length < 100) { complete = true; break; }
    }
    if (!complete) throw new Error('The stable release pull request inventory exceeds its pagination limit.');
    if (previous) {
        try {
            const { readPublishedRelease } = await import('./publish.mjs');
            await readPublishedRelease({ tag: `v${previous.version}`, client,
                publicKey: environment.KRAFTINK_CMS_SIGNING_PUBLIC_KEY, requireBuild: true });
        } catch {
            throw new Error('The previous merged Stable release has not finished publication. Resume its original workflow run.');
        }
    }
    return own;
}

function git(root, args, environment = process.env) {
    return execFileSync('git', ['-C', root, ...args], {
        encoding: 'utf8',
        shell: false,
        windowsHide: true,
        timeout: 60000,
        env: { ...environment, GIT_TERMINAL_PROMPT: '0' },
        stdio: ['ignore', 'pipe', 'pipe'],
    }).trim();
}

export function promotionIntent({ sourceTag, sourceSha, version, bump = 'auto' }) {
    if (
        typeof sourceTag !== 'string' ||
        !sourceTag.startsWith('v') ||
        !['alpha', 'beta'].includes(parseReleaseVersion(sourceTag.slice(1)).channel) ||
        !shaPattern.test(sourceSha) ||
        !['auto', 'patch', 'minor', 'major'].includes(bump)
    ) {
        throw new Error('Invalid stable promotion source or version level.');
    }
    parseStableVersion(version);
    return { schema: 1, source_tag: sourceTag, source_sha: sourceSha, version, bump };
}

export function promotionBranch(intent) {
    return `release/stable-${intent.version}-${intent.source_sha}`;
}

export function promotionBody(intent, environment = {}, notes = null) {
    let body = `Promote the tested snapshot \`${intent.source_tag}\` (\`${intent.source_sha}\`) to master and publish \`v${intent.version}\`.\n\nUse a merge commit to preserve the tested source history. The stable archive reuses the verified published package.\n\n<!-- kraftink-stable-promotion\n${JSON.stringify(intent)}\n-->`;
    if (notes) body += `\n\n<!-- kraftink-release-notes\n${JSON.stringify(notes)}\n-->`;
    if (environment.GITHUB_ACTIONS === 'true') {
        const receipt = {
            schema: 1,
            repository: environment.GITHUB_REPOSITORY,
            run_id: Number(environment.GITHUB_RUN_ID),
            run_attempt: Number(environment.GITHUB_RUN_ATTEMPT),
        };
        if (
            typeof receipt.repository !== 'string' ||
            !/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(receipt.repository) ||
            !Number.isSafeInteger(receipt.run_id) ||
            receipt.run_id < 1 ||
            !Number.isSafeInteger(receipt.run_attempt) ||
            receipt.run_attempt < 1
        )
            throw new Error('The stable promotion automation receipt is invalid.');
        body += `\n\n<!-- kraftink-stable-run\n${JSON.stringify(receipt)}\n-->`;
    }
    return body;
}

function hasExactKeys(value, keys) {
    return Object.keys(value).length === keys.length && keys.every((key) => Object.hasOwn(value, key));
}

export function readPromotionNotes(body, intent) {
    const entries = typeof body === 'string' ? [...body.matchAll(/<!-- kraftink-release-notes\n([^]*?)\n-->/g)] : [];
    let notes;
    try { if (entries.length === 1) notes = JSON.parse(entries[0][1]); } catch {}
    if (
        notes?.schema !== 1 ||
        !hasExactKeys(notes, ['body', 'message', 'mode', 'previous_tag', 'schema', 'source_sha']) ||
        notes.source_sha !== intent.source_sha ||
        typeof notes.mode !== 'string' ||
        typeof notes.message !== 'string' ||
        typeof notes.body !== 'string' ||
        Buffer.byteLength(notes.body) > 65536
    ) {
        throw new Error('The original stable release notes are missing or do not match their immutable source.');
    }
    if (notes.previous_tag !== null) {
        if (typeof notes.previous_tag !== 'string' || !notes.previous_tag.startsWith('v'))
            throw new Error('The stable release notes have an invalid previous stable tag.');
        parseStableVersion(notes.previous_tag.slice(1));
    }
    return notes;
}

export function readPromotionIntent(body) {
    if (typeof body !== 'string' || body.length > 65536) {
        throw new Error('The stable release pull request has no valid promotion intent.');
    }
    const matches = [...body.matchAll(marker)];
    if (matches.length !== 1) {
        throw new Error('The stable release pull request must contain exactly one promotion intent.');
    }
    let value;
    try {
        value = JSON.parse(matches[0][1]);
    } catch {
        throw new Error('The stable promotion intent is not valid JSON.');
    }
    if (
        value?.schema !== 1 ||
        !hasExactKeys(value, ['bump', 'schema', 'source_sha', 'source_tag', 'version'])
    ) {
        throw new Error('The stable promotion intent schema is invalid.');
    }
    return promotionIntent({
        sourceTag: value.source_tag,
        sourceSha: value.source_sha,
        version: value.version,
        bump: value.bump,
    });
}

function checkPull(pull, intent, repository, preparedSha = null) {
    if (
        !Number.isSafeInteger(pull?.number) ||
        pull.number < 1 ||
        pull.base?.ref !== 'master' ||
        pull.base.repo?.full_name?.toLowerCase() !== repository.toLowerCase() ||
        pull.head?.repo?.full_name?.toLowerCase() !== repository.toLowerCase() ||
        pull.head?.ref !== promotionBranch(intent) ||
        pull.user?.type !== 'Bot' || pull.user.login !== 'github-actions[bot]' || pull.draft !== false ||
        !shaPattern.test(pull.head?.sha) ||
        (preparedSha !== null && pull.head.sha !== preparedSha) ||
        JSON.stringify(readPromotionIntent(pull.body)) !== JSON.stringify(intent)
    ) {
        throw new Error('The stable release pull request does not match the selected immutable snapshot.');
    }
    return pull;
}

export async function refreshMaster({ root, client, environment = process.env }) {
    const ref = await client.request('GET', '/git/ref/heads/master');
    if (ref?.ref !== 'refs/heads/master' || ref.object?.type !== 'commit' || !shaPattern.test(ref.object.sha)) {
        throw new Error('Create the master branch before promoting a stable release.');
    }
    const repository = environment.GITHUB_REPOSITORY;
    if (typeof repository !== 'string' || !/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository)) {
        throw new Error('GITHUB_REPOSITORY must identify the release repository.');
    }
    git(
        root,
        ['fetch', '--no-tags', `https://github.com/${repository}.git`, '+refs/heads/master:refs/remotes/origin/master'],
        {
            ...environment,
            GIT_CONFIG_COUNT: '1',
            GIT_CONFIG_KEY_0: 'http.https://github.com/.extraheader',
            GIT_CONFIG_VALUE_0: `AUTHORIZATION: basic ${Buffer.from(`x-access-token:${environment.GITHUB_TOKEN}`).toString('base64')}`,
        },
    );
    if (git(root, ['rev-parse', 'refs/remotes/origin/master']) !== ref.object.sha) {
        throw new Error(
            'The master branch moved while refreshing the stable publication snapshot. Retry the workflow.',
        );
    }
    return ref.object.sha;
}

async function confirmedMerge({ root, intent, client, environment, refresh, pull, preparedSha, tree, expectedSha, base, wait }) {
    const url = 'https://github.com/' + environment.GITHUB_REPOSITORY + '/pull/' + pull.number;
    for (let attempt = 0; attempt < 60; attempt++) {
        const current = checkPull(await client.request('GET', '/pulls/' + pull.number), intent,
            environment.GITHUB_REPOSITORY, preparedSha);
        if (environment.GITHUB_ACTIONS === 'true' && !sameRun(current, environment))
            throw new Error('The stable release no longer belongs to its original workflow run: ' + url);
        if (current.state === 'closed' && current.merged_at && shaPattern.test(current.merge_commit_sha)) {
            if (expectedSha && current.merge_commit_sha !== expectedSha)
                throw new Error('The confirmed stable merge changed: ' + url);
            const sha = current.merge_commit_sha;
            const merged = await client.request('GET', '/git/commits/' + sha);
            if (merged?.sha !== sha || merged.tree?.sha !== tree || merged.parents?.length !== 2
                    || merged.parents[1]?.sha !== preparedSha || !shaPattern.test(merged.parents[0]?.sha)
                    || base && merged.parents[0].sha !== base)
                throw new Error('The stable merge must preserve its exact prepared tree and both parents: ' + url);
            if (await refresh({ root, client, environment }) !== sha
                    || await stableMasterCommit(root, intent.source_sha, intent.version) !== sha)
                throw new Error('Master moved away from the verified stable merge: ' + url);
            return { merged: true, master_sha: sha, prepared_sha: preparedSha, source_tree_sha: tree,
                pull_number: pull.number, pull_request: url };
        }
        if (current.state === 'closed' && !current.merged_at)
            throw new Error('The stable release pull request is closed without merging: ' + url);
        if (attempt < 59) await wait(1000);
    }
    throw new Error('GitHub has not confirmed the stable merge. Rerun the failed jobs of this Stable workflow: ' + url);
}

export async function prepareStablePromotion({
    root,
    intent,
    client,
    environment = process.env,
    refresh = refreshMaster,
    merge = true,
    notes = null,
    wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds)),
}) {
    await authorizeStableActors({ client, environment });
    const base = await refresh({ root, client, environment });
    const branch = promotionBranch(intent);
    const repository = environment.GITHUB_REPOSITORY;
    const tools = await sourceVersionTools(environment);
    const sourceClient = sourceVersionClient(client, repository);
    const pulls = await client.request('GET', '/pulls?state=all&base=master&head='
        + encodeURIComponent(repository.split('/')[0] + ':' + branch) + '&per_page=100');
    if (!Array.isArray(pulls) || pulls.length >= 100)
        throw new Error('The stable promotion pull request is ambiguous.');
    const candidates = pulls.filter((pull) => environment.GITHUB_ACTIONS === 'true'
        ? sameRun(pull, environment) : pull.state === 'open' || pull.merged_at);
    if (candidates.length > 1) throw new Error('Multiple stable promotions belong to this workflow run.');
    let pull = candidates[0] || null;
    if (!pull && pulls.some((entry) => entry.state === 'open'))
        throw new Error('Another stable release pull request is still open. Resume its original workflow run.');
    if (pull) {
        checkPull(pull, intent, repository);
        if (notes && JSON.stringify(readPromotionNotes(pull.body, intent)) !== JSON.stringify(notes))
            throw new Error('The stable release notes changed after their original selection.');
        if (environment.GITHUB_ACTIONS === 'true' && !sameRun(pull, environment))
            throw new Error('Resume the original Stable workflow run for this promotion: https://github.com/'
                + repository + '/pull/' + pull.number);
    }
    let preparedSha = pull?.head.sha;
    if (!pull) {
        const existing = await client.request('GET', '/git/ref/heads/' + branch);
        if (existing && (existing.ref !== 'refs/heads/' + branch || existing.object?.type !== 'commit'
                || !shaPattern.test(existing.object.sha)))
            throw new Error('The stable promotion branch moved away from its tested snapshot.');
        if (!existing) await client.request('POST', '/git/refs', { ref: 'refs/heads/' + branch, sha: intent.source_sha }, 201);
        preparedSha = existing?.object.sha || intent.source_sha;
        if (preparedSha === intent.source_sha)
            preparedSha = (await tools.updateSourceVersion({ client: sourceClient, repository, branch,
                version: intent.version, expectedSha: intent.source_sha })).sha;
    }
    const prepared = await tools.verifyPreparedSourceVersion({ client: sourceClient, repository,
        originSha: intent.source_sha, preparedSha, version: intent.version });
    if (!pull) pull = checkPull(await client.request('POST', '/pulls', {
        title: 'chore(release): promote ' + intent.source_tag + ' to v' + intent.version,
        head: branch, base: 'master', body: promotionBody(intent, environment, notes),
    }, 201), intent, repository, preparedSha);
    const url = 'https://github.com/' + repository + '/pull/' + pull.number;
    if (pull.state === 'closed') {
        if (!pull.merged_at) throw new Error('The stable release pull request is closed without merging: ' + url);
        return confirmedMerge({ root, intent, client, environment, refresh, pull, preparedSha,
            tree: prepared.tree_sha, wait });
    }
    const body = promotionBody(intent, environment, notes);
    if (pull.body !== body) {
        pull = checkPull(await client.request('PATCH', '/pulls/' + pull.number, { body }), intent, repository, preparedSha);
        if (pull.body !== body) throw new Error('GitHub did not save the original stable workflow identity.');
    }
    let mergedTree;
    try { mergedTree = git(root, ['merge-tree', '--write-tree', 'refs/remotes/origin/master', intent.source_sha]); }
    catch {
        throw new Error('The tested snapshot cannot merge cleanly into master. Close this pull request and publish a new alpha containing the resolved changes: ' + url);
    }
    if (mergedTree !== git(root, ['rev-parse', intent.source_sha + '^{tree}'])
            && git(root, ['rev-parse', base + '^{tree}']) !== prepared.tree_sha)
        throw new Error('Merging would change the tested snapshot. Close this pull request and publish a new alpha that includes the master changes: ' + url);
    if (!merge) return { merged: false, pull_request: url, pull_number: pull.number,
        prepared_sha: preparedSha, source_tree_sha: prepared.tree_sha, version: intent.version, source_tag: intent.source_tag };
    let validated = false;
    for (let attempt = 0; attempt < 60; attempt++) {
        pull = checkPull(await client.request('GET', '/pulls/' + pull.number), intent, repository, preparedSha);
        if (pull.merged_at)
            return confirmedMerge({ root, intent, client, environment, refresh, pull, preparedSha,
                tree: prepared.tree_sha, wait });
        if (pull.state !== 'open' || pull.body !== body)
            throw new Error('The original stable release pull request changed: ' + url);
        const sourceRef = await client.request('GET', '/git/ref/heads/' + branch);
        const masterRef = await client.request('GET', '/git/ref/heads/master');
        if (sourceRef?.object?.sha !== preparedSha || masterRef?.object?.sha !== base)
            throw new Error('A stable promotion branch moved during validation: ' + url);
        if (pull.mergeable === false)
            throw new Error('The stable release pull request has a merge conflict. Publish a corrected alpha: ' + url);
        if (pull.mergeable === true && shaPattern.test(pull.merge_commit_sha)) {
            const candidate = await client.request('GET', '/git/commits/' + pull.merge_commit_sha);
            if (candidate?.sha !== pull.merge_commit_sha || candidate.tree?.sha !== prepared.tree_sha
                    || candidate.parents?.length !== 2 || candidate.parents[0]?.sha !== base
                    || candidate.parents[1]?.sha !== preparedSha)
                throw new Error('The proposed stable merge differs from its verified prepared tree or parents: ' + url);
            await authorizeStableActors({ client, environment });
            if (!validated) {
                const status = await client.request('POST', '/statuses/' + preparedSha, {
                    state: 'success', context: 'Release validated source',
                    description: 'The signed release archives and immutable source passed validation.',
                    target_url: environment.GITHUB_ACTIONS === 'true'
                        ? 'https://github.com/' + repository + '/actions/runs/' + environment.GITHUB_RUN_ID : url,
                }, 201);
                if (status?.state !== 'success' || status.context !== 'Release validated source')
                    throw new Error('GitHub did not confirm the validated stable source status.');
                validated = true;
            }
            await authorizeStableActors({ client, environment });
            let result;
            try {
                result = await client.request('PUT', '/pulls/' + pull.number + '/merge', {
                    sha: preparedSha, merge_method: 'merge',
                    commit_title: 'chore(release): promote ' + intent.source_tag + ' to v' + intent.version,
                });
            } catch (error) {
                if (![403, 405, 409, 422].includes(error.status)) throw error;
                if (error.status !== 405)
                    throw new Error('GitHub rules or the current source prevent the stable merge. Resume this workflow after resolving the restriction: ' + url);
            }
            if (result?.merged === true && shaPattern.test(result.sha))
                return confirmedMerge({ root, intent, client, environment, refresh, pull, preparedSha,
                    tree: prepared.tree_sha, expectedSha: result.sha, base, wait });
        }
        if (attempt < 59) await wait(1000);
    }
    throw new Error('GitHub rules or required checks blocked the stable merge. The pull request remains open. Resume this workflow after resolving the restriction: ' + url);
}

export async function cleanupStablePromotion({ client, environment = process.env, intent, promotion }) {
    try {
        if (!promotion?.merged || !Number.isSafeInteger(promotion.pull_number)) return false;
        await authorizeStableActors({ client, environment });
        const branch = promotionBranch(intent);
        const current = await client.request('GET', '/git/ref/heads/' + branch);
        if (!current) return true;
        if (current.ref !== 'refs/heads/' + branch || current.object?.type !== 'commit'
                || current.object.sha !== promotion.prepared_sha) return false;
        const pull = checkPull(await client.request('GET', '/pulls/' + promotion.pull_number), intent,
            environment.GITHUB_REPOSITORY, promotion.prepared_sha);
        if (pull.state !== 'closed' || !pull.merged_at || pull.merge_commit_sha !== promotion.master_sha
                || environment.GITHUB_ACTIONS === 'true' && !sameRun(pull, environment)) return false;
        for (const query of ['head=' + encodeURIComponent(environment.GITHUB_REPOSITORY.split('/')[0] + ':' + branch),
            'base=' + encodeURIComponent(branch)]) {
            const open = await client.request('GET', '/pulls?state=open&' + query + '&per_page=100');
            if (!Array.isArray(open) || open.length) return false;
        }
        const latest = await client.request('GET', '/git/ref/heads/' + branch);
        if (latest?.object?.sha !== promotion.prepared_sha) return false;
        await client.request('DELETE', '/git/refs/heads/' + branch, undefined, 204);
        return true;
    } catch {
        return false;
    }
}
