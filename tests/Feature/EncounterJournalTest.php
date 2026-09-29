<?php

namespace Tests\Feature;

use App\Services\EncounterJournal\EncounterCatalog;
use App\Services\EncounterJournalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EncounterJournalTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = sys_get_temp_dir().'/encounter-http-'.bin2hex(random_bytes(8));
        File::makeDirectory($this->directory);
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

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function entry(array $changes = []): array
    {
        return array_replace_recursive([
            'schema_version' => 1, 'slug' => 'the-trial', 'title' => 'The Trial',
            'group' => 'Trials of the Keep', 'type' => 'event',
            'zone' => ['short_name' => 'mistmoore', 'name' => 'Castle Mistmoore', 'version' => 10],
            'npc_ids' => [59801], 'status' => 'published', 'spoiler' => false,
            'summary' => 'A trial of coordination.', 'overview' => ['Defeat the keepers.'],
            'roles' => [['id' => 'raid', 'label' => 'Everyone', 'tips' => ['Watch the warning.']]],
            'phases' => [['id' => 'timed', 'label' => 'Reinforcements', 'trigger' => 'After one minute', 'description' => 'Control the adds.']],
            'abilities' => [['id' => 'pulse', 'name' => 'Warning pulse', 'summary' => 'Move away.', 'description' => ['Watch the warning.'], 'tags' => ['Movement'], 'roles' => ['raid'], 'spell_ids' => [123]]],
            'sections' => [['id' => 'preparation', 'title' => 'Preparation', 'paragraphs' => ['Assign your roles.'], 'bullets' => [], 'items' => [['id' => 147750, 'name' => 'Authored seal name', 'quantity' => 2]]]],
            'loot' => [['title' => 'Rewards', 'description' => 'One selection.', 'items' => [['id' => 147734, 'name' => 'Secret authored sword', 'quantity' => 1]]]],
            'sources' => ['reviewed_at' => '2026-09-28', 'revision' => str_repeat('a', 40), 'verification' => 'source-reviewed', 'files' => [['path' => 'mistmoore/encounters/test.lua', 'sha256' => str_repeat('b', 64)]], 'notes' => []],
        ], $changes);
    }

    private function document(array $entry): void
    {
        File::put($this->directory.'/'.$entry['slug'].'.json', json_encode($entry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->app->forgetInstance(EncounterCatalog::class);
    }

    public function test_published_document_populates_index_and_generic_event_sections(): void
    {
        $this->document($this->entry());
        $this->get('/encounters')->assertOk()->assertSeeText('The Trial')->assertSeeText('Encounter Journal');
        $this->get('/encounters/the-trial')->assertOk()
            ->assertSeeText('Defeat the keepers.')->assertSeeText('After one minute')
            ->assertSeeText('Warning pulse')->assertSeeText('Preparation')
            ->assertHeader('cache-control', 'no-store, private');
    }

    public function test_filters_search_pagination_and_invalid_query_types(): void
    {
        for ($index = 1; $index <= 22; $index++) {
            $this->document($this->entry(['slug' => 'trial-'.$index, 'title' => 'Trial '.$index]));
        }
        $response = $this->get('/encounters?zone=mistmoore&type=event');
        $response->assertOk()->assertSee('page=2', false);
        $this->get('/encounters?q=not-found')->assertOk()->assertDontSeeText('Trial 1');
        $this->get('/encounters?type=raid')->assertOk()->assertDontSeeText('Trial 1');
        $this->get('/encounters?q[]=bad')->assertStatus(422);
        $this->get('/encounters?page=-1')->assertStatus(422);
        $this->get('/encounters?type=invalid')->assertStatus(422);
    }

    public function test_drafts_are_absent_and_query_parameters_cannot_publish_them(): void
    {
        $this->document($this->entry(['status' => 'draft', 'title' => 'Private draft title']));
        $this->get('/encounters')->assertOk()->assertDontSeeText('Private draft title');
        $this->get('/encounters/the-trial?reveal=1&preview=1')->assertNotFound();
        config()->set('everquest.encounter_journal.preview_drafts', true);
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/encounters/the-trial?reveal=1')->assertNotFound();
    }

    public function test_explicit_local_draft_preview_is_noindex_and_not_shared_cacheable(): void
    {
        $this->document($this->entry(['status' => 'draft']));
        config()->set('everquest.encounter_journal.preview_drafts', true);
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Draft preview')
            ->assertHeader('x-robots-tag', 'noindex, nofollow')->assertHeader('cache-control', 'no-store, private');
    }

    public function test_spoilers_are_not_in_metadata_html_search_or_related_summaries_before_reveal(): void
    {
        $this->document($this->entry(['spoiler' => true, 'title' => 'Concealed adversary', 'summary' => 'Hidden background text.']));
        $this->get('/encounters')->assertOk()->assertDontSee('Concealed adversary')->assertDontSee('Hidden background text.');
        $this->get('/encounters?q=Concealed')->assertOk()->assertDontSee('href="http://localhost/encounters/the-trial"', false);
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Unrevealed encounter')
            ->assertDontSee('Concealed adversary')->assertDontSee('Hidden background text.')
            ->assertDontSee('Warning pulse')->assertDontSee('147734')->assertDontSee('59801')
            ->assertHeader('x-robots-tag', 'noindex, nofollow');
        $this->get('/encounters/the-trial?reveal=1')->assertOk()->assertSeeText('Concealed adversary')->assertSeeText('Warning pulse');
    }

    public function test_item_discovery_masks_authored_names_ids_icons_and_links_in_all_sections(): void
    {
        $this->document($this->entry());
        DB::connection('eqemu')->table('items')->insert([
            ['id' => 147734, 'Name' => 'Secret database sword', 'icon' => 98765],
            ['id' => 147750, 'Name' => 'Secret database seal', 'icon' => 98766],
        ]);
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Undiscovered item')
            ->assertDontSee('Secret authored sword')->assertDontSee('Authored seal name')
            ->assertDontSee('Secret database')->assertDontSee('147734')->assertDontSee('147750')->assertDontSee('98765');
        DB::connection('eqemu')->table('discovered_items')->insert(['item_id' => 147734]);
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Secret database sword')
            ->assertSee(route('items.show', 147734), false)->assertDontSee('Secret database seal');
    }

    public function test_existing_entities_link_and_missing_records_render_without_broken_links(): void
    {
        $this->document($this->entry());
        config()->set('everquest.discovered_items.enable', false);
        DB::connection('eqemu')->table('spells_new')->insert(['id' => 123, 'name' => 'Known spell', 'new_icon' => null]);
        DB::connection('eqemu')->table('npc_types')->insert(['id' => 59801, 'name' => '#Known_Keeper']);
        DB::connection('eqemu')->table('zone')->insert(['id' => 159, 'short_name' => 'mistmoore', 'long_name' => 'Castle Mistmoore', 'version' => 10]);
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Secret authored sword')
            ->assertDontSee(route('items.show', 147734), false)
            ->assertSee(route('spells.show', 123), false)->assertSee(route('npcs.show', 59801), false)
            ->assertSee(route('zones.show', ['zone' => 159, 'v' => 10]), false);
    }

    public function test_missing_discovery_table_fails_closed_and_source_text_is_escaped(): void
    {
        $this->document($this->entry(['overview' => ['<img src=x onerror=alert(1)>']]));
        Schema::connection('eqemu')->drop('discovered_items');
        $this->get('/encounters/the-trial')->assertOk()->assertSeeText('Undiscovered item')
            ->assertDontSee('Secret authored sword')->assertDontSee('<img src=x', false)
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
    }

    public function test_automatic_associations_respect_zone_version_publication_and_feature_switch(): void
    {
        $this->document($this->entry());
        $this->document($this->entry(['slug' => 'other-version', 'zone' => ['version' => 11]]));
        $this->document($this->entry(['slug' => 'unpublished', 'status' => 'draft']));
        $journal = app(EncounterJournalService::class);
        $this->assertCount(2, $journal->forNpc(59801));
        $this->assertSame(['the-trial'], array_column($journal->forZone('mistmoore', 10), 'slug'));
        $this->assertSame([], $journal->forZone('mistmoore', 0));
        $this->assertSame([], $journal->forNpc(1));
        config()->set('everquest.encounter_journal.enable', false);
        $this->assertSame([], $journal->forNpc(59801));
        $this->get('/encounters')->assertNotFound();
        $this->get('/encounters/the-trial')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('href="http://localhost/encounters"', false);
    }

    public function test_ignored_zones_do_not_leak_through_the_journal(): void
    {
        $this->document($this->entry());
        config()->set('everquest.ignore_zones', ['mistmoore']);
        $this->get('/encounters')->assertOk()->assertDontSeeText('The Trial');
        $this->get('/encounters/the-trial')->assertNotFound();
    }
}
