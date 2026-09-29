# Encounter Journal authoring

The Library's Encounter Journal uses one JSON document per encounter in
`resources/data/encounters`. The shared interface renders the document; ordinary
new entries do not need a new controller, route, template or database migration.
Published documents automatically appear in the journal index and on matching
NPC and zone pages.

A document can describe one boss, a multi-boss fight or an objective-based event.
Use the same `group` value for encounters that belong to the same expedition.
Link every participating boss through `npc_ids`; use a separate document when
the encounter has a different version or materially different mechanics.

The approved repository-wide encounter batch is indexed in
[the generation report](encounter-audit/generation-report.md). It maps saved
approval IDs to documents and records publication status and source questions.
The initial publication review released 423 new guides and kept eight drafts
with unresolved encounter identity or entry logic. The editable workbook remains the decision record;
`encounter-audit/saved-decisions.json` is its imported snapshot. Recheck the
snapshot and source hashes with `node docs/encounter-audit/verify_generation.mjs`.
The verifier uses the sibling `../quests` checkout by default; set
`EQEMU_QUEST_ROOT` to use another location. Explicit publication decisions are
recorded in `encounter-audit/publication-review.json`, including why each held
entry is still a draft. The batch generation scripts are historical authoring
tools; use the document workflow below for subsequent edits rather than
regenerating the published collection from those snapshots.

Readers reach the journal through **More -> Encounter Journal** or
`/encounters`. Deployment must include `resources/data/encounters`; publication
is a document status change and does not need a database migration. The journal
catalog reads these files per request. The initial feature also requires the
normal frontend asset build described in the project README.

## Configuration and draft preview

`ENCOUNTER_JOURNAL_ENABLED=true` enables the journal, its navigation entry and
the automatic NPC/zone links. Set it to `false` to hide those links and return
404 from the journal routes. The content directory is configured by
`everquest.encounter_journal.path`, defaulting to `resources/data/encounters`.

For local review, set `ENCOUNTER_JOURNAL_PREVIEW_DRAFTS=true` with
`APP_ENV=local` or `APP_ENV=testing`. Drafts then appear alongside published
entries with a preview banner. Production environments ignore the draft preview
setting. Refresh Laravel's configuration cache after changing environment
settings using the deployment's normal configuration-cache procedure.

Published spoiler entries show a generic title until the reader explicitly
reveals them. Their URL slug, expedition group and zone remain visible, so those
fields must also be safe to show before the reveal. A draft is the setting to
use when even the existence of an encounter should remain private.

## Add an encounter

Run the authoring command from the Library application directory:

```sh
php artisan encounters:make my-encounter
```

It creates `resources/data/encounters/my-encounter.json` as a draft. Fill in the
identity, zone, NPC links and summary, then add whichever content sections help a
player understand the event. The command refuses to overwrite an existing file.

Validate the collection:

```sh
php artisan encounters:validate
```

When the quest checkout is available, also compare every recorded source hash
against the files you reviewed:

```powershell
php artisan encounters:validate --source-root="F:\EQ1\Bastion_Dev\quests"
```

Review the result and choose its publication and spoiler settings explicitly.
A draft is excluded from public routes, listings and NPC/zone links. Change
`status` to `published` only when the content is ready for the Library.

The checked-in guardian entries are examples of short encounter documents. The
initial `last-eclipse-finale.json` document demonstrates a larger fight with
roles, health gates, item mechanics and multiple loot pools. It is published
behind a spoiler warning, with a neutral URL and concealed encounter name until
the reader chooses to reveal it.

## Document fields

| Field | Meaning |
| --- | --- |
| `schema_version` | Currently `1`. |
| `slug` | Unique lowercase kebab-case identifier, matching the JSON filename. Use a neutral slug for a hidden encounter. |
| `title` | Player-facing encounter name. |
| `group` | Expedition or event grouping used by the journal. |
| `type` | `raid`, `group` or `event`. |
| `zone` | `short_name`, player-facing `name` and numeric instance `version`. Version matters even when the short name is shared. |
| `npc_ids` | Positive NPC template IDs associated with this exact encounter. |
| `status` | `draft` or `published`. Publication controls visibility, independently of source verification. |
| `spoiler` | Boolean controlling the spoiler treatment of published content. It is a reader warning/reveal mechanism, not access control. Use `draft` to keep content unavailable. |
| `summary` | One short player-facing description for listings. |
| `overview` | Array of introductory paragraphs. |
| `roles` | Optional array of role objects: `id`, `label`, `tips` (strings). |
| `phases` | Optional ordered array: `id`, `label`, `trigger`, `description`. Triggers may describe health, elapsed time, an objective or any other script condition. |
| `abilities` | Optional array: `id`, `name`, `summary`, `description` (paragraphs), `tags` (strings), `roles` (role IDs), `spell_ids` (positive integers). |
| `sections` | Optional reusable content blocks: `id`, `title`, `paragraphs`, `bullets`, `items`. Use them for positioning, adds, objectives or special item sequences. |
| `loot` | Optional reward groups: `title`, `description`, `items`. Explain the selection rules here, such as one random item from each pool. |
| `sources` | Review metadata, required for published entries; see below. |

