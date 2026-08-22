@extends('layouts.default')
@inject('patchFormatter', 'App\Services\PatchContentFormatter')

@section('title', 'Patch History')

@section('content')
    @php
        $hasFilters = filled($filters['q']) || filled($filters['from']) || filled($filters['to']) || filled($filters['year'])
            || filled($filters['kind']) || filled($filters['expansion']) || filled($filters['source']) || count($filters['categories']);
        $exportQuery = $hasFilters ? array_filter([
            'q' => $filters['q'], 'from' => $filters['from'], 'to' => $filters['to'], 'year' => $filters['year'],
            'kind' => $filters['kind'], 'expansion' => $filters['expansion'], 'source' => $filters['source'],
            'categories' => $filters['categories'], 'scope' => $filters['scope'], 'sort' => $filters['sort'],
        ], fn ($value) => $value !== null && $value !== '' && $value !== []) : [];
    @endphp

    <section class="patch-hero mb-8 overflow-hidden rounded-2xl border border-sky-500/20 bg-base-100">
        <div class="relative z-10 grid gap-8 p-6 lg:grid-cols-[1.4fr_1fr] lg:p-9">
            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-[0.22em] text-sky-400">
                    <span>Norrath Chronicle</span>
                    <span aria-hidden="true" class="text-base-content/30">◆</span>
                    <span>{{ $coverage['first_patch'] }} — {{ $coverage['last_patch'] }}</span>
                </div>
                <h2 class="max-w-3xl text-3xl font-semibold leading-tight text-base-content sm:text-4xl">
                    Every era. Every fix. One searchable history.
                </h2>
                <p class="mt-4 max-w-2xl text-base leading-relaxed text-base-content/70">
                    Explore the complete supplied EverQuest beta and Live patch archive, safely formatted and cross-indexed by era, expansion, topic, date, and source.
                </p>
                <div class="mt-6 flex flex-wrap gap-2">
                    <a href="{{ route('patches.export.json', $exportQuery) }}" class="btn btn-sm btn-soft btn-info">{{ $hasFilters ? 'Export results as JSON' : 'Full JSON export' }}</a>
                    <a href="{{ route('patches.export.csv', $exportQuery) }}" class="btn btn-sm btn-soft btn-success">{{ $hasFilters ? 'Export results as CSV' : 'Full CSV export' }}</a>
                    <a href="{{ route('patches.feed') }}" class="btn btn-sm btn-soft">RSS feed</a>
                    <a href="{{ route('patches.sources') }}" class="btn btn-sm btn-ghost">Sources & methodology</a>
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-3 self-end">
                <div class="patch-stat rounded-xl border border-base-content/10 bg-base-200/80 p-4">
                    <dt class="text-xs uppercase tracking-wider text-base-content/50">Records</dt>
                    <dd class="mt-1 text-2xl font-semibold text-sky-400">{{ number_format($totals['patches']) }}</dd>
                </div>
                <div class="patch-stat rounded-xl border border-base-content/10 bg-base-200/80 p-4">
                    <dt class="text-xs uppercase tracking-wider text-base-content/50">Years</dt>
                    <dd class="mt-1 text-2xl font-semibold">{{ number_format($totals['years']) }}</dd>
                </div>
                <div class="patch-stat rounded-xl border border-base-content/10 bg-base-200/80 p-4">
                    <dt class="text-xs uppercase tracking-wider text-base-content/50">Documented changes</dt>
                    <dd class="mt-1 text-2xl font-semibold">{{ number_format($totals['changes']) }}</dd>
                </div>
                <div class="patch-stat rounded-xl border border-base-content/10 bg-base-200/80 p-4">
                    <dt class="text-xs uppercase tracking-wider text-base-content/50">Source files</dt>
                    <dd class="mt-1 text-2xl font-semibold">{{ number_format($totals['sources']) }}</dd>
                </div>
            </dl>
        </div>
    </section>

    @if ($errors->any())
        <div class="alert alert-error mb-6" role="alert">
            <span>One or more filters were invalid. Check the dates and selected values, then try again.</span>
        </div>
    @endif

    <form method="get" action="{{ route('patches.index') }}" class="mb-7 rounded-2xl border border-base-content/10 bg-base-100 p-4 shadow-sm sm:p-6">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
            <label class="input input-lg w-full bg-base-200">
                <span class="label text-sky-400">Search</span>
                <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="120"
                    placeholder='Try “Plane of Time”, necromancer, or exact phrases' autocomplete="off" />
            </label>
            <label class="select select-lg w-full bg-base-200 lg:w-40">
                <span class="label">Scope</span>
                <select name="scope">
                    <option value="all" @selected($filters['scope'] === 'all')>All text</option>
                    <option value="title" @selected($filters['scope'] === 'title')>Titles</option>
                    <option value="body" @selected($filters['scope'] === 'body')>Body only</option>
                </select>
            </label>
            <button type="submit" class="btn btn-lg btn-info px-8">Search archive</button>
        </div>

        <details class="group mt-4" @if($hasFilters) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-lg px-1 py-2 text-sm font-semibold text-base-content/70 hover:text-sky-400">
                <span>Advanced filters</span>
                <span class="transition group-open:rotate-45" aria-hidden="true">＋</span>
            </summary>
            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <label class="input w-full bg-base-200">
                    <span class="label">From</span>
                    <input type="date" name="from" value="{{ $filters['from'] }}" min="{{ $coverage['first_patch'] }}" max="{{ $coverage['last_patch'] }}" />
                </label>
                <label class="input w-full bg-base-200">
                    <span class="label">To</span>
                    <input type="date" name="to" value="{{ $filters['to'] }}" min="{{ $coverage['first_patch'] }}" max="{{ $coverage['last_patch'] }}" />
                </label>
                <label class="select w-full bg-base-200">
                    <span class="label">Year</span>
                    <select name="year">
                        <option value="">All years</option>
                        @foreach ($facets['years'] as $year => $count)
                            <option value="{{ $year }}" @selected((string) $filters['year'] === (string) $year)>{{ $year }} ({{ $count }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="select w-full bg-base-200">
                    <span class="label">Record type</span>
                    <select name="kind">
                        <option value="">All types</option>
                        @foreach ($facets['kinds'] as $kind => $count)
                            <option value="{{ $kind }}" @selected($filters['kind'] === $kind)>{{ str($kind)->headline() }} ({{ $count }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="select w-full bg-base-200 md:col-span-2">
                    <span class="label">Expansion era</span>
                    <select name="expansion">
                        <option value="">All expansion eras</option>
                        @foreach ($facets['expansions'] as $code => $expansion)
                            <option value="{{ $code }}" @selected($filters['expansion'] === $code)>{{ $expansion['label'] }} ({{ $expansion['count'] }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="select w-full bg-base-200 md:col-span-2">
                    <span class="label">Source file</span>
                    <select name="source">
                        <option value="">All source files</option>
                        @foreach ($facets['sources'] as $source => $count)
                            <option value="{{ $source }}" @selected($filters['source'] === $source)>{{ $source }} ({{ $count }})</option>
                        @endforeach
                    </select>
                </label>
                <label class="select w-full bg-base-200">
                    <span class="label">Sort</span>
                    <select name="sort">
                        @if (filled($filters['q']))
                            <option value="relevance" @selected($filters['sort'] === 'relevance')>Most relevant</option>
                        @endif
                        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
                    </select>
                </label>
                <label class="select w-full bg-base-200">
                    <span class="label">Results</span>
                    <select name="per_page">
                        @foreach ([12, 24, 48] as $size)
                            <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} per page</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <fieldset class="mt-5">
                <legend class="mb-2 text-xs font-semibold uppercase tracking-wider text-base-content/50">Topics — select up to six</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach ($facets['categories'] as $slug => $category)
                        <label class="patch-filter-chip cursor-pointer">
                            <input type="checkbox" name="categories[]" value="{{ $slug }}" class="sr-only"
                                @checked(in_array($slug, $filters['categories'], true)) />
                            <span class="badge badge-lg border-base-content/15 bg-base-200 transition hover:border-sky-400/50">
                                {{ $category['label'] }} <span class="opacity-50">{{ $category['count'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="mt-5 flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-info">Apply filters</button>
                <a href="{{ route('patches.index') }}" class="btn btn-sm btn-ghost">Clear everything</a>
            </div>
        </details>
    </form>

    <nav class="mb-6" aria-label="Jump to a year">
        <div class="scrollbar-thin flex gap-2 overflow-x-auto pb-2">
            <a href="{{ route('patches.index', request()->except(['year', 'page'])) }}"
                class="btn btn-sm shrink-0 {{ blank($filters['year']) ? 'btn-info' : 'btn-ghost bg-base-100' }}">All years</a>
            @foreach ($facets['years'] as $year => $count)
                <a href="{{ route('patches.index', array_merge(request()->except('page'), ['year' => $year])) }}"
                    class="btn btn-sm shrink-0 {{ (string) $filters['year'] === (string) $year ? 'btn-info' : 'btn-ghost bg-base-100' }}">
                    {{ $year }} <span class="text-xs opacity-50">{{ $count }}</span>
                </a>
            @endforeach
        </div>
    </nav>

    <div class="mb-5 flex flex-col gap-3 border-b border-base-content/10 pb-5 sm:flex-row sm:items-end sm:justify-between">
        <div aria-live="polite">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-400">Archive results</p>
            <h3 class="mt-1 text-2xl font-semibold">
                {{ number_format($patches->total()) }} {{ \Illuminate\Support\Str::plural('record', $patches->total()) }}
                @if (filled($filters['q']))
                    <span class="text-base font-normal text-base-content/50">for “{{ $filters['q'] }}”</span>
                @endif
            </h3>
        </div>
        <div class="join" aria-label="Result layout">
            @foreach (['cards' => 'Cards', 'compact' => 'Compact', 'expansions' => 'Expansions', 'timeline' => 'Timeline'] as $view => $label)
                <a href="{{ route('patches.index', array_merge(request()->except(['page', 'view']), ['view' => $view])) }}"
                    class="join-item btn btn-sm {{ $filters['view'] === $view ? 'btn-active btn-info' : 'btn-ghost bg-base-100' }}"
                    aria-current="{{ $filters['view'] === $view ? 'true' : 'false' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    @if ($patches->isEmpty())
        <div class="flex min-h-72 flex-col items-center justify-center rounded-2xl border border-dashed border-base-content/20 bg-base-100 p-8 text-center">
            <div class="text-4xl text-sky-400" aria-hidden="true">◇</div>
            <h3 class="mt-3 text-xl font-semibold">No patch notes matched</h3>
            <p class="mt-2 max-w-md text-base-content/60">Try a wider date range, fewer topic filters, or a shorter search phrase.</p>
            <a href="{{ route('patches.index') }}" class="btn btn-sm btn-info mt-5">Return to the full archive</a>
        </div>
    @elseif ($filters['view'] === 'compact')
        <div class="overflow-x-auto rounded-xl border border-base-content/10 bg-base-100">
            <table class="table table-zebra">
                <thead>
                    <tr><th>Date</th><th>Patch</th><th>Era</th><th class="text-right">Size</th></tr>
                </thead>
                <tbody>
                    @foreach ($patches as $patch)
                        <tr>
                            <td class="whitespace-nowrap font-mono text-xs text-base-content/60">{{ $patch['patch_date'] }}</td>
                            <td>
                                <a href="{{ route('patches.show', $patch['slug']) }}" class="font-semibold link-accent link-hover">{{ $patch['title'] }}</a>
                                @if (filled($filters['q']))<p class="mt-1 max-w-4xl text-xs text-base-content/55">{!! $patchFormatter->highlight($patch['_excerpt'], $filters['q']) !!}</p>@endif
                            </td>
                            <td><span class="badge badge-sm badge-ghost">{{ $patch['expansion'] }}</span></td>
                            <td class="whitespace-nowrap text-right text-xs text-base-content/50">{{ number_format($patch['word_count']) }} words</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($filters['view'] === 'expansions')
        <div class="space-y-8">
            @foreach ($expansionGroups as $expansionCode => $items)
                <section>
                    <div class="mb-3 flex flex-wrap items-end justify-between gap-2 border-b border-sky-400/20 pb-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-400">Expansion era</p>
                            <h4 class="mt-1 text-2xl font-semibold">{{ $items->first()['expansion'] }}</h4>
                        </div>
                        <a href="{{ route('patches.index', array_merge(request()->except(['page', 'view', 'expansion']), ['expansion' => $expansionCode, 'view' => 'cards'])) }}" class="btn btn-xs btn-ghost">
                            {{ number_format($items->count()) }} matching · {{ number_format($facets['expansions'][$expansionCode]['count']) }} total
                        </a>
                    </div>
                    <div class="grid gap-3 xl:grid-cols-2">
                        @foreach ($items->take(6) as $patch)
                            <article class="group relative rounded-xl border border-base-content/10 bg-base-100 p-5 transition hover:border-sky-400/30">
                                <div class="flex items-center justify-between gap-3">
                                    <time datetime="{{ $patch['patch_date'] }}" class="font-mono text-xs font-semibold text-sky-400">{{ $patch['patch_date'] }}</time>
                                    <span class="badge badge-sm badge-ghost">{{ str($patch['kind'])->headline() }}</span>
                                </div>
                                <h5 class="mt-2 text-lg font-semibold leading-snug">
                                    <a href="{{ route('patches.show', $patch['slug']) }}" class="after:absolute after:inset-0 group-hover:text-sky-400">{{ $patch['title'] }}</a>
                                </h5>
                                <p class="mt-2 line-clamp-3 text-sm leading-relaxed text-base-content/60">{!! $patchFormatter->highlight($patch['_excerpt'], $filters['q']) !!}</p>
                            </article>
                        @endforeach
                    </div>
                    @if ($items->count() > 6)
                        <a href="{{ route('patches.index', array_merge(request()->except(['page', 'view', 'expansion']), ['expansion' => $expansionCode, 'view' => 'cards'])) }}" class="btn btn-sm btn-ghost mt-3">
                            Browse all {{ number_format($items->count()) }} matching records →
                        </a>
                    @endif
                </section>
            @endforeach
        </div>
    @else
        <div class="patch-results relative grid gap-4 {{ $filters['view'] === 'cards' ? 'xl:grid-cols-2' : '' }}">
            @php $currentYear = null; @endphp
            @foreach ($patches as $patch)
                @if ($filters['view'] === 'timeline' && $currentYear !== $patch['year'])
                    @php $currentYear = $patch['year']; @endphp
                    <div class="patch-year-marker sticky top-16 z-10 -mx-1 flex items-center gap-3 bg-base-200/95 px-1 py-2 backdrop-blur">
                        <span class="text-2xl font-semibold text-sky-400">{{ $currentYear }}</span>
                        <span class="h-px grow bg-sky-400/20"></span>
                    </div>
                @endif
                <article class="patch-card group relative overflow-hidden rounded-xl border border-base-content/10 bg-base-100 p-5 transition hover:-translate-y-0.5 hover:border-sky-400/30 hover:shadow-lg hover:shadow-sky-950/10 sm:p-6">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <time datetime="{{ $patch['patch_date'] }}" class="font-mono text-xs font-semibold uppercase tracking-wider text-sky-400">{{ $patch['patch_date'] }}</time>
                        <div class="flex gap-2">
                            <span class="badge badge-sm badge-ghost">{{ str($patch['kind'])->headline() }}</span>
                            @if ($patch['year_inferred'])<span class="badge badge-sm badge-warning badge-outline" title="The year was inferred from the source file">Inferred year</span>@endif
                        </div>
                    </div>
                    <h4 class="text-xl font-semibold leading-snug">
                        <a href="{{ route('patches.show', $patch['slug']) }}" class="after:absolute after:inset-0 group-hover:text-sky-400">{{ $patch['title'] }}</a>
                    </h4>
                    <p class="mt-3 line-clamp-4 leading-relaxed text-base-content/65">
                        {!! $patchFormatter->highlight($patch['_excerpt'], $filters['q']) !!}
                    </p>
                    <div class="relative z-10 mt-4 flex flex-wrap gap-1.5">
                        <a href="{{ route('patches.index', array_merge(request()->except(['page', 'expansion']), ['expansion' => $patch['expansion_code']])) }}" class="badge badge-info badge-outline">{{ $patch['expansion'] }}</a>
                        @foreach (array_slice($patch['categories'], 0, 4) as $category)
                            @php $categoryFilters = array_values(array_unique([...$filters['categories'], $category['slug']])); @endphp
                            <a href="{{ route('patches.index', array_merge(request()->except(['page', 'categories']), ['categories' => $categoryFilters])) }}" class="badge badge-ghost hover:badge-info">{{ $category['label'] }}</a>
                        @endforeach
                    </div>
                    <div class="mt-5 flex items-center gap-4 border-t border-base-content/8 pt-3 text-xs text-base-content/45">
                        <span>{{ number_format($patch['word_count']) }} words</span>
                        <span>{{ number_format($patch['change_count']) }} changes</span>
                        <span>{{ count($patch['source_files']) }} {{ \Illuminate\Support\Str::plural('source', count($patch['source_files'])) }}</span>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($filters['view'] !== 'expansions' && $patches->hasPages())
        <div class="mt-8">{{ $patches->onEachSide(1)->links() }}</div>
    @endif

    <p class="mt-6 text-center text-xs text-base-content/40">
        Exports include full patch bodies and provenance. Filtered exports preserve the current search and facets; clear filters to download all {{ number_format($totals['patches']) }} records.
    </p>
@endsection
