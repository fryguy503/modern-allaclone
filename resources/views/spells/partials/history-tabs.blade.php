@php
    $activeTab = in_array($activeTab ?? null, ['details', 'history'], true) ? $activeTab : 'details';
@endphp

<nav aria-label="Spell navigation" class="mb-5">
    <div class="tabs tabs-border">
        <a href="{{ route('spells.show', $spellId) }}"
            @if ($activeTab === 'details') aria-current="page" @endif
            class="tab {{ $activeTab === 'details' ? 'tab-active' : '' }}">
            Details
        </a>
        <a href="{{ route('spells.history', $spellId) }}"
            @if ($activeTab === 'history') aria-current="page" @endif
            class="tab {{ $activeTab === 'history' ? 'tab-active' : '' }}">
            History
        </a>
    </div>
</nav>
