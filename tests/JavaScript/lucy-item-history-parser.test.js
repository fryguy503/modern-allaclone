import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { describe, test } from 'node:test';
import { fileURLToPath } from 'node:url';

import {
    LUCY_ITEM_HISTORY_FORMAT_VERSION,
    LUCY_ITEM_HISTORY_PARSER_FORMAT_VERSION,
    LUCY_ITEM_HISTORY_SCHEMA,
    LucyParseError,
    buildItemHistoryArtifact,
    canonicalJson,
    decodeLucyHtml,
    itemHistoryRelativePath,
    parseItemDetailPage,
    parseItemHistoryPage,
    parseItemList,
    parseItemRawPage,
    sha256Hex,
} from '../../scripts/lib/lucy-item-history-parser.mjs';

const fixtureRoot = join(dirname(fileURLToPath(import.meta.url)), 'fixtures', 'lucy-item-history');

async function fixture(name) {
    return readFile(join(fixtureRoot, name), 'utf8');
}

function expectParseError(action, code) {
    assert.throws(action, (error) => {
        assert.ok(error instanceof LucyParseError);
        assert.equal(error.code, code);
        return true;
    });
}

describe('Lucy daily item-list parser', () => {
    test('parses RFC-style CSV without treating duplicate names as duplicate items', async () => {
        const parsed = parseItemList(await fixture('itemlist.csv'));

        assert.equal(parsed.row_count, 4);
        assert.deepEqual(parsed.headers, ['id', 'name', 'lucylink', 'kind']);
        assert.deepEqual(parsed.items.map((item) => item.id), [2743, 20542, 127913, 130000]);
        assert.equal(parsed.items[0].name, 'Talisen, Bow of the Trailblazer');
        assert.equal(parsed.items[2].name, 'Singing "Short" Sword Ornamentation');
        assert.equal(parsed.items[3].name, 'A two-line\nitem name');
        assert.deepEqual(parsed.items[1].extra, { kind: 'weapon' });
    });

    test('fails closed for duplicate ids, mismatched links, and malformed quoted records', () => {
        expectParseError(
            () => parseItemList('id,name,lucylink\n1,One,https://lucy.allakhazam.com/item.html?id=1\n1,Again,https://lucy.allakhazam.com/item.html?id=1\n'),
            'item_list_duplicate_id',
        );
        expectParseError(
            () => parseItemList('id,name,lucylink\n1,One,https://lucy.allakhazam.com/item.html?id=2\n'),
            'item_list_link',
        );
        expectParseError(
            () => parseItemList('id,name,lucylink\n1,"unterminated,https://lucy.allakhazam.com/item.html?id=1'),
            'item_list_csv',
        );
    });

    test('accepts multiple trailing blank records while rejecting an interior blank record', () => {
        const item = '1,One,https://lucy.allakhazam.com/item.html?id=1';
        const parsed = parseItemList(`id,name,lucylink\n${item}\n\n\n`);
        const parsedCrlf = parseItemList(`id,name,lucylink\r\n${item}\r\n\r\n`);

        assert.equal(parsed.row_count, 1);
        assert.equal(parsedCrlf.row_count, 1);
        assert.equal(parsed.items[0].id, 1);
        expectParseError(
            () => parseItemList(`id,name,lucylink\n${item}\n\n2,Two,https://lucy.allakhazam.com/item.html?id=2\n`),
            'item_list_columns',
        );
        expectParseError(
            () => parseItemList(`id,name,lucylink\n${item}\n \n`),
            'item_list_columns',
        );
        expectParseError(
            () => parseItemList('id,name,lucylink\n\n\n'),
            'item_list_empty',
        );
    });
});

