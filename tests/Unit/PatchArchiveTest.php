<?php

namespace Tests\Unit;

use App\Services\PatchArchive;
use Tests\TestCase;

class PatchArchiveTest extends TestCase
{
    private PatchArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archive = $this->app->make(PatchArchive::class);
    }

    public function test_archive_covers_every_supplied_record_and_source_occurrence(): void
    {
        $patches = $this->archive->all();
        $coverage = $this->archive->coverage();
        $metadata = $this->archive->metadata();

        $this->assertCount(682, $patches);
        $this->assertSame(682, $coverage['patch_count']);
        $this->assertSame('1998-07-07', $coverage['first_patch']);
        $this->assertSame('2026-06-24', $coverage['last_patch']);
        $this->assertSame(56, $coverage['source_file_count']);
        $this->assertCount(56, $metadata['provenance']['files']);
        $this->assertSame(1287, array_sum(array_column($patches, 'occurrence_count')));
        $this->assertCount(682, array_unique(array_column($patches, 'slug')));
    }

    public function test_malformed_and_duplicate_source_entries_remain_lossless(): void
    {
        $mayThird = $this->archive->find('1999-05-03-1');
        $augustNinth = array_values(array_filter(
            $this->archive->all(),
            fn (array $patch) => $patch['patch_date'] === '2000-08-09'
        ));

        $this->assertNotNull($mayThird);
        $this->assertSame(2, $mayThird['occurrence_count']);
        $this->assertCount(2, $mayThird['source_occurrences']);
        $this->assertNotSame(
            $mayThird['source_occurrences'][0]['source_offset'],
            $mayThird['source_occurrences'][1]['source_offset']
        );
        $this->assertCount(2, $augustNinth);
        $this->assertSame(['August 9, 2000', 'August 9th, 7:00am'], array_column($augustNinth, 'title'));
    }

    public function test_legacy_encoding_and_rescheduled_dates_are_normalized_without_data_loss(): void
    {
        $trademarkPatch = $this->archive->find('1999-11-01-1');
        $rescheduled = array_values(array_filter(
            $this->archive->all(),
            fn (array $patch) => $patch['effective_date'] !== $patch['patch_date']
        ));

        $this->assertNotNull($trademarkPatch);
        $this->assertStringContainsString('Kunark™', $trademarkPatch['content']);
        $this->assertDoesNotMatchRegularExpression('/[\x{0080}-\x{009F}\x{FFFD}]/u', $trademarkPatch['content']);
        $this->assertCount(1, $rescheduled);
        $this->assertSame('2018-01-04', $rescheduled[0]['patch_date']);
        $this->assertSame('2018-01-05', $rescheduled[0]['effective_date']);
    }

    public function test_phrase_search_and_composite_filters_are_applied_consistently(): void
    {
        $matches = $this->archive->filtered([
            'q' => '"Plane of Time" wizard',
            'scope' => 'all',
            'sort' => 'relevance',
            'categories' => [],
        ]);

        $this->assertNotEmpty($matches);
        foreach ($matches as $match) {
            $searchable = mb_strtolower($match['title'].' '.$match['content']);
            $this->assertStringContainsString('plane of time', $searchable);
            $this->assertStringContainsString('wizard', $searchable);
            $this->assertArrayNotHasKey('_score', $match);
        }

        $first = $matches[0];
        $categories = array_slice(array_column($first['categories'], 'slug'), 0, 2);
        $filtered = $this->archive->filtered([
            'from' => $first['patch_date'],
            'to' => $first['patch_date'],
            'expansion' => $first['expansion_code'],
            'categories' => $categories,
            'scope' => 'all',
            'sort' => 'oldest',
        ]);

        $this->assertNotEmpty($filtered);
        foreach ($filtered as $match) {
            $this->assertSame($first['patch_date'], $match['patch_date']);
            $this->assertSame($first['expansion_code'], $match['expansion_code']);
            $this->assertSame([], array_diff($categories, array_column($match['categories'], 'slug')));
        }
    }

    public function test_compact_global_suggestion_index_returns_ranked_patch_links(): void
    {
        $this->assertFileExists($this->archive->suggestionPath());
        $this->assertLessThan(filesize($this->archive->dataPath()), filesize($this->archive->suggestionPath()));

        $suggestions = $this->archive->suggest('"Plane of Time"', 5);
        $this->assertNotEmpty($suggestions);
        $this->assertLessThanOrEqual(5, count($suggestions));
        $this->assertArrayHasKey('slug', $suggestions[0]);
        $this->assertArrayHasKey('patch_date', $suggestions[0]);
        $this->assertArrayNotHasKey('search_text', $suggestions[0]);
    }

    public function test_every_supplied_artifact_is_described_in_the_manifest(): void
    {
        $files = collect($this->archive->metadata()['provenance']['files'])->keyBy('filename');

        $this->assertTrue($files->has('Patch_Summaries.pdf'));
        $this->assertStringContainsString('15-page ZAM highlights', $files['Patch_Summaries.pdf']['description']);
        $this->assertCount(15, $files['Patch_Summaries.pdf']['semantic_extraction']['pages']);
        $this->assertTrue($files->has('patches_1999-2008_combined.md'));
        $this->assertTrue($files->has('patch.txt'));
        $this->assertNotEmpty($files['patch.txt']['sha256']);
        $this->assertTrue($files->has('official-game-update-notes-live.rss'));
        $this->assertSame(20, $files['official-game-update-notes-live.rss']['adapter_metadata']['raw_item_count']);
        $this->assertSame(12, $files['official-game-update-notes-live.rss']['adapter_metadata']['feed_excerpt_count']);
    }

    public function test_only_complete_official_feed_items_are_published(): void
    {
        $complete = $this->archive->find('2026-06-24-1');

        $this->assertNotNull($complete);
        $this->assertSame('hotfix', $complete['kind']);
        $this->assertSame('Shattering of Ro', $complete['expansion']);
        $this->assertNull($this->archive->find('2026-08-19-1'));
    }
}
