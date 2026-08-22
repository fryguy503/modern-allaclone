const MAGIC = 'EQM1';
const HEADER_BYTES = 40;
const GROUP_HEADER_BYTES = 8;
const SEGMENT_FLOAT_COUNT = 6;
const SEGMENT_BYTES = SEGMENT_FLOAT_COUNT * Float32Array.BYTES_PER_ELEMENT;
const POINT_HEADER_BYTES = 20;

const textDecoder = new TextDecoder('utf-8', { fatal: true });
const littleEndianHost = new Uint8Array(new Uint16Array([0x00ff]).buffer)[0] === 0xff;

export const EQM1_FORMAT = Object.freeze({
    magic: MAGIC,
    version: 1,
    headerBytes: HEADER_BYTES,
    groupHeaderBytes: GROUP_HEADER_BYTES,
    segmentBytes: SEGMENT_BYTES,
    pointHeaderBytes: POINT_HEADER_BYTES,
});

// These ceilings are intentionally far above the supplied Brewall maps while
// still preventing a corrupt header from causing excessive loops or allocation.
export const EQM1_DEFAULT_LIMITS = Object.freeze({
    maxBytes: 8 * 1024 * 1024,
    maxColorGroups: 4096,
    maxSegments: 1_000_000,
    maxPoints: 250_000,
    maxLabelBytes: 4096,
});

export class EqMapFormatError extends Error {
    constructor(message, { code = 'ERR_EQM_FORMAT', offset = null } = {}) {
        super(offset === null ? message : `${message} (byte ${offset})`);
        this.name = 'EqMapFormatError';
        this.code = code;
        this.offset = offset;
    }
}

/**
 * Decode an EQM1 map.
 *
 * Returned group segment arrays are views over the input buffer when the input
 * is four-byte aligned and the host is little-endian. Otherwise they are the
 * smallest necessary copy with the same Float32Array API.
 */
