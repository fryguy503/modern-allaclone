#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, unlink, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TextDecoder } from 'node:util';

const FORMAT_MAGIC = 'EQM1';
const FORMAT_VERSION = 1;
const HEADER_BYTES = 40;
const SEGMENT_BYTES = 24;
const COLOR_GROUP_HEADER_BYTES = 8;
const POINT_FIXED_BYTES = 20;

const MAX_ZONE_COUNT = 10_000;
const MAX_SOURCE_BYTES = 8 * 1024 * 1024;
const MAX_TOTAL_SOURCE_BYTES = 1024 * 1024 * 1024;
const MAX_LINE_BYTES = 64 * 1024;
const MAX_LINES_PER_ZONE = 1_000_000;
const MAX_RECORDS_PER_ZONE = 1_000_000;
const MAX_COLOR_GROUPS = 4096;
const MAX_POINTS_PER_ZONE = 250_000;
const MAX_TOTAL_RECORDS = 20_000_000;
const MAX_LABEL_BYTES = 4 * 1024;
const MAX_COMPILED_BYTES = 8 * 1024 * 1024;
const MAX_MANIFEST_BYTES = 5 * 1024 * 1024;
const MAX_ANNOTATIONS_PER_VARIANT = 256;
const MAX_ANNOTATION_LABEL_BYTES = 240;
const MAX_ANNOTATION_LABEL_CHARACTERS = 160;
const MAX_ANNOTATION_COORDINATE = 1_000_000;
const ANNOTATION_BOUNDS_MARGIN_RATIO = 0.25;
const MIN_ANNOTATION_BOUNDS_MARGIN = 64;

const FLOAT_TOKEN = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;
const INTEGER_TOKEN = /^[+-]?\d+$/;
const BASE_MAP_FILE = /^([a-z0-9]+)\.txt$/;
const UTF8_DECODER = new TextDecoder('utf-8', { fatal: true });

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(scriptDirectory, '..');
const defaults = {
    source: resolve(repositoryRoot, '..', 'brewels'),
    output: resolve(repositoryRoot, 'public', 'maps'),
};

function usage() {
    return [
        'Usage: node scripts/build-eq-maps.mjs [options]',
        '',
        'Options:',
        `  --source <path>  Brewall map directory (default: ${defaults.source})`,
        `  --output <path>  Generated map directory (default: ${defaults.output})`,
        '  --check          Validate sources and generated output without writing',
        '  --help           Show this help',
    ].join('\n');
}

function parseArguments(argv) {
    const options = { ...defaults, check: false, help: false };

    for (let index = 0; index < argv.length; index += 1) {
        const argument = argv[index];

        if (argument === '--check') {
            options.check = true;
        } else if (argument === '--help' || argument === '-h') {
            options.help = true;
        } else if (argument === '--source' || argument === '--output') {
            const value = argv[index + 1];
            if (!value || value.startsWith('--')) {
                throw new Error(`${argument} requires a path`);
            }
            options[argument.slice(2)] = resolve(value);
            index += 1;
        } else if (argument.startsWith('--source=')) {
            options.source = resolve(argument.slice('--source='.length));
        } else if (argument.startsWith('--output=')) {
            options.output = resolve(argument.slice('--output='.length));
        } else {
            throw new Error(`Unknown argument: ${argument}`);
        }
    }

    return options;
}

function asciiCompare(left, right) {
    if (left === right) return 0;
    return left < right ? -1 : 1;
}

function naturalCompare(left, right) {
    const leftParts = left.match(/\d+|\D+/g) ?? [left];
    const rightParts = right.match(/\d+|\D+/g) ?? [right];
    const length = Math.min(leftParts.length, rightParts.length);

    for (let index = 0; index < length; index += 1) {
        const leftPart = leftParts[index];
        const rightPart = rightParts[index];
        const leftNumeric = /^\d+$/.test(leftPart);
        const rightNumeric = /^\d+$/.test(rightPart);

        if (leftNumeric && rightNumeric) {
            const leftNormalized = leftPart.replace(/^0+(?=\d)/, '');
            const rightNormalized = rightPart.replace(/^0+(?=\d)/, '');
            if (leftNormalized.length !== rightNormalized.length) {
                return leftNormalized.length - rightNormalized.length;
            }
            const numericOrder = asciiCompare(leftNormalized, rightNormalized);
            if (numericOrder !== 0) return numericOrder;
            if (leftPart.length !== rightPart.length) return leftPart.length - rightPart.length;
        } else {
            const textOrder = asciiCompare(leftPart, rightPart);
            if (textOrder !== 0) return textOrder;
        }
    }

    if (leftParts.length !== rightParts.length) return leftParts.length - rightParts.length;
    return asciiCompare(left, right);
}

function context(filePath, lineNumber, message) {
    return new Error(`${filePath}:${lineNumber}: ${message}`);
}

