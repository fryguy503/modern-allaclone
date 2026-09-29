<ul class="journal-item-list" aria-label="{{ $listLabel ?? 'Items' }}">
    @foreach ($items as $item)
        @php($resolvedItem = $itemLinks[$item['id']] ?? ['visible' => false, 'exists' => false])
        <li>
            @if (! $resolvedItem['visible'])
                <span class="journal-undiscovered">
                    @include('encounters.partials.icon', ['name' => 'lock', 'class' => 'h-4 w-4 shrink-0'])
                    Undiscovered item
                </span>
            @elseif ($resolvedItem['exists'])
                <x-item-link :item-id="$resolvedItem['id']" :item-name="$resolvedItem['name']"
                    :item-icon="$resolvedItem['icon'] ?? null" item-class="min-w-0" />
                @if (($item['quantity'] ?? 1) > 1)
                    <span class="journal-item-quantity">×{{ $item['quantity'] }}</span>
                @endif
            @else
                <span>{{ $item['name'] }}<span class="mt-1 block text-xs text-base-content/60">Details unavailable</span></span>
                @if (($item['quantity'] ?? 1) > 1)
                    <span class="journal-item-quantity">×{{ $item['quantity'] }}</span>
                @endif
            @endif
        </li>
    @endforeach
</ul>
