import assert from 'node:assert/strict';
import {
    mkdir,
    mkdtemp,
    readFile,
    rm,
    symlink,
    writeFile,
} from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { basename, dirname, join } from 'node:path';
import { afterEach, test } from 'node:test';
import { gunzipSync, gzipSync } from 'node:zlib';

import { CrawlWorkspace, atomicWriteJson, readAtomicJson } from '../../scripts/lib/lucy-crawl-state.mjs';
import { sha256Hex } from '../../scripts/lib/lucy-item-history-parser.mjs';
import {
    assertAcceptableLucyHtml,
    buildLucyCookieHeader,
    calculateCrawlerRetryBackoff,
    cookieBootstrapTarget,
    publishContentAddressedCapture,
    readVerifiedContentAddressedCapture,
    runCrawlerCli,
} from '../../scripts/lucy-item-history-crawler.mjs';

const fixtures = join(import.meta.dirname, 'fixtures', 'lucy-item-history');
const temporaryDirectories = [];

afterEach(async () => {
    await Promise.all(temporaryDirectories.splice(0).map((path) => rm(path, {
        recursive: true,
        force: true,
    })));
});

async function runCrawler(arguments_) {
    const originalLog = console.log;
    console.log = () => {};
    try {
        return await runCrawlerCli(arguments_);
    } finally {
        console.log = originalLog;
    }
}

function historyPage(itemId, name, revisions) {
    const rows = revisions.map((revision) => `<tr>
        <td><a href="item.html?entryid=${revision.entryId}">${revision.source ?? 'Live'}</a></td>
        <td><a href="item.html?entryid=${revision.entryId}">${revision.observedAt}</a></td>
        <td>${revision.change}</td>
    </tr>`).join('\n');

    return Buffer.from(`<!doctype html><html><head><title>Item History for ${name}</title></head>
        <body><a href="item.html?id=${itemId}">Detail</a><table>
        <tr><th>Source</th><th>Date</th><th>Change</th></tr>
        ${rows}</table></body></html>`, 'ascii');
}

test('init and status are local-only and enforce the approved production rate bounds', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-init-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');

    await assert.rejects(
        runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--min-interval-ms=1999',
        ]),
        (error) => {
            assert.match(error.message, /min-interval-ms must be an integer from 2000/);
            return true;
        },
    );

    await assert.rejects(
        runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--daily-cap=100001',
        ]),
        (error) => {
            assert.match(error.message, /daily-cap must be an integer from 1 through 100000/);
            return true;
        },
    );

    await assert.rejects(
        runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--capture-strategy=fan-out',
        ]),
        (error) => error.code === 'invalid_option'
            && /direct-detail, reversible-delta/.test(error.message),
    );

    await runCrawler([
        'init',
        `--workspace=${workspace}`,
        `--artifact-root=${artifactRoot}`,
        '--contact=test@example.invalid',
        '--authorization-ref=fixture-authorization',
        '--min-interval-ms=2000',
        '--daily-cap=100000',
    ]);
    const status = await readAtomicJson(join(workspace, 'state', 'status.json'));
    const config = await readAtomicJson(join(workspace, 'config.json'));
    assert.equal(status.state, 'ready');
    assert.equal(status.metrics.requestStarts, 0);
    assert.equal(status.policy.captureStrategy, 'direct-detail');
    assert.equal(config.capture_strategy, 'direct-detail');
    assert.equal(config.min_interval_ms, 2000);
    assert.equal(config.daily_cap, 100000);

    delete config.capture_strategy;
    await atomicWriteJson(join(workspace, 'config.json'), config);

    await atomicWriteJson(join(workspace, 'state', 'progress.json'), {
        schema: 'modern-allaclone.lucy-item-crawl-progress',
        version: 1,
        total_items: 1,
        primary_cursor: 2,
        retry_ids: [],
        retry_cursor: 0,
        completed_items: 0,
        failed_items: 0,
        current_item_id: null,
        sweep_generation: 1,
        refresh_item_list: false,
    });
    await assert.rejects(
        runCrawler(['run', `--workspace=${workspace}`]),
        (error) => error.code === 'invalid_progress',
    );
    const failedStatus = await readAtomicJson(join(workspace, 'state', 'status.json'));
    assert.equal(failedStatus.metrics.requestStarts, 0);
    assert.equal(failedStatus.policy.captureStrategy, 'direct-detail');
});

