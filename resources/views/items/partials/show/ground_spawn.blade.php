@php
    $groundGroups = $ground_spawn->values()->all();
    $groundCount = $ground_spawn->sum(fn ($group) => count($group['locations'] ?? []));
    $groundMapConfig = [
        'groups' => $groundGroups,
        'layers' => [[
            'id' => 'ground-spawns',
            'label' => 'Ground spawn areas',
            'color' => '#4ade80',
            'shape' => 'diamond',
            'default' => true,
            'count' => $groundCount,
        ]],
        'subjectName' => $item->Name . ' ground spawns',
        'coordinateOrder' => config('everquest.coords_as_yxz') ? 'yxz' : 'xyz',
    ];
    $formatCoordinate = static fn ($value): string => number_format((float) $value, 2, '.', '');
@endphp

<section class="mt-6 space-y-4" aria-labelledby="item-ground-spawns-heading">
    <div>
        <div class="divider" id="item-ground-spawns-heading">This item spawns on the ground</div>
        <p class="text-sm text-base-content/55">
            Shaded rectangles show the full random spawn area recorded by EQEmu; the center marker is for selection only.
        </p>
    </div>

    @include('maps.atlas', [
        'atlasId' => 'item-ground-atlas',
        'atlasHeading' => 'Ground-spawn atlas',
        'atlasDescription' => 'Verified item spawn areas by zone and version.',
        'atlasEmptyHelp' => 'Every verified ground-spawn area is still listed below.',
        'mapConfig' => $groundMapConfig,
    ])

    <div class="max-h-96 space-y-3 overflow-y-auto scrollbar-thin scrollbar-track-base-300 scrollbar-thumb-accent"
        aria-label="Ground spawn area list">
        @foreach ($ground_spawn as $group)
            <section class="rounded-lg border border-base-content/10 bg-base-200/40">
                <header class="sticky top-0 z-10 flex flex-wrap items-center gap-2 bg-neutral/95 p-2 text-sm">
                    <a href="{{ route('zones.show', $group['zone_row_id']) }}{{ (int) $group['version'] !== 0 ? '?v=' . (int) $group['version'] : '' }}"
                        class="link link-hover font-bold text-sky-400">
                        {{ $group['long_name'] }}
                    </a>
                    <span class="badge badge-xs badge-ghost">{{ $group['short_name'] }}</span>
                    @if ((int) $group['version'] !== 0)
                        <span class="badge badge-xs badge-outline">v{{ $group['version'] }}</span>
                    @endif
                    <span class="ml-auto text-xs text-base-content/50">
                        {{ count($group['locations'] ?? []) }} {{ count($group['locations'] ?? []) === 1 ? 'area' : 'areas' }}
                    </span>
                </header>

                <ul role="list" class="divide-y divide-base-200">
                    @foreach ($group['locations'] ?? [] as $location)
                        @php
                            $coords = $location['coordinates'];
                            $pointArea = $coords['min_x'] === $coords['max_x'] && $coords['min_y'] === $coords['max_y'];
                        @endphp
                        <li class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-mono text-sm text-neutral-content">
                                    @if ($pointArea)
                                        @if (config('everquest.coords_as_yxz'))
                                            y={{ $formatCoordinate($coords['max_y']) }}, x={{ $formatCoordinate($coords['max_x']) }}, z={{ $formatCoordinate($coords['z']) }}
                                        @else
                                            x={{ $formatCoordinate($coords['max_x']) }}, y={{ $formatCoordinate($coords['max_y']) }}, z={{ $formatCoordinate($coords['z']) }}
                                        @endif
                                    @elseif (config('everquest.coords_as_yxz'))
                                        y={{ $formatCoordinate($coords['min_y']) }}–{{ $formatCoordinate($coords['max_y']) }},
                                        x={{ $formatCoordinate($coords['min_x']) }}–{{ $formatCoordinate($coords['max_x']) }},
                                        z={{ $formatCoordinate($coords['z']) }}
                                    @else
                                        x={{ $formatCoordinate($coords['min_x']) }}–{{ $formatCoordinate($coords['max_x']) }},
                                        y={{ $formatCoordinate($coords['min_y']) }}–{{ $formatCoordinate($coords['max_y']) }},
                                        z={{ $formatCoordinate($coords['z']) }}
                                    @endif
                                </p>
                                @if ($location['applies_to_all_versions'] ?? false)
                                    <p class="text-xs text-base-content/45">Applies to every zone version</p>
                                @endif
                            </div>
                            <a href="{{ $location['url'] }}" class="btn btn-xs btn-ghost text-info">Open zone atlas</a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</section>
