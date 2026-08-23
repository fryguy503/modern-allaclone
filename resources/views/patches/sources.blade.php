@extends('layouts.default')

@section('title', 'Patch Archive Sources')

@section('content')
    @php
        $pdfSource = collect($metadata['provenance']['files'])->firstWhere('filename', 'Patch_Summaries.pdf');
        $pdfSummary = $pdfSource['semantic_extraction'] ?? null;
    @endphp
    <div class="grid gap-6 lg:grid-cols-[1.2fr_.8fr]">
        <section class="rounded-2xl border border-sky-500/20 bg-base-100 p-6 sm:p-8">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-400">Transparent by design</p>
            <h2 class="mt-2 text-3xl font-semibold">A traceable historical archive</h2>
            <p class="mt-4 max-w-3xl leading-relaxed text-base-content/65">
                Every supplied artifact is inventoried by filename, byte size, role, encoding, and SHA-256 checksum. Raw yearly and beta text is canonical; combined files corroborate and fill gaps; the PDF highlights are extracted as curated context without being misrepresented as full patch text.
            </p>
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="{{ route('patches.index') }}" class="btn btn-sm btn-info">Browse archive</a>
                <a href="{{ route('patches.export.json') }}" class="btn btn-sm btn-soft">Full JSON</a>
                <a href="{{ route('patches.export.csv') }}" class="btn btn-sm btn-soft">Full CSV</a>
            </div>
        </section>
        <dl class="grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-base-100 p-5"><dt class="text-xs uppercase text-base-content/45">Records</dt><dd class="mt-1 text-2xl font-semibold text-sky-400">{{ number_format($metadata['coverage']['patch_count']) }}</dd></div>
            <div class="rounded-xl bg-base-100 p-5"><dt class="text-xs uppercase text-base-content/45">Files</dt><dd class="mt-1 text-2xl font-semibold">{{ number_format($metadata['coverage']['source_file_count']) }}</dd></div>
            <div class="rounded-xl bg-base-100 p-5"><dt class="text-xs uppercase text-base-content/45">First</dt><dd class="mt-1 font-mono text-lg font-semibold">{{ $metadata['coverage']['first_patch'] }}</dd></div>
            <div class="rounded-xl bg-base-100 p-5"><dt class="text-xs uppercase text-base-content/45">Last</dt><dd class="mt-1 font-mono text-lg font-semibold">{{ $metadata['coverage']['last_patch'] }}</dd></div>
        </dl>
    </div>

    <section class="mt-8 rounded-xl border border-base-content/10 bg-base-100 p-6">
        <h3 class="text-xl font-semibold text-sky-400">Normalization policy</h3>
        <div class="mt-4 grid gap-5 leading-relaxed text-base-content/65 md:grid-cols-3">
            <div><strong class="block text-base-content">1. Decode without loss</strong><span class="text-sm">Strict UTF-8 is preferred. Mixed legacy files recover valid UTF-8 sequences and map only invalid singleton bytes through Windows-1252.</span></div>
            <div><strong class="block text-base-content">2. Preserve provenance</strong><span class="text-sm">Every contributing filename and alternate heading stays attached to the normalized record, including repeated and derivative occurrences.</span></div>
            <div><strong class="block text-base-content">3. Render as untrusted text</strong><span class="text-sm">Imported markup is interpreted into a small structural model. Patch text is always escaped; source links are restricted to valid HTTP(S) URLs.</span></div>
        </div>
        <p class="mt-5 text-sm text-base-content/50">{{ $metadata['provenance']['note'] }}</p>
    </section>

    @if ($pdfSummary)
        <section class="mt-8 rounded-xl border border-base-content/10 bg-base-100 p-6" id="curated-highlights">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-400">Supplemental source</p>
                    <h3 class="mt-1 text-xl font-semibold">{{ $pdfSummary['title'] }}</h3>
                    <p class="mt-2 text-sm text-base-content/55">{{ $pdfSummary['coverage_note'] }}</p>
                </div>
                <span class="badge badge-info badge-outline">{{ $pdfSummary['page_count'] }} extracted pages</span>
            </div>
            <div class="mt-5 space-y-2">
                @foreach ($pdfSummary['pages'] as $page)
                    <details class="collapse collapse-arrow border border-base-content/10 bg-base-200" @if($page['page'] === 1) open @endif>
                        <summary class="collapse-title min-h-0 py-3 font-semibold">Highlights page {{ $page['page'] }}</summary>
                        <div class="collapse-content">
                            <div class="whitespace-pre-line border-t border-base-content/10 pt-4 text-sm leading-6 text-base-content/70">{{ $page['text'] }}</div>
                        </div>
                    </details>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-8">
        <div class="divider uppercase text-xl font-bold text-sky-400">Source manifest</div>
        <div class="overflow-x-auto rounded-xl border border-base-content/10 bg-base-100">
            <table class="table table-zebra">
                <thead><tr><th>File</th><th>Role</th><th>Encoding</th><th class="text-right">Records</th><th class="text-right">Bytes</th><th>SHA-256</th></tr></thead>
                <tbody>
                    @foreach ($metadata['provenance']['files'] as $file)
                        <tr>
                            <td>
                                <span class="font-semibold">{{ $file['filename'] }}</span>
                                <span class="mt-1 block max-w-xl text-xs leading-relaxed text-base-content/45">{{ $file['description'] }}</span>
                                @foreach ($file['warnings'] as $warning)<span class="mt-1 block max-w-md text-xs text-warning">{{ $warning }}</span>@endforeach
                            </td>
                            <td class="text-sm text-base-content/60">{{ str($file['role'])->headline() }}</td>
                            <td><span class="badge badge-sm badge-ghost">{{ $file['detected_encoding'] }}</span></td>
                            <td class="text-right">{{ number_format($file['parsed_records']) }}</td>
                            <td class="text-right font-mono text-xs">{{ number_format($file['bytes']) }}</td>
                            <td class="font-mono text-xs" title="{{ $file['sha256'] }}">{{ substr($file['sha256'], 0, 12) }}…</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <p class="mt-5 text-center text-xs text-base-content/40">Schema v{{ $metadata['schema_version'] }} · Archive generated {{ $metadata['generated_at'] }}</p>
@endsection
