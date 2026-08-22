<?php

namespace Tests\Unit\Services;

use App\Models\Zone;
use App\Services\GroundSpawnLocationService;
use App\Services\ZoneAtlasService;
use App\Services\ZoneMapCatalog;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ZoneAtlasServiceTest extends TestCase
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
        config()->set('everquest.npc.display.spawn_locs', true);
        config()->set('everquest.npc.display.respawn', true);
        config()->set('everquest.discovered_items.enable', false);

        DB::purge('eqemu');
        $this->createSchema();
        $this->seedData();
    }

    protected function tearDown(): void
    {
        DB::purge('eqemu');
        parent::tearDown();
    }

    public function test_zone_atlas_is_exact_versioned_and_keeps_overlapping_traits_areas_and_exits(): void
    {
        $zone = Zone::findOrFail(1);
        $dataset = $this->zoneService()->forZone($zone, 0);
        $locations = collect($dataset['groups'][0]['locations'])->keyBy('id');

        $this->assertTrue($locations->has('npc-100'));
        $this->assertFalse($locations->has('npc-101'));
        $this->assertSame(['named', 'merchants'], $locations['npc-100']['layers']);
        $this->assertSame('a named merchant', $locations['npc-100']['candidates'][0]['name']);
        $this->assertSame(25.0, $locations['npc-100']['candidates'][0]['chance']);

        $this->assertSame(
            ['min_x' => -10.0, 'max_x' => 30.0, 'min_y' => 20.0, 'max_y' => 40.0],
            $locations['ground-300']['area'],
        );
        $this->assertSame(['x' => 10.0, 'y' => 30.0, 'z' => 5.0], $locations['ground-300']['position']);

        $this->assertTrue($locations->has('zone-point-400'));
        $this->assertTrue($locations->has('zone-point-401'));
        $this->assertSame('To Lower Guk', $locations['zone-point-400']['label']);
        $this->assertTrue($locations->has('navigation-safe'));

        $layers = collect($dataset['layers'])->keyBy('id');
        $this->assertSame(1, $layers['named']['count']);
        $this->assertSame(1, $layers['merchants']['count']);
        $this->assertSame(1, $layers['ground-spawns']['count']);
        $this->assertSame(2, $layers['zone-points']['count']);
    }

    public function test_item_ground_spawn_service_retains_all_version_semantics_and_full_rectangle(): void
    {
        $groups = $this->groundService()->forItem(234020);

        $this->assertCount(1, $groups);
        $this->assertSame('arena:0', $groups[0]['key']);
        $this->assertSame(1, $groups[0]['zone_row_id']);
        $this->assertCount(1, $groups[0]['locations']);
        $location = $groups[0]['locations'][0];
        $this->assertSame('ground-300', $location['id']);
        $this->assertTrue($location['applies_to_all_versions']);
        $this->assertSame(
            ['min_x' => -10.0, 'max_x' => 30.0, 'min_y' => 20.0, 'max_y' => 40.0, 'z' => 5.0],
            $location['coordinates'],
        );
        $this->assertStringContainsString('layers=ground-spawns', $location['url']);
        $this->assertStringContainsString('pin=ground-300', $location['url']);
    }

    public function test_spawn_location_privacy_removes_every_npc_pin_without_hiding_public_navigation(): void
    {
        config()->set('everquest.npc.display.spawn_locs', false);
        $dataset = $this->zoneService()->forZone(Zone::findOrFail(1), 0);
        $locations = collect($dataset['groups'][0]['locations']);

        $this->assertFalse($locations->contains(fn (array $location) => str_starts_with($location['id'], 'npc-')));
        $this->assertTrue($locations->contains(fn (array $location) => $location['id'] === 'ground-300'));
        $this->assertTrue($locations->contains(fn (array $location) => $location['id'] === 'zone-point-400'));
    }

    public function test_atlas_applies_content_flags_and_resolves_type_57_portals_without_none_sentinels(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('content_flags')->insert([
            'id' => 1, 'flag_name' => 'seasonal', 'enabled' => 1,
        ]);
        $connection->table('spawngroup')->insert(['id' => 11, 'name' => 'flagged_group']);
        $connection->table('npc_types')->insert([
            'id' => 201, 'name' => 'a_flagged_guard', 'race' => 1, 'level' => 10, 'maxlevel' => 10,
            'merchant_id' => 0, 'rare_spawn' => 0, 'raid_target' => 0, 'isquest' => 0,
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 11, 'npcID' => 201, 'chance' => 100,
        ]);
        $connection->table('spawn2')->insert([
            [
                'id' => 102, 'spawngroupID' => 11, 'zone' => 'arena', 'version' => 0,
                'x' => 30, 'y' => 30, 'z' => 0, 'content_flags' => 'missing',
                'content_flags_disabled' => null,
            ],
            [
                'id' => 103, 'spawngroupID' => 11, 'zone' => 'arena', 'version' => 0,
                'x' => 40, 'y' => 40, 'z' => 0, 'content_flags' => 'missing,seasonal',
                'content_flags_disabled' => null,
            ],
            [
                'id' => 104, 'spawngroupID' => 11, 'zone' => 'arena', 'version' => 0,
                'x' => 50, 'y' => 50, 'z' => 0, 'content_flags' => null,
                'content_flags_disabled' => 'seasonal',
            ],
        ]);
        $connection->table('items')->insert(['id' => 234021, 'Name' => 'Flagged Ground Item']);
        $connection->table('ground_spawns')->insert([
            [
                'id' => 301, 'zoneid' => 77, 'version' => -1, 'min_x' => 1, 'max_x' => 1,
                'min_y' => 1, 'max_y' => 1, 'max_z' => 1, 'item' => 234021,
                'content_flags' => 'missing',
            ],
            [
                'id' => 302, 'zoneid' => 77, 'version' => -1, 'min_x' => 2, 'max_x' => 2,
                'min_y' => 2, 'max_y' => 2, 'max_z' => 2, 'item' => 234021,
                'content_flags' => 'seasonal',
            ],
        ]);
        $connection->table('doors')->insert([
            [
                'id' => 500, 'doorid' => 1, 'zone' => 'arena', 'version' => 0,
                'pos_x' => 250, 'pos_y' => 250, 'pos_z' => 0, 'dest_zone' => 'NONE',
                'opentype' => 0, 'door_param' => 0,
            ],
            [
                'id' => 501, 'doorid' => 2, 'zone' => 'arena', 'version' => 0,
                'pos_x' => 300, 'pos_y' => 300, 'pos_z' => 0, 'dest_zone' => 'NONE',
                'opentype' => 57, 'door_param' => 1,
            ],
        ]);
        $connection->table('object')->insert([
            [
                'id' => 600, 'zoneid' => 77, 'version' => 0, 'xpos' => 5, 'ypos' => 5,
                'zpos' => 0, 'type' => 10, 'display_name' => 'Hidden forge',
                'content_flags' => 'missing',
            ],
            [
                'id' => 601, 'zoneid' => 77, 'version' => 0, 'xpos' => 6, 'ypos' => 6,
                'zpos' => 0, 'type' => 10, 'display_name' => 'Seasonal forge',
                'content_flags' => 'seasonal',
            ],
        ]);

        $locations = collect($this->zoneService()->forZone(Zone::findOrFail(1), 0)['groups'][0]['locations'])
            ->keyBy('id');

        $this->assertFalse($locations->has('npc-102'));
        $this->assertTrue($locations->has('npc-103'));
        $this->assertFalse($locations->has('npc-104'));
        $this->assertFalse($locations->has('ground-301'));
        $this->assertTrue($locations->has('ground-302'));
        $this->assertFalse($locations->has('door-500'));
        $this->assertSame('Portal to Lower Guk', $locations['door-501']['label']);
        $this->assertFalse($locations->has('object-600'));
        $this->assertTrue($locations->has('object-601'));
    }

    private function zoneService(): ZoneAtlasService
    {
        return new ZoneAtlasService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(ConfigRepository::class),
            $this->app->make(UrlGenerator::class),
            $this->mapCatalog(),
        );
    }

    private function groundService(): GroundSpawnLocationService
    {
        return new GroundSpawnLocationService(
            $this->app->make(DatabaseManager::class),
            $this->app->make(ConfigRepository::class),
            $this->app->make(UrlGenerator::class),
            $this->mapCatalog(),
        );
    }

    private function mapCatalog(): ZoneMapCatalog
    {
        return new ZoneMapCatalog(
            $this->app->make(UrlGenerator::class),
            base_path('tests/fixtures/missing-map-manifest.json'),
            public_path(),
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
            $table->float('safe_x')->default(0);
            $table->float('safe_y')->default(0);
            $table->float('safe_z')->default(0);
            $table->float('safe_heading')->default(0);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('spawngroup', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $schema->create('spawn2', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('spawngroupID');
            $table->string('zone');
            $table->integer('version')->default(0);
            $table->float('x');
            $table->float('y');
            $table->float('z');
            $table->float('heading')->default(0);
            $table->integer('respawntime')->default(0);
            $table->integer('variance')->default(0);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('spawnentry', function (Blueprint $table) {
            $table->integer('spawngroupID');
            $table->integer('npcID');
            $table->float('chance')->default(100);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('npc_types', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('race')->default(1);
            $table->integer('level')->default(1);
            $table->integer('maxlevel')->default(1);
            $table->integer('merchant_id')->default(0);
            $table->boolean('rare_spawn')->default(false);
            $table->boolean('raid_target')->default(false);
            $table->boolean('isquest')->default(false);
        });
        $schema->create('items', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('Name');
        });
        $schema->create('discovered_items', function (Blueprint $table) {
            $table->integer('item_id')->primary();
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
        $schema->create('zone_points', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('zone');
            $table->integer('version')->default(0);
            $table->integer('number')->default(0);
            $table->float('x');
            $table->float('y');
            $table->float('z');
            $table->float('heading')->default(0);
            $table->float('target_x')->default(0);
            $table->float('target_y')->default(0);
            $table->float('target_z')->default(0);
            $table->float('target_heading')->default(0);
            $table->integer('target_zone_id')->default(0);
            $table->integer('target_instance')->default(0);
            $table->boolean('is_virtual')->default(false);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('doors', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('doorid')->default(0);
            $table->string('zone');
            $table->integer('version')->default(-1);
            $table->string('name')->nullable();
            $table->float('pos_x')->default(0);
            $table->float('pos_y')->default(0);
            $table->float('pos_z')->default(0);
            $table->float('heading')->default(0);
            $table->integer('opentype')->default(0);
            $table->integer('lockpick')->default(0);
            $table->integer('keyitem')->default(0);
            $table->integer('door_param')->default(0);
            $table->string('dest_zone')->nullable();
            $table->float('dest_x')->default(0);
            $table->float('dest_y')->default(0);
            $table->float('dest_z')->default(0);
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
        $schema->create('object', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('zoneid');
            $table->integer('version')->default(-1);
            $table->float('xpos')->default(0);
            $table->float('ypos')->default(0);
            $table->float('zpos')->default(0);
            $table->float('heading')->default(0);
            $table->integer('itemid')->default(0);
            $table->string('objectname')->nullable();
            $table->integer('type')->default(0);
            $table->string('display_name')->nullable();
            $table->integer('min_expansion')->default(-1);
            $table->integer('max_expansion')->default(-1);
            $table->string('content_flags')->nullable();
            $table->string('content_flags_disabled')->nullable();
        });
    }

    private function seedData(): void
    {
        $connection = DB::connection('eqemu');
        $connection->table('zone')->insert([
            [
                'id' => 1, 'zoneidnumber' => 77, 'short_name' => 'arena', 'long_name' => 'The Arena',
                'version' => 0, 'expansion' => 0, 'min_status' => 0, 'safe_x' => 1, 'safe_y' => 2,
                'safe_z' => 3, 'safe_heading' => 0,
            ],
            [
                'id' => 2, 'zoneidnumber' => 66, 'short_name' => 'gukbottom', 'long_name' => 'Lower Guk',
                'version' => 0, 'expansion' => 0, 'min_status' => 0, 'safe_x' => 0, 'safe_y' => 0,
                'safe_z' => 0, 'safe_heading' => 0,
            ],
        ]);
        $connection->table('spawngroup')->insert(['id' => 10, 'name' => 'named_merchant_group']);
        $connection->table('spawn2')->insert([
            [
                'id' => 100, 'spawngroupID' => 10, 'zone' => 'arena', 'version' => 0,
                'x' => 11, 'y' => 12, 'z' => 13, 'heading' => 0, 'respawntime' => 960, 'variance' => 174,
            ],
            [
                'id' => 101, 'spawngroupID' => 10, 'zone' => 'arena', 'version' => 1,
                'x' => 21, 'y' => 22, 'z' => 23, 'heading' => 0, 'respawntime' => 960, 'variance' => 0,
            ],
        ]);
        $connection->table('spawnentry')->insert([
            'spawngroupID' => 10, 'npcID' => 200, 'chance' => 25,
        ]);
        $connection->table('npc_types')->insert([
            'id' => 200, 'name' => 'a_named_merchant', 'race' => 1, 'level' => 72, 'maxlevel' => 72,
            'merchant_id' => 99, 'rare_spawn' => 1, 'raid_target' => 0, 'isquest' => 0,
        ]);
        $connection->table('items')->insert(['id' => 234020, 'Name' => 'Token of Witness Protection 1']);
        $connection->table('ground_spawns')->insert([
            'id' => 300, 'zoneid' => 77, 'version' => -1, 'min_x' => -10, 'max_x' => 30,
            'min_y' => 20, 'max_y' => 40, 'max_z' => 5, 'heading' => 0, 'name' => 'IT63_ACTORDEF',
            'item' => 234020, 'max_allowed' => 1, 'respawn_timer' => 300,
        ]);
        $connection->table('zone_points')->insert([
            [
                'id' => 400, 'zone' => 'arena', 'version' => 0, 'number' => 1,
                'x' => 100, 'y' => 200, 'z' => 10, 'target_zone_id' => 66,
            ],
            [
                'id' => 401, 'zone' => 'arena', 'version' => 0, 'number' => 2,
                'x' => 110, 'y' => 210, 'z' => 10, 'target_zone_id' => 66,
            ],
        ]);
    }
}
