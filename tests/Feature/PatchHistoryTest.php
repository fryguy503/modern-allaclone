<?php

namespace Tests\Feature;

use App\Services\PatchArchive;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesEmptyEqemuSearchSchema;
use Tests\TestCase;

class PatchHistoryTest extends TestCase
{
    use CreatesEmptyEqemuSearchSchema;

    public function test_patch_history_is_enabled_and_advertised_by_default(): void
    {
        $this->assertTrue(config('everquest.patch_history.enable'));
        $this->assertTrue(Route::has('patches.index'));

        $this->get('/')
            ->assertOk()
            ->assertSeeText('Patch History')
            ->assertSee('Search NPCs, items, patches...', false)
            ->assertSee('rel="alternate" type="application/rss+xml"', false);
    }

    public function test_enabled_global_search_includes_patch_suggestions(): void
    {
        $this->useEmptyEqemuSearchDatabase();

        $this->getJson('/search/suggest?q=Plane%20of%20Time')
            ->assertOk()
            ->assertJsonFragment(['type' => 'patch']);
    }

    public function test_archive_index_exposes_search_facets_layouts_and_complete_exports(): void
    {
        $this->get('/patches')
            ->assertOk()
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-frame-options', 'DENY')
            ->assertHeader('content-security-policy')
            ->assertSeeText('Every era. Every fix. One searchable history.')
            ->assertSeeText('682')
            ->assertSeeText('Expansions')
            ->assertSeeText('Timeline')
            ->assertSee(route('patches.export.json'), false)
            ->assertSee(route('patches.export.csv'), false)
            ->assertSee(route('patches.feed'), false);
    }

    public function test_search_filters_and_layout_are_shareable(): void
    {
        $response = $this->get('/patches?q=%22Plane+of+Time%22&scope=body&sort=relevance&view=expansions&categories%5B0%5D=zones-npcs');

        $response->assertOk()
            ->assertSeeText('Archive results')
            ->assertSeeText('Plane of Time')
            ->assertSee('name="view" value="expansions"', false);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->get('/patches?per_page=500&sort=drop-table&from=1900-01-01')
            ->assertRedirect()
            ->assertSessionHasErrors(['per_page', 'sort', 'from']);
    }

    public function test_detail_page_has_safe_formatted_plain_and_download_views(): void
    {
        $patch = $this->app->make(PatchArchive::class)->find('1999-11-01-1');

        $this->get('/patches/1999-11-01-1')
            ->assertOk()
            ->assertSeeText($patch['title'])
            ->assertSeeText('Formatted')
            ->assertSeeText('Plain text')
            ->assertSeeText('Record provenance')
            ->assertSeeText('source occurrences retained')
            ->assertSee(route('patches.single.json', $patch['slug']), false)
            ->assertSee(route('patches.raw', $patch['slug']), false);

        $this->get('/patches/2099-01-01-1')->assertNotFound();
    }

    public function test_sources_page_exposes_the_complete_manifest_and_extracted_pdf_context(): void
    {
        $this->get('/patches/sources')
            ->assertOk()
            ->assertSeeText('Source manifest')
            ->assertSeeText('Patch_Summaries.pdf')
            ->assertSeeText('ZAM EverQuest Patch Highlights')
            ->assertSeeText('Highlights page 15');
    }

    public function test_imported_titles_and_bodies_are_escaped_at_the_view_boundary(): void
    {
        $patch = [
            'slug' => '2000-01-01-1', 'title' => '<img src=x onerror=alert(1)>',
            'display_date' => '<script>alert(1)</script>', 'patch_date' => '2000-01-01',
            'effective_date' => '2000-01-01', 'year' => 2000, 'month' => 1, 'kind' => 'live',
            'year_inferred' => false, 'expansion' => 'Original EverQuest', 'expansion_code' => 'original-everquest',
            'categories' => [], 'content' => '<iframe src=javascript:alert(1)></iframe>',
            'summary' => '<svg onload=alert(1)>', 'word_count' => 3, 'change_count' => 0,
            'content_hash' => str_repeat('a', 64), 'source_files' => ['unsafe.txt'], 'source_urls' => [],
            'occurrence_count' => 1, 'source_occurrences' => [],
        ];
        $fakeArchive = new class($patch) extends PatchArchive {
            public function __construct(private array $patch) {}
            public function find(string $slug): ?array { return $slug === $this->patch['slug'] ? $this->patch : null; }
            public function adjacent(array $patch): array { return ['previous' => null, 'next' => null]; }
            public function related(array $patch, int $limit = 4): array { return []; }
            public function onThisDay(array $patch): array { return []; }
            public function yearArchive(int $year): array { return [1 => [$this->patch]]; }
        };
        $this->app->instance(PatchArchive::class, $fakeArchive);

        $response = $this->get('/patches/2000-01-01-1')->assertOk();
        $response->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        $response->assertSee('&lt;iframe src=javascript:alert(1)&gt;&lt;/iframe&gt;', false);
        $response->assertDontSee('<img src=x', false);
        $response->assertDontSee('<iframe src=javascript:', false);
    }

