import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const sourceDirectory = path.resolve(process.argv[2] ?? '../patcheq');
const outputDirectory = path.resolve(process.argv[3] ?? 'database/data');

const monthNumbers = new Map([
    ['january', 1], ['jan', 1], ['february', 2], ['feb', 2], ['march', 3], ['mar', 3],
    ['april', 4], ['apr', 4], ['may', 5], ['june', 6], ['jun', 6], ['july', 7],
    ['jul', 7], ['august', 8], ['aug', 8], ['september', 9], ['sept', 9], ['sep', 9],
    ['october', 10], ['oct', 10], ['november', 11], ['nov', 11], ['december', 12], ['dcember', 12], ['dec', 12],
]);

const expansionTimeline = [
    ['1998-01-01', 'EverQuest Beta'],
    ['1999-03-16', 'Original EverQuest'],
    ['2000-04-24', 'The Ruins of Kunark'],
    ['2000-12-05', 'The Scars of Velious'],
    ['2001-12-04', 'The Shadows of Luclin'],
    ['2002-10-29', 'The Planes of Power'],
    ['2003-02-25', 'The Legacy of Ykesha'],
    ['2003-09-09', 'Lost Dungeons of Norrath'],
    ['2004-02-10', 'Gates of Discord'],
    ['2004-09-14', 'Omens of War'],
    ['2005-02-15', 'Dragons of Norrath'],
    ['2005-09-13', 'Depths of Darkhollow'],
    ['2006-02-21', 'Prophecy of Ro'],
    ['2006-09-19', "The Serpent's Spine"],
    ['2007-02-13', 'The Buried Sea'],
    ['2007-11-13', 'Secrets of Faydwer'],
    ['2008-10-21', 'Seeds of Destruction'],
    ['2009-12-15', 'Underfoot'],
    ['2010-10-12', 'House of Thule'],
    ['2011-11-15', 'Veil of Alaris'],
    ['2012-11-28', 'Rain of Fear'],
    ['2013-10-08', 'Call of the Forsaken'],
    ['2014-10-28', 'The Darkened Sea'],
    ['2015-11-18', 'The Broken Mirror'],
    ['2016-11-16', 'Empires of Kunark'],
    ['2017-12-12', 'Ring of Scale'],
    ['2018-12-11', 'The Burning Lands'],
    ['2019-12-18', 'Torment of Velious'],
    ['2020-12-08', 'Claws of Veeshan'],
    ['2021-12-07', 'Terror of Luclin'],
];

