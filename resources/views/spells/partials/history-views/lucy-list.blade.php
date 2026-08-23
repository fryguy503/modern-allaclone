<div class="mt-4 overflow-x-auto rounded-box border border-base-content/10 bg-base-100 shadow-sm"
    role="region" tabindex="0" aria-label="Lucy-style spell revision list" data-history-view="lucy">
    <table class="table table-xs min-w-[46rem] sm:table-sm">
        <caption class="sr-only">
            Spell revisions listed newest first, with one row for each captured field change.
        </caption>
        <thead class="bg-base-300 text-xs uppercase tracking-wide">
            <tr>
                <th scope="col" class="w-64">Date</th>
                <th scope="col">Change</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($revisions as $revision)
                @php
                    $changes = collect(data_get($revision, 'changes', []));
                    $rows = $changes->isEmpty() ? collect([null]) : $changes;
                    $isBaseline = (bool) data_get(
                        $revision,
                        'is_baseline',
                        data_get($revision, 'is_server_baseline', false),
                    );
                    $relativeToBaseline = data_get($revision, 'relative_to_baseline');
                    $revisionType = (string) data_get($revision, 'type', 'changed');
                    $revisionTypeLabel = match ($revisionType) {
                        'first_observed' => 'First observed',
                        'presence_missing', 'removed' => 'Not observed',
                        'presence_restored', 'restored' => 'Observed again',
                        'changed' => 'Changed',
                        default => ucfirst(str_replace('_', ' ', $revisionType)),
                    };
                    $revisionTypeClass = match ($revisionType) {
                        'presence_missing', 'removed' => 'badge-warning',
                        'first_observed', 'presence_restored', 'restored' => 'badge-info',
                        default => 'badge-ghost',
                    };
                    $capturedAt = data_get($revision, 'captured_at', data_get($revision, 'observed_at'));
                    $capturedLabel = data_get(
                        $revision,
                        'captured_label',
                        $capturedAt ?: 'Unknown capture',
                    );
                    $eraLabel = data_get($revision, 'era_label', data_get($revision, 'era'));
                    $lifecycleMessage = match ($revisionType) {
                        'first_observed' => 'This spell first appears in the Lucy Live archive.',
                        'presence_missing', 'removed' => 'This spell is no longer observed after consecutive Lucy Live captures.',
                        'presence_restored', 'restored' => 'This spell is observed again after a confirmed archive gap.',
                        default => $isBaseline
                            ? 'This capture represents the spell state at the configured cutoff; no field delta is attached.'
                            : 'No comparable field delta is attached to this captured revision.',
                    };
                @endphp

                @foreach ($rows as $change)
                    @php
                        $isLifecycle = $change === null;
                        $field = (string) data_get($change, 'field', '');
                        $fieldLabel = (string) data_get($change, 'label', $field ?: 'Spell field');
                        $category = (string) data_get($change, 'category', $isLifecycle ? 'lifecycle' : 'other');
                        $isTechnical = in_array($category, ['technical', 'advanced'], true)
                            || str_starts_with($field, 'unknown');
                        if ($isTechnical) {
                            $category = 'technical';
                        }
                        $categoryLabel = ucfirst(str_replace('_', ' ', $category));
                        $categoryClass = match ($category) {
                            'effects' => 'badge-secondary',
                            'messages', 'identity' => 'badge-info',
                            'casting', 'gameplay' => 'badge-primary',
                            'availability', 'classes', 'reagents' => 'badge-accent',
                            'targeting', 'stacking', 'restrictions' => 'badge-warning',
                            'technical' => 'badge-neutral',
                            'lifecycle' => 'badge-info',
                            default => 'badge-ghost',
                        };
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
                        $beforeIsEmpty = $beforeValue === null || $beforeValue === '';
                        $afterIsEmpty = $afterValue === null || $afterValue === '';
                    @endphp

                    <tr class="border-b border-base-content/10 last:border-b-0
                            {{ $isBaseline ? 'bg-accent/10 hover:bg-accent/15' : 'hover:bg-base-200/50' }}">
                        <th scope="row"
                            class="w-64 min-w-64 whitespace-normal border-l-4 align-top font-normal
                                {{ $isBaseline ? 'border-accent' : 'border-transparent' }}">
                            @if ($capturedAt)
                                <time datetime="{{ $capturedAt }}" class="block font-semibold tabular-nums">
                                    {{ $capturedLabel }}
                                </time>
                            @else
                                <span class="block font-semibold tabular-nums">{{ $capturedLabel }}</span>
                            @endif

                            <span class="mt-1.5 flex flex-wrap gap-1">
                                @if ($eraLabel)
                                    <span class="badge badge-xs badge-outline">{{ $eraLabel }}</span>
                                @endif
                                <span class="badge badge-xs badge-soft {{ $revisionTypeClass }}">
                                    {{ $revisionTypeLabel }}
                                </span>
                                @if ($isBaseline)
                                    <span class="badge badge-xs badge-soft badge-accent">State at spell-data cutoff</span>
                                @elseif ($relativeToBaseline === 'after')
                                    <span class="badge badge-xs badge-soft badge-info">After cutoff</span>
                                @elseif ($relativeToBaseline === 'before')
                                    <span class="badge badge-xs badge-ghost">Before cutoff</span>
                                @endif
                            </span>
                        </th>
                        <td class="align-top">
                            <div class="flex items-start gap-2">
                                <span class="badge badge-xs badge-soft {{ $categoryClass }} mt-0.5 shrink-0">
                                    {{ $categoryLabel }}
                                </span>
                                <div class="min-w-0 text-sm">
                                    @if ($isLifecycle)
                                        <span>{{ $lifecycleMessage }}</span>
                                    @elseif ($beforeIsEmpty && !$afterIsEmpty)
                                        <span>Added <strong class="font-semibold">{{ $fieldLabel }}</strong>:</span>
                                        <span class="break-words text-success">{{ $displayValue($afterValue) }}</span>
                                    @elseif (!$beforeIsEmpty && $afterIsEmpty)
                                        <span>Removed <strong class="font-semibold">{{ $fieldLabel }}</strong>:</span>
                                        <span class="break-words text-error">{{ $displayValue($beforeValue) }}</span>
                                    @else
                                        <span>Changed <strong class="font-semibold">{{ $fieldLabel }}</strong> from</span>
                                        <span class="break-words text-error">{{ $displayValue($beforeValue) }}</span>
                                        <span>to</span>
                                        <span class="break-words text-success">{{ $displayValue($afterValue) }}</span>
                                    @endif

                                    @if ($isTechnical && $field !== '' && $field !== $fieldLabel)
                                        <span class="mt-1 block break-all font-mono text-xs text-base-content/45">
                                            Field: {{ $field }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
