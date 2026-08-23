#!/usr/bin/env node

import { spawn } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import {
    closeSync,
    openSync,
} from 'node:fs';
import {
    appendFile,
    link,
    lstat,
    mkdir,
    open,
    readFile,
    readdir,
    rename,
    stat,
    unlink,
} from 'node:fs/promises';
import { gunzipSync, gzipSync } from 'node:zlib';
import {
    basename,
    dirname,
    isAbsolute,
    join,
    relative,
    resolve,
    sep,
} from 'node:path';
import { fileURLToPath } from 'node:url';

import {
    CrawlWorkspace,
    atomicWriteJson,
    calculateBackoff,
    ensureSafeFileTarget,
    establishRealDirectory,
    readAtomicJson,
} from './lib/lucy-crawl-state.mjs';
import {
    LucyParseError,
    REVERSIBLE_DELTA_CAPTURE_STRATEGY,
    analyzeReversibleItemHistory,
    buildItemHistoryArtifact,
    canonicalJson,
    decodeLucyHtml,
    itemHistoryRelativePath,
    parseItemDetailPage,
    parseItemHistoryPage,
    parseItemList,
    parseItemRawPage,
} from './lib/lucy-item-history-parser.mjs';

const SCRIPT_PATH = fileURLToPath(import.meta.url);
const PROJECT_ROOT = resolve(dirname(SCRIPT_PATH), '..');
const DEFAULT_WORKSPACE = join(PROJECT_ROOT, 'storage', 'app', 'private', 'lucy-item-history-crawl');
const DEFAULT_ARTIFACT_ROOT = join(PROJECT_ROOT, 'storage', 'app', 'private', 'item-history');
const CONFIG_SCHEMA = 'modern-allaclone.lucy-item-crawl-config';
const QUEUE_SCHEMA = 'modern-allaclone.lucy-item-crawl-queue';
const PROGRESS_SCHEMA = 'modern-allaclone.lucy-item-crawl-progress';
const ITEM_WORK_SCHEMA = 'modern-allaclone.lucy-item-crawl-item-work';
const ERROR_SCHEMA = 'modern-allaclone.lucy-item-crawl-error';
const VERSION = 1;
const CRAWLER_VERSION = '1.1.0';
const DEFAULT_BASE_URL = 'https://lucy.allakhazam.com/';
const DEFAULT_MIN_INTERVAL_MS = 30_000;
const ABSOLUTE_MIN_INTERVAL_MS = 2_000;
const DEFAULT_JITTER_MS = 5_000;
const DEFAULT_DAILY_CAP = 2_000;
const MAX_DAILY_CAP = 100_000;
const DEFAULT_CAPTURE_STRATEGY = 'direct-detail';
const REVERSIBLE_DELTA_CONFIG_STRATEGY = 'reversible-delta';
const CAPTURE_STRATEGIES = new Set([
    DEFAULT_CAPTURE_STRATEGY,
    REVERSIBLE_DELTA_CONFIG_STRATEGY,
]);
const RATE_LIMIT_MINIMUM_BACKOFF_MS = 60 * 60 * 1_000;
const MAXIMUM_RETRY_BACKOFF_MS = 6 * 60 * 60 * 1_000;
const DEFAULT_REQUEST_TIMEOUT_MS = 30_000;
const DEFAULT_MAX_HTML_BYTES = 8 * 1024 * 1024;
const DEFAULT_MAX_ITEM_LIST_BYTES = 32 * 1024 * 1024;
const DEFAULT_MAX_ITEM_LIST_EXPANDED_BYTES = 64 * 1024 * 1024;
const MAX_QUEUE_BYTES = 64 * 1024 * 1024;
const MAX_ARTIFACT_BYTES = 16 * 1024 * 1024;
const PAUSE_POLL_MS = 5_000;

class CrawlerError extends Error {
    constructor(message, { code = 'crawler_error', cause = undefined, context = {} } = {}) {
        super(message, { cause });
        this.name = 'CrawlerError';
        this.code = code;
        this.context = context;
    }
}

class StopRequested extends Error {
    constructor(message = 'Crawler stop requested.') {
        super(message);
        this.name = 'StopRequested';
    }
}

class PauseRequested extends Error {
    constructor(message, { code = 'automatic_pause', cause = undefined, context = {} } = {}) {
        super(message, { cause });
        this.name = 'PauseRequested';
        this.code = code;
        this.context = context;
    }
}

const delay = (milliseconds) => new Promise((resolvePromise) => setTimeout(resolvePromise, milliseconds));

function parseArguments(argv) {
    const values = {};
    const positionals = [];

    for (let index = 0; index < argv.length; index += 1) {
        const token = argv[index];
        if (!token.startsWith('--')) {
            positionals.push(token);
            continue;
        }

        const equals = token.indexOf('=');
        if (equals !== -1) {
            values[token.slice(2, equals)] = token.slice(equals + 1);
            continue;
        }

        const key = token.slice(2);
        if (argv[index + 1] !== undefined && !argv[index + 1].startsWith('--')) {
            values[key] = argv[index + 1];
            index += 1;
        } else {
            values[key] = true;
        }
    }

    return { values, positionals };
}

function usage() {
    return `Authorized Lucy item-revision crawler

Usage:
  node scripts/lucy-item-history-crawler.mjs init --contact=<address> --authorization-ref=<reference>
  node scripts/lucy-item-history-crawler.mjs start [--workspace=<path>]
  node scripts/lucy-item-history-crawler.mjs run [--workspace=<path>]
  node scripts/lucy-item-history-crawler.mjs status [--workspace=<path>] [--json]
  node scripts/lucy-item-history-crawler.mjs pause [--reason=<text>]
  node scripts/lucy-item-history-crawler.mjs resume
  node scripts/lucy-item-history-crawler.mjs stop [--reason=<text>]
  node scripts/lucy-item-history-crawler.mjs retry-failures
  node scripts/lucy-item-history-crawler.mjs sweep

init options:
  --contact=<address>            Monitored email or URL sent in User-Agent (required)
  --authorization-ref=<text>     Lucy/ZAM authorization or ticket reference (required)
  --artifact-root=<path>         Site artifact root (default: storage/app/private/item-history)
  --base-url=<url>               Lucy origin; HTTPS is required except for loopback testing
  --item-list-file=<path>        Use an already-downloaded item list (zero seed request)
  --capture-strategy=<strategy>  direct-detail (default) or reversible-delta
  --min-interval-ms=<n>          Start-to-start delay (default 30000; hard floor 2000)
  --jitter-ms=<n>                Additive random delay (default 5000)
  --daily-cap=<n>                UTC request cap (default 2000)

The crawl is single-worker and resumable. status, pause, resume, stop, and
retry-failures and sweep only touch local files and never contact Lucy.`;
}

function workspacePath(args) {
    return resolve(String(
        args.workspace
        ?? process.env.LUCY_ITEM_CRAWL_WORKSPACE
        ?? DEFAULT_WORKSPACE,
    ));
}

function configPath(root) {
    return join(root, 'config.json');
}

function queuePath(root) {
    return join(root, 'queue.json');
}

function progressPath(root) {
    return join(root, 'state', 'progress.json');
}

function retryQueuePath(root) {
    return join(root, 'state', 'retry-queue.json');
}

function logPath(root) {
    return join(root, 'logs', 'crawler.log');
}

function parseInteger(value, label, { minimum = 0, maximum = Number.MAX_SAFE_INTEGER } = {}) {
    const normalized = typeof value === 'number' ? value : Number(String(value));
    if (!Number.isSafeInteger(normalized) || normalized < minimum || normalized > maximum) {
        throw new CrawlerError(`${label} must be an integer from ${minimum} through ${maximum}.`, {
            code: 'invalid_option',
        });
    }

    return normalized;
}

function isWithin(parent, child) {
    const candidate = relative(resolve(parent), resolve(child));
    return candidate === '' || (!candidate.startsWith(`..${sep}`) && candidate !== '..' && !isAbsolute(candidate));
}

function samePath(left, right) {
    const normalizedLeft = resolve(left);
    const normalizedRight = resolve(right);

    return process.platform === 'win32'
        ? normalizedLeft.toLowerCase() === normalizedRight.toLowerCase()
        : normalizedLeft === normalizedRight;
}

async function assertPrivatePaths(workspace, artifactRoot) {
    const publicRoot = join(PROJECT_ROOT, 'public');
    if (isWithin(publicRoot, workspace) || isWithin(publicRoot, artifactRoot)) {
        throw new CrawlerError('Crawl workspaces and item-history artifacts must remain outside public/.', {
            code: 'unsafe_path',
        });
    }
    if (isWithin(workspace, artifactRoot) || isWithin(artifactRoot, workspace)) {
        throw new CrawlerError('The crawl workspace and public-site artifact root cannot overlap.', {
            code: 'overlapping_paths',
        });
    }

    let canonicalWorkspace;
    let canonicalArtifactRoot;
    let canonicalPublicRoot;
    try {
        [canonicalWorkspace, canonicalArtifactRoot, canonicalPublicRoot] = await Promise.all([
            establishRealDirectory(workspace, { label: 'crawl workspace' }),
            establishRealDirectory(artifactRoot, { label: 'item artifact root' }),
            establishRealDirectory(publicRoot, { label: 'application public root' }),
        ]);
    } catch (error) {
        throw new CrawlerError('Crawl paths must contain only real directories, not links or junctions.', {
            code: 'unsafe_path',
            cause: error,
        });
    }
    if (isWithin(canonicalPublicRoot, canonicalWorkspace)
        || isWithin(canonicalPublicRoot, canonicalArtifactRoot)) {
        throw new CrawlerError('Crawl workspaces and item-history artifacts must remain outside public/.', {
            code: 'unsafe_path',
        });
    }
    if (isWithin(canonicalWorkspace, canonicalArtifactRoot)
        || isWithin(canonicalArtifactRoot, canonicalWorkspace)) {
        throw new CrawlerError('The crawl workspace and public-site artifact root cannot overlap.', {
            code: 'overlapping_paths',
        });
    }

    return { workspace: canonicalWorkspace, artifactRoot: canonicalArtifactRoot };
}

function validateBaseUrl(value) {
    let url;
    try {
        url = new URL(value);
    } catch (error) {
        throw new CrawlerError('base-url must be an absolute URL.', { code: 'invalid_base_url', cause: error });
    }

    const loopback = ['localhost', '127.0.0.1', '::1'].includes(url.hostname);
    if (url.protocol !== 'https:' && !(loopback && url.protocol === 'http:')) {
        throw new CrawlerError('base-url must use HTTPS; HTTP is allowed only for loopback fixture servers.', {
            code: 'invalid_base_url',
        });
    }
    if (url.username !== '' || url.password !== '' || url.search !== '' || url.hash !== '') {
        throw new CrawlerError('base-url cannot contain credentials, a query, or a fragment.', {
            code: 'invalid_base_url',
        });
    }
    if (!url.pathname.endsWith('/')) url.pathname += '/';
    if (!loopback && url.href !== DEFAULT_BASE_URL) {
        throw new CrawlerError(`Production base-url must be exactly ${DEFAULT_BASE_URL}`, {
            code: 'invalid_base_url',
        });
    }

    return url.href;
}

