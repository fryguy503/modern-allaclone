<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatchExportService
{
    private const CSV_HEADERS = [
        'id', 'slug', 'patch_date', 'effective_date', 'display_date', 'title', 'sequence', 'year', 'month',
        'kind', 'era', 'expansion', 'expansion_code', 'categories', 'sections', 'change_count',
        'word_count', 'summary', 'content', 'content_hash', 'source_files', 'source_titles',
        'source_url', 'source_urls', 'occurrence_count', 'source_occurrences', 'year_inferred',
    ];

    public function __construct(private readonly PatchArchive $archive)
    {
    }

    public function fullJson(Request $request): BinaryFileResponse
    {
        $coverage = $this->archive->coverage();
        $filename = "everquest-patches-{$coverage['first_patch']}-to-{$coverage['last_patch']}.json";
        $response = response()->download($this->archive->dataPath(), $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600, must-revalidate',
        ]);

        return $this->conditional($request, $response, $this->archive->checksum());
    }

    public function fullCsv(Request $request): BinaryFileResponse
    {
        $coverage = $this->archive->coverage();
        $filename = "everquest-patches-{$coverage['first_patch']}-to-{$coverage['last_patch']}.csv";
        $response = response()->download($this->archive->csvPath(), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600, must-revalidate',
        ]);

        return $this->conditional($request, $response, hash_file('sha256', $this->archive->csvPath()));
    }

    public function json(Request $request, array $patches, array $filters = [], ?string $singleName = null): StreamedResponse
    {
        $filename = $singleName ? $singleName.'.json' : 'everquest-patches-filtered.json';
        $metadata = $this->archive->metadata();
        $response = response()->streamDownload(function () use ($patches, $filters, $metadata): void {
            $prefix = array_merge($metadata, [
                'record_count' => count($patches),
                'filters' => (object) array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== []),
            ]);
            echo substr(json_encode($prefix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 0, -1);
            echo ',"patches":[';
            foreach ($patches as $index => $patch) {
                if ($index > 0) echo ',';
                echo json_encode($this->cleanPatch($patch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            echo "]}\n";
        }, $filename, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);

        $etag = hash('sha256', $this->archive->checksum().json_encode($filters).implode(',', array_column($patches, 'slug')));
        return $this->conditional($request, $response, $etag);
    }

    public function csv(Request $request, array $patches, array $filters = [], ?string $singleName = null): StreamedResponse
    {
        $filename = $singleName ? $singleName.'.csv' : 'everquest-patches-filtered.csv';
        $response = response()->streamDownload(function () use ($patches): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::CSV_HEADERS, ',', '"', '', "\r\n");
            foreach ($patches as $patch) {
                $row = [];
                foreach (self::CSV_HEADERS as $header) {
                    $value = $patch[$header] ?? '';
                    if ($header === 'categories') $value = implode('|', array_column($patch['categories'], 'label'));
                    elseif ($header === 'source_occurrences') {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    }
                    elseif (is_array($value)) $value = implode('|', $value);
                    if (is_bool($value)) $value = $value ? 'true' : 'false';
                    $row[] = $this->spreadsheetSafe((string) $value);
                }
                fputcsv($handle, $row, ',', '"', '', "\r\n");
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);

        $etag = hash('sha256', $this->archive->checksum().json_encode($filters).implode(',', array_column($patches, 'slug')).'csv');
        return $this->conditional($request, $response, $etag);
    }

    public function raw(Request $request, array $patch): StreamedResponse
    {
        $response = response()->streamDownload(function () use ($patch): void {
            echo $patch['display_date']."\r\n";
            echo str_repeat('-', max(30, mb_strlen($patch['display_date'])))."\r\n\r\n";
            echo str_replace("\n", "\r\n", $patch['content'])."\r\n";
        }, $patch['slug'].'.txt', [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=3600, must-revalidate',
        ]);

        return $this->conditional($request, $response, $patch['content_hash']);
    }

    private function cleanPatch(array $patch): array
    {
        return array_filter($patch, fn (string $key) => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }

    private function conditional(Request $request, BinaryFileResponse|StreamedResponse $response, string $etag): mixed
    {
        $response->setEtag('"'.trim($etag, '"').'"');
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp($this->archive->lastModified()));
        $response->isNotModified($request);

        return $response;
    }
}
