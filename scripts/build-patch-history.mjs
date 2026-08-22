import { createHash, randomUUID } from 'node:crypto';
import { spawn } from 'node:child_process';
import { constants } from 'node:fs';
import { copyFile, lstat, mkdir, open, readFile, readdir, rename, stat, unlink } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const scriptDirectory = path.dirname(fileURLToPath(import.meta.url));
const defaultConfigPath = path.join(scriptDirectory, 'patch-import.config.json');

const helpText = `Usage:
  node scripts/build-patch-history.mjs [source-directory] [output-directory] [options]

Options:
  --config PATH          Merge a JSON override onto the safe importer defaults.
  --prior-archive PATH   Reuse and validate identities from this archive (must exist).
  --report PATH          Write the report here (explicit paths also write during dry-run).
  --python COMMAND       Python interpreter used by PDF/DOCX/HTML/feed adapters.
  --strict               Fail when a source or structured record is rejected.
  --dry-run              Parse and validate without replacing any artifacts.
  --allow-removals       Permit reviewed removal of existing public slugs.
  --no-stable-slugs      Disable prior identity reuse (not recommended).
  -h, --help             Show this help.
`;

const monthNumbers = new Map([
    ['january', 1], ['jan', 1], ['february', 2], ['feb', 2], ['march', 3], ['mar', 3],
    ['april', 4], ['apr', 4], ['may', 5], ['june', 6], ['jun', 6], ['july', 7],
    ['jul', 7], ['august', 8], ['aug', 8], ['september', 9], ['sept', 9], ['sep', 9],
    ['october', 10], ['oct', 10], ['november', 11], ['nov', 11], ['december', 12], ['dcember', 12], ['dec', 12],
]);

function sha256(value) {
    return createHash('sha256').update(value).digest('hex');
}

function stableStringify(value) {
    if (Array.isArray(value)) return `[${value.map(stableStringify).join(',')}]`;
    if (value && typeof value === 'object') {
        return `{${Object.keys(value).sort().map(key => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`;
    }
    return JSON.stringify(value);
}

function deepMerge(base, override) {
    if (Array.isArray(override)) return [...override];
    if (!override || typeof override !== 'object') return override;
    const merged = { ...(base && typeof base === 'object' && !Array.isArray(base) ? base : {}) };
    for (const [key, value] of Object.entries(override)) {
        merged[key] = value && typeof value === 'object' && !Array.isArray(value)
            ? deepMerge(merged[key], value)
            : Array.isArray(value) ? [...value] : value;
    }
    return merged;
}

function parseArguments(argv) {
    const positional = [];
    const options = {};
    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];
        if (argument === '--help' || argument === '-h') {
            options.help = true;
            continue;
        }
        if (!argument.startsWith('--')) {
            positional.push(argument);
            continue;
        }
        if (argument === '--no-stable-slugs') {
            options.stableSlugs = false;
            continue;
        }
        if (argument === '--strict') {
            options.strict = true;
            continue;
        }
        if (argument === '--dry-run') {
            options.dryRun = true;
            continue;
        }
        if (argument === '--allow-removals') {
            options.allowRemovals = true;
            continue;
        }
        const name = argument.slice(2);
        if (!['config', 'report', 'prior-archive', 'python'].includes(name)) {
            throw new Error(`Unknown option: ${argument}`);
        }
        const value = argv[index + 1];
        if (!value || value.startsWith('--')) throw new Error(`${argument} requires a value.`);
        options[name.replaceAll('-', '_')] = value;
        index += 1;
    }
    if (positional.length > 2) throw new Error('Expected at most source and output positional arguments.');
    const sourceDirectory = path.resolve(positional[0] ?? '../patcheq');
    const outputDirectory = path.resolve(positional[1] ?? 'database/data');
    return {
        sourceDirectory,
        outputDirectory,
        configPath: path.resolve(options.config ?? process.env.PATCH_IMPORT_CONFIG ?? defaultConfigPath),
        reportPath: path.resolve(options.report ?? path.join(outputDirectory, 'everquest-patch-import-report.json')),
        reportExplicit: Object.hasOwn(options, 'report'),
        priorArchivePath: path.resolve(options.prior_archive ?? path.join(outputDirectory, 'everquest-patch-history.json')),
        priorArchiveExplicit: Object.hasOwn(options, 'prior_archive'),
        pythonCommand: options.python ?? process.env.PATCH_IMPORT_PYTHON ?? null,
        stableSlugs: options.stableSlugs ?? true,
        strict: options.strict ?? false,
        dryRun: options.dryRun ?? false,
        allowRemovals: options.allowRemovals ?? false,
        help: options.help ?? false,
    };
}

async function loadConfig(configPath) {
    const defaults = JSON.parse(await readFile(defaultConfigPath, 'utf8'));
    const override = path.resolve(configPath) === path.resolve(defaultConfigPath)
        ? {}
        : JSON.parse(await readFile(configPath, 'utf8'));
    const config = deepMerge(defaults, override);
    if (Array.isArray(override.source_overrides)) {
        config.source_overrides = [...(defaults.source_overrides ?? []), ...override.source_overrides];
    }
    if (config.schema_version !== 1) throw new Error(`Unsupported importer config schema: ${config.schema_version}`);
    if (!Array.isArray(config.expansion_timeline) || config.expansion_timeline.length === 0) {
        throw new Error('Importer config requires a non-empty expansion_timeline array.');
    }
    const timeline = [...config.expansion_timeline].sort((left, right) => left.start.localeCompare(right.start));
    for (const entry of timeline) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(entry.start) || !entry.name) {
            throw new Error(`Invalid expansion timeline entry: ${JSON.stringify(entry)}`);
        }
        if (entry.end && !/^\d{4}-\d{2}-\d{2}$/.test(entry.end)) {
            throw new Error(`Invalid expansion end date: ${JSON.stringify(entry)}`);
        }
    }
    const positiveInteger = (value, label) => {
        if (!Number.isSafeInteger(value) || value < 1) throw new Error(`${label} must be a positive safe integer.`);
    };
    const maximumRemovalPercent = config.safety?.maximum_removal_percent ?? 0;
    if (typeof maximumRemovalPercent !== 'number' || !Number.isFinite(maximumRemovalPercent)
        || maximumRemovalPercent < 0 || maximumRemovalPercent > 100) {
        throw new Error('safety.maximum_removal_percent must be a number from 0 through 100.');
    }
    positiveInteger(config.safety?.adapter_timeout_seconds ?? 60, 'safety.adapter_timeout_seconds');
    positiveInteger(config.safety?.maximum_adapter_output_bytes ?? 64 * 1024 * 1024, 'safety.maximum_adapter_output_bytes');
    for (const [name, adapter] of Object.entries(config.adapters ?? {})) {
        for (const key of [
            'minimum_text_characters', 'max_items', 'max_pages', 'max_text_characters',
            'max_xml_bytes', 'max_archive_entries', 'max_docx_compressed_bytes',
            'max_uncompressed_bytes', 'max_docx_entry_compressed_bytes',
            'max_docx_entry_uncompressed_bytes', 'max_docx_paragraphs',
        ]) {
            if (adapter[key] !== undefined) positiveInteger(adapter[key], `adapters.${name}.${key}`);
        }
        if (adapter.max_compression_ratio !== undefined
            && (typeof adapter.max_compression_ratio !== 'number' || !Number.isFinite(adapter.max_compression_ratio)
                || adapter.max_compression_ratio <= 0)) {
            throw new Error(`adapters.${name}.max_compression_ratio must be a positive number.`);
        }
    }
    const categoryRules = (config.category_rules ?? []).map(rule => ({
        ...rule,
        expression: new RegExp(rule.pattern, rule.flags ?? 'i'),
    }));
    const sourceOverrides = (config.source_overrides ?? []).map(rule => ({
        ...rule,
        expression: new RegExp(rule.pattern, rule.flags ?? 'i'),
    }));
    return {
        ...config,
        expansion_timeline: timeline,
        category_rules: categoryRules,
        source_overrides: sourceOverrides,
        config_path: path.resolve(configPath),
        config_directory: path.dirname(path.resolve(configPath)),
        fingerprint: sha256(stableStringify(config)),
    };
}

