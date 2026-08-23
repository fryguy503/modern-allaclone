# Modern EQEmu Allaclone
![Laravel](https://img.shields.io/badge/laravel-%23FF2D20.svg?style=for-the-badge&logo=laravel&logoColor=white)![TailwindCSS](https://img.shields.io/badge/tailwindcss-%2338B2AC.svg?style=for-the-badge&logo=tailwind-css&logoColor=white)![DaisyUI](https://img.shields.io/badge/daisyui-5A0EF8?style=for-the-badge&logo=daisyui&logoColor=white)

## Live Demo
You can see this in use on [Project Lazarus](https://www.lazaruseq.com/alla/)

## Requirements

- PHP >= 8.2, Composer, Mysql/MariaDB, and an EQemu DB.
- Rebuilding the historical corpus also requires Node.js and Python 3. Install the
  optional document adapters with `pip install -r scripts/requirements-patch-import.txt`.

## Historical patch archive

The read-only archive at `/patches` currently contains 682 distinct EverQuest beta, Live, hotfix, and news records from July 1998 through June 2026. It supports phrase search, composable date/topic/type/expansion/source filters, cards/compact/expansion/timeline layouts, formatted and plain-text detail views, provenance, adjacent/related history, RSS, and full or filtered JSON/CSV exports. Coverage reflects the supplied corpus and complete official-feed entries; it does not claim that every intervening forum thread is present.

The generated artifacts live in `database/data`, so serving the archive never reads the external source directory or writes to either the application or EQEmu database. Imported text is treated as untrusted and escaped before structural formatting. CSV exports are UTF-8, RFC 4180-compatible, and neutralize spreadsheet formulas.

Set `PATCH_HISTORY_ENABLED=false` to remove every patch route, navigation link,
RSS advertisement, and patch suggestion. Run `php artisan optimize:clear` after
changing the value on a server that caches configuration.

To check and then rebuild from a supplied `patcheq` corpus:

```sh
npm run patches:import:check -- /path/to/patcheq database/data --report database/data/everquest-patch-import-report.json
npm run patches:import -- /path/to/patcheq database/data
npm run patches:validate -- /path/to/patcheq database/data
```

Documents can be placed anywhere below the source directory. TXT, Markdown,
saved HTML/XenForo pages, RSS/Atom/XML, JSON, CSV, TSV, text-layer PDF, and DOCX
files are discovered recursively. The importer inventories every supplied
artifact by hash, recovers historical encodings, keeps stable public URLs,
deduplicates records while retaining occurrence-level provenance, and refuses
unexplained record removals. The validator then checks the JSON, CSV,
suggestion index, and deterministic import report as one publication set.

See [docs/patch-history-importing.md](docs/patch-history-importing.md) for source
format examples, configuration overrides, OCR guidance, official-feed syncing,
saved forum-page imports, and recovery steps.

## Installation

[Download the item/spell icons!](https://github.com/chadw/modern-allaclone/releases/download/1.0.0/icons.zip) and unzip them to /public/img/icons

### To setup a local development environment
```
git clone https://github.com/chadw/modern-allaclone.git
cd modern-allaclone

composer install
npm install
npm run dev

cp .env.example .env
```
Create a allaclone db utf8mb4/utf8mb4_unicode_ci
Edit the .env variables to point to your allaclone db
```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=allaclone
DB_USERNAME=root
DB_PASSWORD=
```

Edit the .env variables to point to your eqemu db.
```
EQEMU_DB_HOST=127.0.0.1
EQEMU_DB_PORT=3306
EQEMU_DB_DATABASE=peq
EQEMU_DB_USERNAME=user
EQEMU_DB_PASSWORD=password
```

Now run migrations. This will populate your allaclone db with tables used for sessions and caching.
```
php artisan migrate
```

### To set this up in production
Copy over your allaclone db and run the following command on your production server
```
php artisan optimize:clear
```

Next build the assets. Do this on your dev server preferrably.
```
npm run build
```
Then copy the /public/build/ folder to your production server.

### Location maps

NPC pages can render their verified spawn locations on precompiled Brewall zone maps. Put the Brewall map folder beside this repository (the default is `../brewels`) and build the compact, content-addressed browser assets:

```
npm run maps:build
npm run maps:check
```

Only base files such as `qeynos.txt` are compiled; numbered overlay layers such as `_1`, `_2`, and `_3` are intentionally excluded. Optional replacements in the source folder's `legacy/` directory are compiled alongside the current maps. Select legacy geometry per zone with the comma-separated `EQ_MAP_LEGACY_ZONES` setting; leave it empty to use current maps everywhere.

To use a different source directory, run `npm run maps:build -- --source /path/to/maps`. Commit or deploy `public/maps/` with the application. Rebuild after updating either current or legacy source maps.

The viewer includes Brewall attribution. Before redistributing map data, confirm that your source archive's terms permit your intended use.

### Historical spell timeline

Spell pages can optionally show a read-only history compiled from Lucy Live
spelldata snapshots. Compilation is an offline deployment step: web requests
never scan the raw CSV archive and do not query the EQEmu database for history.

Players can switch each history page between the default collapsible Cards,
a structured Diff table, and a compact Lucy-style Date/Change list. Each view
has its own canonical, publicly cacheable URL and renders only the selected
representation; all three read the same bounded artifact page and execute no
database queries.

Place the Lucy snapshots in the standard private source directory,
`storage/app/private/lucy-spelldata`, then build the immutable,
content-addressed artifact:

```bash
php artisan spell-history:build
```

The compiled artifact is written to the standard private artifact directory,
`storage/app/private/spell-history`. The command-line `--source` and `--output`
options remain available for one-off builds using non-standard locations:

```bash
php artisan spell-history:build \
    --source=/path/to/lucy_spelldata_live_2002-2025 \
    --output=/path/to/artifacts
```

Copy the compiled artifact directory when deploying to another server, then
edit the `spell_history` section in `config/everquest.php` to enable the feature
and select the server's spell-data cutoff:

```php
'spell_history' => [
    'enable'        => true,
    'baseline_date' => '2006-01-01',
    'source_path'   => storage_path('app/private/lucy-spelldata'),
    'artifact_path' => storage_path('app/private/spell-history'),
    'page_size'     => 25,
    'max_page'      => 500,
],
```

After changing these values, refresh Laravel's cached configuration with
`php artisan optimize:clear` (or the equivalent configuration-cache step in
your normal deployment).

The configured `source_path` is read only by the build command, never by a web
request. Keep both the Lucy source archive and generated artifacts outside the
public document root. The build streams bounded records, stages a complete
replacement, re-verifies every source checksum, then switches the `CURRENT`
pointer under a lock. An identical rebuild validates and reuses the existing
content-addressed dataset.

Completed datasets are retained for rollback and can be pruned explicitly. The
prune command is a dry run unless `--apply` is supplied:

```bash
php artisan spell-history:prune
php artisan spell-history:prune --keep-recent=2 --minimum-age-hours=24 --apply
```

The active dataset is never removed. By default, the two newest inactive
rollback datasets are also retained, and neither a newly completed dataset nor
one displaced by a recent activation is eligible for 24 hours. Eligible
directories are moved to private tombstones while holding the activation lock,
then their potentially large trees are deleted after releasing that lock; a
later prune safely discovers any tombstone left by an interrupted process. Use
`--path=/absolute/artifact/root` when pruning a non-default artifact location.

Plan filesystem capacity for both bytes and file entries. This source archive
currently produces roughly 600 MiB and 74,000 files per completed dataset. With
two rollback datasets retained, allow at least four dataset equivalents (about
2.4 GiB and 296,000 file entries) so a replacement can be staged before the old
copies are pruned, plus normal filesystem headroom.

`baseline_date` accepts `YYYY-MM-DD` (the end of that calendar day) or
`YYYY-MM-DDTHH:MM:SS`. Snapshot timestamps are intentionally treated as
timezone-naive Lucy capture times. The latest capture at or before the cutoff is
resolved first. If the spell is present there, its most recent recorded revision
represents the state at that cutoff and is highlighted; unchanged captures are
omitted. No revision is highlighted when the spell was not yet observed, was
confirmed absent, or has uncertain availability at the resolved capture. This
setting is independent of `current_expansion`; for example, a Dragons of Norrath
server can use a 2006 spell-data cutoff.

Keep `spell_history.enable` set to `false` until a successful build is deployed.
A rebuild stages and validates a new dataset before atomically switching the
`CURRENT` pointer, so live requests continue reading the previous immutable
dataset during compilation. Lucy captures indicate when a value was observed,
not necessarily the exact time it changed on Live. The compiler reports net
semantic differences between comparable snapshot fields; source-formatting-only
differences and the initial appearance of an exporter column are not presented
as spell changes because neither proves that the Live spell changed.

A disappearance is shown only after two trusted captures omit the spell. A
capture with an anomalous record-count drop remains available for value history
but cannot confirm removals; a single missing capture is shown as uncertain.
This protects the timeline from incomplete Lucy exports.

History requests are stateless, do not query either application database, and
read only the manifest plus one bounded, sharded spell artifact. Successful
pages use a five-minute public cache with a one-minute stale-revalidation window
and representation-derived ETags. Disabling the feature prevents new origin
responses, but previously cached copies can remain available for the five-minute
freshness window and, where supported, the one-minute stale window. Purging a
reverse-proxy/CDN removes shared copies but cannot remove copies already stored
in players' browsers. Custom EQEmu spells that are absent from the Lucy archive
have no historical route and return 404.

Always install this outside your publically accessible web directory. Symlink the /public folder to your public accessible web directory.

### Optional tradeskill planner

The recursive tradeskill planner can be disabled without changing application code:

```env
TRADESKILL_PLANNER_ENABLED=false
```

When disabled, planner links are hidden and planner routes return `404`. When enabled, plans are saved only in the visitor's browser. Share links keep their plan state in the URL fragment, so Modern Allaclone does not need user accounts or a server-side plans table.

After changing the setting on a production installation, refresh Laravel's cached configuration:

```bash
php artisan optimize:clear
```

## Screenshots

![global search](https://github.com/user-attachments/assets/928ad81d-bbd0-459e-90ab-c9a60879044a)

![zones](https://github.com/user-attachments/assets/186bb44c-d820-404e-b630-bcf993cdf114)

![zone view](https://github.com/user-attachments/assets/b8d27fe8-5037-4974-8d7b-988afa0d3a75)

![npc details](https://github.com/user-attachments/assets/194a897f-5123-4cae-a691-9c6c8a7d3862)

![item details](https://github.com/user-attachments/assets/eaef9979-d73b-4db0-aa7b-64d545f0d8c2)

![spell search and table view](https://github.com/user-attachments/assets/95cd93bf-9d93-4eb6-a924-012492a0c0d0)

![additional spell data](https://github.com/user-attachments/assets/feb15f5c-28c1-4acc-9f78-c10a47eacc70)

![item tooltips](https://github.com/user-attachments/assets/27fb0872-4765-4588-b414-0fd0f161e478)

![recipe search and details](https://github.com/user-attachments/assets/3ccb49ad-76f7-454b-86b6-ca8a2d5a145e)

## License

Licensed under the [MIT license](https://opensource.org/licenses/MIT).