function isLoopbackUrl(value) {
    const hostname = new URL(value).hostname;

    return ['localhost', '127.0.0.1', '::1'].includes(hostname);
}

export function cookieBootstrapTarget(bytes, contentType, currentUrl, baseUrl) {
    const html = decodeLucyHtml(bytes, declaredCharset(contentType) ?? 'windows-1252').trim();
    const wrapper = html.match(/^<head>\s*(<meta\b[^>]*>)\s*<\/head>$/i);
    if (wrapper === null) return null;

    const attribute = (name) => {
        const match = wrapper[1].match(new RegExp(`(?:^|\\s)${name}\\s*=\\s*(["'])(.*?)\\1`, 'i'));
        return match?.[2] ?? null;
    };
    if (attribute('http-equiv')?.toLowerCase() !== 'refresh') return null;
    const refresh = attribute('content')?.match(/^\s*0\s*;\s*url\s*=\s*(.{1,2048})\s*$/i);
    if (refresh === undefined || refresh === null) return null;

    let target;
    try {
        target = new URL(refresh[1].replaceAll('&amp;', '&'), currentUrl);
    } catch {
        return null;
    }
    const configured = new URL(baseUrl);
    if (target.origin !== configured.origin
        || target.username !== ''
        || target.password !== ''
        || target.hash !== ''
        || target.pathname !== currentUrl.pathname
        || (currentUrl.searchParams.has('setcookie')
            && (currentUrl.searchParams.getAll('setcookie').length !== 1
                || currentUrl.searchParams.get('setcookie') !== '1'))
        || target.searchParams.getAll('setcookie').length !== 1
        || target.searchParams.get('setcookie') !== '1') {
        return null;
    }

    const withoutBootstrap = new URL(target.href);
    withoutBootstrap.searchParams.delete('setcookie');
    const currentWithoutBootstrap = new URL(currentUrl.href);
    currentWithoutBootstrap.searchParams.delete('setcookie');
    const canonicalParams = (url) => [...url.searchParams.entries()]
        .sort(([leftName, leftValue], [rightName, rightValue]) => (
            leftName.localeCompare(rightName) || leftValue.localeCompare(rightValue)
        ));
    if (JSON.stringify(canonicalParams(withoutBootstrap)) !== JSON.stringify(canonicalParams(currentWithoutBootstrap))) {
        return null;
    }

    return target;
}

export function buildLucyCookieHeader(cookies) {
    if (!(cookies instanceof Map)) throw new TypeError('cookies must be a Map');
    if (cookies.size === 0) return null;

    const header = [...cookies.entries()]
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([name, value]) => `${name}=${value}`)
        .join('; ');
    if (header.length > 8_192) {
        throw new PauseRequested('Lucy cookies exceed the request-header safety limit.', {
            code: 'cookie_header_too_large',
        });
    }

    return header;
}

export function assertAcceptableLucyHtml(bytes, contentType = '') {
    const html = decodeLucyHtml(bytes, declaredCharset(contentType) ?? 'windows-1252');
    const digestBytes = bytes instanceof ArrayBuffer ? new Uint8Array(bytes) : bytes;
    const diagnostic = {
        byte_length: bytes.byteLength,
        content_type: String(contentType).slice(0, 256),
        sha256: createHash('sha256').update(digestBytes).digest('hex'),
        body_preview: html.slice(0, 512)
            .replace(/[\u0000-\u001f\u007f]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim(),
    };
    if (!/<(?:!doctype\s+html|html|title|table)\b/i.test(html)) {
        throw new PauseRequested('Lucy returned a body that does not look like an HTML item page.', {
            code: 'unexpected_body',
            context: diagnostic,
        });
    }
    if (/\b(?:captcha|cloudflare|access denied|verify you are human|checking your browser|attention required|too many requests|just a moment|system error)\b/i.test(html)
        || /no value sent for required parameter/i.test(html)
        || /trace begun at\s+\/home\/lucy/i.test(html)) {
        throw new PauseRequested('Lucy returned a challenge, access-denied, or system-error page.', {
            code: 'challenge_or_error_page',
            context: diagnostic,
        });
    }

    return html;
}

export function calculateCrawlerRetryBackoff({
    statusCode = null,
    attempt,
    retryAfter = null,
    jitterMs = DEFAULT_JITTER_MS,
    nowMs = Date.now(),
    random = Math.random,
} = {}) {
    const rateLimited = statusCode === 429;

    return calculateBackoff({
        attempt,
        baseMs: rateLimited ? RATE_LIMIT_MINIMUM_BACKOFF_MS : 60_000,
        maxMs: MAXIMUM_RETRY_BACKOFF_MS,
        jitterMs,
        retryAfter,
        nowMs,
        random,
    });
}

function validateShortText(value, label, maximum = 512) {
    const normalized = String(value ?? '').trim();
    if (normalized.length < 3 || normalized.length > maximum || /[\r\n]/.test(normalized)) {
        throw new CrawlerError(`${label} must contain 3-${maximum} characters on one line.`, {
            code: 'invalid_option',
        });
    }

    return normalized;
}

function validateCaptureStrategy(value) {
    const normalized = String(value ?? DEFAULT_CAPTURE_STRATEGY).trim().toLowerCase();
    if (!CAPTURE_STRATEGIES.has(normalized)) {
        throw new CrawlerError(
            `capture-strategy must be one of: ${[...CAPTURE_STRATEGIES].join(', ')}.`,
            { code: 'invalid_option' },
        );
    }

    return normalized;
}

async function buildConfig(root, args) {
    const contact = validateShortText(
        args.contact ?? process.env.LUCY_CRAWL_CONTACT,
        'contact',
        256,
    );
    const authorizationReference = validateShortText(
        args['authorization-ref'] ?? process.env.LUCY_CRAWL_AUTHORIZATION_REF,
        'authorization-ref',
        512,
    );
    const artifactRoot = resolve(String(
        args['artifact-root']
        ?? process.env.ITEM_HISTORY_ARTIFACT_PATH
        ?? DEFAULT_ARTIFACT_ROOT,
    ));
    const baseUrl = validateBaseUrl(args['base-url'] ?? DEFAULT_BASE_URL);
    const captureStrategy = validateCaptureStrategy(
        args['capture-strategy'] ?? process.env.LUCY_ITEM_CRAWL_CAPTURE_STRATEGY,
    );
    const minIntervalMs = parseInteger(
        args['min-interval-ms'] ?? DEFAULT_MIN_INTERVAL_MS,
        'min-interval-ms',
        { minimum: isLoopbackUrl(baseUrl) ? 0 : ABSOLUTE_MIN_INTERVAL_MS, maximum: 3_600_000 },
    );
    const jitterMs = parseInteger(
        args['jitter-ms'] ?? DEFAULT_JITTER_MS,
        'jitter-ms',
        { minimum: 0, maximum: 3_600_000 },
    );
    const dailyCap = parseInteger(
        args['daily-cap'] ?? DEFAULT_DAILY_CAP,
        'daily-cap',
        { minimum: 1, maximum: MAX_DAILY_CAP },
    );
    const itemListFile = args['item-list-file'] === undefined
        ? null
        : resolve(String(args['item-list-file']));

    const paths = await assertPrivatePaths(root, artifactRoot);

    return {
        schema: CONFIG_SCHEMA,
        version: VERSION,
        crawler_version: CRAWLER_VERSION,
        created_at: new Date().toISOString(),
        workspace: paths.workspace,
        artifact_root: paths.artifactRoot,
        base_url: baseUrl,
        item_list_url: 'itemlist.txt.gz',
        item_list_file: itemListFile,
        contact,
        authorization_reference: authorizationReference,
        capture_strategy: captureStrategy,
        min_interval_ms: minIntervalMs,
        jitter_ms: jitterMs,
        daily_cap: dailyCap,
        request_timeout_ms: DEFAULT_REQUEST_TIMEOUT_MS,
        max_html_bytes: DEFAULT_MAX_HTML_BYTES,
        max_item_list_bytes: DEFAULT_MAX_ITEM_LIST_BYTES,
        max_item_list_expanded_bytes: DEFAULT_MAX_ITEM_LIST_EXPANDED_BYTES,
    };
}

async function validateConfig(config, root) {
    if (config?.schema !== CONFIG_SCHEMA || config?.version !== VERSION) {
        throw new CrawlerError('The crawl configuration is missing or incompatible.', {
            code: 'invalid_config',
        });
    }
    if (typeof config.workspace !== 'string' || !samePath(config.workspace, root)) {
        throw new CrawlerError('The crawl configuration belongs to a different workspace path.', {
            code: 'invalid_config',
        });
    }
    config.contact = validateShortText(config.contact, 'configured contact', 256);
    config.authorization_reference = validateShortText(
        config.authorization_reference,
        'configured authorization reference',
        512,
    );
    config.base_url = validateBaseUrl(config.base_url);
    config.capture_strategy = validateCaptureStrategy(
        config.capture_strategy ?? DEFAULT_CAPTURE_STRATEGY,
    );
    config.artifact_root = resolve(config.artifact_root);
    config.min_interval_ms = parseInteger(config.min_interval_ms, 'configured min_interval_ms', {
        minimum: isLoopbackUrl(config.base_url) ? 0 : ABSOLUTE_MIN_INTERVAL_MS,
        maximum: 3_600_000,
    });
    config.jitter_ms = parseInteger(config.jitter_ms, 'configured jitter_ms', {
        minimum: 0,
        maximum: 3_600_000,
    });
    config.daily_cap = parseInteger(config.daily_cap, 'configured daily_cap', {
        minimum: 1,
        maximum: MAX_DAILY_CAP,
    });
    const paths = await assertPrivatePaths(root, config.artifact_root);
    config.workspace = paths.workspace;
    config.artifact_root = paths.artifactRoot;

    return config;
}

async function loadConfig(root) {
    try {
        await establishRealDirectory(root, { label: 'crawl workspace' });
    } catch (error) {
        throw new CrawlerError('The crawl workspace must contain only real directories.', {
            code: 'unsafe_path',
            cause: error,
        });
    }
    const config = await readAtomicJson(configPath(root), {
        allowMissing: true,
        root,
        label: 'crawl configuration',
    });
    if (config === null) {
        throw new CrawlerError(
            `No crawler configuration exists in ${root}. Run the init command first.`,
            { code: 'missing_config' },
        );
    }

    return validateConfig(config, root);
}

function createWorkspace(root, config = {}) {
    return new CrawlWorkspace(root, {
        minIntervalMs: config.min_interval_ms ?? DEFAULT_MIN_INTERVAL_MS,
        jitterMs: config.jitter_ms ?? DEFAULT_JITTER_MS,
        dailyCap: config.daily_cap ?? DEFAULT_DAILY_CAP,
    });
}

async function appendLog(root, level, message, context = {}) {
    const path = await ensureSafeCrawlerTarget(root, logPath(root), 'crawler log');
    const record = {
        timestamp: new Date().toISOString(),
        level,
        message,
        ...context,
    };
    await appendFile(path, `${JSON.stringify(record)}\n`, { encoding: 'utf8', mode: 0o600 });
}

function serializeError(error) {
    return {
        name: error?.name ?? 'Error',
        code: error?.code ?? null,
        message: error?.message ?? String(error),
        context: error?.context ?? null,
    };
}

async function initializeCommand(root, args) {
    if (isWithin(join(PROJECT_ROOT, 'public'), root)) {
        throw new CrawlerError('The crawl workspace must remain outside public/.', {
            code: 'unsafe_path',
        });
    }
    const workspace = createWorkspace(root);
    await workspace.initialize();
    const existing = await readAtomicJson(configPath(root), {
        allowMissing: true,
        root,
        label: 'crawl configuration',
    });
    if (existing !== null) {
        await validateConfig(existing, root);
        throw new CrawlerError(
            `A valid crawler configuration already exists at ${configPath(root)}; it was not changed.`,
            { code: 'config_exists' },
        );
    }

    const config = await buildConfig(root, args);
    await atomicWriteJson(configPath(root), config, {
        root,
        label: 'crawl configuration',
    });
    await workspace.setControl('run', {
        reason: 'Initialized and ready to start.',
        requestedBy: 'init command',
    });
    await workspace.updateStatus({
        state: 'ready',
        phase: 'initialized',
        crawlerVersion: CRAWLER_VERSION,
        artifactRoot: config.artifact_root,
        policy: {
            concurrency: 1,
            captureStrategy: config.capture_strategy,
            minIntervalMs: config.min_interval_ms,
            jitterMs: config.jitter_ms,
            dailyCap: config.daily_cap,
        },
    });

    console.log(`Initialized private crawl workspace: ${config.workspace}`);
    console.log(`Item JSON artifact root: ${config.artifact_root}`);
    console.log(`Policy: ${config.capture_strategy}, 1 worker, ${config.min_interval_ms}ms minimum + 0-${config.jitter_ms}ms jitter, ${config.daily_cap}/UTC day cap.`);
    console.log('No network request was made. Use the start command when ready.');
}

function processIsAlive(pid) {
    if (!Number.isInteger(pid) || pid < 1) return false;
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        return error?.code === 'EPERM';
    }
}

