@php
    $activeTab = in_array($activeTab ?? null, ['details', 'history'], true) ? $activeTab : 'details';
@endphp

<nav aria-label="Item navigation" class="mb-5">
    <div class="tabs tabs-border">
        <a href="{{ route('items.show', $itemId) }}"
            @if ($activeTab === 'details') aria-current="page" @endif
            class="tab {{ $activeTab === 'details' ? 'tab-active' : '' }}">
            Details
        </a>
        <a href="{{ route('items.history', $itemId) }}"
            @if ($activeTab === 'history') aria-current="page" @endif
            class="tab {{ $activeTab === 'history' ? 'tab-active' : '' }}">
            History
        </a>
    </div>
</nav>
