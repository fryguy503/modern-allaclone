import assert from 'node:assert/strict';
import { mkdir, mkdtemp, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
    adaptMarkdown,
    allocateStableSlugs,
    buildCsv,
    decodeHistoricalText,
    discoverSourceFiles,
    extractRecords,
    loadConfig,
    loadPriorArchive,
    main,
    normalizeStructuredDocumentRecords,
    publishArtifactsAtomically,
    spawnJson,
    validatePriorArchive,
} from '../scripts/build-patch-history.mjs';

const HASH_A = 'a'.repeat(64);
const HASH_B = 'b'.repeat(64);
const HASH_C = 'c'.repeat(64);
const SHARED_URL = 'https://forums.everquest.com/index.php?threads/shared.123/';

function priorPatch(overrides = {}) {
    const patchDate = overrides.patch_date ?? '2020-01-01';
    const sequence = overrides.sequence ?? 1;
    return {
        patch_date: patchDate,
        sequence,
        slug: `${patchDate}-${sequence}`,
        id: `${patchDate}-${sequence}`,
        display_date: 'Game Update Notes: January 1, 2020',
        title: 'January 1, 2020',
        content_hash: HASH_A,
        source_urls: [],
        ...overrides,
    };
}

function priorArchive(patches) {
    return {
        schema_version: 1,
        record_count: patches.length,
        coverage: { patch_count: patches.length },
        patches,
    };
}

function incoming(overrides = {}) {
    return {
        patch_date: '2020-01-01',
        display_date: 'Game Update Notes: January 1, 2020',
        title: 'January 1, 2020',
        content_hash: HASH_A,
        source_urls: [],
        source_url: null,
        ...overrides,
    };
}

test('content-hash slug reuse obeys its config switch', () => {
    const prior = priorArchive([priorPatch()]);
    const reused = allocateStableSlugs([incoming()], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: true,
        reuse_by_canonical_url_and_date: false,
    });
    assert.equal(reused.records[0].slug, '2020-01-01-1');
    assert.equal(reused.report.reused, 1);

    const disabled = allocateStableSlugs([incoming()], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: false,
        reuse_by_canonical_url_and_date: false,
    });
    assert.equal(disabled.records[0].slug, '2020-01-01-2');
    assert.equal(disabled.report.reused, 0);
    assert.equal(disabled.report.assigned, 1);
});

test('canonical URL reuse obeys its config switch', () => {
    const prior = priorArchive([priorPatch({ source_urls: [SHARED_URL] })]);
    const record = incoming({ content_hash: HASH_C, source_urls: [SHARED_URL] });
    const reused = allocateStableSlugs([record], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: false,
        reuse_by_canonical_url_and_date: true,
    });
    assert.equal(reused.records[0].slug, '2020-01-01-1');
    assert.equal(reused.report.reused, 1);

    const disabled = allocateStableSlugs([incoming({ content_hash: HASH_C, source_urls: [SHARED_URL] })], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: false,
        reuse_by_canonical_url_and_date: false,
    });
    assert.equal(disabled.records[0].slug, '2020-01-01-2');
    assert.equal(disabled.report.reused, 0);
});

test('shared same-date URL is disambiguated by source title and never swapped', () => {
    const prior = priorArchive([
        priorPatch({ sequence: 1, content_hash: HASH_A, source_urls: [SHARED_URL] }),
        priorPatch({
            sequence: 2,
            content_hash: HASH_B,
            display_date: 'Hotfix Notes: January 1, 2020',
            source_urls: [SHARED_URL],
        }),
    ]);
    const allocation = allocateStableSlugs([
        incoming({ content_hash: HASH_C, display_date: 'Hotfix Notes: January 1, 2020', source_urls: [SHARED_URL] }),
    ], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: false,
        reuse_by_canonical_url_and_date: true,
    });
    assert.equal(allocation.records[0].slug, '2020-01-01-2');
    assert.deepEqual(allocation.report.ambiguities, []);
    assert.deepEqual(allocation.report.collisions, []);
});

