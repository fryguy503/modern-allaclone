import { randomUUID } from 'node:crypto';
import {
    lstat,
    mkdir,
    open,
    readFile,
    realpath,
    rename,
    rm,
} from 'node:fs/promises';
import { hostname as readHostname } from 'node:os';
import {
    basename,
    dirname,
    isAbsolute,
    join,
    parse,
    relative,
    resolve,
    sep,
} from 'node:path';

const STATUS_SCHEMA = 'modern-allaclone.lucy-item-crawl-status';
const CONTROL_SCHEMA = 'modern-allaclone.lucy-item-crawl-control';
const RATE_SCHEMA = 'modern-allaclone.lucy-item-crawl-rate';
const LOCK_SCHEMA = 'modern-allaclone.lucy-item-crawl-lock';
const STATE_VERSION = 1;
const DEFAULT_MAX_STATE_BYTES = 4 * 1024 * 1024;
const DEFAULT_MIN_INTERVAL_MS = 20_000;
const DEFAULT_JITTER_MS = 5_000;
const DEFAULT_DAILY_CAP = 3_500;
const DEFAULT_LOCK_STALE_MS = 120_000;
const DEFAULT_HEARTBEAT_INTERVAL_MS = 15_000;
const DEFAULT_WAIT_POLL_MS = 1_000;

const RETRYABLE_RENAME_CODES = new Set(['EACCES', 'EEXIST', 'ENOTEMPTY', 'EPERM']);
const CONTROL_COMMANDS = new Set(['run', 'pause', 'stop']);

export class CrawlStateError extends Error {
    constructor(message, options = undefined) {
        super(message, options);
        this.name = 'CrawlStateError';
    }
}

/**
 * Create a directory one component at a time while rejecting symbolic links
 * and Windows junctions anywhere in its absolute path. The returned path is
 * the filesystem's canonical spelling of the directory.
 */
export async function establishRealDirectory(path, {
    label = 'directory',
} = {}) {
    const target = resolve(path);
    const anchor = parse(target).root;
    let cursor = anchor;

    await assertRealDirectory(cursor, label);
    for (const segment of relative(anchor, target).split(sep).filter(Boolean)) {
        cursor = join(cursor, segment);
        try {
            await mkdir(cursor);
        } catch (error) {
            if (error?.code !== 'EEXIST') throw error;
        }
        await assertRealDirectory(cursor, label);
    }

    return realpath(target);
}

/**
 * Establish and validate a file's parent beneath a trusted real directory.
 * The target and its atomic-replacement backup may be absent, but may never be
 * links or non-files. This prevents a junction in a state/work shard from
 * redirecting a publication outside the crawl workspace.
 */
export async function ensureSafeFileTarget(root, target, {
    label = 'file',
} = {}) {
    const normalizedRoot = resolve(root);
    const normalizedTarget = resolve(target);
    if (!isPathWithin(normalizedRoot, normalizedTarget)
        || samePath(normalizedRoot, normalizedTarget)) {
        throw new CrawlStateError(`${label} path escaped its configured root: ${normalizedTarget}`);
    }

    const canonicalRoot = await establishRealDirectory(normalizedRoot, {
        label: `${label} root`,
    });
    const canonicalParent = await establishRealDirectory(dirname(normalizedTarget), {
        label: `${label} parent`,
    });
    if (!isPathWithin(canonicalRoot, canonicalParent)) {
        throw new CrawlStateError(
            `${label} parent resolves outside its configured root: ${normalizedTarget}`,
        );
    }

    const canonicalTarget = join(canonicalParent, basename(normalizedTarget));
    for (const candidate of [canonicalTarget, `${canonicalTarget}.bak`]) {
        try {
            const metadata = await lstat(candidate);
            if (metadata.isSymbolicLink() || !metadata.isFile()) {
                throw new CrawlStateError(`${label} target must be a regular file: ${candidate}`);
            }
        } catch (error) {
            if (error?.code !== 'ENOENT') throw error;
        }
    }

    return canonicalTarget;
}

/**
 * Write JSON through a same-directory temporary file. On Windows, where a
 * rename cannot replace an open destination, a recoverable .bak handoff keeps
 * at least one complete copy available to readAtomicJson().
 */
export async function atomicWriteJson(path, value, {
    maxBytes = DEFAULT_MAX_STATE_BYTES,
    root = null,
    label = 'JSON state',
} = {}) {
    const target = root === null
        ? resolve(path)
        : await ensureSafeFileTarget(root, path, { label });
    const directory = dirname(target);
    const backup = `${target}.bak`;
    const temporary = join(
        directory,
        `.${basename(target)}.${process.pid}.${randomUUID()}.tmp`,
    );
    const json = encodeJson(value, maxBytes, target);

    if (root === null) await mkdir(directory, { recursive: true });
    await rejectSymbolicLink(target, 'JSON state target');
    await rejectSymbolicLink(backup, 'JSON state backup');
    await recoverBackupIfNecessary(target, backup, maxBytes);

    let handle;
    try {
        handle = await open(temporary, 'wx', 0o600);
        await handle.writeFile(json, 'utf8');
        await handle.sync();
        await handle.close();
        handle = undefined;

        try {
            await rename(temporary, target);
        } catch (error) {
            if (!RETRYABLE_RENAME_CODES.has(error?.code) || !await isRegularFile(target)) {
                throw error;
            }

            // A previous successful handoff may have left a backup behind if
            // the process was terminated just before cleanup. The target is
            // the newer complete copy in that situation.
            if (await pathExists(backup)) {
                await rm(backup, { force: true });
            }

            await rename(target, backup);
            try {
                await rename(temporary, target);
            } catch (replacementError) {
                try {
                    if (!await pathExists(target) && await pathExists(backup)) {
                        await rename(backup, target);
                    }
                } catch (restoreError) {
                    throw new CrawlStateError(
                        `Unable to replace or restore JSON state file: ${target}`,
                        { cause: new AggregateError([replacementError, restoreError]) },
                    );
                }

                throw replacementError;
            }

            await rm(backup, { force: true });
        }
    } finally {
        if (handle !== undefined) {
            await handle.close().catch(() => {});
        }
        await rm(temporary, { force: true }).catch(() => {});
    }

    return Buffer.byteLength(json);
}