async function startCommand(root) {
    const config = await loadConfig(root);
    const workspace = createWorkspace(root, config);
    const owner = await workspace.readLockOwner();
    if (owner !== null && processIsAlive(owner.pid)) {
        throw new CrawlerError(`Crawler is already running as PID ${owner.pid}.`, {
            code: 'already_running',
        });
    }

    await workspace.setControl('run', {
        reason: 'Background start requested.',
        requestedBy: 'start command',
    });
    const safeLogPath = await ensureSafeCrawlerTarget(root, logPath(root), 'crawler log');
    const output = openSync(safeLogPath, 'a');
    const child = spawn(process.execPath, [SCRIPT_PATH, 'run', `--workspace=${root}`], {
        cwd: PROJECT_ROOT,
        detached: true,
        env: { ...process.env, LUCY_ITEM_CRAWL_BACKGROUND: '1' },
        stdio: ['ignore', output, output],
        windowsHide: true,
    });
    child.unref();
    closeSync(output);

    console.log(`Started authorized Lucy item crawler as PID ${child.pid}.`);
    console.log(`Local status: ${process.execPath} ${SCRIPT_PATH} status --workspace=${root}`);
    console.log(`Log: ${logPath(root)}`);
}

async function controlCommand(root, command, args) {
    const config = await loadConfig(root);
    const workspace = createWorkspace(root, config);
    const reason = args.reason === undefined
        ? `${command} command requested.`
        : validateShortText(args.reason, 'reason', 2_000);
    const control = await workspace.setControl(command, {
        reason,
        requestedBy: 'local operator',
    });

    console.log(`Crawler control is now ${control.command}. No network request was made.`);
}

async function statusCommand(root, args) {
    let config = null;
    try {
        config = await loadConfig(root);
    } catch (error) {
        if (error.code !== 'missing_config') throw error;
    }
    const workspace = createWorkspace(root, config ?? {});
    const [status, control, owner, progress] = await Promise.all([
        workspace.readStatus(),
        workspace.readControl(),
        workspace.readLockOwner(),
        readAtomicJson(progressPath(root), {
            allowMissing: true,
            maxBytes: MAX_QUEUE_BYTES,
            root,
            label: 'crawl progress',
        }),
    ]);
    const report = {
        workspace: root,
        configured: config !== null,
        state: status.state,
        phase: status.phase ?? null,
        updated_at: status.updatedAt ?? null,
        control: control.command,
        control_reason: control.reason,
        process: owner === null ? null : {
            pid: owner.pid,
            hostname: owner.hostname,
            alive_on_this_host: processIsAlive(owner.pid),
            heartbeat_at: owner.heartbeatAt,
            run_id: owner.runId,
        },
        queue: progress === null ? null : {
            primary_cursor: progress.primary_cursor,
            total_items: progress.total_items,
            completed_items: progress.completed_items,
            failed_items: progress.failed_items,
            current_item_id: progress.current_item_id,
            sweep_generation: progress.sweep_generation,
            item_list_refresh_pending: progress.refresh_item_list,
            retry_cursor: progress.retry_cursor,
            retry_total: progress.retry_ids?.length ?? 0,
        },
        rate: status.rate ?? null,
        metrics: status.metrics ?? null,
        last_request: status.lastRequest ?? null,
        last_error: status.lastError ?? null,
        capture_strategy: config?.capture_strategy ?? null,
        artifact_root: config?.artifact_root ?? null,
        log: logPath(root),
    };

    if (args.json === true || args.json === 'true') {
        console.log(JSON.stringify(report, null, 2));
        return;
    }

    console.log(`State: ${report.state}${report.phase ? ` (${report.phase})` : ''}`);
    if (report.capture_strategy !== null) {
        console.log(`Capture strategy: ${report.capture_strategy}`);
    }
    console.log(`Control: ${report.control}${report.control_reason ? ` — ${report.control_reason}` : ''}`);
    if (report.process !== null) {
        console.log(`Process: PID ${report.process.pid}, alive=${report.process.alive_on_this_host}, heartbeat=${report.process.heartbeat_at}`);
    } else {
        console.log('Process: not running');
    }
    if (report.queue !== null) {
        const percent = report.queue.total_items > 0
            ? ((report.queue.primary_cursor / report.queue.total_items) * 100).toFixed(2)
            : '0.00';
        console.log(`Primary queue: ${report.queue.primary_cursor}/${report.queue.total_items} (${percent}%)`);
        console.log(`Published: ${report.queue.completed_items}; unresolved failures: ${report.queue.failed_items}; current item: ${report.queue.current_item_id ?? 'none'}`);
        console.log(`Sweep generation: ${report.queue.sweep_generation}; item-list refresh pending: ${report.queue.item_list_refresh_pending}`);
        if (report.queue.retry_total > 0) {
            console.log(`Retry queue: ${report.queue.retry_cursor}/${report.queue.retry_total}`);
        }
    }
    if (report.rate?.requestsToday !== undefined) {
        console.log(`Requests today: ${report.rate.requestsToday}/${report.rate.dailyCap}; next permitted: ${report.rate.nextRequestNotBefore ?? 'now'}`);
    }
    if (report.last_error !== null) {
        console.log(`Last error: ${report.last_error.message ?? JSON.stringify(report.last_error)}`);
    }
    console.log(`Log: ${report.log}`);
    console.log('This status check made no network request.');
}

async function retryFailuresCommand(root) {
    const config = await loadConfig(root);
    const workspace = createWorkspace(root, config);
    const owner = await workspace.readLockOwner();
    if (owner !== null && processIsAlive(owner.pid)) {
        throw new CrawlerError('Stop the active crawler before rebuilding its retry queue.', {
            code: 'crawler_active',
        });
    }

    const errorPaths = await listSafeJsonFiles(root, join(root, 'errors', 'items'), 'item errors');
    const ids = [];
    for (const path of errorPaths) {
        const match = /^(\d+)\.json$/.exec(basename(path));
        if (match === null) continue;
        const record = await readAtomicJson(path, {
            allowMissing: true,
            root,
            label: 'item error state',
        });
        if (record !== null && record.resolved_at === null) ids.push(Number(match[1]));
    }
    const uniqueIds = [...new Set(ids)].sort((left, right) => left - right);
    await atomicWriteJson(retryQueuePath(root), {
        schema: 'modern-allaclone.lucy-item-crawl-retry-queue',
        version: VERSION,
        created_at: new Date().toISOString(),
        item_ids: uniqueIds,
    }, {
        maxBytes: MAX_QUEUE_BYTES,
        root,
        label: 'retry queue',
    });

    const progress = await readProgress(root);
    progress.retry_ids = uniqueIds;
    progress.retry_cursor = 0;
    progress.updated_at = new Date().toISOString();
    await writeProgress(root, progress);
    await workspace.setControl('run', {
        reason: 'Retry queue prepared.',
        requestedBy: 'retry-failures command',
    });

    console.log(`Prepared ${uniqueIds.length} unresolved item failures for retry. No network request was made.`);
    if (uniqueIds.length > 0) console.log('Use start to run the retry queue after the primary queue is complete.');
}

async function sweepCommand(root) {
    const config = await loadConfig(root);
    const workspace = createWorkspace(root, config);
    const owner = await workspace.readLockOwner();
    if (owner !== null && processIsAlive(owner.pid)) {
        throw new CrawlerError('Stop the active crawler before preparing another sweep.', {
            code: 'crawler_active',
        });
    }

    const progress = await readProgress(root);
    progress.sweep_generation = parseInteger(
        (progress.sweep_generation ?? 1) + 1,
        'sweep generation',
        { minimum: 2 },
    );
    progress.primary_cursor = 0;
    progress.retry_ids = [];
    progress.retry_cursor = 0;
    progress.completed_items = 0;
    progress.failed_items = 0;
    progress.current_item_id = null;
    progress.refresh_item_list = true;
    await writeProgress(root, progress);
    await workspace.setControl('run', {
        reason: `Item-history sweep ${progress.sweep_generation} prepared.`,
        requestedBy: 'sweep command',
    });
    await workspace.updateStatus({
        state: 'ready',
        phase: 'sweep-prepared',
        sweepGeneration: progress.sweep_generation,
        lastError: null,
    });

    console.log(`Prepared item-history sweep ${progress.sweep_generation}. No network request was made.`);
    console.log('The next run refreshes the item list, rechecks every history page, reuses immutable entry captures, and republishes changed items.');
}

function defaultProgress() {
    return {
        schema: PROGRESS_SCHEMA,
        version: VERSION,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        total_items: 0,
        primary_cursor: 0,
        retry_ids: [],
        retry_cursor: 0,
        completed_items: 0,
        failed_items: 0,
        current_item_id: null,
        sweep_generation: 1,
        refresh_item_list: false,
    };
}

