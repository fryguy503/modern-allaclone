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
