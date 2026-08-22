import {
    clampZoom,
    dbToBrewall,
    fitBounds,
    formatCoordinates,
    parseEqMap,
    screenToWorld,
    worldToScreen,
} from '../maps/eq-map-format.js';

const MAP_CACHE = new Map();
const MIN_ZOOM = 0.5;
const MAX_ZOOM = 24;
const MAP_PADDING = 32;

function finiteNumber(value, fallback = 0) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function isFiniteCoordinate(value) {
    return typeof value === 'number' && Number.isFinite(value);
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

export default function npcLocationMap(config = {}) {
    return {
        groups: Array.isArray(config.groups) ? config.groups : [],
        coordinateOrder: config.coordinateOrder === 'yxz' ? 'yxz' : 'xyz',
        npcName: String(config.npcName ?? 'NPC'),
        selectedZoneKey: null,
        selectedLocationId: null,
        hoveredLocationId: null,
        mapData: null,
        loading: false,
        loadError: '',
        statusMessage: '',
        copyMessage: '',
        showMapPoints: false,
        showPaths: true,
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

        init() {
            const mappedGroup = this.groups.find((group) => group?.map?.available && group?.map?.url);
            this.selectedZoneKey = mappedGroup?.key ?? this.groups[0]?.key ?? null;
            this.selectedLocationId = this.mappableLocations[0]?.id ?? this.currentLocations[0]?.id ?? null;

            this.resizeObserver = new ResizeObserver(() => this.resizeCanvas());
            if (this.$refs.viewport) {
                this.resizeObserver.observe(this.$refs.viewport);
            }

            this.visibilityObserver = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    this.ensureMapLoaded();
                }
            }, { rootMargin: '240px' });
            this.visibilityObserver.observe(this.$root);

            this.$watch('elevationFocus', () => this.scheduleDraw());
            this.$watch('elevationRange', () => this.scheduleDraw());
            this.$watch('showMapPoints', () => this.scheduleDraw());
            this.$watch('showPaths', () => this.scheduleDraw());
        },

        destroy() {
            this.loadGeneration += 1;
            this.loading = false;
            this.loadingMapUrl = null;
            this.resizeObserver?.disconnect();
            this.visibilityObserver?.disconnect();
            if (this.animationFrame) cancelAnimationFrame(this.animationFrame);
        },

        get currentGroup() {
            return this.groups.find((group) => group.key === this.selectedZoneKey) ?? this.groups[0] ?? null;
        },

        get currentLocations() {
            return Array.isArray(this.currentGroup?.locations) ? this.currentGroup.locations : [];
        },

        get mappableLocations() {
            return this.currentLocations.filter((location) => hasFinitePosition(location));
        },

        get currentPaths() {
            const paths = this.currentGroup?.paths;
            return paths !== null && typeof paths === 'object' && !Array.isArray(paths) ? paths : {};
        },

        get selectedLocation() {
            return this.currentLocations.find((location) => String(location.id) === String(this.selectedLocationId))
                ?? this.currentLocations[0]
                ?? null;
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
            return this.mappableLocations.some((location) => this.pathForLocation(location).length > 0);
        },

        get hasRoamAreas() {
            return this.currentLocations.some((location) => location.roam);
        },

        get zoneLabel() {
            if (!this.currentGroup) return '';
            return `${this.currentGroup.long_name}${Number(this.currentGroup.version) === 0 ? '' : ` · v${this.currentGroup.version}`}`;
        },

        async selectZone(key) {
            if (!this.groups.some((group) => group.key === key)) return;
            this.selectedZoneKey = key;
            this.selectedLocationId = this.mappableLocations[0]?.id ?? this.currentLocations[0]?.id ?? null;
            this.hoveredLocationId = null;
            this.mapData = null;
            this.loadedMapUrl = null;
            this.loadError = '';
            this.zoom = 1;
            this.panX = 0;
            this.panY = 0;
            await this.ensureMapLoaded(true);
        },

        async ensureMapLoaded(force = false) {
            const map = this.currentGroup?.map;
            if (!map?.available || !map?.url) {
                this.loadGeneration += 1;
                this.mapData = null;
                this.loadedMapUrl = null;
                this.loadingMapUrl = null;
                this.loading = false;
                this.loadError = '';
                this.statusMessage = `No base map is available for ${this.zoneLabel}.`;
                this.scheduleDraw();
                return;
            }

            if (!force && this.mapData && this.loadedMapUrl === map.url) {
                return;
            }

            if (!force && this.loading && this.loadingMapUrl === map.url) return;

            const generation = ++this.loadGeneration;
            this.loadingMapUrl = map.url;
            this.loading = true;
            this.loadError = '';
            this.statusMessage = `Loading the ${this.zoneLabel} base map…`;

            try {
                if (!MAP_CACHE.has(map.url)) {
                    MAP_CACHE.set(map.url, fetch(map.url, {
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
                        MAP_CACHE.delete(map.url);
                        throw error;
                    }));
                }

                const parsed = await MAP_CACHE.get(map.url);
                if (this.loadGeneration !== generation) return;

                this.mapData = parsed;
                this.loadedMapUrl = map.url;
                this.resetView(false);
                this.statusMessage = `${this.zoneLabel} map ready: ${parsed.segmentCount.toLocaleString()} lines and ${this.mappableLocations.length.toLocaleString()} location${this.mappableLocations.length === 1 ? '' : 's'}.`;
            } catch (error) {
                if (this.loadGeneration !== generation) return;
                this.mapData = null;
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
            if (!canvas || !viewport) return;

            const rect = viewport.getBoundingClientRect();
            if (!Number.isFinite(rect.width) || !Number.isFinite(rect.height)
                || rect.width < 1 || rect.height < 1) {
                return;
            }

            const width = Math.max(1, Math.floor(rect.width));
            const height = Math.max(1, Math.floor(rect.height));
            const ratio = Math.min(window.devicePixelRatio || 1, 2);

            this.canvasWidth = width;
            this.canvasHeight = height;
            canvas.width = Math.floor(width * ratio);
            canvas.height = Math.floor(height * ratio);
            canvas.style.width = `${width}px`;
            canvas.style.height = `${height}px`;

            const context = canvas.getContext('2d');
            context?.setTransform(ratio, 0, 0, ratio, 0, 0);

            if (this.mapData && this.fit.scale === 1 && this.fit.centerX === 0 && this.fit.centerY === 0) {
                this.resetView(false);
            } else {
                this.recalculateFit(false);
                this.scheduleDraw();
            }
        },

        recalculateFit(resetPan = true) {
            if (!this.mapData) return;
            const maximumSafePadding = Math.max(0, (Math.min(this.canvasWidth, this.canvasHeight) - 1) / 2);
            const padding = Math.min(MAP_PADDING, maximumSafePadding);
            this.fit = fitBounds(this.mapData.bounds, this.canvasWidth, this.canvasHeight, padding);
            if (resetPan) {
                this.zoom = 1;
                this.panX = 0;
                this.panY = 0;
            }
        },

        resetView(announce = true) {
            this.recalculateFit(true);
            if (announce) this.statusMessage = `Map view reset for ${this.zoneLabel}.`;
            this.scheduleDraw();
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
            this.statusMessage = `Map zoom ${Math.round(this.zoom * 100)}%.`;
            this.scheduleDraw();
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
            const rect = this.$refs.canvas.getBoundingClientRect();
            const factor = Math.exp(-event.deltaY * 0.0015);
            this.zoomBy(factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
        },

        onPointerDown(event) {
            if (!this.mapData || event.button !== 0) return;
            event.currentTarget.setPointerCapture?.(event.pointerId);
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

            if (this.dragging && this.pointerStart) {
                const dx = event.clientX - this.pointerStart.x;
                const dy = event.clientY - this.pointerStart.y;
                if (Math.abs(dx) + Math.abs(dy) > 3) this.pointerMoved = true;
                this.panX = this.pointerStart.panX + dx;
                this.panY = this.pointerStart.panY + dy;
                this.scheduleDraw();
                return;
            }

            const rect = canvas.getBoundingClientRect();
            const hit = this.hitTest(event.clientX - rect.left, event.clientY - rect.top);
            const nextHoveredId = hit?.id ?? null;
            if (String(nextHoveredId) === String(this.hoveredLocationId)) return;
            this.hoveredLocationId = nextHoveredId;
            canvas.style.cursor = hit ? 'pointer' : 'grab';
            this.scheduleDraw();
        },

        onPointerUp(event) {
            if (!this.dragging) return;
            event.currentTarget.releasePointerCapture?.(event.pointerId);
            this.dragging = false;
            if (!this.pointerMoved) {
                const rect = this.$refs.canvas.getBoundingClientRect();
                const hit = this.hitTest(event.clientX - rect.left, event.clientY - rect.top);
                if (hit) this.selectLocation(hit.id, false);
            }
            this.pointerStart = null;
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
            this.scheduleDraw();
        },

        selectLocation(id, center = true) {
            const location = this.currentLocations.find((item) => String(item.id) === String(id));
            if (!location) return;
            this.selectedLocationId = location.id;
            this.statusMessage = `Selected ${this.coordinateLabel(location)} in ${this.zoneLabel}.`;

            const point = locationMapPoint(location);
            if (center && this.mapData && point) {
                this.zoom = Math.max(this.zoom, 2.25);
                const screen = this.toScreen(point.x, point.y);
                this.panX += this.canvasWidth / 2 - screen.x;
                this.panY += this.canvasHeight / 2 - screen.y;
            }

            this.scheduleDraw();
        },

        hasUsablePosition(location) {
            return hasFinitePosition(location);
        },

        pathForLocation(location) {
            const path = this.currentPaths[String(location?.path_grid ?? '')];
            return Array.isArray(path) ? path : [];
        },

        coordinateLabel(location) {
            if (!hasFinitePosition(location)) return 'coordinates unavailable';
            return formatCoordinates(location.position, this.coordinateOrder, 2);
        },

        async copyCoordinates(location = this.selectedLocation) {
            if (!location) return;
            if (!hasFinitePosition(location)) {
                this.copyMessage = 'Coordinates are unavailable for this spawn.';
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

            for (const location of this.mappableLocations) {
                const point = locationMapPoint(location);
                const screen = this.toScreen(point.x, point.y);
                const distance = ((screen.x - x) ** 2) + ((screen.y - y) ** 2);
                if (distance <= closestDistance) {
                    closest = location;
                    closestDistance = distance;
                }
            }

            return closest;
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

            this.drawGeometry(context);
            if (this.showMapPoints) this.drawMapPoints(context);
            if (this.showPaths) this.drawMovement(context);
            this.drawLocations(context);
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

        drawMapPoints(context) {
            context.save();
            context.font = '500 11px Instrument Sans, ui-sans-serif, system-ui, sans-serif';
            context.textBaseline = 'bottom';
            context.lineWidth = 3;

            for (const point of this.mapData.points) {
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
            context.save();
            context.setLineDash([7, 5]);
            context.lineWidth = 2;
            const drawnPathKeys = new Set();
            const drawnRoamKeys = new Set();

            for (const location of this.currentLocations) {
                const path = this.pathForLocation(location);
                const pathKey = String(location.path_grid ?? 'none');
                const start = locationMapPoint(location);
                if (start && path.length > 0 && !drawnPathKeys.has(pathKey)) {
                    const waypoints = path
                        .map((waypoint) => dbPositionMapPoint(waypoint))
                        .filter((waypoint) => waypoint !== null);

                    if (waypoints.length > 0) {
                        drawnPathKeys.add(pathKey);
                        const startScreen = this.toScreen(start.x, start.y);
                        context.beginPath();
                        context.moveTo(startScreen.x, startScreen.y);
                        for (const waypoint of waypoints) {
                            const screen = this.toScreen(waypoint.x, waypoint.y);
                            context.lineTo(screen.x, screen.y);
                        }
                        context.strokeStyle = 'rgba(56, 189, 248, .82)';
                        context.stroke();
                    }
                }

                const roam = location.roam;
                if (roam && [roam.min_x, roam.max_x, roam.min_y, roam.max_y].every(isFiniteCoordinate)) {
                    const roamKey = [
                        location.spawn_group_id ?? `location-${location.id ?? 'unknown'}`,
                        roam.min_x,
                        roam.max_x,
                        roam.min_y,
                        roam.max_y,
                    ].join(':');
                    if (drawnRoamKeys.has(roamKey)) continue;
                    drawnRoamKeys.add(roamKey);

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
            }
            context.restore();
        },

        drawLocations(context) {
            for (const location of this.mappableLocations) {
                const point = locationMapPoint(location);
                const screen = this.toScreen(point.x, point.y);
                const selected = String(location.id) === String(this.selectedLocationId);
                const hovered = String(location.id) === String(this.hoveredLocationId);
                const radius = selected ? 9 : hovered ? 8 : 6.5;

                context.beginPath();
                context.arc(screen.x, screen.y, radius + 4, 0, Math.PI * 2);
                context.fillStyle = selected ? 'rgba(251, 191, 36, .22)' : 'rgba(244, 63, 94, .18)';
                context.fill();

                context.beginPath();
                context.arc(screen.x, screen.y, radius, 0, Math.PI * 2);
                context.fillStyle = selected ? '#fbbf24' : '#fb7185';
                context.fill();
                context.lineWidth = 2;
                context.strokeStyle = '#fff7ed';
                context.stroke();

                context.beginPath();
                context.arc(screen.x, screen.y, 2.1, 0, Math.PI * 2);
                context.fillStyle = '#172033';
                context.fill();
            }
        },
    };
}