async function readProgress(root) {
    const progress = await readAtomicJson(progressPath(root), {
        allowMissing: true,
        maxBytes: MAX_QUEUE_BYTES,
        root,
        label: 'crawl progress',
    }) ?? defaultProgress();
    if (progress.schema !== PROGRESS_SCHEMA || progress.version !== VERSION) {
        throw new CrawlerError('Crawl progress is incompatible.', { code: 'invalid_progress' });
    }
    progress.sweep_generation ??= 1;
    progress.refresh_item_list ??= false;
    const counters = [
        'total_items',
        'primary_cursor',
        'retry_cursor',
        'completed_items',
        'failed_items',
    ];
    if (counters.some((field) => !Number.isSafeInteger(progress[field]) || progress[field] < 0)
        || !Number.isSafeInteger(progress.sweep_generation)
        || progress.sweep_generation < 1
        || typeof progress.refresh_item_list !== 'boolean'
        || !Array.isArray(progress.retry_ids)
        || progress.retry_ids.some((itemId) => !Number.isSafeInteger(itemId) || itemId < 1)
        || new Set(progress.retry_ids).size !== progress.retry_ids.length
        || progress.primary_cursor > progress.total_items
        || progress.retry_cursor > progress.retry_ids.length
        || (progress.current_item_id !== null
            && (!Number.isSafeInteger(progress.current_item_id) || progress.current_item_id < 1))) {
        throw new CrawlerError('Crawl progress contains invalid counters, cursors, or item IDs.', {
            code: 'invalid_progress',
        });
    }

    return progress;
}

async function writeProgress(root, progress) {
    progress.updated_at = new Date().toISOString();
    await atomicWriteJson(progressPath(root), progress, {
        maxBytes: MAX_QUEUE_BYTES,
        root,
        label: 'crawl progress',
    });
}

function shardForItem(itemId) {
    return createHash('sha256').update(String(itemId)).digest('hex').slice(0, 2);
}

function itemWorkPath(root, itemId) {
    return join(root, 'work', 'items', shardForItem(itemId), `${itemId}.json`);
}

function itemErrorPath(root, itemId) {
    return join(root, 'errors', 'items', shardForItem(itemId), `${itemId}.json`);
}

function defaultItemWork(item) {
    return {
        schema: ITEM_WORK_SCHEMA,
        version: VERSION,
        item: { id: item.id, name: item.name },
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        history_capture: null,
        history_capture_parsed_sha256: null,
        history: null,
        detail_captures: {},
        details: {},
        raw_captures: {},
        current_raw: {},
        failed_capture: null,
        sweep_generation: 0,
        published_at: null,
    };
}

function validateFailedCapture(value, itemId) {
    if (value === null) return;
    const validStage = ['history', 'detail', 'raw'].includes(value?.stage);
    const validKey = value?.stage === 'history'
        ? value.key === null
        : value?.stage === 'detail'
            ? typeof value.key === 'string' && /^[1-9]\d*$/.test(value.key)
            : ['Live', 'Test'].includes(value?.key);
    if (!validStage || !validKey
        || typeof value.capture_sha256 !== 'string'
        || !/^[a-f0-9]{64}$/.test(value.capture_sha256)
        || value.error_code !== 'lucy_item_missing'
        || !Number.isSafeInteger(value.sweep_generation)
        || value.sweep_generation < 1) {
        throw new CrawlerError(`Item ${itemId} has an invalid failed-capture checkpoint.`, {
            code: 'invalid_item_work',
        });
    }
}

function captureHashes(history) {
    if (history === null || history === undefined) return [];
    const values = [
        ...(Array.isArray(history.capture_sha256s) ? history.capture_sha256s : []),
        ...(typeof history.capture_sha256 === 'string' ? [history.capture_sha256] : []),
    ];
    const hashes = [...new Set(values)];
    if (hashes.some((hash) => typeof hash !== 'string' || !/^[a-f0-9]{64}$/.test(hash))) {
        throw new LucyParseError(
            'history_capture_provenance',
            'Persisted item history contains invalid capture provenance.',
        );
    }

    return hashes.sort();
}

function revisionCaptureHashes(revision, fallbackHashes) {
    const configured = revision.history_capture_sha256s;
    if (configured === undefined) return [...fallbackHashes];
    if (!Array.isArray(configured)
        || configured.some((hash) => typeof hash !== 'string' || !/^[a-f0-9]{64}$/.test(hash))) {
        throw new LucyParseError(
            'history_capture_provenance',
            `Item history entry ${revision.entry_id ?? 'unknown'} contains invalid capture provenance.`,
        );
    }

    return [...new Set(configured)].sort();
}

function revisionCore(revision) {
    return {
        entry_id: revision.entry_id,
        source: revision.source,
        observed_at: revision.observed_at,
        observed_precision: revision.observed_precision,
        type: revision.type,
        changes: [...(revision.changes ?? [])]
            .sort((left, right) => canonicalJson(left).localeCompare(canonicalJson(right))),
    };
}

function compareHistoryRevisions(left, right) {
    return String(left.observed_at).localeCompare(String(right.observed_at))
        || (left.source === right.source ? 0 : left.source === 'Live' ? -1 : 1)
        || left.entry_id - right.entry_id;
}

function mergeHistoryObservations(previous, incoming, itemId) {
    if (incoming?.item_id !== itemId || !Array.isArray(incoming.revisions)) {
        throw new LucyParseError(
            'history_merge_input',
            `The refreshed history for item ${itemId} is invalid.`,
        );
    }
    if (previous !== null
        && (previous?.item_id !== itemId || !Array.isArray(previous.revisions))) {
        throw new LucyParseError(
            'history_merge_input',
            `The persisted history for item ${itemId} is invalid.`,
        );
    }

    const previousHashes = captureHashes(previous);
    const incomingHashes = captureHashes(incoming);
    const byEntryId = new Map();
    for (const revision of previous?.revisions ?? []) {
        if (!Number.isSafeInteger(revision?.entry_id) || revision.entry_id < 1
            || byEntryId.has(revision.entry_id)) {
            throw new LucyParseError(
                'history_revision_conflict',
                `The persisted history for item ${itemId} has duplicate or invalid entry IDs.`,
            );
        }
        byEntryId.set(revision.entry_id, {
            ...revision,
            history_capture_sha256s: revisionCaptureHashes(revision, previousHashes),
        });
    }

    for (const revision of incoming.revisions) {
        if (!Number.isSafeInteger(revision?.entry_id) || revision.entry_id < 1) {
            throw new LucyParseError(
                'history_revision_conflict',
                `The refreshed history for item ${itemId} has an invalid entry ID.`,
            );
        }
        const existing = byEntryId.get(revision.entry_id);
        const evidence = revisionCaptureHashes(revision, incomingHashes);
        if (existing === undefined) {
            byEntryId.set(revision.entry_id, {
                ...revision,
                history_capture_sha256s: evidence,
            });
            continue;
        }
        if (canonicalJson(revisionCore(existing)) !== canonicalJson(revisionCore(revision))) {
            throw new LucyParseError(
                'history_revision_conflict',
                `Lucy entry ${revision.entry_id} changed across item-history sweeps.`,
                {
                    item_id: itemId,
                    entry_id: revision.entry_id,
                    previous_source: existing.source,
                    refreshed_source: revision.source,
                },
            );
        }
        existing.history_capture_sha256s = [
            ...new Set([...existing.history_capture_sha256s, ...evidence]),
        ].sort();
    }

    const revisions = [...byEntryId.values()].sort(compareHistoryRevisions);
    const sources = ['Live', 'Test'].filter((source) =>
        (previous?.sources ?? []).includes(source)
        || (incoming.sources ?? []).includes(source)
        || revisions.some((revision) => revision.source === source));

    return {
        ...incoming,
        item_name: incoming.item_name ?? previous?.item_name ?? null,
        sources,
        revision_count: revisions.length,
        revisions,
        capture_sha256s: [...new Set([...previousHashes, ...incomingHashes])].sort(),
    };
}

function invalidateFailedCapture(work) {
    const failed = work.failed_capture;
    if (failed === null) return false;

    if (failed.stage === 'history') {
        if (work.history_capture?.sha256 === failed.capture_sha256) {
            work.history_capture = null;
            work.history_capture_parsed_sha256 = null;
        }
    } else if (failed.stage === 'detail') {
        if (work.detail_captures?.[failed.key]?.sha256 === failed.capture_sha256) {
            delete work.detail_captures[failed.key];
            delete work.details[failed.key];
        }
    } else if (work.raw_captures?.[failed.key]?.sha256 === failed.capture_sha256) {
        delete work.raw_captures[failed.key];
        delete work.current_raw[failed.key];
    }
    work.failed_capture = null;

    return true;
}

async function readItemWork(root, item) {
    const work = await readAtomicJson(itemWorkPath(root, item.id), {
        allowMissing: true,
        maxBytes: MAX_ARTIFACT_BYTES * 2,
        root,
        label: 'item work state',
    }) ?? defaultItemWork(item);
    if (work.schema !== ITEM_WORK_SCHEMA || work.version !== VERSION || work.item?.id !== item.id) {
        throw new CrawlerError(`Item ${item.id} has incompatible crawl work state.`, {
            code: 'invalid_item_work',
        });
    }
    work.history_capture_parsed_sha256 ??= work.history_capture !== null && work.history !== null
        ? work.history_capture.sha256
        : null;
    work.failed_capture ??= null;
    if (work.history_capture_parsed_sha256 !== null
        && (typeof work.history_capture_parsed_sha256 !== 'string'
            || !/^[a-f0-9]{64}$/.test(work.history_capture_parsed_sha256))) {
        throw new CrawlerError(`Item ${item.id} has an invalid history parse checkpoint.`, {
            code: 'invalid_item_work',
        });
    }
    validateFailedCapture(work.failed_capture, item.id);

    return work;
}

async function writeItemWork(root, work) {
    work.updated_at = new Date().toISOString();
    await atomicWriteJson(itemWorkPath(root, work.item.id), work, {
        maxBytes: MAX_ARTIFACT_BYTES * 2,
        root,
        label: 'item work state',
    });
}

class AuthorizedLucyCrawler {
    constructor(root, config, workspace) {
        this.root = root;
        this.config = config;
        this.workspace = workspace;
        this.stopRequested = false;
        this.runAbortController = new AbortController();
        this.activeRequestController = null;
        this.runId = randomUUID();
        this.queue = null;
        this.cookies = new Map();
        this.cookieExpirations = new Map();
        this.consecutiveRateLimits = 0;
    }

