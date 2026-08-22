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
    formatLocationCoordinates,
    hasFinitePosition,
    locationMapArea,
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

describe('layered atlas overlays', () => {
    test('transforms every ground-spawn rectangle corner and restores ordered Brewall bounds', () => {
        const location = {
            area: { min_x: -20, max_x: 10, min_y: 5, max_y: 25 },
        };

        assert.deepEqual(locationMapArea(location), {
            minX: -10,
            maxX: 20,
            minY: -25,
            maxY: -5,
        });
        assert.equal(locationMapArea({ area: { min_x: 0, max_x: Number.NaN, min_y: 0, max_y: 1 } }), null);
    });

    test('filters overlapping traits once and searches bounded detail text', () => {
        const state = npcLocationMap({
            layers: [
                { id: 'npcs', label: 'NPCs', color: '#7dd3fc', default: true },
                { id: 'named', label: 'Named', color: '#f472b6', default: true },
                { id: 'merchants', label: 'Merchants', color: '#facc15', default: true },
            ],
            groups: [{
                key: 'qeynos:0',
                locations: [
                    { id: 'npc-1', layers: ['npcs'], label: 'a guard', position: { x: 1, y: 2, z: 3 } },
                    {
                        id: 'npc-2',
                        kind: 'named',
                        layers: ['named', 'merchants'],
                        label: 'Quartermaster Zed',
                        details: [{ label: 'NPC ID', value: '2048' }],
                        position: { x: 4, y: 5, z: 6 },
                    },
                ],
            }],
        });
        state.configureLayers(state.layers);
        state.selectedZoneKey = 'qeynos:0';

        assert.deepEqual(state.filteredLocations.map((location) => location.id), ['npc-1', 'npc-2']);
        state.activeLayers = { npcs: false, named: false, merchants: true };
        assert.deepEqual(state.filteredLocations.map((location) => location.id), ['npc-2']);
        assert.equal(state.locationVisualKind(state.currentLocations[1]), 'merchants');
        assert.equal(state.locationColor(state.currentLocations[1]), '#facc15');
        state.searchQuery = '2048';
        assert.deepEqual(state.filteredLocations.map((location) => location.id), ['npc-2']);
        state.searchQuery = 'guard';
        assert.deepEqual(state.filteredLocations, []);
    });

    test('assigns every atlas layer a distinct color and marker silhouette', () => {
        const layers = [
            { id: 'npcs', color: '#38bdf8', shape: 'circle' },
            { id: 'named', color: '#fb7185', shape: 'star' },
            { id: 'merchants', color: '#facc15', shape: 'square' },
            { id: 'quest', color: '#a78bfa', shape: 'pentagon' },
            { id: 'ground-spawns', color: '#4ade80', shape: 'diamond' },
            { id: 'zone-points', color: '#fb923c', shape: 'triangle' },
            { id: 'doors', color: '#2dd4bf', shape: 'hexagon' },
            { id: 'objects', color: '#e879f9', shape: 'cross' },
            { id: 'navigation', color: '#f8fafc', shape: 'compass' },
        ];
        const state = npcLocationMap({ layers });
        state.configureLayers(state.layers);

        const shapes = layers.map((layer) => state.locationShape({ kind: layer.id, layers: [layer.id] }));
        const signatures = shapes.map((shape) => {
            const operations = [];
            const context = {
                arc: (...values) => operations.push(['arc', ...values]),
                beginPath: () => operations.push(['begin']),
                closePath: () => operations.push(['close']),
                lineTo: (...values) => operations.push(['line', ...values]),
                moveTo: (...values) => operations.push(['move', ...values]),
                rect: (...values) => operations.push(['rect', ...values]),
            };
            state.markerPath(context, shape, 0, 0, 10);
            return operations.map(([operation]) => operation).join(':');
        });

        assert.equal(new Set(state.layers.map((layer) => layer.color)).size, layers.length);
        assert.equal(new Set(shapes).size, layers.length);
        assert.equal(new Set(signatures).size, layers.length);
        assert.equal(state.locationShape({ kind: 'unknown', layers: [] }), 'circle');
    });

    test('memoizes filtered and projected locations until filter state changes', () => {
        const state = npcLocationMap({
            layers: [{ id: 'npcs', label: 'NPCs', default: true }],
            groups: [{
                key: 'qeynos:0',
                locations: [{ id: 'npc-1', layers: ['npcs'], label: 'a guard', position: { x: 1, y: 2, z: 3 } }],
            }],
        });
        state.configureLayers(state.layers);
        state.selectedZoneKey = 'qeynos:0';

        assert.strictEqual(state.filteredLocations, state.filteredLocations);
        assert.strictEqual(state.mappableEntries, state.mappableEntries);
        const visible = state.filteredLocations;
        state.searchQuery = 'missing';
        assert.notStrictEqual(state.filteredLocations, visible);
        assert.deepEqual(state.filteredLocations, []);
    });

    test('uses area bounds for labels and clipboard text instead of the representative point', async () => {
        const area = {
            position: { x: -5, y: 15, z: 7 },
            area: { min_x: -20, max_x: 10, min_y: 5, max_y: 25 },
        };

        assert.equal(
            formatLocationCoordinates(area, 'xyz', 2),
            'X -20.00–10.00, Y 5.00–25.00, Z 7.00',
        );
        assert.equal(
            formatLocationCoordinates(area, 'yxz', 1),
            'Y 5.0–25.0, X -20.0–10.0, Z 7.0',
        );
        assert.equal(
            formatLocationCoordinates({ ...area, area: { min_x: -5, max_x: -5, min_y: 15, max_y: 15 } }),
            'X -5.00, Y 15.00, Z 7.00',
        );

        const navigatorDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
        const originalWindow = globalThis.window;
        let copiedText = null;
        Object.defineProperty(globalThis, 'navigator', {
            configurable: true,
            value: { clipboard: { writeText: async (text) => { copiedText = text; } } },
        });
        globalThis.window = { setTimeout: () => 1 };
        try {
            const state = npcLocationMap({ coordinateOrder: 'yxz' });
            await state.copyCoordinates(area);
            assert.equal(copiedText, 'Y 5.00–25.00, X -20.00–10.00, Z 7.00');
            assert.equal(state.statusMessage, `Copied ${copiedText}`);
        } finally {
            if (navigatorDescriptor) Object.defineProperty(globalThis, 'navigator', navigatorDescriptor);
            else delete globalThis.navigator;
            if (originalWindow === undefined) delete globalThis.window;
            else globalThis.window = originalWindow;
        }
    });

    test('lets a painted point marker outrank a containing ground area', () => {
        const state = npcLocationMap({
            layers: [
                { id: 'npcs', label: 'NPCs', default: true },
                { id: 'ground-spawns', label: 'Ground', default: true },
            ],
            groups: [{
                key: 'arena:0',
                locations: [
                    {
                        id: 'ground', kind: 'ground-spawns', layers: ['ground-spawns'],
                        position: { x: 0, y: 0, z: 0 },
                        area: { min_x: -10, max_x: 10, min_y: -10, max_y: 10 },
                    },
                    {
                        id: 'npc', kind: 'npcs', layers: ['npcs'],
                        position: { x: -5, y: 0, z: 0 },
                    },
                ],
            }],
        });
        state.configureLayers(state.layers);
        state.selectedZoneKey = 'arena:0';
        state.canvasWidth = 800;
        state.canvasHeight = 600;
        state.fit = fitBounds({ minX: -20, minY: -20, maxX: 20, maxY: 20 }, 800, 600, 24);
        const npcPoint = locationMapPoint(state.currentLocations[1]);
        const screen = state.toScreen(npcPoint.x, npcPoint.y);

        assert.equal(state.hitTest(screen.x, screen.y).id, 'npc');
    });

    test('prefers the smallest containing ground area when no point marker is hit', () => {
        const state = npcLocationMap({
            layers: [{ id: 'ground-spawns', label: 'Ground', color: '#34d399', default: true }],
            groups: [{
                key: 'arena:0',
                locations: [
                    {
                        id: 'large', kind: 'ground-spawns', layers: ['ground-spawns'],
                        position: { x: 0, y: 0, z: 0 },
                        area: { min_x: -10, max_x: 10, min_y: -10, max_y: 10 },
                    },
                    {
                        id: 'small', kind: 'ground-spawns', layers: ['ground-spawns'],
                        position: { x: 0, y: 0, z: 0 },
                        area: { min_x: -2, max_x: 2, min_y: -2, max_y: 2 },
                    },
                ],
            }],
        });
        state.configureLayers(state.layers);
        state.selectedZoneKey = 'arena:0';
        state.canvasWidth = 800;
        state.canvasHeight = 600;
        state.fit = fitBounds({ minX: -20, minY: -20, maxX: 20, maxY: 20 }, 800, 600, 24);
        const insideBothAreas = state.toScreen(1.5, 1.5);

        assert.equal(state.hitTest(insideBothAreas.x, insideBothAreas.y).id, 'small');
    });

    test('keeps default layers for unknown-only URL state and enables a pinned feature layer', () => {
        const originalWindow = globalThis.window;
        globalThis.window = {
            location: { href: 'https://example.test/zones/1?layers=unknown&pin=ground-7' },
        };

        try {
            const location = {
                id: 'ground-7', kind: 'ground-spawns', layers: ['ground-spawns'],
                // A representative position is not guaranteed to be the bounds center.
                position: { x: 80, y: 60, z: 5 },
                area: { min_x: -10, max_x: 10, min_y: -5, max_y: 5 },
            };
            const state = npcLocationMap({
                syncUrl: true,
                layers: [
                    { id: 'npcs', label: 'NPCs', default: true },
                    { id: 'ground-spawns', label: 'Ground', default: false },
                ],
                groups: [{ key: 'arena:0', map: { available: true, url: '/arena.eqmap' }, locations: [location] }],
            });
            state.configureLayers(state.layers);
            state.applyUrlState();
            assert.deepEqual(state.activeLayers, { npcs: true, 'ground-spawns': false });

            state.initializeGroupSelection();
            assert.equal(state.selectedLocationId, 'ground-7');
            assert.equal(state.pendingFocusId, 'ground-7');
            assert.equal(state.activeLayers['ground-spawns'], true);
        } finally {
            if (originalWindow === undefined) delete globalThis.window;
            else globalThis.window = originalWindow;
        }
    });

    test('fits a pending rectangular pin after map dimensions are ready', () => {
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        globalThis.requestAnimationFrame = () => 1;
        try {
            const location = {
                id: 'ground-7', kind: 'ground-spawns', layers: ['ground-spawns'],
                position: { x: 0, y: 0, z: 5 },
                area: { min_x: -10, max_x: 10, min_y: -5, max_y: 5 },
            };
            const state = npcLocationMap({
                groups: [{ key: 'arena:0', locations: [location] }],
            });
            state.selectedZoneKey = 'arena:0';
            state.selectedLocationId = location.id;
            state.pendingFocusId = location.id;
            state.mapData = { bounds: { minX: -100, minY: -100, maxX: 100, maxY: 100 } };
            state.canvasWidth = 800;
            state.canvasHeight = 600;
            state.recalculateFit(true);

            assert.equal(state.focusPendingLocation(), true);
            assert.equal(state.pendingFocusId, null);
            assert.ok(state.zoom > 1);
            const area = locationMapArea(location);
            const topLeft = state.toScreen(area.minX, area.minY);
            const bottomRight = state.toScreen(area.maxX, area.maxY);
            assert.ok(topLeft.x >= 55 && topLeft.y >= 55);
            assert.ok(bottomRight.x <= 745 && bottomRight.y <= 545);
            assert.ok(Math.abs((topLeft.x + bottomRight.x) / 2 - 400) < 0.001);
            assert.ok(Math.abs((topLeft.y + bottomRight.y) / 2 - 300) < 0.001);
        } finally {
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('keeps visual markers compact while preserving a generous hit target', () => {
        const state = npcLocationMap({
            layers: [{ id: 'npcs', color: '#38bdf8', shape: 'circle' }],
            groups: [{
                key: 'arena:0',
                locations: [
                    { id: 'normal', kind: 'npcs', layers: ['npcs'], position: { x: -10, y: 0, z: 0 } },
                    { id: 'hovered', kind: 'npcs', layers: ['npcs'], position: { x: 0, y: 0, z: 0 } },
                    { id: 'selected', kind: 'npcs', layers: ['npcs'], position: { x: 10, y: 0, z: 0 } },
                ],
            }],
        });
        state.configureLayers(state.layers);
        state.selectedZoneKey = 'arena:0';
        state.selectedLocationId = 'selected';
        state.hoveredLocationId = 'hovered';
        state.canvasWidth = 800;
        state.canvasHeight = 600;
        state.fit = fitBounds({ minX: -20, minY: -20, maxX: 20, maxY: 20 }, 800, 600, 24);
        const radii = [];
        state.markerPath = (context, shape, x, y, radius) => radii.push(radius);
        const context = {
            arc() {},
            beginPath() {},
            fill() {},
            stroke() {},
        };

        state.drawLocations(context);

        assert.deepEqual(radii.sort((left, right) => left - right), [4.25, 5.25, 6]);
        const selectedPoint = locationMapPoint(state.currentLocations[2]);
        const selectedScreen = state.toScreen(selectedPoint.x, selectedPoint.y);
        assert.equal(state.hitTest(selectedScreen.x + 10, selectedScreen.y).id, 'selected');
    });

    test('clamps pointer and keyboard panning to finite map edges at every zoom', () => {
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        globalThis.requestAnimationFrame = () => 1;
        try {
            const state = npcLocationMap();
            state.mapData = { bounds: { minX: -100, minY: -100, maxX: 100, maxY: 100 } };
            state.canvasWidth = 800;
            state.canvasHeight = 600;
            state.recalculateFit(true);

            state.zoom = 0.5;
            state.panX = 1_000_000;
            state.panY = -1_000_000;
            assert.deepEqual(state.clampPan(), { minX: 0, maxX: 0, minY: 0, maxY: 0 });
            assert.equal(state.panX, 0);
            assert.equal(state.panY, 0);

            state.zoom = 4;
            state.dragging = true;
            state.pointerStart = { x: 0, y: 0, panX: 0, panY: 0 };
            state.$refs = { canvas: { style: {} } };
            state.onPointerMove({ clientX: 1_000_000, clientY: -1_000_000 });
            const limits = state.panLimits();
            assert.equal(state.panX, limits.maxX);
            assert.equal(state.panY, limits.minY);

            state.dragging = false;
            state.pointerStart = null;
            state.onKeydown({ key: 'ArrowLeft', shiftKey: true, preventDefault() {} });
            assert.equal(state.panX, limits.maxX);
            assert.ok(Number.isFinite(state.panX));
            assert.ok(Number.isFinite(state.panY));
        } finally {
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('keeps outlying locations visible while clamping to all map features', () => {
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        globalThis.requestAnimationFrame = () => 1;
        try {
            const state = npcLocationMap({
                groups: [{
                    key: 'outlier:0',
                    locations: [{
                        id: 'far-away',
                        position: { x: -1000, y: 0, z: 0 },
                        layers: [],
                    }],
                }],
            });
            state.selectedZoneKey = 'outlier:0';
            state.mapData = {
                bounds: { minX: -100, minY: -100, maxX: 100, maxY: 100 },
                points: [],
            };
            state.canvasWidth = 800;
            state.canvasHeight = 600;
            state.recalculateFit(true);

            assert.equal(state.focusLocation(state.currentLocations[0]), true);
            const point = locationMapPoint(state.currentLocations[0]);
            const screen = state.toScreen(point.x, point.y);
            assert.ok(screen.x >= 0 && screen.x <= state.canvasWidth);
            assert.ok(screen.y >= 0 && screen.y <= state.canvasHeight);
            assert.ok(Number.isFinite(state.panX));
            assert.ok(Number.isFinite(state.panY));
            assert.ok(state.interactionBounds().maxX >= point.x);
        } finally {
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('briefly pulses a selected marker and disables animation for reduced motion', () => {
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        let requestedFrames = 0;
        globalThis.requestAnimationFrame = () => { requestedFrames += 1; return requestedFrames; };
        try {
            const state = npcLocationMap();
            state.selectedLocationId = 'npc-1';
            state.flashSelection();
            assert.ok(state.selectionPulse());
            assert.equal(requestedFrames, 1);
            assert.equal(state.selectionPulse(Number.MAX_SAFE_INTEGER), null);

            state.animationFrame = null;
            state.reducedMotion = true;
            state.flashSelection();
            assert.equal(state.selectionPulse(), null);
        } finally {
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('caches static overlays while animating and stops when the selection is absent', () => {
        const documentDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'document');
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        let requestedFrames = 0;
        let staticRenders = 0;
        let overlayBlits = 0;
        const overlayContext = {
            clearRect() {},
            setTransform() {},
        };
        Object.defineProperty(globalThis, 'document', {
            configurable: true,
            value: {
                createElement: () => ({
                    width: 0,
                    height: 0,
                    getContext: () => overlayContext,
                }),
            },
        });
        globalThis.requestAnimationFrame = () => { requestedFrames += 1; return requestedFrames; };

        try {
            const context = {
                arc() {},
                beginPath() {},
                clearRect() {},
                drawImage() { overlayBlits += 1; },
                restore() {},
                save() {},
                setTransform() {},
                stroke() {},
            };
            const canvas = { width: 800, height: 600, getContext: () => context };
            const state = npcLocationMap({
                groups: [{
                    key: 'pulse:0',
                    locations: [{ id: 'selected', position: { x: 0, y: 0, z: 0 }, layers: [] }],
                }],
            });
            state.$refs = { canvas };
            state.selectedZoneKey = 'pulse:0';
            state.selectedLocationId = 'selected';
            state.canvasWidth = 800;
            state.canvasHeight = 600;
            state.fit = { centerX: 0, centerY: 0, scale: 1 };
            state.mapData = {};
            state.drawCachedBase = () => true;
            state.drawStaticOverlays = () => { staticRenders += 1; };
            state.flashSelection();

            state.animationFrame = null;
            state.draw();
            state.animationFrame = null;
            state.draw();
            assert.equal(staticRenders, 1);
            assert.equal(overlayBlits, 2);

            state.selectedLocationId = 'missing';
            state.animationFrame = null;
            requestedFrames = 0;
            state.draw();
            assert.equal(requestedFrames, 0);
        } finally {
            if (documentDescriptor) Object.defineProperty(globalThis, 'document', documentDescriptor);
            else delete globalThis.document;
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('reuses the base-map render until a base-affecting change marks it dirty', () => {
        const documentDescriptor = Object.getOwnPropertyDescriptor(globalThis, 'document');
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        let baseRenders = 0;
        let blits = 0;
        const cachedContext = {
            clearRect() {},
            setTransform() {},
        };
        const cachedCanvas = {
            width: 0,
            height: 0,
            getContext: () => cachedContext,
        };
        Object.defineProperty(globalThis, 'document', {
            configurable: true,
            value: { createElement: () => cachedCanvas },
        });
        globalThis.requestAnimationFrame = () => 1;

        try {
            const context = {
                clearRect() {},
                drawImage() { blits += 1; },
                restore() {},
                save() {},
                setTransform() {},
            };
            const canvas = { width: 800, height: 600, getContext: () => context };
            const state = npcLocationMap();
            state.$refs = { canvas };
            state.canvasWidth = 400;
            state.canvasHeight = 300;
            state.mapData = {};
            state.drawBaseLayers = () => { baseRenders += 1; };
            state.drawAreas = () => {};
            state.drawLocations = () => {};
            state.drawLocationLabels = () => {};

            state.draw();
            state.draw();
            assert.equal(baseRenders, 1);
            assert.equal(blits, 2);

            state.invalidateBase();
            state.draw();
            assert.equal(baseRenders, 2);
            assert.equal(blits, 3);
        } finally {
            if (documentDescriptor) Object.defineProperty(globalThis, 'document', documentDescriptor);
            else delete globalThis.document;
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('cleans up pointer state when capture was already cancelled or lost', () => {
        const originalRequestAnimationFrame = globalThis.requestAnimationFrame;
        globalThis.requestAnimationFrame = () => 1;
        try {
            const state = npcLocationMap();
            state.dragging = true;
            state.pointerStart = { x: 1, y: 2, panX: 0, panY: 0 };
            assert.doesNotThrow(() => state.onPointerUp({
                type: 'pointercancel',
                pointerId: 9,
                currentTarget: {
                    hasPointerCapture: () => false,
                    releasePointerCapture: () => { throw new Error('must not release'); },
                },
            }));
            assert.equal(state.dragging, false);
            assert.equal(state.pointerStart, null);

            state.dragging = true;
            state.pointerStart = { x: 1, y: 2, panX: 0, panY: 0 };
            state.onLostPointerCapture();
            assert.equal(state.dragging, false);
            assert.equal(state.pointerStart, null);
        } finally {
            globalThis.requestAnimationFrame = originalRequestAnimationFrame;
        }
    });

    test('installs and removes canvas and reduced-motion listeners', () => {
        const globals = ['ResizeObserver', 'IntersectionObserver', 'requestAnimationFrame', 'cancelAnimationFrame', 'window'];
        const descriptors = Object.fromEntries(globals.map((name) => [
            name,
            Object.getOwnPropertyDescriptor(globalThis, name),
        ]));
        let listener = null;
        let removedListener = null;
        let motionListener = null;
        let removedMotionListener = null;
        Object.defineProperty(globalThis, 'ResizeObserver', {
            configurable: true,
            value: class { observe() {} disconnect() {} },
        });
        Object.defineProperty(globalThis, 'IntersectionObserver', {
            configurable: true,
            value: class { observe() {} disconnect() {} },
        });
        Object.defineProperty(globalThis, 'requestAnimationFrame', {
            configurable: true,
            value: () => 1,
        });
        Object.defineProperty(globalThis, 'cancelAnimationFrame', {
            configurable: true,
            value: () => {},
        });
        Object.defineProperty(globalThis, 'window', {
            configurable: true,
            value: {
                clearTimeout() {},
                matchMedia: () => ({
                    matches: false,
                    addEventListener(type, callback) {
                        assert.equal(type, 'change');
                        motionListener = callback;
                    },
                    removeEventListener(type, callback) {
                        assert.equal(type, 'change');
                        removedMotionListener = callback;
                    },
                }),
            },
        });

        try {
            const state = npcLocationMap();
            state.$refs = {
                viewport: null,
                canvas: {
                    addEventListener(type, callback) {
                        assert.equal(type, 'lostpointercapture');
                        listener = callback;
                    },
                    removeEventListener(type, callback) {
                        assert.equal(type, 'lostpointercapture');
                        removedListener = callback;
                    },
                },
            };
            state.$root = {};
            state.$watch = () => {};
            state.init();

            assert.equal(typeof listener, 'function');
            state.dragging = true;
            state.pointerStart = { x: 1, y: 2, panX: 0, panY: 0 };
            listener();
            assert.equal(state.dragging, false);
            assert.equal(state.pointerStart, null);

            state.selectedLocationId = 'selected';
            state.flashSelection();
            assert.ok(state.selectionPulse());
            motionListener({ matches: true });
            assert.equal(state.reducedMotion, true);
            assert.equal(state.selectionPulse(), null);

            state.destroy();
            assert.strictEqual(removedListener, listener);
            assert.strictEqual(removedMotionListener, motionListener);
        } finally {
            for (const name of globals) {
                if (descriptors[name]) Object.defineProperty(globalThis, name, descriptors[name]);
                else delete globalThis[name];
            }
        }
    });
});
