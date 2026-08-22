<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Zone;
use App\Models\Spell;
use App\Models\NpcType;
use App\Models\FactionList;
use App\Models\TradeskillRecipe;
use App\Services\PatchArchive;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function suggest(Request $request)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');
        $q = $request->query('q', '');
        if (! is_string($q)) return response()->json([]);
        $q = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $q)), 0, 80);

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        // create a special query for npc type names
        $qNpcs = str_replace(' ', '_', $q);
        $qNpcs = str_replace('`', '-', $qNpcs);
        $qId = $q;
        $qLike = addcslashes($q, '\\%_');
        $qNpcsLike = addcslashes($qNpcs, '\\%_');
        $qIdLike = addcslashes($qId, '\\%_');

        $patches = collect();
        if (config('everquest.patch_history.enable', true)) {
            $patches = collect(app(PatchArchive::class)->suggest($q, 5))->map(function (array $patch) {
                return [
                    'type' => 'patch',
                    'name' => $patch['title'].' · '.$patch['patch_date'],
                    'url' => route('patches.show', $patch['slug']),
                    'id' => 'patch-'.$patch['slug'],
                ];
            });
        }

        $results = collect($patches);

        $results = $results
            ->merge(
                NpcType::where('name', 'like', "%{$qLike}%")->orWhere('name', 'like', "%{$qNpcsLike}%")
                    ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qIdLike}%"])
                    ->groupBy('name')->limit(5)->get()->map(function ($npc) {
                        return [
                            'type' => 'npc',
                            'name' => $npc->clean_name,
                            'url' => route('npcs.show', $npc->id),
                            'id' => 'npc-' . $npc->id
                        ];
                    })
            )->merge(
                Item::query()
                    ->when($discoveryEnabled, function ($q) {
                        $q->whereHas('discovery');
                    })
                    ->where(function ($qBuilder) use ($qLike, $qIdLike) {
                        $qBuilder->where('Name', 'like', "%{$qLike}%")
                            ->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qIdLike}%"]);
                    })
                    ->limit(10)
                    ->get()
                    ->map(function ($item) {
                        return [
                            'type' => 'item',
                            'name' => $item->Name,
                            'url' => route('items.show', $item->id),
                            'id' => 'item-' . $item->id
                        ];
                    })
            )->merge(
                TradeskillRecipe::where('name', 'like', "%{$qLike}%")->limit(5)->get()->map(function ($r) {
                    return [
                        'type' => 'recipe',
                        'name' => $r->name,
                        'url' => route('recipes.show', $r->id),
                        'id' => 'recipe-' . $r->id
                    ];
                })
            )->merge(
                Zone::where(function ($query) use ($qLike, $qIdLike) {
                    $query->where('long_name', 'like', "%{$qLike}%")
                        ->orWhere('short_name', 'like', "%{$qLike}%")
                        ->orWhereRaw('CAST(zoneidnumber AS CHAR) LIKE ?', ["%{$qIdLike}%"]);
                })
                    ->whereNotIn('short_name', config('everquest.ignore_zones', []))
                    ->groupBy('short_name', 'long_name')->limit(5)->get()->map(function ($z) {
                        return [
                            'type' => 'zone',
                            'name' => $z->long_name,
                            'url' => route('zones.show', $z->id),
                            'id' => 'zone-' . $z->id
                        ];
                    })
            )->merge(
                FactionList::where('name', 'like', "%{$qLike}%")->limit(5)->get()->map(function ($f) {
                    return [
                        'type' => 'faction',
                        'name' => $f->name,
                        'url' => route('factions.show', $f->id),
                        'id' => 'faction-' . $f->id
                    ];
                })
            )->merge(
                Spell::where('name', 'like', "%{$qLike}%")->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$qIdLike}%"])
                    ->groupBy('name')->limit(5)->get()->map(function ($s) {
                        return [
                            'type' => 'spell',
                            'name' => $s->name,
                            'url' => route('spells.show', $s->id),
                            'id' => 'spell-' . $s->id
                        ];
                    })
            );

        return response()->json($results->take(40)->values());
    }
}
