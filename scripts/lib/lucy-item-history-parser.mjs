import { createHash } from 'node:crypto';

export const LUCY_ITEM_HISTORY_SCHEMA = 'modern-allaclone.item-history';
export const LUCY_ITEM_HISTORY_FORMAT_VERSION = 1;
export const LUCY_ITEM_HISTORY_PARSER_FORMAT_VERSION = 1;
export const LUCY_ITEM_HISTORY_REVERSIBLE_DELTA_FORMAT_VERSION = 2;
export const LUCY_ITEM_HISTORY_REVERSIBLE_DELTA_PARSER_FORMAT_VERSION = 2;
export const REVERSIBLE_DELTA_CAPTURE_STRATEGY = 'reversible-delta-v1';

const REVERSIBLE_DELTA_ALGORITHM = 'lucy-reversible-delta';
const REVERSIBLE_DELTA_VALUE_ENCODING = 'lucy-history-display-v1';

const LUCY_ORIGIN = 'https://lucy.allakhazam.com';
const SOURCE_ORDER = new Map([['Live', 0], ['Test', 1]]);
const VOID_ELEMENTS = new Set([
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta',
    'param', 'source', 'track', 'wbr',
]);
const BLOCK_ELEMENTS = new Set([
    'address', 'article', 'aside', 'blockquote', 'div', 'dl', 'fieldset', 'figcaption',
    'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr',
    'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'tfoot',
    'th', 'thead', 'tr', 'ul',
]);
const NAMED_ENTITIES = Object.freeze({
    amp: '&', apos: "'", copy: '©', gt: '>', hellip: '…', laquo: '«',
    ldquo: '“', lsquo: '‘', lt: '<', nbsp: '\u00a0', ndash: '–',
    quot: '"', raquo: '»', rdquo: '”', reg: '®', rsquo: '’',
    trade: '™',
});

export class LucyParseError extends Error {
    constructor(code, message, context = {}) {
        super(message);
        this.name = 'LucyParseError';
        this.code = code;
        this.context = context;
    }
}

export const LucyItemHistoryParseError = LucyParseError;

export function sha256Hex(value) {
    const input = value instanceof ArrayBuffer ? new Uint8Array(value) : value;
    return createHash('sha256').update(input).digest('hex');
}

/**
 * Lucy's HTML responses currently omit charset metadata and are interpreted by
 * browsers as Windows-1252. Decode response bytes explicitly; Response.text()
 * assumes UTF-8 and can silently replace historical punctuation and names.
 */
export function decodeLucyHtml(bytes, charset = 'windows-1252') {
    if (!(bytes instanceof ArrayBuffer) && !ArrayBuffer.isView(bytes)) {
        throw parseError('html_bytes', 'Lucy HTML decoder requires an ArrayBuffer or typed-array view.');
    }
    const normalizedCharset = String(charset).trim().toLowerCase();
    if (!['windows-1252', 'utf-8'].includes(normalizedCharset)) {
        throw parseError('html_charset', 'Lucy HTML charset must be windows-1252 or utf-8.', {
            charset,
        });
    }
    try {
        return new TextDecoder(normalizedCharset, { fatal: true }).decode(bytes);
    } catch (error) {
        throw new LucyParseError('html_encoding', 'Lucy HTML could not be decoded without replacement.', {
            charset: normalizedCharset,
            cause: error instanceof Error ? error.message : String(error),
        });
    }
}

export function itemHistoryRelativePath(itemId) {
    const normalized = requireItemId(itemId, 'item id');
    const prefix = sha256Hex(String(normalized)).slice(0, 2);

    return `items/${prefix}/${normalized}.json`;
}

/**
 * Parse Lucy's decompressed itemlist.txt.gz payload. The gzip transport is kept
 * outside this module so callers can enforce compressed and expanded byte caps.
 */
export function parseItemList(csvText) {
    if (typeof csvText !== 'string') {
        throw parseError('item_list_type', 'Lucy item list must be decoded text.');
    }

    const rows = parseCsv(csvText);
    if (rows.length < 2) {
        throw parseError('item_list_empty', 'Lucy item list does not contain any item rows.');
    }

    const header = rows[0].map((value, index) => {
        const normalized = normalizeLabel(index === 0 ? value.replace(/^\uFEFF/, '') : value).toLowerCase();
        if (normalized === '') {
            throw parseError('item_list_header', 'Lucy item list contains an empty header.', { index });
        }
        return normalized;
    });
    if (new Set(header).size !== header.length) {
        throw parseError('item_list_header', 'Lucy item list contains duplicate headers.');
    }

    const indexes = Object.fromEntries(header.map((name, index) => [name, index]));
    for (const required of ['id', 'name', 'lucylink']) {
        if (!Object.hasOwn(indexes, required)) {
            throw parseError('item_list_header', `Lucy item list is missing the ${required} header.`);
        }
    }

    let lastDataRow = rows.length - 1;
    while (lastDataRow > 0
        && rows[lastDataRow].length === 1
        && rows[lastDataRow][0] === '') {
        lastDataRow -= 1;
    }

    const byId = new Map();
    for (let rowIndex = 1; rowIndex <= lastDataRow; rowIndex += 1) {
        const row = rows[rowIndex];
        if (row.length !== header.length) {
            throw parseError('item_list_columns', 'Lucy item list row has an unexpected column count.', {
                row: rowIndex + 1,
                expected: header.length,
                actual: row.length,
            });
        }

        const rawId = row[indexes.id];
        const itemId = parseCanonicalPositiveInteger(rawId, 'item_list_id', `item-list row ${rowIndex + 1}`);
        const name = row[indexes.name];
        if (name.trim() === '') {
            throw parseError('item_list_name', 'Lucy item list contains an empty item name.', {
                row: rowIndex + 1,
                item_id: itemId,
            });
        }
        const lucyLink = validateLucyItemLink(row[indexes.lucylink], itemId, 'item_list_link');
        if (byId.has(itemId)) {
            throw parseError('item_list_duplicate_id', 'Lucy item list contains a duplicate item id.', {
                row: rowIndex + 1,
                item_id: itemId,
            });
        }

        const extra = {};
        for (let index = 0; index < header.length; index += 1) {
            if (!['id', 'name', 'lucylink'].includes(header[index])) extra[header[index]] = row[index];
        }
        byId.set(itemId, {
            id: itemId,
            name,
            lucylink: lucyLink,
            ...(Object.keys(extra).length > 0 ? { extra: sortObject(extra) } : {}),
        });
    }

    const items = [...byId.values()].sort((left, right) => left.id - right.id);
    if (items.length === 0) {
        throw parseError('item_list_empty', 'Lucy item list does not contain any item rows.');
    }

    return {
        headers: header,
        row_count: items.length,
        items,
    };
}