    async run() {
        const lock = await this.workspace.acquireLock({ runId: this.runId, recoverStale: true });
        if (!lock.acquired) {
            throw new CrawlerError(`Unable to acquire crawler lock (${lock.reason ?? 'unknown'}).`, {
                code: 'lock_unavailable',
                context: { owner: lock.owner },
            });
        }

        const stop = () => {
            this.stopRequested = true;
            this.runAbortController.abort();
            this.activeRequestController?.abort();
        };
        process.once('SIGINT', stop);
        process.once('SIGTERM', stop);
        try {
            await this.workspace.updateStatus({
                state: 'running',
                phase: 'starting',
                crawlerVersion: CRAWLER_VERSION,
                artifactRoot: this.config.artifact_root,
                policy: {
                    concurrency: 1,
                    captureStrategy: this.config.capture_strategy,
                    minIntervalMs: this.config.min_interval_ms,
                    jitterMs: this.config.jitter_ms,
                    dailyCap: this.config.daily_cap,
                },
                lastError: null,
            });
            await appendLog(this.root, 'info', 'Crawler run started.', {
                run_id: this.runId,
                capture_strategy: this.config.capture_strategy,
            });
            this.queue = await this.ensureQueue();
            await this.processQueues();
        } catch (error) {
            if (error instanceof StopRequested) {
                await this.workspace.updateStatus({ state: 'stopped', phase: 'stopped' });
                await appendLog(this.root, 'info', error.message, { run_id: this.runId });
                return;
            }
            if (error instanceof PauseRequested || error instanceof LucyParseError) {
                const reason = error instanceof LucyParseError
                    ? `Lucy parser safety pause: ${error.code}`
                    : error.message;
                await this.workspace.setControl('pause', {
                    reason,
                    requestedBy: 'crawler safety circuit',
                });
                await this.workspace.updateStatus({
                    state: 'paused',
                    phase: 'safety-pause',
                    lastError: serializeError(error),
                });
                await appendLog(this.root, 'error', reason, {
                    run_id: this.runId,
                    error: serializeError(error),
                });
                return;
            }
            await this.workspace.updateStatus({
                state: 'failed',
                phase: 'failed',
                lastError: serializeError(error),
            }).catch(() => {});
            await appendLog(this.root, 'error', error.message, {
                run_id: this.runId,
                error: serializeError(error),
            }).catch(() => {});
            throw error;
        } finally {
            process.removeListener('SIGINT', stop);
            process.removeListener('SIGTERM', stop);
            await this.workspace.releaseLock().catch(async (error) => {
                await appendLog(this.root, 'error', 'Failed to release crawler lock.', {
                    error: serializeError(error),
                }).catch(() => {});
            });
        }
    }

    async waitForControl() {
        while (true) {
            if (this.stopRequested) throw new StopRequested('Crawler received a process stop signal.');
            const control = await this.workspace.readControl();
            if (control.command === 'stop') throw new StopRequested(control.reason ?? undefined);
            if (control.command === 'run') return;

            await this.workspace.updateStatus({ state: 'paused', phase: 'paused', control });
            await this.workspace.heartbeat();
            await delay(PAUSE_POLL_MS);
        }
    }

    async ensureQueue() {
        const existing = await readAtomicJson(queuePath(this.root), {
            allowMissing: true,
            maxBytes: MAX_QUEUE_BYTES,
            root: this.root,
            label: 'item queue',
        });
        const progress = await readProgress(this.root);
        if (existing !== null && !progress.refresh_item_list) {
            this.validateQueue(existing);
            return existing;
        }
        if (existing !== null) this.validateQueue(existing);

        await this.workspace.updateStatus({ state: 'running', phase: 'seeding-item-list' });
        let bytes;
        let capture = null;
        if (this.config.item_list_file !== null) {
            bytes = await readBoundedFile(
                this.config.item_list_file,
                this.config.max_item_list_bytes,
                'configured item list',
            );
        } else {
            const response = await this.fetchCapture(
                new URL(this.config.item_list_url, this.config.base_url),
                { kind: 'item-list', maxBytes: this.config.max_item_list_bytes },
            );
            bytes = response.bytes;
            capture = response.capture;
        }

        const expanded = bytes[0] === 0x1f && bytes[1] === 0x8b
            ? boundedGunzip(bytes, this.config.max_item_list_expanded_bytes, 'Lucy item list')
            : bytes;
        if (expanded.byteLength > this.config.max_item_list_expanded_bytes) {
            throw new CrawlerError('Expanded Lucy item list exceeds its configured byte limit.', {
                code: 'item_list_too_large',
            });
        }
        const parsed = parseItemList(decodeText(expanded, 'text/csv; charset=utf-8'));
        const byId = new Map((existing?.items ?? []).map((item) => [item.id, item]));
        for (const item of parsed.items) byId.set(item.id, item);
        const items = [...byId.values()].sort((left, right) => left.id - right.id);
        const queue = {
            schema: QUEUE_SCHEMA,
            version: VERSION,
            created_at: existing?.created_at ?? new Date().toISOString(),
            refreshed_at: new Date().toISOString(),
            source_url: new URL(this.config.item_list_url, this.config.base_url).href,
            capture,
            source_snapshot_item_count: parsed.items.length,
            item_count: items.length,
            items,
        };
        await atomicWriteJson(queuePath(this.root), queue, {
            maxBytes: MAX_QUEUE_BYTES,
            root: this.root,
            label: 'item queue',
        });
        progress.total_items = queue.item_count;
        progress.refresh_item_list = false;
        await writeProgress(this.root, progress);
        await appendLog(this.root, 'info', existing === null ? 'Lucy item list seeded.' : 'Lucy item list refreshed.', {
            item_count: queue.item_count,
            source_snapshot_item_count: parsed.items.length,
            newly_discovered_items: queue.item_count - (existing?.item_count ?? 0),
        });

        return queue;
    }

    validateQueue(queue) {
        if (queue?.schema !== QUEUE_SCHEMA || queue?.version !== VERSION
            || !Array.isArray(queue.items) || queue.item_count !== queue.items.length) {
            throw new CrawlerError('The persisted item queue is incompatible.', { code: 'invalid_queue' });
        }
        let previous = 0;
        for (const item of queue.items) {
            if (!Number.isSafeInteger(item?.id) || item.id <= previous || typeof item.name !== 'string') {
                throw new CrawlerError('The persisted item queue contains an invalid item.', {
                    code: 'invalid_queue',
                });
            }
            previous = item.id;
        }
    }

    async processQueues() {
        const progress = await readProgress(this.root);
        if (progress.primary_cursor > this.queue.items.length) {
            throw new CrawlerError('The primary cursor is beyond the persisted item queue.', {
                code: 'invalid_progress',
            });
        }
        progress.total_items = this.queue.item_count;
        await writeProgress(this.root, progress);

        while (progress.primary_cursor < this.queue.items.length) {
            await this.waitForControl();
            const item = this.queue.items[progress.primary_cursor];
            await this.processQueueItem(item, progress, 'primary');
        }

        const byId = new Map(this.queue.items.map((item) => [item.id, item]));
        while (progress.retry_cursor < progress.retry_ids.length) {
            await this.waitForControl();
            const itemId = progress.retry_ids[progress.retry_cursor];
            const item = byId.get(itemId);
            if (item === undefined) {
                throw new CrawlerError(`Retry item ${itemId} is absent from the item queue.`, {
                    code: 'invalid_retry_queue',
                });
            }
            await this.processQueueItem(item, progress, 'retry');
        }

        progress.current_item_id = null;
        await writeProgress(this.root, progress);
        const unresolved = await this.countUnresolvedFailures();
        progress.failed_items = unresolved;
        await writeProgress(this.root, progress);
        const state = unresolved === 0 ? 'complete' : 'complete-with-gaps';
        await this.workspace.updateStatus({ state, phase: state, lastError: null });
        await appendLog(this.root, 'info', 'Crawler queues completed.', {
            unresolved_failures: unresolved,
        });
    }

    async processQueueItem(item, progress, mode) {
        progress.current_item_id = item.id;
        await writeProgress(this.root, progress);
        await this.workspace.updateStatus({
            state: 'running',
            phase: 'item',
            currentItem: { id: item.id, name: item.name, queue: mode },
            queue: {
                primaryCursor: progress.primary_cursor,
                totalItems: progress.total_items,
                retryCursor: progress.retry_cursor,
                retryTotal: progress.retry_ids.length,
                completedItems: progress.completed_items,
                failedItems: progress.failed_items,
            },
        });

        try {
            await this.captureItem(item, progress.sweep_generation ?? 1, {
                explicitRetry: mode === 'retry',
            });
            progress.completed_items += 1;
            await this.resolveItemError(item.id);
            await appendLog(this.root, 'info', 'Published item history.', { item_id: item.id });
        } catch (error) {
            if (error instanceof StopRequested) throw error;
            if ((error instanceof LucyParseError && error.code !== 'lucy_item_missing')
                || error instanceof PauseRequested) {
                const reason = error instanceof LucyParseError
                    ? `Lucy parser paused on item ${item.id}: ${error.code}`
                    : error.message;
                await this.workspace.setControl('pause', {
                    reason,
                    requestedBy: 'crawler safety circuit',
                });
                await this.workspace.updateStatus({
                    state: 'paused',
                    phase: 'safety-pause',
                    lastError: serializeError(error),
                });
                await appendLog(this.root, 'error', reason, {
                    item_id: item.id,
                    error: serializeError(error),
                });
                await this.waitForControl();
                return;
            }

            progress.failed_items += 1;
            await this.recordItemError(item, error);
            await appendLog(this.root, 'error', 'Item crawl failed; item retained for explicit retry.', {
                item_id: item.id,
                error: serializeError(error),
            });
        }

        if (mode === 'primary') progress.primary_cursor += 1;
        else progress.retry_cursor += 1;
        progress.current_item_id = null;
        await writeProgress(this.root, progress);
    }