function normalizeRelativePath(value) {
    return value.split(path.sep).join('/').replace(/^\.\//, '');
}

function globToRegExp(glob) {
    const source = normalizeRelativePath(glob);
    let expression = '^';
    for (let index = 0; index < source.length; index += 1) {
        const character = source[index];
        if (character === '*' && source[index + 1] === '*') {
            index += 1;
            if (source[index + 1] === '/') {
                expression += '(?:.*/)?';
                index += 1;
            } else {
                expression += '.*';
            }
        } else if (character === '*') {
            expression += '[^/]*';
        } else if (character === '?') {
            expression += '[^/]';
        } else {
            expression += character.replace(/[\\^$.*+?()[\]{}|]/g, '\\$&');
        }
    }
    return new RegExp(`${expression}$`, 'i');
}

function matchesGlobs(relativePath, globs) {
    return (globs ?? []).some(glob => globToRegExp(glob).test(relativePath));
}

async function discoverSourceFiles(sourceDirectory, outputDirectory, config) {
    const files = [];
    const notices = [];
    const excludedDirectories = new Set((config.discovery?.exclude_directories ?? []).map(value => value.toLocaleLowerCase('en-US')));
    const includeGlobs = config.discovery?.include_globs ?? ['**/*'];
    const excludeGlobs = config.discovery?.exclude_globs ?? [];
    const outputRelative = path.relative(sourceDirectory, outputDirectory);
    const outputIsInsideSource = outputRelative && !outputRelative.startsWith('..') && !path.isAbsolute(outputRelative);

    async function walk(directory, relativeDirectory = '') {
        const entries = await readdir(directory, { withFileTypes: true });
        entries.sort((left, right) => left.name.localeCompare(right.name, 'en-US'));
        for (const entry of entries) {
            const absolutePath = path.join(directory, entry.name);
            const relativePath = normalizeRelativePath(path.join(relativeDirectory, entry.name));
            if (outputIsInsideSource && (relativePath === normalizeRelativePath(outputRelative) || relativePath.startsWith(`${normalizeRelativePath(outputRelative)}/`))) {
                notices.push({ path: relativePath, code: 'output_directory_excluded', message: 'Generated output directory was excluded from source discovery.' });
                continue;
            }
            if (entry.isSymbolicLink()) {
                notices.push({ path: relativePath, code: 'symlink_ignored', message: 'Symbolic links are not followed by the importer.' });
                continue;
            }
            if (entry.isDirectory()) {
                if (!excludedDirectories.has(entry.name.toLocaleLowerCase('en-US'))) await walk(absolutePath, relativePath);
                continue;
            }
            if (!entry.isFile()) continue;
            if (!matchesGlobs(relativePath, includeGlobs) || matchesGlobs(relativePath, excludeGlobs)) {
                notices.push({ path: relativePath, code: 'file_excluded', message: 'File was excluded by importer discovery globs.' });
                continue;
            }
            files.push({ absolutePath, relativePath });
        }
    }

    await walk(sourceDirectory);
    return {
        files: files.sort((left, right) => left.relativePath.localeCompare(right.relativePath, 'en-US')),
        notices: notices.sort((left, right) => left.path.localeCompare(right.path, 'en-US') || left.code.localeCompare(right.code)),
    };
}

function adapterForPath(relativePath, config) {
    const extension = path.extname(relativePath).toLocaleLowerCase('en-US');
    for (const [name, adapter] of Object.entries(config.adapters ?? {})) {
        if (adapter.enabled !== false && (adapter.extensions ?? []).map(value => value.toLocaleLowerCase('en-US')).includes(extension)) {
            return { name, extension, ...adapter };
        }
    }
    return null;
}

function sourceSettings(relativePath, adapter, config) {
    const settings = {
        role: adapter ? 'canonical patch source' : 'unsupported source artifact',
        priority: adapter?.priority ?? 30,
        parse_records: adapter?.parse_records ?? true,
        admit_new_records: adapter?.admit_new_records ?? true,
        admit_excerpts: adapter?.admit_excerpts ?? false,
    };
    for (const override of config.source_overrides) {
        if (!override.expression.test(relativePath)) continue;
        for (const key of ['role', 'priority', 'parse_records', 'admit_new_records', 'admit_excerpts']) {
            if (override[key] !== undefined) settings[key] = override[key];
        }
    }
    return settings;
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

function detectUtf16Encoding(bytes) {
    if (bytes.length >= 2 && bytes[0] === 0xff && bytes[1] === 0xfe) return 'utf-16le';
    if (bytes.length >= 2 && bytes[0] === 0xfe && bytes[1] === 0xff) return 'utf-16be';
    const sampleLength = Math.min(bytes.length - (bytes.length % 2), 512);
    if (sampleLength < 8) return null;
    let evenNulls = 0;
    let oddNulls = 0;
    for (let index = 0; index < sampleLength; index += 2) {
        if (bytes[index] === 0) evenNulls += 1;
        if (bytes[index + 1] === 0) oddNulls += 1;
    }
    const pairs = sampleLength / 2;
    if (oddNulls / pairs > 0.3 && evenNulls / pairs < 0.05) return 'utf-16le';
    if (evenNulls / pairs > 0.3 && oddNulls / pairs < 0.05) return 'utf-16be';
    return null;
}

function decodeHistoricalText(bytes) {
    const utf16Encoding = detectUtf16Encoding(bytes);
    if (utf16Encoding) {
        const decoded = new TextDecoder(utf16Encoding, { fatal: true }).decode(bytes);
        return {
            text: normalizeLineEndings(decoded).normalize('NFC'),
            encoding: utf16Encoding,
            warnings: [`Decoded historical text as ${utf16Encoding}.`],
        };
    }
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

function adaptMarkdown(text, relativePath) {
    let contextualYear = inferYear(relativePath);
    const lines = normalizeLineEndings(text).split('\n');
    const adapted = [];
    for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index];
        const heading = line.match(/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/)?.[1];
        const parsed = heading ? parseDateLabel(heading, contextualYear) : null;
        if (parsed) {
            contextualYear = Number(parsed.date.slice(0, 4));
            adapted.push(heading.trim(), '------------------------------');
            continue;
        }

        const setextHeading = lines[index + 1]?.match(/^\s{0,3}={5,}\s*$/)
            ? line.trim()
            : null;
        const setextDate = setextHeading ? parseDateLabel(setextHeading, contextualYear) : null;
        if (setextDate) {
            contextualYear = Number(setextDate.date.slice(0, 4));
            adapted.push(setextHeading, '------------------------------');
            index += 1;
            continue;
        }

        adapted.push(line);
    }
    return adapted.join('\n');
}

function safeSourceUrl(value) {
    if (typeof value !== 'string' || !value) return null;
    try {
        const candidate = value.trim();
        if (!candidate || /[\u0000-\u001f\u007f]/.test(candidate)) return null;
        const parsed = new URL(candidate);
        if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return null;
        return candidate;
    } catch {
        return null;
    }
}

function publishedDateLabel(value) {
    if (!value) return null;
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return null;
    return new Intl.DateTimeFormat('en-US', {
        timeZone: 'UTC', month: 'long', day: 'numeric', year: 'numeric',
    }).format(parsed);
}

function normalizeStructuredDocumentRecords(items, sourceContext) {
    const records = [];
    const rejections = [];
    const warnings = [];
    const separatorLength = '\n\n.....................................................................\n\n'.length;
    let syntheticOffset = 0;
    let contextualYear = inferYear(sourceContext.relativePath);
    for (const [index, item] of (items ?? []).entries()) {
        const itemIndex = item?.item_index ?? index + 1;
        const title = String(item?.title ?? '').replace(/\s+/g, ' ').trim();
        const content = normalizeLineEndings(String(item?.content ?? '')).trim();
        if (!title) {
            rejections.push({ item_index: itemIndex, code: 'structured_record_missing_title', message: 'Structured record has no title.' });
            continue;
        }
        if (content.length < 12) {
            rejections.push({ item_index: itemIndex, code: 'structured_record_content_too_short', message: `Structured record content is shorter than 12 characters: ${title}` });
            continue;
        }
        let label = title;
        let parsed = parseDateLabel(label, contextualYear);
        if (!parsed) {
            const published = publishedDateLabel(item.published);
            if (!published) {
                rejections.push({ item_index: itemIndex, code: 'structured_record_missing_date', message: `Structured record has no usable title or publication date: ${title}` });
                continue;
            }
            label = `${published} — ${title}`;
            parsed = parseDateLabel(label, contextualYear);
        }
        contextualYear = Number(parsed.date.slice(0, 4));
        const sourceUrl = safeSourceUrl(item.source_url);
        if (item.source_url && !sourceUrl) {
            warnings.push({ item_index: itemIndex, code: 'unsafe_source_url', message: `Structured record URL is not safe HTTP(S) provenance: ${title}` });
        }
        const syntheticBlockLength = label.length
            + (sourceUrl ? `\nSource: ${sourceUrl}`.length : 0)
            + '\n------------------------------\n'.length
            + content.length;
        if (records.length) syntheticOffset += separatorLength;
        const sourceOffset = syntheticOffset;
        syntheticOffset += syntheticBlockLength;
        const excerpt = Boolean(item.excerpt);
        records.push({
            patch_date: parsed.date,
            display_date: label.replace(/\s+/g, ' ').trim(),
            content,
            source_file: sourceContext.relativePath,
            source_offset: sourceOffset,
            source_url: sourceUrl,
            source_url_raw: sourceUrl,
            source_adapter: sourceContext.adapter,
            source_priority: sourceContext.priority,
            admit_new_records: sourceContext.admitNewRecords && (!excerpt || sourceContext.admitExcerpts),
            source_excerpt: excerpt,
            source_kind: item.source_kind ?? 'structured_document_record',
            source_published: item.published ?? null,
            source_item_index: itemIndex,
            year_inferred: parsed.year_inferred ?? false,
        });
    }
    return {
        records,
        rejections,
        warnings,
    };
}

function spawnJson(command, argumentsList, maximumOutputBytes = 64 * 1024 * 1024, timeoutMilliseconds = 60_000) {
    return new Promise(resolve => {
        const child = spawn(command, argumentsList, { shell: false, windowsHide: true });
        const stdout = [];
        const stderr = [];
        let outputBytes = 0;
        let finished = false;
        let timeout = null;
        const finish = result => {
            if (finished) return;
            finished = true;
            if (timeout) clearTimeout(timeout);
            resolve(result);
        };
        const capture = (chunks, chunk) => {
            if (finished) return;
            outputBytes += chunk.length;
            if (outputBytes > maximumOutputBytes) {
                child.kill();
                finish({ ok: false, code: 'adapter_output_too_large', message: `Adapter output exceeded ${maximumOutputBytes} bytes.` });
                return;
            }
            chunks.push(chunk);
        };
        child.stdout.on('data', chunk => capture(stdout, chunk));
        child.stderr.on('data', chunk => capture(stderr, chunk));
        child.on('error', error => finish({ ok: false, code: error.code === 'ENOENT' ? 'python_not_found' : 'adapter_spawn_error', message: error.message }));
        child.on('close', exitCode => {
            if (finished) return;
            const output = Buffer.concat(stdout).toString('utf8');
            if (exitCode !== 0) {
                finish({ ok: false, code: 'adapter_process_failed', message: Buffer.concat(stderr).toString('utf8').trim() || `Adapter exited ${exitCode}.` });
                return;
            }
            try {
                finish({ ok: true, value: JSON.parse(output) });
            } catch (error) {
                finish({ ok: false, code: 'adapter_invalid_json', message: error.message });
            }
        });
        timeout = setTimeout(() => {
            child.kill();
            finish({ ok: false, code: 'adapter_timeout', message: `Adapter exceeded the ${timeoutMilliseconds}-millisecond time limit.` });
        }, timeoutMilliseconds);
        timeout.unref?.();
    });
}

async function runDocumentAdapter(absolutePath, adapter, config, pythonCommand) {
    const helper = path.isAbsolute(adapter.helper ?? '')
        ? adapter.helper
        : path.resolve(scriptDirectory, adapter.helper ?? 'patch_document_adapter.py');
    const candidates = pythonCommand
        ? [{ command: pythonCommand, prefix: [] }]
        : process.platform === 'win32'
            ? [{ command: 'python', prefix: [] }, { command: 'py', prefix: ['-3'] }]
            : [{ command: 'python3', prefix: [] }, { command: 'python', prefix: [] }];
    let lastFailure = null;
    for (const candidate of candidates) {
        const result = await spawnJson(candidate.command, [
            ...candidate.prefix,
            helper,
            absolutePath,
            '--adapter', adapter.name,
            '--minimum-text-characters', String(adapter.minimum_text_characters ?? 12),
            '--max-items', String(adapter.max_items ?? 500),
            '--max-xml-bytes', String(adapter.max_xml_bytes ?? 10 * 1024 * 1024),
            '--max-pages', String(adapter.max_pages ?? 1_000),
            '--max-text-characters', String(adapter.max_text_characters ?? 5_000_000),
            '--max-archive-entries', String(adapter.max_archive_entries ?? 2_048),
            '--max-docx-compressed-bytes', String(adapter.max_docx_compressed_bytes ?? 50 * 1024 * 1024),
            '--max-uncompressed-bytes', String(adapter.max_uncompressed_bytes ?? 200 * 1024 * 1024),
            '--max-docx-entry-compressed-bytes', String(adapter.max_docx_entry_compressed_bytes ?? 25 * 1024 * 1024),
            '--max-docx-entry-uncompressed-bytes', String(adapter.max_docx_entry_uncompressed_bytes ?? 50 * 1024 * 1024),
            '--max-docx-paragraphs', String(adapter.max_docx_paragraphs ?? 100_000),
            '--max-compression-ratio', String(adapter.max_compression_ratio ?? 200),
        ], config.safety?.maximum_adapter_output_bytes ?? 64 * 1024 * 1024,
        (config.safety?.adapter_timeout_seconds ?? 60) * 1_000);
        if (!result.ok) {
            lastFailure = result;
            continue;
        }
        if (result.value.status === 'dependency_missing' && candidates.length > 1) {
            lastFailure = { ok: false, code: 'adapter_dependency_missing', message: result.value.message };
            continue;
        }
        return { ok: true, value: result.value };
    }
    return lastFailure ?? { ok: false, code: 'adapter_unavailable', message: 'No document adapter runtime was available.' };
}

async function adaptSource(bytes, source, adapter, settings, config, pythonCommand) {
    if (!adapter) return { status: 'unsupported', text: '', recordsMetadata: [], rejections: [], warnings: [], metadata: null };
    if (!settings.parse_records) return { status: 'supporting', text: '', recordsMetadata: [], rejections: [], warnings: [], metadata: null };
    if (['text', 'markdown'].includes(adapter.name)) {
        const decoded = decodeHistoricalText(bytes);
        return {
            status: 'adapted',
            text: adapter.name === 'markdown' ? adaptMarkdown(decoded.text, source.relativePath) : decoded.text,
            recordsMetadata: [],
            rejections: [],
            warnings: decoded.warnings.map(message => ({ code: 'encoding_recovery', message })),
            metadata: { detected_encoding: decoded.encoding },
        };
    }
    const extracted = await runDocumentAdapter(source.absolutePath, adapter, config, pythonCommand);
    if (!extracted.ok) {
        return { status: 'rejected', text: '', recordsMetadata: [], rejections: [], warnings: [], metadata: null, rejection: extracted };
    }
    const result = extracted.value;
    if ([
        'ocr_required', 'dependency_missing', 'adapter_error', 'unsafe_xml_rejected',
        'empty_document', 'limit_exceeded', 'invalid_document', 'invalid_feed',
    ].includes(result.status)) {
        return {
            status: 'rejected', text: '', recordsMetadata: [], rejections: [], warnings: [], metadata: result,
            rejection: { code: result.status, message: result.message ?? 'Document adapter rejected the source.' },
        };
    }
    const structured = normalizeStructuredDocumentRecords(result.records, {
        relativePath: source.relativePath,
        adapter: adapter.name,
        priority: settings.priority,
        admitNewRecords: settings.admit_new_records,
        admitExcerpts: settings.admit_excerpts,
    });
    const text = structured.records.length ? '' : (
        ['html', 'docx', 'pdf'].includes(adapter.name)
            ? adaptMarkdown(result.text ?? '', source.relativePath)
            : result.text ?? ''
    );
    const warnings = [...(result.warnings ?? []), ...structured.warnings];
    if (result.feed_excerpt_count) {
        warnings.push({ code: 'feed_excerpt', message: `${result.feed_excerpt_count} feed item(s) are excerpts ending in “Read more”; canonical thread HTML is preferred when available.` });
    }
    const adapterRejections = [...(result.rejections ?? []), ...structured.rejections];
    const boundedStructuredAdapter = ['json', 'csv', 'tsv'].includes(adapter.name);
    if (adapter.name === 'rss' && result.raw_item_count === 0) {
        adapterRejections.push({ code: 'feed_empty', message: 'The feed contains no items; refusing to treat it as a successful import.' });
    }
    if (adapter.name === 'rss' && (result.truncated_item_count ?? 0) > 0) {
        adapterRejections.push({
            code: 'feed_items_truncated',
            message: `${result.truncated_item_count} feed item(s) exceeded the configured max_items limit.`,
        });
    }
    if (adapter.name === 'rss' && Number.isInteger(result.processed_item_count)
        && (result.accepted_item_count ?? 0) + (result.rejected_item_count ?? 0) !== result.processed_item_count) {
        adapterRejections.push({ code: 'feed_item_accounting_mismatch', message: 'Feed item accounting is inconsistent; the adapter contract may have changed.' });
    }
    if (boundedStructuredAdapter && result.raw_item_count === 0) {
        adapterRejections.push({ code: 'structured_source_empty', message: 'Structured source contains no records.' });
    }
    if (boundedStructuredAdapter && (result.truncated_item_count ?? 0) > 0) {
        adapterRejections.push({
            code: 'structured_items_truncated',
            message: `${result.truncated_item_count} structured record(s) exceeded the configured max_items limit.`,
        });
    }
    if (boundedStructuredAdapter && Number.isInteger(result.processed_item_count)
        && (result.accepted_item_count ?? 0) + (result.rejected_item_count ?? 0) !== result.processed_item_count) {
        adapterRejections.push({ code: 'structured_item_accounting_mismatch', message: 'Structured item accounting is inconsistent; the adapter contract may have changed.' });
    }
    return {
        status: 'adapted', text, recordsMetadata: [], structuredRecords: structured.records,
        rejections: adapterRejections, warnings,
        metadata: { ...result, records: undefined, rejections: undefined, text: undefined },
    };
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

function extractRecords(text, sourceContext) {
    const filename = sourceContext.relativePath;
    let contextualYear = inferYear(filename);
    const source = normalizeLineEndings(text);
    const headingPattern = /^([^\n]{3,180})(?:\nSource:\s*([^\n]+))?\n-{5,}\s*$/gm;
    const headings = [];
    const rejections = [];
    const warnings = [];
    let match;

    while ((match = headingPattern.exec(source)) !== null) {
        const parsed = parseDateLabel(match[1], contextualYear);
        if (!parsed) {
            if (/\b(?:19|20)\d{2}\b|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/i.test(match[1])) {
                rejections.push({
                    path: filename,
                    adapter: sourceContext.adapter,
                    source_offset: match.index,
                    code: 'invalid_date_heading',
                    message: `Could not normalize candidate date heading: ${match[1].trim()}`,
                });
            }
            continue;
        }
        contextualYear = Number(parsed.date.slice(0, 4));
        const rawSourceUrl = match[2]?.trim() ?? null;
        const sourceUrl = safeSourceUrl(rawSourceUrl);
        if (rawSourceUrl && !sourceUrl) {
            warnings.push({
                path: filename,
                adapter: sourceContext.adapter,
                source_offset: match.index,
                code: 'unsafe_source_url',
                message: 'A non-HTTP(S) or credential-bearing Source URL was omitted from public provenance.',
            });
        }
        headings.push({
            start: match.index,
            end: headingPattern.lastIndex,
            originalLabel: match[1].trim(),
            sourceUrl,
            rawSourceUrl: sourceUrl,
            ...parsed,
        });
    }

    const records = headings.flatMap((heading, index) => {
        const next = headings[index + 1];
        let content = cleanupContent(source.slice(heading.end, next?.start ?? source.length));
        const trailingSource = content.match(/^Source:\s*(\S+)\s*(?:\n|$)/i);
        const trailingRawUrl = trailingSource?.[1] ?? null;
        const trailingUrl = safeSourceUrl(trailingRawUrl);
        if (trailingRawUrl && !trailingUrl) {
            warnings.push({
                path: filename,
                adapter: sourceContext.adapter,
                source_offset: heading.end,
                code: 'unsafe_source_url',
                message: 'A non-HTTP(S) or credential-bearing trailing Source URL was omitted from public provenance.',
            });
        }
        if (trailingSource) content = content.slice(trailingSource[0].length).trim();
        if (content.length < 12) {
            rejections.push({
                path: filename,
                adapter: sourceContext.adapter,
                source_offset: heading.start,
                code: 'content_too_short',
                message: `Dated record content was shorter than 12 characters: ${heading.originalLabel}`,
            });
            return [];
        }
        const metadata = [...(sourceContext.recordsMetadata ?? [])]
            .reverse()
            .find(item => item.start <= heading.start) ?? null;
        const metadataRawUrl = metadata?.source_url_raw ?? null;
        const metadataUrl = safeSourceUrl(metadataRawUrl);
        if (metadataRawUrl && !metadataUrl) {
            warnings.push({
                path: filename,
                adapter: sourceContext.adapter,
                source_offset: heading.start,
                code: 'unsafe_source_url',
                message: 'An adapter-provided Source URL was omitted from public provenance.',
            });
        }
        const sourceUrl = heading.sourceUrl ?? trailingUrl ?? metadataUrl;
        return [{
            patch_date: heading.date,
            display_date: heading.originalLabel.replace(/\s+/g, ' ').trim(),
            content,
            source_file: filename,
            source_offset: heading.start,
            source_url: sourceUrl,
            source_url_raw: sourceUrl,
            source_adapter: sourceContext.adapter,
            source_priority: sourceContext.priority,
            admit_new_records: sourceContext.admitNewRecords
                && (!(metadata?.excerpt ?? false) || sourceContext.admitExcerpts),
            source_excerpt: metadata?.excerpt ?? false,
            source_kind: metadata?.source_kind ?? sourceContext.adapter,
            source_published: metadata?.published ?? null,
            source_item_index: metadata?.item_index ?? null,
            year_inferred: heading.year_inferred ?? false,
        }];
    });
    return { records, rejections, warnings, heading_candidates: headings.length };
}

function recordKind(record) {
    if (/beta/i.test(record.source_file) || record.patch_date < '1999-03-16') return 'beta';
    if (/\bhotfix\b|emergency (?:downtime|update|patch)/i.test(record.display_date)) return 'hotfix';
    if (/news bit|news story|press release/i.test(record.display_date)) return 'news';
    return 'live';
}

function expansionCode(name) {
    return name.toLocaleLowerCase('en-US')
        .replace(/['’]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '');
}

function expansionFor(date, config) {
    let expansion = config.unmapped_expansion_name ?? 'Unmapped Expansion';
    let selected = null;
    for (const entry of config.expansion_timeline) {
        if (date < entry.start) break;
        selected = entry;
        expansion = entry.name;
    }
    if (!selected || (selected.end && date > selected.end)) return config.unmapped_expansion_name ?? 'Unmapped Expansion';
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

function deriveCategories(record, sections, config) {
    const categoryText = `${record.display_date}\n${sections.join('\n')}\n${record.content}`;
    const categories = config.category_rules
        .filter(rule => rule.expression.test(categoryText))
        .map(rule => ({ slug: rule.slug, label: rule.label }));

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

function sourcePriority(record) {
    if (Number.isFinite(record.source_priority)) return record.source_priority;
    if (/phase\d+beta/i.test(record.source_file)) return 40;
    if (/(?:^|\/)patches-\d{4}(?:-\d)?\.txt$/i.test(record.source_file)) return 30;
    if (/combined\.txt$/i.test(record.source_file)) return 20;
    return 10;
}

function canonicalUrlKey(record) {
    if (!record.source_url) return null;
    try {
        const parsed = new URL(record.source_url);
        parsed.hash = '';
        // A forum thread can legitimately contain a base update and a later
        // hotfix for the same date (the 2018-12-11 source is one real example).
        // Keep the normalized source heading in this key so URL reconciliation
        // can join feed excerpts/saved copies without collapsing those records.
        const sourceTitle = record.display_date
            .normalize('NFKC')
            .toLocaleLowerCase('en-US')
            .replace(/\s+/g, ' ')
            .trim();
        return `${record.patch_date}\n${parsed.href}\n${sourceTitle}`;
    } catch {
        return null;
    }
}

function occurrenceFor(record) {
    const sourceUrl = safeSourceUrl(record.source_url);
    const sourceUrlRaw = safeSourceUrl(record.source_url_raw);
    return {
        occurrence_id: sha256([
            record.source_file, record.source_adapter, record.source_offset,
            record.source_item_index ?? '', record.display_date,
        ].join('\n')),
        filename: record.source_file,
        adapter: record.source_adapter,
        source_offset: record.source_offset,
        item_index: record.source_item_index,
        heading: record.display_date,
        source_url: sourceUrl,
        source_url_raw: sourceUrlRaw,
        published: record.source_published,
        source_kind: record.source_kind,
        excerpt: record.source_excerpt,
        year_inferred: record.year_inferred,
    };
}

function mergeExactDuplicates(records) {
    const contentIndex = new Map();
    const urlIndex = new Map();
    const canonical = [];
    const rejections = [];
    const sorted = [...records].sort((left, right) =>
        Number(right.admit_new_records) - Number(left.admit_new_records)
        || sourcePriority(right) - sourcePriority(left)
        || left.source_file.localeCompare(right.source_file, 'en-US')
        || left.source_offset - right.source_offset
    );

    const attach = (existing, record, reason) => {
        existing.source_occurrences.push(occurrenceFor(record));
        existing.duplicate_reasons.add(reason);
        existing.year_inferred = existing.year_inferred && record.year_inferred;
        const recordIsBetter = (existing.source_excerpt && !record.source_excerpt)
            || (!existing.source_excerpt === !record.source_excerpt && sourcePriority(record) > sourcePriority(existing));
        const hasDescriptiveKind = /news bit|news story|press release|hotfix/i.test(record.display_date);
        if (recordIsBetter) {
            for (const key of [
                'display_date', 'content', 'source_file', 'source_offset', 'source_url', 'source_url_raw',
                'source_adapter', 'source_priority', 'source_excerpt', 'source_kind', 'source_published', 'source_item_index',
            ]) existing[key] = record[key];
        } else if (hasDescriptiveKind && !/news bit|news story|press release|hotfix/i.test(existing.display_date)) {
            existing.display_date = record.display_date;
        }
        return existing;
    };

    for (const record of sorted) {
        const contentKey = sha256(`${record.patch_date}\n${normalizeForDuplicate(record.content)}`);
        const urlKey = canonicalUrlKey(record);
        let existing = contentIndex.get(contentKey) ?? (urlKey ? urlIndex.get(urlKey) : null);
        if (!existing && !record.admit_new_records) {
            rejections.push({
                path: record.source_file,
                adapter: record.source_adapter,
                source_offset: record.source_offset,
                code: 'corroborating_record_unmatched',
                message: `Corroborating-only record did not match an admitted record: ${record.display_date}`,
            });
            continue;
        }
        if (!existing) {
            existing = {
                ...record,
                source_occurrences: [occurrenceFor(record)],
                duplicate_reasons: new Set(),
            };
            canonical.push(existing);
        } else {
            existing = attach(existing, record, contentIndex.has(contentKey) ? 'normalized_content' : 'canonical_url');
        }
        contentIndex.set(contentKey, existing);
        if (urlKey) urlIndex.set(urlKey, existing);
    }

    const merged = canonical.map(record => {
        const sourceOccurrences = record.source_occurrences.sort((left, right) =>
            left.filename.localeCompare(right.filename, 'en-US')
            || left.source_offset - right.source_offset
            || (left.item_index ?? -1) - (right.item_index ?? -1)
        );
        return {
            ...record,
            source_files: [...new Set(sourceOccurrences.map(item => item.filename))].sort(),
            source_titles: [...new Set(sourceOccurrences.map(item => item.heading))].sort(),
            source_urls: [...new Set(sourceOccurrences.map(item => item.source_url).filter(Boolean))].sort(),
            source_occurrences: sourceOccurrences,
            content_hash: sha256(normalizeForHash(record.content)),
            duplicate_reasons: [...record.duplicate_reasons].sort(),
        };
    });
    const duplicateGroups = merged
        .filter(record => record.source_occurrences.length > 1)
        .map(record => ({
            patch_date: record.patch_date,
            content_hash: record.content_hash,
            occurrence_count: record.source_occurrences.length,
            reasons: record.duplicate_reasons,
            occurrences: record.source_occurrences.map(item => item.occurrence_id),
        }))
        .sort((left, right) => left.patch_date.localeCompare(right.patch_date) || left.content_hash.localeCompare(right.content_hash));
    return { records: merged, rejections, duplicateGroups };
}

function priorCanonicalUrls(record) {
    return [...new Set([...(record.source_urls ?? []), record.source_url]
        .map(value => {
            if (typeof value !== 'string' || !value) return null;
            try {
                const parsed = new URL(value);
                if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return null;
                parsed.hash = '';
                return parsed.href;
            } catch {
                return null;
            }
        })
        .filter(Boolean))].sort();
}

function stableTitleKey(record) {
    return String(record.display_date ?? record.title ?? '')
        .normalize('NFKC')
        .toLocaleLowerCase('en-US')
        .replace(/\s+/g, ' ')
        .trim();
}

function uniquePriorCandidates(candidates) {
    return [...new Map(candidates.map(candidate => [candidate.slug, candidate])).values()]
        .sort((left, right) => left.sequence - right.sequence || left.slug.localeCompare(right.slug, 'en-US'));
}

function allocateStableSlugs(records, priorArchive, options = true) {
    const settings = typeof options === 'boolean'
        ? { enabled: options, reuse_by_content_hash_and_date: true, reuse_by_canonical_url_and_date: true }
        : {
            enabled: options?.enabled !== false,
            reuse_by_content_hash_and_date: options?.reuse_by_content_hash_and_date !== false,
            reuse_by_canonical_url_and_date: options?.reuse_by_canonical_url_and_date !== false,
        };
    const priorArchiveFound = priorArchive !== null && priorArchive !== undefined;
    const priorRecords = settings.enabled && Array.isArray(priorArchive?.patches) ? priorArchive.patches : [];
    const byContent = new Map();
    const byUrl = new Map();
    const byUrlAndTitle = new Map();
    const priorMaximum = new Map();
    const add = (index, key, value) => {
        if (!index.has(key)) index.set(key, []);
        index.get(key).push(value);
    };
    for (const prior of priorRecords) {
        const sequence = Number(prior.sequence);
        if (!prior.patch_date || !prior.content_hash || !Number.isInteger(sequence) || sequence < 1) continue;
        if (settings.reuse_by_content_hash_and_date) {
            add(byContent, `${prior.patch_date}\n${prior.content_hash}`, prior);
        }
        if (settings.reuse_by_canonical_url_and_date) {
            for (const url of priorCanonicalUrls(prior)) {
                add(byUrl, `${prior.patch_date}\n${url}`, prior);
                add(byUrlAndTitle, `${prior.patch_date}\n${url}\n${stableTitleKey(prior)}`, prior);
            }
        }
        priorMaximum.set(prior.patch_date, Math.max(priorMaximum.get(prior.patch_date) ?? 0, sequence));
    }
    for (const index of [byContent, byUrl, byUrlAndTitle]) {
        for (const candidates of index.values()) candidates.sort((left, right) => left.sequence - right.sequence || left.slug.localeCompare(right.slug));
    }

    const used = new Map();
    const report = {
        enabled: settings.enabled,
        reuse_by_content_hash_and_date: settings.reuse_by_content_hash_and_date,
        reuse_by_canonical_url_and_date: settings.reuse_by_canonical_url_and_date,
        prior_archive_found: priorArchiveFound,
        reused: 0,
        assigned: 0,
        collisions: [],
        ambiguities: [],
        reassignments: [],
    };
    const assigned = [];
    const reserve = (record, prior, matchedBy) => {
        if (!prior || prior.patch_date !== record.patch_date) return false;
        const sequence = Number(prior.sequence);
        const expectedSlug = `${record.patch_date}-${sequence}`;
        if (!Number.isInteger(sequence) || sequence < 1 || prior.slug !== expectedSlug) return false;
        if (!used.has(record.patch_date)) used.set(record.patch_date, new Set());
        if (used.get(record.patch_date).has(sequence)) {
            report.collisions.push({ patch_date: record.patch_date, content_hash: record.content_hash, requested_slug: prior.slug, matched_by: matchedBy });
            return false;
        }
        used.get(record.patch_date).add(sequence);
        record.sequence = sequence;
        record.slug = prior.slug;
        record.id = prior.id === prior.slug ? prior.id : prior.slug;
        report.reused += 1;
        return true;
    };

    const noteAmbiguity = (record, matchedBy, candidates) => {
        report.ambiguities.push({
            patch_date: record.patch_date,
            content_hash: record.content_hash,
            title: record.display_date ?? record.title ?? '',
            matched_by: matchedBy,
            candidate_slugs: uniquePriorCandidates(candidates).map(candidate => candidate.slug),
        });
    };

    for (const record of records) {
        let matched = false;
        if (settings.reuse_by_content_hash_and_date) {
            const contentCandidates = uniquePriorCandidates(byContent.get(`${record.patch_date}\n${record.content_hash}`) ?? []);
            if (contentCandidates.length === 1) {
                matched = reserve(record, contentCandidates[0], 'content_hash_and_date');
            } else if (contentCandidates.length > 1) {
                noteAmbiguity(record, 'content_hash_and_date', contentCandidates);
            }
        }
        if (!matched && settings.reuse_by_canonical_url_and_date) {
            const urls = priorCanonicalUrls(record);
            const titleCandidates = uniquePriorCandidates(urls.flatMap(url =>
                byUrlAndTitle.get(`${record.patch_date}\n${url}\n${stableTitleKey(record)}`) ?? []
            ));
            if (titleCandidates.length === 1) {
                matched = reserve(record, titleCandidates[0], 'canonical_url_date_and_title');
            } else if (titleCandidates.length > 1) {
                noteAmbiguity(record, 'canonical_url_date_and_title', titleCandidates);
            } else {
                const urlCandidates = uniquePriorCandidates(urls.flatMap(url => byUrl.get(`${record.patch_date}\n${url}`) ?? []));
                if (urlCandidates.length === 1) {
                    matched = reserve(record, urlCandidates[0], 'unambiguous_canonical_url_and_date');
                } else if (urlCandidates.length > 1) {
                    noteAmbiguity(record, 'canonical_url_and_date', urlCandidates);
                }
            }
        }
        if (matched) assigned.push(record);
    }

    for (const record of records.filter(item => !item.slug)) {
        if (!used.has(record.patch_date)) used.set(record.patch_date, new Set());
        const usedForDate = used.get(record.patch_date);
        let sequence = priorRecords.length ? (priorMaximum.get(record.patch_date) ?? 0) + 1 : 1;
        while (usedForDate.has(sequence)) sequence += 1;
        usedForDate.add(sequence);
        record.sequence = sequence;
        record.slug = `${record.patch_date}-${sequence}`;
        record.id = record.slug;
        report.assigned += 1;
        if (priorRecords.length && byContent.has(`${record.patch_date}\n${record.content_hash}`)) {
            report.reassignments.push({ patch_date: record.patch_date, content_hash: record.content_hash, assigned_slug: record.slug });
        }
        assigned.push(record);
    }

    report.collisions.sort((left, right) => left.patch_date.localeCompare(right.patch_date) || left.content_hash.localeCompare(right.content_hash));
    report.ambiguities.sort((left, right) => left.patch_date.localeCompare(right.patch_date) || left.content_hash.localeCompare(right.content_hash) || left.matched_by.localeCompare(right.matched_by));
    report.reassignments.sort((left, right) => left.patch_date.localeCompare(right.patch_date) || left.content_hash.localeCompare(right.content_hash));
    assigned.sort((left, right) => left.patch_date.localeCompare(right.patch_date) || left.sequence - right.sequence || left.content_hash.localeCompare(right.content_hash));
    return { records: assigned, report };
}

function annotateRecords(records, config, priorArchive, stableSlugs = true) {
    const sorted = records.sort((left, right) => {
        const dateOrder = left.patch_date.localeCompare(right.patch_date);
        if (dateOrder !== 0) return dateOrder;
        const labelOrder = left.display_date.localeCompare(right.display_date);
        if (labelOrder !== 0) return labelOrder;
        return left.content_hash.localeCompare(right.content_hash);
    });

    const annotated = sorted.map(record => {
        const sections = deriveSections(record.content);
        const kind = recordKind(record);
        const expansion = expansionFor(record.patch_date, config);
        return {
            patch_date: record.patch_date,
            effective_date: effectiveDateFor(record),
            display_date: record.display_date,
            title: titleFor(record),
            year: Number(record.patch_date.slice(0, 4)),
            month: Number(record.patch_date.slice(5, 7)),
            kind,
            era: kind === 'beta' ? 'EverQuest Beta' : 'EverQuest Live',
            expansion,
            expansion_code: expansionCode(expansion),
            categories: deriveCategories(record, sections, config),
            sections,
            change_count: countChanges(record.content),
            word_count: record.content.split(/\s+/).filter(Boolean).length,
            summary: summarize(record.content),
            content: record.content,
            content_hash: record.content_hash,
            source_files: record.source_files,
            source_titles: record.source_titles,
            source_url: safeSourceUrl(record.source_url),
            source_urls: [...new Set((record.source_urls ?? []).map(safeSourceUrl).filter(Boolean))].sort(),
            occurrence_count: record.source_occurrences.length,
            source_occurrences: record.source_occurrences,
            year_inferred: record.year_inferred,
        };
    });
    return allocateStableSlugs(annotated, priorArchive, {
        enabled: stableSlugs && config.stable_slugs?.enabled !== false,
        reuse_by_content_hash_and_date: config.stable_slugs?.reuse_by_content_hash_and_date !== false,
        reuse_by_canonical_url_and_date: config.stable_slugs?.reuse_by_canonical_url_and_date !== false,
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

function isValidIsoDate(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');
    if (!match) return false;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    if (month < 1 || month > 12 || day < 1) return false;
    return day <= new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function validatePriorArchive(archive, filename = 'prior archive') {
    const fail = message => {
        throw new Error(`Invalid prior archive ${path.basename(filename)}: ${message}`);
    };
    if (!archive || typeof archive !== 'object' || Array.isArray(archive)) fail('root must be a JSON object.');
    if (archive.schema_version !== 1) fail('schema_version must be 1.');
    if (!Number.isSafeInteger(archive.record_count) || archive.record_count < 0) {
        fail('record_count must be a non-negative safe integer.');
    }
    if (!Array.isArray(archive.patches)) fail('patches must be an array.');
    if (archive.record_count > 0 && archive.patches.length === 0) {
        fail('patches must be non-empty when record_count is nonzero.');
    }
    if (archive.patches.length !== archive.record_count) {
        fail(`record_count ${archive.record_count} does not match patches length ${archive.patches.length}.`);
    }
    if (archive.coverage?.patch_count !== undefined && archive.coverage.patch_count !== archive.record_count) {
        fail('coverage.patch_count does not match record_count.');
    }
    const slugs = new Set();
    for (const [index, record] of archive.patches.entries()) {
        const label = `patches[${index}]`;
        if (!record || typeof record !== 'object' || Array.isArray(record)) fail(`${label} must be an object.`);
        if (!isValidIsoDate(record.patch_date)) fail(`${label}.patch_date must be a valid ISO calendar date.`);
        if (!Number.isSafeInteger(record.sequence) || record.sequence < 1) fail(`${label}.sequence must be a positive safe integer.`);
        const expectedSlug = `${record.patch_date}-${record.sequence}`;
        if (record.slug !== expectedSlug) fail(`${label}.slug must equal ${expectedSlug}.`);
        if (record.id !== record.slug) fail(`${label}.id must equal slug.`);
        if (slugs.has(record.slug)) fail(`duplicate slug ${record.slug}.`);
        slugs.add(record.slug);
        if (typeof record.content_hash !== 'string' || !/^[a-f0-9]{64}$/.test(record.content_hash)) {
            fail(`${label}.content_hash must be a lowercase SHA-256 digest.`);
        }
        if (record.occurrence_count !== undefined) {
            if (!Number.isSafeInteger(record.occurrence_count) || record.occurrence_count < 1) {
                fail(`${label}.occurrence_count must be a positive safe integer when present.`);
            }
            if (Array.isArray(record.source_occurrences) && record.source_occurrences.length !== record.occurrence_count) {
                fail(`${label}.occurrence_count does not match source_occurrences length.`);
            }
        }
    }
    return archive;
}

async function readJsonIfPresent(filename) {
    try {
        return JSON.parse(await readFile(filename, 'utf8'));
    } catch (error) {
        if (error?.code === 'ENOENT') return null;
        throw new Error(`Expected valid JSON in ${path.basename(filename)}.`, { cause: error });
    }
}

async function loadPriorArchive(filename, required = false) {
    const archive = await readJsonIfPresent(filename);
    if (!archive && required) {
        throw new Error(`Explicit prior archive does not exist: ${filename}`);
    }
    return archive ? validatePriorArchive(archive, filename) : null;
}

function sortIssues(items) {
    return items.sort((left, right) =>
        String(left.path ?? '').localeCompare(String(right.path ?? ''), 'en-US')
        || Number(left.source_offset ?? -1) - Number(right.source_offset ?? -1)
        || String(left.code ?? '').localeCompare(String(right.code ?? ''), 'en-US')
        || String(left.message ?? '').localeCompare(String(right.message ?? ''), 'en-US')
    );
}

function publicationTargetKey(filename) {
    const resolved = path.resolve(filename);
    return process.platform === 'win32' ? resolved.toLocaleLowerCase('en-US') : resolved;
}

async function existingRegularTarget(filename) {
    try {
        const details = await lstat(filename);
        if (details.isSymbolicLink() || !details.isFile()) {
            throw new Error(`Publication target must be a regular non-symlink file: ${filename}`);
        }
        return true;
    } catch (error) {
        if (error?.code === 'ENOENT') return false;
        throw error;
    }
}

async function writeStagedFile(filename, content, suffix = 'tmp') {
    const temporary = path.join(
        path.dirname(filename),
        `.${path.basename(filename)}.${process.pid}.${randomUUID()}.${suffix}`,
    );
    const handle = await open(temporary, 'wx', 0o600);
    let complete = false;
    try {
        await handle.writeFile(content, typeof content === 'string' ? { encoding: 'utf8' } : undefined);
        await handle.sync();
        complete = true;
    } finally {
        await handle.close();
        if (!complete) {
            try {
                await unlink(temporary);
            } catch (error) {
                if (error?.code !== 'ENOENT') throw error;
            }
        }
    }
    return temporary;
}

function assertDistinctPublicationTargets(artifacts) {
    const targets = new Set();
    for (const artifact of artifacts) {
        const key = publicationTargetKey(artifact.filename);
        if (targets.has(key)) throw new Error(`Publication targets collide: ${artifact.filename}`);
        targets.add(key);
    }
}

async function publishArtifactsAtomically(artifacts, operations = {}) {
    assertDistinctPublicationTargets(artifacts);

    const renameFile = operations.rename ?? rename;

    const directories = [...new Set(artifacts.map(artifact => path.dirname(artifact.filename)))];
    for (const directory of directories) await mkdir(directory, { recursive: true });

    const staged = [];
    try {
        for (const artifact of artifacts) {
            const existed = await existingRegularTarget(artifact.filename);
            const temporary = await writeStagedFile(artifact.filename, artifact.content);
            let backup = null;
            if (existed) {
                backup = path.join(
                    path.dirname(artifact.filename),
                    `.${path.basename(artifact.filename)}.${process.pid}.${randomUUID()}.bak`,
                );
            }
            const stagedArtifact = {
                ...artifact,
                existed,
                temporary,
                backup,
                published: false,
                preserveBackup: false,
            };
            staged.push(stagedArtifact);
            if (backup) await copyFile(artifact.filename, backup, constants.COPYFILE_EXCL);
        }

        try {
            for (const artifact of staged) {
                await renameFile(artifact.temporary, artifact.filename);
                artifact.published = true;
            }
        } catch (publishError) {
            const rollbackErrors = [];
            for (const artifact of [...staged].reverse()) {
                if (!artifact.published) continue;
                try {
                    if (artifact.backup) await renameFile(artifact.backup, artifact.filename);
                    else await unlink(artifact.filename);
                } catch (rollbackError) {
                    artifact.preserveBackup = Boolean(artifact.backup);
                    const recovery = artifact.backup ? `; recovery backup retained at ${artifact.backup}` : '';
                    rollbackErrors.push(`${artifact.filename}: ${rollbackError.message}${recovery}`);
                }
            }
            if (rollbackErrors.length) {
                throw new Error(`Artifact publication failed and rollback was incomplete (${rollbackErrors.join('; ')}).`, { cause: publishError });
            }
            throw publishError;
        }
    } finally {
        for (const artifact of staged) {
            for (const temporary of [
                artifact.temporary,
                artifact.preserveBackup ? null : artifact.backup,
            ]) {
                if (!temporary) continue;
                try {
                    await unlink(temporary);
                } catch (error) {
                    if (error?.code !== 'ENOENT') throw error;
                }
            }
        }
    }
}

async function main(argv = process.argv.slice(2)) {
    const arguments_ = parseArguments(argv);
    if (arguments_.help) {
        process.stdout.write(helpText);
        return null;
    }
    const { sourceDirectory, outputDirectory } = arguments_;
    const config = await loadConfig(arguments_.configPath);
    const discovery = await discoverSourceFiles(sourceDirectory, outputDirectory, config);
    const inventory = [];
    const extracted = [];
    const rejected = [];
    const warnings = [...discovery.notices];
    const priorArchive = await loadPriorArchive(arguments_.priorArchivePath, arguments_.priorArchiveExplicit);
    const summaryExtraction = await readJsonIfPresent(path.join(outputDirectory, 'patch-summary-highlights.json'));

    for (const source of discovery.files) {
        const details = await stat(source.absolutePath);
        const adapter = adapterForPath(source.relativePath, config);
        const settings = sourceSettings(source.relativePath, adapter, config);
        const maximumBytes = config.discovery?.max_file_bytes ?? 52_428_800;
        if (details.size > maximumBytes) {
            const rejection = { path: source.relativePath, adapter: adapter?.name ?? 'unsupported', code: 'file_too_large', message: `File exceeds configured ${maximumBytes}-byte limit.` };
            rejected.push(rejection);
            inventory.push({
                filename: source.relativePath, basename: path.basename(source.relativePath), bytes: details.size, sha256: null,
                adapter: adapter?.name ?? 'unsupported', status: 'rejected', parsed_records: 0, accepted_occurrences: 0,
                rejected_occurrences: 0, detected_encoding: adapter ? 'not-decoded' : 'binary', warnings: [],
                role: settings.role, description: 'Source rejected before reading because it exceeds the configured size limit.',
            });
            continue;
        }
        const bytes = await readFile(source.absolutePath);
        const fileSha256 = sha256(bytes);
        const adapted = await adaptSource(bytes, source, adapter, settings, config, arguments_.pythonCommand);
        const parsedText = adapted.status === 'adapted'
            ? extractRecords(adapted.text, {
                relativePath: source.relativePath,
                adapter: adapter.name,
                priority: settings.priority,
                admitNewRecords: settings.admit_new_records,
                admitExcerpts: settings.admit_excerpts,
                recordsMetadata: adapted.recordsMetadata,
            })
            : { records: [], rejections: [], warnings: [], heading_candidates: 0 };
        const parsed = {
            records: [...(adapted.structuredRecords ?? []), ...parsedText.records],
            rejections: parsedText.rejections,
            warnings: parsedText.warnings,
            heading_candidates: (adapted.structuredRecords?.length ?? 0) + parsedText.heading_candidates,
        };
        extracted.push(...parsed.records);
        const adapterRejections = (adapted.rejections ?? []).map(item => ({
            path: source.relativePath,
            adapter: adapter?.name ?? 'unsupported',
            ...item,
            code: item.code ?? 'structured_record_rejected',
            message: item.message ?? `Structured record rejected: ${(item.reasons ?? ['unspecified reason']).join(', ')}`,
        }));
        rejected.push(...adapterRejections, ...parsed.rejections);
        warnings.push(...adapted.warnings.map(item => ({ path: source.relativePath, adapter: adapter?.name, ...item })), ...parsed.warnings);
        if (adapted.rejection) rejected.push({ path: source.relativePath, adapter: adapter?.name ?? 'unsupported', ...adapted.rejection });
        const semanticExtraction = summaryExtraction?.source_sha256 === fileSha256
            ? summaryExtraction
            : adapter?.name === 'pdf' && adapted.metadata?.pages
                ? {
                    schema_version: 1,
                    source_filename: source.relativePath,
                    source_sha256: fileSha256,
                    title: path.basename(source.relativePath),
                    coverage_note: 'Text-layer extraction produced by the configurable PDF adapter; OCR was not used.',
                    page_count: adapted.metadata.page_count,
                    pages: adapted.metadata.pages,
                }
                : null;
        inventory.push({
            filename: source.relativePath,
            basename: path.basename(source.relativePath),
            bytes: bytes.length,
            sha256: fileSha256,
            adapter: adapter?.name ?? 'unsupported',
            status: adapted.status,
            parsed_records: parsed.records.length,
            heading_candidates: parsed.heading_candidates,
            accepted_occurrences: 0,
            rejected_occurrences: adapterRejections.length + parsed.rejections.length + (adapted.rejection ? 1 : 0),
            detected_encoding: adapted.metadata?.detected_encoding ?? (adapter ? 'extracted unicode text' : 'binary'),
            warnings: adapted.warnings.map(item => item.message),
            role: settings.role,
            description: describeSource(source.relativePath, adapter?.extension ?? path.extname(source.relativePath), parsed.records.length),
            adapter_metadata: adapted.metadata,
            ...(semanticExtraction ? { semantic_extraction: semanticExtraction } : {}),
        });
    }

    const merged = mergeExactDuplicates(extracted);
    warnings.push(...merged.rejections);
    const annotated = annotateRecords(merged.records, config, priorArchive, arguments_.stableSlugs);
    if (annotated.report.collisions.length || annotated.report.ambiguities.length) {
        throw new Error(
            `Stable slug allocation is unsafe: ${annotated.report.collisions.length} collision(s) and `
            + `${annotated.report.ambiguities.length} ambiguous prior match(es); refusing to publish.`
        );
    }
    const records = annotated.records;
    const priorRecords = Array.isArray(priorArchive?.patches) ? priorArchive.patches : [];
    if (!records.length && config.safety?.allow_empty !== true) {
        throw new Error('Import produced no public records; refusing to replace the archive.');
    }
    const currentSlugs = new Set(records.map(record => record.slug));
    const removedSlugs = priorRecords
        .map(record => record.slug)
        .filter(slug => typeof slug === 'string' && !currentSlugs.has(slug))
        .sort();
    const removalPercent = priorRecords.length ? (removedSlugs.length / priorRecords.length) * 100 : 0;
    const maximumRemovalPercent = Number(config.safety?.maximum_removal_percent ?? 0);
    if (removedSlugs.length && removalPercent > maximumRemovalPercent && !arguments_.allowRemovals) {
        throw new Error(
            `Import would remove ${removedSlugs.length} of ${priorRecords.length} published records `
            + `(${removalPercent.toFixed(2)}%); review the sources and rerun with --allow-removals if intentional.`
        );
    }
    const acceptedByFile = new Map();
    for (const record of records) {
        for (const occurrence of record.source_occurrences) {
            acceptedByFile.set(occurrence.filename, (acceptedByFile.get(occurrence.filename) ?? 0) + 1);
        }
    }
    for (const file of inventory) file.accepted_occurrences = acceptedByFile.get(file.filename) ?? 0;
    const corroboratingByFile = new Map();
    for (const issue of merged.rejections) {
        corroboratingByFile.set(issue.path, (corroboratingByFile.get(issue.path) ?? 0) + 1);
    }
    for (const file of inventory) file.corroborating_unmatched_occurrences = corroboratingByFile.get(file.filename) ?? 0;
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
            config_sha256: config.fingerprint,
            note: 'Files are recursively inventoried. Enabled text, Markdown, HTML, RSS/XML, JSON, CSV, TSV, PDF, and DOCX adapters normalize records through bounded format-specific handling; duplicates retain occurrence-level provenance. Image-only PDFs are reported as OCR-required and never OCRed implicitly.',
            files: inventory,
        },
        patches: records,
    };

    const acceptedOccurrences = records.reduce((sum, record) => sum + record.occurrence_count, 0);
    const importReport = {
        schema_version: 1,
        config_sha256: config.fingerprint,
        summary: {
            discovered_files: discovery.files.length,
            adapted_files: inventory.filter(file => file.status === 'adapted').length,
            supporting_files: inventory.filter(file => file.status === 'supporting').length,
            unsupported_files: inventory.filter(file => file.status === 'unsupported').length,
            rejected_files: inventory.filter(file => file.status === 'rejected').length,
            parsed_occurrences: extracted.length,
            accepted_occurrences: acceptedOccurrences,
            duplicate_occurrences: acceptedOccurrences - records.length,
            rejected_occurrences: rejected.length,
            corroborating_unmatched_occurrences: merged.rejections.length,
            public_records: records.length,
            feed_excerpt_occurrences: extracted.filter(record => record.source_excerpt).length,
            published_feed_excerpt_occurrences: records.reduce((sum, record) => sum + record.source_occurrences.filter(item => item.excerpt).length, 0),
        },
        slugs: annotated.report,
        comparison: {
            prior_records: priorRecords.length,
            current_records: records.length,
            removed_records: removedSlugs.length,
            removal_percent: Number(removalPercent.toFixed(4)),
            removed_slugs: removedSlugs,
        },
        files: inventory.map(file => ({
            path: file.filename, adapter: file.adapter, status: file.status, role: file.role,
            parsed_records: file.parsed_records, accepted_occurrences: file.accepted_occurrences,
            rejected_occurrences: file.rejected_occurrences,
            corroborating_unmatched_occurrences: file.corroborating_unmatched_occurrences,
            warnings: file.warnings,
        })),
        duplicates: merged.duplicateGroups,
        rejections: sortIssues(rejected),
        warnings: sortIssues(warnings),
    };

    const jsonPath = path.join(outputDirectory, 'everquest-patch-history.json');
    const csvPath = path.join(outputDirectory, 'everquest-patch-history.csv');
    const suggestionPath = path.join(outputDirectory, 'everquest-patch-suggestions.json');
    const publicationArtifacts = [
        { filename: arguments_.reportPath, content: `${JSON.stringify(importReport, null, 2)}\n` },
        { filename: csvPath, content: buildCsv(records) },
        { filename: suggestionPath, content: `${JSON.stringify(buildSuggestionIndex(records, generatedAt))}\n` },
        { filename: jsonPath, content: `${JSON.stringify(archive, null, 2)}\n` },
    ];
    assertDistinctPublicationTargets(publicationArtifacts);
    if (publicationTargetKey(arguments_.reportPath) === publicationTargetKey(arguments_.priorArchivePath)
        && publicationTargetKey(arguments_.priorArchivePath) !== publicationTargetKey(jsonPath)) {
        throw new Error('The import report cannot overwrite the prior archive input.');
    }
    if (arguments_.strict && (importReport.summary.rejected_files || importReport.summary.rejected_occurrences)) {
        if (!arguments_.dryRun || arguments_.reportExplicit) {
            await publishArtifactsAtomically([publicationArtifacts[0]]);
        }
        throw new Error(
            `Strict import failed with ${importReport.summary.rejected_occurrences} rejected occurrence(s); `
            + `${arguments_.dryRun && !arguments_.reportExplicit ? 'rerun with an explicit --report path to write' : 'inspect'} ${arguments_.reportPath}.`
        );
    }

    if (!arguments_.dryRun) await publishArtifactsAtomically(publicationArtifacts);
    else if (arguments_.reportExplicit) await publishArtifactsAtomically([publicationArtifacts[0]]);

    process.stdout.write(`${JSON.stringify({
        jsonPath,
        csvPath,
        suggestionPath,
        reportPath: arguments_.reportPath,
        extractedRecords: extracted.length,
        canonicalRecords: records.length,
        firstPatch: archive.coverage.first_patch,
        lastPatch: archive.coverage.last_patch,
        sourceFiles: inventory.length,
        dryRun: arguments_.dryRun,
        reportWritten: !arguments_.dryRun || arguments_.reportExplicit,
    }, null, 2)}\n`);
    return { archive, importReport };
}

if (path.resolve(process.argv[1] ?? '') === fileURLToPath(import.meta.url)) {
    try {
        await main();
    } catch (error) {
        process.stderr.write(`Patch import failed: ${error.message}\n`);
        if (process.env.PATCH_IMPORT_DEBUG === '1' && error.stack) process.stderr.write(`${error.stack}\n`);
        process.exitCode = 1;
    }
}

export {
    adaptMarkdown,
    allocateStableSlugs,
    buildCsv,
    decodeHistoricalText,
    discoverSourceFiles,
    extractRecords,
    loadConfig,
    loadPriorArchive,
    main,
    mergeExactDuplicates,
    normalizeForDuplicate,
    normalizeStructuredDocumentRecords,
    parseDateLabel,
    publishArtifactsAtomically,
    spawnJson,
    validatePriorArchive,
};