test('shared same-date URL without a title match is reported as ambiguous', () => {
    const prior = priorArchive([
        priorPatch({ sequence: 1, content_hash: HASH_A, source_urls: [SHARED_URL] }),
        priorPatch({
            sequence: 2,
            content_hash: HASH_B,
            display_date: 'Hotfix Notes: January 1, 2020',
            source_urls: [SHARED_URL],
        }),
    ]);
    const allocation = allocateStableSlugs([
        incoming({ content_hash: HASH_C, display_date: 'Mystery Notes: January 1, 2020', source_urls: [SHARED_URL] }),
    ], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: false,
        reuse_by_canonical_url_and_date: true,
    });
    assert.equal(allocation.report.ambiguities.length, 1);
    assert.deepEqual(allocation.report.ambiguities[0].candidate_slugs, ['2020-01-01-1', '2020-01-01-2']);
});

test('attempting to reserve one prior slug twice is reported as a collision', () => {
    const prior = priorArchive([priorPatch()]);
    const allocation = allocateStableSlugs([
        incoming(),
        incoming({ display_date: 'Another January 1, 2020 record' }),
    ], prior, {
        enabled: true,
        reuse_by_content_hash_and_date: true,
        reuse_by_canonical_url_and_date: false,
    });
    assert.equal(allocation.report.collisions.length, 1);
    assert.equal(allocation.report.collisions[0].requested_slug, '2020-01-01-1');
});

test('prior archive semantic validation accepts a well-formed snapshot', () => {
    const archive = priorArchive([priorPatch()]);
    assert.equal(validatePriorArchive(archive), archive);
});

test('prior archive semantic validation rejects unsafe shapes', async t => {
    const cases = [
        ['schema', { ...priorArchive([]), schema_version: 2 }, /schema_version/],
        ['count type', { ...priorArchive([]), record_count: '0' }, /record_count/],
        ['nonzero empty', { ...priorArchive([]), record_count: 1 }, /non-empty/],
        ['count mismatch', { ...priorArchive([priorPatch()]), record_count: 2 }, /patches length/],
        ['calendar date', priorArchive([priorPatch({ patch_date: '2020-02-30', slug: '2020-02-30-1', id: '2020-02-30-1' })]), /calendar date/],
        ['slug', priorArchive([priorPatch({ slug: 'wrong' })]), /slug/],
        ['id', priorArchive([priorPatch({ id: 'wrong' })]), /id must equal slug/],
        ['hash', priorArchive([priorPatch({ content_hash: 'not-a-hash' })]), /content_hash/],
        ['duplicate slug', priorArchive([priorPatch(), priorPatch()]), /duplicate slug/],
        ['occurrence count', priorArchive([priorPatch({ occurrence_count: 2, source_occurrences: [{}] })]), /occurrence_count/],
    ];
    for (const [name, archive, pattern] of cases) {
        await t.test(name, () => assert.throws(() => validatePriorArchive(archive), pattern));
    }
});

