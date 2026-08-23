# Modern EQEmu Allaclone
![Laravel](https://img.shields.io/badge/laravel-%23FF2D20.svg?style=for-the-badge&logo=laravel&logoColor=white)![TailwindCSS](https://img.shields.io/badge/tailwindcss-%2338B2AC.svg?style=for-the-badge&logo=tailwind-css&logoColor=white)![DaisyUI](https://img.shields.io/badge/daisyui-5A0EF8?style=for-the-badge&logo=daisyui&logoColor=white)

## Live Demo
You can see this in use on [Project Lazarus](https://www.lazaruseq.com/alla/)

## Requirements

- PHP >= 8.2, Composer, Node.js >= 20, Mysql/MariaDB, and an EQemu DB.

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

Build the immutable, content-addressed artifact outside the public web root:

```bash
php artisan spell-history:build \
    --source=/path/to/lucy_spelldata_live_2002-2025
```

Use `--output=/path/to/artifacts` when the default
`storage/app/private/spell-history` location is not appropriate. Copy that
artifact directory when deploying to another server, then configure and enable
the feature:

```dotenv
SPELL_HISTORY_ENABLED=true
SPELL_HISTORY_BASELINE_DATE=2006-01-01
SPELL_HISTORY_PAGE_SIZE=25
# SPELL_HISTORY_SOURCE_PATH=/path/to/lucy_spelldata_live_2002-2025
# SPELL_HISTORY_ARTIFACT_PATH=/path/to/artifacts
```

After changing these values, refresh Laravel's cached configuration with
`php artisan optimize:clear` (or the equivalent configuration-cache step in
your normal deployment).

`SPELL_HISTORY_SOURCE_PATH` lets the build command run without `--source`; it is
never read by a web request. Keep both the Lucy source archive and generated
artifacts outside the public document root. The build streams bounded records,
stages a complete replacement, re-verifies every source checksum, then switches
the `CURRENT` pointer under a lock. An identical rebuild validates and reuses
the existing content-addressed dataset.

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

`SPELL_HISTORY_BASELINE_DATE` accepts `YYYY-MM-DD` (the end of that calendar
day) or `YYYY-MM-DDTHH:MM:SS`. Snapshot timestamps are intentionally treated as
timezone-naive Lucy capture times. The latest capture at or before the cutoff is
resolved first. If the spell is present there, its most recent recorded revision
represents the state at that cutoff and is highlighted; unchanged captures are
omitted. No revision is highlighted when the spell was not yet observed, was
confirmed absent, or has uncertain availability at the resolved capture. This
setting is independent of `current_expansion`; for example, a Dragons of Norrath
server can use a 2006 spell-data cutoff.

Keep `SPELL_HISTORY_ENABLED=false` until a successful build is deployed. A
rebuild stages and validates a new dataset before atomically switching the
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

### Historical item revisions

Item pages can use the same file-only serving model without adding an item
history database. The authorized crawler writes one bounded JSON artifact per
item beneath a SHA-256 shard, for example item `20542` is published as
`items/dc/20542.json`. A history request opens only that item file. The UI offers
shareable Cards and compact Lucy-style Table modes through
`?view=cards|table`, and neither mode queries an application database.

The crawler is intentionally conservative and must only be initialized when
Lucy/ZAM has authorized the crawl. Record a monitored contact and the supplied
authorization or ticket reference in its private configuration:

```bash
node scripts/lucy-item-history-crawler.mjs init \
    --contact=operator@example.com \
    --authorization-ref=ZAM-TICKET-OR-WRITTEN-REFERENCE
```

Initialization is local-only and makes no request. By default, the crawl uses
one worker, a 30-second start-to-start interval plus 0-5 seconds of jitter, and
a 2,000-request UTC daily cap. The authorized Lucy target can be configured as
low as a 2-second interval and as high as a 100,000-request daily cap, but those
are validation ceilings rather than recommended starting values. These
limits are persisted before each request, so restarting the process cannot
accidentally burst or reset the daily budget. `Retry-After` is honored and
transient network, 429, and server failures back off durably rather than moving
rapidly to another item. A 429 waits at least one hour, and a repeated 429
pauses the crawler for operator review.

The default `direct-detail` capture strategy archives every historical detail
page. For a substantially lower-request structured history, initialize with
`--capture-strategy=reversible-delta`. That mode captures the item history page
and exactly one current raw anchor (preferring Live), verifies every field
transition as a reversible source-isolated chain, and publishes a version 2
artifact with explicit captured-versus-reconstructed provenance. It never
silently falls back to per-entry requests: an ambiguous or conflicting delta
causes a safety pause. Historical details captured by an earlier direct run are
retained as partial direct evidence.

If Lucy presents its cookie bootstrap page, the crawler follows it only when it
adds exactly `setcookie=1` to the same-origin page URL. That handshake consumes
a normal rate-limited request slot, and the resulting cookies are kept only in
the running worker's memory.

Start it as a hidden detached process, then inspect or control it without making
any Lucy request:

```bash
node scripts/lucy-item-history-crawler.mjs start
node scripts/lucy-item-history-crawler.mjs status
node scripts/lucy-item-history-crawler.mjs status --json
node scripts/lucy-item-history-crawler.mjs pause --reason="maintenance"
node scripts/lucy-item-history-crawler.mjs resume
node scripts/lucy-item-history-crawler.mjs stop --reason="planned shutdown"
node scripts/lucy-item-history-crawler.mjs retry-failures
node scripts/lucy-item-history-crawler.mjs sweep
```

The foreground equivalent is `run`. Progress is checkpointed after every
capture, so an interrupted item resumes from its saved responses. The crawler
automatically pauses on authorization failures, challenge/error bodies, or an
unrecognized Lucy layout. An item with a permanent missing page is recorded
under the private error tree and is not published as complete; after reviewing
the problem, use `retry-failures` followed by `start` to revisit unresolved
items. An explicit retry invalidates the specific missing response checkpoint
before requesting it again. To resume automatically after a machine reboot,
run the `start` command from the host's normal service manager or task scheduler.

After a completed backfill, `sweep` prepares another generation without making
a request. Its next `start` refreshes the item list, rechecks every item's
history page at the same durable rate, fetches only newly discovered immutable
entry IDs in `direct-detail` mode, refreshes the configured raw anchor records,
and atomically republishes changed item JSON. This captures items and revisions
added during a long prior pass without redownloading every historical detail.
Previously observed entry IDs are retained monotonically if a later Lucy history page omits them; a
conflicting reuse of an entry ID pauses publication for operator review.
Schedule `sweep` followed by `start` at the cadence covered by the authorization;
do not overlap sweeps.

The private crawl workspace defaults to
`storage/app/private/lucy-item-history-crawl`. It contains the queue, durable
rate/control/status state, per-item checkpoints, errors, and content-addressed
gzip copies of every response. Keep it private. The separate site artifact root
defaults to `storage/app/private/item-history` and receives only complete item
files through atomic replacement. You can override both locations during
initialization:

```bash
node scripts/lucy-item-history-crawler.mjs init \
    --workspace=/private/crawl-work \
    --artifact-root=/private/item-history \
    --contact=operator@example.com \
    --authorization-ref=ZAM-TICKET-OR-WRITTEN-REFERENCE
```

The workspace, artifact root, and their parent path components must be real
directories rather than symlinks or junctions. Raw captures are checksummed
before reuse and published from a synced temporary file through a same-directory
hard link; use a filesystem with hard-link support (such as NTFS or ext4). A
truncated prior capture is quarantined and rebuilt, while unsupported filesystems
fail safely without publishing it. Non-loopback runs are pinned to exactly
`https://lucy.allakhazam.com/`.

An already downloaded Lucy item list can be supplied with `--item-list-file`
to avoid the one seed download. History pages discover the Live/Test revision
entry IDs. In `direct-detail` mode, each historical entry page is captured in
sequence, followed by the current raw record for every represented source. In
`reversible-delta` mode, no historical entry page is requested and only the
preferred current raw source is captured; the JSON retains Lucy's change rows,
verified reconstruction metadata, and any direct details already present in
the checkpoint. Lucy does not expose a raw record for an old `entryid`, so a
reconstructed state must not be described as a byte-for-byte historical Lucy
page. Observation timestamps must not be presented as exact patch times.

After artifacts exist, enable the site reader and clear Laravel's cached
configuration:

```dotenv
ITEM_HISTORY_ENABLED=true
ITEM_HISTORY_PAGE_SIZE=25
# ITEM_HISTORY_ARTIFACT_PATH=/private/item-history
```

The page size is a maximum. A text-heavy revision page may split earlier to stay
within the reader's conservative response-render budget, without dropping a
revision.

```bash
php artisan optimize:clear
```

The crawler/parser tests use local HTML fixtures and make zero Lucy requests:

```bash
npm run test:js
```

Always install this outside your publically accessible web directory. Symlink the /public folder to your public accessible web directory.

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