describe('Lucy item history parser', () => {
    test('finds semantic Live/Test tables and aggregates change rows by entry id', async () => {
        const parsed = parseItemHistoryPage(await fixture('itemhistory-20542.html'), {
            itemId: 20542,
            url: 'https://lucy.allakhazam.com/itemhistory.html?id=20542',
            captureSha256: 'a'.repeat(64),
        });

        assert.equal(parsed.item_id, 20542);
        assert.equal(parsed.item_name, 'Singing Short Sword');
        assert.equal(parsed.revision_count, 5);
        assert.deepEqual(parsed.sources, ['Live', 'Test']);
        assert.deepEqual(parsed.revisions.map((revision) => revision.entry_id), [20280, 40951, 1346097, 2043322, 2156558]);
        assert.deepEqual(parsed.revisions.map((revision) => revision.type), ['initial', 'initial', 'changed', 'changed', 'changed']);

        const grouped = parsed.revisions.find((revision) => revision.entry_id === 2043322);
        assert.equal(grouped.observed_at, '2018-03-21T20:46:00');
        assert.equal(grouped.observed_precision, 'minute');
        assert.equal(grouped.changes.length, 2);
        assert.deepEqual(grouped.changes[0], {
            operation: 'added',
            field: 'stacksize',
            before: null,
            after: '1',
            display: "Added stacksize: '1'",
        });
        assert.deepEqual(grouped.changes[1], {
            operation: 'changed',
            field: 'effect1',
            before: 'Dance of the Blade',
            after: 'Ultravision',
            display: "Changed effect1 from 'Dance of the Blade' to 'Ultravision'",
        });

        const iconChange = parsed.revisions.find((revision) => revision.entry_id === 1346097).changes[0];
        assert.equal(iconChange.operation, 'changed');
        assert.equal(iconChange.field, 'icon');
        assert.equal(iconChange.display, "Changed icon from '101' to '202'");
        assert.equal(iconChange.before, '101');
        assert.equal(iconChange.after, '202');
        assert.deepEqual(iconChange.icon_ids, [101, 202]);
        assert.equal(parsed.capture_sha256, 'a'.repeat(64));
    });

    test('does not silently accept conflicting rows or Lucy error bodies', async () => {
        const history = await fixture('itemhistory-20542.html');
        const errorPage = await fixture('system-error.html');
        const conflicting = history.replace(
            '<td><a href="item.html?entryid=2043322">2018-03-21&nbsp;20:46</a></td>\n          <td>Changed effect1',
            '<td><a href="item.html?entryid=2043322">2018-03-22&nbsp;20:46</a></td>\n          <td>Changed effect1',
        );
        expectParseError(() => parseItemHistoryPage(conflicting, { itemId: 20542 }), 'history_entry_conflict');
        expectParseError(
            () => parseItemHistoryPage(history.replace('item_101.png', 'unknown-icon.png'), { itemId: 20542 }),
            'history_change_image',
        );
        expectParseError(
            () => parseItemHistoryPage(errorPage, { itemId: 20542 }),
            'lucy_error_page',
        );
    });

    test('fails closed when a revision table or row gains an unparsed column', async () => {
        const history = await fixture('itemhistory-20542.html');
        const extraHeader = history.replace(
            '<thead><tr><th> Source </th><th>Date</th><th>Change</th></tr></thead>',
            '<thead><tr><th> Source </th><th>Date</th><th>Change</th><th>Extra</th></tr></thead>',
        );
        const extraRowCell = history.replace(
            "<td>Changed idfile from 'IT148' to ''</td>",
            "<td>Changed idfile from 'IT148' to ''</td><td>Unexpected</td>",
        );

        expectParseError(() => parseItemHistoryPage(extraHeader, { itemId: 20542 }), 'history_table_columns');
        expectParseError(() => parseItemHistoryPage(extraRowCell, { itemId: 20542 }), 'history_row_columns');
    });

    test('validates the requested Lucy endpoint rather than trusting a redirect target', async () => {
        const history = await fixture('itemhistory-20542.html');
        expectParseError(() => parseItemHistoryPage(history, {
            itemId: 20542,
            url: 'https://lucy.allakhazam.com/itemhistory.html?id=999',
        }), 'page_url');
        expectParseError(
            () => parseItemHistoryPage('<html><head><title>Just a moment...</title></head><body>Verify you are human</body></html>', { itemId: 20542 }),
            'lucy_challenge_page',
        );
    });

    test('decodes Lucy HTML bytes as Windows-1252 without UTF-8 replacement', () => {
        const bytes = Uint8Array.from([0x4c, 0x75, 0x63, 0x79, 0x92, 0x73]);
        assert.equal(decodeLucyHtml(bytes), 'Lucy’s');
        assert.equal(sha256Hex(bytes.buffer), sha256Hex(bytes));
        expectParseError(() => decodeLucyHtml(bytes, 'shift_jis'), 'html_charset');
    });
});

