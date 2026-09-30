<?php

namespace Tests\Feature;

use App\Services\EncounterJournal\EncounterCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class TofsEncounterJournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // The override supports validation of a staged content overlay without installing it.
        $this->directory = getenv('TOFS_JOURNAL_TEST_PATH') ?: resource_path('data/encounters');
        config()->set('everquest.encounter_journal', ['enable' => true, 'path' => $this->directory, 'preview_drafts' => false]);
        config()->set('everquest.ignore_zones', []);
        config()->set('database.connections.eqemu', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('eqemu');
        Schema::connection('eqemu')->create('items', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('Name');
            $table->integer('icon')->nullable();
        });
        Schema::connection('eqemu')->create('discovered_items', function (Blueprint $table) {
            $table->integer('item_id')->primary();
        });
        Schema::connection('eqemu')->create('npc_types', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
        });
        Schema::connection('eqemu')->create('spells_new', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('name');
            $table->integer('new_icon')->nullable();
        });
        Schema::connection('eqemu')->create('zone', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('short_name');
            $table->string('long_name');
            $table->integer('version');
        });
    }

    private function tofsEntries(): array
    {
        return array_values(array_filter((new EncounterCatalog($this->directory))->all(true), fn (array $entry): bool => str_starts_with($entry['slug'], 'tofs-')));
    }

    public function test_tofs_documents_validate_and_link_only_to_their_event_versions(): void
    {
        $catalog = new EncounterCatalog($this->directory);
        $report = $catalog->validationReport();
        $this->assertTrue($report['valid'], json_encode($report['errors'], JSON_PRETTY_PRINT));
        $entries = $this->tofsEntries();
        $this->assertCount(12, $entries);
        $this->assertCount(7, array_filter($entries, fn (array $entry): bool => $entry['type'] === 'group' && $entry['zone']['version'] === 20));
        $this->assertCount(5, array_filter($entries, fn (array $entry): bool => $entry['type'] === 'raid' && $entry['zone']['version'] === 21));
        foreach ($entries as $entry) {
            $this->assertSame('published', $entry['status']);
            $this->assertSame('frozenshadow', $entry['zone']['short_name']);
            $this->assertSame('source-reviewed', $entry['sources']['verification']);
            $this->assertNotEmpty($entry['sources']['files']);
            foreach ($entry['npc_ids'] as $npcId) {
                $this->assertCount(1, $catalog->forNpc($npcId, $entry['zone']['version']));
                $this->assertSame([], $catalog->forNpc($npcId, 0));
            }
        }
        $this->assertSame('tofs-unwritten-hour-finale', $catalog->forNpc(111812, 21)[0]['slug']);
    }

    public function test_group_and_raid_reward_pools_cover_the_complete_item_catalog_once(): void
    {
        $groupItems = [];
        $raidItems = [];
        foreach ($this->tofsEntries() as $entry) {
            if ($entry['type'] === 'group') {
                $this->assertCount(2, $entry['loot']);
                $this->assertCount(4, $entry['loot'][0]['items']);
                foreach ($entry['loot'][0]['items'] as $item) {
                    $groupItems[] = $item['id'];
                    $this->assertSame(1, $item['quantity']);
                }
                $this->assertSame([['id' => 148252, 'name' => 'Splinter of the Unwritten Hour', 'quantity' => 1]], $entry['loot'][1]['items']);
                $bossIndex = $entry['npc_ids'][0] - 111800;
                $chance = [20, 25, 30, 35, 40, 45, 60][$bossIndex];
                $this->assertStringContainsString($chance.'%', $entry['loot'][1]['description']);
            } else {
                $expectedPools = $entry['slug'] === 'tofs-unwritten-hour-finale' ? 3 : 1;
                $this->assertCount($expectedPools, $entry['loot']);
                foreach ($entry['loot'] as $pool) {
                    if (count($pool['items']) === 4) {
                        foreach ($pool['items'] as $item) {
                            $raidItems[] = $item['id'];
                            $this->assertSame(1, $item['quantity']);
                        }
                    }
                }
            }
        }
        sort($groupItems);
        sort($raidItems);
        $this->assertSame(range(148200, 148227), $groupItems);
        $this->assertSame(range(148228, 148251), $raidItems);
        $finale = (new EncounterCatalog($this->directory))->find('tofs-unwritten-hour-finale');
        $this->assertSame([['id' => 148253, 'name' => 'Dawn-Sealed Testament of the Unwritten Hour', 'quantity' => 6]], $finale['loot'][2]['items']);
    }

    public function test_every_tofs_guide_renders_native_roles_phases_and_rewards(): void
    {
        foreach ($this->tofsEntries() as $entry) {
            $response = $this->get('/encounters/'.$entry['slug'].'?reveal=1');
            $response->assertOk()->assertSeeText($entry['title'])->assertSeeText('Undiscovered item');
            foreach ($entry['roles'] as $role) {
                $response->assertSeeText($role['label']);
            }
            foreach ($entry['phases'] as $phase) {
                $response->assertSeeText($phase['label']);
            }
        }
    }

    public function test_both_finales_preserve_the_native_spoiler_boundary(): void
    {
        $catalog = new EncounterCatalog($this->directory);
        foreach (['tofs-seven-silences-finale', 'tofs-unwritten-hour-finale'] as $slug) {
            $entry = $catalog->find($slug);
            $this->assertTrue($entry['spoiler']);
            $this->get('/encounters/'.$slug)->assertOk()->assertSeeText('Unrevealed encounter')
                ->assertDontSee($entry['title'])->assertDontSee($entry['summary'])
                ->assertDontSee('The Last Claim')->assertHeader('x-robots-tag', 'noindex, nofollow');
            $this->get('/encounters/'.$slug.'?reveal=1')->assertOk()->assertSeeText($entry['title']);
        }
        $this->get('/encounters?zone=frozenshadow')->assertOk()
            ->assertDontSee('Tserrina, Lady of the Unwritten Hour')
            ->assertDontSee("Tserrina&#039;s Unlived Reflection");
    }

    public function test_reward_names_remain_in_discovery_filtered_structured_references(): void
    {
        foreach ($this->tofsEntries() as $entry) {
            $names = [];
            foreach ($entry['loot'] as $pool) {
                foreach ($pool['items'] as $item) {
                    $names[] = $item['name'];
                }
            }
            unset($entry['loot']);
            foreach ($entry['sections'] as &$section) {
                foreach ($section['items'] as $item) {
                    $names[] = $item['name'];
                }
                unset($section['items']);
            }
            unset($section);
            $metadata = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($names as $name) {
                $this->assertStringNotContainsString($name, $metadata, $entry['slug'].' leaks an item name outside structured references.');
            }
        }
        DB::connection('eqemu')->table('items')->insert(['id' => 148200, 'Name' => 'Shield of the Last Ember', 'icon' => 1499]);
        $this->get('/encounters/tofs-the-last-ember')->assertOk()->assertDontSee('Shield of the Last Ember')->assertDontSee('148200');
        DB::connection('eqemu')->table('discovered_items')->insert(['item_id' => 148200]);
        $this->get('/encounters/tofs-the-last-ember')->assertOk()->assertSeeText('Shield of the Last Ember')->assertSee(route('items.show', 148200), false);
    }
}