test('init rejects a non-Lucy production origin and an artifact junction without network access', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-paths-'));
    temporaryDirectories.push(root);

    await assert.rejects(
        runCrawler([
            'init',
            `--workspace=${join(root, 'wrong-origin-work')}`,
            `--artifact-root=${join(root, 'wrong-origin-artifacts')}`,
            '--base-url=https://example.invalid/',
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
        ]),
        (error) => error.code === 'invalid_base_url',
    );

    const actual = join(root, 'actual-artifact-parent');
    const alias = join(root, 'artifact-parent-alias');
    await mkdir(actual);
    await symlink(actual, alias, process.platform === 'win32' ? 'junction' : 'dir');
    await assert.rejects(
        runCrawler([
            'init',
            `--workspace=${join(root, 'junction-work')}`,
            `--artifact-root=${join(alias, 'artifacts')}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
        ]),
        (error) => error.code === 'unsafe_path',
    );

    const workspace = join(root, 'swap-work');
    const artifactRoot = join(root, 'swap-artifacts');
    const redirectedArtifacts = join(root, 'redirected-artifacts');
    await runCrawler([
        'init',
        `--workspace=${workspace}`,
        `--artifact-root=${artifactRoot}`,
        '--contact=test@example.invalid',
        '--authorization-ref=fixture-authorization',
    ]);
    await rm(artifactRoot, { recursive: true });
    await mkdir(redirectedArtifacts);
    await symlink(
        redirectedArtifacts,
        artifactRoot,
        process.platform === 'win32' ? 'junction' : 'dir',
    );
    await assert.rejects(
        runCrawler(['status', `--workspace=${workspace}`]),
        (error) => error.code === 'unsafe_path',
    );

    const errorWorkspace = join(root, 'error-work');
    await runCrawler([
        'init',
        `--workspace=${errorWorkspace}`,
        `--artifact-root=${join(root, 'error-artifacts')}`,
        '--contact=test@example.invalid',
        '--authorization-ref=fixture-authorization',
    ]);
    const outsideErrors = join(root, 'outside-errors');
    await mkdir(outsideErrors);
    await writeFile(join(outsideErrors, '20542.json'), '{}\n');
    await mkdir(join(errorWorkspace, 'errors', 'items'), { recursive: true });
    await symlink(
        outsideErrors,
        join(errorWorkspace, 'errors', 'items', 'dc'),
        process.platform === 'win32' ? 'junction' : 'dir',
    );
    await assert.rejects(
        runCrawler(['retry-failures', `--workspace=${errorWorkspace}`]),
        (error) => error.code === 'unsafe_output_path',
    );
});

test('challenge and Lucy server-error pages are rejected before capture', () => {
    const phrases = [
        'Checking your browser before accessing Lucy',
        'Attention Required',
        'Too Many Requests',
        'Just a moment...',
        'System error',
        'No value sent for required parameter id',
        'Trace begun at /home/lucy/lib/example.pm',
    ];
    for (const phrase of phrases) {
        assert.throws(
            () => assertAcceptableLucyHtml(
                Buffer.from(`<!doctype html><html><title>${phrase}</title><body>${phrase}</body></html>`),
                'text/html; charset=utf-8',
            ),
            (error) => error.code === 'challenge_or_error_page'
                && error.context.byte_length > 0
                && /^[a-f0-9]{64}$/.test(error.context.sha256)
                && error.context.body_preview.includes(phrase),
            phrase,
        );
    }

    assert.throws(
        () => assertAcceptableLucyHtml(Buffer.from('short non-HTML response'), 'text/plain'),
        (error) => error.code === 'unexpected_body'
            && error.context.content_type === 'text/plain'
            && error.context.body_preview === 'short non-HTML response',
    );
    const arrayBuffer = Uint8Array.from(Buffer.from('short non-HTML ArrayBuffer response')).buffer;
    assert.throws(
        () => assertAcceptableLucyHtml(arrayBuffer, 'text/plain'),
        (error) => error.code === 'unexpected_body'
            && /^[a-f0-9]{64}$/.test(error.context.sha256),
    );

    assert.match(
        assertAcceptableLucyHtml(
            Buffer.from('<!doctype html><html><title>Item History</title><table></table></html>'),
            'text/html; charset=utf-8',
        ),
        /Item History/,
    );

    const original = new URL('https://lucy.allakhazam.com/itemhistory.html?id=1001');
    const bootstrapBody = Buffer.from(
        '<head><meta HTTP-EQUIV="Refresh" CONTENT="0; URL=/itemhistory.html?id=1001&setcookie=1"></head>',
    );
    const target = cookieBootstrapTarget(
        bootstrapBody,
        'text/html',
        original,
        'https://lucy.allakhazam.com/',
    );
    assert.equal(target.href, 'https://lucy.allakhazam.com/itemhistory.html?id=1001&setcookie=1');
    assert.equal(
        cookieBootstrapTarget(bootstrapBody, 'text/html', target, 'https://lucy.allakhazam.com/').href,
        target.href,
        'a repeated bootstrap must remain recognizable so the caller can stop the loop',
    );
    assert.equal(cookieBootstrapTarget(
        Buffer.from('<head><meta HTTP-EQUIV="Refresh" CONTENT="0; URL=https://example.invalid/"></head>'),
        'text/html',
        original,
        'https://lucy.allakhazam.com/',
    ), null);
    assert.equal(cookieBootstrapTarget(
        Buffer.from('<head><meta HTTP-EQUIV="Refresh" CONTENT="0; URL=/itemhistory.html?id=1002&setcookie=1"></head>'),
        'text/html',
        original,
        'https://lucy.allakhazam.com/',
    ), null);
    assert.equal(cookieBootstrapTarget(
        Buffer.from('<head><meta HTTP-EQUIV="Refresh" CONTENT="0; URL=/item.html?id=1001&setcookie=1"></head>'),
        'text/html',
        original,
        'https://lucy.allakhazam.com/',
    ), null);

    assert.equal(buildLucyCookieHeader(new Map([['LucySession', 'fixture']])), 'LucySession=fixture');
    assert.throws(
        () => buildLucyCookieHeader(new Map([
            ['first', 'a'.repeat(4_096)],
            ['second', 'b'.repeat(4_096)],
        ])),
        (error) => error.code === 'cookie_header_too_large',
    );
});

test('HTTP 429 backoff is at least one hour and honors a longer Retry-After', () => {
    const nowMs = Date.parse('2026-08-23T12:00:00.000Z');
    assert.equal(calculateCrawlerRetryBackoff({
        statusCode: 429,
        attempt: 1,
        jitterMs: 0,
        nowMs,
        random: () => 0,
    }), 60 * 60 * 1_000);
    assert.equal(calculateCrawlerRetryBackoff({
        statusCode: 429,
        attempt: 1,
        retryAfter: '7200',
        jitterMs: 0,
        nowMs,
        random: () => 0,
    }), 2 * 60 * 60 * 1_000);
    assert.equal(calculateCrawlerRetryBackoff({
        statusCode: 503,
        attempt: 1,
        jitterMs: 0,
        nowMs,
        random: () => 0,
    }), 60_000);
});

test('the Lucy cookie bootstrap stays same-origin, consumes a normal request slot, and carries its cookie', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-cookie-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');
    const itemListPath = join(root, 'itemlist.csv');
    await writeFile(
        itemListPath,
        'id,name,lucylink\n20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542\n',
        'utf8',
    );

    const history = historyPage(20542, 'Singing Short Sword', [{
        entryId: 2156558,
        observedAt: '2020-02-02 13:45',
        change: 'Initial Entry',
    }]);
    const detail = await readFile(join(fixtures, 'itemdetail-2156558.html'));
    const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
    const requests = [];
    const server = createServer((request, response) => {
        requests.push({ url: request.url, cookie: request.headers.cookie ?? null });
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname === '/itemhistory.html' && !url.searchParams.has('setcookie')) {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end('<head><meta HTTP-EQUIV="Refresh" CONTENT="0; URL=/itemhistory.html?id=20542&setcookie=1"></head>');
            return;
        }
        if (url.pathname === '/itemhistory.html' && url.searchParams.get('setcookie') === '1') {
            response.writeHead(200, {
                'Content-Type': 'text/html',
                'Set-Cookie': 'LucySession=fixture-token; Path=/; Max-Age=3600; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly',
            });
            response.end(history);
            return;
        }
        if (url.pathname === '/item.html' && url.searchParams.get('entryid') === '2156558') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(detail);
            return;
        }
        if (url.pathname === '/itemraw.html'
            && url.searchParams.get('id') === '20542'
            && url.searchParams.get('source') === 'Live') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(raw);
            return;
        }
        response.writeHead(404, { 'Content-Type': 'text/plain' });
        response.end('missing fixture');
    });
    await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

    try {
        const address = server.address();
        await runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            `--base-url=http://127.0.0.1:${address.port}/`,
            `--item-list-file=${itemListPath}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--min-interval-ms=0',
            '--jitter-ms=0',
            '--daily-cap=10',
        ]);
        await runCrawler(['run', `--workspace=${workspace}`]);
    } finally {
        await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
            if (error) rejectPromise(error);
            else resolvePromise();
        }));
    }

    assert.deepEqual(requests.map(({ url }) => url), [
        '/itemhistory.html?id=20542',
        '/itemhistory.html?id=20542&setcookie=1',
        '/item.html?entryid=2156558',
        '/itemraw.html?id=20542&source=Live',
    ]);
    assert.equal(requests[0].cookie, null);
    assert.equal(requests[1].cookie, null);
    assert.equal(requests[2].cookie, 'LucySession=fixture-token');
    assert.equal(requests[3].cookie, 'LucySession=fixture-token');
    const status = await readAtomicJson(join(workspace, 'state', 'status.json'));
    assert.equal(status.metrics.requestStarts, 4);
    assert.equal(status.state, 'complete');
    const artifact = JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'), 'utf8'));
    assert.equal(artifact.complete, true);
});

test('content-addressed capture publication replaces a truncated final gzip and verifies reuse', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-cas-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'workspace');
    const payload = Buffer.from('<!doctype html><html><title>Historical item</title></html>', 'utf8');
    const digest = sha256Hex(payload);
    const target = join(workspace, 'raw', digest.slice(0, 2), `${digest}.detail.gz`);
    await mkdir(join(workspace, 'raw', digest.slice(0, 2)), { recursive: true });
    await writeFile(target, Buffer.from([0x1f, 0x8b, 0x08]));

    const recovered = await publishContentAddressedCapture({
        root: workspace,
        path: target,
        compressedBytes: gzipSync(payload, { level: 9 }),
        expectedSha256: digest,
        maxExpandedBytes: 1024,
    });
    assert.equal(recovered.reused, false);
    assert.equal(recovered.recoveredCorrupt, true);
    assert.deepEqual(gunzipSync(await readFile(target)), payload);

    const reused = await publishContentAddressedCapture({
        root: workspace,
        path: target,
        compressedBytes: gzipSync(payload, { level: 1 }),
        expectedSha256: digest,
        maxExpandedBytes: 1024,
    });
    assert.equal(reused.reused, true);
    assert.deepEqual(gunzipSync(await readFile(target)), payload);

    const compressed = await readFile(target);
    const outside = join(root, 'outside');
    await rm(join(workspace, 'raw'), { recursive: true });
    await mkdir(join(outside, digest.slice(0, 2)), { recursive: true });
    await writeFile(join(outside, digest.slice(0, 2), basename(target)), compressed);
    await symlink(outside, join(workspace, 'raw'), process.platform === 'win32' ? 'junction' : 'dir');
    await assert.rejects(
        readVerifiedContentAddressedCapture({
            root: workspace,
            path: target,
            expectedSha256: digest,
            maxExpandedBytes: 1024,
        }),
        (error) => error.code === 'unsafe_output_path',
    );

    await rm(join(workspace, 'raw'), { recursive: true });
    await mkdir(dirname(target), { recursive: true });
    const leafTarget = join(root, 'outside-leaf');
    await mkdir(leafTarget);
    await symlink(leafTarget, target, process.platform === 'win32' ? 'junction' : 'dir');
    await assert.rejects(
        readVerifiedContentAddressedCapture({
            root: workspace,
            path: target,
            expectedSha256: digest,
            maxExpandedBytes: 1024,
        }),
        (error) => error.code === 'unsafe_output_path',
    );
});

test('a loopback crawl resumes mid-item and a later sweep reuses immutable entry captures', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-e2e-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');
    const itemListPath = join(root, 'itemlist.csv');
    await writeFile(
        itemListPath,
        'id,name,lucylink\n20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542\n',
        'utf8',
    );

    const detail = await readFile(join(fixtures, 'itemdetail-2156558.html'));
    const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
    const history = Buffer.from(`<!doctype html><html><head><title>Item History for Singing Short Sword</title></head>
        <body><a href="item.html?id=20542">Detail</a><table>
        <tr><th>Source</th><th>Date</th><th>Change</th></tr>
        <tr><td><a href="item.html?entryid=20280">Live</a></td>
        <td><a href="item.html?entryid=20280">2002-12-22 18:35</a></td>
        <td>Initial Entry</td></tr></table></body></html>`, 'ascii');
    const newItemHistory = Buffer.from(
        history.toString('ascii')
            .replaceAll('20542', '20543')
            .replaceAll('20280', '20281')
            .replaceAll('Singing Short Sword', 'New Singing Short Sword'),
        'ascii',
    );
    const newItemDetail = Buffer.from(detail.toString('utf8').replaceAll('20542', '20543'));
    const newItemRaw = Buffer.from(raw.toString('utf8').replaceAll('20542', '20543'));
    const requests = [];
    let stopAfterHistory = true;
    let controlWorkspace = null;
    const server = createServer((request, response) => {
        requests.push({
            url: request.url,
            userAgent: request.headers['user-agent'],
        });
        const url = new URL(request.url, 'http://127.0.0.1');
        let body = null;
        if (url.pathname === '/itemhistory.html' && url.searchParams.get('id') === '20542') body = history;
        if (url.pathname === '/itemhistory.html' && url.searchParams.get('id') === '20543') body = newItemHistory;
        if (url.pathname === '/item.html' && url.searchParams.get('entryid') === '20280') body = detail;
        if (url.pathname === '/item.html' && url.searchParams.get('entryid') === '20281') body = newItemDetail;
        if (url.pathname === '/itemraw.html'
            && url.searchParams.get('id') === '20542'
            && url.searchParams.get('source') === 'Live') body = raw;
        if (url.pathname === '/itemraw.html'
            && url.searchParams.get('id') === '20543'
            && url.searchParams.get('source') === 'Live') body = newItemRaw;
        if (body === null) {
            response.writeHead(404, { 'Content-Type': 'text/plain' });
            response.end('missing fixture');
            return;
        }
        response.writeHead(200, { 'Content-Type': 'text/html' });
        if (url.pathname === '/itemhistory.html' && stopAfterHistory) {
            stopAfterHistory = false;
            void controlWorkspace.setControl('stop', {
                reason: 'fixture interruption after first capture',
                requestedBy: 'offline test',
            }).then(() => response.end(body));
            return;
        }
        response.end(body);
    });
    await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

    try {
        const address = server.address();
        const baseUrl = `http://127.0.0.1:${address.port}/`;
        await runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            `--base-url=${baseUrl}`,
            `--item-list-file=${itemListPath}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--min-interval-ms=0',
            '--jitter-ms=0',
            '--daily-cap=10',
        ]);
        assert.equal(requests.length, 0, 'init must not contact even the loopback fixture server');

        controlWorkspace = new CrawlWorkspace(workspace, {
            minIntervalMs: 0,
            jitterMs: 0,
            dailyCap: 10,
        });
        await runCrawler(['run', `--workspace=${workspace}`]);
        assert.deepEqual(requests.map((request) => request.url), ['/itemhistory.html?id=20542']);
        await assert.rejects(readFile(join(artifactRoot, 'items', 'dc', '20542.json')), { code: 'ENOENT' });

        await runCrawler(['resume', `--workspace=${workspace}`]);
        await runCrawler(['run', `--workspace=${workspace}`]);

        await writeFile(
            itemListPath,
            [
                'id,name,lucylink',
                '20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542',
                '20543,New Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20543',
                '',
            ].join('\n'),
            'utf8',
        );
        await runCrawler(['sweep', `--workspace=${workspace}`]);
        await runCrawler(['run', `--workspace=${workspace}`]);
    } finally {
        await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
            if (error) rejectPromise(error);
            else resolvePromise();
        }));
    }

    assert.deepEqual(requests.map((request) => request.url), [
        '/itemhistory.html?id=20542',
        '/item.html?entryid=20280',
        '/itemraw.html?id=20542&source=Live',
        '/itemhistory.html?id=20542',
        '/itemraw.html?id=20542&source=Live',
        '/itemhistory.html?id=20543',
        '/item.html?entryid=20281',
        '/itemraw.html?id=20543&source=Live',
    ]);
    assert.ok(requests.every((request) => request.userAgent.includes('ModernAllacloneAuthorizedItemHistoryCrawler/')));

    const artifact = JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'), 'utf8'));
    assert.equal(artifact.schema, 'modern-allaclone.item-history');
    assert.equal(artifact.format_version, 1);
    assert.equal(artifact.parser_format_version, 1);
    assert.equal(artifact.capture_strategy, undefined);
    assert.equal(artifact.item_id, 20542);
    assert.equal(artifact.complete, true);
    assert.deepEqual(artifact.gaps, []);
    assert.equal(artifact.revision_count, 1);
    assert.equal(artifact.revisions[0].entry_id, 20280);
    assert.equal(artifact.current_raw.Live.fields.id, '20542');
    const newItemShard = sha256Hex('20543').slice(0, 2);
    const newItemArtifact = JSON.parse(await readFile(
        join(artifactRoot, 'items', newItemShard, '20543.json'),
        'utf8',
    ));
    assert.equal(newItemArtifact.item_id, 20543);
    assert.equal(newItemArtifact.revisions[0].entry_id, 20281);

    const status = await readAtomicJson(join(workspace, 'state', 'status.json'));
    const progress = await readAtomicJson(join(workspace, 'state', 'progress.json'));
    assert.equal(status.state, 'complete');
    assert.equal(progress.total_items, 2);
    assert.equal(progress.primary_cursor, 2);
    assert.equal(progress.completed_items, 2);
    assert.equal(progress.sweep_generation, 2);
    assert.equal(status.metrics.requestStarts, 8);
});

test('reversible-delta resumes after history and publishes from one raw anchor without detail calls', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-delta-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');
    const itemListPath = join(root, 'itemlist.csv');
    await writeFile(
        itemListPath,
        'id,name,lucylink\n20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542\n',
        'utf8',
    );

    const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
    const history = historyPage(20542, 'Singing Short Sword', [
        {
            entryId: 20280,
            source: 'Live',
            observedAt: '2002-12-22 18:35',
            change: 'Initial Entry',
        },
        {
            entryId: 20281,
            source: 'Test',
            observedAt: '2002-12-22 18:36',
            change: 'Initial Entry',
        },
        {
            entryId: 2156558,
            source: 'Live',
            observedAt: '2020-06-12 13:36',
            change: "Changed idfile from 'IT148' to ''",
        },
        {
            entryId: 2156559,
            source: 'Test',
            observedAt: '2020-06-12 13:37',
            change: "Changed idfile from 'IT147' to 'IT148'",
        },
    ]);
    const requests = [];
    let stopAfterHistory = true;
    let controlWorkspace = null;
    const server = createServer((request, response) => {
        requests.push(request.url);
        const url = new URL(request.url, 'http://127.0.0.1');
        if (url.pathname === '/itemhistory.html') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            if (stopAfterHistory) {
                stopAfterHistory = false;
                void controlWorkspace.setControl('stop', {
                    reason: 'fixture interruption after delta history capture',
                    requestedBy: 'offline test',
                }).then(() => response.end(history));
                return;
            }
            response.end(history);
            return;
        }
        if (url.pathname === '/itemraw.html'
            && url.searchParams.get('id') === '20542'
            && url.searchParams.get('source') === 'Live') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            response.end(raw);
            return;
        }
        response.writeHead(500, { 'Content-Type': 'text/html' });
        response.end('<!doctype html><html><title>Unexpected fixture request</title></html>');
    });
    await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

    try {
        const address = server.address();
        await runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            `--base-url=http://127.0.0.1:${address.port}/`,
            `--item-list-file=${itemListPath}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--capture-strategy=reversible-delta',
            '--min-interval-ms=0',
            '--jitter-ms=0',
            '--daily-cap=10',
        ]);
        controlWorkspace = new CrawlWorkspace(workspace, {
            minIntervalMs: 0,
            jitterMs: 0,
            dailyCap: 10,
        });

        await runCrawler(['run', `--workspace=${workspace}`]);
        assert.deepEqual(requests, ['/itemhistory.html?id=20542']);
        await assert.rejects(readFile(join(artifactRoot, 'items', 'dc', '20542.json')), {
            code: 'ENOENT',
        });

        await runCrawler(['resume', `--workspace=${workspace}`]);
        await runCrawler(['run', `--workspace=${workspace}`]);
    } finally {
        await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
            if (error) rejectPromise(error);
            else resolvePromise();
        }));
    }

    assert.deepEqual(requests, [
        '/itemhistory.html?id=20542',
        '/itemraw.html?id=20542&source=Live',
    ]);
    assert.equal(requests.some((url) => url.startsWith('/item.html?entryid=')), false);

    const artifact = JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'), 'utf8'));
    assert.equal(artifact.format_version, 2);
    assert.equal(artifact.parser_format_version, 2);
    assert.equal(artifact.capture_strategy, 'reversible-delta-v1');
    assert.deepEqual(Object.keys(artifact.current_raw), ['Live']);
    assert.deepEqual(artifact.coverage, {
        history_rows: 'captured',
        current_raw: 'captured',
        historical_state: 'reconstructed',
        rendered_details: 'not-captured',
        direct_detail_count: 0,
    });
    assert.equal(artifact.evidence.current_raw_source, 'Live');
    assert.match(artifact.evidence.current_raw_capture_sha256, /^[a-f0-9]{64}$/);
    assert.equal(artifact.evidence.history_capture_sha256s.length, 1);
    assert.equal(artifact.reconstruction.algorithm, 'lucy-reversible-delta');
    assert.equal(artifact.reconstruction.sources.Live.status, 'chain-verified-anchored');
    assert.equal(artifact.reconstruction.sources.Test.status, 'chain-verified-unanchored');
    assert.ok(artifact.revisions.every((revision) =>
        revision.capture_sha256 === undefined
        && revision.history_capture_sha256s.length === 1));

    const status = await readAtomicJson(join(workspace, 'state', 'status.json'));
    assert.equal(status.state, 'complete');
    assert.equal(status.policy.captureStrategy, 'reversible-delta');
    assert.equal(status.metrics.requestStarts, 2);
});

test('a semantic missing-item page is quarantined without pausing the remaining queue', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-missing-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');
    const itemListPath = join(root, 'itemlist.csv');
    await writeFile(itemListPath, [
        'id,name,lucylink',
        '20541,Missing Item,https://lucy.allakhazam.com/item.html?id=20541',
        '20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542',
        '',
    ].join('\n'), 'utf8');

    const detail = await readFile(join(fixtures, 'itemdetail-2156558.html'));
    const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
    const history = Buffer.from(`<!doctype html><html><head><title>Item History for Singing Short Sword</title></head>
        <body><a href="item.html?id=20542">Detail</a><table>
        <tr><th>Source</th><th>Date</th><th>Change</th></tr>
        <tr><td><a href="item.html?entryid=20280">Live</a></td>
        <td><a href="item.html?entryid=20280">2002-12-22 18:35</a></td>
        <td>Initial Entry</td></tr></table></body></html>`, 'ascii');
    const missing = Buffer.from(
        '<!doctype html><html><head><title>Item History for Missing Item</title></head><body>Could not find item</body></html>',
    );
    const requests = [];
    const server = createServer((request, response) => {
        requests.push(request.url);
        const url = new URL(request.url, 'http://127.0.0.1');
        let body = null;
        if (url.pathname === '/itemhistory.html' && url.searchParams.get('id') === '20541') body = missing;
        if (url.pathname === '/itemhistory.html' && url.searchParams.get('id') === '20542') body = history;
        if (url.pathname === '/item.html' && url.searchParams.get('entryid') === '20280') body = detail;
        if (url.pathname === '/itemraw.html' && url.searchParams.get('id') === '20542') body = raw;
        response.writeHead(body === null ? 404 : 200, { 'Content-Type': 'text/html' });
        response.end(body ?? '<html><body>missing fixture</body></html>');
    });
    await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

    try {
        const address = server.address();
        await runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            `--base-url=http://127.0.0.1:${address.port}/`,
            `--item-list-file=${itemListPath}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--min-interval-ms=0',
            '--jitter-ms=0',
            '--daily-cap=10',
        ]);
        await runCrawler(['run', `--workspace=${workspace}`]);
    } finally {
        await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
            if (error) rejectPromise(error);
            else resolvePromise();
        }));
    }

    assert.deepEqual(requests, [
        '/itemhistory.html?id=20541',
        '/itemhistory.html?id=20542',
        '/item.html?entryid=20280',
        '/itemraw.html?id=20542&source=Live',
    ]);
    const status = await readAtomicJson(join(workspace, 'state', 'status.json'));
    const progress = await readAtomicJson(join(workspace, 'state', 'progress.json'));
    assert.equal(status.state, 'complete-with-gaps');
    assert.equal(progress.primary_cursor, 2);
    assert.equal(progress.failed_items, 1);
    const missingShard = sha256Hex('20541').slice(0, 2);
    const error = await readAtomicJson(join(workspace, 'errors', 'items', missingShard, '20541.json'));
    assert.equal(error.error.code, 'lucy_item_missing');
    assert.equal(error.resolved_at, null);
    assert.equal(JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'))).complete, true);
});

test('a quarantined semantic-missing detail is refetched by explicit retry and by a new sweep', async (t) => {
    for (const recovery of ['retry', 'sweep']) {
        await t.test(recovery, async () => {
            const root = await mkdtemp(join(tmpdir(), `lucy-item-crawler-detail-${recovery}-`));
            temporaryDirectories.push(root);
            const workspace = join(root, 'work');
            const artifactRoot = join(root, 'artifacts');
            const itemListPath = join(root, 'itemlist.csv');
            await writeFile(
                itemListPath,
                'id,name,lucylink\n20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542\n',
                'utf8',
            );

            const detail = await readFile(join(fixtures, 'itemdetail-2156558.html'));
            const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
            const history = historyPage(20542, 'Singing Short Sword', [{
                entryId: 2156558,
                observedAt: '2020-06-12 13:36',
                change: "Changed idfile from 'IT148' to ''",
            }]);
            const missingDetail = Buffer.from(
                '<!doctype html><html><head><title>Item Details for Singing Short Sword</title></head><body>Could not find item</body></html>',
            );
            const requests = [];
            let detailRequests = 0;
            const server = createServer((request, response) => {
                requests.push(request.url);
                const url = new URL(request.url, 'http://127.0.0.1');
                let body = null;
                if (url.pathname === '/itemhistory.html') body = history;
                if (url.pathname === '/item.html' && url.searchParams.get('entryid') === '2156558') {
                    detailRequests += 1;
                    body = detailRequests === 1 ? missingDetail : detail;
                }
                if (url.pathname === '/itemraw.html') body = raw;
                response.writeHead(body === null ? 404 : 200, { 'Content-Type': 'text/html' });
                response.end(body ?? '<html><body>missing fixture</body></html>');
            });
            await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

            try {
                const address = server.address();
                await runCrawler([
                    'init',
                    `--workspace=${workspace}`,
                    `--artifact-root=${artifactRoot}`,
                    `--base-url=http://127.0.0.1:${address.port}/`,
                    `--item-list-file=${itemListPath}`,
                    '--contact=test@example.invalid',
                    '--authorization-ref=fixture-authorization',
                    '--min-interval-ms=0',
                    '--jitter-ms=0',
                    '--daily-cap=20',
                ]);
                await runCrawler(['run', `--workspace=${workspace}`]);
                assert.equal((await readAtomicJson(join(workspace, 'state', 'status.json'))).state, 'complete-with-gaps');

                if (recovery === 'retry') {
                    await runCrawler(['retry-failures', `--workspace=${workspace}`]);
                } else {
                    await runCrawler(['sweep', `--workspace=${workspace}`]);
                }
                await runCrawler(['run', `--workspace=${workspace}`]);
            } finally {
                await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
                    if (error) rejectPromise(error);
                    else resolvePromise();
                }));
            }

            assert.equal(detailRequests, 2, `${recovery} must replace the quarantined detail capture`);
            assert.deepEqual(requests, recovery === 'retry'
                ? [
                    '/itemhistory.html?id=20542',
                    '/item.html?entryid=2156558',
                    '/item.html?entryid=2156558',
                    '/itemraw.html?id=20542&source=Live',
                ]
                : [
                    '/itemhistory.html?id=20542',
                    '/item.html?entryid=2156558',
                    '/itemhistory.html?id=20542',
                    '/item.html?entryid=2156558',
                    '/itemraw.html?id=20542&source=Live',
                ]);
            const artifact = JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'), 'utf8'));
            assert.equal(artifact.revision_count, 1);
            assert.equal(artifact.revisions[0].entry_id, 2156558);
            assert.equal((await readAtomicJson(join(workspace, 'state', 'status.json'))).state, 'complete');
        });
    }
});