function parseFloat32(rawValue, field, filePath, lineNumber) {
    const token = rawValue.trim();
    if (!FLOAT_TOKEN.test(token)) {
        throw context(filePath, lineNumber, `${field} is not a strict finite number: ${JSON.stringify(token)}`);
    }

    const value = Number(token);
    const floatValue = Math.fround(value);
    if (!Number.isFinite(value) || !Number.isFinite(floatValue)) {
        throw context(filePath, lineNumber, `${field} is outside float32 range`);
    }

    return floatValue;
}

function parseInteger(rawValue, field, minimum, maximum, filePath, lineNumber) {
    const token = rawValue.trim();
    if (!INTEGER_TOKEN.test(token)) {
        throw context(filePath, lineNumber, `${field} is not an integer: ${JSON.stringify(token)}`);
    }

    const value = Number(token);
    if (!Number.isSafeInteger(value) || value < minimum || value > maximum) {
        throw context(filePath, lineNumber, `${field} must be in ${minimum}..${maximum}`);
    }

    return value;
}

function splitPointFields(value) {
    const fields = [];
    let start = 0;

    for (let index = 0; index < 7; index += 1) {
        const comma = value.indexOf(',', start);
        if (comma === -1) return null;
        fields.push(value.slice(start, comma));
        start = comma + 1;
    }

    fields.push(value.slice(start));
    return fields;
}

function newBounds() {
    return {
        minX: Number.POSITIVE_INFINITY,
        minY: Number.POSITIVE_INFINITY,
        minZ: Number.POSITIVE_INFINITY,
        maxX: Number.NEGATIVE_INFINITY,
        maxY: Number.NEGATIVE_INFINITY,
        maxZ: Number.NEGATIVE_INFINITY,
    };
}

function includeCoordinate(bounds, x, y, z) {
    bounds.minX = Math.min(bounds.minX, x);
    bounds.minY = Math.min(bounds.minY, y);
    bounds.minZ = Math.min(bounds.minZ, z);
    bounds.maxX = Math.max(bounds.maxX, x);
    bounds.maxY = Math.max(bounds.maxY, y);
    bounds.maxZ = Math.max(bounds.maxZ, z);
}

function boundsArray(bounds) {
    return [bounds.minX, bounds.minY, bounds.minZ, bounds.maxX, bounds.maxY, bounds.maxZ];
}

function validateRawSource(source, filePath, { allowEmpty = false } = {}) {
    if (source.length === 0) {
        if (allowEmpty) return;
        throw new Error(`${filePath}: source file is empty`);
    }
    if (source.length > MAX_SOURCE_BYTES) {
        throw new Error(`${filePath}: source exceeds ${MAX_SOURCE_BYTES} bytes`);
    }

    let lineStart = 0;
    let lineCount = 1;
    for (let index = 0; index < source.length; index += 1) {
        const byte = source[index];
        if (byte === 0) throw new Error(`${filePath}: NUL byte is not allowed`);
        if (byte !== 0x0a && byte !== 0x0d) continue;

        const lineLength = index - lineStart;
        if (lineLength > MAX_LINE_BYTES) {
            throw new Error(`${filePath}:${lineCount}: line exceeds ${MAX_LINE_BYTES} bytes`);
        }

        if (byte === 0x0d && source[index + 1] === 0x0a) index += 1;
        lineStart = index + 1;
        lineCount += 1;
        if (lineCount > MAX_LINES_PER_ZONE) {
            throw new Error(`${filePath}: source exceeds ${MAX_LINES_PER_ZONE} lines`);
        }
    }

    if (source.length - lineStart > MAX_LINE_BYTES) {
        throw new Error(`${filePath}:${lineCount}: line exceeds ${MAX_LINE_BYTES} bytes`);
    }
}

function parseLineRecord(value, filePath, lineNumber) {
    const fields = value.split(',');
    if (fields.length !== 9) {
        throw context(filePath, lineNumber, `L record must contain exactly 9 fields; found ${fields.length}`);
    }

    const coordinates = fields.slice(0, 6).map((coordinate, fieldIndex) =>
        parseFloat32(coordinate, `coordinate ${fieldIndex + 1}`, filePath, lineNumber),
    );

    return {
        coordinates,
        red: parseInteger(fields[6], 'red', 0, 255, filePath, lineNumber),
        green: parseInteger(fields[7], 'green', 0, 255, filePath, lineNumber),
        blue: parseInteger(fields[8], 'blue', 0, 255, filePath, lineNumber),
    };
}

function parsePointRecord(value, filePath, lineNumber) {
    const fields = splitPointFields(value);
    if (!fields) throw context(filePath, lineNumber, 'P record must contain at least 8 fields');

    const x = parseFloat32(fields[0], 'x', filePath, lineNumber);
    const y = parseFloat32(fields[1], 'y', filePath, lineNumber);
    const z = parseFloat32(fields[2], 'z', filePath, lineNumber);
    const red = parseInteger(fields[3], 'red', 0, 255, filePath, lineNumber);
    const green = parseInteger(fields[4], 'green', 0, 255, filePath, lineNumber);
    const blue = parseInteger(fields[5], 'blue', 0, 255, filePath, lineNumber);
    const size = parseInteger(fields[6], 'size', 1, 3, filePath, lineNumber);
    const label = fields[7].trim();
    if (label === '') throw context(filePath, lineNumber, 'point label may not be empty');
    const labelBytes = Buffer.from(label, 'utf8');
    if (labelBytes.length > MAX_LABEL_BYTES || labelBytes.length > 0xffff) {
        throw context(filePath, lineNumber, `point label exceeds ${Math.min(MAX_LABEL_BYTES, 0xffff)} bytes`);
    }

    return { x, y, z, red, green, blue, size, labelBytes, lineNumber };
}

