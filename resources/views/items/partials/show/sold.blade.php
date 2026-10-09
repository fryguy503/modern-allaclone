@inject('merchantPrices', 'App\Services\MerchantPriceService')
<div class="flex w-full flex-col">
    <div class="divider">Sold by Merchants</div>
</div>

@if ($sold->contains(fn ($val) => collect($val['npcs'])->contains(fn ($npc) => $npc['class'] == 41 && !($npc['alt_currency_id'] ?? 0))))
    <p class="mb-2 text-xs text-base-content/70">Coin prices are estimates using the best charisma and faction pricing; your in-game price may vary.</p>
@endif

<div class="max-h-96 scrollbar-thin scrollbar-thumb-accent scrollbar-track-base-300 overflow-y-auto mb-5">
@foreach ($sold as $val)
<div class="px-1">
    <span class="block bg-neutral/80 text-sky-400 mt-2 p-2 font-bold sticky top-0">{{ $val['zone_name'] }}</span>
@if ($val['npcs'])
<ul role="list" class="list bg-base-300 divide-y divide-base-200">
    @foreach ($val['npcs'] as $npc)
    <li class="flex justify-between gap-x-6 px-3 py-1">
        <div class="flex min-w-0 gap-x-4">
            <div class="min-w-0 flex-auto">
                <p class="text-sm/6 font-semibold text-neutral-content">
                    <a
                        href="{{ route('npcs.show', $npc['id']) }}"
                        class="link-info link-hover">{{ $npc['clean_name'] }}</a>
                </p>
            </div>
        </div>
        <div class="shrink-0 sm:flex sm:flex-col sm:items-end">
            @if ($npc['class'] == 41)
                @if ($npc['alt_currency_id'] ?? 0)
                <p class="mt-1 text-xs/5 font-medium text-accent">{{ price($item->price) }}</p>
                @else
                <p class="mt-1 text-xs/5 font-medium text-accent">Estimated cost: {{ price($merchantPrices->estimate($item, $val['zone'], (int) ($npc['zone_version'] ?? 0))) }}</p>
                @endif
            @elseif($npc['class'] == 61)
                <p class="mt-1 text-xs/5 font-medium text-accent">{{ $item->ldopnprice }} points</p>
            @endif
        </div>
    </li>
    @endforeach
</ul>
@endif
</div>
@endforeach
</div>