    async captureItem(item, sweepGeneration, { explicitRetry = false } = {}) {
        const work = await readItemWork(this.root, item);
        const generationChanged = work.sweep_generation !== sweepGeneration;
        let workChanged = false;
        if (work.failed_capture !== null && (explicitRetry || generationChanged)) {
            workChanged = invalidateFailedCapture(work) || workChanged;
        }
        if (generationChanged) {
            work.history_capture = null;
            work.history_capture_parsed_sha256 = null;
            work.raw_captures = {};
            work.current_raw = {};
            work.sweep_generation = sweepGeneration;
            workChanged = true;
        }
        if (workChanged) {
            await writeItemWork(this.root, work);
        }
        const historyUrl = this.pageUrl('itemhistory.html', { id: item.id });
        if (work.history_capture === null) {
            const response = await this.fetchCapture(historyUrl, { kind: 'history' });
            work.history_capture = response.capture;
            await writeItemWork(this.root, work);
        }
        if (work.history_capture_parsed_sha256 !== work.history_capture.sha256) {
            const html = await this.readCaptureText(work.history_capture);
            const refreshedHistory = await this.parseCapturedPage(work, {
                stage: 'history',
                key: null,
                capture: work.history_capture,
            }, () => parseItemHistoryPage(html, {
                    itemId: item.id,
                    url: this.parserPageUrl(historyUrl),
                    captureSha256: work.history_capture.sha256,
                }));
            work.history = mergeHistoryObservations(work.history, refreshedHistory, item.id);
            work.history_capture_parsed_sha256 = work.history_capture.sha256;
            await writeItemWork(this.root, work);
        }

        const reversibleDelta = this.config.capture_strategy === REVERSIBLE_DELTA_CONFIG_STRATEGY;
        if (!reversibleDelta) {
            for (const revision of work.history.revisions) {
                const key = String(revision.entry_id);
                const detailUrl = this.pageUrl('item.html', { entryid: revision.entry_id });
                if (work.details[key] !== undefined && work.details[key].source !== revision.source) {
                    delete work.details[key];
                    delete work.detail_captures[key];
                    await writeItemWork(this.root, work);
                }
                if (work.detail_captures[key] === undefined) {
                    const response = await this.fetchCapture(detailUrl, { kind: 'detail' });
                    work.detail_captures[key] = response.capture;
                    await writeItemWork(this.root, work);
                }
                if (work.details[key] === undefined) {
                    const html = await this.readCaptureText(work.detail_captures[key]);
                    work.details[key] = await this.parseCapturedPage(work, {
                        stage: 'detail',
                        key,
                        capture: work.detail_captures[key],
                    }, () => parseItemDetailPage(html, {
                            itemId: item.id,
                            entryId: revision.entry_id,
                            source: revision.source,
                            url: this.parserPageUrl(detailUrl),
                            historyObservedAt: revision.observed_at,
                            captureSha256: work.detail_captures[key].sha256,
                        }));
                    await writeItemWork(this.root, work);
                }
            }
        }

        const anchorSource = reversibleDelta
            ? (work.history.sources.includes('Live') ? 'Live' : work.history.sources[0])
            : null;
        if (reversibleDelta && anchorSource === undefined) {
            throw new LucyParseError(
                'reconstruction_anchor_source',
                `Item ${item.id} has no Lucy source available for reversible reconstruction.`,
            );
        }
        const rawSources = reversibleDelta ? [anchorSource] : work.history.sources;
        for (const source of rawSources) {
            const rawUrl = this.pageUrl('itemraw.html', { id: item.id, source });
            if (work.raw_captures[source] === undefined) {
                const response = await this.fetchCapture(rawUrl, { kind: 'raw' });
                work.raw_captures[source] = response.capture;
                await writeItemWork(this.root, work);
            }
            if (work.current_raw[source] === undefined) {
                const html = await this.readCaptureText(work.raw_captures[source]);
                work.current_raw[source] = await this.parseCapturedPage(work, {
                    stage: 'raw',
                    key: source,
                    capture: work.raw_captures[source],
                }, () => parseItemRawPage(html, {
                        itemId: item.id,
                        source,
                        url: this.parserPageUrl(rawUrl),
                        captureSha256: work.raw_captures[source].sha256,
                    }));
                await writeItemWork(this.root, work);
            }
        }

        const activeEntryIds = new Set(work.history.revisions.map((revision) => String(revision.entry_id)));
        const activeDetails = Object.fromEntries(
            Object.entries(work.details).filter(([entryId]) => activeEntryIds.has(entryId)),
        );
        const activeDetailCaptures = Object.fromEntries(
            Object.entries(work.detail_captures).filter(([entryId]) => activeEntryIds.has(entryId)),
        );
        const activeCurrentRaw = reversibleDelta
            ? { [anchorSource]: work.current_raw[anchorSource] }
            : work.current_raw;
        const activeRawCaptures = reversibleDelta
            ? { [anchorSource]: work.raw_captures[anchorSource] }
            : work.raw_captures;
        const reconstruction = reversibleDelta
            ? analyzeReversibleItemHistory({
                itemId: item.id,
                history: work.history,
                currentRaw: activeCurrentRaw,
                anchorSource,
            })
            : null;
        const artifact = buildItemHistoryArtifact({
            itemId: item.id,
            itemListName: item.name,
            history: work.history,
            detailsByEntry: activeDetails,
            currentRaw: activeCurrentRaw,
            generatedAt: new Date().toISOString(),
            gaps: [],
            complete: true,
            captureStrategy: reversibleDelta ? REVERSIBLE_DELTA_CAPTURE_STRATEGY : null,
            anchorSource,
            reconstruction,
            captures: {
                history: work.history_capture,
                entries: activeDetailCaptures,
                raw: activeRawCaptures,
            },
        });
        artifact.history_capture_sha256s = captureHashes(work.history);
        const historyByEntryId = new Map(
            work.history.revisions.map((revision) => [revision.entry_id, revision]),
        );
        for (const revision of artifact.revisions) {
            const evidence = historyByEntryId.get(revision.entry_id)?.history_capture_sha256s ?? [];
            if (evidence.length > 0) revision.history_capture_sha256s = [...evidence];
        }
        await this.publishArtifact(artifact);
        work.published_at = artifact.generated_at;
        await writeItemWork(this.root, work);
    }

    async parseCapturedPage(work, { stage, key, capture }, parser) {
        try {
            return parser();
        } catch (error) {
            if (error instanceof LucyParseError && error.code === 'lucy_item_missing') {
                work.failed_capture = {
                    stage,
                    key,
                    capture_sha256: capture.sha256,
                    error_code: error.code,
                    sweep_generation: work.sweep_generation,
                    failed_at: new Date().toISOString(),
                };
                error.context = {
                    ...(error.context ?? {}),
                    capture_stage: stage,
                    capture_key: key,
                    capture_sha256: capture.sha256,
                };
                await writeItemWork(this.root, work);
            }
            throw error;
        }
    }

    pageUrl(pathname, parameters) {
        const url = new URL(pathname, this.config.base_url);
        for (const [key, value] of Object.entries(parameters)) url.searchParams.set(key, String(value));
        return url;
    }

    parserPageUrl(url) {
        return new URL(this.config.base_url).origin === new URL(DEFAULT_BASE_URL).origin
            ? url.href
            : null;
    }

    async publishArtifact(artifact) {
        if (artifact.complete !== true || artifact.gaps.length !== 0) {
            throw new CrawlerError('Refusing to publish an incomplete item-history artifact.', {
                code: 'incomplete_artifact',
            });
        }
        const relativePath = itemHistoryRelativePath(artifact.item_id);
        const target = join(this.config.artifact_root, ...relativePath.split('/'));
        if (!isWithin(this.config.artifact_root, target)) {
            throw new CrawlerError('Item artifact path escaped its configured root.', {
                code: 'unsafe_artifact_path',
            });
        }
        const json = `${canonicalJson(artifact)}\n`;
        if (Buffer.byteLength(json) > MAX_ARTIFACT_BYTES) {
            throw new CrawlerError(`Item ${artifact.item_id} artifact exceeds ${MAX_ARTIFACT_BYTES} bytes.`, {
                code: 'artifact_too_large',
            });
        }
        const safeTarget = await ensureSafeCrawlerTarget(
            this.config.artifact_root,
            target,
            'item artifact',
        );
        await atomicWriteText(safeTarget, json);
    }

    async fetchCapture(url, { kind, maxBytes = this.config.max_html_bytes }) {
        if (!(url instanceof URL) || url.origin !== new URL(this.config.base_url).origin) {
            throw new CrawlerError('Refusing to request a URL outside the configured Lucy origin.', {
                code: 'unsafe_request_url',
            });
        }

        let lastError = null;
        let requestUrl = new URL(url.href);
        let cookieBootstrapUsed = false;
        for (let attempt = 1; ; attempt += 1) {
            await this.waitForControl();
            this.cookieHeader();
            let slot;
            try {
                slot = await this.workspace.waitForRateSlot({
                    signal: this.runAbortController.signal,
                    onWait: async (wait) => {
                        await appendLog(this.root, 'info', 'Request waiting on durable rate gate.', {
                            reason: wait.reason,
                            until: wait.until,
                        });
                    },
                });
            } catch (error) {
                if (this.stopRequested || error?.name === 'AbortError') {
                    throw new StopRequested('Crawler received a process stop signal.');
                }
                throw error;
            }
            if (!slot.granted) {
                if (slot.reason === 'stopped') throw new StopRequested(slot.control?.reason ?? undefined);
                await this.waitForControl();
                attempt -= 1;
                continue;
            }

            const cookie = this.cookieHeader();
            const started = Date.now();
            const controller = new AbortController();
            this.activeRequestController = controller;
            const timeout = setTimeout(() => controller.abort(), this.config.request_timeout_ms);
            let response;
            let bytes = null;
            let requestFailure = null;
            try {
                const headers = {
                    Accept: kind === 'item-list'
                        ? 'application/gzip, application/octet-stream, text/csv;q=0.9, */*;q=0.1'
                        : 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1',
                    'User-Agent': this.userAgent(),
                };
                if (cookie !== null) headers.Cookie = cookie;
                response = await fetch(requestUrl, {
                    method: 'GET',
                    redirect: 'error',
                    signal: controller.signal,
                    headers,
                });
                bytes = await readResponseBytes(response, maxBytes);
                await this.workspace.recordRequest({
                    statusCode: response.status,
                    success: response.ok,
                    durationMs: Date.now() - started,
                    bytes: bytes.byteLength,
                    url: requestUrl.href,
                });
            } catch (error) {
                if (this.stopRequested && error?.name === 'AbortError') {
                    throw new StopRequested('Crawler received a process stop signal.');
                }
                requestFailure = error;
                await this.workspace.recordRequest({
                    statusCode: null,
                    success: false,
                    durationMs: Date.now() - started,
                    bytes: bytes?.byteLength ?? 0,
                    url: requestUrl.href,
                    error,
                });
                lastError = new CrawlerError(`Network request failed for ${requestUrl.href}.`, {
                    code: error?.name === 'AbortError' ? 'request_timeout' : 'network_error',
                    cause: error,
                });
            } finally {
                clearTimeout(timeout);
                this.activeRequestController = null;
            }

            if (requestFailure?.code === 'response_too_large') {
                throw new PauseRequested('Lucy returned a body larger than the configured safety limit.', {
                    code: 'response_too_large',
                    cause: requestFailure,
                });
            }

            if (response?.ok && bytes !== null) {
                this.rememberResponseCookies(response, requestUrl);
                if (kind !== 'item-list') {
                    const bootstrapTarget = cookieBootstrapTarget(
                        bytes,
                        response.headers.get('content-type') ?? '',
                        requestUrl,
                        this.config.base_url,
                    );
                    if (bootstrapTarget !== null) {
                        if (cookieBootstrapUsed) {
                            throw new PauseRequested('Lucy repeated its cookie bootstrap response.', {
                                code: 'cookie_bootstrap_loop',
                            });
                        }
                        cookieBootstrapUsed = true;
                        await appendLog(this.root, 'info', 'Following Lucy cookie bootstrap through the durable rate gate.', {
                            from: requestUrl.href,
                            to: bootstrapTarget.href,
                        });
                        this.consecutiveRateLimits = 0;
                        requestUrl = bootstrapTarget;
                        attempt -= 1;
                        continue;
                    }
                    this.assertLooksLikeLucyHtml(bytes, response);
                }
                const capture = await this.storeCapture(requestUrl, response, bytes, kind);
                this.consecutiveRateLimits = 0;
                return { bytes, capture, response };
            }

            if (response !== undefined && [401, 403].includes(response.status)) {
                throw new PauseRequested(`Lucy returned HTTP ${response.status}; authorization/access must be checked.`, {
                    code: 'access_denied',
                });
            }
            if (response !== undefined && response.status === 404) {
                throw new CrawlerError(`Lucy returned HTTP 404 for ${requestUrl.href}.`, {
                    code: 'not_found',
                    context: { status: 404, url: requestUrl.href },
                });
            }
            if (response?.status === 429) {
                this.consecutiveRateLimits += 1;
                if (this.consecutiveRateLimits >= 2) {
                    throw new PauseRequested(
                        'Lucy repeatedly returned HTTP 429; operator review is required before resuming.',
                        {
                            code: 'repeated_rate_limit',
                            context: {
                                status: 429,
                                url: requestUrl.href,
                                consecutive_rate_limits: this.consecutiveRateLimits,
                            },
                        },
                    );
                }
            }

            const retryable = requestFailure !== null
                || response === undefined
                || [408, 425, 429].includes(response.status)
                || response.status >= 500;
            if (!retryable) {
                throw new CrawlerError(`Lucy returned unexpected HTTP ${response.status} for ${requestUrl.href}.`, {
                    code: 'unexpected_http_status',
                    context: { status: response.status, url: requestUrl.href },
                });
            }
            if (response !== undefined) {
                lastError = new CrawlerError(`Lucy returned retryable HTTP ${response.status}.`, {
                    code: 'retryable_http_status',
                    context: { status: response.status, url: requestUrl.href },
                });
            }
            const waitMs = calculateCrawlerRetryBackoff({
                statusCode: response?.status ?? null,
                attempt,
                jitterMs: this.config.jitter_ms,
                retryAfter: response?.headers.get('retry-after') ?? null,
            });
            await this.workspace.deferRequestsUntil(Date.now() + waitMs, {
                reason: `retry-${lastError?.code ?? 'request'}`,
            });
            await appendLog(this.root, 'warning', 'Request deferred before retry.', {
                url: requestUrl.href,
                attempt,
                wait_ms: waitMs,
                error: serializeError(lastError),
            });
        }
    }

