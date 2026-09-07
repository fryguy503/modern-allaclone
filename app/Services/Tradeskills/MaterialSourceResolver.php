<?php

namespace App\Services\Tradeskills;

use App\Models\NpcType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class MaterialSourceResolver
{
    private const ITEM_CHUNK_SIZE = 500;

    private int $maxSourcesPerType;

    private int $maxSourceRows;

    public function __construct()
    {
        $this->maxSourcesPerType = $this->boundedConfigInt('max_sources_per_type', 5, 1, 25);
        $this->maxSourceRows = $this->boundedConfigInt('max_source_rows', 10_000, 100, 50_000);
    }

    /**
     * Resolve acquisition sources for many items without issuing per-item queries.
     *
     * @param  array<int|string>  $itemIds
     * @return array<int, array{sources: array<int, array{type: string, label: string, detail: string, url: ?string}>, sourceTypes: array<int, string>}>
     */
    public function resolve(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn (int $itemId): bool => $itemId > 0,
        )));
        sort($itemIds, SORT_NUMERIC);

        $resolved = [];
        foreach ($itemIds as $itemId) {
            $resolved[$itemId] = [
                'sources' => [],
                'sourceTypes' => [],
            ];
        }

        if ($itemIds === []) {
            return $resolved;
        }

        $this->loadVendors($itemIds, $resolved);
        $this->loadForage($itemIds, $resolved);
        $this->loadFishing($itemIds, $resolved);
        $this->loadGroundSpawns($itemIds, $resolved);
        $this->loadDrops($itemIds, $resolved);

        return $resolved;
    }

    /** @param array<int> $itemIds */
    private function loadVendors(array $itemIds, array &$resolved): void
    {
        foreach ($this->itemChunks($itemIds) as $chunk) {
            $query = DB::connection('eqemu')
                ->table('merchantlist as ml')
                ->join('npc_types as n', 'n.merchant_id', '=', 'ml.merchantid')
                ->join('spawnentry as se', 'se.npcID', '=', 'n.id')
                ->join('spawn2 as s2', 's2.spawngroupID', '=', 'se.spawngroupID')
                ->leftJoin('zone as z', function ($join) {
                    $join->on('z.short_name', '=', 's2.zone')
                        ->on('z.version', '=', 's2.version');
                })
                ->whereIn('ml.item', $chunk)
                ->where('se.chance', '>', 0)
                ->tap(fn (Builder $query) => $this->applyZoneFilters($query, 's2', 'z'))
                ->select([
                    'ml.item as item_id',
                    'n.id as npc_id',
                    'n.name as npc_name',
                    's2.zone as zone_short_name',
                    'z.id as zone_id',
                    'z.long_name as zone_name',
                ])
                ->distinct();

            $rows = $this->fairRows($query, 'zone_name ASC, npc_name ASC, npc_id ASC');

            foreach ($rows as $row) {
                $detail = $this->joinedDetail([
                    $this->cleanNpcName($row->npc_name),
                    $row->zone_name ?: $row->zone_short_name,
                ], 'Merchant listing');

                $this->addSource($resolved, (int) $row->item_id, [
                    'type' => 'vendor',
                    'label' => 'Vendor',
                    'detail' => $detail,
                    'url' => $row->npc_id
                        ? route('npcs.show', (int) $row->npc_id, absolute: false)
                        : null,
                ]);
            }
        }
    }

    /** @param array<int> $itemIds */
    private function loadForage(array $itemIds, array &$resolved): void
    {
        foreach ($this->itemChunks($itemIds) as $chunk) {
            $query = DB::connection('eqemu')
                ->table('forage as f')
                ->leftJoin('zone as z', 'z.zoneidnumber', '=', 'f.zoneid')
                ->whereIn('f.itemid', $chunk)
                ->where('f.chance', '>', 0)
                ->tap(fn (Builder $query) => $this->applyDirectZoneFilters($query, 'z'))
                ->select([
                    'f.itemid as item_id',
                    'f.chance',
                    'f.level',
                    'z.id as zone_id',
                    'z.short_name as zone_short_name',
                    'z.long_name as zone_name',
                ])
                ->distinct();

            $rows = $this->fairRows($query, 'chance DESC, zone_name ASC, zone_id ASC');

            foreach ($rows as $row) {
                $detailParts = [$row->zone_name ?: $row->zone_short_name];
                if ((float) $row->chance > 0) {
                    $detailParts[] = $this->formatPercent($row->chance).' chance';
                }
                if ((int) $row->level > 0) {
                    $detailParts[] = 'level '.(int) $row->level;
                }

                $this->addSource($resolved, (int) $row->item_id, [
                    'type' => 'forage',
                    'label' => 'Forage',
                    'detail' => $this->joinedDetail($detailParts, 'Forage table'),
                    'url' => $row->zone_id
                        ? route('zones.show', (int) $row->zone_id, absolute: false)
                        : null,
                ]);
            }
        }
    }

    /** @param array<int> $itemIds */
    private function loadFishing(array $itemIds, array &$resolved): void
    {
        foreach ($this->itemChunks($itemIds) as $chunk) {
            $query = DB::connection('eqemu')
                ->table('fishing as f')
                ->leftJoin('zone as z', 'z.zoneidnumber', '=', 'f.zoneid')
                ->whereIn('f.itemid', $chunk)
                ->where('f.zoneid', '>', 0)
                ->where('f.chance', '>', 0)
                ->tap(fn (Builder $query) => $this->applyDirectZoneFilters($query, 'z'))
                ->select([
                    'f.itemid as item_id',
                    'f.chance',
                    'f.skill_level',
                    'z.id as zone_id',
                    'z.short_name as zone_short_name',
                    'z.long_name as zone_name',
                ])
                ->distinct();

            $rows = $this->fairRows($query, 'chance DESC, zone_name ASC, zone_id ASC');

            foreach ($rows as $row) {
                $detailParts = [$row->zone_name ?: $row->zone_short_name];
                if ((float) $row->chance > 0) {
                    $detailParts[] = $this->formatPercent($row->chance).' chance';
                }
                if ((int) $row->skill_level > 0) {
                    $detailParts[] = 'skill '.(int) $row->skill_level;
                }

                $this->addSource($resolved, (int) $row->item_id, [
                    'type' => 'fishing',
                    'label' => 'Fishing',
                    'detail' => $this->joinedDetail($detailParts, 'Fishing table'),
                    'url' => $row->zone_id
                        ? route('zones.show', (int) $row->zone_id, absolute: false)
                        : null,
                ]);
            }
        }
    }

    /** @param array<int> $itemIds */
    private function loadGroundSpawns(array $itemIds, array &$resolved): void
    {
        foreach ($this->itemChunks($itemIds) as $chunk) {
            $query = DB::connection('eqemu')
                ->table('ground_spawns as gs')
                ->leftJoin('zone as z', 'z.zoneidnumber', '=', 'gs.zoneid')
                ->whereIn('gs.item', $chunk)
                ->tap(fn (Builder $query) => $this->applyDirectZoneFilters($query, 'z'))
                ->select([
                    'gs.item as item_id',
                    'gs.max_x',
                    'gs.max_y',
                    'gs.max_z',
                    'z.id as zone_id',
                    'z.short_name as zone_short_name',
                    'z.long_name as zone_name',
                ])
                ->distinct();

            $rows = $this->fairRows($query, 'zone_name ASC, zone_id ASC');

            foreach ($rows as $row) {
                $detailParts = [$row->zone_name ?: $row->zone_short_name];
                if ($row->max_x !== null && $row->max_y !== null && $row->max_z !== null) {
                    $detailParts[] = sprintf(
                        '%.0f, %.0f, %.0f',
                        (float) $row->max_x,
                        (float) $row->max_y,
                        (float) $row->max_z,
                    );
                }

                $this->addSource($resolved, (int) $row->item_id, [
                    'type' => 'ground',
                    'label' => 'Ground spawn',
                    'detail' => $this->joinedDetail($detailParts, 'Ground spawn'),
                    'url' => $row->zone_id
                        ? route('zones.show', (int) $row->zone_id, absolute: false)
                        : null,
                ]);
            }
        }
    }

    /** @param array<int> $itemIds */
    private function loadDrops(array $itemIds, array &$resolved): void
    {
        $excludeMerchants = (bool) config('everquest.merchants_dont_drop_stuff', true);

        foreach ($this->itemChunks($itemIds) as $chunk) {
            $query = DB::connection('eqemu')
                ->table('lootdrop_entries as lde')
                ->join('loottable_entries as lte', 'lte.lootdrop_id', '=', 'lde.lootdrop_id')
                ->join('npc_types as n', 'n.loottable_id', '=', 'lte.loottable_id')
                ->join('spawnentry as se', 'se.npcID', '=', 'n.id')
                ->join('spawn2 as s2', 's2.spawngroupID', '=', 'se.spawngroupID')
                ->leftJoin('zone as z', function ($join) {
                    $join->on('z.short_name', '=', 's2.zone')
                        ->on('z.version', '=', 's2.version');
                })
                ->whereIn('lde.item_id', $chunk)
                ->where('lde.chance', '>', 0)
                ->where('se.chance', '>', 0)
                ->when($excludeMerchants, fn (Builder $query) => $query->where('n.merchant_id', 0))
                ->tap(fn (Builder $query) => $this->applyZoneFilters($query, 's2', 'z'))
                ->select([
                    'lde.item_id',
                    'lde.chance as drop_chance',
                    'lte.probability as loot_probability',
                    'n.id as npc_id',
                    'n.name as npc_name',
                    's2.zone as zone_short_name',
                    'z.id as zone_id',
                    'z.long_name as zone_name',
                ])
                ->distinct();

            $rows = $this->fairRows($query, 'drop_chance DESC, npc_name ASC, npc_id ASC');

            foreach ($rows as $row) {
                $detailParts = [
                    $this->cleanNpcName($row->npc_name),
                    $row->zone_name ?: $row->zone_short_name,
                ];
                if ((float) $row->drop_chance > 0) {
                    $detailParts[] = $this->formatPercent($row->drop_chance).' loot roll';
                }

                $this->addSource($resolved, (int) $row->item_id, [
                    'type' => 'drop',
                    'label' => 'Creature drop',
                    'detail' => $this->joinedDetail($detailParts, 'Loot table'),
                    'url' => $row->npc_id
                        ? route('npcs.show', (int) $row->npc_id, absolute: false)
                        : null,
                ]);
            }
        }
    }

    private function applyZoneFilters(Builder $query, string $spawnAlias, string $zoneAlias): void
    {
        $ignoreZones = array_values(array_filter((array) config('everquest.ignore_zones', [])));
        if ($ignoreZones !== []) {
            $query->whereNotIn("{$spawnAlias}.zone", $ignoreZones);
        }

        $this->applyExpansionFilter($query, $zoneAlias);
    }

    private function applyDirectZoneFilters(Builder $query, string $zoneAlias): void
    {
        $ignoreZones = array_values(array_filter((array) config('everquest.ignore_zones', [])));
        if ($ignoreZones !== []) {
            $query->where(function (Builder $nested) use ($ignoreZones, $zoneAlias) {
                $nested->whereNull("{$zoneAlias}.short_name")
                    ->orWhereNotIn("{$zoneAlias}.short_name", $ignoreZones);
            });
        }

        $this->applyExpansionFilter($query, $zoneAlias);
    }

    private function applyExpansionFilter(Builder $query, string $zoneAlias): void
    {
        $currentExpansion = (int) config('everquest.current_expansion', 0);

        $query->where(function (Builder $nested) use ($currentExpansion, $zoneAlias) {
            $nested->whereNull("{$zoneAlias}.expansion")
                ->orWhere("{$zoneAlias}.expansion", '<=', $currentExpansion);
        });
    }

    /**
     * @param  array<int, array{sources: array, sourceTypes: array}>  $resolved
     * @param  array{type: string, label: string, detail: string, url: ?string}  $source
     */
    private function addSource(array &$resolved, int $itemId, array $source): void
    {
        if (! isset($resolved[$itemId])) {
            return;
        }

        if (! in_array($source['type'], $resolved[$itemId]['sourceTypes'], true)) {
            $resolved[$itemId]['sourceTypes'][] = $source['type'];
        }

        $duplicate = collect($resolved[$itemId]['sources'])->contains(
            fn (array $existing): bool => $existing['type'] === $source['type']
                && $existing['detail'] === $source['detail']
                && $existing['url'] === $source['url'],
        );

        $typeCount = count(array_filter(
            $resolved[$itemId]['sources'],
            static fn (array $existing): bool => $existing['type'] === $source['type'],
        ));

        if (! $duplicate && $typeCount < $this->maxSourcesPerType) {
            $resolved[$itemId]['sources'][] = $source;
        }
    }

    /** @param array<int, mixed> $parts */
    private function joinedDetail(array $parts, string $fallback): string
    {
        $parts = array_values(array_filter(
            array_map(static fn ($part): string => trim((string) $part), $parts),
            static fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? $fallback : implode(' · ', $parts);
    }

    private function cleanNpcName(mixed $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : NpcType::npcFixName($name);
    }

    private function formatPercent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%';
    }

    /** @param array<int> $itemIds */
    private function itemChunks(array $itemIds): array
    {
        $chunkSize = min(
            self::ITEM_CHUNK_SIZE,
            max(1, intdiv($this->maxSourceRows, $this->maxSourcesPerType)),
        );

        return array_chunk($itemIds, $chunkSize);
    }

    private function fairRows(Builder $query, string $orderBy): iterable
    {
        $rankedQuery = DB::connection('eqemu')
            ->query()
            ->fromSub($query, 'source_candidate')
            ->select('source_candidate.*')
            ->selectRaw(
                "ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY {$orderBy}) as planner_source_rank",
            );

        return DB::connection('eqemu')
            ->query()
            ->fromSub($rankedQuery, 'ranked_source')
            ->where('planner_source_rank', '<=', $this->maxSourcesPerType)
            ->orderBy('item_id')
            ->orderBy('planner_source_rank')
            ->limit($this->maxSourceRows)
            ->get();
    }

    private function boundedConfigInt(string $key, int $default, int $minimum, int $maximum): int
    {
        return min(
            $maximum,
            max($minimum, (int) config("everquest.tradeskill_planner.{$key}", $default)),
        );
    }
}
