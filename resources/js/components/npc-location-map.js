import {
    brewallToEqemu,
    clampZoom,
    dbToBrewall,
    fitBounds,
    formatCoordinates,
    parseEqMap,
    screenToWorld,
    worldToScreen,
} from '../maps/eq-map-format.js';

const MAP_CACHE = new Map();
const MAX_CACHED_MAPS = 8;
const MAX_DATASET_BYTES = 8 * 1024 * 1024;
const MAX_GROUPS = 100;
const MAX_LOCATIONS = 12000;
const MAX_MAP_ANNOTATIONS = 256;
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 24;
const MAP_PADDING = 32;
const FEATURE_FOCUS_PADDING = 56;
const PAN_EDGE_ALLOWANCE = 40;
const SELECTION_FLASH_MS = 1100;
const MAX_PATH_PREVIEW_WAYPOINTS = 1000;
const MAX_PATH_PREVIEW_PAUSE_SECONDS = 120;
const LOCATION_SEARCH_CACHE = new WeakMap();
const GROUP_FEATURE_BOUNDS_CACHE = new WeakMap();
const MAP_ANNOTATION_CACHE = new WeakMap();
const MARKER_SHAPES = new Set([
    'circle',
    'star',
    'square',
    'pentagon',
    'diamond',
    'triangle',
    'hexagon',
    'cross',
    'compass',
]);
const DEFAULT_MARKER_SHAPES = Object.freeze({
    npcs: 'circle',
    named: 'star',
    merchants: 'square',
    quest: 'pentagon',
    'ground-spawns': 'diamond',
    'zone-points': 'triangle',
    doors: 'hexagon',
    objects: 'cross',
    navigation: 'compass',
});

function finiteNumber(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function isFiniteCoordinate(value) {
    return typeof value === 'number' && Number.isFinite(value);
}

function safeText(value, maximum = 240) {
    return String(value ?? '').replace(/[\u0000-\u001f\u007f]/g, ' ').trim().slice(0, maximum);
}

function safeColor(value, fallback = '#fb7185') {
    return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value) ? value : fallback;
}

/** Normalize the small, non-interactive annotation allowlist shipped in map metadata. */
export function normalizeMapAnnotations(annotations) {
    if (!Array.isArray(annotations)) return [];

    const seen = new Set();
    return annotations.slice(0, MAX_MAP_ANNOTATIONS).map((annotation) => {
        // normalizeMap() stores this flattened form, while server payloads use
        // the canonical nested position shape.
        const position = annotation?.position ?? annotation;
        if (annotation === null || typeof annotation !== 'object' || Array.isArray(annotation)
            || position === null || typeof position !== 'object' || Array.isArray(position)
            || !isFiniteCoordinate(position.x)
            || !isFiniteCoordinate(position.y)
            || !isFiniteCoordinate(position.z)) return null;

        const label = safeText(annotation.label, 120);
        if (!label) return null;
        const kind = safeText(annotation.kind, 32).toLowerCase() === 'portal' ? 'portal' : 'zone-line';
        const key = `${position.x}:${position.y}:${position.z}:${label.toLocaleLowerCase()}`;
        if (seen.has(key)) return null;
        seen.add(key);

        return { x: position.x, y: position.y, z: position.z, label, kind };
    }).filter(Boolean);
}

function mapAnnotations(map) {
    if (map === null || typeof map !== 'object' || Array.isArray(map)) return [];
    if (MAP_ANNOTATION_CACHE.has(map)) return MAP_ANNOTATION_CACHE.get(map);
    const annotations = normalizeMapAnnotations(map.annotations);
    MAP_ANNOTATION_CACHE.set(map, annotations);
    return annotations;
}

function markerShape(value, fallback = 'circle') {
    const shape = safeText(value, 24).toLowerCase();
    return MARKER_SHAPES.has(shape) ? shape : fallback;
}

function animationNow() {
    return typeof performance !== 'undefined' && typeof performance.now === 'function'
        ? performance.now()
        : Date.now();
}

function includeBoundsPoint(bounds, x, y) {
    if (!Number.isFinite(x) || !Number.isFinite(y)) return bounds;
    if (!bounds) return { minX: x, minY: y, maxX: x, maxY: y };
    bounds.minX = Math.min(bounds.minX, x);
    bounds.minY = Math.min(bounds.minY, y);
    bounds.maxX = Math.max(bounds.maxX, x);
    bounds.maxY = Math.max(bounds.maxY, y);
    return bounds;
}

function includeBoundsArea(bounds, area) {
    if (!area) return bounds;
    bounds = includeBoundsPoint(bounds, area.minX, area.minY);
    return includeBoundsPoint(bounds, area.maxX, area.maxY);
}

function groupFeatureBounds(group) {
    if (group === null || typeof group !== 'object' || Array.isArray(group)) return null;
    if (GROUP_FEATURE_BOUNDS_CACHE.has(group)) return GROUP_FEATURE_BOUNDS_CACHE.get(group);

    let bounds = null;
    const locations = Array.isArray(group.locations) ? group.locations : [];
    for (const location of locations) {
        const point = locationMapPoint(location);
        if (point) bounds = includeBoundsPoint(bounds, point.x, point.y);
        bounds = includeBoundsArea(bounds, locationMapArea(location));

        const roam = location?.roam;
        if (roam && [roam.min_x, roam.max_x, roam.min_y, roam.max_y].every(isFiniteCoordinate)) {
            for (const [dbX, dbY] of [
                [roam.min_x, roam.min_y],
                [roam.max_x, roam.min_y],
                [roam.max_x, roam.max_y],
                [roam.min_x, roam.max_y],
            ]) {
                const [x, y] = dbToBrewall(dbX, dbY);
                bounds = includeBoundsPoint(bounds, x, y);
            }
        }
    }

    const paths = group.paths !== null && typeof group.paths === 'object' && !Array.isArray(group.paths)
        ? group.paths
        : {};
    for (const path of Object.values(paths)) {
        if (!Array.isArray(path)) continue;
        for (const waypoint of path) {
            if (waypoint === null || typeof waypoint !== 'object' || Array.isArray(waypoint)
                || !isFiniteCoordinate(waypoint.x) || !isFiniteCoordinate(waypoint.y)) continue;
            const [x, y] = dbToBrewall(waypoint.x, waypoint.y);
            bounds = includeBoundsPoint(bounds, x, y);
        }
    }

    GROUP_FEATURE_BOUNDS_CACHE.set(group, bounds);
    return bounds;
}

function hexToRgba(color, alpha = 1) {
    const value = safeColor(color).slice(1);
    const red = Number.parseInt(value.slice(0, 2), 16);
    const green = Number.parseInt(value.slice(2, 4), 16);
    const blue = Number.parseInt(value.slice(4, 6), 16);
    return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
}

function normalizeLayers(layers) {
    if (!Array.isArray(layers)) return [];

    const seen = new Set();
    return layers.slice(0, 32).map((layer) => {
        const id = safeText(layer?.id, 48).toLowerCase();
        if (!/^[a-z0-9-]+$/.test(id) || seen.has(id)) return null;
        seen.add(id);
        return {
            id,
            label: safeText(layer?.label || id, 80),
            color: safeColor(layer?.color),
            shape: markerShape(layer?.shape, DEFAULT_MARKER_SHAPES[id] ?? 'circle'),
            default: layer?.default !== false,
            count: Math.max(0, Math.min(MAX_LOCATIONS, Number(layer?.count) || 0)),
            truncated: layer?.truncated === true,
        };
    }).filter(Boolean);
}

function normalizeDetails(details) {
    if (!Array.isArray(details)) return [];
    return details.slice(0, 20).map((detail) => ({
        label: safeText(detail?.label, 80),
        value: safeText(detail?.value, 300),
    })).filter((detail) => detail.label || detail.value);
}

function normalizeCandidates(candidates) {
    if (!Array.isArray(candidates)) return [];
    return candidates.slice(0, 20).map((candidate) => ({
        id: Number.isSafeInteger(Number(candidate?.id)) ? Number(candidate.id) : null,
        name: safeText(candidate?.name, 120),
        level_label: safeText(candidate?.level_label, 80),
        chance: Number.isFinite(Number(candidate?.chance)) ? Number(candidate.chance) : null,
        merchant: candidate?.merchant === true,
        named: candidate?.named === true,
        raid: candidate?.raid === true,
        quest: candidate?.quest === true,
        url: safeInternalUrl(candidate?.url),
    })).filter((candidate) => candidate.name);
}

function locationSearchText(location) {
    if (location === null || typeof location !== 'object') return '';
    const cached = LOCATION_SEARCH_CACHE.get(location);
    if (cached !== undefined) return cached;

    const searchable = [
        location.label,
        location.subtitle,
        ...(Array.isArray(location.details) ? location.details.flatMap((detail) => [detail.label, detail.value]) : []),
        ...(Array.isArray(location.candidates) ? location.candidates.map((candidate) => candidate.name) : []),
    ].map((value) => safeText(value, 300).toLocaleLowerCase()).join(' ');
    LOCATION_SEARCH_CACHE.set(location, searchable);

    return searchable;
}

function safeInternalUrl(value) {
    if (typeof value !== 'string' || value.length > 2048) return null;
    try {
        if (typeof window === 'undefined' || !window.location) {
            const url = new URL(value, 'http://localhost/');
            return url.origin === 'http://localhost' ? value : null;
        }
        const url = new URL(value, window.location.href);
        return url.origin === window.location.origin ? url.href : null;
    } catch {
        return null;
    }
}

function normalizeLocation(location, allowedLayerIds) {
    if (location === null || typeof location !== 'object' || Array.isArray(location)) return null;
    const id = typeof location.id === 'number' || typeof location.id === 'string'
        ? safeText(location.id, 128)
        : '';
    if (!id) return null;

    const position = location.position;
    const normalizedPosition = position !== null && typeof position === 'object' && !Array.isArray(position)
        && ['x', 'y', 'z'].every((axis) => typeof position[axis] === 'number' && Number.isFinite(position[axis]))
        ? { x: position.x, y: position.y, z: position.z }
        : null;
    const area = location.area;
    const normalizedArea = area !== null && typeof area === 'object' && !Array.isArray(area)
        && ['min_x', 'max_x', 'min_y', 'max_y'].every((axis) => typeof area[axis] === 'number' && Number.isFinite(area[axis]))
        ? {
            min_x: Math.min(area.min_x, area.max_x),
            max_x: Math.max(area.min_x, area.max_x),
            min_y: Math.min(area.min_y, area.max_y),
            max_y: Math.max(area.min_y, area.max_y),
        }
        : null;
    const layers = Array.isArray(location.layers)
        ? location.layers.map((layer) => safeText(layer, 48).toLowerCase())
            .filter((layer) => allowedLayerIds.has(layer))
        : [];

    return {
        ...location,
        id,
        kind: allowedLayerIds.has(safeText(location.kind, 48).toLowerCase())
            ? safeText(location.kind, 48).toLowerCase()
            : (layers[0] ?? ''),
        layers: [...new Set(layers)],
        label: safeText(location.label, 160),
        subtitle: safeText(location.subtitle, 240),
        position: normalizedPosition,
        area: normalizedArea,
        details: normalizeDetails(location.details),
        candidates: normalizeCandidates(location.candidates),
        url: safeInternalUrl(location.url),
        show_label: location.show_label === true,
    };
}