export function parseEqMap(input, options = {}) {
    const bytes = asByteView(input);
    const limits = normalizeLimits(options.limits ?? options);

    if (bytes.byteLength > limits.maxBytes) {
        fail(
            'ERR_EQM_LIMIT',
            `EQM1 payload is ${bytes.byteLength} bytes; limit is ${limits.maxBytes}`,
            0,
        );
    }

    if (bytes.byteLength < HEADER_BYTES) {
        fail(
            'ERR_EQM_TRUNCATED',
            `EQM1 header requires ${HEADER_BYTES} bytes; received ${bytes.byteLength}`,
            bytes.byteLength,
        );
    }

    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const magic = String.fromCharCode(bytes[0], bytes[1], bytes[2], bytes[3]);
    if (magic !== MAGIC) {
        fail('ERR_EQM_MAGIC', `Invalid EQM1 magic ${JSON.stringify(magic)}`, 0);
    }

    const version = view.getUint16(4, true);
    if (version !== EQM1_FORMAT.version) {
        fail('ERR_EQM_VERSION', `Unsupported EQM1 version ${version}`, 4);
    }

    const colorGroupCount = view.getUint16(6, true);
    const totalSegmentCount = view.getUint32(8, true);
    const pointCount = view.getUint32(12, true);

    enforceCountLimit('color group', colorGroupCount, limits.maxColorGroups, 6);
    enforceCountLimit('segment', totalSegmentCount, limits.maxSegments, 8);
    enforceCountLimit('point', pointCount, limits.maxPoints, 12);

    const bounds = {
        minX: view.getFloat32(16, true),
        minY: view.getFloat32(20, true),
        minZ: view.getFloat32(24, true),
        maxX: view.getFloat32(28, true),
        maxY: view.getFloat32(32, true),
        maxZ: view.getFloat32(36, true),
    };
    validateBounds(bounds);

    const minimumBytes = HEADER_BYTES
        + (colorGroupCount * GROUP_HEADER_BYTES)
        + (totalSegmentCount * SEGMENT_BYTES)
        + (pointCount * POINT_HEADER_BYTES);
    if (minimumBytes > bytes.byteLength) {
        fail(
            'ERR_EQM_TRUNCATED',
            `EQM1 counts require at least ${minimumBytes} bytes; received ${bytes.byteLength}`,
            bytes.byteLength,
        );
    }

    let offset = HEADER_BYTES;
    let parsedSegmentCount = 0;
    const groups = new Array(colorGroupCount);

    for (let groupIndex = 0; groupIndex < colorGroupCount; groupIndex += 1) {
        requireBytes(bytes, offset, GROUP_HEADER_BYTES, `color group ${groupIndex} header`);

        const r = bytes[offset];
        const g = bytes[offset + 1];
        const b = bytes[offset + 2];
        const reserved = bytes[offset + 3];
        if (reserved !== 0) {
            fail(
                'ERR_EQM_RESERVED',
                `Color group ${groupIndex} reserved byte must be zero`,
                offset + 3,
            );
        }

        const count = view.getUint32(offset + 4, true);
        enforceCountLimit(`color group ${groupIndex} segment`, count, limits.maxSegments, offset + 4);

        if (parsedSegmentCount + count > totalSegmentCount) {
            fail(
                'ERR_EQM_COUNT',
                `Color group segment counts exceed declared total ${totalSegmentCount}`,
                offset + 4,
            );
        }

        offset += GROUP_HEADER_BYTES;
        const segmentByteLength = count * SEGMENT_BYTES;
        requireBytes(bytes, offset, segmentByteLength, `color group ${groupIndex} segments`);

        const segments = readFloat32Array(bytes, view, offset, count * SEGMENT_FLOAT_COUNT);
        for (let coordinateIndex = 0; coordinateIndex < segments.length; coordinateIndex += 1) {
            if (!Number.isFinite(segments[coordinateIndex])) {
                fail(
                    'ERR_EQM_COORDINATE',
                    `Color group ${groupIndex} contains a non-finite segment coordinate`,
                    offset + (coordinateIndex * Float32Array.BYTES_PER_ELEMENT),
                );
            }
        }

        groups[groupIndex] = { color: [r, g, b], count, segments };
        parsedSegmentCount += count;
        offset += segmentByteLength;
    }

    if (parsedSegmentCount !== totalSegmentCount) {
        fail(
            'ERR_EQM_COUNT',
            `Color groups contain ${parsedSegmentCount} segments; header declares ${totalSegmentCount}`,
            offset,
        );
    }

    const points = new Array(pointCount);
    for (let pointIndex = 0; pointIndex < pointCount; pointIndex += 1) {
        requireBytes(bytes, offset, POINT_HEADER_BYTES, `point ${pointIndex} header`);

        const x = view.getFloat32(offset, true);
        const y = view.getFloat32(offset + 4, true);
        const z = view.getFloat32(offset + 8, true);
        validatePointCoordinates(pointIndex, x, y, z, offset);

        const r = bytes[offset + 12];
        const g = bytes[offset + 13];
        const b = bytes[offset + 14];
        const size = bytes[offset + 15];
        const labelByteLength = view.getUint16(offset + 16, true);
        const reserved = view.getUint16(offset + 18, true);

        if (reserved !== 0) {
            fail(
                'ERR_EQM_RESERVED',
                `Point ${pointIndex} reserved field must be zero`,
                offset + 18,
            );
        }
        enforceCountLimit('point label byte', labelByteLength, limits.maxLabelBytes, offset + 16);

        offset += POINT_HEADER_BYTES;
        requireBytes(bytes, offset, labelByteLength, `point ${pointIndex} label`);

        let label;
        try {
            label = textDecoder.decode(bytes.subarray(offset, offset + labelByteLength));
        } catch {
            fail('ERR_EQM_UTF8', `Point ${pointIndex} label is not valid UTF-8`, offset);
        }
        offset += labelByteLength;

        const padding = paddingFor(labelByteLength);
        requireBytes(bytes, offset, padding, `point ${pointIndex} label padding`);
        for (let paddingIndex = 0; paddingIndex < padding; paddingIndex += 1) {
            if (bytes[offset + paddingIndex] !== 0) {
                fail(
                    'ERR_EQM_PADDING',
                    `Point ${pointIndex} label padding must be zero`,
                    offset + paddingIndex,
                );
            }
        }
        offset += padding;

        points[pointIndex] = { x, y, z, color: [r, g, b], size, label };
    }

    if (offset !== bytes.byteLength) {
        fail(
            'ERR_EQM_TRAILING_BYTES',
            `EQM1 payload has ${bytes.byteLength - offset} trailing byte(s)`,
            offset,
        );
    }

    return {
        version,
        bounds,
        colorGroupCount,
        totalSegmentCount,
        segmentCount: totalSegmentCount,
        pointCount,
        groups,
        points,
        byteLength: bytes.byteLength,
    };
}

// Explicit alias for callers that prefer the binary nature in the function name.
export const parseEqMapBinary = parseEqMap;