function parseAnnotationPointRecord(value, filePath, lineNumber) {
    const fields = splitPointFields(value);
    if (!fields) throw context(filePath, lineNumber, 'P annotation must contain at least 8 fields');

    const label = fields[7].trim();
    if (label === '') throw context(filePath, lineNumber, 'point annotation label may not be empty');

    return {
        x: parseFloat32(fields[0], 'x', filePath, lineNumber),
        y: parseFloat32(fields[1], 'y', filePath, lineNumber),
        z: parseFloat32(fields[2], 'z', filePath, lineNumber),
        size: parseInteger(fields[6], 'size', 1, 3, filePath, lineNumber),
        labelBytes: Buffer.from(label, 'utf8'),
        lineNumber,
    };
}

function decodePointLabel(point, filePath) {
    try {
        return UTF8_DECODER.decode(point.labelBytes);
    } catch {
        throw context(filePath, point.lineNumber, 'point label is not valid UTF-8');
    }
}

function sanitizeAnnotationLabel(point, filePath) {
    const label = decodePointLabel(point, filePath)
        .normalize('NFKC')
        .replace(/[\u0000-\u001f\u007f-\u009f]/g, ' ')
        .replaceAll('_', ' ')
        .replace(/\s+/gu, ' ')
        .trim();

    if (label === '') return null;

    const byteLength = Buffer.byteLength(label, 'utf8');
    const characterLength = [...label].length;
    if (byteLength > MAX_ANNOTATION_LABEL_BYTES
        || characterLength > MAX_ANNOTATION_LABEL_CHARACTERS) {
        throw context(
            filePath,
            point.lineNumber,
            `map annotation label exceeds ${MAX_ANNOTATION_LABEL_CHARACTERS} characters or ${MAX_ANNOTATION_LABEL_BYTES} bytes`,
        );
    }

    return label;
}

function annotationKind(label) {
    if (/^(?:to|(?:zone[\s-]*(?:in|out|line)|zoneline)|(?:exit|return)\s+to)\b/iu.test(label)) {
        return 'zone-line';
    }
    if (/^(?:knowledge|(?:the\s+)?plane\s+of\s+knowledge|p\.?\s*o\.?\s*k\.?)\s+(?:portal|book)\b/iu.test(label)) {
        return 'portal';
    }
    if (/^(?:(?:an?\s+)?portals?|teleport(?:s|er|ers|ation)?|translocators?)\b.*\b(?:attun(?:e|er|ement)?|keys?)\b/iu.test(label)) {
        return null;
    }
    if (/^(?:(?:an?\s+)?portals?|teleport(?:s|er|ers|ation)?|translocators?)\b/iu.test(label)) {
        return 'portal';
    }

    return null;
}

function pointValueHasAnnotationLabel(value) {
    const fields = splitPointFields(value);
    if (!fields) return false;

    const size = fields[6].trim();
    if (!INTEGER_TOKEN.test(size) || Number(size) !== 3) return false;

    const label = fields[7]
        .trim()
        .normalize('NFKC')
        .replace(/[\u0000-\u001f\u007f-\u009f]/g, ' ')
        .replaceAll('_', ' ')
        .replace(/\s+/gu, ' ')
        .trim();

    return label !== '' && annotationKind(label) !== null;
}

function annotationFromPoint(point, filePath) {
    if (point.size !== 3) return null;

    const label = sanitizeAnnotationLabel(point, filePath);
    const kind = label === null ? null : annotationKind(label);
    if (kind === null) return null;

    for (const [axis, value] of Object.entries({ x: point.x, y: point.y, z: point.z })) {
        if (!Number.isFinite(value) || Math.abs(value) > MAX_ANNOTATION_COORDINATE) {
            throw context(
                filePath,
                point.lineNumber,
                `map annotation ${axis} must be within ±${MAX_ANNOTATION_COORDINATE}`,
            );
        }
    }

    return {
        kind,
        label,
        position: {
            x: Object.is(point.x, -0) ? 0 : point.x,
            y: Object.is(point.y, -0) ? 0 : point.y,
            z: Object.is(point.z, -0) ? 0 : point.z,
        },
    };
}

