@extends('layouts.default')

@section('title')
    @if (!empty($itemSummary['icon']))
        <img src="{{ asset('img/icons/' . $itemSummary['icon'] . '.png') }}" alt=""
            class="inline-block w-7 h-7 mr-2">
    @endif
    {{ $itemSummary['name'] }} History
@endsection

@section('content')
    @php
        $currentPage = max(1, (int) data_get($pagination, 'current_page', 1));
        $hasPrevious = (bool) data_get($pagination, 'has_previous', $currentPage > 1);
        $hasMore = (bool) data_get($pagination, 'has_more', false);
        $revisionCount = (int) data_get($archive, 'revision_count', 0);
        $archiveComplete = (bool) data_get($archive, 'complete', false);
        $isReconstructed = (bool) data_get($archive, 'is_reconstructed', false);
        $directDetailCount = (int) data_get($archive, 'coverage.direct_detail_count', 0);
        $sources = collect(data_get($archive, 'sources', []));
        $gaps = collect(data_get($archive, 'gaps', []));
        $firstObserved = data_get($archive, 'first_observed_label');
        $lastObserved = data_get($archive, 'last_observed_label');
        $generated = data_get($archive, 'generated_label', data_get($archive, 'generated_at'));
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
        $historyUrl = static function (string $view, int $page = 1) use ($itemSummary): string {
            $parameters = ['item' => $itemSummary['id'], 'view' => $view];
            if ($page > 1) {
                $parameters['page'] = $page;
            }

            return route('items.history', $parameters);
        };
        $operationClass = static fn (string $operation): string => match ($operation) {
            'added', 'initial' => 'badge-success',
            'removed' => 'badge-warning',
            'changed' => 'badge-info',
            default => 'badge-ghost',
        };
    @endphp

    @include('items.partials.history-tabs', [
        'itemId' => $itemSummary['id'],
        'activeTab' => 'history',
    ])

    <section class="card bg-base-300 shadow-sm" aria-labelledby="item-history-archive-heading">
        <div class="card-body p-5 md:p-6">
            <p class="text-xs font-semibold uppercase tracking-wider text-accent">Lucy item archive</p>
            <h2 id="item-history-archive-heading" class="card-title mt-1 text-2xl">
                {{ $itemSummary['name'] }} revisions
            </h2>
            <p class="max-w-3xl text-sm text-base-content/65">
                Details shows this server's current EQEmu item.
                @if ($isReconstructed)
                    This timeline preserves Lucy's recorded changes and reconstructs historical field state from one
                    captured current raw export. It can differ from custom server data and is not a collection of
                    directly captured Lucy detail pages.
                @else
                    This timeline shows item states and changes captured from Lucy, which can differ from custom
                    server data.
                @endif
            </p>

            <div class="mt-2 flex flex-wrap gap-2 text-xs">
                <span class="badge badge-soft badge-info">
                    {{ number_format($revisionCount) }} {{ \Illuminate\Support\Str::plural('revision', $revisionCount) }}
                </span>
                @foreach ($sources as $source)
                    <span class="badge badge-soft">{{ $source }}</span>
                @endforeach
                @if ($isReconstructed)
                    <span class="badge badge-soft badge-warning">Reconstructed field history</span>
                    @if ($directDetailCount > 0)
                        <span class="badge badge-outline">
                            {{ number_format($directDetailCount) }} directly captured {{ \Illuminate\Support\Str::plural('detail', $directDetailCount) }}
                        </span>
                    @endif
                @endif
                @if ($firstObserved && $lastObserved)
                    <span class="badge badge-outline">{{ $firstObserved }} &ndash; {{ $lastObserved }}</span>
                @endif
            </div>

            <p class="mt-1 text-xs text-base-content/55">
                Lucy timestamps identify when a revision was recorded, not necessarily the exact patch time.
                @if ($isReconstructed)
                    Reconstructed history is limited to reversible fields Lucy listed as changes; unchanged fields
                    inherit the captured current raw export and Test history may be an unanchored verified chain.
                @else
                    Historical detail is limited to fields Lucy rendered for that revision.
                @endif
                @if ($generated)
                    Artifact generated {{ $generated }}.
                @endif
            </p>
        </div>
    </section>

    @if (!$archiveComplete || $gaps->isNotEmpty())
        <div role="status" class="alert alert-warning alert-soft mt-4">
            <span>
                This item's archive is incomplete.
                @if ($gaps->isNotEmpty())
                    It reports {{ number_format($gaps->count()) }} known coverage
                    {{ \Illuminate\Support\Str::plural('gap', $gaps->count()) }}.
                @endif
                Missing source pages are not inferred as unchanged item data.
            </span>
        </div>
    @endif

    <section class="mt-6" aria-labelledby="item-history-timeline-heading">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="item-history-timeline-heading" class="text-xl font-bold text-sky-400">
                    {{ $isReconstructed ? 'Recorded revisions' : 'Captured revisions' }}
                </h2>
                <p class="text-sm text-base-content/55">Newest recorded revisions are shown first.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="join" aria-label="History display mode">
                    <a href="{{ $historyUrl('cards') }}" class="btn btn-sm join-item {{ $displayMode === 'cards' ? 'btn-active' : 'btn-soft' }}"
                        @if ($displayMode === 'cards') aria-current="page" @endif>Cards</a>
                    <a href="{{ $historyUrl('table') }}" class="btn btn-sm join-item {{ $displayMode === 'table' ? 'btn-active' : 'btn-soft' }}"
                        @if ($displayMode === 'table') aria-current="page" @endif>Table</a>
                </div>
                <span class="text-sm tabular-nums text-base-content/55">Page {{ $currentPage }}</span>
            </div>
        </div>

        @if ($revisions->isEmpty())
            <div role="status" class="alert alert-info alert-soft mt-4">
                <span>No captured revisions were found for this item.</span>
            </div>
        @elseif ($displayMode === 'table')
            <div class="mt-4 overflow-x-auto rounded-box border border-base-content/10 bg-base-100" tabindex="0"
                role="region" aria-label="Lucy-style compact item revision table">
                <table class="table table-sm min-w-[76rem]">
                    <thead class="bg-base-300 text-xs uppercase">
                        <tr>
                            <th scope="col">Recorded</th>
                            <th scope="col">Source</th>
                            <th scope="col">Revision / entry</th>
                            <th scope="col">Operation</th>
                            <th scope="col">Field</th>
                            <th scope="col">Before</th>
                            <th scope="col">After</th>
                            <th scope="col">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($revisions as $revision)
                            @php
                                $changes = collect(data_get($revision, 'changes', []));
                                $revisionType = (string) data_get($revision, 'type', 'changed');
                                $rows = $changes->isEmpty()
                                    ? collect([[
                                        'operation' => $revisionType === 'initial' ? 'initial' : 'unknown',
                                        'field' => null,
                                        'before' => null,
                                        'after' => null,
                                        'display' => 'No field delta recorded',
                                    ]])
                                    : $changes;
                            @endphp
                            @foreach ($rows as $change)
                                @php
                                    $operation = (string) data_get($change, 'operation', 'unknown');
                                    $field = data_get($change, 'field');
                                    $display = trim((string) data_get($change, 'display', ''));
                                @endphp
                                <tr>
                                    <td class="whitespace-nowrap align-top tabular-nums">
                                        {{ data_get($revision, 'observed_label', 'Unknown observation time') ?: 'Unknown observation time' }}
                                    </td>
                                    <td class="align-top">
                                        <span class="badge badge-xs badge-soft">{{ data_get($revision, 'source', 'Unknown') }}</span>
                                    </td>
                                    <td class="whitespace-nowrap align-top">
                                        <span class="font-medium">{{ $revisionType === 'initial' ? 'Initial entry' : 'Changed' }}</span>
                                        <span class="block text-xs text-base-content/50">Entry {{ data_get($revision, 'entry_id') }}</span>
                                    </td>
                                    <td class="align-top">
                                        <span class="badge badge-xs badge-soft {{ $operationClass($operation) }}">{{ ucfirst($operation) }}</span>
                                    </td>
                                    <td class="align-top font-medium">
                                        {{ $field ? \Illuminate\Support\Str::headline($field) : '—' }}
                                    </td>
                                    <td class="max-w-64 break-words align-top">{{ $displayValue(data_get($change, 'before')) }}</td>
                                    <td class="max-w-64 break-words align-top">{{ $displayValue(data_get($change, 'after')) }}</td>
                                    <td class="max-w-96 break-words align-top">{{ $display !== '' ? $display : '—' }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <ol class="mt-4 space-y-4 border-l border-base-content/10 pl-4 md:ml-3 md:pl-6">
                @foreach ($revisions as $revision)
                    @php
                        $changes = collect(data_get($revision, 'changes', []));
                        $snapshotLines = collect(data_get($revision, 'snapshot_lines', []));
                        $detailFidelity = (string) data_get($revision, 'detail_fidelity', 'captured');
                        $revisionType = (string) data_get($revision, 'type', 'changed');
                        $capturedLabel = data_get($revision, 'observed_label') ?: 'Unknown observation time';
                    @endphp
                    <li class="relative">
                        <span aria-hidden="true"
                            class="absolute -left-[1.32rem] top-6 h-3 w-3 rounded-full border-2 border-base-200 bg-base-content/30 md:-left-[1.82rem]"></span>
                        <details class="collapse collapse-arrow border border-base-content/10 bg-base-100 shadow-sm"
                            @if ($loop->first) open @endif>
                            <summary class="collapse-title pr-12">
                                <span class="flex w-full flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <span>
                                        <span class="block font-semibold tabular-nums">Recorded {{ $capturedLabel }}</span>
                                        <span class="mt-1 flex flex-wrap gap-1.5">
                                            <span class="badge badge-xs badge-soft">{{ data_get($revision, 'source', 'Unknown') }}</span>
                                            <span class="badge badge-xs badge-soft badge-info">
                                                {{ $revisionType === 'initial' ? 'Initial entry' : 'Changed' }}
                                            </span>
                                            <span class="badge badge-xs badge-ghost">Entry {{ data_get($revision, 'entry_id') }}</span>
                                        </span>
                                    </span>
                                    <span class="text-sm text-base-content/55">
                                        {{ number_format($changes->count()) }} {{ \Illuminate\Support\Str::plural('change', $changes->count()) }}
                                    </span>
                                </span>
                            </summary>

                            <div class="collapse-content space-y-3">
                                @forelse ($changes as $change)
                                    @php
                                        $operation = (string) data_get($change, 'operation', 'unknown');
                                        $field = data_get($change, 'field');
                                        $display = trim((string) data_get($change, 'display', ''));
                                    @endphp
                                    <article class="rounded-box border border-base-content/10 p-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="badge badge-xs badge-soft {{ $operationClass($operation) }}">{{ ucfirst($operation) }}</span>
                                            @if ($field)
                                                <h3 class="font-medium">{{ \Illuminate\Support\Str::headline($field) }}</h3>
                                            @endif
                                        </div>
                                        @if ($display !== '')
                                            <p class="mt-2 break-words text-sm">{{ $display }}</p>
                                        @endif
                                        @if (array_key_exists('before', $change) || array_key_exists('after', $change))
                                            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <div class="min-w-0 rounded bg-error/5 p-3">
                                                    <span class="block text-xs font-semibold uppercase tracking-wide text-base-content/50">Before</span>
                                                    <span class="mt-1 block break-words text-sm">{{ $displayValue(data_get($change, 'before')) }}</span>
                                                </div>
                                                <div class="min-w-0 rounded bg-success/5 p-3">
                                                    <span class="block text-xs font-semibold uppercase tracking-wide text-base-content/50">After</span>
                                                    <span class="mt-1 block break-words text-sm">{{ $displayValue(data_get($change, 'after')) }}</span>
                                                </div>
                                            </div>
                                        @endif
                                    </article>
                                @empty
                                    <p class="rounded-box bg-base-200 p-4 text-sm text-base-content/55">
                                        {{ $revisionType === 'initial'
                                            ? 'This is the first Lucy entry captured for the item.'
                                            : 'No field delta was recorded for this revision.' }}
                                    </p>
                                @endforelse

                                @if ($snapshotLines->isNotEmpty())
                                    <details class="collapse collapse-arrow border border-base-content/10 bg-base-200/40">
                                        <summary class="collapse-title py-3 text-sm font-medium">
                                            {{ $isReconstructed ? 'Directly captured Lucy detail snapshot' : 'Captured item snapshot' }}
                                            ({{ number_format($snapshotLines->count()) }} lines)
                                        </summary>
                                        <div class="collapse-content">
                                            <ul class="space-y-1 font-mono text-xs">
                                                @foreach ($snapshotLines as $line)
                                                    <li class="break-words">{{ $line }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </details>
                                @elseif ($isReconstructed && $detailFidelity === 'reconstructed')
                                    <p class="rounded-box border border-warning/20 bg-warning/5 p-4 text-sm text-base-content/65">
                                        No Lucy-rendered detail snapshot was fetched for this revision. Its field history
                                        is reconstructed from the captured history rows and current raw export.
                                    </p>
                                @endif
                            </div>
                        </details>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($hasPrevious || $hasMore)
            <nav aria-label="Item history pages" class="mt-6 flex items-center justify-between gap-3">
                @if ($hasPrevious)
                    <a href="{{ $historyUrl($displayMode, $currentPage - 1) }}"
                        rel="prev" class="btn btn-sm btn-soft">Newer revisions</a>
                @else
                    <span></span>
                @endif

                @if ($hasMore && $currentPage < $maximumPage)
                    <a href="{{ $historyUrl($displayMode, $currentPage + 1) }}"
                        rel="next" class="btn btn-sm btn-soft">Older revisions</a>
                @endif
            </nav>
        @endif
    </section>
@endsection