export function parseItemHistory(html, { itemId = null, captureSha256 = null } = {}) {
    const expectedItemId = itemId === null ? null : requireItemId(itemId, 'item id');
    const document = parseHtmlDocument(html, 'item history');
    rejectLucyErrorPage(document, 'item history');

    const title = firstDescendant(document, (node) => isElement(node, 'title'));
    const titleText = title === null ? '' : normalizeDisplayText(textContent(title));
    const titleMatch = /^Item History for\s+(.+)$/i.exec(titleText);
    if (titleMatch === null) {
        throw parseError('history_title', 'Lucy item history page has an unexpected title.', { title: titleText });
    }

    const linkedItemIds = collectLucyLinkedItemIds(document);
    const resolvedItemId = expectedItemId ?? onlyValue(linkedItemIds, 'history_item_id', 'item history page');
    if (resolvedItemId === null) {
        throw parseError('history_item_id', 'Lucy item history page does not identify its item id.');
    }
    if (linkedItemIds.size > 0 && !linkedItemIds.has(resolvedItemId)) {
        throw parseError('history_item_id', 'Lucy item history page links to a different item id.', {
            expected_item_id: resolvedItemId,
            linked_item_ids: [...linkedItemIds],
        });
    }

    const revisionTables = [];
    for (const table of descendants(document, (node) => isElement(node, 'table'))) {
        const rows = directTableRows(table);
        if (rows.length === 0) continue;
        const headers = directRowCells(rows[0]).map((cell) => normalizeLabel(textContent(cell)).toLowerCase());
        const hasExpectedLeadingHeaders = headers.length >= 3
            && headers[0] === 'source'
            && headers[1] === 'date'
            && headers[2] === 'change';
        if (!hasExpectedLeadingHeaders) continue;
        if (headers.length !== 3) {
            throw parseError(
                'history_table_columns',
                'Lucy item history table has unexpected columns.',
                { headers },
            );
        }
        revisionTables.push(table);
    }
    if (revisionTables.length === 0) {
        throw parseError('history_table_missing', 'Lucy item history page has no Source/Date/Change table.');
    }

    const grouped = new Map();
    for (const table of revisionTables) {
        const rows = directTableRows(table);
        for (let rowIndex = 1; rowIndex < rows.length; rowIndex += 1) {
            const cells = directRowCells(rows[rowIndex]);
            if (cells.length !== 3) {
                if (normalizeDisplayText(textContent(rows[rowIndex])) === '') continue;
                throw parseError('history_row_columns', 'Lucy item history row must contain exactly three cells.', {
                    columns: cells.length,
                });
            }

            const source = normalizeSource(textContent(cells[0]), 'history_source');
            const historyObservedAt = parseLucyTimestamp(textContent(cells[1]), 'minute', 'history_timestamp');
            const entryIds = new Set();
            for (const anchor of descendants(rows[rowIndex], (node) => isElement(node, 'a'))) {
                const entryId = lucyEntryId(attribute(anchor, 'href'));
                if (entryId !== null) entryIds.add(entryId);
            }
            if (entryIds.size !== 1) {
                throw parseError('history_entry_id', 'Lucy item history row must link to exactly one entry id.', {
                    entry_ids: [...entryIds],
                });
            }
            const [entryId] = entryIds;
            const { display, iconIds } = historyChangeContent(cells[2]);
            if (display === '') {
                throw parseError('history_change_empty', 'Lucy item history contains an empty change description.', {
                    entry_id: entryId,
                });
            }
            const change = parseHistoryChange(display, iconIds);

            const existing = grouped.get(entryId);
            if (existing !== undefined) {
                if (existing.source !== source || existing.observed_at !== historyObservedAt.value) {
                    throw parseError('history_entry_conflict', 'Lucy entry id has conflicting source or timestamp rows.', {
                        entry_id: entryId,
                    });
                }
                existing.changes.push(change);
                continue;
            }

            grouped.set(entryId, {
                entry_id: entryId,
                source,
                observed_at: historyObservedAt.value,
                observed_precision: historyObservedAt.precision,
                type: change.operation === 'initial' ? 'initial' : 'changed',
                changes: [change],
            });
        }
    }

    if (grouped.size === 0) {
        throw parseError('history_empty', 'Lucy item history page contains no revision rows.');
    }

    const revisions = [...grouped.values()];
    for (const revision of revisions) {
        const hasInitial = revision.changes.some((change) => change.operation === 'initial');
        if (hasInitial && revision.changes.some((change) => change.operation !== 'initial')) {
            throw parseError('history_initial_mixed', 'Lucy initial entry is mixed with field changes.', {
                entry_id: revision.entry_id,
            });
        }
        revision.type = hasInitial ? 'initial' : 'changed';
    }
    revisions.sort(compareRevisions);

    return {
        item_id: resolvedItemId,
        item_name: titleMatch[1].trim(),
        sources: orderedSources(revisions.map((revision) => revision.source)),
        revision_count: revisions.length,
        revisions,
        ...(captureSha256 === null ? {} : { capture_sha256: requireSha256(captureSha256, 'history capture') }),
    };
}

export function parseItemHistoryPage(html, { itemId = null, url = null, captureSha256 = null } = {}) {
    validateOptionalPageUrl(url, '/itemhistory.html', { id: itemId });
    return parseItemHistory(html, { itemId, captureSha256 });
}

export function parseHistoricalItemDetail(html, {
    itemId = null,
    entryId,
    expectedSource = null,
    historyObservedAt = null,
    captureSha256 = null,
} = {}) {
    const expectedItemId = itemId === null ? null : requireItemId(itemId, 'item id');
    const normalizedEntryId = requireItemId(entryId, 'entry id');
    const normalizedExpectedSource = expectedSource === null
        ? null
        : normalizeSource(expectedSource, 'detail_expected_source');
    const document = parseHtmlDocument(html, 'historical item detail');
    rejectLucyErrorPage(document, 'historical item detail');

    const title = firstDescendant(document, (node) => isElement(node, 'title'));
    const titleText = title === null ? '' : normalizeDisplayText(textContent(title));
    const titleMatch = /^Item Details for\s+(.+)$/i.exec(titleText);
    if (titleMatch === null) {
        throw parseError('detail_title', 'Lucy historical item page has an unexpected title.', { title: titleText });
    }

    const linkedItemIds = collectHistoryLinkedItemIds(document);
    const resolvedItemId = expectedItemId ?? onlyValue(linkedItemIds, 'detail_item_id', 'historical item page');
    if (resolvedItemId === null) {
        throw parseError('detail_item_id', 'Lucy historical item page does not identify its item id.');
    }
    if (linkedItemIds.size > 0 && !linkedItemIds.has(resolvedItemId)) {
        throw parseError('detail_item_id', 'Lucy historical item page links to a different item id.', {
            expected_item_id: resolvedItemId,
            linked_item_ids: [...linkedItemIds],
        });
    }

    const itemTable = firstDescendant(document, (node) => isElement(node, 'table') && hasClass(node, 'eqitem'));
    if (itemTable === null) {
        throw parseError('detail_snapshot_missing', 'Lucy historical item page has no item snapshot table.');
    }
    const shotData = firstDescendant(itemTable, (node) => isElement(node, 'td') && hasClass(node, 'shotdata'));
    if (shotData === null) {
        throw parseError('detail_snapshot_missing', 'Lucy historical item page has no item snapshot content.');
    }
    const snapshotLines = textLines(shotData);
    if (snapshotLines.length === 0) {
        throw parseError('detail_snapshot_empty', 'Lucy historical item snapshot is empty.');
    }

    const metadata = collectLabeledMetadata(document);
    const sourceValue = metadataValue(metadata, 'Source');
    const source = sourceValue === null
        ? normalizedExpectedSource
        : normalizeSource(sourceValue, 'detail_source');
    if (source === null) {
        throw parseError('detail_source', 'Lucy historical item page does not identify its source.');
    }
    if (normalizedExpectedSource !== null && source !== normalizedExpectedSource) {
        throw parseError('detail_source_conflict', 'Lucy historical item source differs from its history row.', {
            expected: normalizedExpectedSource,
            actual: source,
        });
    }

    const detailObserved = optionalLucyTimestamp(metadataValue(metadata, 'IC Last Updated'), 'second', 'detail_timestamp');
    const detailVerified = optionalLucyTimestamp(metadataValue(metadata, 'IC Last Verified'), 'second', 'detail_verified_timestamp');
    if (historyObservedAt !== null) {
        const normalizedHistory = parseIsoNaiveTimestamp(historyObservedAt, 'history_timestamp');
        if (detailObserved !== null && detailObserved.value.slice(0, 16) !== normalizedHistory.slice(0, 16)) {
            // A disagreement is retained rather than rewritten; callers can expose
            // it as a crawl warning while keeping the history row authoritative.
        }
    }

    const links = descendants(shotData, (node) => isElement(node, 'a'))
        .map((anchor) => ({
            text: normalizeDisplayText(textContent(anchor)),
            href: normalizeLink(attribute(anchor, 'href')),
        }))
        .filter((link) => link.text !== '' && link.href !== null)
        .sort((left, right) => compareStrings(left.href, right.href) || compareStrings(left.text, right.text));

    const icon = findItemIcon(document);

    return {
        entry_id: normalizedEntryId,
        item_id: resolvedItemId,
        source,
        name: titleMatch[1].trim(),
        icon,
        snapshot_lines: snapshotLines,
        metadata: sortObject(metadata),
        links,
        ...(detailObserved === null ? {} : { detail_observed_at: detailObserved.value }),
        ...(detailVerified === null ? {} : { detail_verified_at: detailVerified.value }),
        ...(captureSha256 === null ? {} : { capture_sha256: requireSha256(captureSha256, 'detail capture') }),
    };
}

export function parseItemDetailPage(html, {
    itemId = null,
    entryId,
    source = null,
    url = null,
    historyObservedAt = null,
    captureSha256 = null,
} = {}) {
    validateOptionalPageUrl(url, '/item.html', { entryid: entryId });
    return parseHistoricalItemDetail(html, {
        itemId,
        entryId,
        expectedSource: source,
        historyObservedAt,
        captureSha256,
    });
}

