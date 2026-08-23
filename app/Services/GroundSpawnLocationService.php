<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class GroundSpawnLocationService
{
    private const MAX_ITEM_LOCATIONS = 2000;

    private const MAX_ZONE_SHORT_NAME_LENGTH = 64;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly UrlGenerator $url,
        private readonly ZoneMapCatalog $maps,
    ) {}

    /**
     * Return an item's ground spawns grouped by an accessible, deterministic
     * zone version. A ground spawn is an area, not an exact point: EQEmu stores
     * a minimum and maximum X/Y and chooses a position inside that rectangle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId): array
    {
        $currentExpansion = (int) $this->config->get('everquest.current_expansion', 0);
        $connection = $this->database->connection('eqemu');
        $ignoredZones = $this->ignoredZones();
        $driver = $connection->getDriverName();
        $enabledContentFlags = $connection
            ->table('content_flags')
            ->where('enabled', 1)
            ->whereNotNull('flag_name')
            ->pluck('flag_name')
            ->map(fn ($flag) => is_string($flag) ? trim($flag) : '')
            ->filter(fn (string $flag) => $flag !== '' && ! str_contains($flag, ','))
            ->unique()
            ->values()
            ->all();

        if ((bool) $this->config->get('everquest.discovered_items.enable', false)
            && ! $connection->table('discovered_items')->where('item_id', $itemId)->exists()) {
            return [];
        }

        $spawnsQuery = $connection
            ->table('ground_spawns as gs')
            ->join('items as item', 'item.id', '=', 'gs.item')
            ->where('gs.item', $itemId)
            ->whereIn('gs.zoneid', function ($query) use (
                $currentExpansion,
                $driver,
                $enabledContentFlags,
                $ignoredZones,
            ) {
                $query->from('zone as accessible_zone')
                    ->select('accessible_zone.zoneidnumber')
                    ->where('accessible_zone.expansion', '<=', $currentExpansion)
                    ->where('accessible_zone.min_status', 0)
                    ->when(
                        $ignoredZones !== [],
                        fn ($query) => $query->whereNotIn('accessible_zone.short_name', $ignoredZones),
                    )
                    ->distinct();

                $this->applyContentFlagFilters(
                    $query,
                    'accessible_zone',
                    $enabledContentFlags,
                    $driver,
                );
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('gs.min_expansion', -1)
                    ->orWhere('gs.min_expansion', '<=', $currentExpansion);
            })
            ->where(function ($query) use ($currentExpansion) {
                $query->where('gs.max_expansion', -1)
                    ->orWhere('gs.max_expansion', '>=', $currentExpansion);
            });

        $this->applyContentFlagFilters($spawnsQuery, 'gs', $enabledContentFlags, $driver);

        $spawns = $spawnsQuery
            ->select([
                'gs.id',
                'gs.zoneid',
                'gs.version',
                'gs.min_x',
                'gs.max_x',
                'gs.min_y',
                'gs.max_y',
                'gs.max_z',
                'gs.heading',
                'gs.name',
                'gs.max_allowed',
                'gs.respawn_timer',
                'item.Name as item_name',
            ])
            ->orderBy('gs.zoneid')
            ->orderBy('gs.version')
            ->orderBy('gs.id')
            ->limit(self::MAX_ITEM_LOCATIONS)
            ->get();

        if ($spawns->isEmpty()) {
            return [];
        }

        $zoneIds = $spawns->pluck('zoneid')->map(fn ($id) => (int) $id)->unique()->values();

        $zoneRowsQuery = $connection
            ->table('zone as z')
            ->whereIn('z.zoneidnumber', $zoneIds->all())
            ->where('z.expansion', '<=', $currentExpansion)
            ->where('z.min_status', 0)
            ->when($ignoredZones !== [], fn ($query) => $query->whereNotIn('z.short_name', $ignoredZones));

        $this->applyContentFlagFilters($zoneRowsQuery, 'z', $enabledContentFlags, $driver);

        $zoneRows = $zoneRowsQuery
            ->select([
                'z.id',
                'z.zoneidnumber',
                'z.short_name',
                'z.long_name',
                'z.version',
                'z.map_file_name',
            ])
            ->orderBy('z.zoneidnumber')
            ->orderBy('z.version')
            ->get()
            ->groupBy(fn ($zone) => (int) $zone->zoneidnumber);

        return $spawns
            ->map(function ($spawn) use ($zoneRows) {
                $zone = $this->zoneForSpawn($zoneRows->get((int) $spawn->zoneid, collect()), (int) $spawn->version);
                if ($zone === null) {
                    return null;
                }

                $location = $this->locationDto($spawn, $zone);

                return $location === null ? null : ['zone' => $zone, 'location' => $location];
            })
            ->filter()
            ->groupBy(fn (array $entry) => $entry['zone']->short_name.':'.(int) $entry['zone']->version)
            ->map(function (Collection $entries, string $key) {
                $zone = $entries->first()['zone'];
                $shortName = (string) $zone->short_name;
                $mapFileName = trim((string) ($zone->map_file_name ?? ''));

                return [
                    'key' => $key,
                    'short_name' => $shortName,
                    'long_name' => (string) $zone->long_name,
                    'version' => (int) $zone->version,
                    'zone_id' => (int) $zone->zoneidnumber,
                    'zone_row_id' => (int) $zone->id,
                    'map' => ($mapFileName !== '' ? $this->maps->find($mapFileName) : null)
                        ?? $this->maps->find($shortName),
                    'paths' => [],
                    'locations' => $entries->pluck('location')->values()->all(),
                ];
            })
            ->sortBy('long_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Apply EQEmu's comma-delimited content flag rules. Required flags use OR
     * semantics; a row is disabled when any enabled disabled-flag is present.
     *
     * @param  array<int, string>  $enabledFlags
     */
    private function applyContentFlagFilters(
        Builder $query,
        string $tableAlias,
        array $enabledFlags,
        string $driver,
    ): void {
        $requiredColumn = $tableAlias.'.content_flags';
        $disabledColumn = $tableAlias.'.content_flags_disabled';

        $query->where(function (Builder $query) use ($requiredColumn, $enabledFlags, $driver) {
            $query->whereNull($requiredColumn)
                ->orWhere($requiredColumn, '');

            foreach ($enabledFlags as $flag) {
                $query->orWhereRaw($this->contentFlagMatchSql($requiredColumn, $driver), [$flag]);
            }
        });

        if ($enabledFlags === []) {
            return;
        }

        $query->where(function (Builder $query) use ($disabledColumn, $enabledFlags, $driver) {
            $query->whereNull($disabledColumn)
                ->orWhere(function (Builder $query) use ($disabledColumn, $enabledFlags, $driver) {
                    foreach ($enabledFlags as $flag) {
                        $query->whereRaw('NOT ('.$this->contentFlagMatchSql($disabledColumn, $driver).')', [$flag]);
                    }
                });
        });
    }

    private function contentFlagMatchSql(string $column, string $driver): string
    {
        if ($driver === 'sqlite') {
            return "instr(',' || COALESCE({$column}, '') || ',', ',' || ? || ',') > 0";
        }

        return "FIND_IN_SET(?, {$column}) > 0";
    }

    private function zoneForSpawn(Collection $zones, int $spawnVersion): ?object
    {
        if ($zones->isEmpty()) {
            return null;
        }

        if ($spawnVersion >= 0) {
            return $zones->first(fn ($zone) => (int) $zone->version === $spawnVersion);
        }

        return $zones->first(fn ($zone) => (int) $zone->version === 0) ?? $zones->first();
    }

    private function locationDto(object $spawn, object $zone): ?array
    {
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
        $spawnId = (int) $spawn->id;
        $version = (int) $spawn->version;
        $respawn = max(0, (int) ($spawn->respawn_timer ?? 0));
        $maxAllowed = max(0, (int) ($spawn->max_allowed ?? 0));
        $details = [
            ['label' => 'Spawn area', 'value' => $this->areaLabel($minX, $maxX, $minY, $maxY)],
        ];

        if ($maxAllowed > 0) {
            $details[] = ['label' => 'Maximum active', 'value' => (string) $maxAllowed];
        }

        if ((bool) $this->config->get('everquest.npc.display.respawn', true) && $respawn > 0) {
            $details[] = ['label' => 'Respawn', 'value' => $respawn.' seconds'];
        }

        $zoneUrl = $this->url->route('zones.show', ['zone' => (int) $zone->id]);
        $query = ['layers' => 'ground-spawns', 'pin' => 'ground-'.$spawnId];
        if ((int) $zone->version !== 0) {
            $query = ['v' => (int) $zone->version] + $query;
        }

        return [
            'id' => 'ground-'.$spawnId,
            'source_id' => $spawnId,
            'kind' => 'ground-spawns',
            'layers' => ['ground-spawns'],
            'label' => trim((string) ($spawn->item_name ?? '')) ?: 'Ground spawn',
            'subtitle' => 'Ground spawn · '.($version === -1 ? 'all zone versions' : 'zone version '.$version),
            'position' => [
                'x' => ($minX + $maxX) / 2,
                'y' => ($minY + $maxY) / 2,
                'z' => $z,
            ],
            'area' => [
                'min_x' => $minX,
                'max_x' => $maxX,
                'min_y' => $minY,
                'max_y' => $maxY,
            ],
            'heading' => $this->finiteFloat($spawn->heading ?? null),
            'details' => $details,
            'url' => $zoneUrl.'?'.http_build_query($query),
            'applies_to_all_versions' => $version === -1,
            'coordinates' => [
                'min_x' => $minX,
                'max_x' => $maxX,
                'min_y' => $minY,
                'max_y' => $maxY,
                'z' => $z,
            ],
        ];
    }

    private function areaLabel(float $minX, float $maxX, float $minY, float $maxY): string
    {
        if ($minX === $maxX && $minY === $maxY) {
            return number_format($maxX, 2, '.', '').', '.number_format($maxY, 2, '.', '');
        }

        return 'X '.number_format($minX, 2, '.', '').'–'.number_format($maxX, 2, '.', '')
            .'; Y '.number_format($minY, 2, '.', '').'–'.number_format($maxY, 2, '.', '');
    }

    private function finiteFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return is_finite($value) ? $value : null;
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
}