function annotationWithinBounds(annotation, bounds) {
    const spanX = Math.max(0, bounds.maxX - bounds.minX);
    const spanY = Math.max(0, bounds.maxY - bounds.minY);
    // Brewall tags can sit just beyond the last drawn wall. A 25% proportional
    // gutter handles large outdoor maps, while 64 units keeps small interiors
    // useful without accepting the clearly unrelated coordinate spaces found
    // in some historical overlays.
    const marginX = Math.max(MIN_ANNOTATION_BOUNDS_MARGIN, spanX * ANNOTATION_BOUNDS_MARGIN_RATIO);
    const marginY = Math.max(MIN_ANNOTATION_BOUNDS_MARGIN, spanY * ANNOTATION_BOUNDS_MARGIN_RATIO);

    return annotation.position.x >= bounds.minX - marginX
        && annotation.position.x <= bounds.maxX + marginX
        && annotation.position.y >= bounds.minY - marginY
        && annotation.position.y <= bounds.maxY + marginY;
}

function collectAnnotations(pointSources, bounds) {
    const annotations = [];
    const seen = new Set();

    for (const { filePath, points, constrainToBounds = false } of pointSources) {
        for (const point of points) {
            const annotation = annotationFromPoint(point, filePath);
            if (annotation === null) continue;
            if (constrainToBounds && !annotationWithinBounds(annotation, bounds)) continue;
            const key = JSON.stringify([
                annotation.kind,
                annotation.label.toLocaleLowerCase('en-US'),
                annotation.position.x,
                annotation.position.y,
                annotation.position.z,
            ]);
            if (seen.has(key)) continue;
            seen.add(key);
            annotations.push(annotation);
            if (annotations.length > MAX_ANNOTATIONS_PER_VARIANT) {
                throw new Error(`Map variant exceeds ${MAX_ANNOTATIONS_PER_VARIANT} portal and zone-line annotations`);
            }
        }
    }

    return annotations;
}

function parseAnnotationOverlay(source, filePath) {
    validateRawSource(source, filePath, { allowEmpty: true });
    if (source.length === 0) return { points: [], recordCount: 0 };

    let content;
    try {
        content = UTF8_DECODER.decode(source);
    } catch {
        throw new Error(`${filePath}: source is not valid UTF-8`);
    }

    const points = [];
    let recordCount = 0;
    const lines = content.split(/\r\n|\n|\r/);
    for (let index = 0; index < lines.length; index += 1) {
        const lineNumber = index + 1;
        const line = lines[index].trim();
        if (line === '') continue;

        recordCount += 1;
        if (recordCount > MAX_RECORDS_PER_ZONE) {
            throw new Error(`${filePath}: source exceeds ${MAX_RECORDS_PER_ZONE} records`);
        }

        const match = /^([LP])\s+(.+)$/.exec(line);
        if (!match) throw context(filePath, lineNumber, 'record must begin with "L " or "P "');
        if (match[1] === 'L') {
            // Overlay geometry is intentionally ignored. Raw byte, line, and
            // record ceilings above still bound the amount of work performed.
            continue;
        }

        // Historical overlays contain a few malformed non-navigation P records.
        // Parse selected navigation annotations strictly without allowing
        // unrelated overlay content to break otherwise valid base maps.
        if (!pointValueHasAnnotationLabel(match[2])) continue;
        points.push(parseAnnotationPointRecord(match[2], filePath, lineNumber));
        if (points.length > MAX_POINTS_PER_ZONE) {
            throw new Error(`${filePath}: source exceeds ${MAX_POINTS_PER_ZONE} points`);
        }
    }

    return { points, recordCount };
}

function parseMap(source, filePath, zone) {
    validateRawSource(source, filePath);

    let content;
    try {
        content = UTF8_DECODER.decode(source);
    } catch {
        throw new Error(`${filePath}: source is not valid UTF-8`);
    }

    const colorGroups = new Map();
    const points = [];
    const bounds = newBounds();
    let segmentCount = 0;
    let recordCount = 0;
    const lines = content.split(/\r\n|\n|\r/);

    for (let index = 0; index < lines.length; index += 1) {
        const lineNumber = index + 1;
        const line = lines[index].trim();
        if (line === '') continue;

        recordCount += 1;
        if (recordCount > MAX_RECORDS_PER_ZONE) {
            throw new Error(`${filePath}: source exceeds ${MAX_RECORDS_PER_ZONE} records`);
        }

        const match = /^([LP])\s+(.+)$/.exec(line);
        if (!match) throw context(filePath, lineNumber, 'record must begin with "L " or "P "');

        if (match[1] === 'L') {
            const { coordinates, red, green, blue } = parseLineRecord(match[2], filePath, lineNumber);
            const colorKey = (red << 16) | (green << 8) | blue;
            let group = colorGroups.get(colorKey);
            if (!group) {
                group = { red, green, blue, segments: [] };
                colorGroups.set(colorKey, group);
            }
            group.segments.push(coordinates);
            segmentCount += 1;
            includeCoordinate(bounds, coordinates[0], coordinates[1], coordinates[2]);
            includeCoordinate(bounds, coordinates[3], coordinates[4], coordinates[5]);
        } else {
            const point = parsePointRecord(match[2], filePath, lineNumber);
            points.push(point);
            if (points.length > MAX_POINTS_PER_ZONE) {
                throw new Error(`${filePath}: source exceeds ${MAX_POINTS_PER_ZONE} points`);
            }
            includeCoordinate(bounds, point.x, point.y, point.z);
        }
    }

    if (recordCount === 0 || boundsArray(bounds).some((value) => !Number.isFinite(value))) {
        throw new Error(`${filePath}: map contains no coordinate records`);
    }
    if (segmentCount > 0xffffffff || points.length > 0xffffffff) {
        throw new Error(`${filePath}: record count exceeds EQM1 header capacity`);
    }

    const groups = [...colorGroups.values()].sort((left, right) =>
        left.red - right.red || left.green - right.green || left.blue - right.blue,
    );
    if (groups.length > MAX_COLOR_GROUPS) {
        throw new Error(`${filePath}: source exceeds ${MAX_COLOR_GROUPS} color groups`);
    }

    return { zone, groups, points, bounds, segmentCount, recordCount, sourceBytes: source.length };
}