const categoryRules = [
    ['bug-fixes', 'Bug Fixes', /\b(?:bug fixes?|fixed|corrected an issue|crash(?:es|ing)?|exploit)\b/i],
    ['classes', 'Classes', /\b(?:class changes?|warrior|cleric|paladin|ranger|shadowknight|druid|monk|bard|rogue|shaman|necromancer|wizard|magician|enchanter|beastlord|berserker)\b/i],
    ['spells-aa', 'Spells & AA', /\b(?:spells?|alternate advancement|\bAA\b|abilities)\b/i],
    ['items', 'Items', /\b(?:items?|weapons?|armor|augmentations?|loot)\b/i],
    ['zones-npcs', 'Zones & NPCs', /\b(?:zones?|NPCs?|encounters?|mobs?|creatures?)\b/i],
    ['quests-events', 'Quests & Events', /\b(?:quests?|missions?|raids?|events?|anniversary)\b/i],
    ['tradeskills', 'Tradeskills', /\b(?:tradeskills?|recipes?|crafting|forage|fishing)\b/i],
    ['ui', 'UI', /\b(?:interface|UI files?|windows?|hotbuttons?|display)\b/i],
    ['pvp', 'PvP', /\b(?:PvP|player.?versus.?player|player kill)\b/i],
    ['servers', 'Servers', /\b(?:servers?|stability|infrastructure|network|login)\b/i],
    ['audio-graphics', 'Audio & Graphics', /\b(?:audio|sound|music|graphics?|models?|DirectX|render)\b/i],
    ['expansions', 'Expansions', /\b(?:expansion|launches|pre.?order|Kunark|Velious|Luclin|Planes of Power|Ykesha|Dungeons of Norrath|Gates of Discord|Omens of War|Depths of Darkhollow|Prophecy of Ro|Serpent's Spine|Buried Sea|Faydwer|Seeds of Destruction|Underfoot|House of Thule|Veil of Alaris|Rain of Fear|Call of the Forsaken|Darkened Sea|Broken Mirror|Empires of Kunark|Ring of Scale|Burning Lands|Torment of Velious|Claws of Veeshan|Terror of Luclin)\b/i],
];

function sha256(value) {
    return createHash('sha256').update(value).digest('hex');
}

function normalizeLineEndings(value) {
    return value.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
}

const windows1252ControlMap = new Map([
    [0x80, '€'], [0x82, '‚'], [0x83, 'ƒ'], [0x84, '„'], [0x85, '…'], [0x86, '†'], [0x87, '‡'],
    [0x88, 'ˆ'], [0x89, '‰'], [0x8a, 'Š'], [0x8b, '‹'], [0x8c, 'Œ'], [0x8e, 'Ž'], [0x91, '‘'],
    [0x92, '’'], [0x93, '“'], [0x94, '”'], [0x95, '•'], [0x96, '–'], [0x97, '—'], [0x98, '˜'],
    [0x99, '™'], [0x9a, 'š'], [0x9b, '›'], [0x9c, 'œ'], [0x9e, 'ž'], [0x9f, 'Ÿ'],
]);

function repairWindows1252Controls(value) {
    let recovered = 0;
    const text = value.replace(/[\u0080-\u009f]/g, character => {
        const replacement = windows1252ControlMap.get(character.codePointAt(0));
        if (replacement === undefined) return character;
        recovered += 1;
        return replacement;
    });
    return { text, recovered };
}

function decodeHistoricalText(bytes) {
    const utf8 = new TextDecoder('utf-8', { fatal: true });
    try {
        const repaired = repairWindows1252Controls(utf8.decode(bytes));
        return {
            text: repaired.text.normalize('NFC'),
            encoding: repaired.recovered ? 'utf-8 with windows-1252 control recovery' : 'utf-8',
            warnings: repaired.recovered
                ? [`Recovered ${repaired.recovered} embedded Windows-1252 control code${repaired.recovered === 1 ? '' : 's'}.`]
                : [],
        };
    } catch {
        const windows1252 = new TextDecoder('windows-1252');
        let text = '';
        let replacements = 0;
        for (let index = 0; index < bytes.length;) {
            const byte = bytes[index];
            if (byte < 0x80) {
                text += String.fromCharCode(byte);
                index += 1;
                continue;
            }

            const sequenceLength = byte >= 0xc2 && byte <= 0xdf ? 2
                : byte >= 0xe0 && byte <= 0xef ? 3
                    : byte >= 0xf0 && byte <= 0xf4 ? 4 : 0;
            if (sequenceLength > 0 && index + sequenceLength <= bytes.length) {
                try {
                    text += utf8.decode(bytes.subarray(index, index + sequenceLength));
                    index += sequenceLength;
                    continue;
                } catch {
                    // This high byte is a Windows-1252 singleton, handled below.
                }
            }

            text += windows1252.decode(bytes.subarray(index, index + 1));
            replacements += 1;
            index += 1;
        }
        const repaired = repairWindows1252Controls(text);
        return {
            text: repaired.text.normalize('NFC'),
            encoding: 'mixed utf-8/windows-1252',
            warnings: [
                `Recovered ${replacements} Windows-1252 singleton byte${replacements === 1 ? '' : 's'} from mixed historical text.`,
                ...(repaired.recovered
                    ? [`Recovered ${repaired.recovered} embedded Windows-1252 control code${repaired.recovered === 1 ? '' : 's'}.`]
                    : []),
            ],
        };
    }
}

function normalizeForHash(value) {
    return normalizeLineEndings(value)
        .replace(/[\u00a0\u00b7]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .toLocaleLowerCase('en-US');
}

function normalizeForDuplicate(value) {
    return normalizeForHash(value)
        .replace(/[®™©]/g, '')
        .normalize('NFKD')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function inferYear(filename) {
    const year = filename.match(/(?:patches[-_])?(19\d{2}|20\d{2})/i)?.[1];
    if (year) return Number(year);
    if (/phase3beta/i.test(filename)) return 1998;
    if (/phase4beta/i.test(filename)) return 1999;
    return null;
}

function describeSource(filename, extension, parsedRecords) {
    if (filename === 'Patch_Summaries.pdf') {
        return 'A 15-page ZAM highlights summary covering April 1999 through June 2007; its text is extracted as curated context, not duplicated as full patch records.';
    }
    if (/patches_1999-2008_combined\.md/i.test(filename)) {
        return 'An alternate Markdown rendition of early patch history; retained as corroborating provenance because its dated heading structure is incomplete.';
    }
    if (/combined\.txt$/i.test(filename)) {
        return 'A combined archival text used to corroborate canonical notes and recover unique supplemental news entries.';
    }
    if (filename === 'README.md') return 'The supplied corpus README, retained even though its stated end date predates files present in the archive.';
    if (filename === 'last.md') return 'A supplied pointer to the Allakhazam archive, retained as supporting provenance.';
    if (filename === 'patch.txt') return 'A supplied CRLF-only placeholder, retained in the manifest rather than discarded.';
    if (parsedRecords > 0) return 'A canonical chronological source parsed into archival records with occurrence-level offsets.';
    if (extension === '.md') return 'Supporting Markdown material retained in the source manifest.';
    return 'Supporting source artifact retained in the source manifest.';
}

function parseDateLabel(label, inferredYear) {
    const clean = label
        .replace(/^#+\s*/, '')
        .replace(/^[\s*_]+|[\s*_]+$/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    const numeric = clean.match(/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/);
    if (numeric) {
        let year = Number(numeric[3]);
        if (year < 100) year += year >= 80 ? 1900 : 2000;
        return validDate(year, Number(numeric[1]), Number(numeric[2]), clean);
    }

    const named = clean.match(/\b(January|February|March|April|May|June|July|August|September|Sept|October|November|December|Dcember|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*,)?(?:\s+(\d{4}))?/i);
    if (!named) return null;

    const year = named[3] ? Number(named[3]) : inferredYear;
    if (!year) return null;
    const parsed = validDate(year, monthNumbers.get(named[1].toLocaleLowerCase('en-US')), Number(named[2]), clean);
    if (parsed) parsed.year_inferred = !named[3];
    return parsed;
}

function validDate(year, month, day, label) {
    if (!year || !month || day < 1 || day > 31) return null;
    const candidate = new Date(Date.UTC(year, month - 1, day));
    if (candidate.getUTCFullYear() !== year || candidate.getUTCMonth() !== month - 1 || candidate.getUTCDate() !== day) return null;
    return {
        date: `${year.toString().padStart(4, '0')}-${month.toString().padStart(2, '0')}-${day.toString().padStart(2, '0')}`,
        label,
    };
}

function cleanupContent(value) {
    let content = normalizeLineEndings(value).trim();
    content = content.split(/\n\s*\.{12,}/, 1)[0].trim();
    content = content.replace(/Patch Messages from [^\n]+$/i, '').trim();
    content = content.replace(/(?:\n\s*(?:\.{12,}|-{12,})\s*)+$/g, '').trim();
    content = content.replace(/^\s*(?:\.{12,}|-{12,})\s*\n/g, '').trim();
    return content;
}

function extractRecords(text, filename) {
    let contextualYear = inferYear(filename);
    const source = normalizeLineEndings(text);
    const headingPattern = /^([^\n]{3,180})(?:\nSource:\s*([^\n]+))?\n-{5,}\s*$/gm;
    const headings = [];
    let match;

    while ((match = headingPattern.exec(source)) !== null) {
        const parsed = parseDateLabel(match[1], contextualYear);
        if (!parsed) continue;
        contextualYear = Number(parsed.date.slice(0, 4));
        headings.push({
            start: match.index,
            end: headingPattern.lastIndex,
            originalLabel: match[1].trim(),
            sourceUrl: match[2]?.trim() ?? null,
            ...parsed,
        });
    }

    return headings.flatMap((heading, index) => {
        const next = headings[index + 1];
        let content = cleanupContent(source.slice(heading.end, next?.start ?? source.length));
        const trailingSource = content.match(/^Source:\s*(https?:\/\/\S+)\s*\n/i);
        const sourceUrl = heading.sourceUrl ?? trailingSource?.[1] ?? null;
        if (trailingSource) content = content.slice(trailingSource[0].length).trim();
        if (content.length < 12) return [];
        return [{
            patch_date: heading.date,
            display_date: heading.originalLabel.replace(/\s+/g, ' ').trim(),
            content,
            source_file: filename,
            source_offset: heading.start,
            source_url: sourceUrl,
            year_inferred: heading.year_inferred ?? false,
        }];
    });
}

function recordKind(record) {
    if (/beta/i.test(record.source_file) || record.patch_date < '1999-03-16') return 'beta';
    if (/\bhotfix\b|emergency (?:update|patch)/i.test(record.display_date)) return 'hotfix';
    if (/news bit|news story|press release/i.test(record.display_date)) return 'news';
    return 'live';
}

function expansionCode(name) {
    return name.toLocaleLowerCase('en-US')
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

function expansionFor(date) {
    let expansion = expansionTimeline[0][1];
    for (const [start, name] of expansionTimeline) {
        if (date < start) break;
        expansion = name;
    }
    return expansion;
}

function effectiveDateFor(record) {
    const rescheduled = record.display_date.match(/\brescheduled\s+to\s+([^)]{3,80})/i);
    if (!rescheduled) return record.patch_date;
    return parseDateLabel(rescheduled[1], Number(record.patch_date.slice(0, 4)))?.date ?? record.patch_date;
}

function deriveSections(content) {
    const sections = [];
    const seen = new Set();
    const lines = normalizeLineEndings(content).split('\n');

    for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line || line.length > 90) continue;
        let heading = null;
        const starred = line.match(/^(?:#{2,5}\s*)?\*{1,4}\s*([^*]{2,80}?)\s*\*{1,4}\s*$/);
        const colon = line.match(/^([A-Za-z][A-Za-z &/'-]{2,60}):$/);
        if (starred) heading = starred[1];
        else if (colon) heading = colon[1];
        if (!heading) continue;

        heading = heading.replace(/[_-]{3,}/g, '').replace(/\s+/g, ' ').trim();
        if (!heading || seen.has(heading.toLocaleLowerCase('en-US'))) continue;
        seen.add(heading.toLocaleLowerCase('en-US'));
        sections.push(heading);
        if (sections.length === 30) break;
    }
    return sections;
}

function deriveCategories(record, sections) {
    const categoryText = `${record.display_date}\n${sections.join('\n')}\n${record.content}`;
    const categories = categoryRules
        .filter(([, , pattern]) => pattern.test(categoryText))
        .map(([slug, label]) => ({ slug, label }));

    if (recordKind(record) === 'beta') categories.unshift({ slug: 'beta', label: 'Beta' });
    return categories;
}

function summarize(content) {
    const plain = content
        .replace(/^\s*(?:\*{1,4}|#{1,6})\s*/gm, '')
        .replace(/^\s*(?:[-*+]|\d+[.)])\s+/gm, '')
        .replace(/\s+/g, ' ')
        .trim();
    if (plain.length <= 300) return plain;
    const clipped = plain.slice(0, 300);
    const boundary = clipped.lastIndexOf(' ');
    return `${clipped.slice(0, boundary > 220 ? boundary : 300).trim()}…`;
}

function countChanges(content) {
    const matches = normalizeLineEndings(content).match(/^\s*(?:[-*+]|\d+[.)])\s+\S/gm);
    return matches?.length ?? 0;
}

function titleFor(record) {
    return record.display_date
        .replace(/^\s*(?:hotfix notes?|test update notes?|update notes?)\s*:\s*/i, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function sourcePriority(filename) {
    if (/phase\d+beta/i.test(filename)) return 40;
    if (/^patches-\d{4}(?:-\d)?\.txt$/i.test(filename)) return 30;
    if (/combined\.txt$/i.test(filename)) return 20;
    return 10;
}

function mergeExactDuplicates(records) {
    const canonical = new Map();

    for (const record of records) {
        const contentKey = sha256(`${record.patch_date}\n${normalizeForDuplicate(record.content)}`);
        const existing = canonical.get(contentKey);
        if (!existing) {
            canonical.set(contentKey, {
                ...record,
                source_files: [record.source_file],
                source_titles: [record.display_date],
                source_urls: record.source_url ? [record.source_url] : [],
                source_occurrences: [{
                    occurrence_id: sha256(`${record.source_file}\n${record.source_offset}\n${record.display_date}`),
                    filename: record.source_file,
                    source_offset: record.source_offset,
                    heading: record.display_date,
                    source_url: record.source_url,
                    year_inferred: record.year_inferred,
                }],
                content_hash: sha256(normalizeForHash(record.content)),
            });
            continue;
        }

        existing.source_files.push(record.source_file);
        existing.source_titles.push(record.display_date);
        if (record.source_url) existing.source_urls.push(record.source_url);
        existing.source_occurrences.push({
            occurrence_id: sha256(`${record.source_file}\n${record.source_offset}\n${record.display_date}`),
            filename: record.source_file,
            source_offset: record.source_offset,
            heading: record.display_date,
            source_url: record.source_url,
            year_inferred: record.year_inferred,
        });
        existing.year_inferred = existing.year_inferred && record.year_inferred;
        const hasDescriptiveKind = /news bit|news story|press release|hotfix/i.test(record.display_date);
        if (sourcePriority(record.source_file) > sourcePriority(existing.source_file)) {
            existing.display_date = record.display_date;
            existing.content = record.content;
            existing.source_file = record.source_file;
            existing.source_offset = record.source_offset;
            existing.source_url = record.source_url;
        } else if (hasDescriptiveKind && !/news bit|news story|press release|hotfix/i.test(existing.display_date)) {
            existing.display_date = record.display_date;
        }
    }

    return [...canonical.values()].map(record => ({
        ...record,
        source_files: [...new Set(record.source_files)].sort(),
        source_titles: [...new Set(record.source_titles)].sort(),
        source_urls: [...new Set(record.source_urls)].sort(),
        source_occurrences: record.source_occurrences.sort((left, right) =>
            left.filename.localeCompare(right.filename) || left.source_offset - right.source_offset
        ),
    }));
}

function annotateRecords(records) {
    const sorted = records.sort((left, right) => {
        const dateOrder = left.patch_date.localeCompare(right.patch_date);
        if (dateOrder !== 0) return dateOrder;
        const labelOrder = left.display_date.localeCompare(right.display_date);
        if (labelOrder !== 0) return labelOrder;
        return left.content_hash.localeCompare(right.content_hash);
    });

    const sequences = new Map();
    return sorted.map(record => {
        const sequence = (sequences.get(record.patch_date) ?? 0) + 1;
        sequences.set(record.patch_date, sequence);
        const sections = deriveSections(record.content);
        const kind = recordKind(record);
        return {
            id: `${record.patch_date}-${sequence}`,
            slug: `${record.patch_date}-${sequence}`,
            patch_date: record.patch_date,
            effective_date: effectiveDateFor(record),
            display_date: record.display_date,
            title: titleFor(record),
            sequence,
            year: Number(record.patch_date.slice(0, 4)),
            month: Number(record.patch_date.slice(5, 7)),
            kind,
            era: kind === 'beta' ? 'EverQuest Beta' : 'EverQuest Live',
            expansion: expansionFor(record.patch_date),
            expansion_code: expansionCode(expansionFor(record.patch_date)),
            categories: deriveCategories(record, sections),
            sections,
            change_count: countChanges(record.content),
            word_count: record.content.split(/\s+/).filter(Boolean).length,
            summary: summarize(record.content),
            content: record.content,
            content_hash: record.content_hash,
            source_files: record.source_files,
            source_titles: record.source_titles,
            source_url: record.source_url,
            source_urls: record.source_urls,
            occurrence_count: record.source_occurrences.length,
            source_occurrences: record.source_occurrences,
            year_inferred: record.year_inferred,
        };
    });
}

function csvCell(value) {
    let text = Array.isArray(value) ? value.join('|') : String(value ?? '');
    if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
    return `"${text.replaceAll('"', '""')}"`;
}

function buildCsv(records) {
    const headers = [
        'id', 'slug', 'patch_date', 'effective_date', 'display_date', 'title', 'sequence', 'year', 'month',
        'kind', 'era', 'expansion', 'expansion_code', 'categories', 'sections', 'change_count', 'word_count',
        'summary', 'content', 'content_hash', 'source_files', 'source_titles', 'source_url', 'source_urls',
        'occurrence_count', 'source_occurrences', 'year_inferred',
    ];
    const rows = records.map(record => headers.map(header => {
        if (header === 'categories') return csvCell(record.categories.map(category => category.label));
        if (header === 'source_occurrences') return csvCell(JSON.stringify(record.source_occurrences));
        return csvCell(record[header]);
    }).join(','));
    return `\uFEFF${headers.map(csvCell).join(',')}\r\n${rows.join('\r\n')}\r\n`;
}

function buildSuggestionIndex(records, generatedAt) {
    return {
        schema_version: 1,
        generated_at: generatedAt,
        record_count: records.length,
        patches: records.map(record => ({
            slug: record.slug,
            title: record.title,
            patch_date: record.patch_date,
            search_text: [
                record.title,
                record.display_date,
                record.summary,
                record.expansion,
                ...record.categories.map(category => category.label),
            ].join('\n'),
        })),
    };
}

async function main() {
    const filenames = (await readdir(sourceDirectory)).sort();
    const inventory = [];
    const extracted = [];
    let summaryExtraction = null;
    try {
        summaryExtraction = JSON.parse(await readFile(path.join(outputDirectory, 'patch-summary-highlights.json'), 'utf8'));
    } catch {
        // The optional semantic PDF extraction is produced by extract-patch-summary.py.
    }

    for (const filename of filenames) {
        if (filename === '.git') continue;
        const absolutePath = path.join(sourceDirectory, filename);
        const details = await stat(absolutePath);
        if (!details.isFile()) continue;
        const bytes = await readFile(absolutePath);
        const fileSha256 = sha256(bytes);
        const extension = path.extname(filename).toLocaleLowerCase('en-US');
        if (filename === 'Patch_Summaries.pdf' && summaryExtraction?.source_sha256 !== fileSha256) {
            throw new Error('Patch_Summaries.pdf has no matching semantic extraction. Run extract-patch-summary.py before rebuilding the archive.');
        }
        let parsedRecords = [];
        let decoded = null;

        if (extension === '.txt' && /^patches/i.test(filename) && bytes.length > 12) {
            decoded = decodeHistoricalText(bytes);
            parsedRecords = extractRecords(decoded.text, filename);
            extracted.push(...parsedRecords);
        }

        inventory.push({
            filename,
            bytes: bytes.length,
            sha256: fileSha256,
            parsed_records: parsedRecords.length,
            detected_encoding: decoded?.encoding ?? (['.txt', '.md'].includes(extension) ? decodeHistoricalText(bytes).encoding : 'binary'),
            warnings: decoded?.warnings ?? [],
            role: parsedRecords.length > 0
                ? (/(?:combined|_combined)/i.test(filename) ? 'corroborating patch source' : 'canonical patch source')
                : (extension === '.pdf' ? 'curated historical highlights' : 'supporting archive material'),
            description: describeSource(filename, extension, parsedRecords.length),
            ...(filename === 'Patch_Summaries.pdf' && summaryExtraction?.source_sha256 === fileSha256
                ? { semantic_extraction: summaryExtraction }
                : {}),
        });
    }

    const records = annotateRecords(mergeExactDuplicates(extracted));
    const categoryCounts = new Map();
    const yearCounts = new Map();
    const kindCounts = new Map();
    for (const record of records) {
        yearCounts.set(record.year, (yearCounts.get(record.year) ?? 0) + 1);
        kindCounts.set(record.kind, (kindCounts.get(record.kind) ?? 0) + 1);
        for (const category of record.categories) categoryCounts.set(category.slug, (categoryCounts.get(category.slug) ?? 0) + 1);
    }

    const generatedAt = new Date().toISOString();
    const archive = {
        schema_version: 1,
        generated_at: generatedAt,
        title: 'EverQuest Historical Patch Archive',
        description: 'A searchable normalization of every distinct patch, beta, hotfix, and news entry in the supplied corpus, with a complete manifest of supporting artifacts.',
        record_count: records.length,
        filters: {},
        coverage: {
            first_patch: records.at(0)?.patch_date ?? null,
            last_patch: records.at(-1)?.patch_date ?? null,
            patch_count: records.length,
            source_file_count: inventory.length,
            source_bytes: inventory.reduce((sum, file) => sum + file.bytes, 0),
            years: Object.fromEntries([...yearCounts.entries()].sort(([a], [b]) => a - b)),
            kinds: Object.fromEntries([...kindCounts.entries()].sort()),
            categories: Object.fromEntries([...categoryCounts.entries()].sort()),
        },
        provenance: {
            supplied_directory_name: path.basename(sourceDirectory),
            note: 'Every supplied file is inventoried below. Raw and combined text sources are parsed; exact duplicate notes are merged while retaining each occurrence and source offset. The PDF highlights are semantically extracted as supporting context, while the duplicate Markdown rendition is described and retained as corroborating provenance rather than duplicated as patch records.',
            files: inventory,
        },
        patches: records,
    };

    await mkdir(outputDirectory, { recursive: true });
    const jsonPath = path.join(outputDirectory, 'everquest-patch-history.json');
    const csvPath = path.join(outputDirectory, 'everquest-patch-history.csv');
    const suggestionPath = path.join(outputDirectory, 'everquest-patch-suggestions.json');
    await writeFile(jsonPath, `${JSON.stringify(archive, null, 2)}\n`, 'utf8');
    await writeFile(csvPath, buildCsv(records), 'utf8');
    await writeFile(suggestionPath, `${JSON.stringify(buildSuggestionIndex(records, generatedAt))}\n`, 'utf8');

    process.stdout.write(`${JSON.stringify({
        sourceDirectory,
        jsonPath,
        csvPath,
        suggestionPath,
        extractedRecords: extracted.length,
        canonicalRecords: records.length,
        firstPatch: archive.coverage.first_patch,
        lastPatch: archive.coverage.last_patch,
        sourceFiles: inventory.length,
    }, null, 2)}\n`);
}

await main();