/**
 * Read an atomically-published JSON value, falling back to the recovery copy
 * left by an interrupted Windows replacement.
 */
export async function readAtomicJson(path, {
    allowMissing = false,
    maxBytes = DEFAULT_MAX_STATE_BYTES,
    root = null,
    label = 'JSON state',
} = {}) {
    const target = root === null
        ? resolve(path)
        : await ensureSafeFileTarget(root, path, { label });
    const candidates = [target, `${target}.bak`];
    const errors = [];
    let found = false;

    for (const candidate of candidates) {
        try {
            const metadata = await lstat(candidate);
            found = true;
            if (metadata.isSymbolicLink() || !metadata.isFile()) {
                throw new CrawlStateError(`Unsafe JSON state file: ${candidate}`);
            }
            if (metadata.size < 2 || metadata.size > maxBytes) {
                throw new CrawlStateError(`Invalid JSON state size for ${candidate}`);
            }

            return JSON.parse(await readFile(candidate, 'utf8'));
        } catch (error) {
            if (error?.code === 'ENOENT') {
                continue;
            }
            errors.push(error);
        }
    }

    if (!found && allowMissing) {
        return null;
    }
    if (errors.length > 0) {
        throw new CrawlStateError(`Unable to read valid JSON state: ${target}`, {
            cause: errors.length === 1 ? errors[0] : new AggregateError(errors),
        });
    }

    throw new CrawlStateError(`Missing JSON state file: ${target}`);
}

/**
 * Parse Retry-After as either delta seconds or an HTTP date. The result is a
 * non-negative delay in milliseconds; invalid values return null.
 */
export function parseRetryAfter(value, nowMs = Date.now()) {
    if (Array.isArray(value)) {
        [value] = value;
    }
    if (typeof value !== 'string' && typeof value !== 'number') {
        return null;
    }

    const normalized = String(value).trim();
    if (/^\d+$/.test(normalized)) {
        const seconds = Number(normalized);
        if (!Number.isSafeInteger(seconds) || seconds < 0
            || seconds > Math.floor(Number.MAX_SAFE_INTEGER / 1_000)) {
            return null;
        }

        return seconds * 1_000;
    }

    const timestamp = Date.parse(normalized);
    if (!Number.isFinite(timestamp)) {
        return null;
    }

    return Math.max(0, timestamp - normalizeTimestamp(nowMs, 'nowMs'));
}

/**
 * Calculate a one-based exponential retry delay with additive jitter. A valid
 * Retry-After value is always honored even when it exceeds maxMs.
 */
export function calculateBackoff({
    attempt,
    baseMs = 60_000,
    maxMs = 3_600_000,
    jitterMs = 5_000,
    retryAfter = null,
    nowMs = Date.now(),
    random = Math.random,
} = {}) {
    assertPositiveInteger(attempt, 'attempt');
    assertNonNegativeInteger(baseMs, 'baseMs');
    assertNonNegativeInteger(maxMs, 'maxMs');
    assertNonNegativeInteger(jitterMs, 'jitterMs');
    if (maxMs < baseMs) {
        throw new TypeError('maxMs must be greater than or equal to baseMs');
    }

    const exponent = Math.min(attempt - 1, 52);
    const exponential = Math.min(maxMs, baseMs * (2 ** exponent));
    const jitter = randomInteger(jitterMs, random);
    const localDelay = Math.min(maxMs, exponential + jitter);
    const serverDelay = retryAfter === null
        ? null
        : parseRetryAfter(retryAfter, nowMs);

    return Math.max(localDelay, serverDelay ?? 0);
}

export class CrawlWorkspace {
    #clock;
    #sleep;
    #random;
    #isProcessAlive;
    #stateQueue = Promise.resolve();
    #rateQueue = Promise.resolve();
    #lockToken = null;
    #lockOwner = null;
    #heartbeatTimer = null;
    #heartbeatInFlight = null;
    #heartbeatError = null;
    #lastHeartbeatMs = null;

    constructor(root, {
        clock = Date.now,
        sleep = abortableSleep,
        random = Math.random,
        isProcessAlive = processIsAlive,
        minIntervalMs = DEFAULT_MIN_INTERVAL_MS,
        jitterMs = DEFAULT_JITTER_MS,
        dailyCap = DEFAULT_DAILY_CAP,
        accessDeniedCooldownMs = 0,
        lockStaleMs = DEFAULT_LOCK_STALE_MS,
        heartbeatIntervalMs = DEFAULT_HEARTBEAT_INTERVAL_MS,
        waitPollMs = DEFAULT_WAIT_POLL_MS,
        hostname = readHostname(),
        pid = process.pid,
        maxStateBytes = DEFAULT_MAX_STATE_BYTES,
    } = {}) {
        if (typeof root !== 'string' || root.trim() === '') {
            throw new TypeError('Crawl workspace root must be a non-empty path');
        }
        if (typeof clock !== 'function' || typeof sleep !== 'function'
            || typeof random !== 'function' || typeof isProcessAlive !== 'function') {
            throw new TypeError('clock, sleep, random, and isProcessAlive must be functions');
        }
        assertNonNegativeInteger(minIntervalMs, 'minIntervalMs');
        assertNonNegativeInteger(jitterMs, 'jitterMs');
        assertNonNegativeInteger(accessDeniedCooldownMs, 'accessDeniedCooldownMs');
        assertPositiveInteger(dailyCap, 'dailyCap');
        assertPositiveInteger(lockStaleMs, 'lockStaleMs');
        assertPositiveInteger(heartbeatIntervalMs, 'heartbeatIntervalMs');
        assertPositiveInteger(waitPollMs, 'waitPollMs');
        assertPositiveInteger(maxStateBytes, 'maxStateBytes');
        assertPositiveInteger(pid, 'pid');
        if (typeof hostname !== 'string' || hostname.trim() === '') {
            throw new TypeError('hostname must be a non-empty string');
        }

        this.root = resolve(root);
        this.stateDirectory = join(this.root, 'state');
        this.statusPath = join(this.stateDirectory, 'status.json');
        this.controlPath = join(this.stateDirectory, 'control.json');
        this.ratePath = join(this.stateDirectory, 'rate.json');
        this.lockPath = join(this.stateDirectory, 'crawler.lock');
        this.lockOwnerPath = join(this.lockPath, 'owner.json');
        this.minIntervalMs = minIntervalMs;
        this.jitterMs = jitterMs;
        this.dailyCap = dailyCap;
        this.accessDeniedCooldownMs = accessDeniedCooldownMs;
        this.lockStaleMs = lockStaleMs;
        this.heartbeatIntervalMs = heartbeatIntervalMs;
        this.waitPollMs = waitPollMs;
        this.hostname = hostname.trim();
        this.pid = pid;
        this.maxStateBytes = maxStateBytes;
        this.#clock = clock;
        this.#sleep = sleep;
        this.#random = random;
        this.#isProcessAlive = isProcessAlive;
    }