describe('Lucy historical detail parser', () => {
    test('captures visible snapshot lines, link ids, icon, source, and Lucy timestamps', async () => {
        const parsed = parseItemDetailPage(await fixture('itemdetail-2156558.html'), {
            itemId: 20542,
            entryId: 2156558,
            source: 'Live',
            historyObservedAt: '2020-06-12T13:36:00',
            url: 'https://lucy.allakhazam.com/item.html?entryid=2156558',
            captureSha256: 'b'.repeat(64),
        });

        assert.equal(parsed.entry_id, 2156558);
        assert.equal(parsed.item_id, 20542);
        assert.equal(parsed.source, 'Live');
        assert.equal(parsed.name, 'Singing Short Sword');
        assert.equal(parsed.icon, 2863);
        assert.equal(parsed.detail_observed_at, '2020-06-12T13:36:37');
        assert.equal(parsed.detail_verified_at, '2022-01-15T20:02:09');
        assert.equal(parsed.capture_sha256, 'b'.repeat(64));
        assert.deepEqual(parsed.snapshot_lines.slice(0, 3), [
            'Lore Item No Trade Placeable',
            'Slot: PRIMARY SECONDARY',
            'Skill: 1H Slashing Atk Delay: 26',
        ]);
        assert.deepEqual(parsed.links, [
            { text: 'Dance of the Blade', href: 'https://lucy.allakhazam.com/spell.html?id=1937' },
            { text: 'Performance Resonance 8', href: 'https://lucy.allakhazam.com/spell.html?id=21320' },
        ]);
        assert.equal(parsed.metadata.Source, 'Live');
        assert.equal(parsed.metadata['IC Last Updated'], '2020-06-12 13:36:37');
        assert.equal(Object.hasOwn(parsed.metadata, 'Unrelated'), false);
    });

    test('rejects a detail page assigned to the wrong source or item', async () => {
        const detail = await fixture('itemdetail-2156558.html');
        expectParseError(() => parseItemDetailPage(detail, {
            itemId: 20542,
            entryId: 2156558,
            source: 'Test',
        }), 'detail_source_conflict');
        expectParseError(() => parseItemDetailPage(detail, {
            itemId: 999,
            entryId: 2156558,
            source: 'Live',
        }), 'detail_item_id');
    });
});

describe('Lucy current raw-item parser', () => {
    test('preserves every raw value as a string, including empty and zero values', async () => {
        const parsed = parseItemRawPage(await fixture('itemraw-20542-live.html'), {
            itemId: 20542,
            source: 'Live',
            url: 'https://lucy.allakhazam.com/itemraw.html?id=20542&source=Live',
            captureSha256: 'c'.repeat(64),
        });

        assert.equal(parsed.source, 'Live');
        assert.equal(parsed.item_id, 20542);
        assert.equal(parsed.name, 'Singing Short Sword');
        assert.equal(parsed.updated_at, '2022-11-27T19:42:42');
        assert.equal(parsed.fields.ac, '0');
        assert.equal(parsed.fields.idfile, '');
        assert.equal(parsed.fields.UNKNOWN44, '-1');
        assert.equal(typeof parsed.fields.stacksize, 'string');
        assert.equal(Object.hasOwn(parsed.fields, ''), false);
        assert.equal(parsed.capture_sha256, 'c'.repeat(64));
    });

    test('requires an explicit source and rejects a mismatched raw id', async () => {
        const raw = await fixture('itemraw-20542-live.html');
        expectParseError(() => parseItemRawPage(raw, { itemId: 20542 }), 'raw_source');
        expectParseError(() => parseItemRawPage(raw, { itemId: 999, source: 'Live' }), 'raw_item_id');
    });

    test('fails closed when the candidate raw table contains partially unstructured fields', async () => {
        const raw = await fixture('itemraw-20542-live.html');
        const missingLabelClass = raw.replace(
            '<td class="spelllabel">UNKNOWN44</td>',
            '<td>UNKNOWN44</td>',
        );
        const nonemptyUnpairedCell = raw.replace(
            '<tr><td class="spelllabel">updated</td>',
            '<tr><td colspan="4">Unexpected raw content</td></tr>\n    <tr><td class="spelllabel">updated</td>',
        );

        for (const malformed of [missingLabelClass, nonemptyUnpairedCell]) {
            expectParseError(
                () => parseItemRawPage(malformed, { itemId: 20542, source: 'Live' }),
                'raw_table_structure',
            );
        }
    });
});