/** Convert EQEmu database X/Y values into Brewall client-map coordinates. */
export function dbToBrewall(x, y) {
    assertFiniteNumber(x, 'x');
    assertFiniteNumber(y, 'y');

    return [x === 0 ? 0 : -x, y === 0 ? 0 : -y];
}

/** Convert Brewall client-map coordinates back into EQEmu database X/Y values. */
export function brewallToEqemu(x, y) {
    assertFiniteNumber(x, 'x');
    assertFiniteNumber(y, 'y');

    return [x === 0 ? 0 : -x, y === 0 ? 0 : -y];
}

/**
 * Compute a centered world-to-viewport transform that preserves aspect ratio.
 * Accepts either (bounds, width, height, padding) or
 * (bounds, { width, height, padding }).
 */
export function fitBounds(bounds, widthOrViewport, height, padding = 0) {
    validatePlanarBounds(bounds);

    let viewportWidth = widthOrViewport;
    let viewportHeight = height;
    let viewportPadding = padding;
    if (widthOrViewport && typeof widthOrViewport === 'object') {
        viewportWidth = widthOrViewport.width ?? widthOrViewport.viewportWidth;
        viewportHeight = widthOrViewport.height ?? widthOrViewport.viewportHeight;
        viewportPadding = widthOrViewport.padding ?? 0;
    }

    assertPositiveFiniteNumber(viewportWidth, 'viewport width');
    assertPositiveFiniteNumber(viewportHeight, 'viewport height');
    assertNonNegativeFiniteNumber(viewportPadding, 'padding');

    const innerWidth = viewportWidth - (viewportPadding * 2);
    const innerHeight = viewportHeight - (viewportPadding * 2);
    if (innerWidth <= 0 || innerHeight <= 0) {
        throw new RangeError('Padding must leave a positive viewport area');
    }

    const worldWidth = bounds.maxX - bounds.minX;
    const worldHeight = bounds.maxY - bounds.minY;
    const xScale = worldWidth === 0 ? Number.POSITIVE_INFINITY : innerWidth / worldWidth;
    const yScale = worldHeight === 0 ? Number.POSITIVE_INFINITY : innerHeight / worldHeight;
    const scale = Number.isFinite(Math.min(xScale, yScale)) ? Math.min(xScale, yScale) : 1;

    return {
        centerX: (bounds.minX + bounds.maxX) / 2,
        centerY: (bounds.minY + bounds.maxY) / 2,
        scale,
        viewportWidth,
        viewportHeight,
        padding: viewportPadding,
    };
}

/** Project Brewall/world coordinates into screen coordinates. */
export function worldToScreen(
    pointOrX,
    yOrTransform,
    transformOrWidth,
    zoomOrHeight,
    objectZoom = 1,
    objectPanX = 0,
    objectPanY = 0,
) {
    if (pointOrX !== null && typeof pointOrX === 'object') {
        const point = pointOrX;
        const transform = yOrTransform;
        const viewportWidth = transformOrWidth ?? transform?.viewportWidth;
        const viewportHeight = zoomOrHeight ?? transform?.viewportHeight;

        assertFiniteNumber(point.x, 'x');
        assertFiniteNumber(point.y, 'y');
        validateTransform(transform, false);
        assertPositiveFiniteNumber(viewportWidth, 'viewport width');
        assertPositiveFiniteNumber(viewportHeight, 'viewport height');
        assertPositiveFiniteNumber(objectZoom, 'zoom');
        assertFiniteNumber(objectPanX, 'panX');
        assertFiniteNumber(objectPanY, 'panY');

        const scale = transform.scale * objectZoom;
        return {
            x: (viewportWidth / 2) + ((point.x - transform.centerX) * scale) + objectPanX,
            y: (viewportHeight / 2) + ((point.y - transform.centerY) * scale) + objectPanY,
        };
    }

    const x = pointOrX;
    const y = yOrTransform;
    const transform = transformOrWidth;
    const zoom = zoomOrHeight ?? 1;
    assertFiniteNumber(x, 'x');
    assertFiniteNumber(y, 'y');
    validateTransform(transform);
    assertPositiveFiniteNumber(zoom, 'zoom');

    const scale = transform.scale * zoom;
    return [
        (transform.viewportWidth / 2) + ((x - transform.centerX) * scale),
        (transform.viewportHeight / 2) + ((y - transform.centerY) * scale),
    ];
}