    async initialize() {
        await establishRealDirectory(this.root, { label: 'crawl workspace root' });
        await establishRealDirectory(this.stateDirectory, { label: 'crawl state directory' });

        return this;
    }

    async acquireLock({
        runId = randomUUID(),
        recoverStale = true,
        staleAfterMs = this.lockStaleMs,
    } = {}) {
        if (this.#lockToken !== null) {
            return { acquired: true, owner: { ...this.#lockOwner }, reason: 'already-owned' };
        }
        assertPositiveInteger(staleAfterMs, 'staleAfterMs');
        await this.initialize();

        for (let attempt = 0; attempt < 5; attempt += 1) {
            try {
                await mkdir(this.lockPath);
            } catch (error) {
                if (error?.code !== 'EEXIST') {
                    throw error;
                }

                const existing = await this.#readLockOwner();
                if (existing === null) {
                    let lockMetadata;
                    try {
                        lockMetadata = await lstat(this.lockPath);
                    } catch (metadataError) {
                        if (metadataError?.code === 'ENOENT') continue;
                        throw metadataError;
                    }
                    if (lockMetadata.isSymbolicLink() || !lockMetadata.isDirectory()) {
                        return { acquired: false, owner: null, reason: 'invalid-lock' };
                    }
                    const ownerlessAge = this.#now() - lockMetadata.mtimeMs;
                    if (!recoverStale || !Number.isFinite(ownerlessAge) || ownerlessAge <= staleAfterMs) {
                        return {
                            acquired: false,
                            owner: null,
                            reason: recoverStale ? 'lock-initializing' : 'invalid-lock',
                        };
                    }

                    const ownerlessTombstone = join(
                        this.stateDirectory,
                        `.crawler.lock.ownerless-${randomUUID()}`,
                    );
                    try {
                        await rename(this.lockPath, ownerlessTombstone);
                    } catch (renameError) {
                        if (renameError?.code === 'ENOENT') continue;
                        if (renameError?.code === 'EACCES' || renameError?.code === 'EPERM') {
                            return { acquired: false, owner: null, reason: 'ownerless-lock-busy' };
                        }
                        throw renameError;
                    }
                    await rm(ownerlessTombstone, { recursive: true, force: true });
                    continue;
                }
                const heartbeatMs = Date.parse(existing.heartbeatAt);
                const stale = Number.isFinite(heartbeatMs)
                    && this.#now() - heartbeatMs > staleAfterMs;
                if (!stale) {
                    return { acquired: false, owner: existing, reason: 'active' };
                }
                if (!recoverStale) {
                    return { acquired: false, owner: existing, reason: 'stale' };
                }
                if (existing.hostname !== this.hostname) {
                    return { acquired: false, owner: existing, reason: 'stale-remote-host' };
                }
                if (this.#isProcessAlive(existing.pid)) {
                    return { acquired: false, owner: existing, reason: 'stale-heartbeat-live-process' };
                }

                const tombstone = join(
                    this.stateDirectory,
                    `.crawler.lock.stale-${randomUUID()}`,
                );
                try {
                    await rename(this.lockPath, tombstone);
                } catch (renameError) {
                    if (renameError?.code === 'ENOENT') {
                        continue;
                    }
                    if (renameError?.code === 'EACCES' || renameError?.code === 'EPERM') {
                        return { acquired: false, owner: existing, reason: 'stale-lock-busy' };
                    }
                    throw renameError;
                }
                await rm(tombstone, { recursive: true, force: true });
                continue;
            }

            const now = this.#now();
            const owner = {
                schema: LOCK_SCHEMA,
                version: STATE_VERSION,
                token: randomUUID(),
                runId: String(runId),
                pid: this.pid,
                hostname: this.hostname,
                acquiredAt: toIso(now),
                heartbeatAt: toIso(now),
            };
            try {
                await atomicWriteJson(this.lockOwnerPath, owner, {
                    maxBytes: this.maxStateBytes,
                    root: this.root,
                    label: 'crawl lock owner',
                });
            } catch (error) {
                await rm(this.lockPath, { recursive: true, force: true }).catch(() => {});
                throw error;
            }

            this.#lockToken = owner.token;
            this.#lockOwner = owner;
            this.#lastHeartbeatMs = now;
            this.#startHeartbeatTimer();
            try {
                await this.updateStatus({
                    pid: this.pid,
                    hostname: this.hostname,
                    runId: owner.runId,
                    heartbeatAt: owner.heartbeatAt,
                });
            } catch (error) {
                await this.releaseLock().catch(() => {});
                throw error;
            }

            return { acquired: true, owner: { ...owner } };
        }

        return { acquired: false, owner: await this.#readLockOwner(), reason: 'contended' };
    }

    async releaseLock() {
        this.#stopHeartbeatTimer();
        if (this.#heartbeatInFlight !== null) {
            await this.#heartbeatInFlight.catch(() => {});
        }
        if (this.#lockToken === null) {
            return false;
        }

        const owner = await this.#readLockOwner();
        if (owner === null || owner.token !== this.#lockToken) {
            throw new CrawlStateError('Refusing to release a crawl lock owned by another process');
        }

        const tombstone = join(
            this.stateDirectory,
            `.crawler.lock.release-${this.#lockToken}`,
        );
        await rename(this.lockPath, tombstone);
        this.#lockToken = null;
        this.#lockOwner = null;
        this.#lastHeartbeatMs = null;
        this.#heartbeatError = null;
        await rm(tombstone, { recursive: true, force: true });

        return true;
    }

    async heartbeat() {
        this.#assertLockOwned();
        if (this.#heartbeatInFlight !== null) {
            return this.#heartbeatInFlight;
        }

        this.#heartbeatInFlight = this.#refreshHeartbeat()
            .catch((error) => {
                this.#heartbeatError = error;
                throw error;
            })
            .finally(() => {
                this.#heartbeatInFlight = null;
            });

        return this.#heartbeatInFlight;
    }

    async readLockOwner() {
        await this.initialize();
        return this.#readLockOwner();
    }

    async readStatus() {
        await this.initialize();
        const status = await readAtomicJson(this.statusPath, {
            allowMissing: true,
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl status',
        });
        if (status === null) {
            return defaultStatus(this.#now());
        }
        validateStateEnvelope(status, STATUS_SCHEMA, 'crawl status');

        return status;
    }

    async updateStatus(patch) {
        if (!isPlainObject(patch)) {
            throw new TypeError('Status patch must be a plain object');
        }

        return this.#enqueueState(async () => {
            await this.initialize();
            const existing = await readAtomicJson(this.statusPath, {
                allowMissing: true,
                maxBytes: this.maxStateBytes,
                root: this.root,
                label: 'crawl status',
            }) ?? defaultStatus(this.#now());
            validateStateEnvelope(existing, STATUS_SCHEMA, 'crawl status');

            const updated = deepMerge(existing, patch);
            updated.schema = STATUS_SCHEMA;
            updated.version = STATE_VERSION;
            updated.updatedAt = toIso(this.#now());
            await atomicWriteJson(this.statusPath, updated, {
                maxBytes: this.maxStateBytes,
                root: this.root,
                label: 'crawl status',
            });

            return updated;
        });
    }

    async readControl() {
        await this.initialize();
        const control = await readAtomicJson(this.controlPath, {
            allowMissing: true,
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl control',
        });
        if (control === null) {
            return defaultControl();
        }
        validateControl(control);

        return control;
    }

    async setControl(command, {
        reason = null,
        requestedBy = null,
    } = {}) {
        const normalized = command === 'resume' ? 'run' : command;
        if (!CONTROL_COMMANDS.has(normalized)) {
            throw new TypeError("Control command must be 'run', 'resume', 'pause', or 'stop'");
        }
        assertNullableShortString(reason, 'reason', 2_000);
        assertNullableShortString(requestedBy, 'requestedBy', 256);
        await this.initialize();

        const control = {
            schema: CONTROL_SCHEMA,
            version: STATE_VERSION,
            command: normalized,
            reason,
            requestedBy,
            requestedAt: toIso(this.#now()),
        };
        await atomicWriteJson(this.controlPath, control, {
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl control',
        });

        return control;
    }

    /**
     * Wait until both the global start-to-start gate and the UTC daily budget
     * allow a request. The reservation is persisted before granted=true is
     * returned, so a crash can only make the crawler more conservative.
     */
    async waitForRateSlot({
        signal = undefined,
        onWait = undefined,
        maxWaitMs = Infinity,
    } = {}) {
        this.#assertLockHealthy();
        if (onWait !== undefined && typeof onWait !== 'function') {
            throw new TypeError('onWait must be a function');
        }
        if (maxWaitMs !== Infinity) {
            assertNonNegativeInteger(maxWaitMs, 'maxWaitMs');
        }

        const waitStartedAt = this.#now();
        let reportedWaitKey = null;

        while (true) {
            this.#assertLockHealthy();
            throwIfAborted(signal);

            const control = await this.readControl();
            if (control.command !== 'run') {
                const reason = control.command === 'pause' ? 'paused' : 'stopped';
                await this.updateStatus({
                    state: reason,
                    control,
                    heartbeatAt: toIso(this.#now()),
                });

                return { granted: false, reason, control };
            }

            const outcome = await this.#enqueueRate(async () => {
                const now = this.#now();
                const state = await this.#readRateState(now);
                const day = utcDayKey(now);
                if (state.day < day) {
                    state.day = day;
                    state.requestsToday = 0;
                    state.dailyResetAt = toIso(nextUtcDayStart(now));
                }

                const intervalGate = parseOptionalIso(state.nextRequestNotBefore)
                    ?? now;
                const persistedDailyReset = parseOptionalIso(state.dailyResetAt)
                    ?? nextUtcDayStart(now);
                const dailyGate = state.requestsToday >= this.dailyCap
                    ? (persistedDailyReset > now
                        ? persistedDailyReset
                        : nextUtcDayStart(now))
                    : now;
                const allowedAt = Math.max(now, intervalGate, dailyGate);
                if (allowedAt > now) {
                    return {
                        granted: false,
                        now,
                        allowedAt,
                        reason: dailyGate > now && dailyGate >= intervalGate
                            ? 'daily-cap'
                            : 'rate-limit',
                        state,
                    };
                }

                const jitter = randomInteger(this.jitterMs, this.#random);
                // Never move a persisted UTC budget window backwards if the
                // machine clock is corrected across midnight.
                state.day = state.day > day ? state.day : day;
                state.requestsToday += 1;
                state.dailyCap = this.dailyCap;
                state.lastRequestStartedAt = toIso(now);
                state.nextRequestNotBefore = toIso(now + this.minIntervalMs + jitter);
                state.dailyResetAt = state.day === day
                    ? toIso(nextUtcDayStart(now))
                    : state.dailyResetAt;
                state.updatedAt = toIso(now);
                state.deferReason = null;
                await this.#writeRateState(state);

                return { granted: true, now, jitter, state };
            });

            if (outcome.granted) {
                const result = {
                    granted: true,
                    startedAt: toIso(outcome.now),
                    nextRequestNotBefore: outcome.state.nextRequestNotBefore,
                    jitterMs: outcome.jitter,
                    requestsToday: outcome.state.requestsToday,
                    dailyCap: this.dailyCap,
                    dailyResetAt: outcome.state.dailyResetAt,
                };
                const status = await this.readStatus();
                const metrics = normalizedMetrics(status.metrics);
                metrics.requestStarts += 1;
                await this.updateStatus({
                    state: 'running',
                    heartbeatAt: toIso(outcome.now),
                    rate: rateStatus(outcome.state, this),
                    metrics,
                    lastRequestStartedAt: result.startedAt,
                });

                return result;
            }

            const elapsed = outcome.now - waitStartedAt;
            if (elapsed >= maxWaitMs) {
                return {
                    granted: false,
                    reason: 'timeout',
                    blockedBy: outcome.reason,
                    nextRequestNotBefore: toIso(outcome.allowedAt),
                    requestsToday: outcome.state.requestsToday,
                    dailyCap: this.dailyCap,
                };
            }

            const waitKey = `${outcome.reason}:${outcome.allowedAt}`;
            if (waitKey !== reportedWaitKey) {
                reportedWaitKey = waitKey;
                const waitInfo = {
                    reason: outcome.reason,
                    now: toIso(outcome.now),
                    until: toIso(outcome.allowedAt),
                    waitMs: outcome.allowedAt - outcome.now,
                    requestsToday: outcome.state.requestsToday,
                    dailyCap: this.dailyCap,
                };
                await this.updateStatus({
                    state: outcome.reason,
                    heartbeatAt: toIso(outcome.now),
                    rate: rateStatus(outcome.state, this, outcome.allowedAt),
                });
                await onWait?.(waitInfo);
            }

            const remainingBudget = maxWaitMs === Infinity
                ? Infinity
                : Math.max(0, maxWaitMs - elapsed);
            const sleepMs = Math.min(
                outcome.allowedAt - outcome.now,
                this.waitPollMs,
                remainingBudget,
            );
            if (sleepMs <= 0) {
                continue;
            }
            await this.#sleep(sleepMs, { signal });
            if (this.#now() - this.#lastHeartbeatMs >= this.heartbeatIntervalMs / 2) {
                await this.heartbeat();
            }
        }
    }

    /**
     * Record the result of a previously-reserved request. This never consumes
     * another daily-budget slot.
     */
    async recordRequest(result = {}) {
        this.#assertLockHealthy();
        if (!isPlainObject(result)) {
            throw new TypeError('Request result must be a plain object');
        }

        const now = this.#now();
        const statusCode = result.statusCode ?? null;
        if (statusCode !== null
            && (!Number.isInteger(statusCode) || statusCode < 100 || statusCode > 599)) {
            throw new TypeError('statusCode must be null or an HTTP status integer');
        }
        const durationMs = result.durationMs ?? null;
        const bytes = result.bytes ?? null;
        if (durationMs !== null) assertNonNegativeInteger(durationMs, 'durationMs');
        if (bytes !== null) assertNonNegativeInteger(bytes, 'bytes');
        const deferForMs = result.deferForMs ?? null;
        if (deferForMs !== null) assertNonNegativeInteger(deferForMs, 'deferForMs');
        if (deferForMs !== null && result.notBefore !== undefined && result.notBefore !== null) {
            throw new TypeError('deferForMs and notBefore cannot both be provided');
        }
        const error = normalizeError(result.error);
        const success = result.success ?? (
            error === null && statusCode !== null && statusCode >= 200 && statusCode < 400
        );
        if (typeof success !== 'boolean') {
            throw new TypeError('success must be a boolean');
        }

        const lastResult = {
            finishedAt: toIso(now),
            success,
            statusCode,
            durationMs,
            bytes,
            url: typeof result.url === 'string' ? result.url : null,
            error,
        };

        const rate = await this.#enqueueRate(async () => {
            const state = await this.#readRateState(now);
            state.lastRequestCompletedAt = lastResult.finishedAt;
            state.lastResult = lastResult;
            state.consecutiveFailures = success
                ? 0
                : (state.consecutiveFailures ?? 0) + 1;
            if (statusCode === 403) {
                state.consecutiveAccessDenials = (state.consecutiveAccessDenials ?? 0) + 1;
            }
            if (deferForMs !== null
                || (result.notBefore !== undefined && result.notBefore !== null)) {
                const requested = deferForMs === null
                    ? normalizeTimestamp(result.notBefore, 'notBefore')
                    : now + deferForMs;
                const current = parseOptionalIso(state.nextRequestNotBefore) ?? 0;
                if (requested > current) {
                    state.nextRequestNotBefore = toIso(requested);
                    state.deferReason = typeof result.deferReason === 'string'
                        ? result.deferReason
                        : 'request-result';
                }
            }
            state.updatedAt = toIso(now);
            await this.#writeRateState(state);

            return state;
        });

        const status = await this.readStatus();
        const metrics = normalizedMetrics(status.metrics);
        metrics.requestResults += 1;
        metrics.responseBytes += bytes ?? 0;
        metrics[classifyResult(statusCode, error, success)] += 1;
        await this.updateStatus({
            heartbeatAt: toIso(now),
            rate: rateStatus(rate, this),
            metrics,
            lastRequest: lastResult,
        });

        return {
            ...lastResult,
            consecutiveFailures: rate.consecutiveFailures,
            consecutiveAccessDenials: rate.consecutiveAccessDenials,
        };
    }

    /**
     * Clear the durable HTTP 403 streak only after the caller has accepted and
     * stored a valid destination response.
     */
    async clearAccessDenials() {
        this.#assertLockHealthy();
        const now = this.#now();
        const state = await this.#enqueueRate(async () => {
            const current = await this.#readRateState(now);
            if (current.consecutiveAccessDenials !== 0) {
                current.consecutiveAccessDenials = 0;
                current.updatedAt = toIso(now);
                await this.#writeRateState(current);
            }

            return current;
        });
        await this.updateStatus({
            heartbeatAt: toIso(now),
            rate: rateStatus(state, this),
        });

        return state.consecutiveAccessDenials;
    }

    async deferRequestsUntil(timestamp, { reason = 'backoff' } = {}) {
        this.#assertLockHealthy();
        assertNullableShortString(reason, 'reason', 512);
        const requested = normalizeTimestamp(timestamp, 'timestamp');
        const now = this.#now();

        const state = await this.#enqueueRate(async () => {
            const current = await this.#readRateState(now);
            const currentGate = parseOptionalIso(current.nextRequestNotBefore) ?? 0;
            if (requested > currentGate) {
                current.nextRequestNotBefore = toIso(requested);
                current.deferReason = reason;
                current.updatedAt = toIso(now);
                await this.#writeRateState(current);
            }

            return current;
        });
        await this.updateStatus({
            heartbeatAt: toIso(now),
            rate: rateStatus(state, this),
        });

