<div class="mt-4 overflow-x-auto rounded-box border border-base-content/10 bg-base-100 shadow-sm"
    role="region" tabindex="0" aria-label="Spell revision changes in table view" data-history-view="table">
    <table class="table table-sm min-w-[72rem]">
        <caption class="sr-only">
            Spell revision changes by capture, field, previous value, new value, and category.
        </caption>
        <thead class="bg-base-300 text-xs uppercase tracking-wide text-base-content/65">
            <tr>
                <th scope="col">Captured</th>
                <th scope="col">Field</th>
                <th scope="col">Before</th>
                <th scope="col">After</th>
                <th scope="col">Category</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($revisions as $revision)
                @php
                    $changes = collect(data_get($revision, 'changes', []));
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
                        default => \Illuminate\Support\Str::headline($revisionType),
                    };
                    $revisionTypeClass = match ($revisionType) {
                        'first_observed' => 'badge-success',
                        'presence_missing', 'removed' => 'badge-warning',
                        'presence_restored', 'restored' => 'badge-info',
                        default => 'badge-ghost',
                    };
                    $cutoffLabel = match (true) {
                        $isBaseline => 'State at spell-data cutoff',
                        $relativeToBaseline === 'after' => 'After spell-data cutoff',
                        $relativeToBaseline === 'before' => 'Before spell-data cutoff',
                        default => null,
                    };
                    $cutoffClass = match (true) {
                        $isBaseline => 'badge-accent',
                        $relativeToBaseline === 'after' => 'badge-info',
                        default => 'badge-ghost',
                    };
                    $capturedLabel = data_get(
                        $revision,
                        'captured_label',
                        data_get($revision, 'captured_at', data_get($revision, 'observed_at', 'Unknown capture')),
                    );
                    $syntheticChange = match ($revisionType) {
                        'first_observed' => [
                            'label' => 'Spell availability',
                            'before' => 'Not yet observed',
                            'after' => 'Available',
                            'category' => 'Lifecycle',
                        ],
                        'presence_missing', 'removed' => [
                            'label' => 'Spell availability',
                            'before' => 'Available',
                            'after' => 'Not observed',
                            'category' => 'Lifecycle',
                        ],
                        'presence_restored', 'restored' => [
                            'label' => 'Spell availability',
                            'before' => 'Not observed',
                            'after' => 'Available',
                            'category' => 'Lifecycle',
                        ],
                        default => [
                            'label' => $isBaseline ? 'Captured cutoff state' : 'Captured revision',
                            'before' => 'No field delta',
                            'after' => 'No field delta',
                            'category' => 'Lifecycle',
                        ],
                    };
                    $rows = $changes->isEmpty() ? collect([$syntheticChange]) : $changes;
                @endphp

                @foreach ($rows as $change)
                    @php
                        $field = (string) data_get($change, 'field', '');
                        $category = (string) data_get($change, 'category', 'other');
                        $isTechnical = in_array($category, ['technical', 'advanced'], true)
                            || str_starts_with($field, 'unknown');
                        $categoryLabel = $isTechnical
                            ? 'Technical'
                            : \Illuminate\Support\Str::headline($category);
                        $categoryClass = match ($category) {
                            'effects' => 'badge-secondary',
                            'messages', 'identity' => 'badge-info',
                            'casting', 'gameplay' => 'badge-primary',
                            'availability', 'classes', 'reagents', 'Lifecycle' => 'badge-accent',
                            'targeting', 'stacking', 'restrictions' => 'badge-warning',
                            'technical', 'advanced' => 'badge-neutral !text-base-content',
                            default => $isTechnical ? 'badge-neutral !text-base-content' : 'badge-ghost',
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
                    @endphp

                    <tr class="align-top {{ $isBaseline ? 'border-l-4 border-accent bg-accent/10' : '' }}">
                        <td class="w-56">
                            <span class="block whitespace-nowrap font-semibold tabular-nums">{{ $capturedLabel }}</span>
                            <span class="mt-1 flex max-w-56 flex-wrap gap-1">
                                <span class="badge badge-xs badge-soft {{ $revisionTypeClass }}">
                                    {{ $revisionTypeLabel }}
                                </span>
                                @if ($cutoffLabel)
                                    <span class="badge badge-xs badge-soft {{ $cutoffClass }}">
                                        {{ $cutoffLabel }}
                                    </span>
                                @endif
                            </span>
                        </td>
                        <th scope="row" class="max-w-64 whitespace-normal font-medium">
                            {{ data_get($change, 'label', data_get($change, 'field', 'Spell field')) }}
                        </th>
                        <td class="max-w-80 whitespace-normal break-words bg-error/5">
                            {{ $displayValue($beforeValue) }}
                        </td>
                        <td class="max-w-80 whitespace-normal break-words bg-success/5">
                            {{ $displayValue($afterValue) }}
                        </td>
                        <td class="w-32">
                            <span class="badge badge-sm badge-soft whitespace-nowrap {{ $categoryClass }}">
                                {{ $categoryLabel }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>