export function locationMapArea(location) {
    const area = location?.area;
    if (area === null || typeof area !== 'object' || Array.isArray(area)
        || !['min_x', 'max_x', 'min_y', 'max_y'].every((axis) => isFiniteCoordinate(area[axis]))) {
        return null;
    }

    const corners = [
        dbToBrewall(area.min_x, area.min_y),
        dbToBrewall(area.max_x, area.min_y),
        dbToBrewall(area.max_x, area.max_y),
        dbToBrewall(area.min_x, area.max_y),
    ];
    const xs = corners.map(([x]) => x);
    const ys = corners.map(([, y]) => y);
    return {
        minX: Math.min(...xs), maxX: Math.max(...xs),
        minY: Math.min(...ys), maxY: Math.max(...ys),
    };
}

export function hasFinitePosition(location) {
    const position = location?.position;

    return position !== null
        && typeof position === 'object'
        && !Array.isArray(position)
        && isFiniteCoordinate(position.x)
        && isFiniteCoordinate(position.y)
        && isFiniteCoordinate(position.z);
}

function dbPositionMapPoint(position) {
    if (position === null || typeof position !== 'object' || Array.isArray(position)
        || !isFiniteCoordinate(position.x)
        || !isFiniteCoordinate(position.y)
        || !isFiniteCoordinate(position.z)) {
        return null;
    }

    const [x, y] = dbToBrewall(position.x, position.y);

    return { x, y, z: position.z };
}

function cssColor(rgb, darkCanvas = true, alpha = 1) {
    let [red, green, blue] = rgb.map((channel) => Math.max(0, Math.min(255, Number(channel) || 0)));
    const luminance = (red * 299 + green * 587 + blue * 114) / 1000;

    // Brewall's black wall lines were authored for a parchment background. Lift
    // them on the Magelo night-sky canvas while preserving meaningful colors.
    if (darkCanvas && luminance < 72) {
        const lift = 132 - luminance;
        red = Math.min(255, red + lift);
        green = Math.min(255, green + lift);
        blue = Math.min(255, blue + lift);
    }

    return `rgba(${Math.round(red)}, ${Math.round(green)}, ${Math.round(blue)}, ${alpha})`;
}

export function locationMapPoint(location) {
    return hasFinitePosition(location) ? dbPositionMapPoint(location.position) : null;
}

function formatCoordinateValue(value, fractionDigits) {
    const normalized = Object.is(value, -0) ? 0 : value;
    return normalized.toFixed(fractionDigits);
}

/** Format exact points normally and rectangular spawn areas as their stored bounds. */
export function formatLocationCoordinates(location, coordinateOrder = 'xyz', fractionDigits = 2) {
    if (!hasFinitePosition(location)) throw new TypeError('Location must contain finite x, y, and z coordinates');
    if (coordinateOrder !== 'xyz' && coordinateOrder !== 'yxz') {
        throw new RangeError("Coordinate order must be either 'xyz' or 'yxz'");
    }
    if (!Number.isInteger(fractionDigits) || fractionDigits < 0 || fractionDigits > 6) {
        throw new RangeError('fractionDigits must be an integer from 0 through 6');
    }

    const area = location?.area;
    if (area === null || typeof area !== 'object' || Array.isArray(area)
        || !['min_x', 'max_x', 'min_y', 'max_y'].every((axis) => isFiniteCoordinate(area[axis]))) {
        return formatCoordinates(location.position, coordinateOrder, fractionDigits);
    }

    const range = (minimum, maximum) => {
        const low = Math.min(minimum, maximum);
        const high = Math.max(minimum, maximum);
        const lowText = formatCoordinateValue(low, fractionDigits);
        const highText = formatCoordinateValue(high, fractionDigits);
        return low === high ? lowText : `${lowText}–${highText}`;
    };
    const axes = {
        X: range(area.min_x, area.max_x),
        Y: range(area.min_y, area.max_y),
        Z: formatCoordinateValue(location.position.z, fractionDigits),
    };
    const labels = coordinateOrder === 'yxz' ? ['Y', 'X', 'Z'] : ['X', 'Y', 'Z'];

    return labels.map((label) => `${label} ${axes[label]}`).join(', ');
}

function seededRandom(seedValue) {
    let seed = 2166136261;
    for (const character of String(seedValue ?? 'path')) {
        seed ^= character.codePointAt(0);
        seed = Math.imul(seed, 16777619);
    }
    seed >>>= 0;

    return () => {
        seed += 0x6d2b79f5;
        let value = seed;
        value = Math.imul(value ^ (value >>> 15), value | 1);
        value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
        return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
    };
}

function pathDistance(left, right) {
    return Math.hypot(left.x - right.x, left.y - right.y, (left.z - right.z) * 0.25);
}

function previewRoute(waypoints, wanderType) {
    if (wanderType === 3) {
        return [...waypoints, ...waypoints.slice(0, -1).reverse()];
    }
    return waypoints;
}

function resolvedPauseSeconds(rawPause, pauseType, random) {
    const configured = Math.max(0, Math.min(MAX_PATH_PREVIEW_PAUSE_SECONDS, finiteNumber(rawPause)));
    if (pauseType === 0) return configured * (0.5 + (random() * 0.5));
    if (pauseType === 2) return configured * random();
    return configured;
}

/** Build a deterministic, bounded representative timeline for an EQEmu path grid. */
export function buildPathPreviewTimeline(location, path, pathMeta = {}) {
    if (!hasFinitePosition(location) || !Array.isArray(path)) return null;
    if (path.length > MAX_PATH_PREVIEW_WAYPOINTS) return null;
    const waypoints = path.map((waypoint, index) => {
        if (waypoint === null || typeof waypoint !== 'object' || Array.isArray(waypoint)
            || !isFiniteCoordinate(waypoint.x)
            || !isFiniteCoordinate(waypoint.y)
            || !isFiniteCoordinate(waypoint.z)) return null;
        return {
            x: waypoint.x,
            y: waypoint.y,
            z: waypoint.z,
            pause: Math.max(0, finiteNumber(waypoint.pause)),
            number: Number.isSafeInteger(Number(waypoint.number)) ? Number(waypoint.number) : index + 1,
            centerpoint: waypoint.centerpoint === true || Number(waypoint.centerpoint) === 1,
        };
    }).filter(Boolean).sort((left, right) => left.number - right.number);
    if (waypoints.length < 2) return null;

    const wanderType = Number(pathMeta?.wander_type);
    if (![3, 4, 6].includes(wanderType)) return null;
    const pauseType = Math.max(0, Math.min(2, Number.isSafeInteger(Number(pathMeta?.pause_type))
        ? Number(pathMeta.pause_type) : 1));
    const random = seededRandom(`${location.id ?? 'spawn'}:${location.path_grid ?? 'grid'}:${wanderType}`);
    const traversal = previewRoute(waypoints, wanderType);
    const route = traversal;
    const routeDistance = route.slice(1).reduce(
        (total, point, index) => total + pathDistance(route[index], point),
        0,
    );
    const unitsPerSecond = Math.max(20, Math.min(250, routeDistance / 18));
    const events = [];
    let cursorMs = 0;

    for (let index = 1; index < route.length; index += 1) {
        const from = route[index - 1];
        const to = route[index];
        const travelMs = Math.max(180, (pathDistance(from, to) / unitsPerSecond) * 1000);
        events.push({ kind: 'move', startMs: cursorMs, endMs: cursorMs + travelMs, from, to });
        cursorMs += travelMs;

        const pauseSeconds = resolvedPauseSeconds(to.pause, pauseType, random);
        if (pauseSeconds > 0.01) {
            const pauseMs = pauseSeconds * 1000;
            events.push({ kind: 'pause', startMs: cursorMs, endMs: cursorMs + pauseMs, from: to, to });
            cursorMs += pauseMs;
        }
    }

    if (events.length === 0 || !Number.isFinite(cursorMs) || cursorMs <= 0) return null;
    return {
        events,
        durationMs: cursorMs,
        loop: wanderType !== 4 && wanderType !== 6,
        wanderType,
        pauseType,
        approximate: pauseType !== 1
            || waypoints.some((waypoint) => waypoint.pause > MAX_PATH_PREVIEW_PAUSE_SECONDS),
    };
}