        return state.nextRequestNotBefore;
    }

    async #readRateState(now) {
        const state = await readAtomicJson(this.ratePath, {
            allowMissing: true,
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl rate state',
        }) ?? defaultRateState(now, this.dailyCap);
        const legacyAccessDenial = state.consecutiveAccessDenials === undefined
            && state.lastResult?.statusCode === 403;
        state.consecutiveAccessDenials ??= legacyAccessDenial ? 1 : 0;
        if (legacyAccessDenial && this.accessDeniedCooldownMs > 0) {
            const finishedAt = parseOptionalIso(state.lastResult.finishedAt);
            const currentGate = parseOptionalIso(state.nextRequestNotBefore) ?? 0;
            const migratedGate = finishedAt === null
                ? 0
                : finishedAt + this.accessDeniedCooldownMs;
            if (migratedGate > currentGate) {
                state.nextRequestNotBefore = toIso(migratedGate);
                state.deferReason = 'migrated-http-403-cooldown';
            }
        }
        validateRateState(state);

        return state;
    }

    async #writeRateState(state) {
        validateRateState(state);
        await atomicWriteJson(this.ratePath, state, {
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl rate state',
        });
    }

    async #readLockOwner() {
        let metadata;
        try {
            metadata = await lstat(this.lockPath);
        } catch (error) {
            if (error?.code === 'ENOENT') return null;
            throw error;
        }
        if (metadata.isSymbolicLink() || !metadata.isDirectory()) {
            return null;
        }

        try {
            const owner = await readAtomicJson(this.lockOwnerPath, {
                allowMissing: true,
                maxBytes: this.maxStateBytes,
                root: this.root,
                label: 'crawl lock owner',
            });
            if (owner === null) return null;
            validateLockOwner(owner);

            return owner;
        } catch (error) {
            if (error instanceof CrawlStateError) return null;
            throw error;
        }
    }

    async #refreshHeartbeat() {
        this.#assertLockOwned();
        const owner = await this.#readLockOwner();
        if (owner === null || owner.token !== this.#lockToken) {
            throw new CrawlStateError('Crawl lock ownership was lost');
        }

        const now = this.#now();
        owner.heartbeatAt = toIso(now);
        await atomicWriteJson(this.lockOwnerPath, owner, {
            maxBytes: this.maxStateBytes,
            root: this.root,
            label: 'crawl lock owner',
        });
        this.#lockOwner = owner;
        this.#lastHeartbeatMs = now;
        this.#heartbeatError = null;
        await this.updateStatus({
            heartbeatAt: owner.heartbeatAt,
            pid: owner.pid,
            hostname: owner.hostname,
            runId: owner.runId,
        });

        return { ...owner };
    }

    #startHeartbeatTimer() {
        this.#stopHeartbeatTimer();
        this.#heartbeatTimer = setInterval(() => {
            if (this.#heartbeatInFlight !== null || this.#lockToken === null) return;
            this.heartbeat().catch((error) => {
                this.#heartbeatError = error;
            });
        }, this.heartbeatIntervalMs);
        this.#heartbeatTimer.unref?.();
    }

    #stopHeartbeatTimer() {
        if (this.#heartbeatTimer !== null) {
            clearInterval(this.#heartbeatTimer);
            this.#heartbeatTimer = null;
        }
    }

    #assertLockOwned() {
        if (this.#lockToken === null) {
            throw new CrawlStateError('The crawl workspace lock is not owned by this process');
        }
    }

    #assertLockHealthy() {
        this.#assertLockOwned();
        if (this.#heartbeatError !== null) {
            throw new CrawlStateError('The crawl lock heartbeat failed; refusing more requests', {
                cause: this.#heartbeatError,
            });
        }
    }

    #now() {
        return normalizeTimestamp(this.#clock(), 'clock result');
    }

    #enqueueState(operation) {
        const result = this.#stateQueue.then(operation, operation);
        this.#stateQueue = result.catch(() => {});

        return result;
    }

    #enqueueRate(operation) {
        const result = this.#rateQueue.then(operation, operation);
        this.#rateQueue = result.catch(() => {});

        return result;
    }
}

