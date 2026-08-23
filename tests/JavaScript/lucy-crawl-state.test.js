import assert from 'node:assert/strict';
import {
    mkdtemp,
    mkdir,
    readFile,
    rm,
    symlink,
    utimes,
    writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';

import {
    CrawlWorkspace,
    atomicWriteJson,
    calculateBackoff,
    parseRetryAfter,
    readAtomicJson,
} from '../../scripts/lib/lucy-crawl-state.mjs';

const HOUR = 60 * 60 * 1_000;
const DAY = 24 * HOUR;

async function temporaryWorkspace(run) {
    const root = await mkdtemp(join(tmpdir(), 'lucy-crawl-state-'));
    try {
        return await run(root);
    } finally {
        await rm(root, { recursive: true, force: true });
    }
}

function fakeTime(start = Date.UTC(2026, 7, 23, 12, 0, 0)) {
    let now = start;

    return {
        clock: () => now,
        sleep: async (milliseconds) => {
            now += milliseconds;
        },
        advance: (milliseconds) => {
            now += milliseconds;
        },
        set: (timestamp) => {
            now = timestamp;
        },
        value: () => now,
    };
}

function workspaceOptions(time, overrides = {}) {
    return {
        clock: time.clock,
        sleep: time.sleep,
        heartbeatIntervalMs: HOUR,
        hostname: 'test-host',
        ...overrides,
    };
}

test('atomic JSON publication replaces values and recovers a valid backup', async () => {
    await temporaryWorkspace(async (root) => {
        const path = join(root, 'state', 'value.json');
        await atomicWriteJson(path, { generation: 1 }, { root });
        await atomicWriteJson(path, { generation: 2, text: 'safe' }, { root });
        assert.deepEqual(await readAtomicJson(path, { root }), { generation: 2, text: 'safe' });

        await writeFile(path, '{truncated', 'utf8');
        await writeFile(`${path}.bak`, '{"generation":1}\n', 'utf8');
        assert.deepEqual(await readAtomicJson(path, { root }), { generation: 1 });
        await atomicWriteJson(path, { generation: 3 }, { root });
        assert.deepEqual(await readAtomicJson(path, { root }), { generation: 3 });

        await rm(path);
        await writeFile(`${path}.bak`, '{"generation":3}\n', 'utf8');
        assert.deepEqual(await readAtomicJson(path, { root }), { generation: 3 });
        await atomicWriteJson(path, { generation: 4 }, { root });
        assert.deepEqual(await readAtomicJson(path, { root }), { generation: 4 });
    });
});

test('root-scoped JSON publication rejects a redirected parent outside the workspace', async () => {
    await temporaryWorkspace(async (root) => {
        const workspace = join(root, 'workspace');
        const outside = join(root, 'outside');
        await mkdir(workspace);
        await mkdir(outside);
        await symlink(outside, join(workspace, 'work'), process.platform === 'win32' ? 'junction' : 'dir');

        await assert.rejects(
            atomicWriteJson(join(workspace, 'work', 'item.json'), { unsafe: true }, {
                root: workspace,
                label: 'item work state',
            }),
            /real directories|unsafe|symbolic|junction/i,
        );
        await assert.rejects(readFile(join(outside, 'item.json')), { code: 'ENOENT' });
    });
});

test('workspace initialization rejects a junction above or inside the workspace root', async () => {
    await temporaryWorkspace(async (root) => {
        const actual = join(root, 'actual');
        const alias = join(root, 'alias');
        await mkdir(actual);
        await symlink(actual, alias, process.platform === 'win32' ? 'junction' : 'dir');

        const throughAncestorLink = new CrawlWorkspace(join(alias, 'crawl'));
        await assert.rejects(
            throughAncestorLink.initialize(),
            /real directories|unsafe|symbolic|junction/i,
        );

        const workspace = join(root, 'workspace');
        const outside = join(root, 'outside');
        await mkdir(workspace);
        await mkdir(outside);
        await symlink(outside, join(workspace, 'state'), process.platform === 'win32' ? 'junction' : 'dir');
        await assert.rejects(
            new CrawlWorkspace(workspace).initialize(),
            /real directories|unsafe|symbolic|junction/i,
        );
    });
});

test('a crawl lock excludes another worker and heartbeat ownership is durable', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const first = new CrawlWorkspace(root, workspaceOptions(time));
        const second = new CrawlWorkspace(root, workspaceOptions(time));

        const acquired = await first.acquireLock({ runId: 'first-run' });
        assert.equal(acquired.acquired, true);
        const refused = await second.acquireLock({ runId: 'second-run' });
        assert.equal(refused.acquired, false);
        assert.equal(refused.reason, 'active');
        assert.equal(refused.owner.runId, 'first-run');

        time.advance(5_000);
        const heartbeat = await first.heartbeat();
        assert.equal(heartbeat.heartbeatAt, new Date(time.value()).toISOString());
        assert.equal((await first.readStatus()).heartbeatAt, heartbeat.heartbeatAt);

        assert.equal(await first.releaseLock(), true);
        const acquiredAfterRelease = await second.acquireLock({ runId: 'second-run' });
        assert.equal(acquiredAfterRelease.acquired, true);
        await second.releaseLock();
    });
});