/** Reverse worldToScreen for pointer hit-testing and map readouts. */
export function screenToWorld(
    pointOrX,
    yOrTransform,
    transformOrWidth,
    zoomOrHeight,
    objectZoom = 1,
    objectPanX = 0,
    objectPanY = 0,
) {
    if (pointOrX !== null && typeof pointOrX === 'object') {
        const point = pointOrX;
        const transform = yOrTransform;
        const viewportWidth = transformOrWidth ?? transform?.viewportWidth;
        const viewportHeight = zoomOrHeight ?? transform?.viewportHeight;

        assertFiniteNumber(point.x, 'screenX');
        assertFiniteNumber(point.y, 'screenY');
        validateTransform(transform, false);
        assertPositiveFiniteNumber(viewportWidth, 'viewport width');
        assertPositiveFiniteNumber(viewportHeight, 'viewport height');
        assertPositiveFiniteNumber(objectZoom, 'zoom');
        assertFiniteNumber(objectPanX, 'panX');
        assertFiniteNumber(objectPanY, 'panY');

        const scale = transform.scale * objectZoom;
        return {
            x: transform.centerX + ((point.x - objectPanX - (viewportWidth / 2)) / scale),
            y: transform.centerY + ((point.y - objectPanY - (viewportHeight / 2)) / scale),
        };
    }

    const screenX = pointOrX;
    const screenY = yOrTransform;
    const transform = transformOrWidth;
    const zoom = zoomOrHeight ?? 1;
    assertFiniteNumber(screenX, 'screenX');
    assertFiniteNumber(screenY, 'screenY');
    validateTransform(transform);
    assertPositiveFiniteNumber(zoom, 'zoom');

    const scale = transform.scale * zoom;
    return [
        transform.centerX + ((screenX - (transform.viewportWidth / 2)) / scale),
        transform.centerY + ((screenY - (transform.viewportHeight / 2)) / scale),
    ];
}

export function clampZoom(zoom, minimum = 0.25, maximum = 16) {
    assertPositiveFiniteNumber(minimum, 'minimum zoom');
    assertPositiveFiniteNumber(maximum, 'maximum zoom');
    if (minimum > maximum) {
        throw new RangeError('Minimum zoom cannot exceed maximum zoom');
    }
    assertFiniteNumber(zoom, 'zoom');

    return Math.min(maximum, Math.max(minimum, zoom));
}

/**
 * Format coordinates without altering their canonical axes.
 *
 * Supports formatCoordinates({x, y, z}, order, fractionDigits) and
 * formatCoordinates(x, y, z, order, fractionDigits).
 */
export function formatCoordinates(
    pointOrX,
    yOrOrder = 'xyz',
    zOrFractionDigits = 0,
    numericOrder = 'xyz',
    numericFractionDigits = 0,
) {
    let point;
    let order;
    let fractionDigits;

    if (pointOrX !== null && typeof pointOrX === 'object') {
        point = pointOrX;
        order = yOrOrder;
        fractionDigits = zOrFractionDigits;
    } else {
        point = { x: pointOrX, y: yOrOrder, z: zOrFractionDigits };
        order = numericOrder;
        fractionDigits = numericFractionDigits;
    }

    const { x, y, z } = point;
    assertFiniteNumber(x, 'x');
    assertFiniteNumber(y, 'y');
    assertFiniteNumber(z, 'z');

    if (order !== 'xyz' && order !== 'yxz') {
        throw new RangeError("Coordinate order must be either 'xyz' or 'yxz'");
    }
    if (!Number.isInteger(fractionDigits) || fractionDigits < 0 || fractionDigits > 6) {
        throw new RangeError('fractionDigits must be an integer from 0 through 6');
    }

    const values = order === 'yxz' ? [y, x, z] : [x, y, z];
    return values.map((value) => formatNumber(value, fractionDigits)).join(', ');
}

function asByteView(input) {
    try {
        if (input instanceof ArrayBuffer) {
            return new Uint8Array(input);
        }
        if (typeof SharedArrayBuffer !== 'undefined' && input instanceof SharedArrayBuffer) {
            return new Uint8Array(input);
        }
        if (ArrayBuffer.isView(input)) {
            return new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
        }
    } catch {
        // Convert detached or otherwise invalid backing stores into one stable API error.
    }

    throw new TypeError('EQM1 input must be an ArrayBuffer or ArrayBuffer view');
}

function normalizeLimits(overrides) {
    if (overrides === null || typeof overrides !== 'object' || Array.isArray(overrides)) {
        throw new TypeError('EQM1 parser limits must be an object');
    }

    const limits = { ...EQM1_DEFAULT_LIMITS };
    for (const key of Object.keys(limits)) {
        if (overrides[key] === undefined) {
            continue;
        }
        if (!Number.isSafeInteger(overrides[key]) || overrides[key] < 0) {
            throw new RangeError(`${key} must be a non-negative safe integer`);
        }
        limits[key] = overrides[key];
    }

    return limits;
}