function defaultStatus(now) {
    return {
        schema: STATUS_SCHEMA,
        version: STATE_VERSION,
        state: 'idle',
        updatedAt: toIso(now),
        heartbeatAt: null,
        metrics: normalizedMetrics(),
    };
}

function defaultControl() {
    return {
        schema: CONTROL_SCHEMA,
        version: STATE_VERSION,
        command: 'run',
        reason: null,
        requestedBy: null,
        requestedAt: null,
    };
}

function defaultRateState(now, dailyCap) {
    return {
        schema: RATE_SCHEMA,
        version: STATE_VERSION,
        day: utcDayKey(now),
        requestsToday: 0,
        dailyCap,
        dailyResetAt: toIso(nextUtcDayStart(now)),
        lastRequestStartedAt: null,
        lastRequestCompletedAt: null,
        nextRequestNotBefore: null,
        deferReason: null,
        consecutiveFailures: 0,
        consecutiveAccessDenials: 0,
        lastResult: null,
        updatedAt: toIso(now),
    };
}

function normalizedMetrics(value = {}) {
    const source = isPlainObject(value) ? value : {};
    const fields = [
        'requestStarts',
        'requestResults',
        'successful',
        'notFound',
        'rateLimited',
        'clientErrors',
        'serverErrors',
        'networkErrors',
        'otherErrors',
        'responseBytes',
    ];
    const result = {};
    for (const field of fields) {
        result[field] = Number.isSafeInteger(source[field]) && source[field] >= 0
            ? source[field]
            : 0;
    }

    return result;
}

