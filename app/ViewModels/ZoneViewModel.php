<?php
namespace App\ViewModels;

use App\Models\Item;
use App\Models\Task;
use App\Models\Zone;
use App\Models\NpcType;
use App\Models\SpawnTwo;
use Illuminate\Support\Collection;

class ZoneViewModel
{
    protected Zone $zone;
    protected int $version;

    public function __construct(Zone $zone, int $version = 0)
    {
        $this->zone = $zone;
        $this->version = $version;
    }

    public function connectedZones(): Collection
    {
        return $this->zone->zonepoints
            ->pluck('targetZones')
            ->filter()
            ->unique('id')
            ->sortBy('long_name')
            ->values();
    }

    public function npcs(): Collection
    {
        $zone_short = $this->zone->short_name;
        $zone_id = $this->zone->zoneidnumber;

        $query = NpcType::whereHas('spawnentries.spawn2', function ($query) use ($zone_short) {
            $query->where('zone', $zone_short)
                ->where('version', $this->version);
            })
            ->whereNotIn('race', [127, 240])
            ->select([
                'id', 'class', 'hp', 'level', 'trackable', 'maxlevel', 'race', 'name',
                'loottable_id', 'raid_target', 'rare_spawn', 'special_abilities',
            ])
            ->get();

        $query2 = NpcType::select([
                'id', 'class', 'hp', 'level', 'trackable', 'maxlevel', 'race', 'name',
                'loottable_id', 'raid_target', 'rare_spawn', 'special_abilities',
            ])
            ->whereNotIn('race', [127, 240])
            ->whereRaw('CAST(SUBSTRING(id, 1, LENGTH(id) - 3) AS UNSIGNED) = ?', [$zone_id])
            ->whereDoesntHave('spawnentries')
            ->get();

        return $query
            ->merge($query2)
            ->unique('name')
            ->sortBy(fn ($npc) => $npc->clean_name)
            ->values();
    }

    public function drops(): array
    {
        $npcs = $this->npcs();
        $loottableIds = $npcs->pluck('loottable_id')->unique()->filter()->all();

        $items = Item::select([
            'items.id', 'items.Name', 'items.icon', 'items.itemtype', 'items.bagslots',
            'loottable_entries.loottable_id',
            ])
            ->join('lootdrop_entries', 'items.id', '=', 'lootdrop_entries.item_id')
            ->join('loottable_entries', 'lootdrop_entries.lootdrop_id', '=', 'loottable_entries.lootdrop_id')
            ->whereIn('loottable_entries.loottable_id', $loottableIds)
            ->groupBy('items.id', 'loottable_entries.loottable_id')
            ->orderBy('items.Name')
            //if ($discovered_items_only) {
                //$items = $items->join('discovered_items', 'items.id', '=', 'discovered_items.item_id');
            //}
            ->get();

        $npcMap = [];
        foreach ($npcs as $npc) {
            if ($npc->loottable_id) {
                $npcMap[$npc->loottable_id][] = $npc;
            }
        }

        $drops = [];
        foreach ($items as $item) {
            if (!isset($drops[$item->id])) {
                $drops[$item->id] = [
                    'item' => $item,
                    'npcs' => [],
                ];
            }

            $drops[$item->id]['npcs'] = array_merge(
                $drops[$item->id]['npcs'],
                $npcMap[$item->loottable_id] ?? []
            );
        }

        uasort($drops, fn($a, $b) => strcasecmp($a['item']->Name, $b['item']->Name));

        return $drops;
    }

    public function spawnGroups(): Collection
    {
        return SpawnTwo::with(['spawnGroup.spawnentries.npc' => function ($q) {
            $q->whereNotIn('race', [127, 240]);
        }])
        ->where('zone', $this->zone->short_name)
        ->where('version', $this->version)
        ->whereHas('spawnGroup.spawnentries.npc', function ($q) {
            $q->whereNotIn('race', [127, 240]);
        })
        ->get()
        ->map(function ($spawn2) {
            $group = $spawn2->spawnGroup;
            $group->x = $spawn2->x;
            $group->y = $spawn2->y;
            $group->z = $spawn2->z;
            $group->respawntime = $spawn2->respawntime;

            return $group;
        })
        ->sortBy('name')
        ->values();
    }

    public function foraged(): Collection
    {
        return Item::whereHas('foraged.zone', function ($query) {
            $query->where('zoneid', $this->zone->zoneidnumber);
        })
        ->select('Name', 'id', 'icon', 'itemtype', 'bagslots')
        ->orderBy('name', 'asc')
        ->get();
    }

    public function fished(): Collection
    {
        return Item::whereHas('fished.zone', function ($query) {
            $query->where('zoneid', $this->zone->zoneidnumber);
        })
        ->select('Name', 'id', 'icon', 'itemtype', 'bagslots')
        ->orderBy('name', 'asc')
        ->get();
    }

    public function tasks(): Collection
    {
        $tasks = Task::whereHas('taskActivities', function ($query) {
            $query->where('zones', $this->zone->zoneidnumber)->where(function ($subQuery) {
                $subQuery->where('zone_version', $this->zone->version)
                    ->orWhere('zone_version', -1);
                });
            })
            ->with(['taskActivities' => function ($query) {
                $query->where('zones', $this->zone->zoneidnumber)->where(function ($subQuery) {
                    $subQuery->where('zone_version', $this->zone->version)
                        ->orWhere('zone_version', -1);
            });
        }])
        ->withCount('taskActivities')
        ->where('min_level', '<=', config('everquest.server_max_level'))
        ->where('enabled', 1)
        ->orderBy('min_level')
        ->get();

        $tasks = Task::attachRewardsMultiple($tasks);

        return $tasks;
    }
}
