@extends('layouts.default')

@section('title')
    @if (!empty($spellSummary['icon']))
        <img src="{{ asset('img/icons/' . $spellSummary['icon'] . '.png') }}" alt=""
            class="inline-block w-7 h-7 mr-2">
    @endif
    {{ $spellSummary['name'] }} History
@endsection

@section('content')
    @php
        $currentPage = max(1, (int) data_get($pagination, 'current_page', 1));
        $hasPrevious = (bool) data_get($pagination, 'has_previous', $currentPage > 1);
        $hasMore = (bool) data_get($pagination, 'has_more', false);
        $revisionCount = data_get($archive, 'revision_count');
        $snapshotCount = data_get($archive, 'snapshot_count');
        $firstCapture = data_get($archive, 'first_captured_label', data_get($archive, 'first_captured_at'));
        $lastCapture = data_get($archive, 'last_captured_label', data_get($archive, 'last_captured_at'));
        $baselineConfigured = (bool) data_get($baseline, 'configured', false);
        $baselineInvalid = (bool) data_get($baseline, 'invalid', false);
        $baselineExact = (bool) data_get($baseline, 'exact', false);
        $configuredCapture = data_get($baseline, 'configured_captured_label', data_get($baseline, 'configured_captured_at'));
        $matchedCapture = data_get($baseline, 'matched_captured_label', data_get($baseline, 'matched_captured_at'));
        $baselinePage = (int) data_get($baseline, 'page', 0);
        $baselinePresenceStatus = (string) data_get(
            $baseline,
            'presence_status',
            $matchedCapture ? 'present' : 'no_snapshot',
        );
        $historyView = in_array($historyView ?? 'table', ['cards', 'table', 'lucy'], true)
            ? $historyView
            : 'table';
        $displayValue = static function ($value): string {
            if ($value === null) {
                return 'Not set';
            }
            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Not set';
            }

            return (string) $value !== '' ? (string) $value : '(empty)';
        };
        $historyPageUrl = static function (int $page) use ($spellSummary, $historyView): string {
            $parameters = ['spell' => $spellSummary['id']];
            if ($historyView !== 'table') {
                $parameters['view'] = $historyView;
            }
            if ($page > 1) {
                $parameters['page'] = $page;
            }

            return route('spells.history', $parameters);
        };
        $historyViewUrl = static function (string $view) use ($spellSummary, $currentPage): string {
            $parameters = ['spell' => $spellSummary['id']];
            if ($view !== 'table') {
                $parameters['view'] = $view;
            }
            if ($currentPage > 1) {
                $parameters['page'] = $currentPage;
            }

            return route('spells.history', $parameters);
        };
    @endphp

    @include('spells.partials.history-tabs', [
        'spellId' => $spellSummary['id'],
        'activeTab' => 'history',
    ])

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(18rem,0.85fr)]">
        <section class="card bg-base-300 shadow-sm" aria-labelledby="spell-history-archive-heading">
            <div class="card-body p-5 md:p-6">
                <p class="text-xs font-semibold uppercase tracking-wider text-accent">Lucy Live spell archive</p>
                <h2 id="spell-history-archive-heading" class="card-title mt-1 text-2xl">
                    {{ $spellSummary['name'] }} revisions
                </h2>
                <p class="max-w-3xl text-sm text-base-content/65">
                    Details shows the spell in this server's EQEmu database. This page shows imported Lucy Live
                    captures, which can differ from custom server data.
                </p>

                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                    @if ($revisionCount !== null)
                        <span class="badge badge-soft badge-info">{{ number_format((int) $revisionCount) }} revisions</span>
                    @endif
                    @if ($snapshotCount !== null)
                        <span class="badge badge-soft">{{ number_format((int) $snapshotCount) }} source captures</span>
                    @endif
                    @if ($firstCapture && $lastCapture)
                        <span class="badge badge-outline">{{ $firstCapture }} &ndash; {{ $lastCapture }}</span>
                    @endif
                </div>

                <p class="mt-1 text-xs text-base-content/55">
                    This timeline reports comparable semantic values first observed between captures. Exporter-only
                    formatting and a field's first appearance in a changed export schema are not inferred as spell
                    changes. Source timestamps are shown as recorded, without timezone conversion; a capture time is
                    not necessarily the exact patch time.
                </p>
            </div>
        </section>

        <aside class="card bg-base-100 shadow-sm" aria-labelledby="spell-history-server-context-heading">
            <div class="card-body gap-4 p-5 md:p-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-base-content/55">Current server progression</p>
                    <h2 id="spell-history-server-context-heading" class="mt-1 text-lg font-semibold">
                        {{ $progression['expansion_name'] }}
                    </h2>
                    <span class="badge badge-sm badge-soft badge-accent">Expansion {{ $progression['expansion_id'] }}</span>
                    <p class="mt-2 text-xs text-base-content/55">
                        Progression controls available server content. It does not by itself identify an exact Lucy capture.
                    </p>
                </div>

                <div class="border-t border-base-content/10 pt-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-base-content/55">Spell-data baseline</p>
                    @if ($baselineConfigured)
                        @if ($baselineInvalid)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="badge badge-sm badge-error badge-soft">Invalid configured cutoff</span>
                            </div>
                        @elseif ($matchedCapture)
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="badge badge-sm {{ $baselineExact ? 'badge-success' : 'badge-warning' }} badge-soft">
                                    {{ $baselineExact ? 'Exact configured capture' : 'Resolved capture' }}
                                </span>
                            </div>
                        @else
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="badge badge-sm badge-warning badge-soft">No available capture at or before this cutoff</span>
                            </div>
                        @endif
                        @if ($configuredCapture)
                            <p class="mt-2 text-sm"><strong>Configured cutoff:</strong> {{ $configuredCapture }}</p>
                        @endif
                        @if ($matchedCapture)
                            <p class="mt-1 text-sm"><strong>Resolved capture:</strong> {{ $matchedCapture }}</p>
                        @endif
                        @if (!$baselineInvalid && $matchedCapture)
                            <div class="mt-2">
                                @if ($baselinePresenceStatus === 'present')
                                    <span class="badge badge-sm badge-success badge-soft">Spell present at resolved capture</span>
                                @elseif ($baselinePresenceStatus === 'not_yet_observed')
                                    <span class="badge badge-sm badge-warning badge-soft">Spell not yet observed at this cutoff</span>
                                @elseif ($baselinePresenceStatus === 'not_observed')
                                    <span class="badge badge-sm badge-warning badge-soft">Spell not observed at resolved capture</span>
                                @elseif ($baselinePresenceStatus === 'uncertain_single_capture_gap')
                                    <span class="badge badge-sm badge-warning badge-soft">Availability uncertain at this cutoff</span>
                                @endif
                            </div>
                        @endif
                        @if ($baselineInvalid)
                            <p class="mt-2 text-xs text-error/80">
                                Use YYYY-MM-DD or YYYY-MM-DDTHH:MM:SS. No revision is highlighted while this setting is invalid.
                            </p>
                        @elseif ($matchedCapture)
                            <p class="mt-2 text-xs text-base-content/55">
                                Using the latest available capture at or before the configured cutoff. A date-only cutoff
                                includes captures through the end of that day; an explicit timestamp resolves through that
                                exact second.
                            </p>
                            @if ($baselinePresenceStatus === 'present')
                                <p class="mt-2 text-xs text-base-content/55">
                                    The highlighted state is a Live-data reference and does not prove that custom EQEmu
                                    values exactly match Lucy.
                                </p>
                            @elseif ($baselinePresenceStatus === 'not_observed')
                                <p class="mt-2 text-xs text-base-content/55">
                                    Lucy omitted this spell from consecutive captures at the cutoff. Prior revisions are
                                    last-known values, not the spell state at that capture, so none is highlighted.
                                </p>
                            @elseif ($baselinePresenceStatus === 'uncertain_single_capture_gap')
                                <p class="mt-2 text-xs text-base-content/55">
                                    This spell is missing from one Lucy capture. No disappearance is inferred and no prior
                                    revision is presented as the state at the cutoff.
                                </p>
                            @elseif ($baselinePresenceStatus === 'not_yet_observed')
                                <p class="mt-2 text-xs text-base-content/55">
                                    This spell first appears later in the archive, so no revision is highlighted here.
                                </p>
                            @endif
                        @else
                            <p class="mt-2 text-xs text-base-content/55">
                                The configured cutoff predates the first available Lucy capture. No revision is highlighted.
                            </p>
                        @endif
                        @if (!$baselineInvalid && $matchedCapture && $baselinePage >= 1 && $baselinePage <= $maximumPage && $baselinePage !== $currentPage)
                            <a href="{{ $historyPageUrl($baselinePage) }}"
                                class="btn btn-xs btn-soft btn-accent mt-3">Jump to highlighted capture</a>
                        @endif
                    @else
                        <div role="status" class="alert alert-warning alert-soft mt-2 py-3">
                            <span class="text-sm">
                                No spell-data cutoff is configured, so no revision is presented as the server version.
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </aside>
    </div>

    <section class="mt-6" aria-labelledby="spell-history-timeline-heading">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h2 id="spell-history-timeline-heading" class="text-xl font-bold text-sky-400">Captured revisions</h2>
                <p class="text-sm text-base-content/55">Newest captures are shown first. Unchanged source captures are omitted.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <nav class="join" aria-label="History display view">
                    @foreach ([
                        'cards' => ['label' => 'Cards', 'title' => 'Collapsible revision cards'],
                        'table' => ['label' => 'Diff table', 'title' => 'Structured before-and-after comparison'],
                        'lucy' => ['label' => 'Lucy list', 'title' => 'Compact date and change listing'],
                    ] as $viewKey => $viewOption)
                        <a href="{{ $historyViewUrl($viewKey) }}"
                            class="join-item btn btn-sm {{ $historyView === $viewKey ? 'btn-active btn-accent' : 'btn-soft' }}"
                            title="{{ $viewOption['title'] }}"
                            data-history-view-option="{{ $viewKey }}"
                            @if ($historyView === $viewKey) aria-current="page" @endif>
                            {{ $viewOption['label'] }}
                        </a>
                    @endforeach
                </nav>
                <span class="text-sm tabular-nums text-base-content/55">Page {{ $currentPage }}</span>
            </div>
        </div>

        @if ($revisions->isEmpty())
            <div role="status" class="alert alert-info alert-soft mt-4">
                <span>No recorded field changes were found for this spell.</span>
            </div>
        @elseif ($historyView === 'table')
            @include('spells.partials.history-views.diff-table')
        @elseif ($historyView === 'lucy')
            @include('spells.partials.history-views.lucy-list')
        @else
            <ol class="mt-4 space-y-4 border-l border-base-content/10 pl-4 md:ml-3 md:pl-6"
                data-history-view="cards">
                @foreach ($revisions as $revision)
                    @php
                        $changes = collect(data_get($revision, 'changes', []));
                        [$technicalChanges, $playerChanges] = $changes->partition(function ($change) {
                            $category = data_get($change, 'category');
                            $field = (string) data_get($change, 'field', '');

                            return in_array($category, ['technical', 'advanced'], true)
                                || str_starts_with($field, 'unknown');
                        });
                        $isBaseline = (bool) data_get($revision, 'is_baseline', false);
                        $relativeToBaseline = data_get($revision, 'relative_to_baseline');
                        $revisionType = (string) data_get($revision, 'type', 'changed');
                        $revisionTypeLabel = match ($revisionType) {
                            'first_observed' => 'First observed',
                            'presence_missing', 'removed' => 'Not observed',
                            'presence_restored', 'restored' => 'Observed again',
                            default => null,
                        };
                        $capturedLabel = data_get(
                            $revision,
                            'captured_label',
                            data_get($revision, 'captured_at', 'Unknown capture'),
                        );
                        $eraLabel = data_get($revision, 'era_label');
                        $changeCount = (int) data_get($revision, 'change_count', $changes->count());
                        $expanded = $isBaseline || $loop->first;
                    @endphp

                    <li class="relative">
                        <span aria-hidden="true"
                            class="absolute -left-[1.32rem] top-6 h-3 w-3 rounded-full border-2 border-base-200 md:-left-[1.82rem]
                                {{ $isBaseline ? 'bg-accent ring-4 ring-accent/15' : 'bg-base-content/30' }}"></span>

                        <details class="collapse collapse-arrow border shadow-sm
                                {{ $isBaseline ? 'border-accent bg-accent/5' : 'border-base-content/10 bg-base-100' }}"
                            @if ($expanded) open @endif>
                            <summary class="collapse-title pr-12">
                                <span class="flex w-full flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <span class="block">
                                        <span class="block font-semibold tabular-nums">Captured {{ $capturedLabel }}</span>
                                        <span class="mt-1 flex flex-wrap gap-1.5">
                                            @if ($eraLabel)
                                                <span class="badge badge-xs badge-outline">{{ $eraLabel }}</span>
                                            @endif
                                            @if ($revisionTypeLabel)
                                                <span class="badge badge-xs badge-soft
                                                    {{ in_array($revisionType, ['presence_missing', 'removed'], true) ? 'badge-warning' : 'badge-info' }}">
                                                    {{ $revisionTypeLabel }}
                                                </span>
                                            @endif
                                            @if ($isBaseline)
                                                <span class="badge badge-xs badge-soft badge-accent">State at spell-data cutoff</span>
                                            @elseif ($baselineConfigured && $relativeToBaseline === 'after')
                                                <span class="badge badge-xs badge-soft badge-info">After spell-data cutoff</span>
                                            @elseif ($baselineConfigured && $relativeToBaseline === 'before')
                                                <span class="badge badge-xs badge-ghost">Before spell-data cutoff</span>
                                            @endif
                                        </span>
                                    </span>
                                    @if ($changeCount > 0)
                                        <span class="text-sm text-base-content/55">
                                            {{ number_format($changeCount) }} {{ \Illuminate\Support\Str::plural('change', $changeCount) }}
                                        </span>
                                    @endif
                                </span>
                            </summary>

                            <div class="collapse-content">
                                @if ($playerChanges->isNotEmpty())
                                    <div class="overflow-hidden rounded-box border border-base-content/10">
                                        @foreach ($playerChanges as $change)
                                            @php
                                                $category = data_get($change, 'category', 'other');
                                                $beforeValue = data_get(
                                                    $change,
                                                    'before_display',
                                                    data_get($change, 'before', data_get($change, 'old')),
                                                );
                                                $afterValue = data_get(
                                                    $change,
                                                    'after_display',
                                                    data_get($change, 'after', data_get($change, 'new')),
                                                );
                                                $categoryClass = match ($category) {
                                                    'effects' => 'badge-secondary',
                                                    'messages' => 'badge-info',
                                                    'casting', 'gameplay' => 'badge-primary',
                                                    'availability', 'classes', 'reagents' => 'badge-accent',
                                                    'targeting', 'stacking', 'restrictions' => 'badge-warning',
                                                    'identity' => 'badge-info',
                                                    default => 'badge-ghost',
                                                };
                                            @endphp
                                            <article class="grid grid-cols-1 gap-3 border-b border-base-content/10 p-4 last:border-b-0
                                                    lg:grid-cols-[minmax(10rem,0.55fr)_minmax(0,1fr)_auto_minmax(0,1fr)] lg:items-start"
                                                aria-label="{{ data_get($change, 'label', data_get($change, 'field', 'Spell field')) }} change">
                                                <div class="min-w-0">
                                                    <h3 class="font-medium">
                                                        {{ data_get($change, 'label', data_get($change, 'field', 'Spell field')) }}
                                                    </h3>
                                                    <span class="badge badge-xs badge-soft {{ $categoryClass }} mt-1">
                                                        {{ ucfirst($category) }}
                                                    </span>
                                                </div>
                                                <div class="min-w-0 rounded bg-error/5 p-3">
                                                    <span class="block text-xs font-semibold uppercase tracking-wide text-base-content/50">Before</span>
                                                    <span class="mt-1 block break-words text-sm">
                                                        {{ $displayValue($beforeValue) }}
                                                    </span>
                                                </div>
                                                <span aria-hidden="true" class="hidden pt-4 text-base-content/35 lg:block">&rarr;</span>
                                                <div class="min-w-0 rounded bg-success/5 p-3">
                                                    <span class="block text-xs font-semibold uppercase tracking-wide text-base-content/50">After</span>
                                                    <span class="mt-1 block break-words text-sm">
                                                        {{ $displayValue($afterValue) }}
                                                    </span>
                                                </div>
                                            </article>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($technicalChanges->isNotEmpty())
                                    <details class="collapse collapse-arrow mt-3 border border-base-content/10 bg-base-200/40">
                                        <summary class="collapse-title py-3 text-sm font-medium">
                                            Technical changes ({{ $technicalChanges->count() }})
                                        </summary>
                                        <div class="collapse-content overflow-x-auto" tabindex="0" role="region"
                                            aria-label="Technical changes captured {{ $capturedLabel }}">
                                            <table class="table table-sm min-w-[42rem]">
                                                <thead class="bg-base-300 text-xs uppercase">
                                                    <tr>
                                                        <th scope="col">Field</th>
                                                        <th scope="col">Before</th>
                                                        <th scope="col">After</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($technicalChanges as $change)
                                                        @php
                                                            $beforeValue = data_get(
                                                                $change,
                                                                'before_display',
                                                                data_get($change, 'before', data_get($change, 'old')),
                                                            );
                                                            $afterValue = data_get(
                                                                $change,
                                                                'after_display',
                                                                data_get($change, 'after', data_get($change, 'new')),
                                                            );
                                                        @endphp
                                                        <tr>
                                                            <th scope="row" class="font-medium">
                                                                {{ data_get($change, 'label', data_get($change, 'field', 'Unknown')) }}
                                                            </th>
                                                            <td class="break-all">
                                                                {{ $displayValue($beforeValue) }}
                                                            </td>
                                                            <td class="break-all">
                                                                {{ $displayValue($afterValue) }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </details>
                                @endif

                                @if ($changes->isEmpty())
                                    <p class="rounded-box bg-base-200 p-4 text-sm text-base-content/55">
                                        @if ($revisionType === 'first_observed')
                                            This spell first appears in the Live archive at this capture.
                                        @elseif (in_array($revisionType, ['presence_missing', 'removed'], true))
                                            This spell is no longer observed after consecutive Live archive captures.
                                        @elseif (in_array($revisionType, ['presence_restored', 'restored'], true))
                                            This spell is observed again after a confirmed archive gap.
                                        @elseif ($isBaseline)
                                            This capture represents the spell state at the configured cutoff; no field delta is attached to it.
                                        @else
                                            No field delta is attached to this captured revision.
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </details>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($hasPrevious || $hasMore)
            <nav aria-label="Spell history pages" class="mt-6 flex items-center justify-between gap-3">
                @if ($hasPrevious)
                    <a href="{{ $historyPageUrl($currentPage - 1) }}"
                        rel="prev" class="btn btn-sm btn-soft">Newer captures</a>
                @else
                    <span></span>
                @endif

                @if ($hasMore && $currentPage < $maximumPage)
                    <a href="{{ $historyPageUrl($currentPage + 1) }}"
                        rel="next" class="btn btn-sm btn-soft">Older captures</a>
                @endif
            </nav>
        @endif
    </section>
@endsection