function classifyResult(statusCode, error, success) {
    if (success) return 'successful';
    if (error !== null && statusCode === null) return 'networkErrors';
    if (statusCode === 404 || statusCode === 410) return 'notFound';
    if (statusCode === 429) return 'rateLimited';
    if (statusCode !== null && statusCode >= 500) return 'serverErrors';
    if (statusCode !== null && statusCode >= 400) return 'clientErrors';

    return 'otherErrors';
}

function rateStatus(state, workspace, allowedAt = undefined) {
    return {
        minIntervalMs: workspace.minIntervalMs,
        jitterMs: workspace.jitterMs,
        requestsToday: state.requestsToday,
        dailyCap: workspace.dailyCap,
        dailyResetAt: state.dailyResetAt,
        lastRequestStartedAt: state.lastRequestStartedAt,
        lastRequestCompletedAt: state.lastRequestCompletedAt,
        nextRequestNotBefore: allowedAt === undefined
            ? state.nextRequestNotBefore
            : toIso(allowedAt),
        deferReason: state.deferReason,
        consecutiveFailures: state.consecutiveFailures,
        consecutiveAccessDenials: state.consecutiveAccessDenials,
    };
}

function validateStateEnvelope(value, schema, label) {
    if (!isPlainObject(value)
        || value.schema !== schema
        || value.version !== STATE_VERSION) {
        throw new CrawlStateError(`Invalid ${label} format`);
    }
}

