import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import {
    EQM1_DEFAULT_LIMITS,
    EQM1_FORMAT,
    EqMapFormatError,
    clampZoom,
    dbToBrewall,
    fitBounds,
    formatCoordinates,
    parseEqMap,
    parseEqMapBinary,
    screenToWorld,
    worldToScreen,
} from '../../resources/js/maps/eq-map-format.js';
import npcLocationMap, {
    hasFinitePosition,
    locationMapPoint,
} from '../../resources/js/components/npc-location-map.js';

const encoder = new TextEncoder();

const DEFAULT_BOUNDS = Object.freeze({
    minX: -10,
    minY: -20,
    minZ: -5,
    maxX: 30,
    maxY: 40,
    maxZ: 15,
});

function buildEqm1({
    magic = 'EQM1',
    version = 1,
    bounds = DEFAULT_BOUNDS,
    groups = [{ r: 12, g: 34, b: 56, segments: [-10, -20, -5, 30, 40, 15] }],
    points = [{ x: 4.5, y: 6.25, z: -1.5, r: 200, g: 150, b: 100, size: 3, label: 'Banker ☕' }],
    groupReserved = 0,
    pointReserved = 0,
    paddingByte = 0,
    declaredGroupCount = groups.length,
    declaredSegmentCount = groups.reduce((sum, group) => sum + (group.segments.length / 6), 0),
    declaredPointCount = points.length,
} = {}) {
    const labels = points.map((point) => encoder.encode(point.label));
    const groupBytes = groups.reduce(
        (sum, group) => sum + 8 + (group.segments.length * Float32Array.BYTES_PER_ELEMENT),
        0,
    );
    const pointBytes = labels.reduce(
        (sum, label) => sum + 20 + label.byteLength + paddingFor(label.byteLength),
        0,
    );
    const buffer = new ArrayBuffer(40 + groupBytes + pointBytes);
    const bytes = new Uint8Array(buffer);
    const view = new DataView(buffer);

    bytes.set(encoder.encode(magic).subarray(0, 4), 0);
    view.setUint16(4, version, true);
    view.setUint16(6, declaredGroupCount, true);
    view.setUint32(8, declaredSegmentCount, true);
    view.setUint32(12, declaredPointCount, true);

    [
        bounds.minX,
        bounds.minY,
        bounds.minZ,
        bounds.maxX,
        bounds.maxY,
        bounds.maxZ,
    ].forEach((value, index) => view.setFloat32(16 + (index * 4), value, true));

    let offset = 40;
    for (const group of groups) {
        bytes[offset] = group.r;
        bytes[offset + 1] = group.g;
        bytes[offset + 2] = group.b;
        bytes[offset + 3] = groupReserved;
        view.setUint32(offset + 4, group.segments.length / 6, true);
        offset += 8;

        for (const coordinate of group.segments) {
            view.setFloat32(offset, coordinate, true);
            offset += 4;
        }
    }

    points.forEach((point, pointIndex) => {
        const label = labels[pointIndex];
        view.setFloat32(offset, point.x, true);
        view.setFloat32(offset + 4, point.y, true);
        view.setFloat32(offset + 8, point.z, true);
        bytes[offset + 12] = point.r;
        bytes[offset + 13] = point.g;
        bytes[offset + 14] = point.b;
        bytes[offset + 15] = point.size;
        view.setUint16(offset + 16, label.byteLength, true);
        view.setUint16(offset + 18, pointReserved, true);
        offset += 20;
        bytes.set(label, offset);
        offset += label.byteLength;

        const padding = paddingFor(label.byteLength);
        bytes.fill(paddingByte, offset, offset + padding);
        offset += padding;
    });

    return buffer;
}

function paddingFor(length) {
    return (4 - (length % 4)) % 4;
}

function cloneBuffer(buffer, extraBytes = 0) {
    const copy = new Uint8Array(buffer.byteLength + extraBytes);
    copy.set(new Uint8Array(buffer));
    return copy.buffer;
}

