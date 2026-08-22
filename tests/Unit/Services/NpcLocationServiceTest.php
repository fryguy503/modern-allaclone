<?php

namespace Tests\Unit\Services;

use App\Services\NpcLocationService;
use App\Services\ZoneMapCatalog;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NpcLocationServiceTest extends TestCase
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
        config()->set('everquest.ignore_zones', ['cshome']);
        config()->set('everquest.npc.display.spawn_locs', true);
        config()->set('everquest.npc.display.respawn', true);

        DB::purge('eqemu');
        $this->createSchema();
        $this->seedLocations();
    }

    protected function tearDown(): void
    {
        DB::purge('eqemu');

        parent::tearDown();
    }

    public function test_it_returns_grouped_scalar_locations_with_placeholders_and_paths_in_three_queries(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('spawn2')->insert([
            'id' => 504,
            'spawngroupID' => 10,
            'zone' => 'qeynos',
            'version' => 0,
            'x' => -6400,
            'y' => 1000,
            'z' => 30,
            'heading' => 64,
            'respawntime' => 720,
            'variance' => 60,
            'pathgrid' => 7,
        ]);
        $connection->enableQueryLog();

        $groups = $this->service()->forNpc(69093);

        $this->assertCount(3, $connection->getQueryLog());
        $this->assertCount(1, $groups);
        $this->assertSame('qeynos:0', $groups[0]['key']);
        $this->assertSame('qeynos', $groups[0]['short_name']);
        $this->assertSame('South Qeynos', $groups[0]['long_name']);
        $this->assertSame(2, $groups[0]['zone_id']);
        $this->assertSame(100, $groups[0]['zone_row_id']);
        $this->assertNull($groups[0]['map']);
        $this->assertCount(2, $groups[0]['locations']);
        $this->assertCount(1, $groups[0]['paths']);
        $this->assertSame([
            ['x' => -6400.0, 'y' => 1000.0, 'z' => 30.0, 'pause' => 5],
            ['x' => -6300.0, 'y' => 1100.0, 'z' => 31.0, 'pause' => 0],
        ], $groups[0]['paths'][7]);
        $this->assertStringStartsWith('{"7":', json_encode($groups[0]['paths'], JSON_THROW_ON_ERROR));
        $this->assertCount(1, $groups[0]['placeholders']);
        $this->assertStringStartsWith('{"10":', json_encode($groups[0]['placeholders'], JSON_THROW_ON_ERROR));
        $this->assertSame(69094, $groups[0]['placeholders'][10][0]['id']);
        $this->assertSame('a placeholder', $groups[0]['placeholders'][10][0]['name']);
        $this->assertSame(20.5, $groups[0]['placeholders'][10][0]['chance']);
        $this->assertStringEndsWith('/npcs/69094', $groups[0]['placeholders'][10][0]['url']);

        $location = $groups[0]['locations'][0];
        $this->assertSame(500, $location['id']);
        $this->assertSame(10, $location['spawn_group_id']);
        $this->assertSame('npc_69093_group', $location['spawn_group_name']);
        $this->assertSame(['x' => -6441.0, 'y' => 1025.0, 'z' => 30.4], $location['position']);
        $this->assertSame(128.0, $location['heading']);
        $this->assertSame(35.25, $location['chance']);
        $this->assertSame(720, $location['respawn_seconds']);
        $this->assertSame(60, $location['variance_seconds']);
        $this->assertSame(7, $location['path_grid']);
        $this->assertArrayNotHasKey('path', $location);
        $this->assertArrayNotHasKey('path', $groups[0]['locations'][1]);
        $this->assertSame([
            'min_x' => -6500.0,
            'max_x' => -6200.0,
            'min_y' => 900.0,
            'max_y' => 1200.0,
        ], $location['roam']);
        $this->assertArrayNotHasKey('placeholders', $location);
        $this->assertArrayNotHasKey('placeholders', $groups[0]['locations'][1]);
    }

    public function test_hidden_locations_return_no_groups_without_querying_eqemu(): void
    {
        config()->set('everquest.npc.display.spawn_locs', false);
        config()->set('everquest.npc.display.respawn', false);

        $connection = DB::connection('eqemu');
        $connection->enableQueryLog();

        $groups = $this->service()->forNpc(69093);

        $this->assertSame([], $groups);
        $this->assertCount(0, $connection->getQueryLog());
    }

    public function test_placeholder_limit_is_fair_per_spawn_group_and_deduplicates_candidates(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('spawngroup')->insert([
            'id' => 11,
            'name' => 'second_placeholder_group',
            'min_x' => 0,
            'max_x' => 0,
            'min_y' => 0,
            'max_y' => 0,
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 11,
            'npcID' => 69093,
            'chance' => 100,
        ]);
        $connection->table('spawn2')->insert([
            'id' => 509,
            'spawngroupID' => 11,
            'zone' => 'qeynos',
            'version' => 0,
            'x' => -6000,
            'y' => 1000,
            'z' => 30,
            'heading' => 0,
            'respawntime' => 1,
            'variance' => 0,
            'pathgrid' => 0,
        ]);

        $candidateEntries = [];
        $candidateNpcs = [];
        for ($index = 0; $index < 50; $index++) {
            $candidateId = 71000 + $index;
            $candidateEntries[] = [
                'spawngroupID' => 10,
                'npcID' => $candidateId,
                'chance' => 100 - ($index / 100),
                'min_expansion' => -1,
                'max_expansion' => -1,
            ];
            $candidateNpcs[] = [
                'id' => $candidateId,
                'name' => 'candidate_'.$candidateId,
                'level' => 12,
            ];
        }

        $candidateEntries[] = [
            'spawngroupID' => 10,
            'npcID' => 71000,
            'chance' => 101,
            'min_expansion' => -1,
            'max_expansion' => -1,
        ];
        $candidateEntries[] = [
            'spawngroupID' => 11,
            'npcID' => 72000,
            'chance' => 50,
            'min_expansion' => -1,
            'max_expansion' => -1,
        ];
        $candidateNpcs[] = ['id' => 72000, 'name' => 'second_group_candidate', 'level' => 12];

        $connection->table('spawnentry')->insert($candidateEntries);
        $connection->table('npc_types')->insert($candidateNpcs);

        $groups = $this->service()->forNpc(69093);
        $firstGroupIds = array_column($groups[0]['placeholders'][10], 'id');

        $this->assertCount(25, $groups[0]['placeholders'][10]);
        $this->assertSame(1, count(array_keys($firstGroupIds, 71000, true)));
        $this->assertSame(101.0, $groups[0]['placeholders'][10][0]['chance']);
        $this->assertSame([72000], array_column($groups[0]['placeholders'][11], 'id'));
    }

    public function test_hidden_respawn_values_are_neither_selected_nor_returned(): void
    {
        config()->set('everquest.npc.display.respawn', false);

        $connection = DB::connection('eqemu');
        $connection->enableQueryLog();

        $groups = $this->service()->forNpc(69093);
        $location = $groups[0]['locations'][0];
        $locationSql = $connection->getQueryLog()[0]['query'];

        $this->assertStringNotContainsString('respawntime', $locationSql);
        $this->assertStringNotContainsString('variance', $locationSql);
        $this->assertArrayNotHasKey('respawn_seconds', $location);
        $this->assertArrayNotHasKey('variance_seconds', $location);
    }

    public function test_trimmed_custom_zone_names_are_honored_by_the_ignore_filter(): void
    {
        $overlongZone = str_repeat('x', 65);
        config()->set('everquest.ignore_zones', [
            ' cshome ',
            ' CUSTOM_ZONE ',
            '',
            $overlongZone,
            "bad\nzone",
            123,
        ]);

        $connection = DB::connection('eqemu');
        $connection->table('zone')->insert([
            'id' => 103,
            'zoneidnumber' => 1000,
            'short_name' => 'CUSTOM_ZONE',
            'long_name' => 'Custom Zone',
            'version' => 0,
            'expansion' => 0,
        ]);
        $connection->table('spawngroup')->insert([
            'id' => 60,
            'name' => 'custom_zone_group',
            'min_x' => 0,
            'max_x' => 0,
            'min_y' => 0,
            'max_y' => 0,
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 60,
            'npcID' => 69093,
            'chance' => 100,
        ]);
        $connection->table('spawn2')->insert([
            'id' => 505,
            'spawngroupID' => 60,
            'zone' => 'CUSTOM_ZONE',
            'version' => 0,
            'x' => 1,
            'y' => 2,
            'z' => 3,
            'heading' => 0,
            'respawntime' => 1,
            'variance' => 0,
            'pathgrid' => 0,
        ]);

        $connection->enableQueryLog();
        $groups = $this->service()->forNpc(69093);
        $bindings = $connection->getQueryLog()[0]['bindings'];

        $this->assertCount(1, $groups);
        $this->assertSame('qeynos', $groups[0]['short_name']);
        $this->assertContains('cshome', $bindings);
        $this->assertContains('CUSTOM_ZONE', $bindings);
        $this->assertNotContains($overlongZone, $bindings);
        $this->assertNotContains("bad\nzone", $bindings);
    }

    public function test_expansion_gated_spawns_and_placeholder_candidates_are_excluded(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('spawngroup')->insert([
            ['id' => 70, 'name' => 'future_entry_group', 'min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0],
            ['id' => 80, 'name' => 'expired_spawn_group', 'min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0],
        ]);
        $connection->table('spawnentry')->insert([
            ['spawngroupID' => 70, 'npcID' => 69093, 'chance' => 100, 'min_expansion' => 10, 'max_expansion' => -1],
            ['spawngroupID' => 80, 'npcID' => 69093, 'chance' => 100, 'min_expansion' => -1, 'max_expansion' => -1],
            ['spawngroupID' => 10, 'npcID' => 69095, 'chance' => 99, 'min_expansion' => 10, 'max_expansion' => -1],
            ['spawngroupID' => 10, 'npcID' => 69096, 'chance' => 98, 'min_expansion' => -1, 'max_expansion' => 8],
        ]);
        $connection->table('spawn2')->insert([
            [
                'id' => 506,
                'spawngroupID' => 70,
                'zone' => 'qeynos',
                'version' => 0,
                'x' => 1,
                'y' => 2,
                'z' => 3,
                'heading' => 0,
                'respawntime' => 1,
                'variance' => 0,
                'pathgrid' => 0,
                'min_expansion' => -1,
                'max_expansion' => -1,
            ],
            [
                'id' => 507,
                'spawngroupID' => 80,
                'zone' => 'qeynos',
                'version' => 0,
                'x' => 1,
                'y' => 2,
                'z' => 3,
                'heading' => 0,
                'respawntime' => 1,
                'variance' => 0,
                'pathgrid' => 0,
                'min_expansion' => -1,
                'max_expansion' => 8,
            ],
        ]);
        $connection->table('npc_types')->insert([
            ['id' => 69095, 'name' => 'future_placeholder', 'level' => 12],
            ['id' => 69096, 'name' => 'expired_placeholder', 'level' => 12],
        ]);

        $groups = $this->service()->forNpc(69093);

        $this->assertCount(1, $groups);
        $this->assertCount(1, $groups[0]['locations']);
        $this->assertSame([69094], array_column($groups[0]['placeholders'][10], 'id'));
    }

    public function test_status_gated_zones_do_not_expose_locations(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('zone')->insert([
            'id' => 104,
            'zoneidnumber' => 1001,
            'short_name' => 'gm_zone',
            'long_name' => 'GM Zone',
            'version' => 0,
            'expansion' => 0,
            'min_status' => 100,
        ]);
        $connection->table('spawngroup')->insert([
            'id' => 90,
            'name' => 'gm_zone_group',
            'min_x' => 0,
            'max_x' => 0,
            'min_y' => 0,
            'max_y' => 0,
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 90,
            'npcID' => 69093,
            'chance' => 100,
        ]);
        $connection->table('spawn2')->insert([
            'id' => 508,
            'spawngroupID' => 90,
            'zone' => 'gm_zone',
            'version' => 0,
            'x' => 1,
            'y' => 2,
            'z' => 3,
            'heading' => 0,
            'respawntime' => 1,
            'variance' => 0,
            'pathgrid' => 0,
        ]);

        $groups = $this->service()->forNpc(69093);

        $this->assertCount(1, $groups);
        $this->assertSame('qeynos', $groups[0]['short_name']);
    }

    public function test_locations_with_invalid_coordinates_are_dropped_before_grouping(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('spawngroup')->insert([
            'id' => 50,
            'name' => 'invalid_coordinate_group',
            'min_x' => 0,
            'max_x' => 0,
            'min_y' => 0,
            'max_y' => 0,
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 50,
            'npcID' => 70000,
            'chance' => 100,
        ]);
        $connection->table('spawn2')->insert([
            'id' => 504,
            'spawngroupID' => 50,
            'zone' => 'qeynos',
            'version' => 0,
            'x' => 'not-a-coordinate',
            'y' => 0,
            'z' => 0,
            'heading' => 0,
            'respawntime' => 1,
            'variance' => 0,
            'pathgrid' => 0,
        ]);
        $connection->enableQueryLog();

        $groups = $this->service()->forNpc(70000);

        $this->assertSame([], $groups);
        $this->assertCount(1, $connection->getQueryLog());
    }

    public function test_it_omits_paths_when_the_optional_grid_entries_table_is_unavailable(): void
    {
        Schema::connection('eqemu')->drop('grid_entries');

        $groups = $this->service()->forNpc(69093);

        $this->assertSame(7, $groups[0]['locations'][0]['path_grid']);
        $this->assertSame([], $groups[0]['paths']);
        $this->assertArrayNotHasKey('path', $groups[0]['locations'][0]);
    }

    private function service(): NpcLocationService
    {
        $url = $this->app->make(UrlGenerator::class);
        $missingManifest = storage_path(
            'framework/testing/missing-map-manifest-'.bin2hex(random_bytes(6)).'.json',
        );

        return new NpcLocationService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(ConfigRepository::class),
            $url,
            new ZoneMapCatalog($url, $missingManifest, public_path()),
        );
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('eqemu');

        $schema->create('spawnentry', function (Blueprint $table) {
            $table->integer('spawngroupID');
            $table->integer('npcID');
            $table->float('chance');
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
        });

        $schema->create('spawn2', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('spawngroupID');
            $table->string('zone');
            $table->integer('version');
            $table->float('x');
            $table->float('y');
            $table->float('z');
            $table->float('heading');
            $table->integer('respawntime');
            $table->integer('variance');
            $table->integer('pathgrid');
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
        });

        $schema->create('spawngroup', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
            $table->float('min_x');
            $table->float('max_x');
            $table->float('min_y');
            $table->float('max_y');
        });

        $schema->create('zone', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('zoneidnumber');
            $table->string('short_name');
            $table->string('long_name');
            $table->integer('version');
            $table->integer('expansion');
            $table->integer('min_status')->default(0);
        });

        $schema->create('npc_types', function (Blueprint $table) {
            $table->integer('id');
            $table->string('name');
            $table->integer('level');
        });

        $schema->create('grid_entries', function (Blueprint $table) {
            $table->integer('gridid');
            $table->integer('zoneid');
            $table->integer('number');
            $table->float('x');
            $table->float('y');
            $table->float('z');
            $table->integer('pause');
        });
    }

    private function seedLocations(): void
    {
        $connection = DB::connection('eqemu');

        $connection->table('zone')->insert([
            ['id' => 100, 'zoneidnumber' => 2, 'short_name' => 'qeynos', 'long_name' => 'South Qeynos', 'version' => 0, 'expansion' => 0],
            ['id' => 101, 'zoneidnumber' => 26, 'short_name' => 'cshome', 'long_name' => 'Sunset Home', 'version' => 0, 'expansion' => 0],
            ['id' => 102, 'zoneidnumber' => 999, 'short_name' => 'futurezone', 'long_name' => 'Future Zone', 'version' => 0, 'expansion' => 10],
        ]);

        $connection->table('spawngroup')->insert([
            ['id' => 10, 'name' => 'npc_69093_group', 'min_x' => -6500, 'max_x' => -6200, 'min_y' => 900, 'max_y' => 1200],
            ['id' => 20, 'name' => 'ignored_group', 'min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0],
            ['id' => 30, 'name' => 'future_group', 'min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0],
            ['id' => 40, 'name' => 'version_mismatch_group', 'min_x' => 0, 'max_x' => 0, 'min_y' => 0, 'max_y' => 0],
        ]);

        $connection->table('spawnentry')->insert([
            ['spawngroupID' => 10, 'npcID' => 69093, 'chance' => 35.25],
            ['spawngroupID' => 10, 'npcID' => 69094, 'chance' => 20.5],
            ['spawngroupID' => 20, 'npcID' => 69093, 'chance' => 100],
            ['spawngroupID' => 30, 'npcID' => 69093, 'chance' => 100],
            ['spawngroupID' => 40, 'npcID' => 69093, 'chance' => 100],
        ]);

        $connection->table('spawn2')->insert([
            ['id' => 500, 'spawngroupID' => 10, 'zone' => 'qeynos', 'version' => 0, 'x' => -6441, 'y' => 1025, 'z' => 30.4, 'heading' => 128, 'respawntime' => 720, 'variance' => 60, 'pathgrid' => 7],
            ['id' => 501, 'spawngroupID' => 20, 'zone' => 'cshome', 'version' => 0, 'x' => 1, 'y' => 2, 'z' => 3, 'heading' => 0, 'respawntime' => 1, 'variance' => 0, 'pathgrid' => 0],
            ['id' => 502, 'spawngroupID' => 30, 'zone' => 'futurezone', 'version' => 0, 'x' => 1, 'y' => 2, 'z' => 3, 'heading' => 0, 'respawntime' => 1, 'variance' => 0, 'pathgrid' => 0],
            ['id' => 503, 'spawngroupID' => 40, 'zone' => 'qeynos', 'version' => 9, 'x' => 1, 'y' => 2, 'z' => 3, 'heading' => 0, 'respawntime' => 1, 'variance' => 0, 'pathgrid' => 0],
        ]);

        $connection->table('npc_types')->insert([
            ['id' => 69094, 'name' => 'a_placeholder', 'level' => 12],
        ]);

        $connection->table('grid_entries')->insert([
            ['gridid' => 7, 'zoneid' => 2, 'number' => 1, 'x' => -6400, 'y' => 1000, 'z' => 30, 'pause' => 5],
            ['gridid' => 7, 'zoneid' => 2, 'number' => 2, 'x' => -6300, 'y' => 1100, 'z' => 31, 'pause' => 0],
            ['gridid' => 7, 'zoneid' => 999, 'number' => 1, 'x' => 999, 'y' => 999, 'z' => 999, 'pause' => 0],
        ]);
    }
}