function validateControl(control) {
    validateStateEnvelope(control, CONTROL_SCHEMA, 'crawl control');
    if (!CONTROL_COMMANDS.has(control.command)) {
        throw new CrawlStateError('Invalid crawl control command');
    }
}

function validateRateState(state) {
    validateStateEnvelope(state, RATE_SCHEMA, 'crawl rate state');
    if (typeof state.day !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(state.day)
        || !Number.isSafeInteger(state.requestsToday) || state.requestsToday < 0
        || !Number.isSafeInteger(state.dailyCap) || state.dailyCap < 1
        || !Number.isSafeInteger(state.consecutiveFailures) || state.consecutiveFailures < 0
        || !Number.isSafeInteger(state.consecutiveAccessDenials)
        || state.consecutiveAccessDenials < 0) {
        throw new CrawlStateError('Invalid crawl rate counters');
    }
    for (const field of [
        'dailyResetAt',
        'lastRequestStartedAt',
        'lastRequestCompletedAt',
        'nextRequestNotBefore',
        'updatedAt',
    ]) {
        if (state[field] !== null && parseOptionalIso(state[field]) === null) {
            throw new CrawlStateError(`Invalid crawl rate timestamp: ${field}`);
        }
    }
}

function validateLockOwner(owner) {
    validateStateEnvelope(owner, LOCK_SCHEMA, 'crawl lock owner');
    if (typeof owner.token !== 'string' || owner.token.length < 16
        || typeof owner.runId !== 'string' || owner.runId === ''
        || !Number.isSafeInteger(owner.pid) || owner.pid < 1
        || typeof owner.hostname !== 'string' || owner.hostname === ''
        || parseOptionalIso(owner.acquiredAt) === null
        || parseOptionalIso(owner.heartbeatAt) === null) {
        throw new CrawlStateError('Invalid crawl lock owner');
    }
}

function normalizeError(value) {
    if (value === undefined || value === null) return null;
    if (typeof value === 'string') return value.slice(0, 4_000);
    if (value instanceof Error) return value.message.slice(0, 4_000);

    return String(value).slice(0, 4_000);
}

function encodeJson(value, maxBytes, label) {
    let encoded;
    try {
        encoded = JSON.stringify(value, null, 2);
    } catch (error) {
        throw new CrawlStateError(`Unable to encode JSON state: ${label}`, { cause: error });
    }
    if (encoded === undefined) {
        throw new CrawlStateError(`Unable to encode JSON state: ${label}`);
    }
    const json = `${encoded}\n`;
    const bytes = Buffer.byteLength(json);
    if (bytes < 2 || bytes > maxBytes) {
        throw new CrawlStateError(`JSON state exceeds its byte limit: ${label}`);
    }

    return json;
}

async function recoverBackupIfNecessary(target, backup, maxBytes) {
    const targetExists = await pathExists(target);
    const backupExists = await pathExists(backup);
    if (!targetExists && backupExists) {
        await rename(backup, target);
        return;
    }
    if (!targetExists || !backupExists) {
        return;
    }

    try {
        await readJsonCandidate(target, maxBytes);
        await rm(backup, { force: true });
        return;
    } catch (targetError) {
        try {
            await readJsonCandidate(backup, maxBytes);
        } catch (backupError) {
            throw new CrawlStateError(`Neither JSON state copy is valid: ${target}`, {
                cause: new AggregateError([targetError, backupError]),
            });
        }

        await rm(target, { force: true });
        await rename(backup, target);
    }
}