function expectFormatError(action, code, messagePattern) {
    assert.throws(action, (error) => {
        assert.ok(error instanceof EqMapFormatError);
        assert.equal(error.code, code);
        assert.match(error.message, messagePattern);
        assert.equal(typeof error.offset, 'number');
        return true;
    });
}

describe('EQM1 decoder', () => {
    test('publishes the binary contract constants', () => {
        assert.deepEqual(EQM1_FORMAT, {
            magic: 'EQM1',
            version: 1,
            headerBytes: 40,
            groupHeaderBytes: 8,
            segmentBytes: 24,
            pointHeaderBytes: 20,
        });
        assert.ok(EQM1_DEFAULT_LIMITS.maxBytes > 1_000_000);
        assert.equal(parseEqMapBinary, parseEqMap);
    });

    test('parses groups, segment records, UTF-8 points, and bounds', () => {
        const buffer = buildEqm1({
            groups: [
                { r: 12, g: 34, b: 56, segments: [-10, -20, -5, 30, 40, 15] },
                { r: 1, g: 2, b: 3, segments: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11] },
            ],
        });

        const map = parseEqMap(buffer);

        assert.equal(map.version, 1);
        assert.deepEqual(map.bounds, DEFAULT_BOUNDS);
        assert.equal(map.colorGroupCount, 2);
        assert.equal(map.totalSegmentCount, 3);
        assert.equal(map.segmentCount, 3);
        assert.equal(map.pointCount, 1);
        assert.equal(map.byteLength, buffer.byteLength);
        assert.deepEqual(
            map.groups.map(({ color, count }) => ({ color, count })),
            [
                { color: [12, 34, 56], count: 1 },
                { color: [1, 2, 3], count: 2 },
            ],
        );
        assert.deepEqual(Array.from(map.groups[0].segments), [-10, -20, -5, 30, 40, 15]);
        assert.deepEqual(map.points[0], {
            x: 4.5,
            y: 6.25,
            z: -1.5,
            color: [200, 150, 100],
            size: 3,
            label: 'Banker ☕',
        });
    });

    test('returns zero-copy Float32Array segment views for aligned inputs', () => {
        const buffer = buildEqm1({ points: [] });
        const map = parseEqMap(buffer);

        assert.ok(map.groups[0].segments instanceof Float32Array);
        assert.equal(map.groups[0].segments.buffer, buffer);
        assert.equal(map.groups[0].segments.byteOffset, 48);
    });

    test('copies segment values safely when the input view is unaligned', () => {
        const buffer = buildEqm1({ points: [] });
        const wrapper = new Uint8Array(buffer.byteLength + 1);
        wrapper.set(new Uint8Array(buffer), 1);
        const input = wrapper.subarray(1);

        const map = parseEqMap(input);

        assert.deepEqual(Array.from(map.groups[0].segments), [-10, -20, -5, 30, 40, 15]);
        assert.notEqual(map.groups[0].segments.buffer, wrapper.buffer);
    });

    test('accepts DataView and Uint8Array inputs without reading adjacent bytes', () => {
        const buffer = buildEqm1({ points: [] });
        const wrapper = new Uint8Array(buffer.byteLength + 8);
        wrapper.fill(0xaa);
        wrapper.set(new Uint8Array(buffer), 4);

        assert.equal(parseEqMap(wrapper.subarray(4, 4 + buffer.byteLength)).totalSegmentCount, 1);
        assert.equal(parseEqMap(new DataView(wrapper.buffer, 4, buffer.byteLength)).totalSegmentCount, 1);
    });

    test('supports valid empty maps with finite ordered bounds', () => {
        const map = parseEqMap(buildEqm1({ groups: [], points: [] }));
        assert.equal(map.colorGroupCount, 0);
        assert.equal(map.totalSegmentCount, 0);
        assert.equal(map.pointCount, 0);
        assert.deepEqual(map.groups, []);
        assert.deepEqual(map.points, []);
    });

    test('rejects non-buffer inputs, short headers, bad magic, and versions', () => {
        assert.throws(() => parseEqMap('EQM1'), /ArrayBuffer/);
        expectFormatError(
            () => parseEqMap(new Uint8Array(39)),
            'ERR_EQM_TRUNCATED',
            /header requires 40 bytes/,
        );

        const badMagic = buildEqm1();
        new Uint8Array(badMagic)[0] = 0x58;
        expectFormatError(() => parseEqMap(badMagic), 'ERR_EQM_MAGIC', /magic/);

        const badVersion = buildEqm1();
        new DataView(badVersion).setUint16(4, 2, true);
        expectFormatError(() => parseEqMap(badVersion), 'ERR_EQM_VERSION', /version 2/);
    });

    test('rejects truncated group, point, label, and padding data', () => {
        const valid = buildEqm1();
        expectFormatError(
            () => parseEqMap(valid.slice(0, valid.byteLength - 1)),
            'ERR_EQM_TRUNCATED',
            /Truncated|require at least/,
        );

        const labelTruncated = cloneBuffer(valid);
        new DataView(labelTruncated).setUint16(72 + 16, 100, true);
        expectFormatError(
            () => parseEqMap(labelTruncated),
            'ERR_EQM_TRUNCATED',
            /point 0 label/,
        );

        const pointHeaderTruncated = buildEqm1({ groups: [], points: [] });
        new DataView(pointHeaderTruncated).setUint32(12, 1, true);
        expectFormatError(
            () => parseEqMap(pointHeaderTruncated),
            'ERR_EQM_TRUNCATED',
            /counts require at least/,
        );
    });

    test('rejects nonzero reserved fields, padding, and trailing bytes', () => {
        expectFormatError(
            () => parseEqMap(buildEqm1({ groupReserved: 1 })),
            'ERR_EQM_RESERVED',
            /Color group 0 reserved/,
        );
        expectFormatError(
            () => parseEqMap(buildEqm1({ pointReserved: 1 })),
            'ERR_EQM_RESERVED',
            /Point 0 reserved/,
        );
        expectFormatError(
            () => parseEqMap(buildEqm1({ points: [{
                x: 0, y: 0, z: 0, r: 0, g: 0, b: 0, size: 1, label: 'x',
            }], paddingByte: 1 })),
            'ERR_EQM_PADDING',
            /padding must be zero/,
        );
        expectFormatError(
            () => parseEqMap(cloneBuffer(buildEqm1(), 1)),
            'ERR_EQM_TRAILING_BYTES',
            /1 trailing byte/,
        );
    });

    test('rejects invalid UTF-8 labels', () => {
        const buffer = buildEqm1({
            groups: [],
            points: [{ x: 0, y: 0, z: 0, r: 0, g: 0, b: 0, size: 1, label: 'aa' }],
        });
        const labelOffset = 40 + 20;
        new Uint8Array(buffer).set([0xc3, 0x28], labelOffset);

        expectFormatError(() => parseEqMap(buffer), 'ERR_EQM_UTF8', /valid UTF-8/);
    });

    test('rejects non-finite and inverted bounds', () => {
        const nanBounds = buildEqm1();
        new DataView(nanBounds).setFloat32(16, Number.NaN, true);
        expectFormatError(() => parseEqMap(nanBounds), 'ERR_EQM_BOUNDS', /minX must be finite/);

        expectFormatError(
            () => parseEqMap(buildEqm1({ bounds: { ...DEFAULT_BOUNDS, minY: 50 } })),
            'ERR_EQM_BOUNDS',
            /minY cannot exceed maxY/,
        );
    });

    test('rejects non-finite segment and point coordinates with byte offsets', () => {
        const nanSegment = buildEqm1();
        new DataView(nanSegment).setFloat32(48, Number.NaN, true);
        expectFormatError(
            () => parseEqMap(nanSegment),
            'ERR_EQM_COORDINATE',
            /segment coordinate.*byte 48/,
        );

        const infinitePoint = buildEqm1();
        new DataView(infinitePoint).setFloat32(72 + 4, Number.POSITIVE_INFINITY, true);
        expectFormatError(
            () => parseEqMap(infinitePoint),
            'ERR_EQM_COORDINATE',
            /Point 0.*byte 76/,
        );
    });

    test('enforces byte, group, segment, point, and label ceilings before work', () => {
        const valid = buildEqm1();
        expectFormatError(
            () => parseEqMap(valid, { maxBytes: valid.byteLength - 1 }),
            'ERR_EQM_LIMIT',
            /payload.*limit/,
        );
        expectFormatError(
            () => parseEqMap(valid, { maxColorGroups: 0 }),
            'ERR_EQM_LIMIT',
            /color group count 1/,
        );
        expectFormatError(
            () => parseEqMap(valid, { maxSegments: 0 }),
            'ERR_EQM_LIMIT',
            /segment count 1/,
        );
        expectFormatError(
            () => parseEqMap(valid, { maxPoints: 0 }),
            'ERR_EQM_LIMIT',
            /point count 1/,
        );
        expectFormatError(
            () => parseEqMap(valid, { maxLabelBytes: 2 }),
            'ERR_EQM_LIMIT',
            /point label byte count/,
        );

        const oversizedHeader = buildEqm1({ groups: [], points: [] });
        new DataView(oversizedHeader).setUint32(8, EQM1_DEFAULT_LIMITS.maxSegments + 1, true);
        expectFormatError(
            () => parseEqMap(oversizedHeader),
            'ERR_EQM_LIMIT',
            /segment count/,
        );

        assert.throws(
            () => parseEqMap(valid, { limits: { maxBytes: -1 } }),
            /maxBytes must be a non-negative safe integer/,
        );
    });

    test('requires group segment counts to exactly equal the header total', () => {
        const tooManyInGroup = buildEqm1();
        new DataView(tooManyInGroup).setUint32(40 + 4, 2, true);
        expectFormatError(
            () => parseEqMap(tooManyInGroup),
            'ERR_EQM_COUNT',
            /exceed declared total/,
        );

        const tooFewBuffer = cloneBuffer(buildEqm1({ points: [] }), 24);
        new DataView(tooFewBuffer).setUint32(8, 2, true);
        expectFormatError(
            () => parseEqMap(tooFewBuffer),
            'ERR_EQM_COUNT',
            /contain 1 segments.*declares 2/,
        );
    });
});