function pointRecordBytes(point) {
    const unpadded = POINT_FIXED_BYTES + point.labelBytes.length;
    return unpadded + ((4 - (unpadded % 4)) % 4);
}

function compileMap(parsed) {
    let byteLength = HEADER_BYTES;
    for (const group of parsed.groups) {
        byteLength += COLOR_GROUP_HEADER_BYTES + group.segments.length * SEGMENT_BYTES;
    }
    for (const point of parsed.points) byteLength += pointRecordBytes(point);

    if (!Number.isSafeInteger(byteLength) || byteLength > MAX_COMPILED_BYTES) {
        throw new Error(`${parsed.zone}: compiled map exceeds ${MAX_COMPILED_BYTES} bytes`);
    }

    const output = Buffer.alloc(byteLength);
    output.write(FORMAT_MAGIC, 0, 4, 'ascii');
    output.writeUInt16LE(FORMAT_VERSION, 4);
    output.writeUInt16LE(parsed.groups.length, 6);
    output.writeUInt32LE(parsed.segmentCount, 8);
    output.writeUInt32LE(parsed.points.length, 12);

    boundsArray(parsed.bounds).forEach((value, index) => output.writeFloatLE(value, 16 + index * 4));

    let offset = HEADER_BYTES;
    for (const group of parsed.groups) {
        output[offset] = group.red;
        output[offset + 1] = group.green;
        output[offset + 2] = group.blue;
        output[offset + 3] = 0;
        output.writeUInt32LE(group.segments.length, offset + 4);
        offset += COLOR_GROUP_HEADER_BYTES;

        for (const segment of group.segments) {
            for (const coordinate of segment) {
                output.writeFloatLE(coordinate, offset);
                offset += 4;
            }
        }
    }

    for (const point of parsed.points) {
        const recordStart = offset;
        output.writeFloatLE(point.x, offset);
        output.writeFloatLE(point.y, offset + 4);
        output.writeFloatLE(point.z, offset + 8);
        output[offset + 12] = point.red;
        output[offset + 13] = point.green;
        output[offset + 14] = point.blue;
        output[offset + 15] = point.size;
        output.writeUInt16LE(point.labelBytes.length, offset + 16);
        output.writeUInt16LE(0, offset + 18);
        offset += POINT_FIXED_BYTES;
        point.labelBytes.copy(output, offset);
        offset += point.labelBytes.length;
        offset += (4 - ((offset - recordStart) % 4)) % 4;
    }

    if (offset !== output.length) throw new Error(`${parsed.zone}: internal byte-length mismatch`);
    validateCompiledMap(output, parsed);
    return output;
}

function ensureReadable(buffer, offset, length, zone) {
    if (offset < 0 || length < 0 || offset + length > buffer.length) {
        throw new Error(`${zone}: truncated EQM1 payload at byte ${offset}`);
    }
}

