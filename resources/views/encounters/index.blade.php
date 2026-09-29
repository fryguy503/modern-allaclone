@extends('layouts.default')

@section('title', 'Encounter Journal')
@section('content-padding', 'p-4 sm:p-10')

@section('content')
    <div class="encounter-journal">
        @include('encounters.partials.draft-banner')

        <section class="journal-hero mb-7">
            <div class="journal-hero-copy">
                <p class="journal-eyebrow">The Library / Field guides</p>
                <h2 class="mt-3 text-3xl font-semibold leading-tight sm:text-4xl">Know what awaits.</h2>
                <p class="mt-4 max-w-2xl leading-7 text-base-content/75">Explore selected encounters, learn their mechanics, and plan your role. Each journal brings the phases, abilities, and rewards together in one place.</p>
            </div>
            <div class="journal-hero-emblem" aria-hidden="true">
                @include('encounters.partials.icon', ['name' => 'book', 'class' => 'h-14 w-14'])
            </div>
        </section>

        <form method="get" action="{{ route('encounters.index') }}" class="journal-filters mb-7" aria-label="Find encounters">
            <div class="journal-filter-grid">
                <label class="journal-field">
                    <span>Search encounters</span>
                    <input class="input w-full bg-base-200" type="search" name="q" value="{{ $filters['q'] }}"
                        maxlength="150" placeholder="Encounter or event name" autocomplete="off">
                </label>
                <label class="journal-field">
                    <span>Zone</span>
                    <select class="select w-full bg-base-200" name="zone">
                        <option value="">All zones</option>
                        @foreach ($zones as $shortName => $name)
                            <option value="{{ $shortName }}" @selected($filters['zone'] === $shortName)>{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="journal-field">
                    <span>Encounter type</span>
                    <select class="select w-full bg-base-200" name="type">
                        <option value="">All types</option>
                        @foreach ($types as $type => $label)
                            <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="btn btn-info">Find encounters</button>
            </div>
        </form>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-base-content/65">
                <span class="font-semibold text-base-content">{{ number_format($entries->total()) }}</span>
                {{ $entries->total() === 1 ? 'encounter' : 'encounters' }}
                @if (filled($filters['q']) || filled($filters['zone']) || filled($filters['type'])) matching your filters @endif
            </p>
            @if (filled($filters['q']) || filled($filters['zone']) || filled($filters['type']))
                <a href="{{ route('encounters.index') }}" class="link link-info text-sm">Clear filters</a>
            @endif
        </div>

        <div class="journal-card-grid">
            @forelse ($entries as $entry)
                <article class="journal-card">
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <span class="journal-card-icon">@include('encounters.partials.icon', ['name' => 'shield', 'class' => 'h-6 w-6'])</span>
                        <div class="flex flex-wrap justify-end gap-2">
                            <span class="badge badge-outline badge-sm">{{ $types[$entry['type']] ?? ucfirst($entry['type']) }}</span>
                            @if ($entry['status'] === 'draft')<span class="badge badge-warning badge-outline badge-sm">Draft</span>@endif
                            @if ($entry['spoiler'])<span class="badge badge-outline badge-sm">Spoilers</span>@endif
                        </div>
                    </div>
                    <p class="journal-eyebrow">{{ $entry['zone']['name'] }}</p>
                    <h2 class="mt-2 text-xl font-semibold leading-snug">
                        <a href="{{ route('encounters.show', $entry['slug']) }}" class="journal-card-link">{{ $entry['title'] }}</a>
                    </h2>
                    @if (filled($entry['group']))<p class="mt-1 text-sm text-base-content/60">{{ $entry['group'] }}</p>@endif
                    <p class="mt-4 grow text-sm leading-6 text-base-content/75">{{ $entry['summary'] }}</p>
                    <div class="journal-card-footer">
                        <span>Version {{ $entry['zone']['version'] }}</span>
                        <span class="inline-flex items-center gap-2 text-info">Open journal @include('encounters.partials.icon', ['name' => 'arrow', 'class' => 'h-4 w-4'])</span>
                    </div>
                </article>
            @empty
                <div class="journal-empty">
                    <span class="mb-4 inline-flex text-info">@include('encounters.partials.icon', ['name' => 'book', 'class' => 'h-9 w-9'])</span>
                    <h2 class="text-xl font-semibold">No encounters found</h2>
                    <p class="mt-2 text-base-content/65">
                        @if (filled($filters['q']) || filled($filters['zone']) || filled($filters['type']))
                            Try a different name or clear the filters to explore all available journals.
                        @else
                            New encounter guides will appear here as they are added to the Library.
                        @endif
                    </p>
                    @if (filled($filters['q']) || filled($filters['zone']) || filled($filters['type']))
                        <a href="{{ route('encounters.index') }}" class="btn btn-sm btn-soft btn-info mt-5">View all encounters</a>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($entries->hasPages())<div class="mt-7">{{ $entries->links() }}</div>@endif
    </div>
@endsection