describe('map coordinate helpers', () => {
    test('converts EQEmu DB coordinates into Brewall axes without swapping them', () => {
        assert.deepEqual(dbToBrewall(-6441, 1025), [6441, -1025]);
        assert.deepEqual(dbToBrewall(0, -0), [0, 0]);
        assert.throws(() => dbToBrewall(Number.NaN, 0), /x must be a finite number/);
    });

    test('fits bounds with aspect ratio and symmetric padding', () => {
        const transform = fitBounds(
            { minX: 0, minY: 0, maxX: 100, maxY: 100 },
            200,
            100,
            10,
        );

        assert.deepEqual(transform, {
            centerX: 50,
            centerY: 50,
            scale: 0.8,
            viewportWidth: 200,
            viewportHeight: 100,
            padding: 10,
        });
        assert.deepEqual(worldToScreen(0, 0, transform), [60, 10]);
        assert.deepEqual(worldToScreen(100, 100, transform), [140, 90]);
    });

    test('accepts viewport options and centers degenerate bounds', () => {
        const transform = fitBounds(
            { minX: 7, minY: 9, maxX: 7, maxY: 9 },
            { width: 320, height: 180, padding: 20 },
        );

        assert.equal(transform.scale, 1);
        assert.deepEqual(worldToScreen(7, 9, transform), [160, 90]);
        assert.throws(
            () => fitBounds(DEFAULT_BOUNDS, 100, 100, 50),
            /Padding must leave a positive viewport area/,
        );
    });

    test('projects and unprojects coordinates at arbitrary zoom without Y inversion', () => {
        const transform = fitBounds(DEFAULT_BOUNDS, 800, 600, 24);
        const world = [11.75, -2.125];
        const screen = worldToScreen(world[0], world[1], transform, 2.5);
        const roundTrip = screenToWorld(screen[0], screen[1], transform, 2.5);

        assert.ok(Math.abs(roundTrip[0] - world[0]) < 1e-12);
        assert.ok(Math.abs(roundTrip[1] - world[1]) < 1e-12);
        assert.ok(worldToScreen(0, 10, transform)[1] > worldToScreen(0, 0, transform)[1]);
    });

    test('supports object projections with explicit viewport pan and zoom', () => {
        const transform = fitBounds(DEFAULT_BOUNDS, 800, 600, 24);
        const world = { x: 11.75, y: -2.125 };
        const screen = worldToScreen(world, transform, 800, 600, 2.5, 17, -9);
        const roundTrip = screenToWorld(screen, transform, 800, 600, 2.5, 17, -9);

        assert.ok(Math.abs(roundTrip.x - world.x) < 1e-12);
        assert.ok(Math.abs(roundTrip.y - world.y) < 1e-12);
        assert.deepEqual(
            worldToScreen({ x: transform.centerX, y: transform.centerY }, transform),
            { x: 400, y: 300 },
        );
    });

    test('clamps zoom and rejects invalid zoom ranges', () => {
        assert.equal(clampZoom(0.01), 0.25);
        assert.equal(clampZoom(2), 2);
        assert.equal(clampZoom(100), 16);
        assert.equal(clampZoom(3, 1, 4), 3);
        assert.throws(() => clampZoom(1, 5, 2), /Minimum zoom/);
        assert.throws(() => clampZoom(Number.NaN), /zoom must be a finite number/);
    });

    test('formats xyz/yxz display order without mutating canonical coordinates', () => {
        const point = { x: -6441.04, y: 1025.06, z: 30.44 };

        assert.equal(formatCoordinates(point, 'xyz', 1), '-6441.0, 1025.1, 30.4');
        assert.equal(formatCoordinates(point, 'yxz', 1), '1025.1, -6441.0, 30.4');
        assert.equal(formatCoordinates(1.25, 2.5, 3.75, 'xyz', 2), '1.25, 2.50, 3.75');
        assert.deepEqual(point, { x: -6441.04, y: 1025.06, z: 30.44 });
        assert.throws(() => formatCoordinates(point, 'zxy'), /either 'xyz' or 'yxz'/);
        assert.throws(() => formatCoordinates(point, 'xyz', 7), /fractionDigits/);
    });
});

