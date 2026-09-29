@if ($previewDrafts)
    <div class="alert alert-warning alert-soft mb-6" role="status">
        @include('encounters.partials.icon', ['name' => 'lock'])
        <div>
            <p class="font-semibold">Draft preview</p>
            <p class="text-sm">Unpublished journal entries are visible in this local preview.</p>
        </div>
    </div>
@endif