test('a sweep preserves previously observed revisions omitted by the refreshed Lucy history page', async () => {
    const root = await mkdtemp(join(tmpdir(), 'lucy-item-crawler-monotonic-history-'));
    temporaryDirectories.push(root);
    const workspace = join(root, 'work');
    const artifactRoot = join(root, 'artifacts');
    const itemListPath = join(root, 'itemlist.csv');
    await writeFile(
        itemListPath,
        'id,name,lucylink\n20542,Singing Short Sword,https://lucy.allakhazam.com/item.html?id=20542\n',
        'utf8',
    );

    const detail = await readFile(join(fixtures, 'itemdetail-2156558.html'));
    const raw = await readFile(join(fixtures, 'itemraw-20542-live.html'));
    const initialHistory = historyPage(20542, 'Singing Short Sword', [
        {
            entryId: 20280,
            observedAt: '2002-12-22 18:35',
            change: 'Initial Entry',
        },
        {
            entryId: 2156558,
            observedAt: '2020-06-12 13:36',
            change: "Changed idfile from 'IT148' to ''",
        },
    ]);
    const refreshedHistory = historyPage(20542, 'Singing Short Sword', [{
        entryId: 2156558,
        observedAt: '2020-06-12 13:36',
        change: "Changed idfile from 'IT148' to ''",
    }]);
    let swept = false;
    const requests = [];
    const server = createServer((request, response) => {
        requests.push(request.url);
        const url = new URL(request.url, 'http://127.0.0.1');
        let body = null;
        if (url.pathname === '/itemhistory.html') body = swept ? refreshedHistory : initialHistory;
        if (url.pathname === '/item.html' && ['20280', '2156558'].includes(url.searchParams.get('entryid'))) {
            body = detail;
        }
        if (url.pathname === '/itemraw.html') body = raw;
        response.writeHead(body === null ? 404 : 200, { 'Content-Type': 'text/html' });
        response.end(body ?? '<html><body>missing fixture</body></html>');
    });
    await new Promise((resolvePromise) => server.listen(0, '127.0.0.1', resolvePromise));

    try {
        const address = server.address();
        await runCrawler([
            'init',
            `--workspace=${workspace}`,
            `--artifact-root=${artifactRoot}`,
            `--base-url=http://127.0.0.1:${address.port}/`,
            `--item-list-file=${itemListPath}`,
            '--contact=test@example.invalid',
            '--authorization-ref=fixture-authorization',
            '--min-interval-ms=0',
            '--jitter-ms=0',
            '--daily-cap=20',
        ]);
        await runCrawler(['run', `--workspace=${workspace}`]);
        swept = true;
        await runCrawler(['sweep', `--workspace=${workspace}`]);
        await runCrawler(['run', `--workspace=${workspace}`]);
    } finally {
        await new Promise((resolvePromise, rejectPromise) => server.close((error) => {
            if (error) rejectPromise(error);
            else resolvePromise();
        }));
    }

    assert.deepEqual(requests, [
        '/itemhistory.html?id=20542',
        '/item.html?entryid=20280',
        '/item.html?entryid=2156558',
        '/itemraw.html?id=20542&source=Live',
        '/itemhistory.html?id=20542',
        '/itemraw.html?id=20542&source=Live',
    ]);
    const artifact = JSON.parse(await readFile(join(artifactRoot, 'items', 'dc', '20542.json'), 'utf8'));
    assert.equal(artifact.revision_count, 2);
    assert.deepEqual(artifact.revisions.map((revision) => revision.entry_id), [20280, 2156558]);
    assert.equal(artifact.history_capture_sha256s.length, 2);
    assert.equal(artifact.revisions[0].history_capture_sha256s.length, 1);
    assert.equal(artifact.revisions[1].history_capture_sha256s.length, 2);
});