    assertLooksLikeLucyHtml(bytes, response) {
        const contentType = response.headers.get('content-type') ?? '';
        assertAcceptableLucyHtml(bytes, contentType);
    }

    userAgent() {
        return `ModernAllacloneAuthorizedItemHistoryCrawler/${CRAWLER_VERSION} (+${this.config.contact}; authorization: ${this.config.authorization_reference})`;
    }

    cookieHeader() {
        const now = Date.now();
        for (const [name, expiresAt] of this.cookieExpirations) {
            if (expiresAt !== null && expiresAt <= now) {
                this.cookies.delete(name);
                this.cookieExpirations.delete(name);
            }
        }

        return buildLucyCookieHeader(this.cookies);
    }

    rememberResponseCookies(response, requestUrl) {
        const values = typeof response.headers.getSetCookie === 'function'
            ? response.headers.getSetCookie()
            : [response.headers.get('set-cookie')].filter((value) => value !== null);
        for (const value of values) {
            const segments = String(value).split(';');
            const pair = segments.shift() ?? '';
            const separator = pair.indexOf('=');
            const name = separator < 0 ? '' : pair.slice(0, separator).trim();
            const cookieValue = separator < 0 ? '' : pair.slice(separator + 1).trim();
            if (!/^[!#$%&'*+.^_`|~0-9A-Za-z-]{1,64}$/.test(name)
                || cookieValue.length > 4096
                || /[;,\r\n]/.test(cookieValue)) {
                throw new PauseRequested('Lucy returned an invalid cookie.', {
                    code: 'invalid_cookie',
                });
            }
            const attributes = new Map();
            for (const segment of segments) {
                const attribute = segment.trim();
                if (attribute === '') continue;
                const attributeSeparator = attribute.indexOf('=');
                const attributeName = (attributeSeparator < 0
                    ? attribute
                    : attribute.slice(0, attributeSeparator)).trim().toLowerCase();
                const attributeValue = attributeSeparator < 0
                    ? ''
                    : attribute.slice(attributeSeparator + 1).trim();
                if (!/^[a-z0-9-]{1,64}$/.test(attributeName)
                    || /[;\r\n]/.test(attributeValue)
                    || attributeValue.length > 1_024) {
                    throw new PauseRequested('Lucy returned an invalid cookie attribute.', {
                        code: 'invalid_cookie',
                    });
                }
                attributes.set(attributeName, attributeValue);
            }

            const path = attributes.get('path') ?? '/';
            if (path !== '/') {
                throw new PauseRequested('Lucy returned an unsupported path-scoped cookie.', {
                    code: 'unsupported_cookie_scope',
                });
            }
            const domain = (attributes.get('domain') ?? requestUrl.hostname)
                .replace(/^\./, '')
                .toLowerCase();
            const hostname = requestUrl.hostname.toLowerCase();
            if (domain === '' || (hostname !== domain && !hostname.endsWith(`.${domain}`))) {
                throw new PauseRequested('Lucy returned a cookie for another domain.', {
                    code: 'unsupported_cookie_scope',
                });
            }
            let expiresAt = null;
            let maxAgePresent = false;
            if (attributes.has('max-age')) {
                maxAgePresent = true;
                const maxAge = attributes.get('max-age');
                const maxAgeSeconds = Number(maxAge);
                if (!/^-?\d+$/.test(maxAge) || !Number.isSafeInteger(maxAgeSeconds)) {
                    throw new PauseRequested('Lucy returned an invalid cookie lifetime.', {
                        code: 'invalid_cookie',
                    });
                }
                if (maxAgeSeconds <= 0) {
                    this.cookies.delete(name);
                    this.cookieExpirations.delete(name);
                    continue;
                }
                expiresAt = Math.min(Number.MAX_SAFE_INTEGER, Date.now() + (maxAgeSeconds * 1_000));
            }
            if (!maxAgePresent && attributes.has('expires')) {
                expiresAt = Date.parse(attributes.get('expires'));
                if (!Number.isFinite(expiresAt)) {
                    throw new PauseRequested('Lucy returned an invalid cookie expiration.', {
                        code: 'invalid_cookie',
                    });
                }
                if (expiresAt <= Date.now()) {
                    this.cookies.delete(name);
                    this.cookieExpirations.delete(name);
                    continue;
                }
            }
            if (attributes.has('secure') && requestUrl.protocol !== 'https:') continue;
            if (!this.cookies.has(name) && this.cookies.size >= 16) {
                throw new PauseRequested('Lucy returned too many cookies.', {
                    code: 'too_many_cookies',
                });
            }
            this.cookies.set(name, cookieValue);
            this.cookieExpirations.set(name, expiresAt);
        }
    }

    async storeCapture(url, response, bytes, kind) {
        const sha256 = createHash('sha256').update(bytes).digest('hex');
        const relativePath = join('raw', sha256.slice(0, 2), `${sha256}.${kind}.gz`);
        const path = join(this.root, relativePath);
        await publishContentAddressedCapture({
            root: this.root,
            path,
            compressedBytes: gzipSync(bytes, { level: 9 }),
            expectedSha256: sha256,
            maxExpandedBytes: kind === 'item-list'
                ? this.config.max_item_list_bytes
                : this.config.max_html_bytes,
        });

        return {
            sha256,
            path: relativePath.split(sep).join('/'),
            kind,
            url: url.href,
            status: response.status,
            captured_at: new Date().toISOString(),
            byte_length: bytes.byteLength,
            content_type: response.headers.get('content-type'),
            etag: response.headers.get('etag'),
            last_modified: response.headers.get('last-modified'),
        };
    }

    async readCaptureText(capture) {
        if (!capture || !/^[a-f0-9]{64}$/.test(capture.sha256) || typeof capture.path !== 'string') {
            throw new CrawlerError('Persisted raw capture reference is invalid.', {
                code: 'invalid_capture_reference',
            });
        }
        const path = resolve(this.root, ...capture.path.split('/'));
        if (!isWithin(this.root, path)) {
            throw new CrawlerError('Raw capture path escaped its workspace.', {
                code: 'invalid_capture_reference',
            });
        }
        const bytes = await readVerifiedContentAddressedCapture({
            root: this.root,
            path,
            expectedSha256: capture.sha256,
            maxExpandedBytes: this.config.max_html_bytes,
        });

        return decodeLucyHtml(
            bytes,
            declaredCharset(capture.content_type) ?? 'windows-1252',
        );
    }

    async recordItemError(item, error) {
        const path = itemErrorPath(this.root, item.id);
        const existing = await readAtomicJson(path, {
            allowMissing: true,
            root: this.root,
            label: 'item error state',
        }) ?? {
            schema: ERROR_SCHEMA,
            version: VERSION,
            item_id: item.id,
            item_name: item.name,
            first_failed_at: new Date().toISOString(),
            attempts: 0,
        };
        existing.attempts += 1;
        existing.last_failed_at = new Date().toISOString();
        existing.resolved_at = null;
        existing.error = serializeError(error);
        await atomicWriteJson(path, existing, {
            root: this.root,
            label: 'item error state',
        });
    }

    async resolveItemError(itemId) {
        const path = itemErrorPath(this.root, itemId);
        const existing = await readAtomicJson(path, {
            allowMissing: true,
            root: this.root,
            label: 'item error state',
        });
        if (existing === null || existing.resolved_at !== null) return;
        existing.resolved_at = new Date().toISOString();
        await atomicWriteJson(path, existing, {
            root: this.root,
            label: 'item error state',
        });
    }

    async countUnresolvedFailures() {
        const errorsRoot = join(this.root, 'errors', 'items');
        const errorPaths = await listSafeJsonFiles(this.root, errorsRoot, 'item errors');
        let count = 0;
        for (const path of errorPaths) {
            const record = await readAtomicJson(path, {
                allowMissing: true,
                root: this.root,
                label: 'item error state',
            });
            if (record?.resolved_at === null) count += 1;
        }

        return count;
    }
}

async function readResponseBytes(response, maximum) {
    if (response.body === null) return Buffer.alloc(0);
    const contentLength = Number(response.headers.get('content-length'));
    if (Number.isFinite(contentLength) && contentLength > maximum) {
        throw new CrawlerError(`HTTP body declares ${contentLength} bytes; limit is ${maximum}.`, {
            code: 'response_too_large',
        });
    }

    const chunks = [];
    let bytes = 0;
    for await (const chunk of response.body) {
        const buffer = Buffer.from(chunk);
        bytes += buffer.byteLength;
        if (bytes > maximum) {
            throw new CrawlerError(`HTTP body exceeded its ${maximum}-byte limit.`, {
                code: 'response_too_large',
            });
        }
        chunks.push(buffer);
    }

    return Buffer.concat(chunks, bytes);
}

function boundedGunzip(bytes, maximum, label) {
    try {
        return gunzipSync(bytes, { maxOutputLength: maximum });
    } catch (error) {
        throw new CrawlerError(`${label} could not be expanded within its ${maximum}-byte limit.`, {
            code: 'compressed_payload_invalid_or_too_large',
            cause: error,
        });
    }
}

function decodeText(bytes, contentType) {
    const declared = declaredCharset(contentType) ?? 'utf-8';
    const encoding = ['iso-8859-1', 'latin1', 'windows-1252'].includes(declared)
        ? 'windows-1252'
        : 'utf-8';

    return new TextDecoder(encoding, { fatal: true }).decode(bytes);
}

function declaredCharset(contentType) {
    const match = /charset\s*=\s*["']?([^;"'\s]+)/i.exec(contentType ?? '');

    return match?.[1]?.toLowerCase() ?? null;
}

async function readBoundedFile(path, maximum, label) {
    const metadata = await stat(path);
    if (!metadata.isFile() || metadata.size < 1 || metadata.size > maximum) {
        throw new CrawlerError(`${label} must be a regular file from 1 through ${maximum} bytes.`, {
            code: 'invalid_input_file',
        });
    }

    return readFile(path);
}

async function ensureSafeCrawlerTarget(root, target, label) {
    try {
        return await ensureSafeFileTarget(root, target, { label });
    } catch (error) {
        if (error instanceof CrawlerError) throw error;
        throw new CrawlerError(`${label} path is unsafe.`, {
            code: 'unsafe_output_path',
            cause: error,
            context: { root: resolve(root), path: resolve(target) },
        });
    }
}

async function listSafeJsonFiles(root, directory, label) {
    const sentinel = await ensureSafeCrawlerTarget(
        root,
        join(directory, '.directory-safety-check'),
        label,
    );
    const canonicalDirectory = dirname(sentinel);
    const pending = [canonicalDirectory];
    const files = [];

    while (pending.length > 0) {
        const current = pending.pop();
        for (const entry of await readdir(current, { withFileTypes: true })) {
            const candidate = join(current, entry.name);
            if (entry.isSymbolicLink()) {
                throw new CrawlerError(`${label} contains a symbolic link or junction.`, {
                    code: 'unsafe_output_path',
                    context: { path: candidate },
                });
            }
            if (entry.isDirectory()) {
                let canonicalChild;
                try {
                    canonicalChild = await establishRealDirectory(candidate, { label });
                } catch (error) {
                    throw new CrawlerError(`${label} contains an unsafe directory.`, {
                        code: 'unsafe_output_path',
                        cause: error,
                        context: { path: candidate },
                    });
                }
                if (!isWithin(canonicalDirectory, canonicalChild)) {
                    throw new CrawlerError(`${label} escaped its configured directory.`, {
                        code: 'unsafe_output_path',
                        context: { path: candidate },
                    });
                }
                pending.push(canonicalChild);
                continue;
            }
            if (entry.isFile() && entry.name.endsWith('.json')) files.push(candidate);
        }
    }

    return files;
}

function verifyCompressedCapture(bytes, expectedSha256, maximumExpandedBytes, label) {
    const expanded = boundedGunzip(bytes, maximumExpandedBytes, label);
    const digest = createHash('sha256').update(expanded).digest('hex');
    if (digest !== expectedSha256) {
        throw new CrawlerError(`${label} checksum does not match its content address.`, {
            code: 'capture_checksum_mismatch',
        });
    }

    return expanded.byteLength;
}

async function inspectCaptureFile(
    path,
    expectedSha256,
    maximumCompressedBytes,
    maximumExpandedBytes,
) {
    let metadata;
    try {
        metadata = await lstat(path);
    } catch (error) {
        if (error?.code === 'ENOENT') return { exists: false, valid: false, error: null };
        throw error;
    }
    if (metadata.isSymbolicLink() || !metadata.isFile()) {
        throw new CrawlerError('Raw capture target must be a real regular file.', {
            code: 'unsafe_output_path',
            context: { path },
        });
    }

    try {
        if (metadata.size < 1 || metadata.size > maximumCompressedBytes) {
            throw new CrawlerError('Raw capture compressed size is invalid.', {
                code: 'corrupt_raw_capture',
            });
        }
        const compressed = await readFile(path);
        verifyCompressedCapture(
            compressed,
            expectedSha256,
            maximumExpandedBytes,
            'raw capture',
        );

        return { exists: true, valid: true, error: null };
    } catch (error) {
        return { exists: true, valid: false, error };
    }
}

export async function readVerifiedContentAddressedCapture({
    root,
    path,
    expectedSha256,
    maxExpandedBytes,
}) {
    if (!/^[a-f0-9]{64}$/.test(expectedSha256 ?? '')) {
        throw new TypeError('expectedSha256 must be a lowercase SHA-256 digest');
    }
    if (!Number.isSafeInteger(maxExpandedBytes) || maxExpandedBytes < 1) {
        throw new TypeError('maxExpandedBytes must be a positive integer');
    }

    const target = await ensureSafeCrawlerTarget(root, path, 'raw capture');
    const metadata = await lstat(target);
    const maximumCompressedBytes = Math.min(
        Number.MAX_SAFE_INTEGER,
        maxExpandedBytes + 64 * 1024,
    );
    if (metadata.isSymbolicLink() || !metadata.isFile()
        || metadata.size < 1 || metadata.size > maximumCompressedBytes) {
        throw new CrawlerError('Raw capture is not a safe bounded regular file.', {
            code: 'invalid_input_file',
            context: { path: target },
        });
    }
    const compressed = await readFile(target);
    const expanded = boundedGunzip(compressed, maxExpandedBytes, 'raw capture');
    const digest = createHash('sha256').update(expanded).digest('hex');
    if (digest !== expectedSha256) {
        throw new CrawlerError('Raw capture checksum does not match its reference.', {
            code: 'capture_checksum_mismatch',
        });
    }

    return expanded;
}

/**
 * Publish a content-addressed gzip without ever writing through the final path.
 * A same-directory, fsynced temporary file is verified and atomically linked
 * into place with no-clobber semantics. A truncated artifact left by an older
 * direct-write implementation is quarantined and replaced.
 */
export async function publishContentAddressedCapture({
    root,
    path,
    compressedBytes,
    expectedSha256,
    maxExpandedBytes,
}) {
    if (!/^[a-f0-9]{64}$/.test(expectedSha256 ?? '')) {
        throw new TypeError('expectedSha256 must be a lowercase SHA-256 digest');
    }
    if (!Number.isSafeInteger(maxExpandedBytes) || maxExpandedBytes < 1) {
        throw new TypeError('maxExpandedBytes must be a positive integer');
    }
    const compressed = Buffer.from(compressedBytes);
    const maximumCompressedBytes = Math.max(
        compressed.byteLength * 2,
        maxExpandedBytes + 64 * 1024,
    );
    verifyCompressedCapture(
        compressed,
        expectedSha256,
        maxExpandedBytes,
        'new raw capture',
    );

    const target = await ensureSafeCrawlerTarget(root, path, 'raw capture');
    const initial = await inspectCaptureFile(
        target,
        expectedSha256,
        maximumCompressedBytes,
        maxExpandedBytes,
    );
    if (initial.valid) return { reused: true, recoveredCorrupt: false, path: target };

    const temporary = join(
        dirname(target),
        `.${basename(target)}.${process.pid}.${randomUUID()}.tmp`,
    );
    const quarantined = [];
    let handle;
    let installed = false;
    try {
        handle = await open(temporary, 'wx', 0o600);
        await handle.writeFile(compressed);
        await handle.sync();
        await handle.close();
        handle = undefined;
        verifyCompressedCapture(
            await readFile(temporary),
            expectedSha256,
            maxExpandedBytes,
            'temporary raw capture',
        );

        for (let attempt = 0; attempt < 8; attempt += 1) {
            const existing = await inspectCaptureFile(
                target,
                expectedSha256,
                maximumCompressedBytes,
                maxExpandedBytes,
            );
            if (existing.valid) {
                installed = true;
                return {
                    reused: true,
                    recoveredCorrupt: quarantined.length > 0,
                    path: target,
                };
            }
            if (existing.exists) {
                const quarantine = `${target}.corrupt-${randomUUID()}`;
                try {
                    await rename(target, quarantine);
                    quarantined.push(quarantine);
                } catch (error) {
                    if (error?.code === 'ENOENT') continue;
                    throw new CrawlerError('Unable to quarantine a corrupt raw capture.', {
                        code: 'corrupt_raw_capture',
                        cause: error,
                    });
                }
            }

            try {
                await link(temporary, target);
            } catch (error) {
                if (error?.code === 'EEXIST') continue;
                throw new CrawlerError('Unable to publish raw capture atomically.', {
                    code: 'capture_publish_failed',
                    cause: error,
                });
            }

            const published = await inspectCaptureFile(
                target,
                expectedSha256,
                maximumCompressedBytes,
                maxExpandedBytes,
            );
            if (!published.valid) {
                throw new CrawlerError('Published raw capture failed its checksum verification.', {
                    code: 'capture_publish_failed',
                    cause: published.error,
                });
            }
            installed = true;
            return {
                reused: false,
                recoveredCorrupt: initial.exists || quarantined.length > 0,
                path: target,
            };
        }

        throw new CrawlerError('Raw capture publication remained contended.', {
            code: 'capture_publish_contended',
        });
    } finally {
        await handle?.close().catch(() => {});
        await unlink(temporary).catch(() => {});
        if (installed) {
            await Promise.all(quarantined.map((candidate) => unlink(candidate).catch(() => {})));
        }
    }
}

async function atomicWriteText(path, text) {
    await mkdir(dirname(path), { recursive: true });
    const temporary = join(dirname(path), `.${basename(path)}.${process.pid}.${randomUUID()}.tmp`);
    let handle;
    try {
        handle = await open(temporary, 'wx', 0o600);
        await handle.writeFile(text, 'utf8');
        await handle.sync();
        await handle.close();
        handle = undefined;
        try {
            await rename(temporary, path);
        } catch (error) {
            if (!['EACCES', 'EEXIST', 'EPERM'].includes(error?.code)) throw error;
            const backup = `${path}.bak`;
            try {
                await unlink(backup);
            } catch (unlinkError) {
                if (unlinkError?.code !== 'ENOENT') throw unlinkError;
            }
            try {
                await rename(path, backup);
            } catch (renameError) {
                if (renameError?.code !== 'ENOENT') throw renameError;
            }
            try {
                await rename(temporary, path);
            } catch (replacementError) {
                try {
                    await rename(backup, path);
                } catch {
                    // Both complete files remain recoverable for operator inspection.
                }
                throw replacementError;
            }
            await unlink(backup).catch(() => {});
        }
    } finally {
        await handle?.close().catch(() => {});
        await unlink(temporary).catch(() => {});
    }
}

export async function runCrawlerCli(argv = process.argv.slice(2)) {
    const [command = 'help', ...rest] = argv;
    const { values: args, positionals } = parseArguments(rest);
    if (positionals.length > 0) {
        throw new CrawlerError(`Unexpected positional arguments: ${positionals.join(' ')}`, {
            code: 'invalid_arguments',
        });
    }
    const root = workspacePath(args);

    switch (command) {
        case 'help':
        case '--help':
        case '-h':
            console.log(usage());
            break;
        case 'init':
            await initializeCommand(root, args);
            break;
        case 'start':
            await startCommand(root);
            break;
        case 'run': {
            const config = await loadConfig(root);
            await new AuthorizedLucyCrawler(root, config, createWorkspace(root, config)).run();
            break;
        }
        case 'status':
            await statusCommand(root, args);
            break;
        case 'pause':
        case 'resume':
        case 'stop':
            await controlCommand(root, command, args);
            break;
        case 'retry-failures':
            await retryFailuresCommand(root);
            break;
        case 'sweep':
            await sweepCommand(root);
            break;
        default:
            throw new CrawlerError(`Unknown command: ${command}\n\n${usage()}`, {
                code: 'unknown_command',
            });
    }
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === resolve(SCRIPT_PATH)) {
    runCrawlerCli().catch((error) => {
        console.error(`${error.name ?? 'Error'}${error.code ? ` [${error.code}]` : ''}: ${error.message}`);
        if (process.env.LUCY_ITEM_CRAWL_BACKGROUND !== '1' && error.cause?.message) {
            console.error(`Caused by: ${error.cause.message}`);
        }
        process.exitCode = 1;
    });
}