test('a stale same-host lock can be recovered only after its process is gone', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const abandoned = new CrawlWorkspace(root, workspaceOptions(time));
        assert.equal((await abandoned.acquireLock({ runId: 'abandoned' })).acquired, true);

        time.advance(121_000);
        const cautious = new CrawlWorkspace(root, workspaceOptions(time, {
            isProcessAlive: () => true,
        }));
        const live = await cautious.acquireLock({ runId: 'cautious' });
        assert.equal(live.acquired, false);
        assert.equal(live.reason, 'stale-heartbeat-live-process');

        const replacement = new CrawlWorkspace(root, workspaceOptions(time, {
            isProcessAlive: () => false,
        }));
        const recovered = await replacement.acquireLock({ runId: 'replacement' });
        assert.equal(recovered.acquired, true);
        assert.equal(recovered.owner.runId, 'replacement');
        await replacement.releaseLock();
    });
});

test('an ownerless crash-window lock is reclaimed only after a conservative stale age', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const workspace = new CrawlWorkspace(root, workspaceOptions(time));
        await workspace.initialize();
        const lockPath = join(root, 'state', 'crawler.lock');
        await mkdir(lockPath);
        const currentTime = new Date(time.value());
        await utimes(lockPath, currentTime, currentTime);

        const fresh = await workspace.acquireLock({ runId: 'fresh-ownerless' });
        assert.equal(fresh.acquired, false);
        assert.equal(fresh.reason, 'lock-initializing');

        const staleTime = new Date(time.value() - 121_000);
        await utimes(lockPath, staleTime, staleTime);
        const recovered = await workspace.acquireLock({ runId: 'recovered-ownerless' });
        assert.equal(recovered.acquired, true);
        assert.equal(recovered.owner.runId, 'recovered-ownerless');
        await workspace.releaseLock();
    });
});

test('pause, resume, and stop controls persist across workspace instances', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const writer = new CrawlWorkspace(root, workspaceOptions(time));
        const reader = new CrawlWorkspace(root, workspaceOptions(time));

        assert.equal((await reader.readControl()).command, 'run');
        await writer.setControl('pause', { reason: 'maintenance', requestedBy: 'test' });
        assert.deepEqual(
            await reader.readControl(),
            {
                schema: 'modern-allaclone.lucy-item-crawl-control',
                version: 1,
                command: 'pause',
                reason: 'maintenance',
                requestedBy: 'test',
                requestedAt: new Date(time.value()).toISOString(),
            },
        );

        await reader.setControl('stop', { reason: 'operator request' });
        assert.equal((await writer.readControl()).command, 'stop');
        await writer.setControl('resume');
        assert.equal((await reader.readControl()).command, 'run');
    });
});

test('the start-to-start rate reservation and daily count survive a restart', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const options = workspaceOptions(time, {
            minIntervalMs: 20_000,
            jitterMs: 5_000,
            random: () => 0.5,
        });
        const first = new CrawlWorkspace(root, options);
        await first.acquireLock({ runId: 'first' });

        const firstSlot = await first.waitForRateSlot();
        assert.equal(firstSlot.granted, true);
        assert.equal(firstSlot.jitterMs, 2_500);
        assert.equal(firstSlot.requestsToday, 1);
        const firstStart = Date.parse(firstSlot.startedAt);
        await first.releaseLock();

        // No result was recorded. The conservative reservation still persists.
        const second = new CrawlWorkspace(root, options);
        await second.acquireLock({ runId: 'second' });
        const waits = [];
        const secondSlot = await second.waitForRateSlot({
            onWait: (wait) => waits.push(wait),
        });
        assert.equal(secondSlot.granted, true);
        assert.equal(Date.parse(secondSlot.startedAt) - firstStart, 22_500);
        assert.equal(secondSlot.requestsToday, 2);
        assert.equal(waits[0].reason, 'rate-limit');

        await second.recordRequest({
            statusCode: 200,
            durationMs: 125,
            bytes: 2_048,
            url: 'https://lucy.allakhazam.com/itemhistory.html?id=20542',
        });
        const status = await second.readStatus();
        assert.equal(status.metrics.requestStarts, 2);
        assert.equal(status.metrics.requestResults, 1);
        assert.equal(status.metrics.successful, 1);
        assert.equal(status.metrics.responseBytes, 2_048);
        await second.releaseLock();
    });
});