All text is plain text. Templates escape it rather than interpreting authored
HTML. Optional arrays may be omitted or left empty; empty sections are not
rendered. IDs for roles, phases, abilities and sections are lowercase kebab-case
and unique within their list. An ability's `roles` values must match role IDs
defined in the same entry.

Items are structured references:

```json
{
  "id": 147750,
  "name": "Gravewarden's Seal",
  "quantity": 2
}
```

`name` is the source-reviewed label and `quantity` is a positive integer.
The Library resolves the actual item against its database and applies its
existing discovery policy before displaying an item name or link. Do not put
equipment reward names in free-text summaries, descriptions, phase text, tags or
other metadata: those strings cannot be reliably filtered by item discovery.
Keep reward names in `loot[].items` and special item references in
`sections[].items`. Mechanic instructions may use a short functional label,
such as "Grave seal", when it is needed to explain the encounter.

Use real database spell IDs only when the mechanic actually uses that spell.
Scripted direct damage or a quest state transition may have no spell ID; leave
`spell_ids` empty instead of linking an unrelated visual or dummy spell.

A compact content example:

```json
{
  "schema_version": 1,
  "slug": "example-encounter",
  "title": "Example Encounter",
  "group": "Example Expedition",
  "type": "event",
  "zone": {
    "short_name": "examplezone",
    "name": "Example Zone",
    "version": 0
  },
  "npc_ids": [123456],
  "status": "draft",
  "spoiler": false,
  "summary": "Protect the ritual while handling successive add waves.",
  "overview": [
    "Complete the objectives in order while defending the ritual keeper."
  ],
  "roles": [
    {
      "id": "damage",
      "label": "Damage dealers",
      "tips": ["Defeat the active wave before the next objective."]
    }
  ],
  "phases": [
    {
      "id": "defend-ritual",
      "label": "Defend the ritual",
      "trigger": "After the keeper begins the ritual",
      "description": "Protect the keeper until the final wave is defeated."
    }
  ],
  "abilities": [],
  "sections": [
    {
      "id": "objectives",
      "title": "Objectives",
      "paragraphs": [],
      "bullets": ["Speak to the keeper.", "Defeat each wave."],
      "items": []
    }
  ],
  "loot": []
}
```

The example is intentionally a draft with fictitious IDs. Replace them with the
actual zone and NPC IDs, then add source metadata before publication.

## Review provenance

A published entry includes:

```json
{
  "sources": {
    "reviewed_at": "2026-09-28",
    "revision": "the-full-quest-git-commit",
    "verification": "source-reviewed",
    "files": [
      {
        "path": "somezone/encounters/example.lua",
        "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
      }
    ],
    "notes": [
      "Role advice was derived from the script; a live raid has not verified it."
    ]
  }
}
```

Use the current quest Git revision and SHA-256 of each relevant file. Paths are
relative to the quest checkout, with forward slashes and no `..` segments.
Include the encounter script and supporting SQL/configuration files that define
the documented rewards or tuning.

On Windows, these read-only commands provide the values:

```powershell
git -C "F:\EQ1\Bastion_Dev\quests" rev-parse HEAD
git -C "F:\EQ1\Bastion_Dev\quests" status --short -- somezone/encounters/example.lua sql/example.sql
Get-FileHash "F:\EQ1\Bastion_Dev\quests\somezone\encounters\example.lua" -Algorithm SHA256
```

If a reviewed file has uncommitted changes, record that in `sources.notes`.
The Git revision identifies the base checkout; the hashes identify the exact
reviewed file contents. A successful hash check establishes a match with that
checkout, not a match with a deployed zone process or live database.

Use `source-reviewed` after checking the quest and data definitions. Use
`live-verified` only after the documented behavior has been observed against
the matching deployed version, and record that evidence in the review notes.
A successful local test, a source hash match or publication alone does not prove
live behavior.

The initial Mistmoore documents use the local September 28, 2026 source,
including working-tree changes to the encounter script. Their timings and
thresholds are source-backed; role advice is derived. Exact pulse damage is
omitted because loaded NPC scaling can differ between zone processes.

## Generate and maintain entries

Source-assisted drafting is the intended workflow for a list of zones:

1. Find the version-specific encounter and all supporting scripts/data.
2. Trace entry conditions, phases, warnings, adds, item use, reset behavior and
   reward selection.
3. Write one draft per encounter using the existing fields. Include each NPC
   template ID and the exact zone version.
4. Verify claims against the source, preserve hidden progression deliberately
   and keep item names in structured item references.
5. Record the revision and hashes, validate, review the rendered guide and
   choose publication settings.

The authoring command scaffolds a document; it does not infer a complete
encounter from arbitrary Lua. Generating drafts requires a source review and
must never execute quest scripts inside the web request or authoring process.
Quest code can have database and game-world side effects.

When a quest changes, run validation with `--source-root`. A changed hash means
the document needs review; it does not automatically mean every sentence is
wrong. Update the content and its evidence together after checking the change.
Do not refresh hashes merely to silence a mismatch.

Descriptions, role tips and trigger strings are deliberately general enough for
different encounter styles. Prefer adding content to the existing structures.
Add a new display component only if an encounter needs a meaningful interaction
the current sections cannot express, and make that component reusable.

Journal documents and the application code must both be deployed through the
normal Library release process. Editing these files does not apply quest SQL,
reload encounters or verify the game's live version.