async function readJsonCandidate(path, maxBytes) {
    const metadata = await lstat(path);
    if (metadata.isSymbolicLink() || !metadata.isFile()
        || metadata.size < 2 || metadata.size > maxBytes) {
        throw new CrawlStateError(`Invalid JSON state candidate: ${path}`);
    }

    return JSON.parse(await readFile(path, 'utf8'));
}

async function rejectSymbolicLink(path, label) {
    try {
        const metadata = await lstat(path);
        if (metadata.isSymbolicLink()) {
            throw new CrawlStateError(`${label} cannot be a symbolic link: ${path}`);
        }
    } catch (error) {
        if (error?.code !== 'ENOENT') throw error;
    }
}

async function assertRealDirectory(path, label) {
    let metadata;
    try {
        metadata = await lstat(path);
    } catch (error) {
        throw new CrawlStateError(`Unable to inspect ${label}: ${path}`, { cause: error });
    }
    if (metadata.isSymbolicLink() || !metadata.isDirectory()) {
        throw new CrawlStateError(`${label} must contain only real directories: ${path}`);
    }
}

function isPathWithin(parent, child) {
    const candidate = relative(resolve(parent), resolve(child));

    return candidate === ''
        || (!candidate.startsWith(`..${sep}`) && candidate !== '..' && !isAbsolute(candidate));
}

function samePath(left, right) {
    const normalizedLeft = resolve(left);
    const normalizedRight = resolve(right);

    return process.platform === 'win32'
        ? normalizedLeft.toLowerCase() === normalizedRight.toLowerCase()
        : normalizedLeft === normalizedRight;
}

async function pathExists(path) {
    try {
        await lstat(path);
        return true;
    } catch (error) {
        if (error?.code === 'ENOENT') return false;
        throw error;
    }
}

async function isRegularFile(path) {
    try {
        const metadata = await lstat(path);
        return metadata.isFile() && !metadata.isSymbolicLink();
    } catch (error) {
        if (error?.code === 'ENOENT') return false;
        throw error;
    }
}

function deepMerge(left, right) {
    const result = { ...left };
    for (const [key, value] of Object.entries(right)) {
        if (key === '__proto__' || key === 'prototype' || key === 'constructor') {
            throw new CrawlStateError(`Unsafe crawl status key: ${key}`);
        }
        result[key] = isPlainObject(value) && isPlainObject(result[key])
            ? deepMerge(result[key], value)
            : value;
    }

    return result;
}

function isPlainObject(value) {
    return value !== null
        && typeof value === 'object'
        && !Array.isArray(value)
        && (Object.getPrototypeOf(value) === Object.prototype
            || Object.getPrototypeOf(value) === null);
}

function randomInteger(maximum, random) {
    if (maximum === 0) return 0;
    const value = random();
    if (!Number.isFinite(value) || value < 0 || value >= 1) {
        throw new TypeError('random must return a number greater than or equal to 0 and less than 1');
    }

    return Math.floor(value * (maximum + 1));
}

function utcDayKey(timestamp) {
    return new Date(timestamp).toISOString().slice(0, 10);
}

function nextUtcDayStart(timestamp) {
    const date = new Date(timestamp);
    return Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + 1);
}

function parseOptionalIso(value) {
    if (typeof value !== 'string'
        || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/.test(value)) {
        return null;
    }
    const parsed = Date.parse(value);

    return Number.isFinite(parsed) && new Date(parsed).toISOString() === value
        ? parsed
        : null;
}

function toIso(timestamp) {
    return new Date(normalizeTimestamp(timestamp, 'timestamp')).toISOString();
}

function normalizeTimestamp(value, label) {
    if (value instanceof Date) value = value.getTime();
    if (typeof value === 'string' && value !== '') value = Date.parse(value);
    if (!Number.isFinite(value) || value < 0 || value > 8_640_000_000_000_000) {
        throw new TypeError(`${label} must be a valid timestamp`);
    }

    return Math.trunc(value);
}

function assertPositiveInteger(value, label) {
    if (!Number.isSafeInteger(value) || value < 1) {
        throw new TypeError(`${label} must be a positive integer`);
    }
}

function assertNonNegativeInteger(value, label) {
    if (!Number.isSafeInteger(value) || value < 0) {
        throw new TypeError(`${label} must be a non-negative integer`);
    }
}

function assertNullableShortString(value, label, maximumLength) {
    if (value !== null && (typeof value !== 'string' || value.length > maximumLength)) {
        throw new TypeError(`${label} must be null or a string no longer than ${maximumLength} characters`);
    }
}

function processIsAlive(pid) {
    try {
        process.kill(pid, 0);
        return true;
    } catch (error) {
        if (error?.code === 'ESRCH') return false;

        // EPERM means the process exists but is owned by another account.
        return true;
    }
}

function throwIfAborted(signal) {
    if (signal?.aborted) {
        const error = signal.reason instanceof Error
            ? signal.reason
            : new Error('The crawl wait was aborted');
        error.name = error.name === 'Error' ? 'AbortError' : error.name;
        throw error;
    }
}

function abortableSleep(milliseconds, { signal } = {}) {
    if (milliseconds <= 0) return Promise.resolve();

    return new Promise((resolvePromise, rejectPromise) => {
        if (signal?.aborted) {
            const error = signal.reason instanceof Error
                ? signal.reason
                : new Error('The crawl wait was aborted');
            error.name = error.name === 'Error' ? 'AbortError' : error.name;
            rejectPromise(error);
            return;
        }

        let timer;
        const abort = () => {
            clearTimeout(timer);
            signal?.removeEventListener('abort', abort);
            const error = signal?.reason instanceof Error
                ? signal.reason
                : new Error('The crawl wait was aborted');
            error.name = error.name === 'Error' ? 'AbortError' : error.name;
            rejectPromise(error);
        };
        timer = setTimeout(() => {
            signal?.removeEventListener('abort', abort);
            resolvePromise();
        }, milliseconds);
        signal?.addEventListener('abort', abort, { once: true });
    });
}