function validateCompiledMap(buffer, expected) {
    const zone = expected.zone;
    ensureReadable(buffer, 0, HEADER_BYTES, zone);
    if (buffer.toString('ascii', 0, 4) !== FORMAT_MAGIC) throw new Error(`${zone}: invalid EQM1 magic`);
    if (buffer.readUInt16LE(4) !== FORMAT_VERSION) throw new Error(`${zone}: unsupported EQM1 version`);

    const groupCount = buffer.readUInt16LE(6);
    const segmentCount = buffer.readUInt32LE(8);
    const pointCount = buffer.readUInt32LE(12);
    if (groupCount !== expected.groups.length) throw new Error(`${zone}: color-group count mismatch`);
    if (segmentCount !== expected.segmentCount) throw new Error(`${zone}: segment count mismatch`);
    if (pointCount !== expected.points.length) throw new Error(`${zone}: point count mismatch`);

    const headerBounds = [];
    for (let index = 0; index < 6; index += 1) {
        const value = buffer.readFloatLE(16 + index * 4);
        if (!Number.isFinite(value)) throw new Error(`${zone}: non-finite header bound`);
        headerBounds.push(value);
    }
    const expectedBounds = boundsArray(expected.bounds);
    if (headerBounds.some((value, index) => value !== expectedBounds[index])) {
        throw new Error(`${zone}: header bounds mismatch`);
    }

    const decodedBounds = newBounds();
    let decodedSegments = 0;
    let offset = HEADER_BYTES;
    let previousColor = -1;

    for (let groupIndex = 0; groupIndex < groupCount; groupIndex += 1) {
        ensureReadable(buffer, offset, COLOR_GROUP_HEADER_BYTES, zone);
        const red = buffer[offset];
        const green = buffer[offset + 1];
        const blue = buffer[offset + 2];
        if (buffer[offset + 3] !== 0) throw new Error(`${zone}: nonzero color-group reserved byte`);
        const color = (red << 16) | (green << 8) | blue;
        if (color <= previousColor) throw new Error(`${zone}: color groups are not strictly ordered`);
        previousColor = color;
        const count = buffer.readUInt32LE(offset + 4);
        if (count === 0) throw new Error(`${zone}: empty color group`);
        offset += COLOR_GROUP_HEADER_BYTES;
        ensureReadable(buffer, offset, count * SEGMENT_BYTES, zone);

        for (let segmentIndex = 0; segmentIndex < count; segmentIndex += 1) {
            const coordinates = [];
            for (let coordinateIndex = 0; coordinateIndex < 6; coordinateIndex += 1) {
                const value = buffer.readFloatLE(offset);
                if (!Number.isFinite(value)) throw new Error(`${zone}: non-finite segment coordinate`);
                coordinates.push(value);
                offset += 4;
            }
            includeCoordinate(decodedBounds, coordinates[0], coordinates[1], coordinates[2]);
            includeCoordinate(decodedBounds, coordinates[3], coordinates[4], coordinates[5]);
        }
        decodedSegments += count;
    }

    if (decodedSegments !== segmentCount) throw new Error(`${zone}: color-group segment total mismatch`);

    for (let pointIndex = 0; pointIndex < pointCount; pointIndex += 1) {
        const recordStart = offset;
        ensureReadable(buffer, offset, POINT_FIXED_BYTES, zone);
        const x = buffer.readFloatLE(offset);
        const y = buffer.readFloatLE(offset + 4);
        const z = buffer.readFloatLE(offset + 8);
        if (![x, y, z].every(Number.isFinite)) throw new Error(`${zone}: non-finite point coordinate`);
        const size = buffer[offset + 15];
        if (size < 1 || size > 3) throw new Error(`${zone}: invalid point size`);
        const labelLength = buffer.readUInt16LE(offset + 16);
        if (labelLength === 0 || labelLength > MAX_LABEL_BYTES) throw new Error(`${zone}: invalid point label length`);
        if (buffer.readUInt16LE(offset + 18) !== 0) throw new Error(`${zone}: nonzero point reserved field`);
        offset += POINT_FIXED_BYTES;
        ensureReadable(buffer, offset, labelLength, zone);
        const labelBytes = buffer.subarray(offset, offset + labelLength);
        try {
            UTF8_DECODER.decode(labelBytes);
        } catch {
            throw new Error(`${zone}: point label is not valid UTF-8`);
        }
        offset += labelLength;
        const padding = (4 - ((offset - recordStart) % 4)) % 4;
        ensureReadable(buffer, offset, padding, zone);
        for (let paddingIndex = 0; paddingIndex < padding; paddingIndex += 1) {
            if (buffer[offset + paddingIndex] !== 0) throw new Error(`${zone}: nonzero point padding`);
        }
        offset += padding;
        includeCoordinate(decodedBounds, x, y, z);
    }

    if (offset !== buffer.length) throw new Error(`${zone}: trailing bytes after EQM1 records`);
    if (boundsArray(decodedBounds).some((value, index) => value !== headerBounds[index])) {
        throw new Error(`${zone}: header bounds do not match record bounds`);
    }
}

function sha256(buffer) {
    return createHash('sha256').update(buffer).digest('hex');
}

async function readIfPresent(filePath) {
    try {
        return await readFile(filePath);
    } catch (error) {
        if (error?.code === 'ENOENT') return null;
        throw error;
    }
}

async function writeIfChanged(filePath, content) {
    const existing = await readIfPresent(filePath);
    if (existing && existing.equals(content)) return false;
    await writeFile(filePath, content);
    return true;
}

async function sourceFiles(sourceDirectory, { optional = false } = {}) {
    let entries;
    try {
        entries = await readdir(sourceDirectory, { withFileTypes: true });
    } catch (error) {
        if (optional && error?.code === 'ENOENT') return [];
        throw new Error(`Unable to read source directory ${sourceDirectory}: ${error.message}`);
    }

    const files = entries
        .filter((entry) => entry.isFile() && BASE_MAP_FILE.test(entry.name))
        .map((entry) => ({ name: entry.name, zone: BASE_MAP_FILE.exec(entry.name)[1] }))
        .sort((left, right) => naturalCompare(left.zone, right.zone));

    if (!optional && files.length === 0) {
        throw new Error(`No lowercase alphanumeric base map files found in ${sourceDirectory}`);
    }
    if (files.length > MAX_ZONE_COUNT) throw new Error(`Source contains more than ${MAX_ZONE_COUNT} base maps`);
    return files;
}

