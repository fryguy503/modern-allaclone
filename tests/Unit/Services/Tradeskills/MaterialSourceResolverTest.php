<?php

namespace Tests\Unit\Services\Tradeskills;

use App\Services\Tradeskills\MaterialSourceResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesTradeskillPlannerSchema;
use Tests\TestCase;

class MaterialSourceResolverTest extends TestCase
{
    use CreatesTradeskillPlannerSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTradeskillPlannerSchema();
        config()->set('everquest.current_expansion', 1);
        config()->set('everquest.ignore_zones', []);
        config()->set('everquest.merchants_dont_drop_stuff', true);
    }

    public function test_source_queries_are_batched_instead_of_issued_per_item(): void
    {
        $queryCount = 0;
        DB::listen(function (QueryExecuted $query) use (&$queryCount): void {
            if ($query->connectionName === 'eqemu') {
                $queryCount++;
            }
        });

        $resolved = (new MaterialSourceResolver)->resolve(range(1, 100));

        $this->assertCount(100, $resolved);
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            'Resolving one chunk should require at most one query per source type.',
        );
    }

    public function test_sources_are_normalized_and_filtered_by_zone_and_chance(): void
    {
        config()->set('everquest.ignore_zones', ['ignored']);

        DB::connection('eqemu')->table('zone')->insert([
            [
                'id' => 1,
                'zoneidnumber' => 10,
                'short_name' => 'qeynos',
                'long_name' => 'South Qeynos',
                'version' => 0,
                'expansion' => 0,
            ],
            [
                'id' => 2,
                'zoneidnumber' => 20,
                'short_name' => 'future',
                'long_name' => 'Future Zone',
                'version' => 0,
                'expansion' => 99,
            ],
            [
                'id' => 3,
                'zoneidnumber' => 30,
                'short_name' => 'ignored',
                'long_name' => 'Ignored Zone',
                'version' => 0,
                'expansion' => 0,
            ],
        ]);

        DB::connection('eqemu')->table('forage')->insert([
            ['itemid' => 100, 'zoneid' => 10, 'chance' => 25, 'level' => 5],
            ['itemid' => 100, 'zoneid' => 10, 'chance' => 0, 'level' => 1],
            ['itemid' => 100, 'zoneid' => 20, 'chance' => 50, 'level' => 1],
            ['itemid' => 100, 'zoneid' => 30, 'chance' => 75, 'level' => 1],
        ]);
        DB::connection('eqemu')->table('fishing')->insert([
            ['itemid' => 100, 'zoneid' => 10, 'chance' => 10, 'skill_level' => 20],
            ['itemid' => 100, 'zoneid' => 10, 'chance' => 0, 'skill_level' => 1],
        ]);
        DB::connection('eqemu')->table('ground_spawns')->insert([
            ['item' => 100, 'zoneid' => 10, 'max_x' => 1, 'max_y' => 2, 'max_z' => 3],
        ]);

        $resolved = (new MaterialSourceResolver)->resolve([100]);

        $this->assertSame(['forage', 'fishing', 'ground'], $resolved[100]['sourceTypes']);
        $this->assertCount(3, $resolved[100]['sources']);
        $this->assertStringContainsString('South Qeynos', $resolved[100]['sources'][0]['detail']);
        $this->assertStringNotContainsString(
            'Future Zone',
            implode(' ', array_column($resolved[100]['sources'], 'detail')),
        );
        $this->assertStringNotContainsString(
            'Ignored Zone',
            implode(' ', array_column($resolved[100]['sources'], 'detail')),
        );
    }

    public function test_high_cardinality_sources_for_one_item_do_not_starve_later_items(): void
    {
        config()->set('everquest.tradeskill_planner.max_sources_per_type', 1);
        config()->set('everquest.tradeskill_planner.max_source_rows', 100);

        DB::connection('eqemu')->table('zone')->insert([
            'id' => 1,
            'zoneidnumber' => 10,
            'short_name' => 'qeynos',
            'long_name' => 'South Qeynos',
            'version' => 0,
            'expansion' => 0,
        ]);

        $npcs = [];
        $merchantEntries = [];
        $spawnEntries = [];
        $spawns = [];
        foreach (range(1, 102) as $id) {
            $itemId = $id === 102 ? 200 : 100;
            $npcs[] = [
                'id' => $id,
                'name' => "Vendor {$id}",
                'merchant_id' => $id,
                'loottable_id' => 0,
            ];
            $merchantEntries[] = ['item' => $itemId, 'merchantid' => $id];
            $spawnEntries[] = ['npcID' => $id, 'spawngroupID' => $id, 'chance' => 100];
            $spawns[] = ['spawngroupID' => $id, 'zone' => 'qeynos', 'version' => 0];
        }
        DB::connection('eqemu')->table('npc_types')->insert($npcs);
        DB::connection('eqemu')->table('merchantlist')->insert($merchantEntries);
        DB::connection('eqemu')->table('spawnentry')->insert($spawnEntries);
        DB::connection('eqemu')->table('spawn2')->insert($spawns);

        $resolved = (new MaterialSourceResolver)->resolve([100, 200]);

        $this->assertSame(['vendor'], $resolved[100]['sourceTypes']);
        $this->assertSame(['vendor'], $resolved[200]['sourceTypes']);
        $this->assertCount(1, $resolved[100]['sources']);
        $this->assertCount(1, $resolved[200]['sources']);
    }
}
