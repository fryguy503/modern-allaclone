<?php

namespace App\Http\Controllers;

use App\Services\EncounterJournalService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;

class EncounterController extends Controller
{
    public function index(Request $request, EncounterJournalService $journal)
    {
        abort_unless($journal->enabled(), 404);
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:150'],
            'zone' => ['nullable', 'string', 'regex:/^[a-z0-9_]+$/', 'max:64'],
            'type' => ['nullable', 'in:raid,group,event'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        abort_if($validator->fails(), 422, 'Invalid encounter journal filters.');
        $validated = $validator->validated();
        $filters = ['q' => trim($validated['q'] ?? ''), 'zone' => $validated['zone'] ?? '', 'type' => $validated['type'] ?? ''];
        // Search only the public summary. A query cannot reveal a spoiler's title
        // or match hidden ability/item names through a result count.
        $summaries = collect($journal->entries())->map($journal->summary(...));
        $zones = $summaries->mapWithKeys(fn (array $entry) => [$entry['zone']['short_name'] => $entry['zone']['name']])->sort()->all();
        $types = array_intersect_key(['raid' => 'Raid', 'group' => 'Group', 'event' => 'Event'], $summaries->keyBy('type')->all());
        $filtered = $summaries->filter(function (array $entry) use ($filters) {
            $haystack = implode(' ', [$entry['title'], $entry['summary'], $entry['group'], $entry['zone']['name']]);

            return ($filters['q'] === '' || mb_stripos($haystack, $filters['q']) !== false)
                && ($filters['zone'] === '' || $entry['zone']['short_name'] === $filters['zone'])
                && ($filters['type'] === '' || $entry['type'] === $filters['type']);
        })->sortBy(fn (array $entry) => [$entry['zone']['name'], $entry['group'], $entry['title']])->values();
        $page = (int) ($validated['page'] ?? 1);
        $entries = new LengthAwarePaginator($filtered->forPage($page, 20)->values(), $filtered->count(), 20, $page, [
            'path' => route('encounters.index'), 'query' => $request->only(['q', 'zone', 'type']),
        ]);

        return $this->respond('encounters.index', compact('entries', 'filters', 'zones', 'types') + [
            'previewDrafts' => $journal->previewDrafts(),
            'metaTitle' => config('app.name').' - Encounter Journal',
            'metaDescription' => 'Browse encounter overviews, mechanics, phases, and rewards.',
        ], $journal->previewDrafts());
    }

    public function show(string $slug, Request $request, EncounterJournalService $journal)
    {
        abort_unless($journal->enabled(), 404);
        abort_if(Validator::make($request->query(), ['reveal' => ['nullable', 'in:1']])->fails(), 422, 'Invalid spoiler selection.');
        $entry = $journal->find($slug);
        abort_unless($entry, 404);
        $locked = $entry['spoiler'] && $request->query('reveal') !== '1';
        $related = collect($journal->forZone($entry['zone']['short_name'], $entry['zone']['version']))
            ->where('group', $entry['group'])->values()->all();
        $references = $locked
            ? ['itemLinks' => [], 'spellLinks' => [], 'npcLinks' => [], 'zoneUrl' => null]
            : $journal->references($entry);
        $noIndex = $journal->previewDrafts() || $entry['spoiler'];

        // Never serialize the concealed document into Alpine/HTML/metadata.
        $publicEntry = $locked ? $journal->summary($entry) : $entry;

        return $this->respond('encounters.show', $references + [
            'entry' => $publicEntry,
            'related' => $related,
            'locked' => $locked,
            'previewDrafts' => $journal->previewDrafts(),
            'revealUrl' => route('encounters.show', ['slug' => $slug, 'reveal' => 1]),
            'metaTitle' => config('app.name').' - '.$publicEntry['title'].' - Encounter Journal',
            'metaDescription' => $publicEntry['summary'],
        ], $noIndex);
    }

    private function respond(string $view, array $data, bool $noIndex)
    {
        $response = response()->view($view, $data);
        // Discovery and publication may change independently of the game cache.
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($noIndex) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
