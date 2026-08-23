@php
    $zoneMapConfig = [
        'dataUrl' => $atlasUrl,
        'layers' => $atlasLayers,
        'subjectName' => $zone->long_name . ' map entries',
        'coordinateOrder' => config('everquest.coords_as_yxz') ? 'yxz' : 'xyz',
        'syncUrl' => true,
    ];
@endphp

<input type="radio" name="zone_details" class="tab" aria-label="Map" checked="checked" />
<div class="tab-content bg-base-100 border-base-300">
    <div class="space-y-4 p-3 sm:p-5">
        @include('maps.atlas', [
            'atlasId' => 'zone-atlas',
            'atlasHeading' => 'Zone atlas',
            'atlasDescription' => 'Filter NPCs, items, exits, and useful world locations.',
            'atlasEmptyHelp' => 'The zone tables below remain available even without a base map.',
            'mapConfig' => $zoneMapConfig,
        ])
    </div>
</div>
