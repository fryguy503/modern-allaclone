<?php

namespace App\Http\Controllers;

use App\Models\AlternateCurrency;
use App\Models\DiscoveredItem;
use App\Models\Zone;
use App\Services\ZoneAtlasService;
use App\ViewModels\ZoneViewModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ZoneController extends Controller
{
    public function index(Request $request)
    {
        $currentExpansion = config('everquest.current_expansion');
        $expansions = config('everquest.expansions');

        $zones = Cache::remember('zones.index', now()->addMonth(), function () use ($currentExpansion) {
            return Zone::getExpansionZones($currentExpansion);
        });

        return view('zones.index', [
            'zones' => $zones,
            'expansions' => $expansions,
            'metaTitle' => config('app.name').' - Zones',
        ]);
    }

    public function show(Zone $zone, Request $request, ZoneAtlasService $atlasService)
    {
        $request->validate(['v' => ['nullable', 'integer', 'min:0', 'max:32767']]);
        $version = $request->has('v') ? (int) $request->query('v') : (int) $zone->version;
        $zone = Zone::where('id', $zone->id)->where('version', $version)->firstOrFail();
        abort_unless($atlasService->isZoneAccessible($zone), 404);

        $zoneCache = Cache::rememberForever("zones.show.v2.{$zone->id}_v{$version}", function () use ($zone, $version) {
            $zone = Zone::where('id', $zone->id)
                ->with('zonepoints', function ($q) use ($version) {
                    $q->where('version', $version)
                        ->with('targetZones:id,zoneidnumber,short_name,long_name');
                })
                ->where('version', $version)
                ->firstOrFail();

            $vm = new ZoneViewModel($zone, $version);

            return [
                'zone' => $zone,
                'npcs' => $vm->npcs(),
                'drops' => $vm->drops(),
                'spawnGroups' => $vm->spawnGroups(),
                'foraged' => $vm->foraged(),
                'fished' => $vm->fished(),
                'connectedZones' => $vm->connectedZones(),
                'tasks' => $vm->tasks(),
            ];
        });

        // get cached alt currency since tasks could use it
        $altCurrency = AlternateCurrency::allAltCurrency();

        $discoveredItems = collect();
        if (config('everquest.discovered_items.enable')) {
            $itemIds = collect()
                ->merge(collect($zoneCache['drops'])->pluck('item.id'))
                ->merge(collect($zoneCache['foraged'])->pluck('item.id'))
                ->merge(collect($zoneCache['fished'])->pluck('item.id'))
                ->unique()
                ->values();

            $discoveredItems = DiscoveredItem::whereIn('item_id', $itemIds)
                ->pluck('item_id')
                ->flip();
        }

        // zone version for meta title
        $zone = $zoneCache['zone'];
        $zversion = $zone->version ? ' - version ('.$zone->version.')' : '';

        return view('zones.show', [
            ...$zoneCache,
            'altCurrency' => $altCurrency,
            'discoveredItems' => $discoveredItems,
            'atlasLayers' => $atlasService->layerDefinitions(),
            'atlasUrl' => route('zones.atlas', ['zone' => $zone->id, 'v' => $version]),
            'metaTitle' => config('app.name').' - Zone: '.$zone->long_name.$zversion,
        ]);
    }

    public function atlas(Zone $zone, Request $request, ZoneAtlasService $atlasService): JsonResponse
    {
        $validated = $request->validate(['v' => ['nullable', 'integer', 'min:0', 'max:32767']]);
        $version = array_key_exists('v', $validated) ? (int) $validated['v'] : (int) $zone->version;
        $zone = Zone::where('id', $zone->id)->where('version', $version)->firstOrFail();
        abort_unless($atlasService->isZoneAccessible($zone), 404);

        $mapMetadata = $atlasService->mapMetadata($zone);

        $cacheContext = hash('sha256', json_encode([
            'schema' => 3,
            'expansion' => (int) config('everquest.current_expansion', 0),
            'locations' => (bool) config('everquest.npc.display.spawn_locs', true),
            'respawn' => (bool) config('everquest.npc.display.respawn', true),
            'discovery' => (bool) config('everquest.discovered_items.enable', false),
            'coordinate_order' => (bool) config('everquest.coords_as_yxz', false) ? 'yxz' : 'xyz',
            'map' => $mapMetadata,
            'content_flags' => $atlasService->contentFlagSignature(),
        ], JSON_THROW_ON_ERROR));

        $dataset = Cache::remember(
            "zones.atlas.{$zone->id}_v{$version}.{$cacheContext}",
            now()->addDay(),
            fn () => $atlasService->forZone($zone, $version),
        );

        $response = response()->json($dataset)
            ->setPublic()
            ->setMaxAge(900)
            ->setSharedMaxAge(3600)
            ->setEtag(hash('sha256', json_encode($dataset, JSON_THROW_ON_ERROR)));
        $response->isNotModified($request);

        return $response;
    }
}
