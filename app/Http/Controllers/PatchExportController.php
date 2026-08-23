<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatchSearchRequest;
use App\Services\PatchArchive;
use App\Services\PatchExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PatchExportController extends Controller
{
    public function json(PatchSearchRequest $request, PatchArchive $archive, PatchExportService $export): Response
    {
        $filters = $request->filters();
        if ($this->isUnfiltered($request, $filters)) return $export->fullJson($request);

        return $export->json($request, $archive->filtered($filters), $filters);
    }

    public function csv(PatchSearchRequest $request, PatchArchive $archive, PatchExportService $export): Response
    {
        $filters = $request->filters();
        if ($this->isUnfiltered($request, $filters)) return $export->fullCsv($request);

        return $export->csv($request, $archive->filtered($filters), $filters);
    }

    public function singleJson(Request $request, string $slug, PatchArchive $archive, PatchExportService $export): Response
    {
        $patch = $archive->find($slug);
        abort_unless($patch, 404);

        return $export->json($request, [$patch], [], $patch['slug']);
    }

    public function singleCsv(Request $request, string $slug, PatchArchive $archive, PatchExportService $export): Response
    {
        $patch = $archive->find($slug);
        abort_unless($patch, 404);

        return $export->csv($request, [$patch], [], $patch['slug']);
    }

    public function raw(Request $request, string $slug, PatchArchive $archive, PatchExportService $export): Response
    {
        $patch = $archive->find($slug);
        abort_unless($patch, 404);

        return $export->raw($request, $patch);
    }

    public function feed(Request $request, PatchArchive $archive): Response
    {
        $patches = array_slice(array_reverse($archive->all()), 0, 20);
        $response = response()->view('patches.feed', [
            'patches' => $patches,
            'lastBuildDate' => CarbonImmutable::createFromTimestamp($archive->lastModified())->toRfc2822String(),
        ], 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=1800, must-revalidate',
        ]);
        $response->setEtag('"'.hash('sha256', $archive->checksum().'rss').'"');
        $response->setLastModified(CarbonImmutable::createFromTimestamp($archive->lastModified()));
        $response->isNotModified($request);

        return $response;
    }

    public function legacyJson(Request $request, PatchArchive $archive, PatchExportService $export, ?string $query = null): Response
    {
        if ($query === null && is_string($request->query('q'))) $query = $request->query('q');
        if ($query === null || trim($query) === '') return $export->fullJson($request);
        $filters = ['q' => mb_substr(trim($query), 0, 120), 'scope' => 'all', 'sort' => 'relevance', 'categories' => []];

        return $export->json($request, $archive->filtered($filters), $filters);
    }

    public function legacyCsv(Request $request, PatchArchive $archive, PatchExportService $export, ?string $query = null): Response
    {
        if ($query === null && is_string($request->query('q'))) $query = $request->query('q');
        if ($query === null || trim($query) === '') return $export->fullCsv($request);
        $filters = ['q' => mb_substr(trim($query), 0, 120), 'scope' => 'all', 'sort' => 'relevance', 'categories' => []];

        return $export->csv($request, $archive->filtered($filters), $filters);
    }

    private function isUnfiltered(Request $request, array $filters): bool
    {
        foreach (['q', 'from', 'to', 'year', 'kind', 'expansion', 'source'] as $key) {
            if (($filters[$key] ?? null) !== null && ($filters[$key] ?? '') !== '') return false;
        }

        if (($filters['categories'] ?? []) !== []) return false;

        return ! $request->filled('sort') && ! $request->filled('scope');
    }
}
