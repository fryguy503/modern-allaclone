<?php

namespace App\Services;

use App\Models\DiscoveredItem;
use App\Models\Item;
use App\Models\NpcType;
use App\Models\Spell;
use App\Models\Zone;
use App\Services\EncounterJournal\EncounterCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class EncounterJournalService
{
    public function __construct(private readonly EncounterCatalog $catalog) {}

    public function enabled(): bool
    {
        return (bool) config('everquest.encounter_journal.enable', true);
    }

    public function previewDrafts(): bool
    {
        return app()->environment(['local', 'testing'])
            && (bool) config('everquest.encounter_journal.preview_drafts', false);
    }

    public function entries(): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_values(array_filter($this->catalog->all($this->previewDrafts()),
            fn (array $entry) => ! in_array($entry['zone']['short_name'], config('everquest.ignore_zones', []), true)));
    }

    public function find(string $slug): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /** The summary never contains a hidden title, NPC ID, ability, or reward. */
    public function summary(array $entry): array
    {
        $hidden = $entry['spoiler'];

        return [
            'slug' => $entry['slug'],
            'title' => $hidden ? 'Unrevealed encounter' : $entry['title'],
            'group' => $entry['group'],
            'type' => $entry['type'],
            'zone' => $entry['zone'],
            'summary' => $hidden ? 'This entry contains encounter spoilers. Open it to choose whether to reveal them.' : $entry['summary'],
            'status' => $entry['status'],
            'spoiler' => $entry['spoiler'],
        ];
    }

    public function forNpc(int $id): array
    {
        // NPC pages do not select an instance version. Label each associated
        // journal version explicitly; do not infer it from a spawn or NPC name.
        return array_map($this->summary(...), array_values(array_filter($this->entries(),
            fn (array $entry) => in_array($id, $entry['npc_ids'], true))));
    }

    public function forZone(string $shortName, int $version): array
    {
        return array_map($this->summary(...), array_values(array_filter($this->entries(),
            fn (array $entry) => $entry['zone']['short_name'] === $shortName && $entry['zone']['version'] === $version)));
    }

    /** Resolve in batches. Source-authored rewards obey the same discovery policy as native loot. */
    public function references(array $entry): array
    {
        $authoredItems = collect($entry['loot'])->concat($entry['sections'])
            ->flatMap(fn (array $section) => $section['items'] ?? [])
            ->keyBy('id');
        $itemIds = $authoredItems->keys()->all();
        $items = collect();
        $discovered = collect();
        $discoveryEnabled = (bool) config('everquest.discovered_items.enable');
        try {
            if ($itemIds !== []) {
                $items = Item::query()->whereIn('id', $itemIds)->get(['id', 'Name', 'icon'])->keyBy('id');
                if ($discoveryEnabled) {
                    $discovered = DiscoveredItem::query()->whereIn('item_id', $itemIds)->pluck('item_id')->flip();
                }
            }
        } catch (QueryException $exception) {
            // A guide remains readable before its game data is installed. Missing
            // discovery data never grants permission to expose an item identity.
            Log::warning('Encounter journal item references unavailable.', ['exception' => $exception::class]);
            $items = collect();
            $discovered = collect();
        }

        $itemLinks = [];
        foreach ($authoredItems as $id => $authored) {
            $item = $items->get($id);
            $visible = ! $discoveryEnabled || $discovered->has($id);
            $itemLinks[$id] = [
                'visible' => $visible,
                'exists' => $item !== null,
                'id' => $visible ? (int) $id : 0,
                'name' => $visible ? ($item?->Name ?? $authored['name']) : 'Undiscovered item',
                'icon' => $visible ? $item?->icon : null,
            ];
        }

        $spellIds = collect($entry['abilities'])->flatMap(fn (array $ability) => $ability['spell_ids'])->unique()->values()->all();
        $spellLinks = [];
        $npcLinks = [];
        $zoneUrl = null;
        try {
            if ($spellIds !== []) {
                foreach (Spell::query()->whereIn('id', $spellIds)->get(['id', 'name', 'new_icon']) as $spell) {
                    $spellLinks[$spell->id] = ['visible' => true, 'exists' => true, 'id' => (int) $spell->id, 'name' => $spell->name, 'icon' => $spell->new_icon];
                }
            }
            if ($entry['npc_ids'] !== []) {
                $npcLinks = NpcType::query()->whereIn('id', $entry['npc_ids'])->get(['id', 'name'])
                    ->mapWithKeys(fn (NpcType $npc) => [$npc->id => $npc->clean_name])->all();
            }
            $zone = Zone::query()->where('short_name', $entry['zone']['short_name'])
                ->where('version', $entry['zone']['version'])->first();
            if ($zone && app(ZoneAtlasService::class)->isZoneAccessible($zone)) {
                $zoneUrl = route('zones.show', ['zone' => $zone->id, 'v' => $entry['zone']['version']]);
            }
        } catch (QueryException $exception) {
            Log::warning('Encounter journal entity references unavailable.', ['exception' => $exception::class]);
        }

        return compact('itemLinks', 'spellLinks', 'npcLinks', 'zoneUrl');
    }
}