export function parseCurrentRawItem(html, {
    itemId = null,
    source,
    captureSha256 = null,
} = {}) {
    const expectedItemId = itemId === null ? null : requireItemId(itemId, 'item id');
    const normalizedSource = normalizeSource(source, 'raw_source');
    const document = parseHtmlDocument(html, 'current raw item');
    rejectLucyErrorPage(document, 'current raw item');

    const candidates = [];
    for (const table of descendants(document, (node) => isElement(node, 'table'))) {
        const fields = {};
        let labels = 0;
        let structuralError = null;
        const rows = directTableRows(table);
        for (let rowIndex = 0; rowIndex < rows.length; rowIndex += 1) {
            const row = rows[rowIndex];
            const cells = directRowCells(row);
            for (let index = 0; index < cells.length; index += 2) {
                const labelCell = cells[index];
                const valueCell = cells[index + 1] ?? null;
                const field = normalizeLabel(textContent(labelCell));
                const value = valueCell === null ? '' : normalizeRawValue(textContent(valueCell));

                if (valueCell === null) {
                    if (field !== '' && structuralError === null) {
                        structuralError = {
                            code: 'raw_table_structure',
                            message: 'Lucy raw item table has a nonempty cell without its value cell.',
                            context: { row: rowIndex + 1, cell: index + 1, text: field },
                        };
                    }
                    continue;
                }
                if (!hasClass(labelCell, 'spelllabel')) {
                    if ((field !== '' || value !== '') && structuralError === null) {
                        structuralError = {
                            code: 'raw_table_structure',
                            message: 'Lucy raw item table contains nonempty cells without the expected field-label structure.',
                            context: { row: rowIndex + 1, cell: index + 1 },
                        };
                    }
                    continue;
                }
                if (hasClass(valueCell, 'spelllabel') && structuralError === null) {
                    structuralError = {
                        code: 'raw_table_structure',
                        message: 'Lucy raw item table marks a value cell as another field label.',
                        context: { row: rowIndex + 1, cell: index + 2 },
                    };
                }
                if (field === '') {
                    if (value !== '') {
                        structuralError ??= {
                            code: 'raw_empty_field',
                            message: 'Lucy raw item table has a value without a field name.',
                            context: { row: rowIndex + 1, cell: index + 1 },
                        };
                    }
                    continue;
                }
                labels += 1;
                if (Object.hasOwn(fields, field)) {
                    structuralError ??= {
                        code: 'raw_duplicate_field',
                        message: 'Lucy raw item table contains a duplicate field.',
                        context: { field, row: rowIndex + 1, cell: index + 1 },
                    };
                    continue;
                }
                fields[field] = value;
            }
        }
        const normalizedKeys = new Map(Object.keys(fields).map((key) => [key.toLowerCase(), key]));
        if (labels > 0 && normalizedKeys.has('id') && normalizedKeys.has('name')) {
            if (structuralError !== null) {
                throw parseError(
                    structuralError.code,
                    structuralError.message,
                    structuralError.context,
                );
            }
            candidates.push({ fields, normalizedKeys, labels });
        }
    }
    if (candidates.length !== 1) {
        throw parseError('raw_table', 'Lucy current raw item page must contain exactly one raw field table.', {
            candidates: candidates.length,
        });
    }

    const candidate = candidates[0];
    const rawId = candidate.fields[candidate.normalizedKeys.get('id')];
    const resolvedItemId = parseCanonicalPositiveInteger(rawId, 'raw_item_id', 'raw item id field');
    if (expectedItemId !== null && resolvedItemId !== expectedItemId) {
        throw parseError('raw_item_id', 'Lucy current raw item id differs from the requested item.', {
            expected_item_id: expectedItemId,
            actual_item_id: resolvedItemId,
        });
    }
    const linkedItemIds = collectLucyLinkedItemIds(document);
    if (linkedItemIds.size > 0 && !linkedItemIds.has(resolvedItemId)) {
        throw parseError('raw_item_id', 'Lucy current raw item page links to a different item id.', {
            item_id: resolvedItemId,
            linked_item_ids: [...linkedItemIds],
        });
    }

    const name = candidate.fields[candidate.normalizedKeys.get('name')];
    const updatedKey = candidate.normalizedKeys.get('updated');
    const updated = updatedKey === undefined
        ? null
        : optionalLucyTimestamp(candidate.fields[updatedKey], 'second', 'raw_updated_timestamp');

    return {
        source: normalizedSource,
        item_id: resolvedItemId,
        name,
        fields: sortObject(candidate.fields),
        ...(updated === null ? {} : { updated_at: updated.value }),
        ...(captureSha256 === null ? {} : { capture_sha256: requireSha256(captureSha256, 'raw capture') }),
    };
}

export function parseItemRawPage(html, {
    itemId = null,
    source,
    url = null,
    captureSha256 = null,
} = {}) {
    validateOptionalPageUrl(url, '/itemraw.html', { id: itemId, source });
    return parseCurrentRawItem(html, { itemId, source, captureSha256 });
}

/**
 * Validate Lucy history rows as independent, source-isolated, reversible
 * tri-state transitions. The values remain in Lucy's history-display encoding;
 * this deliberately does not pretend that itemraw numeric/default encodings are
 * interchangeable with the human-readable values in the history table.
 */
export function analyzeReversibleItemHistory({
    itemId,
    history,
    currentRaw = {},
    anchorSource = null,
} = {}) {
    const normalizedItemId = requireItemId(itemId, 'item id');
    if (!isRecord(history) || history.item_id !== normalizedItemId || !Array.isArray(history.revisions)) {
        throw parseError('reconstruction_history', 'Reversible reconstruction history does not match the requested item id.');
    }
    if (history.revisions.length === 0) {
        throw parseError('reconstruction_history_empty', 'Reversible reconstruction requires at least one history revision.');
    }

    const suppliedRawCount = Array.isArray(currentRaw)
        ? currentRaw.length
        : (isRecord(currentRaw) ? Object.keys(currentRaw).length : 0);
    if (suppliedRawCount !== 1) {
        throw parseError('reconstruction_anchor_count', 'Reversible reconstruction requires exactly one current-raw anchor source.', {
            count: suppliedRawCount,
        });
    }
    const normalizedRaw = normalizeCurrentRaw(currentRaw, normalizedItemId);
    const rawSources = Object.keys(normalizedRaw);
    if (rawSources.length !== 1) {
        throw parseError('reconstruction_anchor_count', 'Reversible reconstruction requires exactly one current-raw anchor source.', {
            sources: rawSources,
        });
    }
    const normalizedAnchorSource = anchorSource === null
        ? rawSources[0]
        : normalizeSource(anchorSource, 'reconstruction_anchor_source');
    if (rawSources[0] !== normalizedAnchorSource) {
        throw parseError('reconstruction_anchor_source', 'The requested anchor source differs from the current-raw capture.', {
            requested: normalizedAnchorSource,
            actual: rawSources[0],
        });
    }
    const anchor = normalizedRaw[normalizedAnchorSource];
    if (anchor.capture_sha256 === undefined) {
        throw parseError('reconstruction_anchor_evidence', 'Reversible reconstruction requires capture evidence for its current-raw anchor.');
    }

    const historyCaptureSha256s = collectHistoryCaptureSha256s(history);
    if (historyCaptureSha256s.length === 0) {
        throw parseError('reconstruction_history_evidence', 'Reversible reconstruction requires history capture evidence.');
    }

    const normalizedRevisions = history.revisions.map(normalizeReconstructionRevision).sort(compareRevisions);
    const sources = orderedSources([
        ...(history.sources ?? []),
        ...normalizedRevisions.map((revision) => revision.source),
    ]);
    if (!sources.includes(normalizedAnchorSource)) {
        throw parseError('reconstruction_anchor_source', 'The current-raw anchor source has no corresponding history source.', {
            source: normalizedAnchorSource,
        });
    }

    const sourceResults = {};
    const derivationSources = {};
    for (const source of sources) {
        const revisions = normalizedRevisions.filter((revision) => revision.source === source);
        const analysis = analyzeReversibleSourceChain(source, revisions);
        const status = source === normalizedAnchorSource
            ? 'chain-verified-anchored'
            : 'chain-verified-unanchored';
        sourceResults[source] = {
            status,
            revision_count: revisions.length,
            change_count: analysis.change_count,
            tracked_field_count: analysis.tracked_field_count,
            continuity_checks: analysis.continuity_checks,
        };
        derivationSources[source] = {
            status,
            revisions,
        };
    }

    const derivationPayload = {
        algorithm: REVERSIBLE_DELTA_ALGORITHM,
        version: 1,
        value_encoding: REVERSIBLE_DELTA_VALUE_ENCODING,
        item_id: normalizedItemId,
        history_capture_sha256s: historyCaptureSha256s,
        anchor: {
            source: normalizedAnchorSource,
            capture_sha256: anchor.capture_sha256,
            fields_sha256: sha256Hex(canonicalJson(anchor.fields)),
        },
        sources: derivationSources,
    };

    return {
        algorithm: REVERSIBLE_DELTA_ALGORITHM,
        version: 1,
        derivation_sha256: sha256Hex(canonicalJson(derivationPayload)),
        value_encoding: REVERSIBLE_DELTA_VALUE_ENCODING,
        sources: sourceResults,
    };
}

