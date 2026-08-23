<?php

namespace App\Services\SpellHistory;

use JsonException;
use RuntimeException;

final class CsvSnapshotStream
{
    private const MAX_COLUMNS = 1_024;

    private const MAX_LOGICAL_RECORD_BYTES = 8_388_608;

    private const MAX_FIELD_BYTES = 1_048_576;

    /** @var resource|null */
    private $handle;

    /** @var list<string|null> */
    private array $headers;

    /** @var list<string|null> */
    private array $columnMap;

    /** @var list<SpellColumnSpec> */
    private array $columnSpecs;

    /** @var array<string, int|float|string|null>|null */
    private ?array $currentRow = null;

    /** @var array<string, string|null>|null */
    private ?array $currentRawRow = null;

    private ?int $lastId = null;

    private int $rowCount = 0;

    /** @var resource|\HashContext */
    private $canonicalHashContext;

    private ?string $canonicalDigest = null;

    public function __construct(
        private readonly SnapshotFile $snapshot,
        private readonly SpellCanonicalizer $canonicalizer,
        private readonly string $encoding,
    ) {
        if (! in_array($encoding, ['utf-8', 'windows-1252'], true)) {
            throw new RuntimeException("Unsupported snapshot encoding: {$encoding}");
        }

        $handle = fopen($snapshot->path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open snapshot: {$snapshot->filename}");
        }
        if ($encoding === 'windows-1252'
            && stream_filter_append($handle, 'convert.iconv.Windows-1252/UTF-8', STREAM_FILTER_READ) === false) {
            fclose($handle);
            throw new RuntimeException("Unable to install Windows-1252 decoder for {$snapshot->filename}.");
        }
        $this->handle = $handle;
        $this->canonicalHashContext = hash_init('sha256');

        $headers = $this->readCsvRecord();
        if ($headers === null) {
            $this->close();
            throw new RuntimeException("Snapshot has no CSV header: {$snapshot->filename}");
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($headers[0] ?? ''));
        $this->headers = array_map(static fn ($header): ?string => $header === null ? null : (string) $header, $headers);
        $this->columnSpecs = $canonicalizer->compileColumnSpecs($this->headers);
        $this->columnMap = array_map(
            static fn (SpellColumnSpec $spec): ?string => $spec->key,
            $this->columnSpecs,
        );

        if (count(array_filter($this->columnMap, static fn (?string $field): bool => $field === 'id')) !== 1) {
            $this->close();
            throw new RuntimeException("Snapshot must contain exactly one id column: {$snapshot->filename}");
        }

        $this->advance();
    }

    public function snapshot(): SnapshotFile
    {
        return $this->snapshot;
    }

    public function currentId(): ?int
    {
        return $this->currentRow['id'] ?? null;
    }

    /** @return array<string, int|float|string|null>|null */
    public function currentRow(): ?array
    {
        return $this->currentRow;
    }

    /** @return array<string, string|null>|null */
    public function currentRawRow(): ?array
    {
        return $this->currentRawRow;
    }

    public function rowCount(): int
    {
        return $this->rowCount;
    }

    public function schemaHash(): string
    {
        try {
            return hash('sha256', json_encode($this->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (JsonException $exception) {
            throw new RuntimeException("Could not hash the schema for {$this->snapshot->filename}.", 0, $exception);
        }
    }

    /** @return list<string> */
    public function canonicalFields(): array
    {
        $fields = array_values(array_unique(array_filter($this->columnMap)));
        sort($fields, SORT_NATURAL);

        return $fields;
    }

    public function canonicalDigest(): string
    {
        if ($this->canonicalDigest === null) {
            throw new RuntimeException("Snapshot {$this->snapshot->filename} has not been fully consumed.");
        }

        return $this->canonicalDigest;
    }

    public function detectedEncoding(): string
    {
        return $this->encoding;
    }

    public function advance(): void
    {
        while (($values = $this->readCsvRecord()) !== null) {
            if ($this->blankRecord($values)) {
                continue;
            }

            if (count($values) !== count($this->headers)) {
                throw new RuntimeException(
                    "CSV row width does not match its header in {$this->snapshot->filename} near record ".($this->rowCount + 2).'.'
                );
            }

            $payload = $this->canonicalizer->canonicalizeRowUsingSpecs(
                $values,
                $this->columnSpecs,
                "{$this->snapshot->filename} record ".($this->rowCount + 2),
            );
            $row = $payload['values'];
            $id = $row['id'];

            if (! is_int($id) || ($this->lastId !== null && $id <= $this->lastId)) {
                throw new RuntimeException(
                    "Spell ids must be unique and strictly increasing in {$this->snapshot->filename}; found {$id} after ".($this->lastId ?? 'none').'.'
                );
            }

            $this->lastId = $id;
            $this->rowCount++;
            $this->currentRow = $row;
            $this->currentRawRow = $payload['raw_values'];
            try {
                hash_update(
                    $this->canonicalHashContext,
                    json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                );
            } catch (JsonException $exception) {
                throw new RuntimeException("Unable to hash canonical row {$id} in {$this->snapshot->filename}.", 0, $exception);
            }

            return;
        }

        $this->currentRow = null;
        $this->currentRawRow = null;
        if ($this->canonicalDigest === null) {
            $this->canonicalDigest = hash_final($this->canonicalHashContext);
        }
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return list<string|null>|null */
    private function readCsvRecord(): ?array
    {
        if (! is_resource($this->handle)) {
            return null;
        }

        $record = fgetcsv($this->handle, self::MAX_LOGICAL_RECORD_BYTES + 1, ',', '"', '');
        if ($record === false) {
            if (! feof($this->handle)) {
                throw new RuntimeException("Unable to parse CSV in {$this->snapshot->filename}.");
            }

            return null;
        }

        if (count($record) > self::MAX_COLUMNS) {
            throw new RuntimeException("CSV record has too many columns in {$this->snapshot->filename}.");
        }

        $logicalBytes = max(0, count($record) - 1);
        foreach ($record as $value) {
            $fieldBytes = $value === null ? 0 : strlen($value);
            if ($fieldBytes > self::MAX_FIELD_BYTES) {
                throw new RuntimeException("CSV field exceeds its safety limit in {$this->snapshot->filename}.");
            }
            $logicalBytes += $fieldBytes;
        }
        if ($logicalBytes > self::MAX_LOGICAL_RECORD_BYTES) {
            throw new RuntimeException("CSV record exceeds its safety limit in {$this->snapshot->filename}.");
        }

        return $record;
    }

    /** @param list<string|null> $record */
    private function blankRecord(array $record): bool
    {
        foreach ($record as $value) {
            if ($value !== null && trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
