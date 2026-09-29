@if (!empty($journalEntries))
    <section class="my-6 rounded-lg border border-base-content/10 bg-base-100 p-5" aria-label="Encounter Journal">
        <h2 class="text-lg font-semibold text-info mb-3">Encounter Journal</h2>
        <ul class="grid gap-3 md:grid-cols-2">
            @foreach ($journalEntries as $journalEntry)
                <li>
                    <a class="link link-info link-hover" href="{{ route('encounters.show', $journalEntry['slug']) }}">{{ $journalEntry['title'] }}</a>
                    <p class="text-sm text-base-content/70">{{ $journalEntry['group'] }} · {{ $journalEntry['zone']['name'] }} · v{{ $journalEntry['zone']['version'] }}</p>
                    @if ($journalEntry['status'] === 'draft')
                        <span class="badge badge-warning badge-outline badge-sm">Draft preview</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
