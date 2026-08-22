<?php

namespace App\Http\Controllers;

use App\Filters\NpcFilter;
use App\Models\AlternateCurrency;
use App\Models\DiscoveredItem;
use App\Models\NpcSpell;
use App\Models\NpcType;
use App\Models\Zone;
use App\Services\NpcLocationService;
use Illuminate\Http\Request;

class NpcController extends Controller
{
    public function index(Request $request)
    {
        $npcs = collect();
        $currentExpansion = config('everquest.current_expansion');

        $ignoreZones = config('everquest.ignore_zones') ?? [];
        $zones = Zone::select('id', 'zoneidnumber', 'short_name', 'long_name', 'expansion', 'version')
            ->when(!empty($ignoreZones), function ($q) use ($ignoreZones) {
                $q->whereNotIn('short_name', $ignoreZones);
            })
            ->where('expansion', '<=', $currentExpansion)
            ->orderBy('expansion')
            ->orderBy('long_name')
            ->get()
            ->unique('zoneidnumber')
            ->values();

        if ($request->query->count() > 0) {
            $npcs = (new NpcFilter($request))
                ->apply(NpcType::query())
                ->select('id', 'name', 'level', 'race', 'class', 'hp', 'maxlevel', 'version')
                ->whereNotNull('name')
                ->where('name', '<>', '')
                ->whereNotIn('race', [127, 240])
                ->with([
                    'firstSpawnEntries.spawn2.zoneData',
                ])
                ->orderBy('name', 'asc')
                ->paginate(50)
                ->withQueryString();

            foreach ($npcs as $npc) {
                foreach ($npc->spawnEntries as $entry) {
                    $spawn2 = $entry->spawn2;

                    if (is_object($spawn2) && method_exists($spawn2, 'first')) {
                        $spawn2 = $spawn2->first();
                    }

                    if (!$spawn2) continue;

                    $entry->matched_zone = $zones
                        ->where('short_name', $spawn2->zone)
                        ->where('version', $spawn2->version)
                        ->first();
                }
            }
        }

        return view('npcs.index', [
            'npcs' => $npcs,
            'metaTitle' => config('app.name') . ' - NPC Search',
            'zones' => $zones,
        ]);
    }

    public function show(NpcType $npc, NpcLocationService $locationService)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');

        $npc = NpcType::with('npcSpellset.attackProcSpell')
            ->with([
                'npcFaction.primaryFaction',
                'npcFactionEntries.factionList',
                'lootTable.loottableEntries.lootdropEntries.item',
                'merchantlist.items',
            ])
            ->findOrFail($npc->id);

        $locationGroups = $locationService->forNpc((int) $npc->id);
        $hasSpawnLocations = collect($locationGroups)
            ->contains(fn ($group) => ! empty($group['locations']));
        $primaryGroup = $locationGroups[0] ?? null;
        $primaryLocationZone = $primaryGroup ? [
            'id' => $primaryGroup['zone_row_id'],
            'zone_id' => $primaryGroup['zone_id'],
            'short_name' => $primaryGroup['short_name'],
            'long_name' => $primaryGroup['long_name'],
            'version' => $primaryGroup['version'],
        ] : null;

        // Prevent the legacy Blade spawn graph from silently lazy-loading.
        $npc->setRelation('spawnEntries', collect());
        $npc->setRelation('firstSpawnEntries', null);

        if ($npc->npcSpellset) {
            $npc->attackProcSpell = $npc->npcSpellset->attackProcSpell;
            $npc->attackProcSpellProcChance = $npc->npcSpellset->proc_chance;
        }

        $npcSpellset = $npc->npcSpellset;
        if ($npcSpellset && $npcSpellset->parent_list > 0) {
            $npc->npcSpellset = NpcSpell::with('npcSpellEntries.spells', 'attackProcSpell')
                ->where('id', $npcSpellset->parent_list)
                ->first();
        }

        if ($npc->npcSpellset) {
            $npc->filteredSpellEntries = $npc->npcSpellset->npcSpellEntries()
                ->where('minlevel', '<=', $npc->level)
                ->where('maxlevel', '>=', $npc->level)
                ->orderBy('priority', 'desc')
                ->with('spells')
                ->get();
        } else {
            $npc->filteredSpellEntries = collect();
        }

        // separate and group faction
        $raisesFaction = [];
        $lowersFaction = [];

        foreach ($npc->npcFactionEntries as $entry) {
            $factionName = $entry->factionList->name ?? 'Unknown';
            $factionId   = $entry->faction_id;
            $value       = $entry->value;

            if ($value > 0) {
                $raisesFaction[] = [
                    'name' => $factionName,
                    'id' => $factionId,
                    'value' => $value,
                ];
            } elseif ($value < 0) {
                $lowersFaction[] = [
                    'name' => $factionName,
                    'id' => $factionId,
                    'value' => $value,
                ];
            }
        }

        // discovery
        $itemIds = collect();
        if ($discoveryEnabled) {
            if ($npc->lootTable) {
                $itemIds = $itemIds->merge(
                    $npc->lootTable->loottableEntries
                        ->flatMap(function ($entry) {
                            return $entry->lootdropEntries->pluck('item.id');
                        })
                );
            }

            if ($npc->merchantlist) {
                $itemIds = $itemIds->merge($npc->merchantlist->pluck('items.id'));
            }

            $itemIds = $itemIds->filter()->unique()->values();
        }

        $discoveredItems = $discoveryEnabled
            ? DiscoveredItem::whereIn('item_id', $itemIds)->pluck('item_id')->flip()
            : collect();

        $defaultTab = null;
        if ($npc->lootTable?->loottableEntries->isNotEmpty()) {
            $defaultTab = 'drops';
        } elseif ($npc->merchantlist->isNotEmpty()) {
            $defaultTab = 'merchant';
        } elseif ($hasSpawnLocations) {
            $defaultTab = 'spawns';
        } elseif ($npc->npcFactionEntries->isNotEmpty()) {
            $defaultTab = 'faction';
        }

        $lvl = $npc->level ? ' - Level (' . $npc->level . ')' : '';

        $altCurrency = AlternateCurrency::allAltCurrency();

        return view('npcs.show', [
            'npc' => $npc,
            'defaultTab' => $defaultTab,
            'locationGroups' => $locationGroups,
            'hasSpawnLocations' => $hasSpawnLocations,
            'primaryLocationZone' => $primaryLocationZone,
            'raisesFaction' => $raisesFaction,
            'lowersFaction' => $lowersFaction,
            'altCurrency' => $altCurrency,
            'discoveredItems' => $discoveredItems,
            'metaTitle' => config('app.name') . ' - NPC: ' . $npc->clean_name . $lvl,
        ]);
    }
}