function manifestBounds(bounds) {
    return {
        min_x: bounds.minX,
        min_y: bounds.minY,
        min_z: bounds.minZ,
        max_x: bounds.maxX,
        max_y: bounds.maxY,
        max_z: bounds.maxZ,
    };
}

async function readAnnotationOverlay(sourceDirectory, zone) {
    const filePath = resolve(sourceDirectory, `${zone}_1.txt`);
    const source = await readIfPresent(filePath);
    if (source === null) {
        return { filePath, sourceBytes: 0, recordCount: 0, points: [] };
    }

    const parsed = parseAnnotationOverlay(source, filePath);
    return {
        filePath,
        sourceBytes: source.length,
        recordCount: parsed.recordCount,
        points: parsed.points,
    };
}

async function build(options) {
    const files = await sourceFiles(options.source);
    const legacyDirectory = resolve(options.source, 'legacy');
    const legacyFiles = await sourceFiles(legacyDirectory, { optional: true });
    const defaultZones = new Set(files.map((file) => file.zone));
    const orphanedLegacyZones = legacyFiles
        .filter((file) => !defaultZones.has(file.zone))
        .map((file) => file.zone);
    if (orphanedLegacyZones.length > 0) {
        throw new Error(`Legacy maps have no matching base map: ${orphanedLegacyZones.join(', ')}`);
    }

    const zonesDirectory = resolve(options.output, 'zones');
    const manifestPath = resolve(options.output, 'manifest.json');

    if (!options.check) {
        await mkdir(zonesDirectory, { recursive: true });
    }

    const manifest = {
        version: FORMAT_VERSION,
        format: FORMAT_MAGIC,
        attribution: {
            name: 'Brewall Maps',
            url: 'https://www.eqmaps.info/',
        },
        zones: {},
    };
    const expectedOutputNames = new Set();
    let sourceByteTotal = 0;
    let compiledByteTotal = 0;
    let segmentTotal = 0;
    let pointTotal = 0;
    let annotationTotal = 0;
    let recordTotal = 0;
    let changedFiles = 0;

    for (const file of files) {
        const sourcePath = resolve(options.source, file.name);
        const source = await readFile(sourcePath);
        sourceByteTotal += source.length;
        if (sourceByteTotal > MAX_TOTAL_SOURCE_BYTES) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_SOURCE_BYTES} bytes`);
        }

        const parsed = parseMap(source, sourcePath, file.zone);
        recordTotal += parsed.recordCount;
        if (recordTotal > MAX_TOTAL_RECORDS) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_RECORDS} records`);
        }

        const overlay = await readAnnotationOverlay(options.source, file.zone);
        sourceByteTotal += overlay.sourceBytes;
        if (sourceByteTotal > MAX_TOTAL_SOURCE_BYTES) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_SOURCE_BYTES} bytes`);
        }
        recordTotal += overlay.recordCount;
        if (recordTotal > MAX_TOTAL_RECORDS) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_RECORDS} records`);
        }
        const annotations = collectAnnotations([
            { filePath: sourcePath, points: parsed.points },
            { filePath: overlay.filePath, points: overlay.points, constrainToBounds: true },
        ], parsed.bounds);

        const compiled = compileMap(parsed);
        const digest = sha256(compiled);
        const outputName = `${file.zone}.${digest.slice(0, 12)}.eqmap`;
        const outputPath = resolve(zonesDirectory, outputName);
        expectedOutputNames.add(outputName);

        if (options.check) {
            const existing = await readIfPresent(outputPath);
            if (!existing) throw new Error(`Missing generated map: ${outputPath}`);
            validateCompiledMap(existing, parsed);
            if (!existing.equals(compiled)) throw new Error(`Generated map differs from source: ${outputPath}`);
        } else if (await writeIfChanged(outputPath, compiled)) {
            changedFiles += 1;
        }

        manifest.zones[file.zone] = {
            path: `maps/zones/${outputName}`,
            sha256: digest,
            bytes: compiled.length,
            segments: parsed.segmentCount,
            points: parsed.points.length,
            bounds: manifestBounds(parsed.bounds),
            annotations,
        };

        compiledByteTotal += compiled.length;
        segmentTotal += parsed.segmentCount;
        pointTotal += parsed.points.length;
        annotationTotal += annotations.length;
    }

    for (const file of legacyFiles) {
        const sourcePath = resolve(legacyDirectory, file.name);
        const source = await readFile(sourcePath);
        sourceByteTotal += source.length;
        if (sourceByteTotal > MAX_TOTAL_SOURCE_BYTES) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_SOURCE_BYTES} bytes`);
        }

        const parsed = parseMap(source, sourcePath, `${file.zone}.legacy`);
        recordTotal += parsed.recordCount;
        if (recordTotal > MAX_TOTAL_RECORDS) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_RECORDS} records`);
        }

        // Legacy variants are isolated from the modern source tree: only the
        // legacy base and an optional sibling legacy/<zone>_1.txt contribute.
        const overlay = await readAnnotationOverlay(legacyDirectory, file.zone);
        sourceByteTotal += overlay.sourceBytes;
        if (sourceByteTotal > MAX_TOTAL_SOURCE_BYTES) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_SOURCE_BYTES} bytes`);
        }
        recordTotal += overlay.recordCount;
        if (recordTotal > MAX_TOTAL_RECORDS) {
            throw new Error(`Source set exceeds ${MAX_TOTAL_RECORDS} records`);
        }
        const annotations = collectAnnotations([
            { filePath: sourcePath, points: parsed.points },
            { filePath: overlay.filePath, points: overlay.points, constrainToBounds: true },
        ], parsed.bounds);

        const compiled = compileMap(parsed);
        const digest = sha256(compiled);
        const outputName = `${file.zone}.legacy.${digest.slice(0, 12)}.eqmap`;
        const outputPath = resolve(zonesDirectory, outputName);
        expectedOutputNames.add(outputName);

        if (options.check) {
            const existing = await readIfPresent(outputPath);
            if (!existing) throw new Error(`Missing generated map: ${outputPath}`);
            validateCompiledMap(existing, parsed);
            if (!existing.equals(compiled)) throw new Error(`Generated map differs from source: ${outputPath}`);
        } else if (await writeIfChanged(outputPath, compiled)) {
            changedFiles += 1;
        }

        manifest.zones[file.zone].legacy = {
            path: `maps/zones/${outputName}`,
            sha256: digest,
            bytes: compiled.length,
            segments: parsed.segmentCount,
            points: parsed.points.length,
            bounds: manifestBounds(parsed.bounds),
            annotations,
        };

        compiledByteTotal += compiled.length;
        segmentTotal += parsed.segmentCount;
        pointTotal += parsed.points.length;
        annotationTotal += annotations.length;
    }

    const manifestContent = Buffer.from(`${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
    if (manifestContent.length > MAX_MANIFEST_BYTES) {
        throw new Error(`Generated manifest exceeds ${MAX_MANIFEST_BYTES} bytes`);
    }

    if (options.check) {
        const existingManifest = await readIfPresent(manifestPath);
        if (!existingManifest) throw new Error(`Missing generated manifest: ${manifestPath}`);
        try {
            JSON.parse(existingManifest.toString('utf8'));
        } catch {
            throw new Error(`Generated manifest is not valid JSON: ${manifestPath}`);
        }
        if (!existingManifest.equals(manifestContent)) {
            throw new Error(`Generated manifest differs from source: ${manifestPath}`);
        }
    } else if (await writeIfChanged(manifestPath, manifestContent)) {
        changedFiles += 1;
    }

    let outputEntries;
    try {
        outputEntries = await readdir(zonesDirectory, { withFileTypes: true });
    } catch (error) {
        throw new Error(`Unable to read generated map directory ${zonesDirectory}: ${error.message}`);
    }

    const staleMaps = outputEntries
        .filter((entry) => entry.isFile() && entry.name.endsWith('.eqmap') && !expectedOutputNames.has(entry.name))
        .map((entry) => entry.name)
        .sort(naturalCompare);

    if (options.check && staleMaps.length > 0) {
        throw new Error(`Unexpected generated maps: ${staleMaps.join(', ')}`);
    }
    if (!options.check) {
        for (const staleMap of staleMaps) {
            await unlink(resolve(zonesDirectory, staleMap));
            changedFiles += 1;
        }
    }

    const ratio = compiledByteTotal / sourceByteTotal;
    return {
        zones: files.length,
        legacyZones: legacyFiles.length,
        segments: segmentTotal,
        points: pointTotal,
        annotations: annotationTotal,
        sourceBytes: sourceByteTotal,
        compiledBytes: compiledByteTotal,
        manifestBytes: manifestContent.length,
        ratio,
        changedFiles,
    };
}

async function main() {
    const options = parseArguments(process.argv.slice(2));
    if (options.help) {
        process.stdout.write(`${usage()}\n`);
        return;
    }

    const result = await build(options);
    const action = options.check ? 'Checked' : 'Built';
    process.stdout.write(
        `${action} ${result.zones} zones + ${result.legacyZones} legacy variants: `
        + `${result.segments} segments, ${result.points} points, ${result.annotations} map annotations, `
        + `${result.compiledBytes} compiled bytes from ${result.sourceBytes} source bytes `
        + `(${(result.ratio * 100).toFixed(2)}%), manifest ${result.manifestBytes} bytes`
        + (options.check ? '' : `, ${result.changedFiles} files changed`)
        + '\n',
    );
}

main().catch((error) => {
    process.stderr.write(`Map build failed: ${error.message}\n`);
    process.exitCode = 1;
});
