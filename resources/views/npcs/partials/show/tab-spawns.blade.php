@php
    $mapGroups = array_map(static function (array $group): array {
        return [
            'key' => $group['key'],
            'long_name' => $group['long_name'],
            'version' => $group['version'],
            'map' => $group['map'] ?? null,
            'paths' => $group['paths'] ?? [],
            'path_meta' => $group['path_meta'] ?? [],
            'locations' => array_map(static fn (array $location): array => [
                'id' => $location['id'],
                'position' => $location['position'] ?? null,
                'path_grid' => $location['path_grid'] ?? 0,
                'roam' => $location['roam'] ?? null,
                'spawn_group_id' => $location['spawn_group_id'] ?? 0,
            ], $group['locations'] ?? []),
        ];
    }, $locationGroups);
    $mapConfig = [
        'npcName' => $npc->clean_name,
        'coordinateOrder' => config('everquest.coords_as_yxz') ? 'yxz' : 'xyz',
        'pathPreviewEnabled' => config('everquest.maps.path_preview', true),
        'groups' => $mapGroups,
    ];
    $formatCoordinate = static function ($value): string {
        return number_format((float) $value, 2, '.', '');
    };
    $formatCompactNumber = static function ($value): string {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    };
    $hasFinitePosition = static function ($position): bool {
        if (!is_array($position)) {
            return false;
        }

        foreach (['x', 'y', 'z'] as $axis) {
            if (!array_key_exists($axis, $position)) {
                return false;
            }

            $value = $position[$axis];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                return false;
            }
        }

        return true;
    };
@endphp

<input type="radio" name="npc_details" class="tab" aria-label="Locations"
    {{ $defaultTab === 'spawns' ? 'checked' : '' }} />