export default function npcLocationMap(config = {}) {
    let featureCache = null;
    let cachedBaseCanvas = null;
    let cachedOverlayCanvas = null;
    let baseDirty = true;
    let overlayDirty = true;
    let lostPointerCaptureHandler = null;
    let reducedMotionQuery = null;
    let reducedMotionChangeHandler = null;
    let cachedInteractionMap = null;
    let cachedInteractionGroup = null;
    let cachedInteractionBounds = null;
    let selectionFlashStartedAt = -1;
    let selectionFlashUntil = 0;
    let pathPreviewElapsedMs = 0;
    let pathPreviewLastFrameAt = -1;
    let pathPreviewCache = null;
    let zonePointLayerWasActive = null;
    let visibilityChangeHandler = null;

    return {
        groups: Array.isArray(config.groups) ? config.groups : [],
        dataUrl: typeof config.dataUrl === 'string' ? config.dataUrl : '',
        layers: normalizeLayers(config.layers),
        activeLayers: {},
        searchQuery: '',
        syncUrl: config.syncUrl === true,
        datasetLoaded: !config.dataUrl,
        datasetLoading: false,
        datasetError: '',
        coordinateOrder: config.coordinateOrder === 'yxz' ? 'yxz' : 'xyz',
        npcName: String(config.npcName ?? 'NPC'),
        subjectName: String(config.subjectName ?? config.npcName ?? 'locations'),
        selectedZoneKey: null,
        selectedLocationId: null,
        hoveredLocationId: null,
        mapData: null,
        loading: false,
        loadError: '',
        statusMessage: '',
        copyMessage: '',
        showMapPoints: false,
        showZoneLines: config.showZoneLines !== false,
        showPaths: true,
        pathPreviewEnabled: config.pathPreviewEnabled !== false,
        pathPreviewActive: false,
        pathPreviewPlaying: false,
        pathPreviewSpeed: 4,
        pathPreviewStatus: '',
        elevationFocus: false,
        elevationRange: 35,
        zoom: 1,
        panX: 0,
        panY: 0,
        fit: { centerX: 0, centerY: 0, scale: 1 },
        canvasWidth: 1,
        canvasHeight: 1,
        dragging: false,
        pointerMoved: false,
        pointerStart: null,
        animationFrame: null,
        resizeObserver: null,
        visibilityObserver: null,
        loadGeneration: 0,
        loadedMapUrl: null,
        loadingMapUrl: null,
        pointerX: 0,
        pointerY: 0,
        pointerOnMap: false,
        urlSyncTimer: null,
        requestedPinId: null,
        pendingFocusId: null,
        reducedMotion: false,
        tooltipMeasureVersion: 0,

        init() {
            if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
                reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
                this.reducedMotion = reducedMotionQuery.matches;
                reducedMotionChangeHandler = (event) => {
                    this.reducedMotion = event.matches === true;
                    if (this.reducedMotion) {
                        selectionFlashStartedAt = -1;
                        selectionFlashUntil = 0;
                        if (this.pathPreviewPlaying) this.pausePathPreview('Path preview paused for reduced motion.');
                    }
                    this.scheduleDraw();
                };
                if (typeof reducedMotionQuery.addEventListener === 'function') {
                    reducedMotionQuery.addEventListener('change', reducedMotionChangeHandler);
                } else {
                    reducedMotionQuery.addListener?.(reducedMotionChangeHandler);
                }
            }
            this.configureLayers(this.layers);
            this.applyUrlState();
            zonePointLayerWasActive = this.zonePointLayerActive();
            this.initializeGroupSelection();

            this.resizeObserver = new ResizeObserver(() => this.resizeCanvas());
            if (this.$refs.viewport) {
                this.resizeObserver.observe(this.$refs.viewport);
            }
            if (typeof this.$refs.canvas?.addEventListener === 'function') {
                lostPointerCaptureHandler = () => this.onLostPointerCapture();
                this.$refs.canvas.addEventListener('lostpointercapture', lostPointerCaptureHandler);
            }
            if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
                visibilityChangeHandler = () => {
                    if (document.hidden && this.pathPreviewPlaying) {
                        this.pausePathPreview('Path preview paused while this tab is hidden.');
                    }
                };
                document.addEventListener('visibilitychange', visibilityChangeHandler);
            }

            this.visibilityObserver = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.ensureDatasetLoaded();
                } else if (this.pathPreviewPlaying) {
                    this.pausePathPreview('Path preview paused while the map is out of view.');
                }
            }, { rootMargin: '240px' });
            this.visibilityObserver.observe(this.$root);

            this.$watch('elevationFocus', () => this.invalidateBase());
            this.$watch('elevationRange', () => this.invalidateBase());
            this.$watch('showMapPoints', () => this.invalidateBase());
            this.$watch('showZoneLines', () => this.invalidateBase());
            this.$watch('showPaths', () => this.invalidateBase());
            this.$watch('searchQuery', () => this.onFiltersChanged());
        },

        destroy() {
            this.loadGeneration += 1;
            this.loading = false;
            this.loadingMapUrl = null;
            this.resizeObserver?.disconnect();
            this.visibilityObserver?.disconnect();
            if (lostPointerCaptureHandler
                && typeof this.$refs?.canvas?.removeEventListener === 'function') {
                this.$refs.canvas.removeEventListener('lostpointercapture', lostPointerCaptureHandler);
            }
            lostPointerCaptureHandler = null;
            cachedBaseCanvas = null;
            cachedOverlayCanvas = null;
            featureCache = null;
            if (reducedMotionChangeHandler) {
                if (typeof reducedMotionQuery?.removeEventListener === 'function') {
                    reducedMotionQuery.removeEventListener('change', reducedMotionChangeHandler);
                } else {
                    reducedMotionQuery?.removeListener?.(reducedMotionChangeHandler);
                }
            }
            reducedMotionQuery = null;
            reducedMotionChangeHandler = null;
            if (visibilityChangeHandler && typeof document !== 'undefined') {
                document.removeEventListener?.('visibilitychange', visibilityChangeHandler);
            }
            visibilityChangeHandler = null;
            this.pathPreviewPlaying = false;
            pathPreviewCache = null;
            if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
            if (this.urlSyncTimer) window.clearTimeout(this.urlSyncTimer);
        },

        initializeGroupSelection() {
            const requestedGroup = this.requestedPinId
                ? this.groups.find((group) => Array.isArray(group?.locations)
                    && group.locations.some((location) => String(location.id) === this.requestedPinId))
                : null;
            const requested = requestedGroup?.locations.find(
                (location) => String(location.id) === this.requestedPinId,
            ) ?? null;
            const mappedGroup = this.groups.find((group) => group?.map?.available && group?.map?.url);
            this.selectedZoneKey = requestedGroup?.key ?? mappedGroup?.key ?? this.groups[0]?.key ?? null;
            featureCache = null;
            overlayDirty = true;
            if (requested) {
                this.enableLocationLayers(requested);
                this.pendingFocusId = requested.id;
            } else {
                this.pendingFocusId = null;
            }
            this.selectedLocationId = requested?.id ?? this.mappableLocations[0]?.id ?? this.currentLocations[0]?.id ?? null;
        },

        enableLocationLayers(location) {
            const locationLayers = Array.isArray(location?.layers) ? location.layers : [];
            if (locationLayers.length === 0) return;
            const next = { ...this.activeLayers };
            let changed = false;
            for (const layer of locationLayers) {
                if (Object.prototype.hasOwnProperty.call(next, layer) && !next[layer]) {
                    next[layer] = true;
                    changed = true;
                }
            }
            if (changed) {
                this.activeLayers = next;
                featureCache = null;
                overlayDirty = true;
            }
        },

        configureLayers(layers) {
            this.layers = normalizeLayers(layers);
            const next = {};
            for (const layer of this.layers) {
                next[layer.id] = Object.prototype.hasOwnProperty.call(this.activeLayers, layer.id)
                    ? Boolean(this.activeLayers[layer.id])
                    : layer.default;
            }
            this.activeLayers = next;
            zonePointLayerWasActive = this.zonePointLayerActive();
            featureCache = null;
            overlayDirty = true;
        },

        zonePointLayerActive() {
            return !this.layers.some((layer) => layer.id === 'zone-points')
                || this.activeLayers['zone-points'] === true;
        },

        get currentGroup() {
            return this.groups.find((group) => group.key === this.selectedZoneKey) ?? this.groups[0] ?? null;
        },

        get currentLocations() {
            return Array.isArray(this.currentGroup?.locations) ? this.currentGroup.locations : [];
        },

        get filteredLocations() {
            return this.featureData().filteredLocations;
        },

        get mappableLocations() {
            return this.featureData().mappableLocations;
        },

        get mappableEntries() {
            return this.featureData().mappableEntries;
        },

        featureData() {
            const group = this.currentGroup;
            const query = safeText(this.searchQuery, 120).toLocaleLowerCase();
            const layerSignature = this.layers
                .map((layer) => `${layer.id}:${this.activeLayers[layer.id] ? 1 : 0}`)
                .join('|');
            if (featureCache?.group === group
                && featureCache.query === query
                && featureCache.layerSignature === layerSignature) {
                return featureCache;
            }

            const currentLocations = Array.isArray(group?.locations) ? group.locations : [];
            const activeLayerIds = new Set(this.layers
                .filter((layer) => this.activeLayers[layer.id])
                .map((layer) => layer.id));
            const layerCounts = new Map();
            const filteredLocations = [];

            for (const location of currentLocations) {
                const locationLayers = Array.isArray(location.layers) ? location.layers : [];
                for (const layer of new Set(locationLayers)) {
                    layerCounts.set(layer, (layerCounts.get(layer) ?? 0) + 1);
                }
                if (this.layers.length > 0
                    && !locationLayers.some((layer) => activeLayerIds.has(layer))) continue;
                if (query && !locationSearchText(location).includes(query)) continue;
                filteredLocations.push(location);
            }

            const mappableEntries = filteredLocations.map((location) => ({
                location,
                point: locationMapPoint(location),
                area: locationMapArea(location),
            })).filter((entry) => entry.point !== null);
            featureCache = {
                group,
                query,
                layerSignature,
                layerCounts,
                filteredLocations,
                mappableEntries,
                mappableLocations: mappableEntries.map((entry) => entry.location),
            };

            return featureCache;
        },

        get currentPaths() {
            const paths = this.currentGroup?.paths;
            return paths !== null && typeof paths === 'object' && !Array.isArray(paths) ? paths : {};
        },

        get currentPathMeta() {
            const metadata = this.currentGroup?.path_meta;
            return metadata !== null && typeof metadata === 'object' && !Array.isArray(metadata) ? metadata : {};
        },

        get selectedLocation() {
            return this.filteredLocations.find((location) => String(location.id) === String(this.selectedLocationId))
                ?? this.filteredLocations[0]
                ?? null;
        },

        get hoveredLocation() {
            return this.filteredLocations.find((location) => String(location.id) === String(this.hoveredLocationId)) ?? null;
        },

        get tooltipStyle() {
            // The version is intentionally read so Alpine recomputes after the
            // conditional tooltip content has mounted and can be measured.
            void this.tooltipMeasureVersion;
            const width = 290;
            const margin = 10;
            const gap = 8;
            const measuredHeight = Number(this.$refs?.tooltip?.offsetHeight);
            const height = Number.isFinite(measuredHeight) && measuredHeight > 0 ? measuredHeight : 260;
            const maxLeft = Math.max(margin, this.canvasWidth - width - margin);
            const maxTop = Math.max(margin, this.canvasHeight - height - margin);
            let left = Math.max(margin, Math.min(maxLeft, this.pointerX + 16));
            let top = Math.max(margin, Math.min(maxTop, this.pointerY + 16));

            const cursorReadout = this.$refs?.cursorCoordinates;
            const readoutWidth = Number(cursorReadout?.offsetWidth);
            const readoutHeight = Number(cursorReadout?.offsetHeight);
            if (Number.isFinite(readoutWidth) && readoutWidth > 0
                && Number.isFinite(readoutHeight) && readoutHeight > 0) {
                const readoutLeft = 12;
                const readoutTop = 12;
                const readoutRight = readoutLeft + readoutWidth;
                const readoutBottom = readoutTop + readoutHeight;
                const overlapsReadout = left < readoutRight + gap
                    && left + width > readoutLeft - gap
                    && top < readoutBottom + gap
                    && top + height > readoutTop - gap;

                if (overlapsReadout) {
                    const belowReadout = readoutBottom + gap;
                    const rightOfReadout = readoutRight + gap;
                    if (belowReadout <= maxTop) top = belowReadout;
                    else if (rightOfReadout <= maxLeft) left = rightOfReadout;
                }
            }

            return `left:${left}px;top:${top}px;max-width:${width}px`;
        },

        get cursorCoordinateText() {
            if (!this.mapData || !this.pointerOnMap) return 'Y —, X —';
            const world = this.toWorld(this.pointerX, this.pointerY);
            const [dbX, dbY] = brewallToEqemu(world.x, world.y);
            return `Y ${formatCoordinateValue(dbY, 2)}, X ${formatCoordinateValue(dbX, 2)}`;
        },

        refreshTooltipPosition() {
            this.tooltipMeasureVersion += 1;
            if (this.hoveredLocationId === null || typeof this.$nextTick !== 'function') return;
            const hoveredId = String(this.hoveredLocationId);
            this.$nextTick(() => {
                if (String(this.hoveredLocationId) === hoveredId) this.tooltipMeasureVersion += 1;
            });
        },

        get busy() {
            return this.datasetLoading || this.loading || (Boolean(this.dataUrl) && !this.datasetLoaded && !this.datasetError);
        },

        get selectedCoordinateText() {
            if (!this.selectedLocation) return '';
            if (!hasFinitePosition(this.selectedLocation)) return 'Coordinates unavailable';
            return this.coordinateLabel(this.selectedLocation);
        },

        get mapAvailable() {
            return Boolean(this.currentGroup?.map?.available && this.currentGroup?.map?.url);
        },

        get hasPaths() {
            return this.mappableLocations.some((location) => this.hasPathAssignment(location));
        },

        get hasDrawableMovement() {
            return this.mappableLocations.some((location) => this.hasDrawablePath(location));
        },

        get pathPreviewAvailable() {
            return this.pathPreviewEnabled && this.pathPreviewTimeline() !== null;
        },

        get pathPreviewBehaviorLabel() {
            const metadata = this.pathMetaForLocation(this.selectedLocation);
            return safeText(metadata.wander_type_label, 80) || 'Configured route';
        },

        get availableZoneAnnotations() {
            return mapAnnotations(this.currentGroup?.map);
        },

        get hasZoneAnnotations() {
            return this.availableZoneAnnotations.length > 0;
        },

        get visibleZoneAnnotations() {
            if (!this.showZoneLines) return [];
            const zonePointLayer = this.layers.find((layer) => layer.id === 'zone-points');
            if (zonePointLayer && !this.activeLayers['zone-points']) return [];

            const databaseTransitions = this.currentLocations
                .filter((location) => (location?.kind === 'zone-points' || location?.kind === 'doors')
                    && (location.layers ?? []).some((layer) => this.activeLayers[layer] === true))
                .map((location) => locationMapPoint(location))
                .filter(Boolean);

            return this.availableZoneAnnotations.filter((annotation) => !databaseTransitions.some((point) => (
                ((point.x - annotation.x) ** 2) + ((point.y - annotation.y) ** 2) <= 24 ** 2
            )));
        },

        get hasRoamAreas() {
            return this.currentLocations.some((location) => location.roam);
        },

        layerCount(layerId) {
            return this.featureData().layerCounts.get(layerId) ?? 0;
        },

        get zoneLabel() {
            if (!this.currentGroup) return '';
            return `${this.currentGroup.long_name}${Number(this.currentGroup.version) === 0 ? '' : ` · v${this.currentGroup.version}`}`;
        },

        async ensureDatasetLoaded(force = false) {
            if (!this.dataUrl || (this.datasetLoaded && !force)) {
                await this.ensureMapLoaded(force);
                return;
            }
            if (this.datasetLoading) return;

            const endpoint = safeInternalUrl(this.dataUrl);
            if (!endpoint) {
                this.datasetError = 'The atlas data address is invalid.';
                return;
            }

            const generation = ++this.loadGeneration;
            this.datasetLoading = true;
            this.datasetError = '';
            this.statusMessage = `Loading ${this.subjectName} atlas data…`;

            try {
                const response = await fetch(endpoint, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                if (!response.ok) throw new Error(`Atlas request failed (${response.status})`);
                const contentLength = Number(response.headers.get('content-length'));
                if (Number.isFinite(contentLength) && contentLength > MAX_DATASET_BYTES) {
                    throw new Error('Atlas data is larger than the safety limit');
                }
                const text = await response.text();
                if (new TextEncoder().encode(text).byteLength > MAX_DATASET_BYTES) {
                    throw new Error('Atlas data is larger than the safety limit');
                }
                const dataset = JSON.parse(text);
                if (this.loadGeneration !== generation) return;
                this.installDataset(dataset);
                this.datasetLoaded = true;
                this.initializeGroupSelection();
                this.datasetLoading = false;
                await this.ensureMapLoaded(true);
            } catch (error) {
                if (this.loadGeneration !== generation) return;
                this.datasetError = 'The zone atlas data could not be loaded. The zone lists remain available.';
                this.statusMessage = this.datasetError;
                console.error('Unable to load zone atlas data:', error);
            } finally {
                if (this.loadGeneration === generation) this.datasetLoading = false;
            }
        },

        installDataset(dataset) {
            if (dataset === null || typeof dataset !== 'object' || Array.isArray(dataset)
                || !Array.isArray(dataset.groups) || dataset.groups.length > MAX_GROUPS) {
                throw new Error('Atlas response has an invalid shape');
            }
            if (Array.isArray(dataset.layers)) this.configureLayers(dataset.layers);
            const allowedLayerIds = new Set(this.layers.map((layer) => layer.id));
            let locationCount = 0;
            this.groups = dataset.groups.map((group) => {
                if (group === null || typeof group !== 'object' || Array.isArray(group)) {
                    throw new Error('Atlas group has an invalid shape');
                }
                const rawLocations = Array.isArray(group.locations) ? group.locations : [];
                locationCount += rawLocations.length;
                if (locationCount > MAX_LOCATIONS) throw new Error('Atlas contains too many locations');
                return {
                    ...group,
                    key: safeText(group.key, 140),
                    short_name: safeText(group.short_name, 64),
                    long_name: safeText(group.long_name, 160),
                    version: Number.isSafeInteger(Number(group.version)) ? Number(group.version) : 0,
                    map: this.normalizeMap(group.map),
                    paths: group.paths !== null && typeof group.paths === 'object' && !Array.isArray(group.paths)
                        ? group.paths
                        : {},
                    path_meta: group.path_meta !== null && typeof group.path_meta === 'object' && !Array.isArray(group.path_meta)
                        ? group.path_meta
                        : {},
                    locations: rawLocations.map((location) => normalizeLocation(location, allowedLayerIds)).filter(Boolean),
                };
            });
            featureCache = null;
            baseDirty = true;
            overlayDirty = true;
        },

        normalizeMap(map) {
            if (map === null || typeof map !== 'object' || Array.isArray(map) || map.available !== true) return null;
            const url = safeInternalUrl(map.url);
            return url ? {
                ...map,
                url,
                available: true,
                annotations: normalizeMapAnnotations(map.annotations),
            } : null;
        },

        onFiltersChanged() {
            featureCache = null;
            overlayDirty = true;
            let selectionChanged = false;
            const selectionIsVisible = this.selectedLocationId !== null
                && this.filteredLocations.some(
                    (location) => String(location.id) === String(this.selectedLocationId),
                );
            if (!selectionIsVisible) {
                const nextSelectedId = this.mappableLocations[0]?.id ?? null;
                selectionChanged = nextSelectedId !== this.selectedLocationId;
                this.selectedLocationId = nextSelectedId;
            }
            if (selectionChanged) this.resetPathPreview(false);
            this.hoveredLocationId = null;
            const zonePointLayerIsActive = this.zonePointLayerActive();
            const zoneAnnotationVisibilityChanged = zonePointLayerWasActive !== zonePointLayerIsActive;
            zonePointLayerWasActive = zonePointLayerIsActive;
            if ((this.showZoneLines && zoneAnnotationVisibilityChanged)
                || (selectionChanged && (this.elevationFocus || this.showPaths))) {
                this.invalidateBase();
            }
            else this.scheduleDraw();
            this.queueUrlSync();
        },

        resetFilters() {
            this.searchQuery = '';
            const next = {};
            for (const layer of this.layers) next[layer.id] = layer.default;
            this.activeLayers = next;
            this.onFiltersChanged();
        },

        setAllLayers(visible) {
            const next = {};
            for (const layer of this.layers) next[layer.id] = Boolean(visible);
            this.activeLayers = next;
            this.onFiltersChanged();
        },

        applyUrlState() {
            if (!this.syncUrl || typeof window === 'undefined') return;
            const params = new URL(window.location.href).searchParams;
            const requestedLayers = safeText(params.get('layers'), 500).split(',').filter(Boolean);
            if (requestedLayers.length === 1 && requestedLayers[0] === 'none') {
                this.activeLayers = Object.fromEntries(this.layers.map((layer) => [layer.id, false]));
            } else if (requestedLayers.length > 0) {
                const allowed = new Set(this.layers.map((layer) => layer.id));
                const knownLayers = requestedLayers.filter((layer) => allowed.has(layer));
                if (knownLayers.length > 0) {
                    const next = {};
                    for (const layer of this.layers) next[layer.id] = knownLayers.includes(layer.id);
                    this.activeLayers = next;
                }
            }
            this.searchQuery = safeText(params.get('mapq'), 120);
            this.requestedPinId = safeText(params.get('pin'), 128) || null;
            zonePointLayerWasActive = this.zonePointLayerActive();
            featureCache = null;
        },

        queueUrlSync() {
            if (!this.syncUrl || typeof window === 'undefined') return;
            if (this.urlSyncTimer) window.clearTimeout(this.urlSyncTimer);
            this.urlSyncTimer = window.setTimeout(() => this.syncUrlState(), 180);
        },

        syncUrlState() {
            if (!this.syncUrl || typeof window === 'undefined') return;
            const url = new URL(window.location.href);
            const selectedLayers = this.layers.filter((layer) => this.activeLayers[layer.id]).map((layer) => layer.id);
            const defaults = this.layers.filter((layer) => layer.default).map((layer) => layer.id);
            if (selectedLayers.join(',') === defaults.join(',')) url.searchParams.delete('layers');
            else if (selectedLayers.length > 0) url.searchParams.set('layers', selectedLayers.join(','));
            else url.searchParams.set('layers', 'none');
            const query = safeText(this.searchQuery, 120);
            if (query) url.searchParams.set('mapq', query);
            else url.searchParams.delete('mapq');
            if (this.selectedLocationId !== null) url.searchParams.set('pin', String(this.selectedLocationId));
            else url.searchParams.delete('pin');
            window.history.replaceState(window.history.state, '', url);
        },

        async selectZone(key) {
            if (!this.groups.some((group) => group.key === key)) return;
            this.resetPathPreview(false);
            this.selectedZoneKey = key;
            featureCache = null;
            this.pendingFocusId = null;
            this.selectedLocationId = this.mappableLocations[0]?.id ?? this.currentLocations[0]?.id ?? null;
            this.hoveredLocationId = null;
            this.mapData = null;
            this.loadedMapUrl = null;
            this.loadError = '';
            this.zoom = 1;
            this.panX = 0;
            this.panY = 0;
            baseDirty = true;
            overlayDirty = true;
            await this.ensureMapLoaded(true);
        },

        async ensureMapLoaded(force = false) {
            const map = this.currentGroup?.map;
            const mapUrl = map?.available ? safeInternalUrl(map.url) : null;
            if (!mapUrl) {
                this.loadGeneration += 1;
                this.mapData = null;
                baseDirty = true;
                overlayDirty = true;
                this.loadedMapUrl = null;
                this.loadingMapUrl = null;
                this.loading = false;
                this.loadError = '';
                this.statusMessage = `No base map is available for ${this.zoneLabel}.`;
                this.scheduleDraw();
                return;
            }

            if (!force && this.mapData && this.loadedMapUrl === mapUrl) {
                return;
            }

            if (!force && this.loading && this.loadingMapUrl === mapUrl) return;

            const generation = ++this.loadGeneration;
            this.loadingMapUrl = mapUrl;
            this.loading = true;
            this.loadError = '';
            this.statusMessage = `Loading the ${this.zoneLabel} base map…`;

            try {
                if (!MAP_CACHE.has(mapUrl)) {
                    if (MAP_CACHE.size >= MAX_CACHED_MAPS) {
                        MAP_CACHE.delete(MAP_CACHE.keys().next().value);
                    }
                    MAP_CACHE.set(mapUrl, fetch(mapUrl, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/octet-stream' },
                    }).then(async (response) => {
                        if (!response.ok) {
                            throw new Error(`Map request failed (${response.status})`);
                        }

                        const contentLength = Number(response.headers.get('content-length'));
                        if (Number.isFinite(contentLength) && contentLength > 8 * 1024 * 1024) {
                            throw new Error('Map asset is larger than the safety limit');
                        }

                        return parseEqMap(await response.arrayBuffer());
                    }).catch((error) => {
                        MAP_CACHE.delete(mapUrl);
                        throw error;
                    }));
                }

                const parsed = await MAP_CACHE.get(mapUrl);
                if (this.loadGeneration !== generation) return;

                this.mapData = parsed;
                baseDirty = true;
                overlayDirty = true;
                this.loadedMapUrl = mapUrl;
                this.resetView(false);
                if (!this.focusPendingLocation()) this.flashSelection();
                this.statusMessage = `${this.zoneLabel} map ready: ${parsed.segmentCount.toLocaleString()} lines and ${this.mappableLocations.length.toLocaleString()} location${this.mappableLocations.length === 1 ? '' : 's'}.`;
            } catch (error) {
                if (this.loadGeneration !== generation) return;
                this.mapData = null;
                baseDirty = true;
                overlayDirty = true;
                this.loadedMapUrl = null;
                this.loadError = 'The base map could not be loaded. The verified location table is still available below.';
                this.statusMessage = this.loadError;
                console.error('Unable to load EQ map:', error);
            } finally {
                if (this.loadGeneration === generation) {
                    this.loading = false;
                    this.loadingMapUrl = null;
                    this.scheduleDraw();
                }
            }
        },

        resizeCanvas() {
            const canvas = this.$refs.canvas;
            const viewport = this.$refs.viewport;
            if (!canvas || !viewport) {
                this.pointerOnMap = false;
                return;
            }

            const rect = viewport.getBoundingClientRect();
            if (!Number.isFinite(rect.width) || !Number.isFinite(rect.height)
                || rect.width < 1 || rect.height < 1) {
                this.pointerOnMap = false;
                return;
            }

            const width = Math.max(1, Math.floor(rect.width));
            const height = Math.max(1, Math.floor(rect.height));
            const ratio = Math.min(window.devicePixelRatio || 1, 2);
            if (width !== this.canvasWidth || height !== this.canvasHeight) this.pointerOnMap = false;

            this.canvasWidth = width;
            this.canvasHeight = height;
            canvas.width = Math.floor(width * ratio);
            canvas.height = Math.floor(height * ratio);
            canvas.style.width = `${width}px`;
            canvas.style.height = `${height}px`;

            const context = canvas.getContext('2d');
            context?.setTransform(ratio, 0, 0, ratio, 0, 0);
            baseDirty = true;
            overlayDirty = true;

            if (this.mapData && this.fit.scale === 1 && this.fit.centerX === 0 && this.fit.centerY === 0) {
                this.resetView(false);
            } else {
                this.recalculateFit(false);
                if (!this.focusPendingLocation()) this.scheduleDraw();
            }
        },

        recalculateFit(resetPan = true) {
            if (!this.mapData) return;
            const maximumSafePadding = Math.max(0, (Math.min(this.canvasWidth, this.canvasHeight) - 1) / 2);
            const padding = Math.min(MAP_PADDING, maximumSafePadding);
            this.fit = fitBounds(this.mapData.bounds, this.canvasWidth, this.canvasHeight, padding);
            baseDirty = true;
            overlayDirty = true;
            if (resetPan) {
                this.zoom = 1;
                this.panX = 0;
                this.panY = 0;
            } else {
                this.clampPan();
            }
        },

        interactionBounds() {
            const map = this.mapData;
            const group = this.currentGroup;
            if (map === cachedInteractionMap && group === cachedInteractionGroup && cachedInteractionBounds) {
                return cachedInteractionBounds;
            }

            const mapBounds = map?.bounds;
            let bounds = mapBounds && [mapBounds.minX, mapBounds.minY, mapBounds.maxX, mapBounds.maxY]
                .every((value) => Number.isFinite(Number(value)))
                ? {
                    minX: Number(mapBounds.minX),
                    minY: Number(mapBounds.minY),
                    maxX: Number(mapBounds.maxX),
                    maxY: Number(mapBounds.maxY),
                }
                : null;
            for (const point of Array.isArray(map?.points) ? map.points : []) {
                bounds = includeBoundsPoint(bounds, Number(point?.x), Number(point?.y));
            }
            for (const annotation of this.availableZoneAnnotations) {
                bounds = includeBoundsPoint(bounds, annotation.x, annotation.y);
            }
            bounds = includeBoundsArea(bounds, groupFeatureBounds(group));

            cachedInteractionMap = map;
            cachedInteractionGroup = group;
            cachedInteractionBounds = bounds;
            return bounds;
        },

        panLimits() {
            const bounds = this.interactionBounds();
            const scale = this.fit?.scale * this.zoom;
            if (!bounds || !Number.isFinite(scale) || scale <= 0
                || this.canvasWidth < 1 || this.canvasHeight < 1) {
                return { minX: 0, maxX: 0, minY: 0, maxY: 0 };
            }

            const left = (this.canvasWidth / 2) + ((bounds.minX - this.fit.centerX) * scale);
            const right = (this.canvasWidth / 2) + ((bounds.maxX - this.fit.centerX) * scale);
            const top = (this.canvasHeight / 2) + ((bounds.minY - this.fit.centerY) * scale);
            const bottom = (this.canvasHeight / 2) + ((bounds.maxY - this.fit.centerY) * scale);
            const allowanceX = Math.min(PAN_EDGE_ALLOWANCE, this.canvasWidth / 2);
            const allowanceY = Math.min(PAN_EDGE_ALLOWANCE, this.canvasHeight / 2);
            const renderedWidth = right - left;
            const renderedHeight = bottom - top;

            let minX = this.canvasWidth - allowanceX - right;
            let maxX = allowanceX - left;
            let minY = this.canvasHeight - allowanceY - bottom;
            let maxY = allowanceY - top;
            if (renderedWidth <= this.canvasWidth - (allowanceX * 2)) {
                minX = (this.canvasWidth - left - right) / 2;
                maxX = minX;
            }
            if (renderedHeight <= this.canvasHeight - (allowanceY * 2)) {
                minY = (this.canvasHeight - top - bottom) / 2;
                maxY = minY;
            }

            return { minX, maxX, minY, maxY };
        },

        clampPan() {
            const limits = this.panLimits();
            this.panX = Math.max(limits.minX, Math.min(limits.maxX, finiteNumber(this.panX)));
            this.panY = Math.max(limits.minY, Math.min(limits.maxY, finiteNumber(this.panY)));
            return limits;
        },

        resetView(announce = true) {
            this.recalculateFit(true);
            if (announce) this.statusMessage = `Map view reset for ${this.zoneLabel}.`;
            this.invalidateBase();
        },

        zoomBy(factor, anchor = null) {
            if (!this.mapData) return;
            const oldZoom = this.zoom;
            const newZoom = clampZoom(oldZoom * factor, MIN_ZOOM, MAX_ZOOM);
            if (newZoom === oldZoom) return;

            const point = anchor ?? { x: this.canvasWidth / 2, y: this.canvasHeight / 2 };
            const before = this.toWorld(point.x, point.y);
            this.zoom = newZoom;
            const after = this.toScreen(before.x, before.y);
            this.panX += point.x - after.x;
            this.panY += point.y - after.y;
            this.clampPan();
            this.statusMessage = `Map zoom ${Math.round(this.zoom * 100)}%.`;
            this.invalidateBase();
        },

        async toggleFullscreen() {
            const shell = this.$refs.shell;
            if (!shell) return;

            try {
                if (document.fullscreenElement === shell) {
                    await document.exitFullscreen();
                } else {
                    await shell.requestFullscreen();
                }
                requestAnimationFrame(() => this.resizeCanvas());
            } catch (error) {
                this.statusMessage = 'Fullscreen mode is not available in this browser.';
            }
        },

        onWheel(event) {
            event.preventDefault();
            this.trackPointer(event);
            const rect = this.$refs.canvas.getBoundingClientRect();
            const factor = Math.exp(-event.deltaY * 0.0015);
            this.zoomBy(factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
        },

        onPointerDown(event) {
            if (!this.mapData || event.button !== 0) return;
            this.trackPointer(event, event.currentTarget);
            try {
                event.currentTarget?.setPointerCapture?.(event.pointerId);
            } catch {
                // A browser may reject capture if the pointer ended between dispatch and handling.
            }
            this.dragging = true;
            this.pointerMoved = false;
            this.pointerStart = {
                x: event.clientX,
                y: event.clientY,
                panX: this.panX,
                panY: this.panY,
            };
        },

        onPointerMove(event) {
            const canvas = this.$refs.canvas;
            if (!canvas || !this.mapData) return;
            this.trackPointer(event, canvas);

            if (this.dragging && this.pointerStart) {
                this.hoveredLocationId = null;
                const dx = event.clientX - this.pointerStart.x;
                const dy = event.clientY - this.pointerStart.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) this.pointerMoved = true;
                this.panX = this.pointerStart.panX + dx;
                this.panY = this.pointerStart.panY + dy;
                this.clampPan();
                this.invalidateBase();
                return;
            }

            const hit = this.hitTest(this.pointerX, this.pointerY);
            const nextHoveredId = hit?.id ?? null;
            if (String(nextHoveredId) === String(this.hoveredLocationId)) return;
            this.hoveredLocationId = nextHoveredId;
            canvas.style.cursor = hit ? 'pointer' : 'grab';
            this.refreshTooltipPosition();
            this.invalidateOverlay();
        },

        trackPointer(event, canvas = this.$refs.canvas) {
            if (!canvas || typeof canvas.getBoundingClientRect !== 'function') {
                this.pointerOnMap = false;
                return false;
            }
            const rect = canvas.getBoundingClientRect();
            const x = Number(event?.clientX) - rect.left;
            const y = Number(event?.clientY) - rect.top;
            if (!Number.isFinite(x) || !Number.isFinite(y)) {
                this.pointerOnMap = false;
                return false;
            }

            this.pointerX = x;
            this.pointerY = y;
            this.pointerOnMap = x >= 0 && y >= 0 && x <= rect.width && y <= rect.height;
            return this.pointerOnMap;
        },

        clearHover() {
            this.pointerOnMap = false;
            if (this.hoveredLocationId === null) return;
            this.hoveredLocationId = null;
            const canvas = this.$refs.canvas;
            if (canvas) canvas.style.cursor = this.mapData ? 'grab' : 'default';
            this.refreshTooltipPosition();
            this.invalidateOverlay();
        },

        onPointerUp(event) {
            const clearPointer = event.type === 'pointercancel' || event.pointerType === 'touch';
            if (!this.dragging) {
                if (clearPointer) this.pointerOnMap = false;
                return;
            }
            const selectOnRelease = event.type !== 'pointercancel' && !this.pointerMoved;
            try {
                const target = event.currentTarget;
                const canRelease = typeof target?.releasePointerCapture === 'function';
                const ownsCapture = typeof target?.hasPointerCapture !== 'function'
                    || target.hasPointerCapture(event.pointerId);
                if (canRelease && ownsCapture) {
                    target.releasePointerCapture(event.pointerId);
                }
            } catch {
                // Pointer capture can be lost implicitly before pointercancel arrives.
            } finally {
                this.dragging = false;
                this.pointerStart = null;
            }
            if (selectOnRelease) {
                const rect = this.$refs.canvas.getBoundingClientRect();
                const hit = this.hitTest(event.clientX - rect.left, event.clientY - rect.top);
                if (hit) this.selectLocation(hit.id, false);
            }
            if (clearPointer) this.pointerOnMap = false;
        },

        onLostPointerCapture() {
            if (!this.dragging && this.pointerStart === null) return;
            this.dragging = false;
            this.pointerMoved = false;
            this.pointerStart = null;
            this.scheduleDraw();
        },

        onKeydown(event) {
            if (!this.mapData) return;

            const panStep = event.shiftKey ? 80 : 32;
            const handled = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', '+', '=', '-', '0'].includes(event.key);
            if (!handled) return;
            event.preventDefault();

            if (event.key === 'ArrowLeft') this.panX += panStep;
            if (event.key === 'ArrowRight') this.panX -= panStep;
            if (event.key === 'ArrowUp') this.panY += panStep;
            if (event.key === 'ArrowDown') this.panY -= panStep;
            if (event.key === '+' || event.key === '=') this.zoomBy(1.25);
            if (event.key === '-') this.zoomBy(0.8);
            if (event.key === '0') this.resetView();
            this.clampPan();
            this.invalidateBase();
            this.queueUrlSync();
        },

        selectLocation(id, center = true) {
            const location = this.currentLocations.find((item) => String(item.id) === String(id));
            if (!location) return;
            if (String(location.id) !== String(this.selectedLocationId)) this.resetPathPreview(false);
            this.selectedLocationId = location.id;
            overlayDirty = true;
            this.statusMessage = `Selected ${this.coordinateLabel(location)} in ${this.zoneLabel}.`;
            this.flashSelection();

            if (center && this.mapData) this.focusLocation(location);
            else if (this.elevationFocus || this.showPaths) this.invalidateBase();
            else this.scheduleDraw();
            this.queueUrlSync();
        },

        focusPendingLocation() {
            if (this.pendingFocusId === null || !this.mapData
                || this.canvasWidth < 2 || this.canvasHeight < 2) return false;
            const location = this.currentLocations.find(
                (item) => String(item.id) === String(this.pendingFocusId),
            );
            if (!location || !hasFinitePosition(location)) {
                this.pendingFocusId = null;
                return false;
            }

            this.pendingFocusId = null;
            this.focusLocation(location, false);
            this.flashSelection();
            return true;
        },

        focusLocation(location, announce = false) {
            const point = locationMapPoint(location);
            if (!this.mapData || !point || this.canvasWidth < 2 || this.canvasHeight < 2) return false;
            const area = locationMapArea(location);
            const areaHasExtent = area && (area.maxX > area.minX || area.maxY > area.minY);
            let focusPoint = point;
            if (areaHasExtent) {
                const maximumSafePadding = Math.max(0, (Math.min(this.canvasWidth, this.canvasHeight) - 1) / 2);
                const padding = Math.min(FEATURE_FOCUS_PADDING, maximumSafePadding);
                const areaFit = fitBounds(area, this.canvasWidth, this.canvasHeight, padding);
                this.zoom = clampZoom(areaFit.scale / this.fit.scale, MIN_ZOOM, MAX_ZOOM);
                focusPoint = { x: areaFit.centerX, y: areaFit.centerY };
            } else {
                this.zoom = Math.max(this.zoom, 2.25);
            }
            this.panX = 0;
            this.panY = 0;
            const screen = this.toScreen(focusPoint.x, focusPoint.y);
            this.panX = this.canvasWidth / 2 - screen.x;
            this.panY = this.canvasHeight / 2 - screen.y;
            this.clampPan();
            if (announce) this.statusMessage = `Focused ${this.coordinateLabel(location)} in ${this.zoneLabel}.`;
            this.invalidateBase();
            return true;
        },

        flashSelection() {
            overlayDirty = true;
            if (this.selectedLocationId === null || this.reducedMotion) {
                selectionFlashStartedAt = -1;
                selectionFlashUntil = 0;
                this.scheduleDraw();
                return;
            }

            selectionFlashStartedAt = animationNow();
            selectionFlashUntil = selectionFlashStartedAt + SELECTION_FLASH_MS;
            this.scheduleDraw();
        },

        selectionPulse(now = animationNow()) {
            if (this.reducedMotion || selectionFlashUntil <= now || selectionFlashStartedAt < 0) {
                return null;
            }

            const elapsed = Math.max(0, now - selectionFlashStartedAt);
            const progress = Math.min(1, elapsed / SELECTION_FLASH_MS);
            const cycle = (progress * 2) % 1;
            return {
                radiusOffset: 3 + (cycle * 8),
                alpha: Math.max(0, (1 - cycle) * (1 - (progress * 0.55)) * 0.85),
            };
        },

        hasUsablePosition(location) {
            return hasFinitePosition(location);
        },

        pathForLocation(location) {
            const path = this.currentPaths[String(location?.path_grid ?? '')];
            return Array.isArray(path) ? path : [];
        },

        hasPathAssignment(location) {
            const gridId = Number(location?.path_grid);
            return Number.isSafeInteger(gridId) && gridId > 0;
        },

        hasDrawablePath(location) {
            const path = this.pathForLocation(location);
            const wanderType = this.pathMetaForLocation(location).wander_type;
            return path.length >= 2
                && path.length <= MAX_PATH_PREVIEW_WAYPOINTS
                && [3, 4, 6].includes(wanderType);
        },

        pathMetaForLocation(location) {
            const raw = this.currentPathMeta[String(location?.path_grid ?? '')];
            if (raw === null || typeof raw !== 'object' || Array.isArray(raw)) return {};
            const wanderType = Number(raw.wander_type);
            const pauseType = Number(raw.pause_type);
            return {
                wander_type: Number.isSafeInteger(wanderType) && wanderType >= 0 && wanderType <= 9 ? wanderType : 0,
                pause_type: Number.isSafeInteger(pauseType) && pauseType >= 0 && pauseType <= 2 ? pauseType : 1,
                wander_type_label: safeText(raw.wander_type_label, 80),
                pause_type_label: safeText(raw.pause_type_label, 80),
            };
        },

        pathPreviewTimeline() {
            const location = this.selectedLocation;
            const path = this.pathForLocation(location);
            const metadata = this.pathMetaForLocation(location);
            if (pathPreviewCache?.location === location
                && pathPreviewCache.path === path
                && pathPreviewCache.wanderType === metadata.wander_type
                && pathPreviewCache.pauseType === metadata.pause_type) {
                return pathPreviewCache.timeline;
            }

            const timeline = buildPathPreviewTimeline(location, path, metadata);
            pathPreviewCache = {
                location,
                path,
                wanderType: metadata.wander_type,
                pauseType: metadata.pause_type,
                timeline,
            };
            return timeline;
        },

        togglePathPreview() {
            if (this.pathPreviewPlaying) {
                this.pausePathPreview('Path preview paused.');
                return;
            }
            if (this.reducedMotion) {
                this.pathPreviewStatus = 'Path preview is disabled while reduced motion is enabled.';
                this.statusMessage = this.pathPreviewStatus;
                return;
            }
            const timeline = this.pathPreviewTimeline();
            if (!this.pathPreviewEnabled || !timeline) {
                this.pathPreviewStatus = 'No configured path is available for this spawn.';
                this.statusMessage = this.pathPreviewStatus;
                return;
            }

            if (!this.pathPreviewActive || (!timeline.loop && pathPreviewElapsedMs >= timeline.durationMs)) {
                pathPreviewElapsedMs = 0;
            }
            this.pathPreviewActive = true;
            this.pathPreviewPlaying = true;
            pathPreviewLastFrameAt = animationNow();
            this.pathPreviewStatus = `${timeline.approximate ? 'Representative' : 'Configured'} ${this.pathPreviewBehaviorLabel.toLowerCase()} preview playing.`;
            this.statusMessage = this.pathPreviewStatus;
            this.scheduleDraw();
        },

        pausePathPreview(message = 'Path preview paused.') {
            this.pathPreviewPlaying = false;
            pathPreviewLastFrameAt = -1;
            this.pathPreviewStatus = message;
            this.statusMessage = message;
            this.scheduleDraw();
        },

        resetPathPreview(announce = true) {
            const wasActive = this.pathPreviewActive || this.pathPreviewPlaying;
            this.pathPreviewActive = false;
            this.pathPreviewPlaying = false;
            this.pathPreviewStatus = '';
            pathPreviewElapsedMs = 0;
            pathPreviewLastFrameAt = -1;
            pathPreviewCache = null;
            if (announce) {
                this.statusMessage = 'Path preview reset.';
                this.scheduleDraw();
            } else if (wasActive) {
                this.scheduleDraw();
            }
        },

        pathPreviewFrame(now = animationNow()) {
            if (!this.pathPreviewActive) return null;
            const timeline = this.pathPreviewTimeline();
            if (!timeline) {
                this.resetPathPreview(false);
                return null;
            }

            if (this.pathPreviewPlaying) {
                const elapsed = pathPreviewLastFrameAt < 0 ? 0 : Math.max(0, Math.min(100, now - pathPreviewLastFrameAt));
                const speed = [1, 4, 10].includes(Number(this.pathPreviewSpeed)) ? Number(this.pathPreviewSpeed) : 4;
                pathPreviewElapsedMs += elapsed * speed;
                pathPreviewLastFrameAt = now;
            }

            if (timeline.loop) {
                pathPreviewElapsedMs %= timeline.durationMs;
            } else if (pathPreviewElapsedMs >= timeline.durationMs) {
                pathPreviewElapsedMs = timeline.durationMs;
                if (this.pathPreviewPlaying) {
                    this.pathPreviewPlaying = false;
                    pathPreviewLastFrameAt = -1;
                    const terminal = timeline.wanderType === 4 ? 'repop' : 'depop';
                    this.pathPreviewStatus = `One-way path complete (${terminal}).`;
                    this.statusMessage = this.pathPreviewStatus;
                }
            }

            const timelinePosition = Math.min(pathPreviewElapsedMs, Math.max(0, timeline.durationMs - 0.001));
            const event = timeline.events.find((candidate) => timelinePosition >= candidate.startMs
                && timelinePosition < candidate.endMs) ?? timeline.events[timeline.events.length - 1];
            const eventDuration = Math.max(1, event.endMs - event.startMs);
            const progress = event.kind === 'pause' ? 1 : Math.max(0, Math.min(1,
                (timelinePosition - event.startMs) / eventDuration,
            ));
            const point = {
                x: event.from.x + ((event.to.x - event.from.x) * progress),
                y: event.from.y + ((event.to.y - event.from.y) * progress),
                z: event.from.z + ((event.to.z - event.from.z) * progress),
            };
            if (this.pathPreviewPlaying) {
                const nextStatus = event.kind === 'pause'
                    ? `Paused at waypoint ${event.to.number}.`
                    : `Moving to waypoint ${event.to.number}.`;
                if (this.pathPreviewStatus !== nextStatus) this.pathPreviewStatus = nextStatus;
            }

            return { point, event, timeline };
        },

        coordinateLabel(location) {
            if (!hasFinitePosition(location)) return 'coordinates unavailable';
            return formatLocationCoordinates(location, this.coordinateOrder, 2);
        },

        async copyCoordinates(location = this.selectedLocation) {
            if (!location) return;
            if (!hasFinitePosition(location)) {
                this.copyMessage = 'Coordinates are unavailable for this spawn.';
                this.statusMessage = this.copyMessage;
                window.setTimeout(() => { this.copyMessage = ''; }, 2200);
                return;
            }

            const text = this.coordinateLabel(location);
            try {
                await navigator.clipboard.writeText(text);
                this.copyMessage = `Copied ${text}`;
            } catch (error) {
                this.copyMessage = 'Copy was blocked by the browser.';
            }
            this.statusMessage = this.copyMessage;
            window.setTimeout(() => { this.copyMessage = ''; }, 2200);
        },

        toScreen(x, y) {
            return worldToScreen(
                { x, y },
                this.fit,
                this.canvasWidth,
                this.canvasHeight,
                this.zoom,
                this.panX,
                this.panY,
            );
        },

        toWorld(x, y) {
            return screenToWorld(
                { x, y },
                this.fit,
                this.canvasWidth,
                this.canvasHeight,
                this.zoom,
                this.panX,
                this.panY,
            );
        },

        hitTest(x, y) {
            const radius = 13;
            let closest = null;
            let closestDistance = radius * radius;
            const entries = this.mappableEntries;

            // Point markers are painted above area fills, so they must win the
            // same overlap in hit testing.
            for (const entry of entries) {
                const screen = this.toScreen(entry.point.x, entry.point.y);
                const distance = ((screen.x - x) ** 2) + ((screen.y - y) ** 2);
                if (distance <= closestDistance) {
                    closest = entry.location;
                    closestDistance = distance;
                }
            }
            if (closest) return closest;

            const containingAreas = [];
            for (const entry of entries) {
                const { area, location } = entry;
                if (!area) continue;
                const first = this.toScreen(area.minX, area.minY);
                const second = this.toScreen(area.maxX, area.maxY);
                const minX = Math.min(first.x, second.x) - 4;
                const maxX = Math.max(first.x, second.x) + 4;
                const minY = Math.min(first.y, second.y) - 4;
                const maxY = Math.max(first.y, second.y) + 4;
                if (x >= minX && x <= maxX && y >= minY && y <= maxY) {
                    containingAreas.push({ location, size: Math.max(1, (maxX - minX) * (maxY - minY)) });
                }
            }
            if (containingAreas.length > 0) {
                containingAreas.sort((left, right) => left.size - right.size);
                return containingAreas[0].location;
            }
            return null;
        },

        invalidateBase() {
            baseDirty = true;
            overlayDirty = true;
            this.scheduleDraw();
        },

        invalidateOverlay() {
            overlayDirty = true;
            this.scheduleDraw();
        },

        scheduleDraw() {
            if (this.animationFrame) return;
            this.animationFrame = requestAnimationFrame(() => {
                this.animationFrame = null;
                this.draw();
            });
        },

        draw() {
            const canvas = this.$refs.canvas;
            if (!canvas) return;
            const context = canvas.getContext('2d');
            if (!context) return;

            context.save();
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.clearRect(0, 0, canvas.width, canvas.height);
            context.restore();

            if (!this.mapData) return;

            if (!this.drawCachedBase(context, canvas)) this.drawBaseLayers(context);
            const pulse = this.selectionPulse();
            if (!pulse || !this.drawCachedOverlays(context, canvas)) this.drawStaticOverlays(context);
            if (pulse) this.drawSelectionPulse(context, pulse);
            const pathPreview = this.pathPreviewFrame();
            if (pathPreview) this.drawPathPreviewActor(context, pathPreview);
            if (this.pathPreviewPlaying) this.scheduleDraw();
        },

        drawBaseLayers(context) {
            this.drawGeometry(context);
            if (this.showZoneLines) this.drawZoneAnnotations(context);
            if (this.showMapPoints) this.drawMapPoints(context);
            if (this.showPaths) this.drawMovement(context);
        },

        drawCachedBase(context, canvas) {
            if (typeof document === 'undefined' || typeof context.drawImage !== 'function') return false;
            if (!cachedBaseCanvas) cachedBaseCanvas = document.createElement('canvas');
            if (cachedBaseCanvas.width !== canvas.width || cachedBaseCanvas.height !== canvas.height) {
                cachedBaseCanvas.width = canvas.width;
                cachedBaseCanvas.height = canvas.height;
                baseDirty = true;
            }
            if (baseDirty) {
                const baseContext = cachedBaseCanvas.getContext('2d');
                if (!baseContext) return false;
                baseContext.setTransform(1, 0, 0, 1, 0, 0);
                baseContext.clearRect(0, 0, cachedBaseCanvas.width, cachedBaseCanvas.height);
                const ratio = this.canvasWidth > 0 ? cachedBaseCanvas.width / this.canvasWidth : 1;
                baseContext.setTransform(ratio, 0, 0, ratio, 0, 0);
                this.drawBaseLayers(baseContext);
                baseDirty = false;
            }

            context.save();
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.drawImage(cachedBaseCanvas, 0, 0);
            context.restore();
            return true;
        },

        drawStaticOverlays(context) {
            this.drawAreas(context);
            this.drawLocations(context);
            this.drawLocationLabels(context);
        },

        drawCachedOverlays(context, canvas) {
            if (typeof document === 'undefined' || typeof context.drawImage !== 'function') return false;
            if (!cachedOverlayCanvas) cachedOverlayCanvas = document.createElement('canvas');
            if (cachedOverlayCanvas.width !== canvas.width || cachedOverlayCanvas.height !== canvas.height) {
                cachedOverlayCanvas.width = canvas.width;
                cachedOverlayCanvas.height = canvas.height;
                overlayDirty = true;
            }
            if (overlayDirty) {
                const overlayContext = cachedOverlayCanvas.getContext('2d');
                if (!overlayContext) return false;
                overlayContext.setTransform(1, 0, 0, 1, 0, 0);
                overlayContext.clearRect(0, 0, cachedOverlayCanvas.width, cachedOverlayCanvas.height);
                const ratio = this.canvasWidth > 0 ? cachedOverlayCanvas.width / this.canvasWidth : 1;
                overlayContext.setTransform(ratio, 0, 0, ratio, 0, 0);
                this.drawStaticOverlays(overlayContext);
                overlayDirty = false;
            }

            context.save();
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.drawImage(cachedOverlayCanvas, 0, 0);
            context.restore();
            return true;
        },

        drawGeometry(context) {
            const selectedZ = locationMapPoint(this.selectedLocation)?.z ?? 0;
            const range = Math.max(5, finiteNumber(this.elevationRange, 35));

            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = Math.max(0.55, Math.min(1.4, this.zoom * 0.45));

            for (const group of this.mapData.groups) {
                const segments = group.segments;
                context.beginPath();
                let hasVisibleSegment = false;

                for (let index = 0; index < segments.length; index += 6) {
                    const averageZ = (segments[index + 2] + segments[index + 5]) / 2;
                    const zDistance = Math.abs(averageZ - selectedZ);
                    if (this.elevationFocus && zDistance > range) continue;

                    const start = this.toScreen(segments[index], segments[index + 1]);
                    const end = this.toScreen(segments[index + 3], segments[index + 4]);
                    context.moveTo(start.x, start.y);
                    context.lineTo(end.x, end.y);
                    hasVisibleSegment = true;
                }

                if (hasVisibleSegment) {
                    const alpha = this.elevationFocus ? 0.72 : 0.82;
                    context.strokeStyle = cssColor(group.color, true, alpha);
                    context.stroke();
                }
            }
        },

        drawZoneAnnotations(context) {
            const annotations = this.visibleZoneAnnotations;
            if (annotations.length === 0) return;

            const occupied = new Set();
            context.save();
            context.font = '600 10.5px Instrument Sans, ui-sans-serif, system-ui, sans-serif';
            context.textBaseline = 'middle';
            context.lineJoin = 'round';

            for (const annotation of annotations) {
                const screen = this.toScreen(annotation.x, annotation.y);
                if (screen.x < -220 || screen.y < -30
                    || screen.x > this.canvasWidth + 30 || screen.y > this.canvasHeight + 30) continue;

                const color = annotation.kind === 'portal' ? '#c084fc' : '#fb923c';
                this.markerPath(context, annotation.kind === 'portal' ? 'diamond' : 'triangle', screen.x, screen.y, 4.1);
                context.fillStyle = color;
                context.fill();
                context.lineWidth = 1.25;
                context.strokeStyle = '#fff7ed';
                context.stroke();

                const cell = `${Math.round(screen.x / 100)}:${Math.round(screen.y / 20)}`;
                if (occupied.has(cell)) continue;
                occupied.add(cell);
                const label = annotation.label.replaceAll('_', ' ');
                context.lineWidth = 3.25;
                context.strokeStyle = 'rgba(7, 12, 24, .96)';
                context.fillStyle = color;
                context.strokeText(label, screen.x + 7, screen.y);
                context.fillText(label, screen.x + 7, screen.y);
            }
            context.restore();
        },

        drawMapPoints(context) {
            const annotationCoordinates = new Set((this.showZoneLines ? this.visibleZoneAnnotations : [])
                .map((annotation) => `${annotation.x}:${annotation.y}`));
            context.save();
            context.font = '500 11px Instrument Sans, ui-sans-serif, system-ui, sans-serif';
            context.textBaseline = 'bottom';
            context.lineWidth = 3;

            for (const point of this.mapData.points) {
                if (annotationCoordinates.has(`${point.x}:${point.y}`)) continue;
                const screen = this.toScreen(point.x, point.y);
                if (screen.x < -120 || screen.y < -30 || screen.x > this.canvasWidth + 120 || screen.y > this.canvasHeight + 30) continue;

                const label = point.label.replaceAll('_', ' ');
                context.strokeStyle = 'rgba(7, 12, 24, .92)';
                context.fillStyle = cssColor(point.color, true, 1);
                context.strokeText(label, screen.x + 5, screen.y - 4);
                context.fillText(label, screen.x + 5, screen.y - 4);
            }
            context.restore();
        },

        drawMovement(context) {
            const location = this.selectedLocation;
            if (!location) return;

            context.save();
            context.setLineDash([7, 5]);
            context.lineWidth = 2;

            const path = this.pathForLocation(location);
            const canDrawOrderedPath = this.hasDrawablePath(location);
            if (canDrawOrderedPath && path.length > 0) {
                const waypoints = path
                    .map((waypoint) => dbPositionMapPoint(waypoint))
                    .filter((waypoint) => waypoint !== null);

                if (waypoints.length > 0) {
                    const firstWaypoint = waypoints[0];
                    const startScreen = this.toScreen(firstWaypoint.x, firstWaypoint.y);
                    context.beginPath();
                    context.moveTo(startScreen.x, startScreen.y);
                    for (const waypoint of waypoints.slice(1)) {
                        const screen = this.toScreen(waypoint.x, waypoint.y);
                        context.lineTo(screen.x, screen.y);
                    }
                    context.strokeStyle = 'rgba(56, 189, 248, .82)';
                    context.stroke();
                }
            }

            const roam = location.roam;
            if (roam && [roam.min_x, roam.max_x, roam.min_y, roam.max_y].every(isFiniteCoordinate)) {
                const corners = [
                    dbToBrewall(Number(roam.min_x), Number(roam.min_y)),
                    dbToBrewall(Number(roam.max_x), Number(roam.min_y)),
                    dbToBrewall(Number(roam.max_x), Number(roam.max_y)),
                    dbToBrewall(Number(roam.min_x), Number(roam.max_y)),
                ];
                context.beginPath();
                corners.forEach(([x, y], index) => {
                    const screen = this.toScreen(x, y);
                    if (index === 0) context.moveTo(screen.x, screen.y);
                    else context.lineTo(screen.x, screen.y);
                });
                context.closePath();
                context.fillStyle = 'rgba(56, 189, 248, .08)';
                context.strokeStyle = 'rgba(56, 189, 248, .65)';
                context.fill();
                context.stroke();
            }
            context.restore();
        },

        drawLocations(context) {
            for (const { location, point } of this.mappableEntries) {
                const screen = this.toScreen(point.x, point.y);
                if (screen.x < -24 || screen.y < -24 || screen.x > this.canvasWidth + 24 || screen.y > this.canvasHeight + 24) continue;
                const selected = String(location.id) === String(this.selectedLocationId);
                const hovered = String(location.id) === String(this.hoveredLocationId);
                const radius = selected ? 6 : hovered ? 5.25 : 4.25;
                const color = this.locationColor(location);

                context.beginPath();
                context.arc(screen.x, screen.y, radius + 2.5, 0, Math.PI * 2);
                context.fillStyle = selected ? 'rgba(251, 191, 36, .22)' : hexToRgba(color, hovered ? 0.24 : 0.14);
                context.fill();

                this.markerPath(context, this.locationShape(location), screen.x, screen.y, radius);
                context.fillStyle = color;
                context.fill();
                context.lineWidth = selected ? 2 : 1.35;
                context.strokeStyle = selected ? '#fbbf24' : '#fff7ed';
                context.stroke();

                context.beginPath();
                context.arc(screen.x, screen.y, 1.15, 0, Math.PI * 2);
                context.fillStyle = '#172033';
                context.fill();

                if (this.hasPathAssignment(location)) {
                    context.beginPath();
                    context.arc(screen.x + radius * 0.8, screen.y - radius * 0.8, 2.15, 0, Math.PI * 2);
                    context.fillStyle = '#38bdf8';
                    context.fill();
                    context.lineWidth = 1;
                    context.strokeStyle = '#e0f2fe';
                    context.stroke();
                }
            }
        },

        drawSelectionPulse(context, pulse) {
            const selectedEntry = this.mappableEntries.find(
                ({ location }) => String(location.id) === String(this.selectedLocationId),
            );
            if (!selectedEntry) return false;
            const screen = this.toScreen(selectedEntry.point.x, selectedEntry.point.y);
            if (screen.x < -24 || screen.y < -24
                || screen.x > this.canvasWidth + 24 || screen.y > this.canvasHeight + 24) return false;

            context.beginPath();
            context.arc(screen.x, screen.y, 6 + pulse.radiusOffset, 0, Math.PI * 2);
            context.strokeStyle = `rgba(251, 191, 36, ${pulse.alpha})`;
            context.lineWidth = 2;
            context.stroke();
            this.scheduleDraw();
            return true;
        },

        drawPathPreviewActor(context, preview) {
            const point = dbPositionMapPoint(preview?.point);
            if (!point) return false;
            const screen = this.toScreen(point.x, point.y);
            if (screen.x < -24 || screen.y < -24
                || screen.x > this.canvasWidth + 24 || screen.y > this.canvasHeight + 24) return false;

            context.save();
            context.beginPath();
            context.arc(screen.x, screen.y, 8, 0, Math.PI * 2);
            context.fillStyle = 'rgba(14, 165, 233, .22)';
            context.fill();
            context.strokeStyle = 'rgba(125, 211, 252, .9)';
            context.lineWidth = 1.5;
            context.stroke();

            this.markerPath(context, preview.event.kind === 'pause' ? 'square' : 'compass', screen.x, screen.y, 5.2);
            context.fillStyle = preview.event.kind === 'pause' ? '#fbbf24' : '#38bdf8';
            context.fill();
            context.strokeStyle = '#f8fafc';
            context.lineWidth = 1.4;
            context.stroke();
            context.restore();
            return true;
        },

        drawAreas(context) {
            context.save();
            for (const { location, area } of this.mappableEntries) {
                if (!area) continue;
                const first = this.toScreen(area.minX, area.minY);
                const second = this.toScreen(area.maxX, area.maxY);
                const x = Math.min(first.x, second.x);
                const y = Math.min(first.y, second.y);
                const width = Math.max(2, Math.abs(second.x - first.x));
                const height = Math.max(2, Math.abs(second.y - first.y));
                if (x > this.canvasWidth || y > this.canvasHeight || x + width < 0 || y + height < 0) continue;
                const selected = String(location.id) === String(this.selectedLocationId);
                const hovered = String(location.id) === String(this.hoveredLocationId);
                const color = this.locationColor(location);
                context.beginPath();
                context.rect(x, y, width, height);
                context.fillStyle = hexToRgba(color, selected ? 0.24 : hovered ? 0.18 : 0.09);
                context.strokeStyle = selected ? '#fbbf24' : hexToRgba(color, hovered ? 0.95 : 0.68);
                context.lineWidth = selected || hovered ? 2.5 : 1.5;
                context.fill();
                context.stroke();
            }
            context.restore();
        },

        drawLocationLabels(context) {
            const occupied = new Set();
            context.save();
            context.font = '600 11px Instrument Sans, ui-sans-serif, system-ui, sans-serif';
            context.textBaseline = 'middle';
            context.lineWidth = 3.5;
            for (const { location, point } of this.mappableEntries) {
                const hovered = String(location.id) === String(this.hoveredLocationId);
                const selected = String(location.id) === String(this.selectedLocationId);
                if (!hovered && !selected && !location.show_label) continue;
                const screen = this.toScreen(point.x, point.y);
                if (screen.x < -200 || screen.y < -30 || screen.x > this.canvasWidth + 20 || screen.y > this.canvasHeight + 30) continue;
                const label = safeText(location.label, 90);
                if (!label) continue;
                const cell = `${Math.round(screen.x / 90)}:${Math.round(screen.y / 22)}`;
                if (!hovered && !selected && occupied.has(cell)) continue;
                occupied.add(cell);
                context.strokeStyle = 'rgba(7, 12, 24, .96)';
                context.fillStyle = selected ? '#fbbf24' : this.locationColor(location);
                context.strokeText(label, screen.x + 8, screen.y - 1);
                context.fillText(label, screen.x + 8, screen.y - 1);
            }
            context.restore();
        },

        locationColor(location) {
            const visualKind = this.locationVisualKind(location);
            return this.layers.find((layer) => layer.id === visualKind)?.color ?? '#fb7185';
        },

        locationVisualKind(location) {
            if (location?.kind && this.activeLayers[location.kind]) return location.kind;
            const visibleTrait = Array.isArray(location?.layers)
                ? location.layers.find((layer) => this.activeLayers[layer])
                : null;

            return visibleTrait ?? location?.kind ?? 'npcs';
        },

        locationShape(location) {
            const kind = this.locationVisualKind(location);
            const configured = this.layers.find((layer) => layer.id === kind)?.shape;
            return markerShape(configured, DEFAULT_MARKER_SHAPES[kind] ?? 'circle');
        },

        markerPath(context, requestedShape, x, y, radius) {
            const shape = markerShape(requestedShape, DEFAULT_MARKER_SHAPES[requestedShape] ?? 'circle');
            context.beginPath();
            if (shape === 'diamond') {
                context.moveTo(x, y - radius);
                context.lineTo(x + radius, y);
                context.lineTo(x, y + radius);
                context.lineTo(x - radius, y);
                context.closePath();
                return;
            }
            if (shape === 'square') {
                context.rect(x - radius * 0.78, y - radius * 0.78, radius * 1.56, radius * 1.56);
                return;
            }
            if (shape === 'triangle') {
                context.moveTo(x, y - radius);
                context.lineTo(x + radius * 0.9, y + radius * 0.75);
                context.lineTo(x - radius * 0.9, y + radius * 0.75);
                context.closePath();
                return;
            }
            const vertices = shape === 'hexagon' ? 6 : shape === 'pentagon' ? 5 : 0;
            if (vertices > 0) {
                for (let index = 0; index < vertices; index += 1) {
                    const angle = (-Math.PI / 2) + ((Math.PI * 2 * index) / vertices);
                    const pointX = x + (Math.cos(angle) * radius);
                    const pointY = y + (Math.sin(angle) * radius);
                    if (index === 0) context.moveTo(pointX, pointY);
                    else context.lineTo(pointX, pointY);
                }
                context.closePath();
                return;
            }
            if (shape === 'star' || shape === 'compass') {
                const points = shape === 'star' ? 5 : 4;
                const innerRatio = shape === 'star' ? 0.43 : 0.3;
                for (let index = 0; index < points * 2; index += 1) {
                    const pointRadius = index % 2 === 0 ? radius : radius * innerRatio;
                    const angle = (-Math.PI / 2) + ((Math.PI * index) / points);
                    const pointX = x + (Math.cos(angle) * pointRadius);
                    const pointY = y + (Math.sin(angle) * pointRadius);
                    if (index === 0) context.moveTo(pointX, pointY);
                    else context.lineTo(pointX, pointY);
                }
                context.closePath();
                return;
            }
            if (shape === 'cross') {
                const arm = radius * 0.38;
                context.moveTo(x - arm, y - radius);
                context.lineTo(x + arm, y - radius);
                context.lineTo(x + arm, y - arm);
                context.lineTo(x + radius, y - arm);
                context.lineTo(x + radius, y + arm);
                context.lineTo(x + arm, y + arm);
                context.lineTo(x + arm, y + radius);
                context.lineTo(x - arm, y + radius);
                context.lineTo(x - arm, y + arm);
                context.lineTo(x - radius, y + arm);
                context.lineTo(x - radius, y - arm);
                context.lineTo(x - arm, y - arm);
                context.closePath();
                return;
            }
            context.arc(x, y, radius, 0, Math.PI * 2);
        },
    };
}
