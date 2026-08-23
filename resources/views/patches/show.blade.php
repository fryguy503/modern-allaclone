@extends('layouts.default')

@section('title')
    {{ $patch['title'] }}
@endsection

@section('content')
    <nav class="mb-6 flex flex-wrap items-center gap-2 text-sm text-base-content/50" aria-label="Breadcrumb">
        <a href="{{ route('patches.index') }}" class="hover:text-sky-400">Patch History</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('patches.index', ['year' => $patch['year']]) }}" class="hover:text-sky-400">{{ $patch['year'] }}</a>
        <span aria-hidden="true">/</span>
        <span class="text-base-content/80">{{ $patch['display_date'] }}</span>
    </nav>

    <article x-data="{ tab: 'formatted', copied: false }">
        <header class="patch-detail-header rounded-2xl border border-sky-500/20 bg-base-100 p-6 sm:p-8">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-4xl">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <time datetime="{{ $patch['patch_date'] }}" class="font-mono text-sm font-semibold tracking-wider text-sky-400">{{ $patch['patch_date'] }}</time>
                        @if ($patch['effective_date'] !== $patch['patch_date'])
                            <span class="badge badge-success badge-outline">Effective {{ $patch['effective_date'] }}</span>
                        @endif
                        <span class="badge badge-outline">{{ str($patch['kind'])->headline() }}</span>
                        @if ($patch['year_inferred'])<span class="badge badge-warning badge-outline">Year inferred from source</span>@endif
                    </div>
                    <h2 class="text-3xl font-semibold leading-tight sm:text-4xl">{{ $patch['title'] }}</h2>
                    @if ($patch['display_date'] !== $patch['title'])
                        <p class="mt-2 text-sm text-base-content/50">Original heading: {{ $patch['display_date'] }}</p>
                    @endif
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="{{ route('patches.index', ['expansion' => $patch['expansion_code']]) }}" class="badge badge-lg badge-info badge-outline">{{ $patch['expansion'] }}</a>
                        @foreach ($patch['categories'] as $category)
                            <a href="{{ route('patches.index', ['categories' => [$category['slug']]]) }}" class="badge badge-lg badge-ghost hover:badge-info">{{ $category['label'] }}</a>
                        @endforeach
                    </div>
                </div>
                <div class="relative z-10 flex shrink-0 flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-soft" @click="navigator.clipboard.writeText(window.location.href).then(() => { copied = true; setTimeout(() => copied = false, 1800) })">
                        <span x-text="copied ? 'Copied!' : 'Copy link'">Copy link</span>
                    </button>
                    <a href="{{ route('patches.raw', $patch['slug']) }}" class="btn btn-sm btn-soft">TXT</a>
                    <a href="{{ route('patches.single.json', $patch['slug']) }}" class="btn btn-sm btn-soft btn-info">JSON</a>
                    <a href="{{ route('patches.single.csv', $patch['slug']) }}" class="btn btn-sm btn-soft btn-success">CSV</a>
                </div>
            </div>
            <dl class="mt-6 grid grid-cols-2 gap-3 border-t border-base-content/10 pt-5 text-sm sm:grid-cols-4">
                <div><dt class="text-base-content/45">Words</dt><dd class="font-semibold">{{ number_format($patch['word_count']) }}</dd></div>
                <div><dt class="text-base-content/45">Changes</dt><dd class="font-semibold">{{ number_format($patch['change_count']) }}</dd></div>
                <div><dt class="text-base-content/45">Sections</dt><dd class="font-semibold">{{ count($formattedSections) }}</dd></div>
                <div><dt class="text-base-content/45">Sources</dt><dd class="font-semibold">{{ count($patch['source_files']) }}</dd></div>
            </dl>
        </header>

        <nav class="my-5 grid grid-cols-3 gap-2" aria-label="Patch chronology">
            @if ($adjacent['previous'])
                <a href="{{ route('patches.show', $adjacent['previous']['slug']) }}" class="btn btn-ghost h-auto min-h-14 justify-start bg-base-100 px-3 text-left">
                    <span><span class="block text-[10px] uppercase tracking-wider opacity-45">Older</span><span class="line-clamp-1">{{ $adjacent['previous']['title'] }}</span></span>
                </a>
            @else <span></span> @endif
            <a href="{{ route('patches.index', ['year' => $patch['year']]) }}" class="btn btn-ghost h-auto min-h-14 bg-base-100">{{ $patch['year'] }} index</a>
            @if ($adjacent['next'])
                <a href="{{ route('patches.show', $adjacent['next']['slug']) }}" class="btn btn-ghost h-auto min-h-14 justify-end bg-base-100 px-3 text-right">
                    <span><span class="block text-[10px] uppercase tracking-wider opacity-45">Newer</span><span class="line-clamp-1">{{ $adjacent['next']['title'] }}</span></span>
                </a>
            @else <span></span> @endif
        </nav>

        <div class="grid items-start gap-6 xl:grid-cols-[13rem_minmax(0,1fr)_17rem]">
            <aside class="hidden xl:block">
                <div class="sticky top-20 rounded-xl border border-base-content/10 bg-base-100 p-4">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-sky-400">On this page</p>
                    <ol class="space-y-1.5 text-sm">
                        @foreach ($formattedSections as $section)
                            <li><a href="#{{ $section['anchor'] }}" class="line-clamp-2 text-base-content/60 hover:text-sky-400">{{ $section['title'] }}</a></li>
                        @endforeach
                    </ol>
                </div>
            </aside>

            <div class="min-w-0">
                <div class="tabs tabs-box mb-4 w-fit" role="tablist" aria-label="Patch text view">
                    <button type="button" id="patch-tab-formatted" x-ref="formattedTab" role="tab" aria-controls="patch-panel-formatted"
                        :aria-selected="tab === 'formatted'" :tabindex="tab === 'formatted' ? 0 : -1"
                        class="tab" :class="tab === 'formatted' && 'tab-active'" @click="tab = 'formatted'"
                        @keydown.right.prevent="tab = 'plain'; $nextTick(() => $refs.plainTab.focus())">Formatted</button>
                    <button type="button" id="patch-tab-plain" x-ref="plainTab" role="tab" aria-controls="patch-panel-plain"
                        :aria-selected="tab === 'plain'" :tabindex="tab === 'plain' ? 0 : -1"
                        class="tab" :class="tab === 'plain' && 'tab-active'" @click="tab = 'plain'"
                        @keydown.left.prevent="tab = 'formatted'; $nextTick(() => $refs.formattedTab.focus())">Plain text</button>
                </div>

                <div id="patch-panel-formatted" role="tabpanel" aria-labelledby="patch-tab-formatted"
                    x-show="tab === 'formatted'" class="space-y-5">
                    @foreach ($formattedSections as $section)
                        <section id="{{ $section['anchor'] }}" class="scroll-mt-24 rounded-xl border border-base-content/10 bg-base-100 p-5 sm:p-7">
                            <div class="mb-5 flex items-center gap-3">
                                <span class="h-2 w-2 rotate-45 bg-sky-400" aria-hidden="true"></span>
                                <h3 class="text-xl font-semibold text-sky-400">{{ $section['title'] }}</h3>
                            </div>
                            <div class="patch-prose space-y-4 leading-7 text-base-content/78">
                                @foreach ($section['blocks'] as $block)
                                    @if ($block['type'] === 'paragraph')
                                        <p>{{ $block['text'] }}</p>
                                    @elseif ($block['type'] === 'list')
                                        @if ($block['ordered'])<ol class="list-decimal space-y-2 pl-6">@else<ul class="space-y-2">@endif
                                            @foreach ($block['items'] as $item)
                                                <li @class([
                                                    'patch-bullet' => ! $block['ordered'],
                                                    'ml-6' => $item['depth'] === 1,
                                                    'ml-12' => $item['depth'] === 2,
                                                    'ml-16' => $item['depth'] >= 3,
                                                ])>{{ $item['text'] }}</li>
                                            @endforeach
                                        @if ($block['ordered'])</ol>@else</ul>@endif
                                    @endif
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                </div>

                <div id="patch-panel-plain" role="tabpanel" aria-labelledby="patch-tab-plain" x-show="tab === 'plain'" x-cloak>
                    <pre class="whitespace-pre-wrap break-words rounded-xl border border-base-content/10 bg-neutral p-5 font-mono text-sm leading-6 text-neutral-content/80 sm:p-7">{{ $patch['content'] }}</pre>
                </div>

                <section class="mt-6 rounded-xl border border-base-content/10 bg-base-100 p-5 sm:p-6">
                    <h3 class="text-sm font-semibold uppercase tracking-wider text-sky-400">Record provenance</h3>
                    <p class="mt-3 break-all font-mono text-xs text-base-content/45">SHA-256 {{ $patch['content_hash'] }}</p>
                    <p class="mt-3 text-sm text-base-content/60">
                        {{ number_format($patch['occurrence_count']) }} source {{ \Illuminate\Support\Str::plural('occurrence', $patch['occurrence_count']) }} retained across
                        {{ count($patch['source_files']) }} unique {{ \Illuminate\Support\Str::plural('file', count($patch['source_files'])) }}.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($patch['source_files'] as $source)
                            <span class="badge badge-ghost">{{ $source }}</span>
                        @endforeach
                    </div>
                    @if ($patch['source_urls'])
                        <div class="mt-4 space-y-1 text-sm">
                            @foreach ($patch['source_urls'] as $url)
                                @if (filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))
                                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="block truncate link-accent link-hover">Original source ↗</a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </section>

                @if ($related)
                    <section class="mt-8">
                        <div class="divider uppercase text-lg font-bold text-sky-400">Related patches</div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($related as $item)
                                <a href="{{ route('patches.show', $item['slug']) }}" class="rounded-xl border border-base-content/10 bg-base-100 p-4 transition hover:border-sky-400/30">
                                    <time class="font-mono text-xs text-sky-400">{{ $item['patch_date'] }}</time>
                                    <p class="mt-1 line-clamp-2 font-semibold">{{ $item['title'] }}</p>
                                    <p class="mt-2 line-clamp-2 text-xs text-base-content/50">{{ $item['summary'] }}</p>
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($onThisDay)
                    <section class="mt-6 rounded-xl border border-base-content/10 bg-base-100 p-5">
                        <h3 class="font-semibold text-sky-400">On this day in Norrath</h3>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($onThisDay as $item)
                                <a href="{{ route('patches.show', $item['slug']) }}" class="badge badge-lg badge-ghost hover:badge-info">{{ $item['year'] }} · {{ $item['title'] }}</a>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <aside>
                <div class="sticky top-20 rounded-xl border border-base-content/10 bg-base-100 p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-xs font-semibold uppercase tracking-wider text-sky-400">{{ $patch['year'] }} archive</p>
                        <span class="badge badge-sm badge-ghost">{{ array_sum(array_map('count', $yearArchive)) }}</span>
                    </div>
                    <div class="max-h-[70vh] space-y-1 overflow-y-auto pr-1 scrollbar-thin">
                        @foreach ($yearArchive as $month => $items)
                            <details class="collapse collapse-arrow bg-base-200" @if($month === $patch['month']) open @endif>
                                <summary class="collapse-title min-h-0 py-2 text-sm font-semibold">{{ DateTimeImmutable::createFromFormat('!m', $month)->format('F') }} <span class="opacity-40">{{ count($items) }}</span></summary>
                                <div class="collapse-content pb-2 text-xs">
                                    @foreach ($items as $item)
                                        <a href="{{ route('patches.show', $item['slug']) }}" class="block rounded px-2 py-1.5 {{ $item['slug'] === $patch['slug'] ? 'bg-sky-500/15 text-sky-400' : 'text-base-content/60 hover:bg-base-300' }}">{{ $item['title'] }}</a>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            </aside>
        </div>
    </article>
@endsection
