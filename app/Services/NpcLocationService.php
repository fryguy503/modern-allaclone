<?php

namespace App\Services;

use App\Models\NpcType;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class NpcLocationService
{
    private const MAX_LOCATIONS = 5000;

    private const MAX_PLACEHOLDER_ROWS = 5000;

    private const MAX_PLACEHOLDERS_PER_GROUP = 25;

    private const MAX_PATH_WAYPOINTS = 10000;

    private const MAX_PATH_GRIDS = 500;

    private const MAX_ZONE_SHORT_NAME_LENGTH = 64;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly UrlGenerator $url,
        private readonly ZoneMapCatalog $maps,
    ) {}

    /**
     * Return spawn locations grouped by exact zone and version. Each group owns
     * paths keyed by path_grid and placeholders keyed by spawn_group_id;
     * locations reference that shared data through scalar identifiers.
     *
     * @return array<int, array{
     *     key: string,
     *     short_name: string,
     *     long_name: string,
     *     version: int,
     *     zone_id: int,
     *     zone_row_id: int,
     *     map: array{
     *         available: bool,
     *         url: string,
     *         segments: int,
     *         points: int,
     *         bounds: array{min_x: float, min_y: float, min_z: float, max_x: float, max_y: float, max_z: float}
     *     }|null,
     *     paths: array<string, array<int, array{x: float, y: float, z: float, pause: int}>>,
     *     placeholders: array<string, array<int, array{id: int, name: string, level: int, chance: float, url: string}>>,
     *     locations: array<int, array{
     *         id: int,
     *         spawn_group_id: int,
     *         spawn_group_name: string,
     *         position: array{x: float, y: float, z: float},
     *         heading: float|null,
     *         chance: float,
     *         respawn_seconds?: int,
     *         variance_seconds?: int,
     *         path_grid: int,
     *         roam: array{min_x: float, max_x: float, min_y: float, max_y: float}|null
     *     }>
     * }>
     */
    public function forNpc(int $npcId): array
    {
        $displayLocations = (bool) $this->config->get('everquest.npc.display.spawn_locs', true);
        if (! $displayLocations) {
            return [];
        }

        $displayRespawn = (bool) $this->config->get('everquest.npc.display.respawn', true);
        $currentExpansion = (int) $this->config->get('everquest.current_expansion', 0);
        $ignoreZones = $this->ignoredZones();

        $select = [
            's2.id as spawn_id',
            's2.spawngroupID as spawn_group_id',
            's2.zone as short_name',
            's2.version as version',
            'sg.name as spawn_group_name',
            'se.chance as chance',
            'z.id as zone_row_id',
            'z.zoneidnumber as zone_id',
            'z.long_name as long_name',
            's2.x as x',
            's2.y as y',
            's2.z as z',
            's2.heading as heading',
            's2.pathgrid as path_grid',
            'sg.min_x as roam_min_x',
            'sg.max_x as roam_max_x',
            'sg.min_y as roam_min_y',
            'sg.max_y as roam_max_y',
        ];

        if ($displayRespawn) {
            array_push($select, 's2.respawntime as respawn_seconds', 's2.variance as variance_seconds');
        }

        $locations = $this->database->connection('eqemu')
            ->table('spawnentry as se')
            ->join('spawn2 as s2', 's2.spawngroupID', '=', 'se.spawngroupID')
            ->join('spawngroup as sg', 'sg.id', '=', 'se.spawngroupID')
            ->join('zone as z', function ($join) {
                $join->on('z.short_name', '=', 's2.zone')
                    ->on('z.version', '=', 's2.version');
            })
            ->where('se.npcID', $npcId)
            ->where('se.chance', '>', 0)
            ->where(function ($query) use ($currentExpansion) {
                $query->where('se.min_expansion', -1)
                    ->orWhere('se.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('se.max_expansion', -1)
                    ->orWhere('se.max_expansion', '>=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('s2.min_expansion', -1)
                    ->orWhere('s2.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('s2.max_expansion', -1)
                    ->orWhere('s2.max_expansion', '>=', $currentExpansion);
            })
            ->where('z.expansion', '<=', $currentExpansion)
            ->where('z.min_status', 0)
            ->when($ignoreZones !== [], fn ($query) => $query->whereNotIn('s2.zone', $ignoreZones))
            ->select($select)
            ->distinct()
            ->orderBy('z.long_name')
            ->orderBy('s2.version')
            ->orderBy('s2.id')
            ->limit(self::MAX_LOCATIONS)
            ->get()
            ->filter(fn ($location) => $this->position($location) !== null)
            ->values();

        if ($locations->isEmpty()) {
            return [];
        }

        $spawnGroupIds = $locations
            ->pluck('spawn_group_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $placeholders = $this->placeholderCandidates($npcId, $spawnGroupIds, $currentExpansion);
        $paths = $this->waypointPaths($locations);

        return $locations
            ->groupBy(fn ($location) => $location->short_name.':'.(int) $location->version)
            ->map(function (Collection $zoneLocations, string $key) use ($displayRespawn, $placeholders, $paths) {
                $first = $zoneLocations->first();

                return [
                    'key' => $key,
                    'short_name' => (string) $first->short_name,
                    'long_name' => (string) $first->long_name,
                    'version' => (int) $first->version,
                    'zone_id' => (int) $first->zone_id,
                    'zone_row_id' => (int) $first->zone_row_id,
                    'map' => $this->maps->find((string) $first->short_name),
                    'paths' => $this->pathsForGroup($zoneLocations, $paths),
                    'placeholders' => $this->placeholdersForGroup($zoneLocations, $placeholders),
                    'locations' => $zoneLocations
                        ->map(fn ($location) => $this->locationDto(
                            $location,
                            $displayRespawn,
                        ))
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function ignoredZones(): array
    {
        return collect($this->config->get('everquest.ignore_zones', []))
            ->filter(fn ($zone) => is_string($zone))
            ->map(fn (string $zone) => trim($zone))
            ->filter(fn (string $zone) => $zone !== ''
                && strlen($zone) <= self::MAX_ZONE_SHORT_NAME_LENGTH
                && preg_match('/[\x00-\x1F\x7F]/', $zone) !== 1)
            ->unique()
            ->values()
            ->all();
    }

    private function placeholderCandidates(int $npcId, array $spawnGroupIds, int $currentExpansion): array
    {
        if ($spawnGroupIds === []) {
            return [];
        }

        $spawnGroupCount = count($spawnGroupIds);
        $perGroupLimit = min(
            self::MAX_PLACEHOLDERS_PER_GROUP,
            max(1, intdiv(self::MAX_PLACEHOLDER_ROWS, $spawnGroupCount)),
        );
        $limit = min(self::MAX_PLACEHOLDER_ROWS, $spawnGroupCount * $perGroupLimit);
        $connection = $this->database->connection('eqemu');

        $deduplicatedCandidates = $connection
            ->table('spawnentry as candidate')
            ->join('npc_types as npc', 'npc.id', '=', 'candidate.npcID')
            ->whereIn('candidate.spawngroupID', $spawnGroupIds)
            ->where('candidate.npcID', '<>', $npcId)
            ->where('candidate.chance', '>', 0)
            ->where(function ($query) use ($currentExpansion) {
                $query->where('candidate.min_expansion', -1)
                    ->orWhere('candidate.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('candidate.max_expansion', -1)
                    ->orWhere('candidate.max_expansion', '>=', $currentExpansion);
            })
            ->select([
                'candidate.spawngroupID as spawn_group_id',
                'npc.id as id',
                'npc.name as name',
                'npc.level as level',
            ])
            ->selectRaw('MAX(candidate.chance) as chance')
            ->groupBy('candidate.spawngroupID', 'npc.id', 'npc.name', 'npc.level');

        $rankedCandidates = $connection
            ->query()
            ->fromSub($deduplicatedCandidates, 'candidates')
            ->select(['spawn_group_id', 'chance', 'id', 'name', 'level'])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY spawn_group_id ORDER BY chance DESC, id ASC) as candidate_rank',
            );

        return $connection
            ->query()
            ->fromSub($rankedCandidates, 'ranked_candidates')
            ->where('candidate_rank', '<=', $perGroupLimit)
            ->select(['spawn_group_id', 'chance', 'id', 'name', 'level'])
            ->orderBy('spawn_group_id')
            ->orderByDesc('chance')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->groupBy(fn ($candidate) => (int) $candidate->spawn_group_id)
            ->map(fn (Collection $candidates) => $candidates
                ->take($perGroupLimit)
                ->map(fn ($candidate) => [
                    'id' => (int) $candidate->id,
                    'name' => NpcType::npcFixName((string) $candidate->name),
                    'level' => (int) $candidate->level,
                    'chance' => (float) $candidate->chance,
                    'url' => $this->url->route('npcs.show', ['npc' => (int) $candidate->id]),
                ])
                ->values()
                ->all())
            ->all();
    }

    private function placeholdersForGroup(Collection $locations, array $placeholders): array
    {
        return $locations
            ->pluck('spawn_group_id')
            ->map(fn ($spawnGroupId) => (int) $spawnGroupId)
            ->unique()
            ->mapWithKeys(fn (int $spawnGroupId) => [
                (string) $spawnGroupId => $placeholders[$spawnGroupId] ?? [],
            ])
            ->filter(fn (array $candidates) => $candidates !== [])
            ->all();
    }

    private function pathsForGroup(Collection $locations, array $paths): array
    {
        $zoneId = (int) $locations->first()->zone_id;

        return $locations
            ->pluck('path_grid')
            ->map(fn ($pathGrid) => (int) $pathGrid)
            ->filter(fn (int $pathGrid) => $pathGrid > 0)
            ->unique()
            ->mapWithKeys(fn (int $pathGrid) => [
                (string) $pathGrid => $paths[$zoneId.':'.$pathGrid] ?? [],
            ])
            ->filter(fn (array $path) => $path !== [])
            ->all();
    }

    private function waypointPaths(Collection $locations): array
    {
        $pairs = $locations
            ->filter(fn ($location) => (int) ($location->path_grid ?? 0) > 0)
            ->map(fn ($location) => [
                'zone_id' => (int) $location->zone_id,
                'grid_id' => (int) $location->path_grid,
            ])
            ->unique(fn ($pair) => $pair['zone_id'].':'.$pair['grid_id'])
            ->take(self::MAX_PATH_GRIDS)
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        try {
            $waypoints = $this->database->connection('eqemu')
                ->table('grid_entries')
                ->where(function ($query) use ($pairs) {
                    foreach ($pairs as $pair) {
                        $query->orWhere(function ($pairQuery) use ($pair) {
                            $pairQuery
                                ->where('zoneid', $pair['zone_id'])
                                ->where('gridid', $pair['grid_id']);
                        });
                    }
                })
                ->select(['zoneid', 'gridid', 'number', 'x', 'y', 'z', 'pause'])
                ->orderBy('zoneid')
                ->orderBy('gridid')
                ->orderBy('number')
                ->limit(self::MAX_PATH_WAYPOINTS)
                ->get();
        } catch (QueryException $exception) {
            if (! $this->isMissingGridEntriesTable($exception)) {
                throw $exception;
            }

            return [];
        }

        $requestedPairs = $pairs
            ->mapWithKeys(fn ($pair) => [$pair['zone_id'].':'.$pair['grid_id'] => true])
            ->all();

        return $waypoints
            ->filter(fn ($waypoint) => isset($requestedPairs[(int) $waypoint->zoneid.':'.(int) $waypoint->gridid]))
            ->groupBy(fn ($waypoint) => (int) $waypoint->zoneid.':'.(int) $waypoint->gridid)
            ->map(fn (Collection $path) => $path->map(fn ($waypoint) => [
                'x' => (float) $waypoint->x,
                'y' => (float) $waypoint->y,
                'z' => (float) $waypoint->z,
                'pause' => (int) $waypoint->pause,
            ])->values()->all())
            ->all();
    }

    private function locationDto(
        object $location,
        bool $displayRespawn,
    ): array {
        $pathGrid = (int) ($location->path_grid ?? 0);

        $dto = [
            'id' => (int) $location->spawn_id,
            'spawn_group_id' => (int) $location->spawn_group_id,
            'spawn_group_name' => (string) $location->spawn_group_name,
            'position' => $this->position($location),
            'heading' => $this->finiteFloat($location->heading ?? null),
            'chance' => (float) $location->chance,
            'path_grid' => $pathGrid,
            'roam' => $this->roamBox($location),
        ];

        if ($displayRespawn) {
            $dto['respawn_seconds'] = max(0, (int) ($location->respawn_seconds ?? 0));
            $dto['variance_seconds'] = max(0, (int) ($location->variance_seconds ?? 0));
        }

        return $dto;
    }

    private function position(object $location): ?array
    {
        $x = $this->finiteFloat($location->x ?? null);
        $y = $this->finiteFloat($location->y ?? null);
        $z = $this->finiteFloat($location->z ?? null);

        if ($x === null || $y === null || $z === null) {
            return null;
        }

        return compact('x', 'y', 'z');
    }

    private function roamBox(object $location): ?array
    {
        $minX = $this->finiteFloat($location->roam_min_x ?? null);
        $maxX = $this->finiteFloat($location->roam_max_x ?? null);
        $minY = $this->finiteFloat($location->roam_min_y ?? null);
        $maxY = $this->finiteFloat($location->roam_max_y ?? null);

        if ($minX === null || $maxX === null || $minY === null || $maxY === null
            || ($minX === 0.0 && $maxX === 0.0 && $minY === 0.0 && $maxY === 0.0)
            || $minX > $maxX || $minY > $maxY) {
            return null;
        }

        return [
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
        ];
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
    }

    private function isMissingGridEntriesTable(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return str_contains($message, 'grid_entries')
            && (in_array($sqlState, ['42S02', '42P01'], true)
                || $driverCode === 1146
                || str_contains($message, 'no such table')
                || str_contains($message, 'does not exist'));
    }
}