test('missing default prior is a first build, but explicit missing prior fails', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-prior-test-'));
    const missing = path.join(directory, 'missing.json');
    try {
        assert.equal(await loadPriorArchive(missing, false), null);
        await assert.rejects(loadPriorArchive(missing, true), /Explicit prior archive does not exist/);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('site source overrides append after the built-in archival safety rules', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-config-test-'));
    const configPath = path.join(directory, 'override.json');
    try {
        await writeFile(configPath, JSON.stringify({
            schema_version: 1,
            source_overrides: [{ pattern: '(?:^|/)unverified/', admit_new_records: false }],
        }));
        const config = await loadConfig(configPath);
        assert.ok(config.source_overrides.length > 1);
        assert.equal(config.source_overrides.at(-1).admit_new_records, false);
        assert.equal(config.source_overrides.at(-1).expression.test('unverified/file.txt'), true);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('default discovery excludes generated JSON and CSV artifacts even when output equals source', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-discovery-test-'));
    const generated = [
        'everquest-patch-history.json',
        'everquest-patch-history.csv',
        'everquest-patch-suggestions.json',
        'everquest-patch-import-report.json',
    ];
    try {
        await Promise.all([
            ...generated.map(filename => writeFile(path.join(directory, filename), '{}')),
            writeFile(path.join(directory, 'future-patches.json'), '[]'),
        ]);
        const config = await loadConfig(path.resolve('scripts/patch-import.config.json'));
        const discovery = await discoverSourceFiles(directory, directory, config);
        assert.deepEqual(discovery.files.map(file => file.relativePath), ['future-patches.json']);
        assert.deepEqual(
            discovery.notices.filter(item => item.code === 'file_excluded').map(item => item.path).sort(),
            generated.sort(),
        );
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('structured content is opaque even when it contains dated heading syntax', () => {
    const content = [
        'The original structured record begins here.',
        'January 1, 1999',
        '------------------------------',
        'This embedded heading-looking text must remain content.',
    ].join('\n');
    const normalized = normalizeStructuredDocumentRecords([{
        item_index: 17,
        title: 'Game Update Notes: August 22, 2026',
        content,
        published: '2026-08-22T12:00:00Z',
        source_url: 'https://forums.everquest.com/index.php?threads/example.1/',
        excerpt: true,
        source_kind: 'xenforo_first_post',
    }], {
        relativePath: 'saved-thread.html',
        adapter: 'html',
        priority: 35,
        admitNewRecords: true,
        admitExcerpts: false,
    });
    assert.equal(normalized.records.length, 1);
    assert.equal(normalized.records[0].content, content);
    assert.equal(normalized.records[0].patch_date, '2026-08-22');
    assert.equal(normalized.records[0].display_date, 'Game Update Notes: August 22, 2026');
    assert.equal(normalized.records[0].source_offset, 0);
    assert.equal(normalized.records[0].source_item_index, 17);
    assert.equal(normalized.records[0].source_excerpt, true);
    assert.equal(normalized.records[0].admit_new_records, false);
    assert.equal(normalized.records[0].source_kind, 'xenforo_first_post');
    assert.equal(normalized.records[0].source_url, 'https://forums.everquest.com/index.php?threads/example.1/');
});

test('JSON CSV and TSV adapters flow through the importer with original indices', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-structured-test-'));
    const sourceDirectory = path.join(directory, 'sources');
    const outputDirectory = path.join(directory, 'output');
    const python = process.env.PATCH_IMPORT_PYTHON ?? (process.platform === 'win32' ? 'python' : 'python3');
    try {
        await mkdir(sourceDirectory, { recursive: true });
        await writeFile(path.join(sourceDirectory, 'future.json'), JSON.stringify({ records: [{
            name: 'August 20, 2026',
            body: [
                'A sufficiently long JSON integration note.',
                'January 1, 1999',
                '------------------------------',
                'This embedded dated heading remains opaque content.',
            ].join('\n'),
            date: '2026-08-20',
        }] }));
        await writeFile(
            path.join(sourceDirectory, 'future.csv'),
            'display_date,notes,patch_date\n"August 21, 2026",A sufficiently long CSV integration note.,2026-08-21\n',
        );
        await writeFile(
            path.join(sourceDirectory, 'future.tsv'),
            'title\tcontent\tpublished\nAugust 22, 2026\tA sufficiently long TSV integration note.\t2026-08-22\n',
        );
        await writeFile(
            path.join(sourceDirectory, 'future.html'),
            '<h1>August 23, 2026</h1><p>A sufficiently long generic HTML integration note.</p>',
        );
        const result = await main([
            sourceDirectory,
            outputDirectory,
            '--python', python,
            '--strict',
            '--dry-run',
        ]);
        assert.equal(result.archive.record_count, 4);
        assert.match(result.archive.patches[0].content, /January 1, 1999\n-{30}\nThis embedded dated heading remains opaque content\.$/);
        assert.deepEqual(result.archive.patches.map(record => record.source_occurrences[0].adapter), ['json', 'csv', 'tsv', 'html']);
        assert.deepEqual(result.archive.patches.map(record => record.source_occurrences[0].item_index), [1, 2, 2, null]);
        assert.deepEqual(
            result.archive.provenance.files.filter(file => ['json', 'csv', 'tsv'].includes(file.adapter)).map(file => [
                file.adapter,
                file.adapter_metadata.raw_item_count,
                file.adapter_metadata.accepted_item_count,
                file.adapter_metadata.rejected_item_count,
            ]),
            [['csv', 1, 1, 0], ['json', 1, 1, 0], ['tsv', 1, 1, 0]],
        );
        await assert.rejects(readFile(path.join(outputDirectory, 'everquest-patch-history.json')), error => error.code === 'ENOENT');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('public JSON and CSV exports round-trip with display labels intact', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-export-roundtrip-test-'));
    const sourceDirectory = path.join(directory, 'sources');
    const outputDirectory = path.join(directory, 'output');
    const python = process.env.PATCH_IMPORT_PYTHON ?? (process.platform === 'win32' ? 'python' : 'python3');
    const publicPatch = {
        id: '2026-08-20-1',
        slug: '2026-08-20-1',
        patch_date: '2026-08-20',
        effective_date: '2026-08-20',
        display_date: 'Game Update Notes: August 20, 2026',
        title: 'August 20, 2026',
        sequence: 1,
        year: 2026,
        month: 8,
        kind: 'live',
        era: 'EverQuest Live',
        expansion: 'Shattering of Ro',
        expansion_code: 'shattering-of-ro',
        categories: [],
        sections: [],
        change_count: 1,
        word_count: 7,
        summary: 'A sufficiently long exported patch body.',
        content: 'A sufficiently long exported patch body.',
        content_hash: HASH_A,
        source_files: [],
        source_titles: [],
        source_url: null,
        source_urls: [],
        occurrence_count: 0,
        source_occurrences: [],
        year_inferred: false,
    };
    try {
        await mkdir(sourceDirectory, { recursive: true });
        await writeFile(path.join(sourceDirectory, 'copied-patch-archive.json'), JSON.stringify({
            schema_version: 1,
            record_count: 1,
            patches: [publicPatch],
        }));
        await writeFile(path.join(sourceDirectory, 'copied-patch-archive.csv'), buildCsv([publicPatch]));

        const result = await main([
            sourceDirectory,
            outputDirectory,
            '--python', python,
            '--strict',
            '--dry-run',
        ]);

        assert.equal(result.archive.record_count, 1);
        assert.equal(result.archive.patches[0].display_date, publicPatch.display_date);
        assert.deepEqual(
            result.archive.patches[0].source_occurrences.map(item => item.adapter),
            ['csv', 'json'],
        );
        assert.equal(result.importReport.summary.rejected_occurrences, 0);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('unsafe structured and free-text source URLs never reach public exports or reports', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-url-redaction-test-'));
    const sourceDirectory = path.join(directory, 'sources');
    const outputDirectory = path.join(directory, 'output');
    const python = process.env.PATCH_IMPORT_PYTHON ?? (process.platform === 'win32' ? 'python' : 'python3');
    const structuredSecret = 'structured-secret';
    const textSecret = 'text-secret';
    const directlyNormalized = normalizeStructuredDocumentRecords([{
        title: 'August 19, 2026',
        content: 'A sufficiently long direct structured security note.',
        source_url: `https://archive-user:${structuredSecret}@example.com/private`,
    }], {
        relativePath: 'direct.rss',
        adapter: 'rss',
        priority: 15,
        admitNewRecords: true,
        admitExcerpts: false,
    });
    assert.equal(directlyNormalized.records[0].source_url, null);
    assert.equal(directlyNormalized.records[0].source_url_raw, null);
    assert.doesNotMatch(JSON.stringify(directlyNormalized.warnings), new RegExp(structuredSecret));
    try {
        await mkdir(sourceDirectory, { recursive: true });
        await writeFile(path.join(sourceDirectory, 'unsafe.json'), JSON.stringify({ records: [{
            title: 'August 20, 2026',
            content: 'A sufficiently long structured security note.',
            source_url: `https://archive-user:${structuredSecret}@example.com/private`,
        }] }));
        await writeFile(path.join(sourceDirectory, 'unsafe.txt'), [
            'August 21, 2026',
            `Source: https://archive-user:${textSecret}@example.com/private`,
            '------------------------------',
            'A sufficiently long free-text security note.',
        ].join('\n'));

        const result = await main([
            sourceDirectory,
            outputDirectory,
            '--python', python,
            '--dry-run',
        ]);
        const publicJson = JSON.stringify(result.archive);
        const publicCsv = buildCsv(result.archive.patches);
        const reportJson = JSON.stringify(result.importReport);

        for (const secret of [structuredSecret, textSecret]) {
            assert.doesNotMatch(publicJson, new RegExp(secret));
            assert.doesNotMatch(publicCsv, new RegExp(secret));
            assert.doesNotMatch(reportJson, new RegExp(secret));
        }
        assert.equal(result.archive.record_count, 2);
        for (const patch of result.archive.patches) {
            assert.equal(patch.source_url, null);
            assert.deepEqual(patch.source_urls, []);
            assert.equal(patch.source_occurrences[0].source_url, null);
            assert.equal(patch.source_occurrences[0].source_url_raw, null);
        }
        assert.equal(result.importReport.warnings.filter(item => item.code === 'unsafe_source_url').length, 2);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('ambiguous prior URL aborts main before any artifact is published', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-publish-test-'));
    const sourceDirectory = path.join(directory, 'sources');
    const outputDirectory = path.join(directory, 'output');
    const priorPath = path.join(directory, 'prior.json');
    await import('node:fs/promises').then(({ mkdir }) => mkdir(sourceDirectory, { recursive: true }));
    const prior = priorArchive([
        priorPatch({ sequence: 1, content_hash: HASH_A, source_urls: [SHARED_URL] }),
        priorPatch({ sequence: 2, content_hash: HASH_B, display_date: 'Hotfix Notes: January 1, 2020', source_urls: [SHARED_URL] }),
    ]);
    try {
        await writeFile(path.join(sourceDirectory, 'new-notes.txt'), [
            'Mystery Notes: January 1, 2020',
            `Source: ${SHARED_URL}`,
            '------------------------------',
            '',
            '- A completely changed update note for the ambiguity test.',
            '',
        ].join('\n'));
        await writeFile(priorPath, `${JSON.stringify(prior)}\n`);
        await assert.rejects(
            main([sourceDirectory, outputDirectory, '--prior-archive', priorPath]),
            /Stable slug allocation is unsafe: 0 collision\(s\) and 1 ambiguous prior match\(es\)/,
        );
        await assert.rejects(readFile(path.join(outputDirectory, 'everquest-patch-history.json')), error => error.code === 'ENOENT');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('historical text decoding recognizes UTF-16LE instead of emitting NUL-filled records', () => {
    const encoded = Buffer.concat([
        Buffer.from([0xff, 0xfe]),
        Buffer.from('August 20, 2026\r\n-----\r\nA complete note.', 'utf16le'),
    ]);
    const decoded = decodeHistoricalText(encoded);
    assert.equal(decoded.encoding, 'utf-16le');
    assert.equal(decoded.text, 'August 20, 2026\n-----\nA complete note.');
});

test('Markdown date headings support both setext styles without splitting non-date headings', () => {
    const markdown = [
        'August 20, 2026',
        '================',
        '',
        'The first complete update note belongs to the equals-style heading.',
        '',
        'August 21, 2026',
        '----------------',
        '',
        'The second complete update note belongs to the hyphen-style heading.',
        '',
        'Appendix',
        '========',
        'This non-date setext heading remains part of the second record.',
    ].join('\n');
    const adapted = adaptMarkdown(markdown, 'future-notes.md');
    const parsed = extractRecords(adapted, {
        relativePath: 'future-notes.md',
        adapter: 'markdown',
        priority: 30,
        admitNewRecords: true,
        admitExcerpts: false,
    });

    assert.deepEqual(parsed.records.map(record => record.patch_date), ['2026-08-20', '2026-08-21']);
    assert.equal(parsed.heading_candidates, 2);
    assert.equal(parsed.records[0].content, 'The first complete update note belongs to the equals-style heading.');
    assert.match(parsed.records[1].content, /Appendix\n========\nThis non-date setext heading remains part of the second record\.$/);
});

test('feed excerpts are provenance-only unless explicitly admitted', () => {
    const text = 'August 20, 2026 — Game Update Notes\n------------------------------\nA sufficiently complete-looking excerpt.';
    const context = {
        relativePath: 'official.rss',
        adapter: 'rss',
        priority: 15,
        admitNewRecords: true,
        admitExcerpts: false,
        recordsMetadata: [{ start: 0, excerpt: true, item_index: 0 }],
    };
    assert.equal(extractRecords(text, context).records[0].admit_new_records, false);
    assert.equal(extractRecords(text, { ...context, admitExcerpts: true }).records[0].admit_new_records, true);
});

test('atomic publication rejects colliding targets before writing', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-target-test-'));
    const target = path.join(directory, 'archive.json');
    try {
        await assert.rejects(publishArtifactsAtomically([
            { filename: target, content: 'one' },
            { filename: target, content: 'two' },
        ]), /Publication targets collide/);
        await assert.rejects(readFile(target), error => error.code === 'ENOENT');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('atomic publication replaces regular targets and cleans staging files', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-atomic-test-'));
    const first = path.join(directory, 'archive.json');
    const second = path.join(directory, 'archive.csv');
    try {
        await writeFile(first, 'old');
        await publishArtifactsAtomically([
            { filename: first, content: 'new-json' },
            { filename: second, content: 'new-csv' },
        ]);
        assert.equal(await readFile(first, 'utf8'), 'new-json');
        assert.equal(await readFile(second, 'utf8'), 'new-csv');
        assert.deepEqual((await readdir(directory)).sort(), ['archive.csv', 'archive.json']);
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('failed rollback retains its recovery backup instead of deleting it', async () => {
    const directory = await mkdtemp(path.join(os.tmpdir(), 'patch-publish-rollback-test-'));
    const first = path.join(directory, 'archive.json');
    const second = path.join(directory, 'archive.csv');
    try {
        await writeFile(first, 'old-json');
        await writeFile(second, 'old-csv');
        let renameCall = 0;
        const injectedRename = async (source, target) => {
            renameCall += 1;
            if (renameCall === 2) throw new Error('simulated publication failure');
            if (renameCall === 3) throw new Error('simulated rollback failure');
            return rename(source, target);
        };

        await assert.rejects(
            publishArtifactsAtomically([
                { filename: first, content: 'new-json' },
                { filename: second, content: 'new-csv' },
            ], { rename: injectedRename }),
            error => /rollback was incomplete/.test(error.message)
                && /recovery backup retained at/.test(error.message),
        );

        const remaining = await readdir(directory);
        const recoveryBackups = remaining.filter(filename => filename.endsWith('.bak'));
        assert.equal(recoveryBackups.length, 1);
        assert.equal(await readFile(path.join(directory, recoveryBackups[0]), 'utf8'), 'old-json');
        assert.equal(await readFile(second, 'utf8'), 'old-csv');
    } finally {
        await rm(directory, { recursive: true, force: true });
    }
});

test('document subprocess has combined output and wall-clock limits', async () => {
    const tooLarge = await spawnJson(process.execPath, ['-e', 'process.stdout.write("x".repeat(4096))'], 128, 5_000);
    assert.equal(tooLarge.code, 'adapter_output_too_large');

    const timedOut = await spawnJson(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], 1024, 100);
    assert.equal(timedOut.code, 'adapter_timeout');
});