test('the daily request cap blocks until the next UTC day and then resets', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime(Date.UTC(2026, 7, 23, 23, 59, 0));
        const workspace = new CrawlWorkspace(root, workspaceOptions(time, {
            minIntervalMs: 0,
            jitterMs: 0,
            dailyCap: 2,
            waitPollMs: DAY,
        }));
        await workspace.acquireLock({ runId: 'daily-cap' });

        assert.equal((await workspace.waitForRateSlot()).requestsToday, 1);
        assert.equal((await workspace.waitForRateSlot()).requestsToday, 2);
        const waits = [];
        const nextDay = await workspace.waitForRateSlot({
            onWait: (wait) => waits.push(wait),
        });
        assert.equal(waits[0].reason, 'daily-cap');
        assert.equal(nextDay.startedAt, '2026-08-24T00:00:00.000Z');
        assert.equal(nextDay.requestsToday, 1);
        await workspace.releaseLock();
    });
});

test('a backwards clock correction cannot reset the persisted daily budget', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime(Date.UTC(2026, 7, 24, 0, 0, 10));
        const workspace = new CrawlWorkspace(root, workspaceOptions(time, {
            minIntervalMs: 0,
            jitterMs: 0,
            dailyCap: 1,
        }));
        await workspace.acquireLock({ runId: 'clock-correction' });
        assert.equal((await workspace.waitForRateSlot()).requestsToday, 1);

        time.set(Date.UTC(2026, 7, 23, 23, 59, 50));
        const blocked = await workspace.waitForRateSlot({ maxWaitMs: 0 });
        assert.equal(blocked.granted, false);
        assert.equal(blocked.blockedBy, 'daily-cap');
        assert.equal(blocked.requestsToday, 1);
        await workspace.releaseLock();
    });
});

test('control requests prevent a rate reservation until explicitly resumed', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const workspace = new CrawlWorkspace(root, workspaceOptions(time));
        await workspace.acquireLock({ runId: 'controls' });

        await workspace.setControl('pause', { reason: 'operator check' });
        assert.deepEqual(
            await workspace.waitForRateSlot(),
            {
                granted: false,
                reason: 'paused',
                control: await workspace.readControl(),
            },
        );

        await workspace.setControl('stop');
        assert.equal((await workspace.waitForRateSlot()).reason, 'stopped');
        await workspace.setControl('resume');
        assert.equal((await workspace.waitForRateSlot()).granted, true);
        await workspace.releaseLock();
    });
});

test('a persisted defer time extends, but never shortens, the rate gate', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const workspace = new CrawlWorkspace(root, workspaceOptions(time, {
            minIntervalMs: 20_000,
            jitterMs: 0,
        }));
        await workspace.acquireLock({ runId: 'backoff' });
        await workspace.waitForRateSlot();

        const deferredUntil = time.value() + 90_000;
        assert.equal(
            await workspace.deferRequestsUntil(deferredUntil, { reason: 'retry-after' }),
            new Date(deferredUntil).toISOString(),
        );
        await workspace.deferRequestsUntil(time.value() + 30_000, { reason: 'shorter' });
        const blocked = await workspace.waitForRateSlot({ maxWaitMs: 0 });
        assert.equal(blocked.granted, false);
        assert.equal(blocked.blockedBy, 'rate-limit');
        assert.equal(blocked.nextRequestNotBefore, new Date(deferredUntil).toISOString());
        await workspace.releaseLock();
    });
});

test('Retry-After and exponential backoff helpers are conservative', () => {
    const now = Date.UTC(2026, 7, 23, 12, 0, 0);
    assert.equal(parseRetryAfter('120', now), 120_000);
    assert.equal(
        parseRetryAfter(new Date(now + 65_000).toUTCString(), now),
        65_000,
    );
    assert.equal(parseRetryAfter('not-a-delay', now), null);

    assert.equal(calculateBackoff({
        attempt: 3,
        baseMs: 1_000,
        maxMs: 10_000,
        jitterMs: 100,
        random: () => 0.5,
        nowMs: now,
    }), 4_050);
    assert.equal(calculateBackoff({
        attempt: 1,
        baseMs: 1_000,
        maxMs: 5_000,
        jitterMs: 0,
        retryAfter: '20',
        nowMs: now,
    }), 20_000);
});

test('status patches merge nested progress without losing counters', async () => {
    await temporaryWorkspace(async (root) => {
        const time = fakeTime();
        const workspace = new CrawlWorkspace(root, workspaceOptions(time));
        await workspace.updateStatus({
            state: 'running',
            progress: { itemsCompleted: 10, itemsFailed: 1 },
        });
        await workspace.updateStatus({
            progress: { revisionsFetched: 25 },
        });

        const status = await workspace.readStatus();
        assert.equal(status.state, 'running');
        assert.deepEqual(status.progress, {
            itemsCompleted: 10,
            itemsFailed: 1,
            revisionsFetched: 25,
        });
    });
});
