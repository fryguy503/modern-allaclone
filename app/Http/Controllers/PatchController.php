<?php

namespace App\Http\Controllers;

use App\Http\Requests\PatchSearchRequest;
use App\Services\PatchArchive;
use App\Services\PatchContentFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatchController extends Controller
{
    public function index(PatchSearchRequest $request, PatchArchive $archive): View
    {
        $filters = $request->filters();
        $patches = $archive->paginate(
            $filters,
            max(1, (int) $request->query('page', 1)),
            $filters['per_page'],
            route('patches.index')
        );

        return view('patches.index', [
            'patches' => $patches,
            'expansionGroups' => $filters['view'] === 'expansions'
                ? collect($archive->filtered($filters, true))->groupBy('expansion_code')
                : collect(),
            'filters' => $filters,
            'facets' => $archive->facets(),
            'coverage' => $archive->coverage(),
            'totals' => $archive->totals(),
            'metaTitle' => config('app.name').' - EverQuest Patch History',
            'metaDescription' => 'Search and explore every supplied EverQuest beta and Live patch note from 1998 through 2022.',
        ]);
    }

    public function show(string $slug, PatchArchive $archive, PatchContentFormatter $formatter): View
    {
        $patch = $archive->find($slug);
        abort_unless($patch, 404);

        return view('patches.show', [
            'patch' => $patch,
            'formattedSections' => $formatter->format($patch['content']),
            'adjacent' => $archive->adjacent($patch),
            'related' => $archive->related($patch),
            'onThisDay' => $archive->onThisDay($patch),
            'yearArchive' => $archive->yearArchive($patch['year']),
            'metaTitle' => config('app.name').' - '.$patch['title'],
            'metaDescription' => $patch['summary'],
        ]);
    }

    public function sources(PatchArchive $archive): View
    {
        $metadata = $archive->metadata();

        return view('patches.sources', [
            'metadata' => $metadata,
            'totals' => $archive->totals(),
            'metaTitle' => config('app.name').' - Patch Archive Sources',
            'metaDescription' => 'Coverage, provenance, encoding recovery, and source checksums for the EverQuest historical patch archive.',
        ]);
    }

    public function legacyIndex(Request $request): RedirectResponse
    {
        $tagMap = [
            'bugfix' => 'bug-fixes', 'class_changes' => 'classes', 'items' => 'items',
            'content' => 'quests-events', 'pvp' => 'pvp', 'server' => 'servers',
            'spells' => 'spells-aa', 'tradeskills' => 'tradeskills', 'ui' => 'ui', 'zones' => 'zones-npcs',
        ];
        $query = $request->query('q');
        $from = $request->query('start_date');
        $to = $request->query('end_date');
        $tag = $request->query('tag');
        $parameters = array_filter([
            'q' => is_string($query) ? mb_substr(trim($query), 0, 120) : null,
            'from' => is_string($from) ? $from : null,
            'to' => is_string($to) ? $to : null,
            'categories' => is_string($tag) && isset($tagMap[$tag]) ? [$tagMap[$tag]] : null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return redirect()->route('patches.index', $parameters, 301);
    }

    public function legacySearch(string $query): RedirectResponse
    {
        return redirect()->route('patches.index', ['q' => mb_substr(trim($query), 0, 120)], 301);
    }

    public function legacyView(string $slug): RedirectResponse
    {
        return redirect()->route('patches.show', ['slug' => $slug], 301);
    }

    public function legacyRaw(string $slug): RedirectResponse
    {
        return redirect()->route('patches.raw', ['slug' => $slug], 301);
    }
}