describe('deterministic item-history artifact assembly', () => {
    test('builds the direct per-item JSON contract without a database', async () => {
        const history = parseItemHistoryPage(await fixture('itemhistory-20542.html'), { itemId: 20542 });
        const latestDetail = parseItemDetailPage(await fixture('itemdetail-2156558.html'), {
            itemId: 20542,
            entryId: 2156558,
            source: 'Live',
        });
        const detailsByEntry = Object.fromEntries(history.revisions.map((revision) => [
            revision.entry_id,
            revision.entry_id === latestDetail.entry_id
                ? latestDetail
                : {
                    ...latestDetail,
                    entry_id: revision.entry_id,
                    source: revision.source,
                    name: revision.entry_id === 20280 ? 'An Earlier Singing Short Sword' : latestDetail.name,
                    detail_observed_at: revision.observed_at,
                },
        ]));
        const liveRaw = parseItemRawPage(await fixture('itemraw-20542-live.html'), {
            itemId: 20542,
            source: 'Live',
        });
        const artifact = buildItemHistoryArtifact({
            itemId: 20542,
            itemListName: 'List fallback name',
            history,
            detailsByEntry,
            currentRaw: { Live: liveRaw },
            generatedAt: '2026-08-23T01:02:03Z',
            captures: {
                history: 'a'.repeat(64),
                entries: { 2156558: 'b'.repeat(64) },
                current_raw: { Live: 'c'.repeat(64) },
            },
        });

        assert.equal(artifact.schema, LUCY_ITEM_HISTORY_SCHEMA);
        assert.equal(artifact.artifact_type, 'item');
        assert.equal(artifact.format_version, LUCY_ITEM_HISTORY_FORMAT_VERSION);
        assert.equal(artifact.parser_format_version, LUCY_ITEM_HISTORY_PARSER_FORMAT_VERSION);
        assert.equal(artifact.item_id, 20542);
        assert.equal(artifact.latest_name, 'Singing Short Sword');
        assert.equal(artifact.latest_icon, 2863);
        assert.equal(artifact.first_observed_at, '2002-12-22T18:35:00');
        assert.equal(artifact.last_observed_at, '2020-06-12T13:36:00');
        assert.equal(artifact.revision_count, 5);
        assert.equal(artifact.complete, true);
        assert.deepEqual(artifact.gaps, []);
        assert.deepEqual(artifact.sources, ['Live', 'Test']);
        assert.equal(artifact.history_capture_sha256, 'a'.repeat(64));
        assert.deepEqual(Object.keys(artifact.current_raw), ['Live']);
        assert.deepEqual(Object.keys(artifact.current_raw.Live), ['source', 'fields', 'capture_sha256']);
        assert.equal(artifact.current_raw.Live.capture_sha256, 'c'.repeat(64));

        const latestRevision = artifact.revisions.at(-1);
        assert.equal(latestRevision.entry_id, 2156558);
        assert.equal(latestRevision.observed_at, '2020-06-12T13:36:00');
        assert.equal(latestRevision.observed_precision, 'minute');
        assert.equal(latestRevision.detail_observed_at, '2020-06-12T13:36:37');
        assert.equal(latestRevision.capture_sha256, 'b'.repeat(64));
        assert.deepEqual(latestRevision.detail.snapshot_lines, latestDetail.snapshot_lines);

        assert.equal(itemHistoryRelativePath(20542), 'items/dc/20542.json');
        assert.equal(sha256Hex('20542').slice(0, 2), 'dc');
        assert.equal(canonicalJson({ z: 1, a: { y: 2, x: 3 } }), '{"a":{"x":3,"y":2},"z":1}');
        assert.equal(canonicalJson(artifact), canonicalJson(JSON.parse(canonicalJson(artifact))));
    });

    test('marks missing entry pages as explicit gaps and will not claim completeness', async () => {
        const history = parseItemHistoryPage(await fixture('itemhistory-20542.html'), { itemId: 20542 });
        const detail = parseItemDetailPage(await fixture('itemdetail-2156558.html'), {
            itemId: 20542,
            entryId: 2156558,
            source: 'Live',
        });
        const artifact = buildItemHistoryArtifact({
            itemId: 20542,
            history,
            detailsByEntry: { 2156558: detail },
            generatedAt: '2026-08-23T01:02:03Z',
        });

        assert.equal(artifact.complete, false);
        assert.equal(artifact.gaps.length, 4);
        assert.deepEqual(artifact.gaps[0], { entry_id: 20280, source: 'Live', reason: 'missing_detail' });
        expectParseError(() => buildItemHistoryArtifact({
            itemId: 20542,
            history,
            detailsByEntry: { 2156558: detail },
            generatedAt: '2026-08-23T01:02:03Z',
            complete: true,
        }), 'artifact_complete_with_gaps');
    });
});
