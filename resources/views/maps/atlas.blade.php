@php
    $atlasId = $atlasId ?? 'eq-atlas';
    $atlasHeading = $atlasHeading ?? 'Location atlas';
    $atlasDescription = $atlasDescription ?? 'Explore verified locations on the zone map.';
    $atlasEmptyHelp = $atlasEmptyHelp ?? 'The lists on this page remain available.';
    $mapGroups = $mapConfig['groups'] ?? [];
@endphp

<div x-data="zoneAtlasMap(@js($mapConfig))" class="space-y-3">
    <div class="alert alert-warning" role="status"
        x-show="typeof ensureDatasetLoaded !== 'function'">
        <span>
            The interactive atlas is unavailable. The location lists on this page still work.
            Site administrators should deploy the current frontend assets, then refresh this page.
        </span>
    </div>

    <section class="eq-location-map" x-ref="shell" aria-labelledby="{{ $atlasId }}-heading">
        <header class="space-y-3 border-b border-base-content/10 bg-base-200/70 p-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 6l6 -3l6 3v12l-6 3l-6 -3l-6 3v-12z" />
                            <path d="M9 6v12" /><path d="M15 3v12" />
                        </svg>
                    </span>
                    <div class="min-w-0">
                        <h2 id="{{ $atlasId }}-heading" class="font-semibold leading-tight">{{ $atlasHeading }}</h2>
                        <p class="truncate text-xs text-base-content/55" x-text="zoneLabel || @js($atlasDescription)"></p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if (count($mapGroups) > 1)
                        <label class="form-control">
                            <span class="sr-only">Zone and version</span>
                            <select class="select select-sm select-bordered max-w-72" x-model="selectedZoneKey"
                                @change="selectZone($event.target.value)">
                                @foreach ($mapGroups as $group)
                                    <option value="{{ $group['key'] }}">
                                        {{ $group['long_name'] }}
                                        @if ((int) $group['version'] !== 0) (v{{ $group['version'] }}) @endif
                                        · {{ count($group['locations'] ?? []) }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endif

                    <div class="join" role="group" aria-label="Map zoom controls">
                        <button type="button" class="btn btn-sm btn-ghost join-item" @click="zoomBy(0.8)"
                            :disabled="!mapData" title="Zoom out" aria-label="Zoom out">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8" /><path d="M8 11h6" /><path d="m21 21l-4.3-4.3" />
                            </svg>
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost join-item" @click="zoomBy(1.25)"
                            :disabled="!mapData" title="Zoom in" aria-label="Zoom in">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <circle cx="11" cy="11" r="8" /><path d="M8 11h6" /><path d="M11 8v6" /><path d="m21 21l-4.3-4.3" />
                            </svg>
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost join-item" @click="resetView()"
                            :disabled="!mapData" title="Reset map" aria-label="Reset map">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M20 11a8.1 8.1 0 1 0 .5 4" /><path d="M20 4v7h-7" />
                            </svg>
                        </button>
                    </div>

                    <button type="button" class="btn btn-sm btn-ghost" @click="toggleFullscreen()"
                        :disabled="!mapData" title="Toggle fullscreen" aria-label="Toggle fullscreen map">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M8 3H5a2 2 0 0 0 -2 2v3" /><path d="M16 3h3a2 2 0 0 1 2 2v3" />
                            <path d="M8 21H5a2 2 0 0 1 -2 -2v-3" /><path d="M16 21h3a2 2 0 0 0 2 -2v-3" />
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-2" x-show="layers.length" x-cloak>
                <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                    <label class="input input-sm input-bordered flex w-full items-center gap-2 lg:max-w-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="11" cy="11" r="8" /><path d="m21 21l-4.3-4.3" />
                        </svg>
                        <input type="search" class="grow" maxlength="120" placeholder="Filter loaded map entries"
                            x-model.debounce.200ms="searchQuery" aria-label="Filter map entries" />
                    </label>
                    <div class="flex items-center gap-1 text-xs">
                        <button type="button" class="btn btn-xs btn-ghost" @click="setAllLayers(true)">Show all</button>
                        <button type="button" class="btn btn-xs btn-ghost" @click="setAllLayers(false)">Hide all</button>
                        <button type="button" class="btn btn-xs btn-ghost" @click="resetFilters()">Reset</button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2" role="group" aria-label="Map layers">
                    <template x-for="layer in layers" :key="layer.id">
                        <label class="eq-atlas-filter cursor-pointer select-none"
                            :class="activeLayers[layer.id] ? 'eq-atlas-filter--active' : ''">
                            <input type="checkbox" class="sr-only" x-model="activeLayers[layer.id]"
                                @change="onFiltersChanged()" />
                            <span class="eq-atlas-marker shrink-0"
                                :class="`eq-atlas-marker--${layer.shape}`"
                                :style="`background:${layer.color}`"></span>
                            <span x-text="layer.label"></span>
                            <span class="text-base-content/45" x-text="layerCount(layer.id)"></span>
                            <span x-show="layer.truncated" title="This busy layer was capped for performance">+</span>
                        </label>
                    </template>
                </div>
            </div>
        </header>

        <div class="eq-location-map__viewport" x-ref="viewport"
            :class="{ 'eq-location-map__viewport--interactive': mapData }">
            <canvas x-ref="canvas" :tabindex="mapData ? 0 : -1" role="img"
                :aria-label="`Interactive map of ${subjectName} in ${zoneLabel}. Drag to pan, use the mouse wheel or plus and minus keys to zoom, and press zero to reset.`"
                @wheel.prevent="onWheel($event)"
                @pointerdown="onPointerDown($event)"
                @pointermove="onPointerMove($event)"
                @pointerup="onPointerUp($event)"
                @pointercancel="onPointerUp($event)"
                @pointerleave="clearHover()"
                @keydown="onKeydown($event)"></canvas>

            <div class="eq-location-map__grid" aria-hidden="true"></div>

            <div x-show="hoveredLocation && !dragging" x-cloak aria-hidden="true">
                <div class="eq-atlas-tooltip" x-ref="tooltip" :style="tooltipStyle">
                    <template x-if="hoveredLocation">
                        <div>
                            <div class="mb-2 flex items-start gap-2">
                                <span class="eq-atlas-marker mt-1.5 shrink-0"
                                    :class="`eq-atlas-marker--${locationShape(hoveredLocation)}`"
                                    :style="`background:${locationColor(hoveredLocation)}`"></span>
                                <div class="min-w-0">
                                    <p class="font-semibold leading-tight text-base-content" x-text="hoveredLocation.label || 'Map entry'"></p>
                                    <p class="mt-0.5 text-xs text-base-content/55" x-text="hoveredLocation.subtitle"></p>
                                </div>
                            </div>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                                <template x-for="detail in hoveredLocation.details" :key="`${detail.label}:${detail.value}`">
                                    <div class="contents">
                                        <dt class="text-base-content/45" x-text="detail.label"></dt>
                                        <dd class="truncate text-right text-base-content/85" x-text="detail.value"></dd>
                                    </div>
                                </template>
                                <dt class="text-base-content/45" x-text="hoveredLocation.area ? 'Spawn area' : 'Coordinates'"></dt>
                                <dd class="text-right font-mono text-base-content/85" x-text="coordinateLabel(hoveredLocation)"></dd>
                            </dl>
                            <template x-if="hoveredLocation.candidates?.length > 1">
                                <div class="mt-2 border-t border-base-content/10 pt-2">
                                    <p class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wider text-base-content/40">Possible spawns</p>
                                    <template x-for="candidate in hoveredLocation.candidates.slice(0, 4)" :key="candidate.id">
                                        <p class="truncate text-xs">
                                            <span x-text="candidate.name"></span>
                                            <span class="text-base-content/45" x-text="candidate.chance === null ? '' : ` · ${candidate.chance}%`"></span>
                                        </p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="absolute inset-0 z-20 flex items-center justify-center bg-base-300/80 backdrop-blur-sm"
                x-show="busy" x-transition.opacity x-cloak>
                <div class="flex items-center gap-3 rounded-xl border border-base-content/10 bg-base-200 px-4 py-3 shadow-xl">
                    <span class="loading loading-spinner loading-sm text-primary"></span>
                    <span class="text-sm">Charting <span x-text="zoneLabel || subjectName"></span>…</span>
                </div>
            </div>

            <div class="absolute inset-0 z-10 flex items-center justify-center p-6 text-center"
                x-show="!busy && (datasetError || !mapAvailable || loadError)" x-cloak>
                <div class="max-w-md rounded-xl border border-base-content/10 bg-base-200/95 p-5 shadow-xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="1.7" class="mx-auto mb-2 text-base-content/45"
                        aria-hidden="true">
                        <path d="M9 6l6 -3l6 3v12l-6 3l-6 -3l-6 3v-12z" /><path d="M9 6v12" /><path d="M15 3v12" />
                    </svg>
                    <p class="font-medium" x-text="datasetError || loadError || `No Brewall base map is available for ${zoneLabel}.`"></p>
                    <p class="mt-1 text-sm text-base-content/55">{{ $atlasEmptyHelp }}</p>
                </div>
            </div>

            <div class="pointer-events-none absolute bottom-3 left-3 z-10 flex flex-wrap gap-2 text-xs"
                x-show="mapData" x-cloak>
                <span class="rounded-full border border-primary/25 bg-base-300/85 px-2.5 py-1 backdrop-blur">
                    <span x-text="`${mappableLocations.length.toLocaleString()} shown`"></span>
                </span>
                <span class="rounded-full border border-base-content/10 bg-base-300/85 px-2.5 py-1 backdrop-blur"
                    x-text="`${Math.round(zoom * 100)}%`"></span>
            </div>
        </div>

        <footer class="grid gap-3 border-t border-base-content/10 bg-base-200/60 p-3 lg:grid-cols-[1fr_auto] lg:items-center">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                <label class="label cursor-pointer gap-2 py-0" x-show="mapData?.points?.length" x-cloak>
                    <input type="checkbox" class="toggle toggle-xs toggle-primary" x-model="showMapPoints" />
                    <span class="label-text">Base-map labels</span>
                </label>
                <label class="label cursor-pointer gap-2 py-0" x-show="mapData" x-cloak>
                    <input type="checkbox" class="toggle toggle-xs toggle-warning" x-model="elevationFocus" />
                    <span class="label-text">Focus floor</span>
                </label>
                <label class="flex items-center gap-2" x-show="mapData && elevationFocus" x-cloak>
                    <span class="text-xs text-base-content/55">±<span x-text="elevationRange"></span> Z</span>
                    <input type="range" min="5" max="150" step="5" class="range range-xs range-warning w-28"
                        x-model.number="elevationRange" aria-label="Elevation focus range" />
                </label>
            </div>

            <template x-if="selectedLocation">
                <div class="flex min-w-0 items-start justify-between gap-3 rounded-lg border border-primary/15 bg-primary/5 px-3 py-2 lg:max-w-2xl">
                    <div class="flex min-w-0 gap-2">
                        <span class="eq-atlas-marker mt-1 shrink-0"
                            :class="`eq-atlas-marker--${locationShape(selectedLocation)}`"
                            :style="`background:${locationColor(selectedLocation)}`"></span>
                        <div class="min-w-0">
                            <p class="truncate font-medium" x-text="selectedLocation.label || 'Selected location'"></p>
                            <p class="truncate font-mono text-xs text-base-content/55" x-text="selectedCoordinateText"></p>
                            <dl class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-base-content/70">
                                <template x-for="detail in selectedLocation.details.slice(0, 6)" :key="`${detail.label}:${detail.value}`">
                                    <div class="flex min-w-0 gap-1">
                                        <dt class="text-base-content/45" x-text="`${detail.label}:`"></dt>
                                        <dd class="truncate" x-text="detail.value"></dd>
                                    </div>
                                </template>
                            </dl>
                            <template x-if="selectedLocation.candidates?.length > 1">
                                <p class="mt-1 truncate text-xs text-base-content/55"
                                    x-text="`Possible: ${selectedLocation.candidates.slice(0, 4).map((candidate) => candidate.name).join(', ')}`"></p>
                            </template>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <a class="btn btn-xs btn-ghost" x-show="selectedLocation.url" :href="selectedLocation.url">Details</a>
                        <button type="button" class="btn btn-xs btn-ghost" @click="copyCoordinates()"
                            :disabled="!hasUsablePosition(selectedLocation)"
                            x-text="selectedLocation.area ? 'Copy bounds' : 'Copy'"></button>
                    </div>
                </div>
            </template>
        </footer>

        <p class="sr-only" role="status" aria-live="polite" x-text="statusMessage"></p>
        <div class="toast toast-end z-50" role="status" aria-live="polite"
            x-show="copyMessage" x-transition x-cloak>
            <div class="alert alert-success py-2 text-sm"><span x-text="copyMessage"></span></div>
        </div>
    </section>

    <div class="flex flex-col gap-2 text-xs text-base-content/50 sm:flex-row sm:items-center sm:justify-between">
        <p>Hover for details · click to select · drag to pan · wheel to zoom</p>
        <p>
            Base maps by
            <a href="https://www.eqmaps.info/" target="_blank" rel="noopener noreferrer"
                class="link link-hover text-info">Brewall</a>
            · base geometry only
        </p>
    </div>
</div>