export function assembleItemHistoryArtifact({
    itemId,
    history,
    entries = [],
    currentRaw = {},
    generatedAt,
    gaps = [],
    complete = null,
    captureStrategy = null,
    anchorSource = null,
    reconstruction = null,
} = {}) {
    const normalizedItemId = requireItemId(itemId, 'item id');
    if (!isRecord(history) || history.item_id !== normalizedItemId || !Array.isArray(history.revisions)) {
        throw parseError('artifact_history', 'Artifact history does not match the requested item id.');
    }
    const reversibleDelta = captureStrategy !== null;
    if (reversibleDelta && captureStrategy !== REVERSIBLE_DELTA_CAPTURE_STRATEGY) {
        throw parseError('artifact_capture_strategy', 'Artifact capture strategy is not supported.', {
            capture_strategy: captureStrategy,
        });
    }
    const normalizedGeneratedAt = parseGeneratedAt(generatedAt);
    const historyCaptureSha256s = reversibleDelta ? collectHistoryCaptureSha256s(history) : [];
    if (reversibleDelta && historyCaptureSha256s.length === 0) {
        throw parseError('artifact_history_evidence', 'A reversible-delta artifact requires history capture evidence.');
    }

    const entriesById = new Map();
    for (const entry of entries) {
        if (!isRecord(entry)) throw parseError('artifact_entry', 'Artifact detail entry must be an object.');
        const entryId = requireItemId(entry.entry_id, 'entry id');
        if (entry.item_id !== normalizedItemId) {
            throw parseError('artifact_entry_item', 'Artifact detail entry belongs to a different item.', {
                entry_id: entryId,
            });
        }
        if (entriesById.has(entryId)) {
            throw parseError('artifact_entry_duplicate', 'Artifact contains a duplicate detail entry.', { entry_id: entryId });
        }
        entriesById.set(entryId, entry);
    }

    const automaticGaps = [];
    const revisions = history.revisions.map((historyRevision) => {
        const entryId = requireItemId(historyRevision.entry_id, 'history entry id');
        const detail = entriesById.get(entryId) ?? null;
        if (detail !== null) {
            entriesById.delete(entryId);
            if (detail.source !== historyRevision.source) {
                throw parseError('artifact_entry_source', 'Artifact detail source differs from its history row.', {
                    entry_id: entryId,
                });
            }
        } else if (!reversibleDelta) {
            automaticGaps.push({
                entry_id: entryId,
                source: historyRevision.source,
                reason: 'missing_detail',
            });
        }

        const revision = {
            entry_id: entryId,
            source: historyRevision.source,
            observed_at: parseIsoNaiveTimestamp(historyRevision.observed_at, 'history timestamp'),
            observed_precision: normalizeObservedPrecision(historyRevision.observed_precision),
            type: normalizeRevisionType(historyRevision.type),
            changes: historyRevision.changes.map(normalizeArtifactChange),
            ...(reversibleDelta ? { history_capture_sha256s: [...historyCaptureSha256s] } : {}),
        };
        if (detail !== null) {
            revision.detail = {
                name: detail.name,
                icon: detail.icon ?? null,
                snapshot_lines: [...detail.snapshot_lines],
                metadata: sortObject(detail.metadata ?? {}),
                links: [...(detail.links ?? [])].map((link) => ({ text: link.text, href: link.href })),
            };
            if (detail.detail_observed_at !== undefined) {
                revision.detail_observed_at = parseIsoNaiveTimestamp(detail.detail_observed_at, 'detail timestamp');
            }
            if (detail.detail_verified_at !== undefined) {
                revision.detail_verified_at = parseIsoNaiveTimestamp(detail.detail_verified_at, 'detail verified timestamp');
            }
            if (detail.capture_sha256 !== undefined) {
                revision.capture_sha256 = requireSha256(detail.capture_sha256, 'detail capture');
            }
        }

        return revision;
    }).sort(compareRevisions);
    if (entriesById.size > 0) {
        throw parseError('artifact_orphan_entry', 'Artifact contains detail entries absent from the history page.', {
            entry_ids: [...entriesById.keys()].sort((left, right) => left - right),
        });
    }

    const normalizedGaps = normalizeGaps([...gaps, ...automaticGaps]);
    const currentRawArtifact = normalizeCurrentRaw(currentRaw, normalizedItemId);
    const computedReconstruction = reversibleDelta
        ? analyzeReversibleItemHistory({
            itemId: normalizedItemId,
            history,
            currentRaw: currentRawArtifact,
            anchorSource,
        })
        : null;
    if (reversibleDelta && reconstruction !== null
        && canonicalJson(reconstruction) !== canonicalJson(computedReconstruction)) {
        throw parseError('artifact_reconstruction', 'Provided reconstruction metadata differs from the verified derivation.');
    }
    const detailedRevisions = revisions.filter((revision) => revision.detail !== undefined);
    const latestDetail = detailedRevisions.length === 0 ? null : detailedRevisions[detailedRevisions.length - 1].detail;
    const sources = orderedSources([
        ...(history.sources ?? []),
        ...revisions.map((revision) => revision.source),
        ...Object.keys(currentRawArtifact),
    ]);
    const resolvedComplete = complete === null ? normalizedGaps.length === 0 : Boolean(complete);
    if (resolvedComplete && normalizedGaps.length > 0) {
        throw parseError('artifact_complete_with_gaps', 'A complete item artifact cannot contain gaps.');
    }

    const anchorRawSource = reversibleDelta ? Object.keys(currentRawArtifact)[0] : null;
    const reversibleMetadata = reversibleDelta ? {
        capture_strategy: REVERSIBLE_DELTA_CAPTURE_STRATEGY,
        coverage: {
            history_rows: 'captured',
            current_raw: 'captured',
            historical_state: 'reconstructed',
            rendered_details: detailedRevisions.length === 0 ? 'not-captured' : 'partial',
            direct_detail_count: detailedRevisions.length,
        },
        evidence: {
            history_capture_sha256s: historyCaptureSha256s,
            current_raw_capture_sha256: currentRawArtifact[anchorRawSource].capture_sha256,
            current_raw_source: anchorRawSource,
        },
        reconstruction: computedReconstruction,
    } : {};

    return {
        schema: LUCY_ITEM_HISTORY_SCHEMA,
        artifact_type: 'item',
        format_version: reversibleDelta
            ? LUCY_ITEM_HISTORY_REVERSIBLE_DELTA_FORMAT_VERSION
            : LUCY_ITEM_HISTORY_FORMAT_VERSION,
        parser_format_version: reversibleDelta
            ? LUCY_ITEM_HISTORY_REVERSIBLE_DELTA_PARSER_FORMAT_VERSION
            : LUCY_ITEM_HISTORY_PARSER_FORMAT_VERSION,
        ...reversibleMetadata,
        item_id: normalizedItemId,
        latest_name: reversibleDelta
            ? (history.item_name ?? null)
            : (latestDetail?.name ?? history.item_name ?? null),
        latest_icon: reversibleDelta ? null : (latestDetail?.icon ?? null),
        generated_at: normalizedGeneratedAt,
        sources,
        complete: resolvedComplete,
        gaps: normalizedGaps,
        first_observed_at: revisions[0]?.observed_at ?? null,
        last_observed_at: revisions.at(-1)?.observed_at ?? null,
        revision_count: revisions.length,
        ...(!reversibleDelta && history.capture_sha256 !== undefined
            ? { history_capture_sha256: requireSha256(history.capture_sha256, 'history capture') }
            : {}),
        current_raw: currentRawArtifact,
        revisions,
    };
}