function requireBytes(bytes, offset, count, context) {
    if (count < 0 || offset < 0 || offset > bytes.byteLength - count) {
        fail(
            'ERR_EQM_TRUNCATED',
            `Truncated EQM1 ${context}: need ${count} byte(s) at byte ${offset}`,
            Math.min(offset, bytes.byteLength),
        );
    }
}

function readFloat32Array(bytes, view, offset, count) {
    const absoluteOffset = bytes.byteOffset + offset;
    if (littleEndianHost && absoluteOffset % Float32Array.BYTES_PER_ELEMENT === 0) {
        return new Float32Array(bytes.buffer, absoluteOffset, count);
    }

    const values = new Float32Array(count);
    for (let index = 0; index < count; index += 1) {
        values[index] = view.getFloat32(offset + (index * Float32Array.BYTES_PER_ELEMENT), true);
    }
    return values;
}

function paddingFor(byteLength) {
    return (4 - (byteLength % 4)) % 4;
}

function validateBounds(bounds) {
    const names = ['minX', 'minY', 'minZ', 'maxX', 'maxY', 'maxZ'];
    names.forEach((name, index) => {
        if (!Number.isFinite(bounds[name])) {
            fail('ERR_EQM_BOUNDS', `EQM1 bound ${name} must be finite`, 16 + (index * 4));
        }
    });

    if (bounds.minX > bounds.maxX) {
        fail('ERR_EQM_BOUNDS', 'EQM1 minX cannot exceed maxX', 16);
    }
    if (bounds.minY > bounds.maxY) {
        fail('ERR_EQM_BOUNDS', 'EQM1 minY cannot exceed maxY', 20);
    }
    if (bounds.minZ > bounds.maxZ) {
        fail('ERR_EQM_BOUNDS', 'EQM1 minZ cannot exceed maxZ', 24);
    }
}

function validatePointCoordinates(pointIndex, x, y, z, offset) {
    [x, y, z].forEach((value, coordinateIndex) => {
        if (!Number.isFinite(value)) {
            fail(
                'ERR_EQM_COORDINATE',
                `Point ${pointIndex} contains a non-finite coordinate`,
                offset + (coordinateIndex * 4),
            );
        }
    });
}

function enforceCountLimit(label, count, maximum, offset) {
    if (count > maximum) {
        fail('ERR_EQM_LIMIT', `EQM1 ${label} count ${count} exceeds limit ${maximum}`, offset);
    }
}

function validatePlanarBounds(bounds) {
    if (bounds === null || typeof bounds !== 'object') {
        throw new TypeError('Bounds must be an object');
    }
    ['minX', 'minY', 'maxX', 'maxY'].forEach((name) => assertFiniteNumber(bounds[name], name));
    if (bounds.minX > bounds.maxX || bounds.minY > bounds.maxY) {
        throw new RangeError('Minimum bounds cannot exceed maximum bounds');
    }
}

function validateTransform(transform, requireViewport = true) {
    if (transform === null || typeof transform !== 'object') {
        throw new TypeError('Transform must be an object returned by fitBounds');
    }
    assertFiniteNumber(transform.centerX, 'transform centerX');
    assertFiniteNumber(transform.centerY, 'transform centerY');
    assertPositiveFiniteNumber(transform.scale, 'transform scale');
    if (requireViewport) {
        assertPositiveFiniteNumber(transform.viewportWidth, 'transform viewport width');
        assertPositiveFiniteNumber(transform.viewportHeight, 'transform viewport height');
    }
}

function formatNumber(value, fractionDigits) {
    const formatted = (Object.is(value, -0) ? 0 : value).toFixed(fractionDigits);
    return Number(formatted) === 0 ? (0).toFixed(fractionDigits) : formatted;
}

function assertFiniteNumber(value, label) {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        throw new TypeError(`${label} must be a finite number`);
    }
}

function assertPositiveFiniteNumber(value, label) {
    assertFiniteNumber(value, label);
    if (value <= 0) {
        throw new RangeError(`${label} must be greater than zero`);
    }
}

function assertNonNegativeFiniteNumber(value, label) {
    assertFiniteNumber(value, label);
    if (value < 0) {
        throw new RangeError(`${label} cannot be negative`);
    }
}

function fail(code, message, offset) {
    throw new EqMapFormatError(message, { code, offset });
}
