@extends('layouts.default')

@section('title', 'Encounter Journal')
@section('content-padding', 'p-4 sm:p-10')

@section('content')
    <div class="encounter-journal">
        @include('encounters.partials.draft-banner')

        <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-base-content/65" aria-label="Breadcrumb">
            <a href="{{ route('encounters.index') }}" class="link link-hover link-info">Encounter Journal</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('encounters.index', ['zone' => $entry['zone']['short_name']]) }}" class="link link-hover">{{ $entry['zone']['name'] }}</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $entry['title'] }}</span>
        </nav>

        <div class="journal-layout">
            <aside class="journal-sidebar">
                <div class="journal-sidebar-heading">
                    <p class="journal-eyebrow">{{ ucfirst($entry['type']) }} journal</p>
                    <h2 class="mt-2 text-lg font-semibold leading-snug">{{ $entry['group'] ?: $entry['zone']['name'] }}</h2>
                    <p class="mt-2 text-xs leading-5 text-base-content/60">{{ $entry['zone']['name'] }} <span aria-hidden="true">·</span> Version {{ $entry['zone']['version'] }}</p>
                </div>
                <nav class="journal-encounter-nav" aria-label="Encounters in this event" tabindex="0">
                    <a href="{{ route('encounters.show', $entry['slug']) }}" aria-current="page" class="journal-encounter-link">
                        @include('encounters.partials.icon', ['name' => 'shield', 'class' => 'mt-0.5 h-4 w-4 shrink-0'])
                        <span>{{ $entry['title'] }}<small>Current encounter</small></span>
                    </a>
                    @foreach ($related as $encounter)
                        @if ($encounter['slug'] !== $entry['slug'])
                            <a href="{{ route('encounters.show', $encounter['slug']) }}" class="journal-encounter-link">
                                @include('encounters.partials.icon', ['name' => $encounter['spoiler'] ? 'lock' : 'shield', 'class' => 'mt-0.5 h-4 w-4 shrink-0'])
                                <span>{{ $encounter['title'] }}<small>{{ $encounter['status'] === 'draft' ? 'Draft' : ($encounter['spoiler'] ? 'Spoiler encounter' : ucfirst($encounter['type'])) }}</small></span>
                            </a>
                        @endif
                    @endforeach
                </nav>
                <a href="{{ route('encounters.index') }}" class="journal-sidebar-all">Browse all encounters <span aria-hidden="true">→</span></a>
            </aside>

            <article class="journal-article">
                <header class="journal-entry-header">
                    <div class="min-w-0">
                        <div class="mb-3 flex flex-wrap gap-2">
                            <span class="badge badge-outline badge-sm">{{ ucfirst($entry['type']) }}</span>
                            @if ($entry['status'] === 'draft')<span class="badge badge-warning badge-outline badge-sm">Draft</span>@endif
                            @if ($entry['spoiler'])<span class="badge badge-outline badge-sm">Spoiler encounter</span>@endif
                        </div>
                        <h2 class="text-3xl font-semibold leading-tight tracking-tight sm:text-4xl">{{ $entry['title'] }}</h2>
                        <p class="mt-4 max-w-3xl text-sm leading-7 text-base-content/75">{{ $entry['summary'] }}</p>
                        @if ($zoneUrl)
                            <a href="{{ $zoneUrl }}" class="mt-3 inline-flex items-center gap-1.5 text-sm link-info link-hover">Explore {{ $entry['zone']['name'] }} @include('encounters.partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4'])</a>
                        @endif
                    </div>
                    <span class="journal-entry-emblem">@include('encounters.partials.icon', ['name' => 'shield', 'class' => 'h-8 w-8'])</span>
                </header>

                @if ($locked)
                    <section class="journal-locked" aria-labelledby="journal-spoiler-heading">
                        <span class="mb-5 inline-flex text-info">@include('encounters.partials.icon', ['name' => 'lock', 'class' => 'h-9 w-9'])</span>
                        <h3 id="journal-spoiler-heading" class="text-2xl font-semibold">Discover it on your terms.</h3>
                        <p class="mx-auto mt-3 max-w-lg text-sm leading-7 text-base-content/70">This journal reveals encounter mechanics, objectives, and rewards. Open it when you are ready to see the details.</p>
                        <a href="{{ $revealUrl }}" class="btn btn-info mt-6">Reveal encounter journal</a>
                    </section>
                @else
                    @php
                        $tabs = ['overview' => 'Overview'];
                        if (count($entry['abilities'])) $tabs['abilities'] = 'Abilities';
                        if (count($entry['sections'])) $tabs['notes'] = 'Field notes';
                        if (count($entry['loot'])) $tabs['loot'] = 'Loot';
                        $roleLabels = array_column($entry['roles'], 'label', 'id');
                    @endphp
                    <div x-data="{
                        tab: 'overview',
                        role: 'all',
                        moveTab(event, direction) {
                            const buttons = [...event.currentTarget.parentElement.querySelectorAll('[role=tab]')];
                            const current = buttons.indexOf(event.currentTarget);
                            const next = direction === 'first' ? 0 : direction === 'last' ? buttons.length - 1 : (current + direction + buttons.length) % buttons.length;
                            buttons[next].click();
                            buttons[next].focus();
                        }
                    }">
                        <nav class="journal-tabs" role="tablist" aria-label="Encounter guide sections" x-cloak>
                            @foreach ($tabs as $id => $label)
                                <button type="button" id="journal-tab-{{ $id }}" role="tab" aria-controls="journal-panel-{{ $id }}"
                                    :aria-selected="tab === '{{ $id }}'" :tabindex="tab === '{{ $id }}' ? 0 : -1"
                                    @click="tab = '{{ $id }}'"
                                    @keydown.right.prevent="moveTab($event, 1)" @keydown.left.prevent="moveTab($event, -1)"
                                    @keydown.home.prevent="moveTab($event, 'first')" @keydown.end.prevent="moveTab($event, 'last')">
                                    {{ $label }}
                                    @if ($id === 'abilities')<span class="journal-tab-count">{{ count($entry['abilities']) }}</span>@endif
                                </button>
                            @endforeach
                        </nav>

                        <section id="journal-panel-overview" :role="'tabpanel'" :aria-labelledby="'journal-tab-overview'"
                            tabindex="0" x-show="tab === 'overview'" class="journal-panel">
                            <h3 class="journal-section-heading">The encounter</h3>
                            <div class="journal-prose">
                                @foreach ($entry['overview'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                            </div>

                            @if (count($entry['roles']))
                                <section class="mt-7" aria-labelledby="journal-role-heading">
                                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                        <h3 id="journal-role-heading" class="journal-section-heading !mb-0">Know your role</h3>
                                        <div class="journal-role-controls" role="group" aria-label="Highlight role advice" x-cloak>
                                            <button type="button" :aria-pressed="role === 'all'" @click="role = 'all'">Everyone</button>
                                            @foreach ($entry['roles'] as $role)
                                                @if (! in_array($role['id'], ['all', 'everyone', 'common'], true))
                                                    <button type="button" :aria-pressed="role === '{{ $role['id'] }}'" @click="role = '{{ $role['id'] }}'">{{ $role['label'] }}</button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="journal-role-grid">
                                        @foreach ($entry['roles'] as $role)
                                            <section class="journal-role-card" @if (! in_array($role['id'], ['all', 'everyone', 'common'], true)) x-show="role === 'all' || role === '{{ $role['id'] }}'" @endif>
                                                <h4 class="mb-3 text-sm font-semibold text-info">{{ $role['label'] }}</h4>
                                                <ul class="journal-bullets">
                                                    @foreach ($role['tips'] as $tip)<li>{{ $tip }}</li>@endforeach
                                                </ul>
                                            </section>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            @if (count($entry['phases']))
                                <section class="mt-8" aria-labelledby="journal-phases-heading">
                                    <h3 id="journal-phases-heading" class="journal-section-heading">Encounter progression</h3>
                                    <ol class="journal-phases">
                                        @foreach ($entry['phases'] as $phase)
                                            <li>
                                                <span class="journal-phase-number" aria-hidden="true">{{ $loop->iteration }}</span>
                                                <div class="journal-phase-content">
                                                    <p class="journal-phase-trigger">{{ $phase['trigger'] }}</p>
                                                    <h4 class="mt-1 font-semibold">{{ $phase['label'] }}</h4>
                                                    <p class="mt-2 text-sm leading-7 text-base-content/75">{{ $phase['description'] }}</p>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ol>
                                </section>
                            @endif
                        </section>

                        @if (count($entry['abilities']))
                            <section id="journal-panel-abilities" :role="'tabpanel'" :aria-labelledby="'journal-tab-abilities'"
                                tabindex="0" x-show="tab === 'abilities'" class="journal-panel">
                                <h3 class="journal-section-heading">Abilities & mechanics</h3>
                                <p class="mb-5 text-sm leading-6 text-base-content/65">Open a mechanic for details and any linked spells.</p>
                                <div class="space-y-3">
                                    @foreach ($entry['abilities'] as $ability)
                                        <details class="journal-ability" @if ($loop->first) open @endif>
                                            <summary>
                                                <span class="journal-ability-symbol">@include('encounters.partials.icon', ['name' => 'spark', 'class' => 'h-5 w-5'])</span>
                                                <span class="min-w-0 grow">
                                                    <span class="block font-semibold">{{ $ability['name'] }}</span>
                                                    <span class="mt-1 block text-sm leading-6 text-base-content/70">{{ $ability['summary'] }}</span>
                                                </span>
                                                <span class="journal-disclosure" aria-hidden="true"></span>
                                            </summary>
                                            <div class="journal-ability-body">
                                                @if (count($ability['tags']) || count($ability['roles']))
                                                    <div class="mb-4 flex flex-wrap gap-2">
                                                        @foreach ($ability['tags'] as $tag)<span class="badge badge-sm badge-outline">{{ $tag }}</span>@endforeach
                                                        @foreach ($ability['roles'] as $roleId)<span class="badge badge-sm badge-info badge-outline">{{ $roleLabels[$roleId] ?? ucfirst($roleId) }}</span>@endforeach
                                                    </div>
                                                @endif
                                                <div class="journal-prose">
                                                    @foreach ($ability['description'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                                                </div>
                                                @if (count($ability['spell_ids']))
                                                    <div class="journal-linked-spells">
                                                        <p class="journal-eyebrow mb-2">Related spells</p>
                                                        <ul class="space-y-2">
                                                            @foreach ($ability['spell_ids'] as $spellId)
                                                                @php($resolvedSpell = $spellLinks[$spellId] ?? ['exists' => false])
                                                                <li>
                                                                    @if ($resolvedSpell['exists'])
                                                                        <x-spell-link :spell-id="$resolvedSpell['id']" :spell-name="$resolvedSpell['name']" :spell-icon="$resolvedSpell['icon'] ?? null" />
                                                                    @else
                                                                        <span class="text-sm text-base-content/60">Spell details unavailable</span>
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            </div>
                                        </details>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if (count($entry['sections']))
                            <section id="journal-panel-notes" :role="'tabpanel'" :aria-labelledby="'journal-tab-notes'"
                                tabindex="0" x-show="tab === 'notes'" class="journal-panel">
                                <h3 class="journal-section-heading">Field notes</h3>
                                <div class="space-y-5">
                                    @foreach ($entry['sections'] as $section)
                                        <section class="journal-note">
                                            <h4 class="mb-3 text-lg font-semibold">{{ $section['title'] }}</h4>
                                            <div class="journal-prose">
                                                @foreach ($section['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                                            </div>
                                            @if (count($section['bullets']))
                                                <ul class="journal-bullets mt-4">
                                                    @foreach ($section['bullets'] as $bullet)<li>{{ $bullet }}</li>@endforeach
                                                </ul>
                                            @endif
                                            @if (count($section['items']))
                                                @include('encounters.partials.items', ['items' => $section['items'], 'listLabel' => $section['title'] . ' items'])
                                            @endif
                                        </section>
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if (count($entry['loot']))
                            <section id="journal-panel-loot" :role="'tabpanel'" :aria-labelledby="'journal-tab-loot'"
                                tabindex="0" x-show="tab === 'loot'" class="journal-panel">
                                <h3 class="journal-section-heading">Encounter rewards</h3>
                                <div class="journal-loot-grid">
                                    @foreach ($entry['loot'] as $reward)
                                        <section class="journal-note">
                                            <h4 class="mb-3 text-lg font-semibold">{{ $reward['title'] }}</h4>
                                            @if (filled($reward['description']))<p class="text-sm leading-7 text-base-content/75">{{ $reward['description'] }}</p>@endif
                                            @if (count($reward['items']))
                                                @include('encounters.partials.items', ['items' => $reward['items'], 'listLabel' => $reward['title'] . ' items'])
                                            @endif
                                        </section>
                                    @endforeach
                                </div>
                            </section>
                        @endif
                    </div>

                    <footer class="journal-entry-footer">
                        @if (filled($entry['sources']['reviewed_at'] ?? null))
                            <span>Reviewed <time datetime="{{ $entry['sources']['reviewed_at'] }}">{{ $entry['sources']['reviewed_at'] }}</time></span>
                        @endif
                        @if (($entry['sources']['verification'] ?? '') === 'live-verified')
                            <span>Live verified</span>
                        @elseif (($entry['sources']['verification'] ?? '') === 'source-reviewed')
                            <span>Source reviewed</span>
                        @else
                            <span>Awaiting review</span>
                        @endif
                        @if (count($npcLinks))
                            <div class="flex flex-wrap gap-x-3 gap-y-1">
                                @foreach ($npcLinks as $npcId => $npcName)
                                    <a href="{{ route('npcs.show', $npcId) }}" class="link-info link-hover">{{ $npcName }}</a>
                                @endforeach
                            </div>
                        @endif
                    </footer>
                @endif
            </article>
        </div>
    </div>
@endsection