export function buildItemHistoryArtifact({
    itemId,
    itemListName = null,
    history,
    detailsByEntry = {},
    currentRaw = {},
    generatedAt,
    gaps = [],
    captures = {},
    complete = null,
    captureStrategy = null,
    anchorSource = null,
    reconstruction = null,
} = {}) {
    const normalizedHistory = { ...history };
    if ((normalizedHistory.item_name === null || normalizedHistory.item_name === undefined)
        && typeof itemListName === 'string' && itemListName.trim() !== '') {
        normalizedHistory.item_name = itemListName;
    }
    const historyCapture = captureDigest(captures?.history ?? captures?.history_sha256);
    if (normalizedHistory.capture_sha256 === undefined && historyCapture !== null) {
        normalizedHistory.capture_sha256 = historyCapture;
    }

    const detailEntries = normalizeDetailsInput(detailsByEntry).map((entry) => {
        if (entry.capture_sha256 !== undefined) return entry;
        const configured = captures?.entries?.[entry.entry_id] ?? captures?.details?.[entry.entry_id];
        const digest = captureDigest(configured);
        return digest === null ? entry : { ...entry, capture_sha256: digest };
    });
    const normalizedCurrentRaw = normalizeRawInputForBuild(currentRaw, captures);

    return assembleItemHistoryArtifact({
        itemId,
        history: normalizedHistory,
        entries: detailEntries,
        currentRaw: normalizedCurrentRaw,
        generatedAt,
        gaps,
        complete,
        captureStrategy,
        anchorSource,
        reconstruction,
    });
}

export function canonicalJson(value) {
    return JSON.stringify(canonicalize(value));
}

function parseHistoryChange(display, iconIds = []) {
    const withIcons = (change) => iconIds.length === 0 ? change : { ...change, icon_ids: [...iconIds] };
    if (/^Initial Entry$/i.test(display)) {
        return withIcons({ operation: 'initial', field: null, before: null, after: null, display });
    }

    let match = /^Added\s+([A-Za-z0-9_]+):\s*'(.*)'$/s.exec(display);
    if (match !== null) {
        return withIcons({ operation: 'added', field: match[1], before: null, after: match[2], display });
    }
    match = /^Removed\s+([A-Za-z0-9_]+):\s*'(.*)'$/s.exec(display);
    if (match !== null) {
        return withIcons({ operation: 'removed', field: match[1], before: match[2], after: null, display });
    }
    match = /^Changed\s+([A-Za-z0-9_]+)\s+from\s+'(.*)'\s+to\s+'(.*)'$/s.exec(display);
    if (match !== null) {
        return withIcons({ operation: 'changed', field: match[1], before: match[2], after: match[3], display });
    }
    match = /^(Added|Removed|Changed)\s+([A-Za-z0-9_]+)\b/i.exec(display);
    if (match !== null) {
        return withIcons({
            operation: match[1].toLowerCase() === 'add' ? 'added' : match[1].toLowerCase(),
            field: match[2],
            before: null,
            after: null,
            display,
        });
    }

    return withIcons({ operation: 'unknown', field: null, before: null, after: null, display });
}

