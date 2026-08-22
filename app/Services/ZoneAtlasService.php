<?php

namespace App\Services;

use App\Models\NpcType;
use App\Models\Zone;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class ZoneAtlasService
{
    private const MAX_SPAWN_POINTS = 3000;

    private const MAX_CANDIDATE_ROWS = 8000;

    private const MAX_CANDIDATES_PER_SPAWN = 8;

    private const MAX_GROUND_SPAWNS = 2000;

    private const MAX_ZONE_POINTS = 1000;

    private const MAX_DOORS = 1000;

    private const MAX_OBJECTS = 1000;

    private const INTERACTIVE_OBJECT_TYPES = [
        10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
        24, 25, 26, 27, 30, 31, 32, 33, 34, 35, 36, 38, 39,
        40, 41, 42, 43, 44, 45, 47, 48, 49, 50, 53,
    ];

    /** @var array<int, string>|null */
    private ?array $enabledContentFlags = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly UrlGenerator $url,
        private readonly ZoneMapCatalog $maps,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function layerDefinitions(): array
    {
        return [
            ['id' => 'npcs', 'label' => 'NPCs', 'color' => '#38bdf8', 'shape' => 'circle', 'default' => true],
            ['id' => 'named', 'label' => 'Named & raid', 'color' => '#fb7185', 'shape' => 'star', 'default' => true],
            ['id' => 'merchants', 'label' => 'Merchants', 'color' => '#facc15', 'shape' => 'square', 'default' => true],
            ['id' => 'quest', 'label' => 'Quest NPCs', 'color' => '#a78bfa', 'shape' => 'pentagon', 'default' => true],
            ['id' => 'ground-spawns', 'label' => 'Ground items', 'color' => '#4ade80', 'shape' => 'diamond', 'default' => true],
            ['id' => 'zone-points', 'label' => 'Zone exits', 'color' => '#fb923c', 'shape' => 'triangle', 'default' => true],
            ['id' => 'doors', 'label' => 'Doors & portals', 'color' => '#2dd4bf', 'shape' => 'hexagon', 'default' => false],
            ['id' => 'objects', 'label' => 'Trade containers', 'color' => '#e879f9', 'shape' => 'cross', 'default' => false],
            ['id' => 'navigation', 'label' => 'Navigation', 'color' => '#f8fafc', 'shape' => 'compass', 'default' => true],
        ];
    }

    /** @return array<string, mixed>|null */
    public function mapMetadata(Zone $zone): ?array
    {
        $mapFileName = trim((string) ($zone->map_file_name ?? ''));

        return ($mapFileName !== '' ? $this->maps->find($mapFileName) : null)
            ?? $this->maps->find(trim((string) $zone->short_name));
    }

    public function contentFlagSignature(): string
    {
        return hash('sha256', implode("\n", $this->activeContentFlags()));
    }

    public function isZoneAccessible(Zone $zone): bool
    {
        $shortName = trim((string) $zone->short_name);

        return $shortName !== ''
            && (int) ($zone->min_status ?? 0) <= 0
            && (int) ($zone->expansion ?? 0) <= (int) $this->config->get('everquest.current_expansion', 0)
            && ! in_array($shortName, $this->ignoredZones(), true)
            && $this->passesContentFlags(
                $zone->content_flags ?? null,
                $zone->content_flags_disabled ?? null,
            );
    }

    /** @return array<string, mixed> */
    public function forZone(Zone $zone, int $version): array
    {
        $shortName = trim((string) $zone->short_name);
        $currentExpansion = (int) $this->config->get('everquest.current_expansion', 0);
        $map = $this->mapMetadata($zone);

        if (! $this->isZoneAccessible($zone)) {
            return $this->emptyDataset($zone, $version, $map);
        }

        $truncated = [];
        $locations = collect();

        if ((bool) $this->config->get('everquest.npc.display.spawn_locs', true)) {
            [$npcLocations, $npcTruncated] = $this->npcLocations($shortName, $version, $currentExpansion);
            $locations = $locations->concat($npcLocations);
            $truncated['npcs'] = $npcTruncated;
        }

        [$groundSpawns, $groundTruncated] = $this->groundSpawns((int) $zone->zoneidnumber, $version, $currentExpansion);
        [$zonePoints, $zonePointDefinitions, $zonePointTruncated] = $this->zonePoints($shortName, $version, $map);
        [$doors, $doorTruncated] = $this->doors(
            $shortName,
            $version,
            $currentExpansion,
            $zonePointDefinitions,
            $map,
        );
        [$objects, $objectTruncated] = $this->objects((int) $zone->zoneidnumber, $version, $currentExpansion, $map);

        $locations = $locations
            ->concat($groundSpawns)
            ->concat($zonePoints)
            ->concat($doors)
            ->concat($objects);

        $safePoint = $this->safePoint($zone, $map);
        if ($safePoint !== null) {
            $locations->push($safePoint);
        }
        $graveyard = $this->graveyard($zone, $map);
        if ($graveyard !== null) {
            $locations->push($graveyard);
        }

        $truncated += [
            'ground-spawns' => $groundTruncated,
            'zone-points' => $zonePointTruncated,
            'doors' => $doorTruncated,
            'objects' => $objectTruncated,
        ];

        $locations = $locations->values();
        $layers = collect($this->layerDefinitions())
            ->map(function (array $layer) use ($locations, $truncated) {
                $layer['count'] = $locations->filter(
                    fn (array $location) => in_array($layer['id'], $location['layers'] ?? [], true),
                )->count();
                $layer['truncated'] = (bool) ($truncated[$layer['id']] ?? false);

                return $layer;
            })
            ->all();

        return [
            'layers' => $layers,
            'groups' => [[
                'key' => $shortName.':'.$version,
                'short_name' => $shortName,
                'long_name' => (string) $zone->long_name,
                'version' => $version,
                'zone_id' => (int) $zone->zoneidnumber,
                'zone_row_id' => (int) $zone->id,
                'map' => $map,
                'paths' => [],
                'locations' => $locations->all(),
            ]],
            'meta' => [
                'location_count' => $locations->count(),
                'truncated_layers' => array_keys(array_filter($truncated)),
                'coordinate_order' => $this->config->get('everquest.coords_as_yxz') ? 'yxz' : 'xyz',
            ],
        ];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: bool} */
    private function npcLocations(string $shortName, int $version, int $currentExpansion): array
    {
        $connection = $this->database->connection('eqemu');
        $displayRespawn = (bool) $this->config->get('everquest.npc.display.respawn', true);
        $spawnSelect = [
            's2.id', 's2.spawngroupID as spawn_group_id', 's2.x', 's2.y', 's2.z',
            's2.heading', 'sg.name as spawn_group_name',
        ];
        if ($displayRespawn) {
            array_push($spawnSelect, 's2.respawntime', 's2.variance');
        }

        $spawnQuery = $connection
            ->table('spawn2 as s2')
            ->join('spawngroup as sg', 'sg.id', '=', 's2.spawngroupID')
            ->where('s2.zone', $shortName)
            ->where('s2.version', $version)
            ->where(function ($query) use ($currentExpansion) {
                $query->where('s2.min_expansion', -1)->orWhere('s2.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('s2.max_expansion', -1)->orWhere('s2.max_expansion', '>=', $currentExpansion);
            });
        $this->applyContentFilter($spawnQuery, 's2');
        $spawnRows = $spawnQuery
            ->select($spawnSelect)
            ->orderBy('s2.id')
            ->limit(self::MAX_SPAWN_POINTS + 1)
            ->get();

        $truncated = $spawnRows->count() > self::MAX_SPAWN_POINTS;
        $spawnRows = $spawnRows->take(self::MAX_SPAWN_POINTS);
        if ($spawnRows->isEmpty()) {
            return [collect(), $truncated];
        }

        $spawnGroupIds = $spawnRows
            ->pluck('spawn_group_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $candidateQuery = $connection
            ->table('spawnentry as se')
            ->join('npc_types as npc', 'npc.id', '=', 'se.npcID')
            ->whereIn('se.spawngroupID', $spawnGroupIds)
            ->where('se.chance', '>', 0)
            ->whereNotIn('npc.race', [127, 240])
            ->where(function ($query) use ($currentExpansion) {
                $query->where('se.min_expansion', -1)->orWhere('se.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('se.max_expansion', -1)->orWhere('se.max_expansion', '>=', $currentExpansion);
            });
        $this->applyContentFilter($candidateQuery, 'se');
        $candidateRows = $candidateQuery
            ->select([
                'se.spawngroupID as spawn_group_id', 'se.chance', 'npc.id', 'npc.name',
                'npc.level', 'npc.maxlevel', 'npc.merchant_id', 'npc.rare_spawn',
                'npc.raid_target', 'npc.isquest',
            ])
            ->distinct()
            ->orderBy('se.spawngroupID')
            ->orderByDesc('se.chance')
            ->orderBy('npc.id')
            ->limit(self::MAX_CANDIDATE_ROWS + 1)
            ->get();

        $truncated = $truncated || $candidateRows->count() > self::MAX_CANDIDATE_ROWS;
        $candidateGroups = $candidateRows
            ->take(self::MAX_CANDIDATE_ROWS)
            ->groupBy(fn ($candidate) => (int) $candidate->spawn_group_id)
            ->map(fn (Collection $candidates) => $candidates
                ->unique(fn ($candidate) => (int) $candidate->id)
                ->take(self::MAX_CANDIDATES_PER_SPAWN)
                ->map(fn ($candidate) => $this->candidateDto($candidate))
                ->filter()
                ->values());

        return [
            $spawnRows
                ->map(function ($spawn) use ($candidateGroups, $displayRespawn) {
                    $position = $this->position($spawn);
                    $candidates = $candidateGroups->get((int) $spawn->spawn_group_id, collect());
                    if ($position === null || $candidates->isEmpty()) {
                        return null;
                    }

                    $kind = $this->npcKind($candidates);
                    $primary = $this->primaryCandidate($candidates, $kind);
                    $layers = [$kind];
                    if ($candidates->contains(fn (array $candidate) => $candidate['merchant'])) {
                        $layers[] = 'merchants';
                    }
                    if ($candidates->contains(fn (array $candidate) => $candidate['named'])) {
                        $layers[] = 'named';
                    }
                    if ($candidates->contains(fn (array $candidate) => $candidate['quest'])) {
                        $layers[] = 'quest';
                    }

                    $details = [
                        ['label' => 'NPC ID', 'value' => (string) $primary['id']],
                        ['label' => 'Level', 'value' => $primary['level_label']],
                        ['label' => 'Spawn chance', 'value' => $this->compactNumber($primary['chance']).'%'],
                        ['label' => 'Merchant', 'value' => $primary['merchant'] ? 'Yes' : 'No'],
                    ];
                    if ($displayRespawn) {
                        $details[] = ['label' => 'Respawn', 'value' => max(0, (int) ($spawn->respawntime ?? 0)).' seconds'];
                        $details[] = ['label' => 'Variance', 'value' => '±'.max(0, (int) ($spawn->variance ?? 0)).' seconds'];
                    }

                    return [
                        'id' => 'npc-'.(int) $spawn->id,
                        'source_id' => (int) $spawn->id,
                        'kind' => $kind,
                        'layers' => array_values(array_unique($layers)),
                        'label' => $candidates->count() === 1
                            ? $primary['name']
                            : $primary['name'].' + '.($candidates->count() - 1).' possible',
                        'subtitle' => $primary['level_label'].' · '.$this->cleanGroupName((string) $spawn->spawn_group_name),
                        'position' => $position,
                        'heading' => $this->finiteFloat($spawn->heading ?? null),
                        'details' => $details,
                        'url' => $primary['url'],
                        'candidates' => $candidates->all(),
                    ];
                })
                ->filter()
                ->values(),
            $truncated,
        ];
    }

    private function candidateDto(object $candidate): ?array
    {
        $name = trim(NpcType::npcFixName((string) $candidate->name));
        if ($name === '') {
            return null;
        }

        $level = max(0, (int) $candidate->level);
        $maxLevel = max($level, (int) ($candidate->maxlevel ?? $level));

        return [
            'id' => (int) $candidate->id,
            'name' => $name,
            'level' => $level,
            'max_level' => $maxLevel,
            'level_label' => $maxLevel > $level ? 'Levels '.$level.'–'.$maxLevel : 'Level '.$level,
            'chance' => (float) $candidate->chance,
            'merchant' => (int) $candidate->merchant_id > 0,
            'named' => (bool) $candidate->rare_spawn || (bool) $candidate->raid_target,
            'raid' => (bool) $candidate->raid_target,
            'quest' => (bool) $candidate->isquest,
            'url' => $this->url->route('npcs.show', ['npc' => (int) $candidate->id]),
        ];
    }

    private function npcKind(Collection $candidates): string
    {
        if ($candidates->contains(fn (array $candidate) => $candidate['named'])) {
            return 'named';
        }
        if ($candidates->contains(fn (array $candidate) => $candidate['merchant'])) {
            return 'merchants';
        }
        if ($candidates->contains(fn (array $candidate) => $candidate['quest'])) {
            return 'quest';
        }

        return 'npcs';
    }

    private function primaryCandidate(Collection $candidates, string $kind): array
    {
        $match = match ($kind) {
            'named' => fn (array $candidate) => $candidate['named'],
            'merchants' => fn (array $candidate) => $candidate['merchant'],
            'quest' => fn (array $candidate) => $candidate['quest'],
            default => fn () => true,
        };

        return $candidates->first($match) ?? $candidates->first();
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: bool} */
    private function groundSpawns(int $zoneId, int $version, int $currentExpansion): array
    {
        $query = $this->database->connection('eqemu')
            ->table('ground_spawns as gs')
            ->join('items as item', 'item.id', '=', 'gs.item')
            ->where('gs.zoneid', $zoneId)
            ->whereIn('gs.version', [-1, $version])
            ->where(function ($query) use ($currentExpansion) {
                $query->where('gs.min_expansion', -1)->orWhere('gs.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('gs.max_expansion', -1)->orWhere('gs.max_expansion', '>=', $currentExpansion);
            });
        $this->applyContentFilter($query, 'gs');

        if ((bool) $this->config->get('everquest.discovered_items.enable', false)) {
            $query->join('discovered_items as discovered', 'discovered.item_id', '=', 'item.id');
        }

        $rows = $query
            ->select([
                'gs.id', 'gs.min_x', 'gs.max_x', 'gs.min_y', 'gs.max_y', 'gs.max_z',
                'gs.heading', 'gs.version', 'gs.max_allowed', 'gs.respawn_timer',
                'item.id as item_id', 'item.Name as item_name',
            ])
            ->orderBy('item.Name')
            ->orderBy('gs.id')
            ->limit(self::MAX_GROUND_SPAWNS + 1)
            ->get();

        $truncated = $rows->count() > self::MAX_GROUND_SPAWNS;

        return [
            $rows->take(self::MAX_GROUND_SPAWNS)->map(function ($spawn) {
                $minX = $this->finiteFloat($spawn->min_x ?? null);
                $maxX = $this->finiteFloat($spawn->max_x ?? null);
                $minY = $this->finiteFloat($spawn->min_y ?? null);
                $maxY = $this->finiteFloat($spawn->max_y ?? null);
                $z = $this->finiteFloat($spawn->max_z ?? null);
                if ($minX === null || $maxX === null || $minY === null || $maxY === null || $z === null) {
                    return null;
                }
                [$minX, $maxX] = [min($minX, $maxX), max($minX, $maxX)];
                [$minY, $maxY] = [min($minY, $maxY), max($minY, $maxY)];
                $details = [
                    ['label' => 'Item ID', 'value' => (string) $spawn->item_id],
                    ['label' => 'Spawn area', 'value' => $this->areaLabel($minX, $maxX, $minY, $maxY)],
                ];
                if ((int) $spawn->max_allowed > 0) {
                    $details[] = ['label' => 'Maximum active', 'value' => (string) max(0, (int) $spawn->max_allowed)];
                }
                if ((bool) $this->config->get('everquest.npc.display.respawn', true) && (int) $spawn->respawn_timer > 0) {
                    $details[] = ['label' => 'Respawn', 'value' => max(0, (int) $spawn->respawn_timer).' seconds'];
                }

                return [
                    'id' => 'ground-'.(int) $spawn->id,
                    'source_id' => (int) $spawn->id,
                    'kind' => 'ground-spawns',
                    'layers' => ['ground-spawns'],
                    'label' => (string) $spawn->item_name,
                    'subtitle' => (int) $spawn->version === -1 ? 'Ground spawn · all versions' : 'Ground spawn',
                    'position' => ['x' => ($minX + $maxX) / 2, 'y' => ($minY + $maxY) / 2, 'z' => $z],
                    'area' => ['min_x' => $minX, 'max_x' => $maxX, 'min_y' => $minY, 'max_y' => $maxY],
                    'heading' => $this->finiteFloat($spawn->heading ?? null),
                    'details' => $details,
                    'url' => $this->url->route('items.show', ['item' => (int) $spawn->item_id]),
                ];
            })->filter()->values(),
            $truncated,
        ];
    }

    /**
     * @return array{
     *     0: Collection<int, array<string, mixed>>,
     *     1: Collection<int, array<string, mixed>>,
     *     2: bool
     * }
     */
    private function zonePoints(string $shortName, int $version, ?array $map): array
    {
        $query = $this->database->connection('eqemu')
            ->table('zone_points as point')
            ->where('point.zone', $shortName)
            ->where('point.version', $version)
            ->where('point.is_virtual', 0);
        $this->applyExpansionFilter($query, 'point');
        $this->applyContentFilter($query, 'point');
        $rows = $query
            ->select([
                'point.id', 'point.number', 'point.x', 'point.y', 'point.z', 'point.heading',
                'point.target_x', 'point.target_y', 'point.target_z', 'point.target_heading',
                'point.target_zone_id', 'point.target_instance',
            ])
            ->orderBy('point.number')
            ->orderBy('point.id')
            ->limit(self::MAX_ZONE_POINTS + 1)
            ->get();
        $truncated = $rows->count() > self::MAX_ZONE_POINTS;
        $rows = $rows->take(self::MAX_ZONE_POINTS);
        $targets = $this->targetZonesById($rows->pluck('target_zone_id'));

        $definitions = $rows->map(function ($point) use ($targets) {
            $position = $this->position($point);
            $target = $targets->get((int) $point->target_zone_id);
            $targetName = $target?->long_name ?: 'Zone '.(int) $point->target_zone_id;
            $details = [
                ['label' => 'Zone point', 'value' => '#'.(int) $point->number],
                ['label' => 'Destination', 'value' => (string) $targetName],
            ];
            $targetPosition = $this->targetPosition($point);
            if ($targetPosition !== null) {
                $details[] = ['label' => 'Arrival', 'value' => $this->coordinateLabel($targetPosition)];
            }

            return [
                'id' => 'zone-point-'.(int) $point->id,
                'source_id' => (int) $point->id,
                'kind' => 'zone-points',
                'layers' => ['zone-points'],
                'label' => 'To '.$targetName,
                'subtitle' => 'Zone exit',
                'position' => $position,
                'heading' => $this->finiteFloat($point->heading ?? null),
                'details' => $details,
                'show_label' => true,
                'url' => $target ? $this->url->route('zones.show', ['zone' => (int) $target->id]) : null,
                'target_zone_id' => (int) $point->target_zone_id,
                'zone_point_number' => (int) $point->number,
            ];
        })->values();

        $visible = $definitions->filter(
            fn (array $point) => $this->zonePointOriginIsVisible($point['position'] ?? null, $map),
        )->values();

        return [
            $visible,
            $definitions,
            $truncated,
        ];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: bool} */
    private function doors(
        string $shortName,
        int $version,
        int $currentExpansion,
        Collection $zonePoints,
        ?array $map,
    ): array {
        $query = $this->database->connection('eqemu')
            ->table('doors as door')
            ->leftJoin('items as key_item', 'key_item.id', '=', 'door.keyitem')
            ->where('door.zone', $shortName)
            ->whereIn('door.version', [-1, $version])
            ->where(function ($query) {
                $query->where(function ($destination) {
                    $destination->whereNotNull('door.dest_zone')
                        ->where('door.dest_zone', '<>', '')
                        ->whereRaw("UPPER(door.dest_zone) <> 'NONE'");
                })->orWhere('door.opentype', 57)
                    ->orWhere('door.lockpick', '>', 0)
                    ->orWhere('door.keyitem', '>', 0);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('door.min_expansion', -1)->orWhere('door.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('door.max_expansion', -1)->orWhere('door.max_expansion', '>=', $currentExpansion);
            });
        $this->applyContentFilter($query, 'door');
        if ((bool) $this->config->get('everquest.discovered_items.enable', false)) {
            $query->leftJoin('discovered_items as discovered_key', 'discovered_key.item_id', '=', 'key_item.id');
        }

        $select = [
            'door.id', 'door.doorid', 'door.name', 'door.pos_x as x', 'door.pos_y as y',
            'door.pos_z as z', 'door.heading', 'door.opentype', 'door.lockpick', 'door.keyitem',
            'door.dest_zone', 'door.dest_x', 'door.dest_y', 'door.dest_z', 'door.door_param',
            'key_item.Name as key_name',
        ];
        if ((bool) $this->config->get('everquest.discovered_items.enable', false)) {
            $select[] = 'discovered_key.item_id as discovered_key_id';
        }
        $rows = $query->select($select)->orderBy('door.id')->limit(self::MAX_DOORS + 1)->get();
        $truncated = $rows->count() > self::MAX_DOORS;
        $rows = $rows->take(self::MAX_DOORS);
        $targets = $this->targetZonesByShortName($rows->pluck('dest_zone'));

        return [
            $rows->map(function ($door) use ($targets, $zonePoints, $map) {
                $position = $this->position($door);
                if ($position === null || ! $this->positionWithinMap($position, $map)) {
                    return null;
                }
                $destShortName = trim((string) ($door->dest_zone ?? ''));
                if (strtoupper($destShortName) === 'NONE') {
                    $destShortName = '';
                }
                $target = $targets->get(strtolower($destShortName));
                $linkedPoint = (int) $door->opentype === 57
                    ? $zonePoints->firstWhere('zone_point_number', (int) ($door->door_param ?? 0))
                    : null;
                $linkedPointNeedsOrigin = $linkedPoint !== null
                    && ! $this->zonePointOriginIsVisible($linkedPoint['position'] ?? null, $map);
                $targetZoneId = $target
                    ? (int) $target->zoneidnumber
                    : (int) ($linkedPoint['target_zone_id'] ?? 0);
                if ($targetZoneId > 0 && $this->duplicatesZonePoint(
                    $position,
                    $targetZoneId,
                    $zonePoints,
                    $map,
                )) {
                    return null;
                }
                $isPortal = $destShortName !== '' || $linkedPoint !== null;
                if (! $isPortal && (int) $door->lockpick <= 0 && (int) $door->keyitem <= 0) {
                    return null;
                }
                $linkedTargetName = $linkedPoint !== null
                    ? preg_replace('/^To\s+/i', '', (string) ($linkedPoint['label'] ?? 'another zone'))
                    : null;
                $label = $isPortal
                    ? 'Portal to '.($target?->long_name ?: $destShortName ?: $linkedTargetName ?: 'another zone')
                    : 'Locked door';
                $details = [
                    ['label' => 'Door', 'value' => '#'.(int) $door->doorid],
                    ['label' => 'Type', 'value' => $isPortal ? 'Portal' : 'Locked door'],
                ];
                if ((int) $door->lockpick > 0) {
                    $details[] = ['label' => 'Lockpick', 'value' => (string) (int) $door->lockpick];
                }
                $keyVisible = ! (bool) $this->config->get('everquest.discovered_items.enable', false)
                    || ! empty($door->discovered_key_id);
                if ((int) $door->keyitem > 0 && $keyVisible) {
                    $details[] = ['label' => 'Key', 'value' => (string) ($door->key_name ?: 'Item '.(int) $door->keyitem)];
                }

                return [
                    'id' => 'door-'.(int) $door->id,
                    'source_id' => (int) $door->id,
                    'kind' => 'doors',
                    // A type-57 door is the authoritative in-zone origin when
                    // its linked zone_point has no usable source position.
                    'layers' => $linkedPointNeedsOrigin ? ['doors', 'zone-points'] : ['doors'],
                    'label' => $label,
                    'subtitle' => $isPortal ? 'Door portal' : 'Interactive door',
                    'position' => $position,
                    'heading' => $this->finiteFloat($door->heading ?? null),
                    'details' => $details,
                    'show_label' => $isPortal,
                    'url' => $target
                        ? $this->url->route('zones.show', ['zone' => (int) $target->id])
                        : ($linkedPoint['url'] ?? null),
                ];
            })->filter()->values(),
            $truncated,
        ];
    }

    /** @return array{0: Collection<int, array<string, mixed>>, 1: bool} */
    private function objects(int $zoneId, int $version, int $currentExpansion, ?array $map): array
    {
        $query = $this->database->connection('eqemu')
            ->table('object as object')
            ->leftJoin('items as item', 'item.id', '=', 'object.itemid')
            ->where('object.zoneid', $zoneId)
            ->whereIn('object.version', [-1, $version])
            ->whereIn('object.type', self::INTERACTIVE_OBJECT_TYPES)
            ->where(function ($query) use ($currentExpansion) {
                $query->where('object.min_expansion', -1)->orWhere('object.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('object.max_expansion', -1)->orWhere('object.max_expansion', '>=', $currentExpansion);
            });
        $this->applyContentFilter($query, 'object');
        if ((bool) $this->config->get('everquest.discovered_items.enable', false)) {
            $query->leftJoin('discovered_items as discovered_object', 'discovered_object.item_id', '=', 'item.id')
                ->where(function ($visibility) {
                    $visibility->where('object.itemid', 0)->orWhereNotNull('discovered_object.item_id');
                });
        }
        $rows = $query
            ->select([
                'object.id', 'object.xpos as x', 'object.ypos as y', 'object.zpos as z',
                'object.heading', 'object.type', 'object.display_name', 'object.objectname',
                'object.itemid', 'item.Name as item_name',
            ])
            ->orderBy('object.id')
            ->limit(self::MAX_OBJECTS + 1)
            ->get();
        $truncated = $rows->count() > self::MAX_OBJECTS;

        return [
            $rows->take(self::MAX_OBJECTS)->map(function ($object) use ($map) {
                $position = $this->position($object);
                if ($position === null || ! $this->positionWithinMap($position, $map)) {
                    return null;
                }
                $displayName = trim((string) ($object->display_name ?? ''));
                $itemName = trim((string) ($object->item_name ?? ''));
                $label = $displayName ?: $itemName ?: 'Trade container';
                $details = [
                    ['label' => 'Object type', 'value' => (string) (int) $object->type],
                ];
                if ($itemName !== '') {
                    $details[] = ['label' => 'Item', 'value' => $itemName];
                }

                return [
                    'id' => 'object-'.(int) $object->id,
                    'source_id' => (int) $object->id,
                    'kind' => 'objects',
                    'layers' => ['objects'],
                    'label' => $label,
                    'subtitle' => 'Tradeskill or interactive container',
                    'position' => $position,
                    'heading' => $this->finiteFloat($object->heading ?? null),
                    'details' => $details,
                    'url' => (int) $object->itemid > 0
                        ? $this->url->route('items.show', ['item' => (int) $object->itemid])
                        : null,
                ];
            })->filter()->values(),
            $truncated,
        ];
    }

    private function safePoint(Zone $zone, ?array $map): ?array
    {
        $position = [
            'x' => $this->finiteFloat($zone->safe_x ?? null),
            'y' => $this->finiteFloat($zone->safe_y ?? null),
            'z' => $this->finiteFloat($zone->safe_z ?? null),
        ];
        if (in_array(null, $position, true) || ! $this->positionWithinMap($position, $map)) {
            return null;
        }

        return [
            'id' => 'navigation-safe',
            'source_id' => 0,
            'kind' => 'navigation',
            'layers' => ['navigation'],
            'label' => 'Succor / safe point',
            'subtitle' => 'Zone navigation',
            'position' => $position,
            'heading' => $this->finiteFloat($zone->safe_heading ?? null),
            'details' => [['label' => 'Coordinates', 'value' => $this->coordinateLabel($position)]],
            'show_label' => true,
            'url' => null,
        ];
    }

    private function graveyard(Zone $zone, ?array $map): ?array
    {
        $graveyardId = (int) ($zone->graveyard_id ?? 0);
        if ($graveyardId <= 0) {
            return null;
        }

        $graveyard = $this->database->connection('eqemu')
            ->table('graveyard')
            ->where('id', $graveyardId)
            ->where('zone_id', (int) $zone->zoneidnumber)
            ->select(['id', 'zone_id', 'x', 'y', 'z', 'heading'])
            ->first();
        if ($graveyard === null) {
            return null;
        }

        $position = $this->position($graveyard);
        if ($position === null || ! $this->positionWithinMap($position, $map)) {
            return null;
        }

        return [
            'id' => 'navigation-graveyard',
            'source_id' => (int) $graveyard->id,
            'kind' => 'navigation',
            'layers' => ['navigation'],
            'label' => 'Corpse graveyard',
            'subtitle' => 'Zone navigation',
            'position' => $position,
            'heading' => $this->finiteFloat($graveyard->heading ?? null),
            'details' => [['label' => 'Coordinates', 'value' => $this->coordinateLabel($position)]],
            'show_label' => true,
            'url' => null,
        ];
    }

    private function targetZonesById(Collection $ids): Collection
    {
        $ids = $ids->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return $this->accessibleTargetZones()
            ->whereIn('zoneidnumber', $ids->all())
            ->get()
            ->sortBy(fn ($zone) => (int) $zone->version === 0 ? 0 : 1)
            ->unique(fn ($zone) => (int) $zone->zoneidnumber)
            ->keyBy(fn ($zone) => (int) $zone->zoneidnumber);
    }

    private function targetZonesByShortName(Collection $shortNames): Collection
    {
        $shortNames = $shortNames->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->map(fn ($name) => strtolower(trim($name)))->unique()->values();
        if ($shortNames->isEmpty()) {
            return collect();
        }

        return $this->accessibleTargetZones()
            ->whereIn('short_name', $shortNames->all())
            ->get()
            ->sortBy(fn ($zone) => (int) $zone->version === 0 ? 0 : 1)
            ->unique(fn ($zone) => strtolower((string) $zone->short_name))
            ->keyBy(fn ($zone) => strtolower((string) $zone->short_name));
    }

    private function accessibleTargetZones()
    {
        $currentExpansion = (int) $this->config->get('everquest.current_expansion', 0);

        $query = $this->database->connection('eqemu')
            ->table('zone as z')
            ->where('z.min_status', 0)
            ->where('z.expansion', '<=', $currentExpansion)
            ->whereNotIn('z.short_name', $this->ignoredZones())
            ->select(['z.id', 'z.zoneidnumber', 'z.short_name', 'z.long_name', 'z.version']);
        $this->applyContentFilter($query, 'z');

        return $query;
    }

    private function duplicatesZonePoint(
        array $position,
        int $targetZoneId,
        Collection $zonePoints,
        ?array $map,
    ): bool {
        return $zonePoints->contains(function (array $point) use ($position, $targetZoneId, $map) {
            if ((int) ($point['target_zone_id'] ?? 0) !== $targetZoneId) {
                return false;
            }
            $other = $point['position'] ?? null;

            return $this->zonePointOriginIsVisible($other, $map)
                && hypot((float) $other['x'] - $position['x'], (float) $other['y'] - $position['y']) <= 8.0;
        });
    }

    private function zonePointOriginIsVisible(mixed $position, ?array $map): bool
    {
        return is_array($position)
            && $this->hasResolvedOrigin($position)
            && $this->positionWithinMap($position, $map);
    }

    private function hasResolvedOrigin(array $position): bool
    {
        return (float) ($position['x'] ?? 0) !== 0.0
            || (float) ($position['y'] ?? 0) !== 0.0
            || (float) ($position['z'] ?? 0) !== 0.0;
    }

    private function position(object $row): ?array
    {
        $position = [
            'x' => $this->finiteFloat($row->x ?? null),
            'y' => $this->finiteFloat($row->y ?? null),
            'z' => $this->finiteFloat($row->z ?? null),
        ];

        return in_array(null, $position, true) ? null : $position;
    }

    private function targetPosition(object $row): ?array
    {
        $position = [
            'x' => $this->finiteFloat($row->target_x ?? null),
            'y' => $this->finiteFloat($row->target_y ?? null),
            'z' => $this->finiteFloat($row->target_z ?? null),
        ];

        return in_array(null, $position, true) ? null : $position;
    }

    private function positionWithinMap(array $position, ?array $map): bool
    {
        if (abs((float) $position['x']) > 1_000_000 || abs((float) $position['y']) > 1_000_000) {
            return false;
        }
        $bounds = $map['bounds'] ?? null;
        if (! is_array($bounds)) {
            return true;
        }
        $mapX = -(float) $position['x'];
        $mapY = -(float) $position['y'];
        $marginX = max(100.0, ((float) $bounds['max_x'] - (float) $bounds['min_x']) * 0.15);
        $marginY = max(100.0, ((float) $bounds['max_y'] - (float) $bounds['min_y']) * 0.15);

        return $mapX >= (float) $bounds['min_x'] - $marginX
            && $mapX <= (float) $bounds['max_x'] + $marginX
            && $mapY >= (float) $bounds['min_y'] - $marginY
            && $mapY <= (float) $bounds['max_y'] + $marginY;
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }

    private function applyExpansionFilter(Builder $query, string $table): void
    {
        $currentExpansion = (int) $this->config->get('everquest.current_expansion', 0);
        $query
            ->where(function (Builder $scope) use ($table, $currentExpansion) {
                $scope->where("{$table}.min_expansion", -1)
                    ->orWhere("{$table}.min_expansion", '<=', $currentExpansion);
            })
            ->where(function (Builder $scope) use ($table, $currentExpansion) {
                $scope->where("{$table}.max_expansion", -1)
                    ->orWhere("{$table}.max_expansion", '>=', $currentExpansion);
            });
    }

    private function applyContentFilter(Builder $query, string $table): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid content-filter table alias.');
        }

        $flags = $this->activeContentFlags();
        $enabledColumn = "{$table}.content_flags";
        $disabledColumn = "{$table}.content_flags_disabled";
        $query->where(function (Builder $scope) use ($enabledColumn, $flags, $query) {
            $scope->whereNull($enabledColumn)->orWhere($enabledColumn, '');
            if ($flags !== []) {
                $scope->orWhere(function (Builder $matches) use ($enabledColumn, $flags, $query) {
                    $expression = $this->contentFlagExpression($enabledColumn, $query);
                    foreach ($flags as $flag) {
                        $matches->orWhereRaw("{$expression} LIKE ? ESCAPE '!'", [$this->contentFlagPattern($flag)]);
                    }
                });
            }
        });

        if ($flags !== []) {
            $query->where(function (Builder $scope) use ($disabledColumn, $flags, $query) {
                $scope->whereNull($disabledColumn)
                    ->orWhere($disabledColumn, '')
                    ->orWhere(function (Builder $doesNotMatch) use ($disabledColumn, $flags, $query) {
                        $expression = $this->contentFlagExpression($disabledColumn, $query);
                        foreach ($flags as $flag) {
                            $doesNotMatch->whereRaw("{$expression} NOT LIKE ? ESCAPE '!'", [$this->contentFlagPattern($flag)]);
                        }
                    });
            });
        }
    }

    private function contentFlagExpression(string $column, Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'sqlite'
            ? "(',' || REPLACE(COALESCE({$column}, ''), ' ', '') || ',')"
            : "CONCAT(',', REPLACE(COALESCE({$column}, ''), ' ', ''), ',')";
    }

    private function contentFlagPattern(string $flag): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], str_replace(' ', '', $flag));

        return '%,'.$escaped.',%';
    }

    /** @return array<int, string> */
    private function activeContentFlags(): array
    {
        if ($this->enabledContentFlags !== null) {
            return $this->enabledContentFlags;
        }

        try {
            $flags = $this->database->connection('eqemu')
                ->table('content_flags')
                ->where('enabled', 1)
                ->orderBy('flag_name')
                ->pluck('flag_name');
        } catch (QueryException) {
            $flags = collect();
        }

        return $this->enabledContentFlags = $flags
            ->filter(fn ($flag) => is_string($flag))
            ->map(fn (string $flag) => trim($flag))
            ->filter(fn (string $flag) => $flag !== '' && ! str_contains($flag, ','))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function passesContentFlags(mixed $required, mixed $disabled): bool
    {
        $active = $this->activeContentFlags();
        $requiredFlags = $this->splitContentFlags($required);
        $disabledFlags = $this->splitContentFlags($disabled);

        return ($requiredFlags === [] || array_intersect($requiredFlags, $active) !== [])
            && array_intersect($disabledFlags, $active) === [];
    }

    /** @return array<int, string> */
    private function splitContentFlags(mixed $flags): array
    {
        if (! is_string($flags) || trim($flags) === '') {
            return [];
        }

        return collect(explode(',', $flags))
            ->map(fn (string $flag) => trim($flag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function ignoredZones(): array
    {
        return collect($this->config->get('everquest.ignore_zones', []))
            ->filter(fn ($zone) => is_string($zone) && trim($zone) !== '')
            ->map(fn (string $zone) => trim($zone))
            ->unique()
            ->values()
            ->all();
    }

    private function compactNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function coordinateLabel(array $position): string
    {
        $axes = $this->config->get('everquest.coords_as_yxz')
            ? [$position['y'], $position['x'], $position['z']]
            : [$position['x'], $position['y'], $position['z']];

        return implode(', ', array_map(fn ($value) => number_format((float) $value, 2, '.', ''), $axes));
    }

    private function areaLabel(float $minX, float $maxX, float $minY, float $maxY): string
    {
        if ($minX === $maxX && $minY === $maxY) {
            return number_format($maxX, 2, '.', '').', '.number_format($maxY, 2, '.', '');
        }

        return 'X '.number_format($minX, 2, '.', '').'–'.number_format($maxX, 2, '.', '')
            .'; Y '.number_format($minY, 2, '.', '').'–'.number_format($maxY, 2, '.', '');
    }

    private function cleanGroupName(string $name): string
    {
        $name = str_replace('_', ' ', trim($name));

        return $name !== '' ? $name : 'Spawn group';
    }

    private function emptyDataset(Zone $zone, int $version, ?array $map): array
    {
        return [
            'layers' => array_map(fn (array $layer) => $layer + ['count' => 0, 'truncated' => false], $this->layerDefinitions()),
            'groups' => [[
                'key' => (string) $zone->short_name.':'.$version,
                'short_name' => (string) $zone->short_name,
                'long_name' => (string) $zone->long_name,
                'version' => $version,
                'zone_id' => (int) $zone->zoneidnumber,
                'zone_row_id' => (int) $zone->id,
                'map' => $map,
                'paths' => [],
                'locations' => [],
            ]],
            'meta' => ['location_count' => 0, 'truncated_layers' => []],
        ];
    }
}
