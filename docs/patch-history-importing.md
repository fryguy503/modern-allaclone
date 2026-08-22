# Importing EverQuest patch history

The importer is intentionally a local, reviewable build step. The website never
parses uploads or reaches into your source directory at request time.

## Quick workflow

1. Put source documents anywhere below one corpus directory. Nested folders are
   supported and source paths are retained as provenance.
2. Install the optional PDF and DOCX adapters:

   ```sh
   python -m pip install -r scripts/requirements-patch-import.txt
   ```

3. Run a strict dry run. This reads and validates everything without replacing
   published artifacts:

   ```sh
   npm run patches:import:check -- /path/to/patcheq database/data \
     --report database/data/everquest-patch-import-report.json
   ```

   A dry run never replaces JSON, CSV, or suggestion artifacts. The explicit
   `--report` path permits only the diagnostic report to be refreshed.

4. Review `everquest-patch-import-report.json`, especially `rejections`,
   `warnings`, `comparison`, and `slugs`. Then publish and validate:

   ```sh
   npm run patches:import -- /path/to/patcheq database/data
   npm run patches:validate -- /path/to/patcheq database/data
   ```

The default safety policy permits no loss of an existing public slug. If a
source was intentionally removed, inspect the listed slugs first and invoke the
Node importer directly with `--allow-removals`. That flag should not be part of
an unattended build.

## Supported documents

| Format | What the importer uses | Notes |
| --- | --- | --- |
| TXT, LOG, NOTES | Dated headings and following text | UTF-8, Windows-1252, mixed legacy bytes, and UTF-16 are normalized. |
| Markdown | ATX or setext-style dated headings | Ordinary prose may coexist with patch records. |
| HTML | Visible text and dated headings | Saved XenForo threads use only the title, canonical URL, date, and first post. Scripts and styles are ignored. |
| RSS, Atom, XML | Item title/date/link/content | Truncated “Read more” excerpts are provenance-only by default and cannot become public records. |
| JSON | A top-level array or one `patches`/`records` array | Each object uses the field aliases described below; malformed entries are rejected by item number. |
| CSV, TSV | A header row followed by one record per row | Quoted multiline CSV fields are supported; malformed rows are rejected by original row number. |
| PDF | Existing text layer | Image-only documents are reported as `ocr_required`; OCR is never run implicitly. |
| DOCX | Paragraphs, headings, and tables | Embedded macros and objects are not executed. |

Structured JSON, CSV, and TSV records accept these conservative field aliases:

| Meaning | Accepted field names | Required |
| --- | --- | --- |
| Display title | `title`, `display_date`, `name` | Yes |
| Patch body | `content`, `body`, `notes`, `description` | Yes |
| Published date | `published`, `patch_date`, `date` | No, when the title already contains a date |
| Canonical source | `source_url`, `url`, `link` | No; only credential-free HTTP(S) URLs are retained |
| Truncated record | `excerpt` | No; accepts a boolean or `true`/`false`, `yes`/`no`, `1`/`0` text |

For JSON, use either a top-level array or an object containing exactly one
`patches` or `records` array. CSV/TSV headers are case-insensitive. Supplying
conflicting aliases, invalid field types, duplicate normalized headers, or
short/missing content produces an indexed rejection in the import report.
Structured bodies are treated as opaque content, so a dated heading inside a
body cannot accidentally create a second patch record.

Renamed copies of the archive's own JSON and CSV exports are valid import
sources. Archive-shaped records intentionally prefer `display_date` over the
shorter presentation `title`; ordinary structured files still reject
conflicting aliases. The standard generated filenames and active output paths
remain excluded to prevent self-import. Rejected source URLs produce a generic indexed warning, while the
URL value itself is omitted from both public provenance and the report so
embedded credentials cannot leak.

A plain-text record can be as small as:

```text
August 20, 2026
------------------------------
**Highlights**

- Corrected an issue with an encounter.
```

The date can also be part of a descriptive heading, such as
`August 20, 2026 — Game Update Notes`. Files do not need a special prefix.

For a scanned PDF, run OCR with a tool you trust, verify the result, and add the
OCR output as a text-layer PDF or UTF-8 TXT file. The deliberate review step
prevents silent OCR errors from becoming historical records.

## Official update-notes feed and saved forum pages

The forum advertises a recent-items RSS feed. Synchronize that one allowlisted
endpoint into the same corpus directory, then run the normal dry-run/import
workflow:

```sh
npm run patches:sync-feed -- /path/to/patcheq/official-game-update-notes-live.rss
```

The sync command is not a forum crawler. It validates HTTPS redirects, response
size, XML structure, and cache metadata; it writes atomically and supports
conditional requests. The feed exposes only recent items and some are excerpts,
so it cannot by itself reconstruct older gaps.

For a missing historical thread, save the public thread page as HTML in your
browser and place it in the corpus. The XenForo adapter deliberately extracts
only the first post. This provides a one-document review boundary without
automating pages that the forum excludes from crawling.

## Configuration

The defaults are in `scripts/patch-import.config.json`. Keep that file under
version control and pass a small site-specific override when needed:

```json
{
  "schema_version": 1,
  "discovery": {
    "exclude_globs": ["**/drafts/**"]
  },
  "source_overrides": [
    {
      "pattern": "(?:^|/)unverified/",
      "role": "corroborating source",
      "admit_new_records": false
    }
  ]
}
```

```sh
node scripts/build-patch-history.mjs /path/to/patcheq database/data \
  --config /path/to/site-patch-import.json --strict --dry-run
```

Overrides are merged onto the safe defaults; custom `source_overrides` are
appended so site rules run after the built-in archival rules. Other JSON arrays
replace their default arrays. Common settings include recursive
include/exclude globs, maximum source size, adapter enablement and limits,
source priority, whether a source may create new records, expansion dates, and
category rules. Symlinks are never followed, source URLs must be HTTP(S) without
credentials, and document adapters run out-of-process with time and output
limits.

## Disabling the site feature

Set this in `.env`:

```dotenv
PATCH_HISTORY_ENABLED=false
```

Then clear cached Laravel configuration:

```sh
php artisan optimize:clear
```

With the flag disabled, both `/patches` and compatibility `/patch` routes are
absent, patch search suggestions are not loaded, and the navigation and RSS
discovery link are omitted. Generated artifacts may remain on disk; they are
not served.

## Troubleshooting

- A failed strict import does not replace the published artifacts. Fix the
  reported source and repeat the dry run.
- `ocr_required` means the PDF has no usable text layer.
- `dependency_missing` means the optional Python package for that adapter is
  unavailable.
- `corroborating_record_unmatched` is a warning for a source configured not to
  create records; it remains visible in the report for manual review.
- Never delete or rename the prior JSON before importing. It is the identity
  map that keeps existing `/patches/{slug}` URLs stable.
