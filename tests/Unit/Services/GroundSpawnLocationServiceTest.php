<?php

namespace Tests\Unit\Services;

use App\Services\GroundSpawnLocationService;
use App\Services\ZoneMapCatalog;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GroundSpawnLocationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.eqemu', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('everquest.current_expansion', 9);
        config()->set('everquest.ignore_zones', []);
        config()->set('everquest.npc.display.respawn', true);
        config()->set('everquest.discovered_items.enable', false);

        DB::purge('eqemu');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('eqemu');
        parent::tearDown();
    }

    public function test_inaccessible_zone_ids_do_not_consume_the_location_cap_and_all_versions_remain_grouped_once(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('items')->insert(['id' => 100, 'Name' => 'Public Ground Item']);
        $connection->table('zone')->insert([
            $this->zoneRow(1, 77, 'arena', 'The Arena', 0),
            $this->zoneRow(2, 77, 'arena', 'The Arena', 1),
            $this->zoneRow(3, 1, 'privatezone', 'Private Zone', 0, minStatus: 10),
        ]);

        $hiddenSpawns = [];
        for ($id = 1; $id <= 2000; $id++) {
            $hiddenSpawns[] = $this->groundSpawnRow($id, 1, 100);
        }

        foreach (array_chunk($hiddenSpawns, 100) as $chunk) {
            $connection->table('ground_spawns')->insert($chunk);
        }

        $connection->table('ground_spawns')->insert($this->groundSpawnRow(3000, 77, 100));

        $groups = $this->service()->forItem(100);

        $this->assertCount(1, $groups);
        $this->assertSame('arena:0', $groups[0]['key']);
        $this->assertSame(0, $groups[0]['version']);
        $this->assertCount(1, $groups[0]['locations']);
        $this->assertSame('ground-3000', $groups[0]['locations'][0]['id']);
        $this->assertTrue($groups[0]['locations'][0]['applies_to_all_versions']);
        $this->assertArrayNotHasKey('truncated', $groups[0]);
    }

    public function test_discovery_mode_hides_locations_until_the_item_is_discovered(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('items')->insert(['id' => 101, 'Name' => 'Secret Ground Item']);
        $connection->table('zone')->insert($this->zoneRow(4, 78, 'fieldofbone', 'The Field of Bone', 0));
        $connection->table('ground_spawns')->insert($this->groundSpawnRow(4000, 78, 101));
        config()->set('everquest.discovered_items.enable', true);

        $this->assertSame([], $this->service()->forItem(101));

        $connection->table('discovered_items')->insert(['item_id' => 101]);
        $groups = $this->service()->forItem(101);

        $this->assertCount(1, $groups);
        $this->assertSame('ground-4000', $groups[0]['locations'][0]['id']);
    }

    public function test_content_flags_use_or_semantics_for_zones_and_ground_spawns(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('items')->insert(['id' => 102, 'Name' => 'Flagged Ground Item']);
        $connection->table('content_flags')->insert([
            ['id' => 1, 'flag_name' => 'alpha', 'enabled' => 1],
            ['id' => 2, 'flag_name' => 'beta', 'enabled' => 1],
            ['id' => 3, 'flag_name' => 'dormant', 'enabled' => 0],
        ]);
        $connection->table('zone')->insert([
            $this->zoneRow(5, 79, 'flagged', 'Flagged Zone', 0, contentFlags: 'missing,beta'),
            $this->zoneRow(6, 80, 'requiredmissing', 'Missing Required Flag', 0, contentFlags: 'missing'),
            $this->zoneRow(7, 81, 'disabledactive', 'Active Disabled Flag', 0, contentFlagsDisabled: 'missing,alpha'),
            $this->zoneRow(8, 82, 'disableddormant', 'Dormant Disabled Flag', 0, contentFlagsDisabled: 'dormant'),
        ]);
        $connection->table('ground_spawns')->insert([
            $this->groundSpawnRow(5000, 79, 102, contentFlags: 'missing,alpha'),
            $this->groundSpawnRow(5001, 79, 102, contentFlags: 'missing'),
            $this->groundSpawnRow(5002, 79, 102, contentFlagsDisabled: 'missing,beta'),
            $this->groundSpawnRow(5003, 79, 102, contentFlagsDisabled: 'dormant'),
            $this->groundSpawnRow(5004, 79, 102, contentFlags: ''),
            $this->groundSpawnRow(6000, 80, 102),
            $this->groundSpawnRow(7000, 81, 102),
            $this->groundSpawnRow(8000, 82, 102),
        ]);

        $locationIds = collect($this->service()->forItem(102))
            ->flatMap(fn (array $group) => $group['locations'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['ground-5000', 'ground-5003', 'ground-5004', 'ground-8000'], $locationIds);
    }

    private function service(): GroundSpawnLocationService
    {
        return new GroundSpawnLocationService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(ConfigRepository::class),
            $this->app->make(UrlGenerator::class),
            new ZoneMapCatalog(
                $this->app->make(UrlGenerator::class),
                base_path('tests/fixtures/missing-map-manifest.json'),
                public_path(),
            ),
        );
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('eqemu');
        $schema->create('zone', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('zoneidnumber');
            $table->string('short_name');
            $table->string('long_name');
            $table->integer('version')->default(0);
            $table->integer('expansion')->default(0);
            $table->integer('min_status')->default(0);
            $table->string('map_file_name')->nullable();
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('items', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('Name');
        });
        $schema->create('discovered_items', function (Blueprint $table) {
            $table->integer('item_id');
        });
        $schema->create('content_flags', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('flag_name')->nullable();
            $table->boolean('enabled')->default(false);
        });
        $schema->create('ground_spawns', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('zoneid');
            $table->integer('version')->default(-1);
            $table->float('min_x');
            $table->float('max_x');
            $table->float('min_y');
            $table->float('max_y');
            $table->float('max_z');
            $table->float('heading')->default(0);
            $table->string('name')->nullable();
            $table->integer('item');
            $table->integer('max_allowed')->default(1);
            $table->integer('respawn_timer')->default(0);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
    }

    /** @return array<string, int|string|null> */
    private function zoneRow(
        int $id,
        int $zoneId,
        string $shortName,
        string $longName,
        int $version,
        int $minStatus = 0,
        ?string $contentFlags = null,
        ?string $contentFlagsDisabled = null,
    ): array {
        return [
            'id' => $id,
            'zoneidnumber' => $zoneId,
            'short_name' => $shortName,
            'long_name' => $longName,
            'version' => $version,
            'expansion' => 0,
            'min_status' => $minStatus,
            'map_file_name' => null,
            'content_flags' => $contentFlags,
            'content_flags_disabled' => $contentFlagsDisabled,
        ];
    }

    /** @return array<string, int|string|null> */
    private function groundSpawnRow(
        int $id,
        int $zoneId,
        int $itemId,
        ?string $contentFlags = null,
        ?string $contentFlagsDisabled = null,
    ): array {
        return [
            'id' => $id,
            'zoneid' => $zoneId,
            'version' => -1,
            'min_x' => -10,
            'max_x' => 30,
            'min_y' => 20,
            'max_y' => 40,
            'max_z' => 5,
            'heading' => 0,
            'name' => 'IT63_ACTORDEF',
            'item' => $itemId,
            'max_allowed' => 1,
            'respawn_timer' => 300,
            'min_expansion' => -1,
            'max_expansion' => -1,
            'content_flags' => $contentFlags,
            'content_flags_disabled' => $contentFlagsDisabled,
        ];
    }
}