    public function test_filtered_json_csv_and_rss_contracts_are_valid(): void
    {
        $jsonResponse = $this->get('/patches/export/json?q=%22Plane+of+Time%22');
        $jsonResponse->assertOk()->assertHeader('content-type', 'application/json; charset=UTF-8');
        $json = json_decode($jsonResponse->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $json['schema_version']);
        $this->assertArrayHasKey('title', $json);
        $this->assertArrayHasKey('coverage', $json);
        $this->assertArrayHasKey('provenance', $json);
        $this->assertArrayHasKey('filters', $json);
        $this->assertSame($json['record_count'], count($json['patches']));
        $this->assertGreaterThan(0, $json['record_count']);

        $csvResponse = $this->get('/patches/export/csv?q=%22Plane+of+Time%22');
        $csvResponse->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $csvResponse->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $headers = str_getcsv(strtok(substr($csv, 3), "\r\n"), ',', '"', '');
        $this->assertContains('source_occurrences', $headers);

        $rss = $this->get('/patches/feed.xml');
        $rss->assertOk()->assertHeader('content-type', 'application/rss+xml; charset=UTF-8');
        $this->assertSame(20, substr_count($rss->getContent(), '<item>'));
    }

    public function test_full_export_supports_conditional_requests(): void
    {
        $first = $this->get('/patches/export/json');
        $first->assertOk()->assertHeader('etag');

        $this->withHeader('If-None-Match', $first->headers->get('etag'))
            ->get('/patches/export/json')
            ->assertStatus(304);
    }

    public function test_raw_export_etag_hashes_the_exact_payload_and_tracks_heading_changes(): void
    {
        $patch = $this->app->make(PatchArchive::class)->find('1999-11-01-1');
        $first = $this->get('/patches/1999-11-01-1/raw.txt')->assertOk();
        $firstPayload = $first->streamedContent();
        $firstEtag = $first->headers->get('etag');

        $this->assertSame('"'.hash('sha256', $firstPayload).'"', $firstEtag);
        $this->withHeader('If-None-Match', $firstEtag)
            ->get('/patches/1999-11-01-1/raw.txt')
            ->assertStatus(304);

        $patch['display_date'] .= ' (corrected heading)';
        $changedArchive = new class($patch) extends PatchArchive {
            public function __construct(private array $patch) {}
            public function find(string $slug): ?array { return $slug === $this->patch['slug'] ? $this->patch : null; }
            public function lastModified(): int { return 1_700_000_000; }
        };
        $this->app->instance(PatchArchive::class, $changedArchive);

        $changed = $this->withHeader('If-None-Match', $firstEtag)
            ->get('/patches/1999-11-01-1/raw.txt')
            ->assertOk();
        $changedPayload = $changed->streamedContent();
        $changedEtag = $changed->headers->get('etag');

        $this->assertNotSame($firstPayload, $changedPayload);
        $this->assertNotSame($firstEtag, $changedEtag);
        $this->assertSame('"'.hash('sha256', $changedPayload).'"', $changedEtag);
        $this->withHeader('If-None-Match', $changedEtag)
            ->get('/patches/1999-11-01-1/raw.txt')
            ->assertStatus(304);
    }

    public function test_reference_style_legacy_urls_redirect_without_losing_search_filters(): void
    {
        $this->get('/patch/?q=Example&start_date=1999-01-01&end_date=2000-12-31&tag=zones')
            ->assertRedirect('/patches?q=Example&from=1999-01-01&to=2000-12-31&categories%5B0%5D=zones-npcs')
            ->assertStatus(301);

        $this->get('/patch/Plane%20of%20Time')
            ->assertRedirect('/patches?q=Plane%20of%20Time')
            ->assertStatus(301);

        $this->get('/patch/view/1999-11-01-1/raw/')
            ->assertRedirect('/patches/1999-11-01-1/raw.txt')
            ->assertStatus(301);

        $legacyJson = $this->get('/patch/export/json/Example');
        $legacyJson->assertOk();
        $this->assertGreaterThan(0, json_decode($legacyJson->streamedContent(), true, flags: JSON_THROW_ON_ERROR)['record_count']);

        $legacyCsv = $this->get('/patch/export/csv/Source');
        $legacyCsv->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $legacyCsv->streamedContent());

        $this->get('/patch/export/json')->assertOk();
    }
}