describe('NPC location map safeguards', () => {
    test('rejects malformed locations instead of coercing them to the map origin', () => {
        const valid = { id: 2, position: { x: -6441, y: 1025, z: 30.4 } };
        const missing = { id: 1, position: null };
        const malformed = { id: 3, position: { x: '12', y: 4, z: 5 } };
        const state = npcLocationMap({
            coordinateOrder: 'yxz',
            groups: [{ key: 'qeynos:0', locations: [missing, valid, malformed] }],
        });
        state.selectedZoneKey = 'qeynos:0';
        state.selectedLocationId = missing.id;

        assert.equal(hasFinitePosition(valid), true);
        assert.equal(hasFinitePosition(missing), false);
        assert.equal(hasFinitePosition(malformed), false);
        assert.deepEqual(locationMapPoint(valid), { x: 6441, y: -1025, z: 30.4 });
        assert.equal(locationMapPoint(missing), null);
        assert.deepEqual(state.mappableLocations.map((location) => location.id), [2]);
        assert.equal(state.selectedCoordinateText, 'Coordinates unavailable');
        assert.equal(state.coordinateLabel(valid), '1025.00, -6441.00, 30.40');
    });

    test('preserves the last canvas size while hidden and safely fits undersized viewports', () => {
        const state = npcLocationMap();
        state.canvasWidth = 320;
        state.canvasHeight = 240;
        state.$refs = {
            canvas: {},
            viewport: { getBoundingClientRect: () => ({ width: 0, height: 0 }) },
        };

        state.resizeCanvas();
        assert.equal(state.canvasWidth, 320);
        assert.equal(state.canvasHeight, 240);

        state.mapData = { bounds: DEFAULT_BOUNDS };
        state.canvasWidth = 1;
        state.canvasHeight = 1;
        assert.doesNotThrow(() => state.recalculateFit(false));
        assert.equal(state.fit.padding, 0);
        assert.ok(state.fit.scale > 0);
    });

    test('invalidates in-flight work when the Alpine component is destroyed', () => {
        let resizeDisconnected = false;
        let visibilityDisconnected = false;
        const state = npcLocationMap();
        state.loadGeneration = 8;
        state.loading = true;
        state.loadingMapUrl = '/maps/pending.eqmap';
        state.resizeObserver = { disconnect: () => { resizeDisconnected = true; } };
        state.visibilityObserver = { disconnect: () => { visibilityDisconnected = true; } };

        state.destroy();

        assert.equal(state.loadGeneration, 9);
        assert.equal(state.loading, false);
        assert.equal(state.loadingMapUrl, null);
        assert.equal(resizeDisconnected, true);
        assert.equal(visibilityDisconnected, true);
    });

    test('invalidates a pending map load when an unavailable zone is selected', async () => {
        const originalFetch = globalThis.fetch;
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        let resolvePayload;
        const payload = buildEqm1({ groups: [], points: [] });
        const url = `/maps/pending-${Date.now()}-${Math.random()}.eqmap`;

        globalThis.requestAnimationFrame = () => 1;
        globalThis.fetch = async () => ({
            ok: true,
            status: 200,
            headers: { get: () => null },
            arrayBuffer: () => new Promise((resolve) => { resolvePayload = resolve; }),
        });

        try {
            const state = npcLocationMap({
                groups: [
                    { key: 'mapped:0', long_name: 'Mapped', version: 0, map: { available: true, url }, locations: [] },
                    { key: 'missing:0', long_name: 'Missing', version: 0, map: null, locations: [] },
                ],
            });
            state.selectedZoneKey = 'mapped:0';

            const pending = state.ensureMapLoaded();
            while (!resolvePayload) await Promise.resolve();
            await state.selectZone('missing:0');

            assert.equal(state.loading, false);
            assert.equal(state.mapData, null);
            resolvePayload(payload);
            await pending;

            assert.equal(state.selectedZoneKey, 'missing:0');
            assert.equal(state.mapData, null);
            assert.match(state.statusMessage, /No base map is available for Missing/);
        } finally {
            globalThis.fetch = originalFetch;
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('draws shared grid paths and spawn-group roam bounds only once', () => {
        let fillCount = 0;
        let strokeCount = 0;
        const context = {
            beginPath() {},
            closePath() {},
            fill() { fillCount += 1; },
            lineTo() {},
            moveTo() {},
            restore() {},
            save() {},
            setLineDash() {},
            stroke() { strokeCount += 1; },
        };
        const path = [{ x: 20, y: 30, z: 4 }, { x: 25, y: 35, z: 5 }];
        const roam = { min_x: 1, max_x: 10, min_y: 2, max_y: 20 };
        const state = npcLocationMap({
            groups: [{
                key: 'qeynos:0',
                paths: { 9: path },
                locations: [
                    { id: 1, spawn_group_id: 77, path_grid: 9, position: { x: 1, y: 2, z: 3 }, roam },
                    { id: 2, spawn_group_id: 77, path_grid: 9, position: { x: 4, y: 5, z: 6 }, roam },
                ],
            }],
        });
        state.selectedZoneKey = 'qeynos:0';
        state.canvasWidth = 800;
        state.canvasHeight = 600;
        state.fit = fitBounds(DEFAULT_BOUNDS, 800, 600, 24);

        state.drawMovement(context);
        assert.equal(state.hasPaths, true);
        assert.equal(strokeCount, 2);
        assert.equal(fillCount, 1);
    });
});
