<?php

namespace App\Http\Controllers;

use App\Filters\ItemFilter;
use App\Models\Item;
use App\Services\GroundSpawnLocationService;
use App\ViewModels\ItemViewModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $discoveryEnabled = config('everquest.discovered_items.enable');

        $request->validate([
            'stat1comp' => 'in:1,2,5',
            'stat2comp' => 'in:1,2,5',
            'stat3comp' => 'in:1,2,5',
        ]);

        $items = collect();
        if ($request->query->count() > 0) {
            $query = (new ItemFilter($request))->apply(Item::query());

            if ($discoveryEnabled) {
                $query->whereHas('discovery');
            }

            $query->select([
                'id', 'Name', 'icon', 'itemtype', 'ac', 'hp', 'damage', 'delay',
                'augtype', 'slots', 'bagslots', 'bagwr',
                'mana', 'endur', 'haste', 'aagi', 'acha', 'adex', 'aint', 'asta', 'astr', 'awis',
                'heroic_agi', 'heroic_cha', 'heroic_dex', 'heroic_int', 'heroic_sta', 'heroic_str', 'heroic_wis',
                'attack', 'regen', 'manaregen', 'enduranceregen', 'spellshield', 'combateffects', 'shielding',
                'damageshield', 'dotshielding', 'dsmitigation', 'avoidance', 'accuracy', 'stunresist',
                'strikethrough', 'spelldmg',
            ]);

            $items = $query->sortable()->paginate(50)->withQueryString();
        }

        return view('items.index', [
            'items' => $items,
            'metaTitle' => config('app.name').' - Item Search',
        ]);
    }

    public function show(Item $item, GroundSpawnLocationService $groundSpawnLocations)
    {
        $itemCache = Cache::remember("items.show.{$item->id}", now()->addMonth(), function () use ($item) {
            $item = Item::with(['evolvingDetails.item', 'discovery'])
                ->where('id', $item->id)
                ->firstOrFail();
            $vm = (new ItemViewModel($item))->withEffects();

            return [
                'item' => $item,
                'recipes' => $vm->recipes(),
                'used_in_ts' => $vm->usedInTradeskills(),
                'forage' => $vm->forageZones(),
                'fishing' => $vm->fishingZones(),
                'soldByZone' => $vm->soldInZones(),
            ];
        });

        $cachedItem = $itemCache['item'];
        $groundSpawns = $cachedItem->canDisplay()
            ? collect($groundSpawnLocations->forItem((int) $cachedItem->id))
            : collect();

        return view('items.show', [
            ...$itemCache,
            'ground_spawn' => $groundSpawns,
            'metaTitle' => config('app.name').' - Item: '.$cachedItem->Name,
        ]);
    }

    public function popup(Item $item)
    {
        $item = Item::where('id', $item->id)->firstOrFail();
        (new ItemViewModel($item))->withEffects();

        return response()->json([
            'html' => view('items.partials.popup', ['item' => $item])->render(),
        ]);
    }

    public function drops_by_zone(Item $item)
    {
        $drops = Cache::rememberForever("items.drops_by_zone.{$item->id}", function () use ($item) {
            return (new ItemViewModel($item))->dropsByZone();
        });

        return response()->json([
            'drops_by_zone' => $drops['drops_by_zone'],
            'top_npcs' => $drops['top_npcs'],
        ]);
    }
}
