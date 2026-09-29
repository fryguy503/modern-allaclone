<?php

namespace Tests\Unit\Services\EncounterJournal;

use App\Services\EncounterJournal\EncounterCatalog;
use PHPUnit\Framework\TestCase;

final class EncounterCatalogTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/encounter-catalog-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_public_lookup_excludes_drafts_and_associations_match_zone_version(): void
    {
        $this->write('mayong', $this->entry('mayong'));
        $this->write('hidden-finale', $this->entry('hidden-finale', ['status' => 'draft']));
        $this->write('another-version', $this->entry('another-version', ['zone' => ['short_name' => 'mistmoore', 'name' => 'Castle Mistmoore', 'version' => 0]]));
        $catalog = new EncounterCatalog($this->directory);

        $this->assertCount(2, $catalog->all());
        $this->assertCount(3, $catalog->all(true));
        $this->assertNull($catalog->find('hidden-finale'));
        $this->assertSame('hidden-finale', $catalog->find('hidden-finale', true)['slug']);
        $this->assertSame(['mayong'], array_column($catalog->forNpc(59805, 10), 'slug'));
        $this->assertSame(['another-version'], array_column($catalog->forZone('mistmoore', 0), 'slug'));
        $this->assertCount(2, $catalog->forNpc(59805));
        $this->assertCount(2, $catalog->forZone('mistmoore', 10, true));
        $this->assertSame([], $catalog->forZone('otherzone', 10));
        $this->assertSame([], $catalog->forNpc(999999, 10));
    }

    public function test_optional_sections_are_normalized_and_catalog_reads_once_per_instance(): void
    {
        $this->write('mayong', $this->entry('mayong', [
            'abilities' => [['id' => 'crimson-brand', 'name' => 'Crimson Brand', 'summary' => 'Spread out.']],
            'sections' => [['id' => 'preparation', 'title' => 'Preparation']],
            'loot' => [['title' => 'Rewards', 'description' => 'Collect your reward.']],
        ]));
        $catalog = new EncounterCatalog($this->directory);
        $entry = $catalog->find('mayong');
        $this->assertSame([], $entry['roles']);
        $this->assertSame([], $entry['phases']);
        $this->assertSame([], $entry['abilities'][0]['spell_ids']);
        $this->assertSame([], $entry['abilities'][0]['description']);
        $this->assertSame([], $entry['sections'][0]['items']);
        $this->assertSame([], $entry['loot'][0]['items']);
        $this->assertSame([], $entry['sources']['notes']);

        $this->write('mayong', $this->entry('mayong', ['status' => 'draft']));
        $this->assertNotNull($catalog->find('mayong'));
        $this->assertNull((new EncounterCatalog($this->directory))->find('mayong'));
    }

    public function test_malformed_documents_fail_validation_without_hiding_valid_entries(): void
    {
        $this->write('mayong', $this->entry('mayong'));
        file_put_contents($this->directory.'/broken.json', '{bad json');
        $this->write('bad-version', $this->entry('bad-version', ['zone' => ['short_name' => 'mistmoore', 'name' => 'Castle Mistmoore', 'version' => '10']]));
        $catalog = new EncounterCatalog($this->directory);

        $this->assertCount(1, $catalog->all());
        $report = $catalog->validationReport();
        $this->assertFalse($report['valid']);
        $this->assertSame(3, $report['files']);
        $this->assertSame(1, $report['entries']);
        $this->assertStringContainsString('Invalid JSON', $report['errors'][0]['message'].' '.$report['errors'][1]['message']);
    }

    public function test_duplicate_slugs_exclude_both_documents_and_filename_mismatch_is_reported(): void
    {
        $this->write('mayong', $this->entry('mayong'));
        $this->write('copied-mayong', $this->entry('mayong'));
        $catalog = new EncounterCatalog($this->directory);

        $this->assertSame([], $catalog->all(true));
        $messages = implode(' ', array_column($catalog->validationReport()['errors'], 'message'));
        $this->assertStringContainsString('Duplicate slug', $messages);
        $this->assertStringContainsString('match the JSON filename', $messages);
    }

    public function test_duplicate_ids_unknown_roles_and_unsafe_source_paths_are_rejected(): void
    {
        $this->write('mayong', $this->entry('mayong', [
            'roles' => [
                ['id' => 'tank', 'label' => 'Tanks', 'tips' => ['Hold the boss.']],
                ['id' => 'tank', 'label' => 'Other tanks', 'tips' => ['Hold adds.']],
            ],
            'abilities' => [['id' => 'brand', 'name' => 'Brand', 'summary' => 'Spread.', 'roles' => ['missing-role']]],
            'sources' => [
                'reviewed_at' => '2026-09-28', 'revision' => 'abc123', 'verification' => 'source-reviewed',
                'files' => [['path' => '../private.lua', 'sha256' => str_repeat('a', 64)]],
            ],
        ]));
        $catalog = new EncounterCatalog($this->directory);

        $this->assertSame([], $catalog->all(true));
        $messages = implode(' ', array_column($catalog->validationReport()['errors'], 'message'));
        $this->assertStringContainsString('duplicate ID tank', $messages);
        $this->assertStringContainsString('unknown role reference', $messages);
        $this->assertStringContainsString('relative path without traversal', $messages);
    }

    public function test_schema_accepts_reviewed_source_and_optional_check_detects_staleness(): void
    {
        file_put_contents($this->directory.'/boss.lua', 'original source');
        $this->write('mayong', $this->entry('mayong'));
        $catalog = new EncounterCatalog($this->directory);

        $this->assertTrue($catalog->validationReport()['valid']);
        $this->assertTrue($catalog->validationReport($this->directory)['valid']);
        file_put_contents($this->directory.'/boss.lua', 'changed source');
        $report = $catalog->validationReport($this->directory);
        $this->assertFalse($report['valid']);
        $this->assertStringContainsString('SHA-256 mismatch', $report['errors'][0]['message']);
        $this->assertCount(1, $catalog->all());
        unlink($this->directory.'/boss.lua');
        $this->assertStringContainsString('Source is missing', $catalog->validationReport($this->directory)['errors'][0]['message']);
    }

    public function test_source_symlink_cannot_escape_the_requested_source_root(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'encounter-source-');
        file_put_contents($outside, 'original source');
        try {
            if (! @symlink($outside, $this->directory.'/boss.lua')) {
                $this->markTestSkipped('Creating symlinks is unavailable in this environment.');
            }
            $this->write('mayong', $this->entry('mayong'));
            $report = (new EncounterCatalog($this->directory))->validationReport($this->directory);
            $this->assertFalse($report['valid']);
            $this->assertStringContainsString('outside source root', $report['errors'][0]['message']);
        } finally {
            unlink($outside);
        }
    }

    public function test_source_paths_accept_hash_prefixed_eq_quest_filenames(): void
    {
        file_put_contents($this->directory.'/#Mayong_Mistmoore.lua', 'original source');
        $entry = $this->entry('mayong');
        $entry['sources']['files'][0]['path'] = '#Mayong_Mistmoore.lua';
        $this->write('mayong', $entry);

        $this->assertTrue((new EncounterCatalog($this->directory))->validationReport($this->directory)['valid']);
    }

    public function test_missing_directory_fails_but_empty_directory_is_valid(): void
    {
        $this->assertTrue((new EncounterCatalog($this->directory))->validationReport()['valid']);
        $this->assertFalse((new EncounterCatalog($this->directory.'/missing'))->validationReport()['valid']);
        $this->assertFalse((new EncounterCatalog($this->directory))->validationReport($this->directory.'/missing')['valid']);
    }

    public function test_draft_sources_are_optional_but_published_sources_are_required(): void
    {
        $entry = $this->entry('mayong', ['status' => 'draft']);
        unset($entry['sources']);
        $this->write('mayong', $entry);
        $catalog = new EncounterCatalog($this->directory);
        $this->assertTrue($catalog->validationReport()['valid']);
        $this->assertNull($catalog->find('mayong', true)['sources']);
        $entry['status'] = 'published';
        $this->write('mayong', $entry);
        $this->assertFalse((new EncounterCatalog($this->directory))->validationReport()['valid']);
    }

    private function write(string $filename, array $entry): void
    {
        file_put_contents($this->directory.'/'.$filename.'.json', json_encode($entry, JSON_THROW_ON_ERROR));
    }

    private function entry(string $slug, array $overrides = []): array
    {
        return array_replace([
            'schema_version' => 1,
            'slug' => $slug,
            'title' => 'Mayong Mistmoore',
            'group' => 'The Hour of the Last Eclipse',
            'type' => 'raid',
            'zone' => ['short_name' => 'mistmoore', 'name' => 'Castle Mistmoore', 'version' => 10],
            'npc_ids' => [59805],
            'status' => 'published',
            'spoiler' => true,
            'summary' => 'Survive the final eclipse.',
            'overview' => ['Use the seals to break each covenant.'],
            'sources' => [
                'reviewed_at' => '2026-09-28',
                'revision' => 'abc123',
                'verification' => 'source-reviewed',
                'files' => [['path' => 'boss.lua', 'sha256' => hash('sha256', 'original source')]],
            ],
        ], $overrides);
    }
}