function historyChangeContent(cell) {
    const iconIds = [];
    const visit = (node) => {
        if (node.type === 'text') return node.value;
        if (isElement(node, 'img')) {
            const source = attribute(node, 'src') ?? '';
            const match = /(?:^|\/)item_(\d+)\.(?:png|gif)(?:[?#].*)?$/i.exec(source);
            if (match === null) {
                throw parseError('history_change_image', 'Lucy item history contains an unrecognized change image.', {
                    src: source,
                });
            }
            const iconId = Number(match[1]);
            if (!Number.isSafeInteger(iconId)) {
                throw parseError('history_change_image', 'Lucy item history contains an invalid icon id.', {
                    src: source,
                });
            }
            iconIds.push(iconId);

            return `'${iconId}'`;
        }
        if (isElement(node, 'br')) return ' ';

        return (node.children ?? []).map(visit).join('');
    };

    return {
        display: normalizeDisplayText(visit(cell)),
        iconIds,
    };
}

function parseCsv(text) {
    const rows = [];
    let row = [];
    let field = '';
    let quoted = false;
    let afterQuote = false;

    for (let index = 0; index < text.length; index += 1) {
        const character = text[index];
        if (quoted) {
            if (character === '"') {
                if (text[index + 1] === '"') {
                    field += '"';
                    index += 1;
                } else {
                    quoted = false;
                    afterQuote = true;
                }
            } else {
                field += character;
            }
            continue;
        }

        if (afterQuote) {
            if (character === ',') {
                row.push(field);
                field = '';
                afterQuote = false;
            } else if (character === '\n' || character === '\r') {
                row.push(field);
                rows.push(row);
                row = [];
                field = '';
                afterQuote = false;
                if (character === '\r' && text[index + 1] === '\n') index += 1;
            } else if (character !== ' ' && character !== '\t') {
                throw parseError('item_list_csv', 'Unexpected character after a quoted CSV field.', {
                    offset: index,
                });
            }
            continue;
        }

        if (character === '"') {
            if (field !== '') {
                throw parseError('item_list_csv', 'Quote appears inside an unquoted CSV field.', { offset: index });
            }
            quoted = true;
        } else if (character === ',') {
            row.push(field);
            field = '';
        } else if (character === '\n' || character === '\r') {
            row.push(field);
            rows.push(row);
            row = [];
            field = '';
            if (character === '\r' && text[index + 1] === '\n') index += 1;
        } else {
            field += character;
        }
    }

    if (quoted) throw parseError('item_list_csv', 'Lucy item list ends inside a quoted CSV field.');
    if (afterQuote || field !== '' || row.length > 0) {
        row.push(field);
        rows.push(row);
    }

    return rows;
}

function parseHtmlDocument(html, label) {
    if (typeof html !== 'string' || html.trim() === '') {
        throw parseError('html_empty', `Lucy ${label} response is empty.`);
    }

    const root = { type: 'root', children: [], parent: null };
    const stack = [root];
    const source = html.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '')
        .replace(/<style\b[^>]*>[\s\S]*?<\/style\s*>/gi, '');
    const tokenPattern = /<!--[\s\S]*?-->|<![^>]*>|<\/?[A-Za-z][^>]*>|[^<]+|</g;
    let token;
    while ((token = tokenPattern.exec(source)) !== null) {
        const value = token[0];
        if (value.startsWith('<!--') || /^<!/i.test(value)) continue;
        if (!value.startsWith('<')) {
            appendNode(stack.at(-1), { type: 'text', value: decodeEntities(value), parent: null });
            continue;
        }
        if (/^<\//.test(value)) {
            const match = /^<\/\s*([A-Za-z0-9:-]+)/.exec(value);
            if (match === null) continue;
            const tag = match[1].toLowerCase();
            for (let index = stack.length - 1; index > 0; index -= 1) {
                if (stack[index].tag === tag) {
                    stack.length = index;
                    break;
                }
            }
            continue;
        }

        const open = /^<\s*([A-Za-z0-9:-]+)([\s\S]*?)\/?\s*>$/.exec(value);
        if (open === null) continue;
        const tag = open[1].toLowerCase();
        autoCloseOptionalElements(stack, tag);
        const element = {
            type: 'element',
            tag,
            attributes: parseAttributes(open[2]),
            children: [],
            parent: null,
        };
        appendNode(stack.at(-1), element);
        if (!VOID_ELEMENTS.has(tag) && !/\/\s*>$/.test(value)) stack.push(element);
    }

    return root;
}

function autoCloseOptionalElements(stack, incomingTag) {
    const top = stack.at(-1);
    if (top?.type !== 'element') return;
    if (incomingTag === 'tr' && top.tag === 'tr') stack.pop();
    if ((incomingTag === 'td' || incomingTag === 'th') && (top.tag === 'td' || top.tag === 'th')) stack.pop();
    if (incomingTag === 'li' && top.tag === 'li') stack.pop();
    if (incomingTag === 'p' && top.tag === 'p') stack.pop();
}

function parseAttributes(fragment) {
    const attributes = {};
    const pattern = /([^\s=/>]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
    let match;
    while ((match = pattern.exec(fragment)) !== null) {
        const name = match[1].toLowerCase();
        const value = decodeEntities(match[2] ?? match[3] ?? match[4] ?? '');
        attributes[name] = value;
    }
    return attributes;
}

function decodeEntities(value) {
    return value.replace(/&(#(?:x[0-9a-f]+|\d+)|[a-z][a-z0-9]+);/gi, (entity, token) => {
        if (token[0] === '#') {
            const hexadecimal = token[1]?.toLowerCase() === 'x';
            const codePoint = Number.parseInt(token.slice(hexadecimal ? 2 : 1), hexadecimal ? 16 : 10);
            if (!Number.isInteger(codePoint) || codePoint < 0 || codePoint > 0x10ffff) return entity;
            try {
                return String.fromCodePoint(codePoint);
            } catch {
                return entity;
            }
        }
        return NAMED_ENTITIES[token.toLowerCase()] ?? entity;
    });
}

function appendNode(parent, child) {
    child.parent = parent;
    parent.children.push(child);
}

function descendants(node, predicate) {
    const matches = [];
    const stack = [...(node.children ?? [])].reverse();
    while (stack.length > 0) {
        const current = stack.pop();
        if (predicate(current)) matches.push(current);
        if (current.children?.length > 0) stack.push(...current.children.slice().reverse());
    }
    return matches;
}

function firstDescendant(node, predicate) {
    const stack = [...(node.children ?? [])].reverse();
    while (stack.length > 0) {
        const current = stack.pop();
        if (predicate(current)) return current;
        if (current.children?.length > 0) stack.push(...current.children.slice().reverse());
    }
    return null;
}

function textContent(node) {
    if (node.type === 'text') return node.value;
    return (node.children ?? []).map(textContent).join('');
}

function textWithBreaks(node) {
    if (node.type === 'text') return node.value;
    if (isElement(node, 'br')) return '\n';
    const content = (node.children ?? []).map(textWithBreaks).join('');
    return node.type === 'element' && BLOCK_ELEMENTS.has(node.tag) ? `${content}\n` : content;
}

function textLines(node) {
    return textWithBreaks(node)
        .split(/\r?\n/)
        .map(normalizeDisplayText)
        .filter((line) => line !== '');
}

function isElement(node, tag = null) {
    return node?.type === 'element' && (tag === null || node.tag === tag);
}

function attribute(node, name) {
    return node?.attributes?.[name.toLowerCase()] ?? null;
}

function hasClass(node, expected) {
    return (attribute(node, 'class') ?? '').split(/\s+/).includes(expected);
}

function nearestAncestor(node, tag) {
    let current = node.parent;
    while (current !== null) {
        if (isElement(current, tag)) return current;
        current = current.parent;
    }
    return null;
}

function directTableRows(table) {
    return descendants(table, (node) => isElement(node, 'tr') && nearestAncestor(node, 'table') === table);
}

function directRowCells(row) {
    return descendants(row, (node) => (isElement(node, 'td') || isElement(node, 'th'))
        && nearestAncestor(node, 'tr') === row);
}

function collectLucyLinkedItemIds(document) {
    const ids = new Set();
    for (const anchor of descendants(document, (node) => isElement(node, 'a'))) {
        const id = lucyItemId(attribute(anchor, 'href'));
        if (id !== null) ids.add(id);
    }
    return ids;
}

function collectHistoryLinkedItemIds(document) {
    const ids = new Set();
    for (const anchor of descendants(document, (node) => isElement(node, 'a'))) {
        const href = trustedLucyUrl(attribute(anchor, 'href'));
        if (href === null || href.pathname !== '/itemhistory.html') continue;
        const rawId = href.searchParams.get('id');
        if (rawId !== null && /^[1-9]\d*$/.test(rawId)) ids.add(Number(rawId));
    }
    return ids;
}

function lucyItemId(hrefValue) {
    const href = trustedLucyUrl(hrefValue);
    if (href === null || href.pathname !== '/item.html' || href.searchParams.has('entryid')) return null;
    const rawId = href.searchParams.get('id');
    return rawId !== null && /^[1-9]\d*$/.test(rawId) ? Number(rawId) : null;
}

function lucyEntryId(hrefValue) {
    const href = trustedLucyUrl(hrefValue);
    if (href === null || href.pathname !== '/item.html') return null;
    const rawEntryId = href.searchParams.get('entryid');
    return rawEntryId !== null && /^[1-9]\d*$/.test(rawEntryId) ? Number(rawEntryId) : null;
}

function trustedLucyUrl(hrefValue) {
    if (typeof hrefValue !== 'string' || hrefValue.trim() === '') return null;
    try {
        const url = new URL(hrefValue, `${LUCY_ORIGIN}/`);
        if (url.protocol !== 'https:' || url.hostname !== 'lucy.allakhazam.com'
            || url.port !== '' || url.username !== '' || url.password !== '') return null;
        return url;
    } catch {
        return null;
    }
}

function validateOptionalPageUrl(value, expectedPath, expectedParameters) {
    if (value === null || value === undefined) return;
    const url = trustedLucyUrl(value);
    if (url === null || url.pathname !== expectedPath) {
        throw parseError('page_url', 'Lucy page URL is not an expected HTTPS Lucy endpoint.', { url: value });
    }
    for (const [key, expected] of Object.entries(expectedParameters)) {
        if (expected === null || expected === undefined) continue;
        if (url.searchParams.get(key) !== String(expected)) {
            throw parseError('page_url', 'Lucy page URL parameters differ from the requested capture.', {
                url: value,
                parameter: key,
                expected,
            });
        }
    }
}

function validateLucyItemLink(value, itemId, code) {
    const url = trustedLucyUrl(value);
    if (url === null || url.pathname !== '/item.html' || url.hash !== ''
        || url.searchParams.get('id') !== String(itemId) || [...url.searchParams.keys()].some((key) => key !== 'id')) {
        throw parseError(code, 'Lucy item-list URL does not match its item id.', {
            item_id: itemId,
            url: value,
        });
    }
    return url.href;
}

function normalizeLink(value) {
    if (typeof value !== 'string' || value.trim() === '') return null;
    try {
        const url = new URL(value, `${LUCY_ORIGIN}/`);
        if (!['http:', 'https:'].includes(url.protocol) || url.username !== '' || url.password !== '') return null;
        return url.href;
    } catch {
        return null;
    }
}

function collectLabeledMetadata(document) {
    const metadata = {};
    for (const row of descendants(document, (node) => isElement(node, 'tr'))) {
        const cells = directRowCells(row);
        for (let index = 0; index + 1 < cells.length; index += 1) {
            if (!hasClass(cells[index], 'spelllabel')) continue;
            if (hasAncestorClass(cells[index], 'sidebar')) continue;
            const label = normalizeLabel(textContent(cells[index])).replace(/:$/, '');
            if (label === '') continue;
            const value = normalizeDisplayText(textContent(cells[index + 1]));
            if (Object.hasOwn(metadata, label) && metadata[label] !== value) {
                throw parseError('detail_metadata_conflict', 'Lucy historical item page repeats a metadata label with a different value.', {
                    label,
                });
            }
            metadata[label] = value;
            index += 1;
        }
    }
    return metadata;
}

function hasAncestorClass(node, expected) {
    let current = node.parent;
    while (current !== null) {
        if (current.type === 'element' && hasClass(current, expected)) return true;
        current = current.parent;
    }
    return false;
}

function metadataValue(metadata, expectedLabel) {
    const key = Object.keys(metadata).find((label) => label.toLowerCase() === expectedLabel.toLowerCase());
    return key === undefined ? null : metadata[key];
}

function findItemIcon(document) {
    for (const image of descendants(document, (node) => isElement(node, 'img'))) {
        const source = attribute(image, 'src') ?? '';
        const match = /(?:^|\/)item_(\d+)\.png(?:[?#].*)?$/i.exec(source);
        if (match !== null) return Number(match[1]);
    }
    return null;
}

function rejectLucyErrorPage(document, label) {
    const body = firstDescendant(document, (node) => isElement(node, 'body')) ?? document;
    const text = normalizeDisplayText(textContent(body));
    if (/\bSystem error\b/i.test(text)
        || /no value sent for required parameter/i.test(text)
        || /Trace begun at \/home\/lucy/i.test(text)) {
        throw parseError('lucy_error_page', `Lucy returned an error page for ${label}.`);
    }
    if (/\b(?:Could not find|No such) item\b/i.test(text)) {
        throw parseError('lucy_item_missing', `Lucy could not find the requested ${label}.`);
    }
    if (/\b(?:captcha|verify you are human|checking your browser|attention required|access denied|too many requests)\b/i.test(text)
        || /^Just a moment(?:\.\.\.)?$/i.test(text)) {
        throw parseError('lucy_challenge_page', `Lucy returned an access challenge for ${label}.`);
    }
}

function parseLucyTimestamp(value, precision, code) {
    const normalized = normalizeDisplayText(value).replace(' ', 'T');
    const pattern = precision === 'minute'
        ? /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/
        : /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/;
    const match = pattern.exec(normalized);
    if (match === null || !validCalendarParts(match.slice(1).map(Number), precision)) {
        throw parseError(code, 'Lucy timestamp is invalid.', { value: normalizeDisplayText(value) });
    }
    return {
        value: precision === 'minute' ? `${normalized}:00` : normalized,
        precision,
    };
}

function optionalLucyTimestamp(value, precision, code) {
    if (value === null || normalizeDisplayText(value) === '') return null;
    return parseLucyTimestamp(value, precision, code);
}

function parseIsoNaiveTimestamp(value, code) {
    if (typeof value !== 'string') throw parseError(code, 'Timestamp must be a string.');
    return parseLucyTimestamp(value.replace('T', ' '), 'second', code).value;
}

function validCalendarParts(parts, precision) {
    const [year, month, day, hour, minute, second = 0] = parts;
    if (year < 1 || month < 1 || month > 12 || day < 1 || hour > 23 || minute > 59
        || (precision === 'second' && second > 59)) return false;
    const date = new Date(Date.UTC(year, month - 1, day, hour, minute, second));
    return date.getUTCFullYear() === year
        && date.getUTCMonth() === month - 1
        && date.getUTCDate() === day
        && date.getUTCHours() === hour
        && date.getUTCMinutes() === minute
        && date.getUTCSeconds() === second;
}

function parseGeneratedAt(value) {
    if (value instanceof Date && !Number.isNaN(value.getTime())) return value.toISOString();
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/.test(value)) {
        throw parseError('artifact_generated_at', 'Artifact generated_at must be an explicit UTC timestamp.');
    }
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        throw parseError('artifact_generated_at', 'Artifact generated_at is invalid.');
    }
    return value;
}

function compareRevisions(left, right) {
    return compareStrings(left.observed_at, right.observed_at)
        || (SOURCE_ORDER.get(left.source) ?? 99) - (SOURCE_ORDER.get(right.source) ?? 99)
        || left.entry_id - right.entry_id;
}

function orderedSources(values) {
    return [...new Set(values.map((source) => normalizeSource(source, 'artifact_source')))]
        .sort((left, right) => (SOURCE_ORDER.get(left) ?? 99) - (SOURCE_ORDER.get(right) ?? 99));
}

function normalizeSource(value, code) {
    const normalized = normalizeDisplayText(String(value ?? ''));
    if (!SOURCE_ORDER.has(normalized)) {
        throw parseError(code, 'Lucy item source must be Live or Test.', { source: normalized });
    }
    return normalized;
}

function normalizeObservedPrecision(value) {
    if (!['minute', 'second'].includes(value)) {
        throw parseError('artifact_observed_precision', 'Revision observed_precision must be minute or second.');
    }
    return value;
}

function normalizeRevisionType(value) {
    if (!['initial', 'changed'].includes(value)) {
        throw parseError('artifact_revision_type', 'Revision type must be initial or changed.');
    }
    return value;
}

function normalizeArtifactChange(change) {
    if (!isRecord(change) || !['initial', 'added', 'removed', 'changed', 'unknown'].includes(change.operation)
        || typeof change.display !== 'string') {
        throw parseError('artifact_change', 'Artifact contains an invalid history change.');
    }
    const iconIds = change.icon_ids ?? [];
    if (!Array.isArray(iconIds) || iconIds.some((iconId) => !Number.isSafeInteger(iconId) || iconId < 0)) {
        throw parseError('artifact_change_icons', 'Artifact change icon ids must be non-negative integers.');
    }

    return {
        operation: change.operation,
        field: change.field ?? null,
        before: change.before ?? null,
        after: change.after ?? null,
        display: change.display,
        ...(iconIds.length === 0 ? {} : { icon_ids: [...iconIds] }),
    };
}

function normalizeReconstructionRevision(revision) {
    if (!isRecord(revision) || !Array.isArray(revision.changes) || revision.changes.length === 0) {
        throw parseError('reconstruction_revision', 'Reversible reconstruction requires nonempty revision change arrays.');
    }
    return {
        entry_id: requireItemId(revision.entry_id, 'history entry id'),
        source: normalizeSource(revision.source, 'reconstruction_source'),
        observed_at: parseIsoNaiveTimestamp(revision.observed_at, 'history timestamp'),
        observed_precision: normalizeObservedPrecision(revision.observed_precision),
        type: normalizeRevisionType(revision.type),
        changes: revision.changes.map(normalizeArtifactChange),
    };
}

function analyzeReversibleSourceChain(source, revisions) {
    const fieldStates = new Map();
    const trackedFields = new Set();
    const entryIds = new Set();
    let initialSeen = false;
    let changeCount = 0;
    let continuityChecks = 0;

    for (let revisionIndex = 0; revisionIndex < revisions.length; revisionIndex += 1) {
        const revision = revisions[revisionIndex];
        if (entryIds.has(revision.entry_id)) {
            throw parseError('reconstruction_duplicate_entry', 'A source chain contains a duplicate revision entry id.', {
                source,
                entry_id: revision.entry_id,
            });
        }
        entryIds.add(revision.entry_id);

        const initialChanges = revision.changes.filter((change) => change.operation === 'initial');
        if (initialChanges.length > 0) {
            if (revision.type !== 'initial' || revision.changes.length !== 1
                || initialChanges[0].field !== null || initialChanges[0].before !== null
                || initialChanges[0].after !== null) {
                throw parseError('reconstruction_non_reversible_change', 'Initial history rows cannot be mixed with field transitions.', {
                    source,
                    entry_id: revision.entry_id,
                });
            }
            if (initialSeen || revisionIndex !== 0) {
                throw parseError('reconstruction_initial_conflict', 'A source chain contains a duplicate or out-of-order initial entry.', {
                    source,
                    entry_id: revision.entry_id,
                });
            }
            initialSeen = true;
            changeCount += 1;
            continue;
        }
        if (revision.type !== 'changed') {
            throw parseError('reconstruction_non_reversible_change', 'Changed history rows must contain reversible field transitions.', {
                source,
                entry_id: revision.entry_id,
            });
        }

        const revisionFields = new Set();
        for (const change of revision.changes) {
            if (change.operation === 'unknown') {
                throw parseError('reconstruction_unknown_change', 'Reversible reconstruction cannot accept an unknown history change.', {
                    source,
                    entry_id: revision.entry_id,
                    display: change.display,
                });
            }
            const transition = reversibleTransition(change, source, revision.entry_id);
            if (revisionFields.has(transition.field_key)) {
                throw parseError('reconstruction_duplicate_field', 'A revision changes the same field more than once.', {
                    source,
                    entry_id: revision.entry_id,
                    field: transition.field,
                });
            }
            revisionFields.add(transition.field_key);
            trackedFields.add(transition.field_key);
            changeCount += 1;

            const prior = fieldStates.get(transition.field_key);
            if (prior === undefined) {
                fieldStates.set(transition.field_key, {
                    state: transition.after,
                    transition,
                });
                continue;
            }

            if (sameTriState(prior.state, transition.before)) {
                continuityChecks += 1;
                fieldStates.set(transition.field_key, {
                    state: transition.after,
                    transition,
                });
                continue;
            }

            // Lucy occasionally retains two entry ids for the same effective
            // transition. It is still deterministic and reversible, provided
            // both the before and after assertions are byte-for-byte equal.
            if (sameTriState(prior.state, transition.after)
                && sameTransition(prior.transition, transition)) {
                continuityChecks += 1;
                continue;
            }

            throw parseError('reconstruction_continuity_conflict', 'A source-isolated field chain has conflicting adjacent values.', {
                source,
                entry_id: revision.entry_id,
                field: transition.field,
                expected_before: transition.before,
                actual_before: prior.state,
            });
        }
    }

    return {
        change_count: changeCount,
        tracked_field_count: trackedFields.size,
        continuity_checks: continuityChecks,
    };
}

function reversibleTransition(change, source, entryId) {
    if (!['added', 'removed', 'changed'].includes(change.operation)
        || typeof change.field !== 'string' || change.field === '' || change.field.trim() !== change.field) {
        throw parseError('reconstruction_non_reversible_change', 'History change is not a reversible field transition.', {
            source,
            entry_id: entryId,
            operation: change.operation,
            field: change.field,
        });
    }

    let before;
    let after;
    if (change.operation === 'added') {
        if (change.before !== null || typeof change.after !== 'string') {
            throw parseError('reconstruction_non_reversible_change', 'Added fields require an absent before-state and a string after-state.', {
                source,
                entry_id: entryId,
                field: change.field,
            });
        }
        before = { present: false };
        after = { present: true, value: change.after };
    } else if (change.operation === 'removed') {
        if (typeof change.before !== 'string' || change.after !== null) {
            throw parseError('reconstruction_non_reversible_change', 'Removed fields require a string before-state and an absent after-state.', {
                source,
                entry_id: entryId,
                field: change.field,
            });
        }
        before = { present: true, value: change.before };
        after = { present: false };
    } else {
        if (typeof change.before !== 'string' || typeof change.after !== 'string') {
            throw parseError('reconstruction_non_reversible_change', 'Changed fields require string before- and after-states.', {
                source,
                entry_id: entryId,
                field: change.field,
            });
        }
        before = { present: true, value: change.before };
        after = { present: true, value: change.after };
    }

    return { field: change.field, field_key: change.field.toLowerCase(), before, after };
}

function sameTriState(left, right) {
    return left.present === right.present && (!left.present || left.value === right.value);
}

function sameTransition(left, right) {
    return left.field_key === right.field_key
        && sameTriState(left.before, right.before)
        && sameTriState(left.after, right.after);
}

function collectHistoryCaptureSha256s(history) {
    const values = [];
    appendHistoryCaptureValues(values, history?.capture_sha256s, 'history captures');
    if (history?.capture_sha256 !== undefined) values.push(requireSha256(history.capture_sha256, 'history capture'));
    for (const revision of history?.revisions ?? []) {
        appendHistoryCaptureValues(values, revision?.history_capture_sha256s, 'revision history captures');
        if (revision?.history_capture_sha256 !== undefined) {
            values.push(requireSha256(revision.history_capture_sha256, 'revision history capture'));
        }
    }
    return [...new Set(values)].sort(compareStrings);
}

function appendHistoryCaptureValues(target, values, label) {
    if (values === undefined) return;
    if (!Array.isArray(values) || values.length === 0) {
        throw parseError('invalid_sha256', `${label} must be a nonempty array of SHA-256 digests.`);
    }
    for (const value of values) target.push(requireSha256(value, label));
}

function normalizeCurrentRaw(currentRaw, itemId) {
    const normalized = {};
    const entries = Array.isArray(currentRaw)
        ? currentRaw.map((value) => [value?.source, value])
        : Object.entries(currentRaw ?? {});
    for (const [key, value] of entries) {
        if (!isRecord(value)) throw parseError('artifact_current_raw', 'Current raw capture must be an object.');
        const source = normalizeSource(value.source ?? key, 'artifact_current_raw_source');
        if (key !== source && !Array.isArray(currentRaw)) {
            throw parseError('artifact_current_raw_source', 'Current raw object key differs from its source.', {
                key,
                source,
            });
        }
        if (value.item_id !== undefined && value.item_id !== itemId) {
            throw parseError('artifact_current_raw_item', 'Current raw capture belongs to another item.');
        }
        if (!isRecord(value.fields)) throw parseError('artifact_current_raw_fields', 'Current raw fields must be an object.');
        const fields = {};
        for (const [field, fieldValue] of Object.entries(value.fields)) {
            if (field.trim() === '' || typeof fieldValue !== 'string') {
                throw parseError('artifact_current_raw_fields', 'Current raw fields must map names to strings.');
            }
            fields[field] = fieldValue;
        }
        normalized[source] = {
            source,
            fields: sortObject(fields),
            ...(value.capture_sha256 === undefined
                ? {}
                : { capture_sha256: requireSha256(value.capture_sha256, 'raw capture') }),
        };
    }
    return Object.fromEntries(['Live', 'Test'].filter((source) => normalized[source] !== undefined)
        .map((source) => [source, normalized[source]]));
}

function normalizeDetailsInput(detailsByEntry) {
    if (detailsByEntry instanceof Map) return [...detailsByEntry.values()];
    if (Array.isArray(detailsByEntry)) return detailsByEntry;
    if (!isRecord(detailsByEntry)) {
        throw parseError('artifact_details', 'detailsByEntry must be a map, object, or array.');
    }
    return Object.entries(detailsByEntry).map(([rawEntryId, detail]) => {
        const entryId = parseCanonicalPositiveInteger(rawEntryId, 'artifact_entry_id', 'detailsByEntry key');
        if (!isRecord(detail)) throw parseError('artifact_entry', 'Artifact detail entry must be an object.');
        if (detail.entry_id !== undefined && detail.entry_id !== entryId) {
            throw parseError('artifact_entry_id', 'detailsByEntry key differs from its detail entry id.');
        }
        return { ...detail, entry_id: entryId };
    });
}

function normalizeRawInputForBuild(currentRaw, captures) {
    if (Array.isArray(currentRaw)) {
        return currentRaw.map((raw) => attachRawCapture(raw, captures));
    }
    if (!isRecord(currentRaw)) throw parseError('artifact_current_raw', 'currentRaw must be an object or array.');
    return Object.fromEntries(Object.entries(currentRaw).map(([source, raw]) => [
        source,
        attachRawCapture(raw, captures, source),
    ]));
}

function attachRawCapture(raw, captures, sourceOverride = null) {
    if (!isRecord(raw) || raw.capture_sha256 !== undefined) return raw;
    const source = raw.source ?? sourceOverride;
    const configured = captures?.current_raw?.[source] ?? captures?.raw?.[source];
    const digest = captureDigest(configured);
    return digest === null ? raw : { ...raw, capture_sha256: digest };
}

function captureDigest(value) {
    if (value === null || value === undefined) return null;
    if (typeof value === 'string') return requireSha256(value, 'capture');
    if (isRecord(value) && typeof value.sha256 === 'string') return requireSha256(value.sha256, 'capture');
    throw parseError('invalid_sha256', 'Capture metadata must be a SHA-256 string or an object containing sha256.');
}

function normalizeGaps(gaps) {
    const keyed = new Map();
    for (const gap of gaps) {
        if (!isRecord(gap) || typeof gap.reason !== 'string' || gap.reason.trim() === '') {
            throw parseError('artifact_gap', 'Artifact gaps must contain a reason.');
        }
        const normalized = {
            entry_id: requireItemId(gap.entry_id, 'gap entry id'),
            source: normalizeSource(gap.source, 'artifact_gap_source'),
            reason: gap.reason,
        };
        const key = `${normalized.entry_id}\0${normalized.source}\0${normalized.reason}`;
        keyed.set(key, normalized);
    }
    return [...keyed.values()].sort((left, right) => left.entry_id - right.entry_id
        || (SOURCE_ORDER.get(left.source) ?? 99) - (SOURCE_ORDER.get(right.source) ?? 99)
        || compareStrings(left.reason, right.reason));
}

function canonicalize(value) {
    if (Array.isArray(value)) return value.map(canonicalize);
    if (!isRecord(value)) return value;
    const result = {};
    for (const key of Object.keys(value).sort(compareStrings)) result[key] = canonicalize(value[key]);
    return result;
}

function sortObject(value) {
    return Object.fromEntries(Object.entries(value).sort(([left], [right]) => compareStrings(left, right)));
}

function normalizeDisplayText(value) {
    return String(value ?? '').replace(/\u00a0/g, ' ').replace(/[\t\r\n ]+/g, ' ').trim();
}

function normalizeLabel(value) {
    return normalizeDisplayText(value);
}

function normalizeRawValue(value) {
    return String(value ?? '').replace(/\u00a0/g, ' ').replace(/[\r\n\t]+/g, ' ').trim();
}

function parseCanonicalPositiveInteger(value, code, label) {
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) {
        throw parseError(code, `${label} must be a canonical positive decimal integer.`, { value });
    }
    const parsed = Number(value);
    if (!Number.isSafeInteger(parsed)) {
        throw parseError(code, `${label} exceeds JavaScript's safe integer range.`, { value });
    }
    return parsed;
}

function requireItemId(value, label) {
    if (!Number.isSafeInteger(value) || value < 1) {
        throw parseError('invalid_id', `${label} must be a positive safe integer.`, { value });
    }
    return value;
}

function requireSha256(value, label) {
    if (typeof value !== 'string' || !/^[a-f0-9]{64}$/.test(value)) {
        throw parseError('invalid_sha256', `${label} must be a lowercase SHA-256 digest.`);
    }
    return value;
}

function onlyValue(values, code, label) {
    if (values.size > 1) {
        throw parseError(code, `${label} contains conflicting item ids.`, { item_ids: [...values] });
    }
    return values.size === 0 ? null : values.values().next().value;
}

function compareStrings(left, right) {
    if (left === right) return 0;
    return left < right ? -1 : 1;
}

function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function parseError(code, message, context = {}) {
    return new LucyParseError(code, message, context);
}
