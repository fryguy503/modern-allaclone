# Tower of Frozen Shadow Encounter Journals

The tower uses the existing Encounter Journal document interface. These twelve
entries need no application route, controller, schema migration or new display
component. Install the JSON documents alongside the existing collection under
`resources/data/encounters`.

| Expedition | Version | Guide | NPC links | Equipment pool |
| --- | ---: | --- | --- | --- |
| The Seven Silences | 20 | `tofs-the-last-ember` | 111800 | 148200–148203 |
| The Seven Silences | 20 | `tofs-the-missing-chapter` | 111801 | 148204–148207 |
| The Seven Silences | 20 | `tofs-supper-for-the-sightless` | 111802 | 148208–148211 |
| The Seven Silences | 20 | `tofs-a-body-on-loan` | 111803 | 148212–148215 |
| The Seven Silences | 20 | `tofs-until-death-refuses` | 111804 | 148216–148219 |
| The Seven Silences | 20 | `tofs-the-borrowed-furnace` | 111805 | 148220–148223 |
| The Seven Silences | 20 | `tofs-seven-silences-finale` | 111806 | 148224–148227 |
| The Unwritten Hour | 21 | `tofs-bailiff-of-borrowed-names` | 111807 | 148228–148231 |
| The Unwritten Hour | 21 | `tofs-the-uninvited-court` | 111808 | 148232–148235 |
| The Unwritten Hour | 21 | `tofs-the-marriage-of-ash` | 111809 | 148236–148239 |
| The Unwritten Hour | 21 | `tofs-vhalsera-and-the-borrowed-heart` | 111810 | 148240–148243 |
| The Unwritten Hour | 21 | `tofs-unwritten-hour-finale` | 111811, 111812 | 148244–148247 and 148248–148251 |

All entries use `frozenshadow` and their event version. They do not link the
ordinary public tower's NPC templates to the expedition guides. Floor 6A and
6B deliberately share one group guide and one raid guide.

Each guide contains narrative context, role advice, ordered phases, mechanic
warnings, recovery guidance and the precise reward selection contract. Group
entries explain the progressive difficulty from below the Mistmoore group
encounter at the entrance to comparable pressure on the upper floors.

The seven group guides document one independent splinter roll per boss at
20%, 25%, 30%, 35%, 40%, 45% and 60%, respectively. Their equipment pools each
contain exactly four uniformly selected items. The raid's first four bosses
each select one item from four; the finale selects one from each of two pools.
Its six guaranteed continuation items are structured item references with
quantity six, distinct from its two equipment rewards.

Both finale guides have neutral slugs and `spoiler: true`. Their titles and
mechanics remain hidden until the reader explicitly reveals them. Equipment,
entry-currency and continuation-item names appear only in structured item
references so the Library's discovery policy can mask them. Encounter
instructions use functional labels for these items.

Published records use source-reviewed metadata with the quest base revision,
exact SHA-256 hashes and notes identifying the uncommitted implementation.
These hashes identify reviewed source bytes. They do not establish deployment,
loaded NPC data, client presentation, live balance or in-game completion.

Validate using the ordinary project commands:

```powershell
php artisan encounters:validate --source-root="F:\EQ1\Bastion_Dev\quests"
php vendor/bin/phpunit --filter "EncounterCatalogTest|EncounterJournalTest|EncounterCommandsTest|TofsEncounterJournalTest" --do-not-cache-result
```

`TofsEncounterJournalTest` checks all twelve native guide routes, version and
NPC matching, the complete set of 52 equipment rewards, splinter probabilities,
six continuation rewards, hidden finales and item discovery masking. The test
uses an isolated in-memory SQLite connection. `TOFS_JOURNAL_TEST_PATH` can point
to a staged document directory for validation before installation.

Quest and database installation remain separate from Library publication.
Deploy the matching quest content and its client spell data using the quest
release instructions, then release these journal documents through the normal
Library process. Review and update a document's text before refreshing hashes
after a mechanics change.

The September 30 traditional group revision replaces lanterns, witnesses,
book choices, bells, vessels, rings and valves with named fights. Required
warders awaken bosses; optional support kills suppress spells or remove slow
immunity. Journals describe pulling, saved kills, interruptible casts and
ordinary damage throughout each group fight. The summit uses one initial
non-tank mark, rising to two after both future thresholds, with a 30-second
recording check. All seven group documents were reconciled against the current
mechanics before their source hashes were refreshed.

The ambient population follow-up adds independent inhabitants and native patrols to both expedition versions. All twelve journals distinguish these mobs from named warders and support targets, explain invisibility/undead detection and respawn behavior, and include the new population module in their reviewed source hashes.