<div class="tab-content bg-base-100 border-base-300"
    x-data="npcLocationMap(@js($mapConfig))">
    <div class="p-3 sm:p-5 space-y-5">
        <section class="eq-location-map" x-ref="shell" aria-labelledby="location-map-heading">
            <div class="flex flex-col gap-3 border-b border-base-content/10 bg-base-200/70 p-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/15 text-primary">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 6l6 -3l6 3v12l-6 3l-6 -3l-6 3v-12z" />
                                <path d="M9 6v12" />
                                <path d="M15 3v12" />
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <h2 id="location-map-heading" class="font-semibold leading-tight">Spawn atlas</h2>
                            <p class="truncate text-xs text-base-content/55" x-text="zoneLabel"></p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if (count($locationGroups) > 1)
                        <label class="form-control">
                            <span class="sr-only">Zone and version</span>
                            <select class="select select-sm select-bordered max-w-64" x-model="selectedZoneKey"
                                @change="selectZone($event.target.value)">
                                @foreach ($locationGroups as $locationGroup)
                                    <option value="{{ $locationGroup['key'] }}">
                                        {{ $locationGroup['long_name'] }}
                                        @if ((int) $locationGroup['version'] !== 0)
                                            (v{{ $locationGroup['version'] }})
                                        @endif
                                        · {{ count($locationGroup['locations']) }}
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

            <div class="eq-location-map__viewport" x-ref="viewport"
                :class="{ 'eq-location-map__viewport--interactive': mapData }">
                <canvas x-ref="canvas" :tabindex="mapData ? 0 : -1" role="img"
                    :aria-label="`Interactive map of ${npcName} locations in ${zoneLabel}. Drag to pan, use the mouse wheel or plus and minus keys to zoom, and press zero to reset.`"
                    @wheel.prevent="onWheel($event)"
                    @pointerdown="onPointerDown($event)"
                    @pointermove="onPointerMove($event)"
                    @pointerup="onPointerUp($event)"
                    @pointercancel="onPointerUp($event)"
                    @pointerleave="hoveredLocationId = null; scheduleDraw()"
                    @keydown="onKeydown($event)"></canvas>

                <div class="eq-location-map__grid" aria-hidden="true"></div>

                <div class="absolute inset-0 z-20 flex items-center justify-center bg-base-300/80 backdrop-blur-sm"
                    x-show="loading" x-transition.opacity x-cloak>
                    <div class="flex items-center gap-3 rounded-xl border border-base-content/10 bg-base-200 px-4 py-3 shadow-xl">
                        <span class="loading loading-spinner loading-sm text-primary"></span>
                        <span class="text-sm">Charting <span x-text="zoneLabel"></span>…</span>
                    </div>
                </div>

                <div class="absolute inset-0 z-10 flex items-center justify-center p-6 text-center"
                    x-show="!loading && (!mapAvailable || loadError)" x-cloak>
                    <div class="max-w-md rounded-xl border border-base-content/10 bg-base-200/95 p-5 shadow-xl">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="1.7" class="mx-auto mb-2 text-base-content/45"
                            aria-hidden="true">
                            <path d="M9 6l6 -3l6 3v12l-6 3l-6 -3l-6 3v-12z" /><path d="M9 6v12" /><path d="M15 3v12" />
                        </svg>
                        <p class="font-medium" x-text="loadError || `No Brewall base map is available for ${zoneLabel}.`"></p>
                        <p class="mt-1 text-sm text-base-content/55">Every verified spawn is still listed below.</p>
                    </div>
                </div>

                <div class="pointer-events-none absolute bottom-3 left-3 z-10 flex flex-wrap gap-2 text-xs"
                    x-show="mapData" x-cloak>
                    <span class="rounded-full border border-rose-300/25 bg-base-300/85 px-2.5 py-1 backdrop-blur">
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-rose-400"></span>
                        <span x-text="`${mappableLocations.length} location${mappableLocations.length === 1 ? '' : 's'}`"></span>
                    </span>
                    <span class="rounded-full border border-base-content/10 bg-base-300/85 px-2.5 py-1 backdrop-blur"
                        x-text="`${Math.round(zoom * 100)}%`"></span>
                    <span class="rounded-full border border-sky-300/25 bg-base-300/85 px-2.5 py-1 backdrop-blur"
                        x-show="hasPaths" x-cloak>
                        <span class="mr-1 inline-block h-2 w-2 rounded-full border border-sky-100 bg-sky-400"></span>
                        pathing
                    </span>
                </div>
            </div>

            <div class="grid gap-3 border-t border-base-content/10 bg-base-200/60 p-3 lg:grid-cols-[1fr_auto] lg:items-center">
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm">
                    <label class="label cursor-pointer gap-2 py-0" x-show="mapData?.points?.length" x-cloak>
                        <input type="checkbox" class="toggle toggle-xs toggle-primary" x-model="showMapPoints" />
                        <span class="label-text">Map labels</span>
                    </label>
                    <label class="label cursor-pointer gap-2 py-0" x-show="hasZoneAnnotations" x-cloak>
                        <input type="checkbox" class="toggle toggle-xs toggle-warning" x-model="showZoneLines" />
                        <span class="label-text">Zone lines</span>
                    </label>
                    <label class="label cursor-pointer gap-2 py-0" x-show="hasDrawableMovement || hasRoamAreas" x-cloak>
                        <input type="checkbox" class="toggle toggle-xs toggle-info" x-model="showPaths" />
                        <span class="label-text">Selected movement</span>
                    </label>
                    <div class="flex items-center gap-1" x-show="pathPreviewAvailable" x-cloak>
                        <button type="button" class="btn btn-xs btn-outline btn-info" @click="togglePathPreview()"
                            :disabled="reducedMotion"
                            title="Preview the selected Patrol or one-way waypoint order. Timing is illustrative, not a live server position."
                            x-text="pathPreviewPlaying ? 'Pause path' : (pathPreviewActive ? 'Resume path' : 'Preview path')"></button>
                        <select class="select select-bordered select-xs w-16" x-model.number="pathPreviewSpeed"
                            aria-label="Path preview speed">
                            <option value="1">1×</option>
                            <option value="4">4×</option>
                            <option value="10">10×</option>
                        </select>
                        <button type="button" class="btn btn-xs btn-ghost" x-show="pathPreviewActive"
                            @click="resetPathPreview()">Reset</button>
                        <span class="hidden text-xs text-base-content/50 sm:inline" x-text="pathPreviewBehaviorLabel"></span>
                    </div>
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
                    <div class="flex min-w-0 items-center justify-between gap-2 rounded-lg border border-primary/15 bg-primary/5 px-3 py-2 lg:justify-end">
                        <div class="min-w-0">
                            <p class="text-[0.65rem] font-semibold uppercase tracking-wider text-base-content/45">Selected</p>
                            <p class="truncate font-mono text-sm" x-text="selectedCoordinateText"></p>
                            <p class="truncate text-xs text-info" x-show="pathPreviewActive" x-text="pathPreviewStatus"></p>
                        </div>
                        <button type="button" class="btn btn-xs btn-ghost shrink-0" @click="copyCoordinates()"
                            :disabled="!hasUsablePosition(selectedLocation)">Copy</button>
                    </div>
                </template>
            </div>

            <p class="sr-only" role="status" aria-live="polite" x-text="statusMessage"></p>
            <div class="toast toast-end z-50" role="status" aria-live="polite"
                x-show="copyMessage" x-transition x-cloak>
                <div class="alert alert-success py-2 text-sm"><span x-text="copyMessage"></span></div>
            </div>
        </section>

        <div class="flex flex-col gap-2 text-xs text-base-content/50 sm:flex-row sm:items-center sm:justify-between">
            <p>Drag to pan · wheel to zoom · select a row or pin for details</p>
            <p>
                Base maps by
                <a href="https://www.eqmaps.info/" target="_blank" rel="noopener noreferrer"
                    class="link link-hover text-info">Brewall</a>
                · base geometry with curated zone-line annotations
            </p>
        </div>

        <section aria-labelledby="verified-locations-heading">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="verified-locations-heading" class="font-semibold">Verified locations</h2>
                    <p class="text-sm text-base-content/55">Exact database positions, grouped by zone version.</p>
                </div>
            </div>

            @foreach ($locationGroups as $locationGroup)
                <div x-show="selectedZoneKey === @js($locationGroup['key'])">
                    <div class="mb-2 flex flex-wrap items-center gap-2 rounded-lg bg-base-200/70 px-3 py-2 text-sm">
                        @if (!empty($locationGroup['zone_row_id']))
                            <a href="{{ route('zones.show', $locationGroup['zone_row_id']) }}{{ (int) $locationGroup['version'] !== 0 ? '?v=' . (int) $locationGroup['version'] : '' }}"
                                class="link link-hover font-medium text-info">
                                {{ $locationGroup['long_name'] }}
                            </a>
                        @else
                            <span class="font-medium">{{ $locationGroup['long_name'] }}</span>
                        @endif
                        <span class="badge badge-sm badge-ghost">{{ $locationGroup['short_name'] }}</span>
                        @if ((int) $locationGroup['version'] !== 0)
                            <span class="badge badge-sm badge-outline">Version {{ $locationGroup['version'] }}</span>
                        @endif
                        <span class="ml-auto text-base-content/50">
                            {{ count($locationGroup['locations']) }} spawn
                            {{ count($locationGroup['locations']) === 1 ? 'point' : 'points' }}
                        </span>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-base-content/10">
                        <table class="table table-zebra w-full">
                            <thead class="bg-base-300 text-xs uppercase">
                                <tr>
                                    <th scope="col">
                                        {{ config('everquest.coords_as_yxz') ? 'Coordinates (y, x, z)' : 'Coordinates (x, y, z)' }}
                                    </th>
                                    <th scope="col">Spawn group</th>
                                    <th scope="col">Placeholders</th>
                                    <th scope="col">Chance</th>
                                    @if (config('everquest.npc.display.respawn'))
                                        <th scope="col">Respawn</th>
                                    @endif
                                    <th scope="col"><span class="sr-only">Map action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $listedPlaceholderGroups = [];
                                @endphp
                                @foreach ($locationGroup['locations'] as $location)
                                    @php
                                        $position = $location['position'] ?? null;
                                        $hasCoordinates = $hasFinitePosition($position);
                                        $spawnGroupId = (int) ($location['spawn_group_id'] ?? 0);
                                        $pathGrid = (int) ($location['path_grid'] ?? 0);
                                        $pathWaypoints = $locationGroup['paths'][(string) $pathGrid] ?? [];
                                        $pathMetadata = $locationGroup['path_meta'][(string) $pathGrid] ?? [];
                                        $placeholders = $locationGroup['placeholders'][(string) $spawnGroupId] ?? [];
                                        $showPlaceholderDetails = !isset($listedPlaceholderGroups[$spawnGroupId]);
                                        $listedPlaceholderGroups[$spawnGroupId] = true;
                                        $coordinateText = 'Coordinates unavailable';
                                        if ($hasCoordinates) {
                                            $coordinates = config('everquest.coords_as_yxz')
                                                ? [$position['y'], $position['x'], $position['z']]
                                                : [$position['x'], $position['y'], $position['z']];
                                            $coordinateText = implode(', ', array_map($formatCoordinate, $coordinates));
                                        }
                                    @endphp
                                    <tr :class="String(selectedLocationId) === @js((string) $location['id']) ? 'bg-primary/5' : ''">
                                        <td>
                                            @if ($hasCoordinates)
                                                <button type="button" class="group inline-flex items-center gap-2 text-left font-mono text-sm"
                                                    @click="selectLocation(@js($location['id']))"
                                                    title="Center this location on the map">
                                                    <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full border-2 border-base-100 bg-rose-400 ring-1 ring-rose-400/40"></span>
                                                    <span class="group-hover:text-primary">{{ $coordinateText }}</span>
                                                </button>
                                            @else
                                                <span class="inline-flex items-center gap-2 text-sm text-base-content/45">
                                                    <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full border border-base-content/25"></span>
                                                    {{ $coordinateText }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="flex flex-col">
                                                <span>{{ $location['spawn_group_name'] ?: 'Group ' . $spawnGroupId }}</span>
                                                <span class="text-xs text-base-content/45">#{{ $spawnGroupId }}</span>
                                                @if ($pathGrid > 0)
                                                    <span class="mt-1 badge badge-xs badge-info badge-outline">
                                                        Path {{ $pathGrid }}
                                                        @if (!empty($pathMetadata['wander_type_label']))
                                                            · {{ $pathMetadata['wander_type_label'] }}
                                                        @endif
                                                        @if (!empty($pathWaypoints))
                                                            · {{ count($pathWaypoints) }}
                                                            {{ count($pathWaypoints) === 1 ? 'waypoint' : 'waypoints' }}
                                                        @endif
                                                    </span>
                                                @elseif (!empty($location['roam']))
                                                    <span class="mt-1 badge badge-xs badge-info badge-outline">Roaming area</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            @if ($showPlaceholderDetails)
                                                @forelse ($placeholders as $placeholder)
                                                    <a href="{{ $placeholder['url'] ?? route('npcs.show', $placeholder['id']) }}"
                                                        class="link link-hover text-info">
                                                        {{ $placeholder['name'] }}
                                                    </a>
                                                    @if (isset($placeholder['chance']))
                                                        <span class="text-xs text-base-content/50">({{ $formatCompactNumber($placeholder['chance']) }}%)</span>
                                                    @endif
                                                    @unless ($loop->last)<span class="text-base-content/30"> · </span>@endunless
                                                @empty
                                                    <span class="text-base-content/45">None</span>
                                                @endforelse
                                            @elseif (!empty($placeholders))
                                                <span class="text-xs text-base-content/45"
                                                    title="Candidates are listed on the first location for spawn group {{ $spawnGroupId }}">
                                                    Shared group · {{ count($placeholders) }}
                                                    {{ count($placeholders) === 1 ? 'candidate' : 'candidates' }}
                                                </span>
                                            @else
                                                <span class="text-base-content/45">None</span>
                                            @endif
                                        </td>
                                        <td>{{ $formatCompactNumber($location['chance']) }}%</td>
                                        @if (config('everquest.npc.display.respawn'))
                                            <td>
                                                @if (array_key_exists('respawn_seconds', $location))
                                                    {{ seconds_to_human((int) $location['respawn_seconds']) }}
                                                    @if (($location['variance_seconds'] ?? 0) > 0)
                                                        <span class="block text-xs text-accent">
                                                            ± {{ seconds_to_human((int) $location['variance_seconds']) }}
                                                        </span>
                                                    @endif
                                                @else
                                                    <span class="text-base-content/45">Unknown</span>
                                                @endif
                                            </td>
                                        @endif
                                        <td class="text-right">
                                            @if ($hasCoordinates)
                                                <button type="button" class="btn btn-xs btn-ghost"
                                                    @click="copyCoordinates(currentLocations.find((item) => String(item.id) === @js((string) $location['id'])))"
                                                    aria-label="Copy coordinates {{ $coordinateText }}">Copy</button>
                                            @else
                                                <span class="text-xs text-base-content/35">Unavailable</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </section>
    </div>
</div>
