# Encounter journal generation and publication

Imported saved approvals on 2026-09-29: 422 approved, 14 disapproved, 0 pending.

The approved rows represent 420 encounter groups after combining E030, E031 and E032 into the Agnarr Event. They produced 431 new documents. Separate documents are used where a zone version needs its own mechanics or source caveats. The 5 existing Mistmoore documents are unchanged.

423 new documents are published in the application and 8 remain drafts. Published entries appear under More -> Encounter Journal at /encounters after the updated files are deployed. Publication does not change quests or certify live availability. Mayong retains the existing spoiler warning.

## Publication review

Reviewed 2026-09-29 following the request to publish. Missing optional NPC links, absent database loot/spell data and source-only verification do not prevent publication. Entries with unresolved core identity or misleading encounter instructions remain drafts.

| Held draft | Reason |
| --- | --- |
| [Agnarr Event](../../resources/data/encounters/bothunder-agnarr-event.json) | The normal expedition uses version 0, but Agnarr removes himself from nonzero instances unless version 200. Seasonal Agnarr is published separately. |
| [Find Fibblebrap 5](../../resources/data/encounters/corathusb-find-fibblebrap-5.json) | The mission-5 loader selects a file containing mission-1 task tables and objectives; mission identity and core instructions remain unresolved. |
| [Find Fibblebrap 4: The Korlach](../../resources/data/encounters/illsalinb-find-fibblebrap-4-the-korlach.json) | The encounter still contains placeholder NPC templates and conflicting task IDs. |
| [Seasons: Watery Death](../../resources/data/encounters/kedge-seasons-watery-death.json) | Only the access launcher was identified; opponents, mechanics and completion remain unidentified. |
| [Scorpion hatchling ambush](../../resources/data/encounters/lakeofillomen-scorpion-hatchling-ambush.json) | The prone NPC that triggers the ambush is unidentified, so a player cannot reliably follow the starting instructions. |
| [An End to the Storms: Yar`Lir](../../resources/data/encounters/thundercrest-an-end-to-the-storms-yar-lir.json) | The loader selects version 3 while the module removes the boss unless version 200; no version-200 loader was found. |
| [NPC 158006: teleport hunt](../../resources/data/encounters/vexthal-npc-158006-teleport-hunt.json) | The document still has a numeric placeholder instead of a verified encounter identity, and the spawn version is unresolved. |
| [Primal door riddle](../../resources/data/encounters/yxtta-primal-door-riddle.json) | Broken entry initialization and status logic leave the documented door-access sequence unverified. |


## Bastion of Thunder

Evynd Firestorm, Emmerik Skyfury and Agnarr the Storm Lord form one continuous encounter in each version document. Both documents cover the lower tower, middle tower, summit, portal waves, health thresholds and Karana dialogue.

The normal draft records a source conflict: Gozer creates version 0, but Agnarr removes himself in a nonzero instance unless its version is 200. The seasonal draft follows the explicit version 200 branch. The quest scripts have not been changed.

## Review notes

The table below preserves source questions and review limitations recorded during drafting. Some early notes recommended further review even when the final guide already avoids the uncertain behavior. The explicit publication decisions now distinguish those source caveats from blocking content gaps. Database-only NPC spell lists and equipment loot were not fabricated.

154 documents have specific review notes. These range from missing database identities to source conflicts; they are not all broken encounters. The publication decision and held-draft list above distinguish these from blocking content gaps.

| Document | Version | Review notes |
| --- | ---: | --- |
| [Trondol Shir escort](../../resources/data/encounters/acrylia-trondol-shir-escort.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Kyv Rux Vhedt](../../resources/data/encounters/barindu-kyv-rux-vhedt.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Elite Dragorn Jekisia](../../resources/data/encounters/bloodfields-elite-dragorn-jekisia.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Kragsmash](../../resources/data/encounters/bloodfields-kragsmash.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [The Keeper](../../resources/data/encounters/bloodfields-the-keeper.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [War Caller Kaavi](../../resources/data/encounters/bloodfields-war-caller-kaavi.json) | 0 | The encounter module registers War_Combat although its definition is commented out. Loader behavior and overlap with the NPC script need live verification. |
| [Agnarr Event](../../resources/data/encounters/bothunder-agnarr-event.json) | 0 | Publication blocker: Gozer creates version 0 and zone_status spawns Agnarr there, but Agnarr event_spawn depops him in every nonzero instance unless its version is 200. The static zone is allowed. Verify the intended normal expedition behavior before publishing this version. |
| [Agnarr Event (Seasonal)](../../resources/data/encounters/bothunder-agnarr-event-seasonal.json) | 200 | Version 200 is enforced directly in Agnarr event_spawn. Theta Sigma uses Custom:SeasonalInstanceVersion with fallback 200; verify the deployed rule also resolves to 200. |
| [Praklion of the Cauldron](../../resources/data/encounters/cauldron-praklion-of-the-cauldron.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Alpha Feran](../../resources/data/encounters/causeway-alpha-feran.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Slavedriver Menlo](../../resources/data/encounters/causeway-slavedriver-menlo.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Stone Thrower](../../resources/data/encounters/causeway-stone-thrower.json) | 200 | The close-range warning tells players to get away, but the impact condition damages targets farther than 100 units. Guidance follows the implemented condition and needs live verification. A final_fury timer is armed but no handler was found; no timed enrage is claimed. Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Withering Murkglider](../../resources/data/encounters/causeway-withering-murkglider.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Gimlik Cogboggle escort](../../resources/data/encounters/cazicthule-gimlik-cogboggle-escort.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Sickly mosquito](../../resources/data/encounters/cazicthule-sickly-mosquito.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Mastery of Corruption](../../resources/data/encounters/chambersf-mastery-of-corruption.json) | 2 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Korucust](../../resources/data/encounters/chardokb-korucust.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Leprous chokidai](../../resources/data/encounters/chardokb-leprous-chokidai.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Find Fibblebrap 5](../../resources/data/encounters/corathusb-find-fibblebrap-5.json) | 2 | The version-2 loader selects find_fibblebrap_five, but that file repeats the Find Fibblebrap 1 header, task IDs and much of its objective logic. Fifth-mission identity and objective correctness remain unresolved; this is a provisional source-backed draft. |
| [Find Fibblebrap 1: The Mines](../../resources/data/encounters/corathusb-find-fibblebrap1-the-mines.json) | 1 | The peaceful meat-exchange reward branch tests .valid on PLAYER_STARTING_RP, which holds a name string. The published guide covers the hostile dialogue route and gem loot objective instead. |
| [Stonemaw](../../resources/data/encounters/delvea-stonemaw.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Drithnak the Exiled](../../resources/data/encounters/drachnidhive-drithnak-the-exiled.json) | 0 | Combat starts a timer named agro but the handler checks aggro; the intended periodic hate-link behavior needs runtime verification. Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Bloated Girplan](../../resources/data/encounters/dranik-bloated-girplan.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Lirah the Bridgekeeper](../../resources/data/encounters/dranik-lirah-the-bridgekeeper.json) | 0 | The replacement check looks for template 336129 but spawns 336130. Actual replacement timing and population need live verification. Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Lirah the Bridgekeeper: Seasonal](../../resources/data/encounters/dranik-lirah-the-bridgekeeper-seasonal.json) | 200 | The replacement check looks for template 336129 but spawns 336130. Actual replacement timing and population need live verification. Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Murkglider Breeder](../../resources/data/encounters/dranik-murkglider-breeder.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Gorenaire: Seasonal](../../resources/data/encounters/dreadlands-gorenaire-seasonal.json) | 200 | Health-phase comments label skills 2 and 3 incorrectly; guidance follows the skill numbers (two-handed blunt and two-handed slashing). The howl timer is commented out, so no recurring scripted howl is claimed. |
| [Quigli](../../resources/data/encounters/dulak-quigli.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Ry'Gorr assault / Chief Ry'Gorr (Coldain ring8)](../../resources/data/encounters/eastwastes-ry-gorr-assault-chief-ry-gorr-coldain-ring8.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Severilous: Seasonal](../../resources/data/encounters/emeraldjungle-severilous-seasonal.json) | 200 | Health-phase comments say slashing, but the implemented skill IDs 7 and 8 are archery and backstab. Guidance follows the implementation. |
| [Cazic Thule](../../resources/data/encounters/fearplane-cazic-thule.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Cazic Thule: Static Mode](../../resources/data/encounters/fearplane-cazic-thule-static-mode.json) | 255 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Dracoliche: Static Mode](../../resources/data/encounters/fearplane-dracoliche-static-mode.json) | 255 | The reanimation code toggles special ability 24 while its comments call that Unkillable. No guaranteed immunity is claimed; the three-second transition needs live verification. Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Fear golems: Dread, Fright and Terror](../../resources/data/encounters/fearplane-fear-golems-dread-fright-and-terror.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Hexxt Pzik Shziaak](../../resources/data/encounters/ferubi-hexxt-pzik-shziaak.json) | 0 | Primary NPC template ID remains unverified; npc_ids is intentionally empty. |
| [Pxet elite ambushes](../../resources/data/encounters/ferubi-pxet-elite-ambushes.json) | 0 | The source identifies eligible spawn-point IDs, but their current database placement and the elite NPC spell/loot data still need verification. |
| [Smith Rondo / Weapon Master cycle](../../resources/data/encounters/ferubi-smith-rondo-weapon-master.json) | 0 | The four required component IDs are verified in the turn-in, but individual name-to-ID mappings need database confirmation. The group-only hand-in calls nonexistent group:RaidCount(). The published guide requires the hand-in character to belong to a raid and uses the reviewed raid branch. The normal draft describes the static-zone branch. Rondo removes himself from nonzero instances other than version 200. |
| [Smith Rondo / Weapon Master cycle (Seasonal)](../../resources/data/encounters/ferubi-smith-rondo-weapon-master-seasonal.json) | 200 | The four required component IDs are verified in the turn-in, but individual name-to-ID mappings need database confirmation. The group-only hand-in calls nonexistent group:RaidCount(). The published guide requires the hand-in character to belong to a raid and uses the reviewed raid branch. Confirm Custom:SeasonalInstanceVersion is 200 for the explicit Rondo gate. |
| [Berserker's Image](../../resources/data/encounters/fieldofbone-berserkers-image.json) | 0 | The death handler credits e.other as a client; pet or other final-hit attribution has not been verified. |
| [Sir Lucan D'Lere](../../resources/data/encounters/freportw-sir-lucan-dlere.json) | 0 | The living NPC template ID and identity/combat data for the spawned 9147 form require database verification. NPC links are intentionally empty. |
| [Goblin King Dronan event](../../resources/data/encounters/frontiermtns-goblin-king-dronan-event.json) | 0 | The reviewed encounter registers behavior but does not establish how the King is initially made available; verify the current launcher or database spawn. |
| [Draz Nurakk](../../resources/data/encounters/fungusgrove-draz-nurakk.json) | 0 | The reviewed Draz script does not provide the event-start hand-in or the pet's database abilities. Those details remain outside this draft. |
| [High Priest Maltan assault](../../resources/data/encounters/greatdivide-high-priest-maltan-assault.json) | 0 | The launcher provides the roster, but no boss-specific spell list or combat phases were found in the reviewed source. |
| [Tenth Coldain Ring War / Narandi](../../resources/data/encounters/greatdivide-tenth-coldain-ring-war-narandi.json) | 0 | RingTen contains TODO testing and incomplete Thurgadin aftermath notes. Verify the complete war and reward turn-in in game before publication. Narandi escape calls the simple-repopulation end branch despite a failure comment; this draft does not claim the defeat aftermath occurs on escape. |
| [Final Grimling War / General Staginar's raid](../../resources/data/encounters/grimling-final-grimling-war-general-staginars-raid.json) | 0 | The detailed General Staginar entry exchanges and final reward hand-in are not included in this combat-stage draft; verify those NPC branches before publication. |
| [Tunare](../../resources/data/encounters/growthplane-tunare.json) | 0 | Normal Tunare starts a timer named despawn but handles depop; the intended 20-minute return/reset is not implemented consistently. The Fabled helper accesses listed NPCs without checking that each exists. Verify live population and assistance behavior before publication. |
| [Deepest Guk raid: Executioner Gimdk / First Witness](../../resources/data/encounters/guke-deepest-guk-raid-executioner-gimdk-first-witness.json) | 2 | The scout auto-start check looks for a player more than 150 paces away rather than confirming raid readiness. The explicit ready dialogue is the documented start. |
| [Gragna the Cursed](../../resources/data/encounters/gukg-gragna-the-cursed.json) | 2 | Verify spell 4458 and its target against the loaded database before describing its effect as healing or assigning a response. The emote alone does not establish the actual effect. |
| [The Cavern Creeper](../../resources/data/encounters/gukg-the-cavern-creeper.json) | 2 | The health-stage handlers only emit dialogue and explicitly question whether additional effects are missing. No weakening, shield or timed vulnerability effect is claimed. |
| [Windrush](../../resources/data/encounters/harbingers-windrush.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Broken Skull Armsmaster](../../resources/data/encounters/hatesfury-broken-skull-armsmaster.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Master Yael](../../resources/data/encounters/hole-master-yael.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Chambers of Righteousness](../../resources/data/encounters/ikkinz-chambers-of-righteousness.json) | 3 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Chambers of Singular Might](../../resources/data/encounters/ikkinz-chambers-of-singular-might.json) | 0 | Review the Pixtt Annihilator’s database abilities and trial spawns before publishing a detailed combat guide; the quest handler supplies completion logic only. |
| [Ritesmaster Verok](../../resources/data/encounters/illsalin-ritesmaster-verok.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Find Fibblebrap 4: The Korlach](../../resources/data/encounters/illsalinb-find-fibblebrap-4-the-korlach.json) | 2 | Publication blocked pending completion review: Neran uses TODO template 111111 and Bilitan uses TODO template 349999; neither placeholder is linked as a confirmed encounter NPC. Resolve the task mismatch: headers identify tasks 8135–8139, but instance level and reward tables use 8130–8134. Review the open-world callback entity usage and instance rare-item assignment before live validation; several references use NPC event self as a client or pass a table where an item ID is expected. |
| [Emperor Draygun, the Lich King](../../resources/data/encounters/illsalinc-emperor-draygun-the-lich-king.json) | 2 | Verify the end of the split-phase spell immunity in a live test: the reviewed module enables it but does not explicitly disable it when the living Emperor despawns. |
| [Seasons: Watery Death](../../resources/data/encounters/kedge-seasons-watery-death.json) | 200 | Publication blocked: identify the seasonal Kedge boss, database spawn group, abilities and completion rules. The launcher alone does not establish a Phinigel or other boss encounter. Confirm the deployed SeasonalInstanceVersion rule and Kedge entry/return coordinates before publication. Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Scorpion hatchling ambush](../../resources/data/encounters/lakeofillomen-scorpion-hatchling-ambush.json) | 0 | Identify the prone trigger NPC bound to lakeofillomen/#_.lua and verify the player kill-credit requirement before publication; the file name does not reveal its template. |
| [Vorash, Deep and Xenevorash](../../resources/data/encounters/lakeofillomen-vorash-deep-and-xenevorash.json) | 0 | Confirm Vorash and Deep template IDs for additional NPC links; only the directly spawned Xenevorash ID is linked. |
| [Man-eating Freshwater Shark](../../resources/data/encounters/lakerathe-man-eating-freshwater-shark.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Frozen Nightmare: Marrow the Broken](../../resources/data/encounters/mirb-frozen-nightmare-marrow-the-broken.json) | 2 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Cragbeast Queen (seasonal)](../../resources/data/encounters/natimbi-cragbeast-queen-seasonal.json) | 200 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Tybone Biggums](../../resources/data/encounters/natimbi-tybone-biggums.json) | 0 | Confirm the gathering event flag and guild-specific tuning before publication. |
| [Zlandicar (seasonal)](../../resources/data/encounters/necropolis-zlandicar-seasonal.json) | 200 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Lady Vox (seasonal)](../../resources/data/encounters/permafrost-lady-vox-seasonal.json) | 200 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Manaetic Behemoth](../../resources/data/encounters/poinnovation-manaetic-behemoth.json) | 0 | Verify the dormant activation sequence in game before publication: repeated device signals toggle first_signal and only alternate signals reset the counter, rather than consistently resetting it. |
| [The Seventh Hammer](../../resources/data/encounters/pojustice-the-seventh-hammer.json) | 0 | Confirm the intended Tribunal timer behavior before publication: the ready dialogue sets dialogue=true, while the combat handler starts the judgment timer only when dialogue is false. The dialogue-triggered fight may omit that cycle. |
| [Bonded Hunts: companion challenges (36 targets)](../../resources/data/encounters/poknowledge-bonded-hunts-companion-challenges-36-targets.json) | 0 | Confirm deployment of the native Bonded Hunt combat audit before publication; the Lua reward transition depends on its audit result. |
| [Deyid the Twisted](../../resources/data/encounters/ponightmare-deyid-the-twisted.json) | 0 | Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously. |
| [Vallon Zek](../../resources/data/encounters/potactics-vallon-zek.json) | 0 | Verify or repair the planar projection’s flag dialogue before publication: PP_Say defines vallon_bucket but tests the undefined tallon_bucket variable. |
| [Baraguj Szuul mouth event](../../resources/data/encounters/potorment-baraguj-szuul-mouth-event.json) | 0 | Confirm the entry trigger and spawn wiring for legacy mouth_trigger.pl before publication. The completion chain from the Horror to Baraguj is present, but no reviewed source caller starts that Perl gauntlet. |
| [Keeper of Sorrows / Tylis' torment](../../resources/data/encounters/potorment-keeper-of-sorrows-tylis-torment.json) | 0 | Review the chamber’s database spawns and Keeper abilities before publication; the quest files confirm access and completion, but do not establish the full intervening combat sequence. |
| [Lightning Warrior Spiritseeker](../../resources/data/encounters/provinggrounds-lightning-warrior-spiritseeker.json) | 0 | Check the Spiritseeker cleanup timer before publication: the combat handler starts it on disengagement but does not stop an existing timer when combat resumes. |
| [Enchanted Rat Experiment](../../resources/data/encounters/qeynos2-enchanted-rat-experiment.json) | 0 | Jar IDs and tile behavior are source-verified; the individual jar display names still require database confirmation. |
| [Mastruq Commander Gorlakt and the Spiritlords](../../resources/data/encounters/qinimi-mastruq-commander-gorlakt-and-the-spiritlords.json) | 0 | Gorlakt stops both spell timers when combat ends but keeps spell_active set. The reviewed script does not restart those timers on re-engagement; verify recovery before publication. |
| [Thunderdome: First Chamber](../../resources/data/encounters/qinimi-thunderdome.json) | 0 | Only thunder_dome_one combat logic was reviewed. The launcher also offers chambers two and three; those branches need their own source comparison before this guide claims to cover every room. The launcher dialogue says eighteen participants and the module declares player_limit=18, but this module does not enforce that variable. Do not present eighteen as a verified admission cap. The final boss calls stop_timer("fail_2") on itself although the overall timer belongs to the controller, then cleanup depops the final boss. Verify the victory, loot and delayed-ejection path before publication. |
| [Cynosure Kvanjji](../../resources/data/encounters/qvic-cynosure-kvanjji.json) | 0 | The reset timer is started as reset but handled as Reset. The intended one-minute reset is not reliable as written; healing and mimic timers also remain active after combat ends. Verify recovery before publication. Queued arbiter heals are unconditional when delivered, even if the matching arbiter died during the ten-second delay. This draft intentionally does not advise killing an arbiter to interrupt a queued heal. Spell 4748 is cast by the 90-second portal-energy timer, but its loaded effect has not been verified. The draft does not infer an effect from the emote. |
| [Hexxt Ilk Klokk](../../resources/data/encounters/qvic-hexxt-ilk-klokk.json) | 0 | The NPC template ID was not established from the reviewed file; the guide has no automatic NPC-page link until that identity is confirmed. The volley implementation has no branch for hate-list counts greater than six. Do not claim the same targeting behavior for a larger raid. |
| [Hexxt Jkak Miq](../../resources/data/encounters/qvic-hexxt-jkak-miq.json) | 0 | The NPC template ID was not established from the reviewed file; the guide has no automatic NPC-page link until that identity is confirmed. The volley implementation has no branch for hate-list counts greater than six. Do not claim the same targeting behavior for a larger raid. |
| [Hexxt Pvin Nki](../../resources/data/encounters/qvic-hexxt-pvin-nki.json) | 0 | The NPC template ID was not established from the reviewed file; the guide has no automatic NPC-page link until that identity is confirmed. The volley implementation has no branch for hate-list counts greater than six. Do not claim the same targeting behavior for a larger raid. |
| [Iqthinxa Karnkvi: Zoo Event](../../resources/data/encounters/qvic-iqthinxa-karnkvi-zoo-event.json) | 0 | The three-Rav lowest-health selection misses the case where rav1 is below rav2 but rav3 is below rav1. An imbalance may therefore fail to trigger frenzy in that arrangement. Guidance keeps the health spread below ten points rather than relying on the defect. The Rav check begins at ten-second intervals and changes to one second after an imbalance. It does not restore the original interval when balance returns. The reviewed boss script does not define an out-of-combat cleanup or rearming of the 75% transition. Verify recovery after a wipe before publication. |
| [Warrior Spirit Chalex: Captain Krignok](../../resources/data/encounters/rathemtn-warrior-spirit-chalex-captain-krignok.json) | 0 | Chalex transforms on leaving combat rather than on a confirmed Krignok death. Verify this recovery/completion edge before publication. Krignok begins a five-minute despawn timer after the third add wave; that timer is not paused in combat. |
| [Craftmaster Tieranu](../../resources/data/encounters/riftseekers-craftmaster-tieranu.json) | 0 | The cleanup function omits Sizzle (334090), although it removes the other elemental IDs. Verify leftover-add behavior before publication. |
| [King Gelaqua and the Princes](../../resources/data/encounters/riftseekers-king-gelaqua-and-the-princes.json) | 0 | One prince transition signals 334045, a princess ID, instead of the apparent remaining prince 334035. Verify both trio-clear orders before publication. Orb target selection uses a large placeholder range marked TODO in the source. Current crowd-control susceptibility is database-dependent and is not promised here. |
| [Queen Pyrilonis and the Princesses](../../resources/data/encounters/riftseekers-queen-pyrilonis-and-the-princesses.json) | 0 | Construct target selection uses a large placeholder range marked TODO in the source. Live range and database spell effects remain unverified. |
| [Arena: Turlini and the enslaved yunjo](../../resources/data/encounters/riwwi-arena-turlini-and-the-enslaved-yunjo.json) | 0 | The sequence and trade IDs are source-verified. Container recipe contents, enemy spell lists and equipment rewards require database review. No boss-specific abilities are invented for the wave roster. |
| [Taskmistress Krisz](../../resources/data/encounters/riwwi-taskmistress-krisz.json) | 0 | The initial audit described prerequisite Pixtt kills; the current trigger is dialogue with Pixtt Kekken and an 18-member raid check. |
| [Viqu the Blindeye](../../resources/data/encounters/riwwi-viqu-the-blindeye.json) | 0 | Volley code has no branch for hate-list sizes above six; confirm intended raid behavior before publication. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Rujarkian Hills adventures (shared rules)](../../resources/data/encounters/ruja-rujarkian-hills-adventures-shared-rules.json) | 1 | This theme overview is linked to ruja version 1; identical controllers serve the other Rujarkian maps. Confirm the deployed ruja version before publication. Rescue is listed as a type, but the reviewed controller implements explicit completion branches only for assassination, kills and collection. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Flawless Experimental Battlelord](../../resources/data/encounters/rujg-flawless-experimental-battlelord.json) | 2 | Raid version 2 requires confirmation against the expedition launcher/database before publication. |
| [Disciple of Sun](../../resources/data/encounters/scarlet-disciple-of-sun.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Trakanon (Seasonal)](../../resources/data/encounters/sebilis-trakanon-seasonal.json) | 200 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [A twitching swordfish](../../resources/data/encounters/sirens-a-twitching-swordfish.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Talendor (Seasonal)](../../resources/data/encounters/skyfire-talendor-seasonal.json) | 200 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Lord Yelinak (Seasonal)](../../resources/data/encounters/skyshrine-lord-yelinak-seasonal.json) | 200 | Version 200 documents the default seasonal configuration; the seasonal helper also accepts configured versions and versions 200 to 249. Confirm the deployed version. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Awakening the Sleeper](../../resources/data/encounters/sleeper-awakening-the-sleeper.json) | 0 | Legacy unversioned event only. Confirm deployed wardstone/warder spawn availability versus seasonal and Fabled content; this draft does not describe the separate Fabled Kerafyrm encounter. |
| [The Fabled Kerafyrm the Awakened](../../resources/data/encounters/sleeper-the-fabled-kerafyrm-the-awakened.json) | 1 | Timers start on spawn, not engagement. Live spell data must be checked before assigning effect names or avoidance advice. |
| [Gzifa the Pure One](../../resources/data/encounters/sncrematory-gzifa-the-pure-one.json) | 0 | The trigger comment mentions a progression flag, but the current active condition checks the carried remains only. |
| [Sewers of Nihilia: Lair tool recovery](../../resources/data/encounters/snlair-sewers-of-nihilia-lair-tool-recovery.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Lord Nagafen (Seasonal)](../../resources/data/encounters/soldungb-lord-nagafen-seasonal.json) | 200 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Rizlona](../../resources/data/encounters/solrotower-rizlona.json) | 0 | The first-form NPC ID needs a database lookup before linking both forms. |
| [Lord Inquisitor Seru](../../resources/data/encounters/sseru-lord-inquisitor-seru.json) | 0 | Native spell lists and special defenses reside in database data and are not established by the tether script. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Emperor Ssraeshza and the Blood](../../resources/data/encounters/ssratemple-emperor-ssraeshza-and-the-blood.json) | 0 | This entry is for the open-world version 0; the boss explicitly depops from nonseasonal instances. |
| [Vyzh`dra cycle](../../resources/data/encounters/ssratemple-vyzh-dra-cycle.json) | 0 | Full Exiled and serpent branch details still require a dedicated publication pass; this draft documents the reviewed controller and Banished path. |
| [Yama Tolk and the Inactive Clockwork](../../resources/data/encounters/steamfont-yama-tolk-and-the-inactive-clockwork.json) | 0 | The callback comment claims same-player enforcement, but the cast callback should be audited before relying on that restriction. |
| [Keepers of Strength and Wisdom](../../resources/data/encounters/stillmoona-keepers-of-strength-and-wisdom.json) | 0 | The separate Sung Li Wisdom sequence needs a deeper publication review; this draft establishes both activation branches. |
| [Trial of Perseverance](../../resources/data/encounters/stillmoona-trial-of-perseverance.json) | 9 | Publication blocker: Goblin_Spawn is defined twice; the second definition overwrites the initial timer setup. Exact idle-start/failure timing must be fixed or live-verified before publication. |
| [Rikkukin the Defender](../../resources/data/encounters/stillmoonb-rikkukin-the-defender.json) | 2 | Version 2 is the Reflections of Silver mission constant; confirm this NPC is bound to that mission in the database before publication. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Sudden Tremors: Ancient Golem](../../resources/data/encounters/stillmoonb-sudden-tremors-ancient-golem.json) | 6 | Task-database gates and completion behavior are not present in this short controller. Confirm the chest-first path and verify recovery from an early golem kill before publication. |
| [Takish-Hiz adventures (shared rules)](../../resources/data/encounters/taka-takish-hiz-adventures-shared-rules.json) | 1 | Version 1 is the standard adventure mapping and needs confirmation against the deployed taka launcher. Rescue is listed as a type but lacks an explicit completion path in the reviewed theme controller. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Quintessence of Sand and Ritana](../../resources/data/encounters/takc-quintessence-of-sand-and-ritana.json) | 2 | Corrected the approval-list label Queen of Sand to the Quintessence wording used by the encounter script. |
| [Living Legacy: Sandkeep Endurance Raid](../../resources/data/encounters/takishruinsa-living-legacy-sandkeep-endurance-raid.json) | 1 | Encounter header and tuning comments require matching deployed SQL and level-60 live validation before publication. |
| [Aaryonar](../../resources/data/encounters/templeveeshan-aaryonar.json) | 0 | Database spell lists and native combat defenses need review before expanding this short linked-aggro guide. |
| [Lady Mirenilla](../../resources/data/encounters/templeveeshan-lady-mirenilla.json) | 0 | Database spell lists and native combat defenses need review before expanding this short linked-aggro guide. |
| [Lord Feshlak](../../resources/data/encounters/templeveeshan-lord-feshlak.json) | 0 | Database spell lists and native combat defenses need review before expanding this short linked-aggro guide. |
| [Lord Kreizenn](../../resources/data/encounters/templeveeshan-lord-kreizenn.json) | 0 | Database spell lists and native combat defenses need review before expanding this short linked-aggro guide. |
| [Lord Vyemm](../../resources/data/encounters/templeveeshan-lord-vyemm.json) | 0 | Database spell lists and native combat defenses need review before expanding this short linked-aggro guide. |
| [Vulak`Aerr ring event](../../resources/data/encounters/templeveeshan-vulak-aerr-ring-event.json) | 0 | This entry covers the scripted ring, not the separate fabled lockout-only variant. |
| [Disciple of Focus](../../resources/data/encounters/tenebrous-disciple-of-focus.json) | 0 | Publication blocker: combat starts timer cast, but the spell handler checks startcast. The intended recurring Quivering Nightmares cast does not follow from the current timer path and is intentionally not claimed as a working mechanic. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Hoober](../../resources/data/encounters/tenebrous-hoober.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Johanius Barleou](../../resources/data/encounters/tenebrous-johanius-barleou.json) | 0 | Johanius dialogue, transformation and later Slayer follow-up require a fuller publication review beyond the camp controller. |
| [Deklean Korgad: invisible bridge](../../resources/data/encounters/thedeep-deklean-korgad-invisible-bridge.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [The Burrower Beast](../../resources/data/encounters/thedeep-the-burrower-beast.json) | 0 | Corrected the initial audit: the current event starts by proximity, not by killing the Burrower Beast. The orphan Burrower.lua controller is excluded. |
| [Thought Horror Overfiend (Seasonal)](../../resources/data/encounters/thedeep-thought-horror-overfiend-seasonal.json) | 200 | The final-stage skill modifiers are labeled as magic vulnerability in comments, but their actual spell-damage effect requires server/database validation. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Rampaging Monolith](../../resources/data/encounters/thenest-rampaging-monolith.json) | 14 | Version14 is the named Toppling of the Monolith mission constant; verify the standalone Rampaging_Monolith NPC mapping against the deployed database before publication. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Rivals](../../resources/data/encounters/thenest-rivals.json) | 11 | The alternate tooth-return ending updates a task stage while announcing failure and suppressing in-zone rewards; verify intended reward behavior before publication. |
| [An End to the Storms: Yar`Lir](../../resources/data/encounters/thundercrest-an-end-to-the-storms-yar-lir.json) | 200 | Publication blocker: script_init loads this encounter for version 3, but the module removes the boss from nonzero instances unless version 200. No version 200 loader was confirmed. This draft describes the intended version 200 branch, not a verified playable expedition. |
| [Holy Hour](../../resources/data/encounters/thundercrest-holy-hour.json) | 2 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Lair Unguarded](../../resources/data/encounters/thundercrest-lair-unguarded.json) | 6 | The source labels the guardian location list possibly incomplete and notes the statue timer needs a quest reload after repop. Verify deployed spawns/reset behavior. |
| [Scions of Thundercrest](../../resources/data/encounters/thundercrest-scions-of-thundercrest.json) | 7 | The source marks the chest location list possibly incomplete; verify against deployed mission spawns. |
| [Storm Chasers / Raging Thunderhead](../../resources/data/encounters/thundercrest-storm-chasers-raging-thunderhead.json) | 0 | The growth emote is verified; exact Raging Thunderhead stat scaling is not defined in these reviewed scripts. |
| [Stormreach Challenge: Goblin Dojo](../../resources/data/encounters/thundercrest-stormreach-challenge-goblin-dojo.json) | 10 | The controller intentionally enables a historical retry bug after early-wave failure; later reset behavior must be verified before documenting guaranteed retries. |
| [The Creator](../../resources/data/encounters/thundercrest-the-creator.json) | 11 | This guide is limited to the level 68+ mission configuration. The source hardcodes those NPC templates; lower-difficulty mappings remain unfinished. |
| [Throes of Contagion](../../resources/data/encounters/thundercrest-throes-of-contagion.json) | 12 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Draz Nurakk and his images](../../resources/data/encounters/timorous-draz-nurakk-and-his-images.json) | 0 | Image template IDs are verified by spawn calls; the initial Draz Nurakk template requires database confirmation. |
| [Overseer Wrank and the Captains](../../resources/data/encounters/torgiran-overseer-wrank-and-the-captains.json) | 0 | The controller tests exactly twenty accumulated key credits; verify the upstream turn-in handling prevents overshooting that value. |
| [Taskmaster Lugald Brokenskull](../../resources/data/encounters/torgiran-taskmaster-lugald-brokenskull.json) | 0 | The exact set of enemies supplying the twenty-three signals should be checked against the deployed spawn population before publication. |
| [Ancient Cragbeast Matriarch](../../resources/data/encounters/txevu-ancient-cragbeast-matriarch.json) | 0 | The audit described generic HP phases, but the reviewed controller actually ties these benefits to living bearer NPCs. |
| [Vrex Barxt Qurat and Guardian of Destruction](../../resources/data/encounters/uqua-vrex-barxt-qurat-and-guardian-of-destruction.json) | 0 | Barxt starts a tether_check timer but the handler tests tether; his own leash requires verification. The Guardian and channeler boundary checks are present. |
| [Phara Dar and the Ring of Scale](../../resources/data/encounters/veeshan-phara-dar-and-the-ring-of-scale.json) | 0 | The source calls this Phara Dar 2.0 but does not specify an instance version. Version0 represents the unversioned zone script; confirm the deployed spawn version before publication. |
| [Decaying Lord Galuk Drek](../../resources/data/encounters/veksar-decaying-lord-galuk-drek.json) | 0 | The combat handler does not stop the spell timer on disengagement; verify wipe behavior and database spell effects before publication. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Aten Ha Ra progression / Akhevan Warders](../../resources/data/encounters/vexthal-aten-ha-ra-progression-akhevan-warders.json) | 0 | This draft documents warder progression and variant selection; it does not claim distinct Aten combat mechanics absent from the reviewed trigger. |
| [NPC 158006: teleport hunt](../../resources/data/encounters/vexthal-npc-158006-teleport-hunt.json) | 0 | Publication blocker: NPC 158006 has no display name or verified spawn-version mapping in the reviewed script. The numeric identity is preserved; resolve its database name and deployed version before publication. |
| [Vxed trial / Stonespiritist Ekikoa](../../resources/data/encounters/vxed-vxed-trial-stonespiritist-ekikoa.json) | 0 | Final dialogue and progression credit need a separate review of Ekikoa before publication. |
| [Cristoc Bonethug](../../resources/data/encounters/wakening-cristoc-bonethug.json) | 0 | The audit described linked aggression, but these reviewed scripts only verify the spawns and despawn timers; database social aggro remains unverified. |
| [Wuoshi (Seasonal)](../../resources/data/encounters/wakening-wuoshi-seasonal.json) | 200 | Version200 is the shared helper default; deployments may configure another seasonal version or accept 200 to 249. Confirm the active expedition version and matching support-NPC provisioning before publication. |
| [Murkglider Hivequeen](../../resources/data/encounters/wallofslaughter-murkglider-hivequeen.json) | 0 | Egg and hatchling template IDs are verified; the Hivequeen template still needs database confirmation. |
| [Pyrique Redwing](../../resources/data/encounters/wallofslaughter-pyrique-redwing.json) | 0 | The source explicitly treats killing the real shadow as an uncertain historical behavior; that alternate transition needs live validation. |
| [Grokui, the Slumbering Basilisk](../../resources/data/encounters/westkorlach-grokui-the-slumbering-basilisk.json) | 0 | The script sets 430,000 current HP rather than using GetMaxHP; compare the deployed NPC max health before describing each transition as a full heal. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Klandicar (Seasonal)](../../resources/data/encounters/westwastes-klandicar-seasonal.json) | 200 | Version200 is the shared helper default; deployments may configure another seasonal version or accept 200 to 249. Confirm the active expedition version and matching support-NPC provisioning before publication. |
| [Hexxt Huntmaster](../../resources/data/encounters/yxtta-hexxt-huntmaster.json) | 0 | The volley handler only covers hate-list sizes one through six; behavior above six targets needs verification. Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Pixtt Suir Mindrider](../../resources/data/encounters/yxtta-pixtt-suir-mindrider.json) | 0 | Resolve the exact NPC template IDs from the zone database before enabling NPC-page links. |
| [Primal door riddle](../../resources/data/encounters/yxtta-primal-door-riddle.json) | 0 | Publication blocker: enterzone is not named event_enter_zone and its numeric status check uses Lua truthiness; verify door initialization. The initial question increment also skips an index, so no exact answer count is claimed. |

## Generated documents

| Approval IDs | Document | Zone | Version | Status |
| --- | --- | --- | ---: | --- |
| E001 | [Archmage Gorraferg ring](../../resources/data/encounters/acrylia-archmage-gorraferg-ring.json) | acrylia | 0 | published |
| E003 | [High Priest Gakkernog ring](../../resources/data/encounters/acrylia-high-priest-gakkernog-ring.json) | acrylia | 0 | published |
| E004 | [Khati Sha the Twisted](../../resources/data/encounters/acrylia-khati-sha-the-twisted.json) | acrylia | 0 | published |
| E005 | [Ring of Fire](../../resources/data/encounters/acrylia-ring-of-fire.json) | acrylia | 0 | published |
| E006 | [Trondol Shir escort](../../resources/data/encounters/acrylia-trondol-shir-escort.json) | acrylia | 0 | published |
| E009 | [Shei Vinitras](../../resources/data/encounters/akheva-shei-vinitras.json) | akheva | 0 | published |
| E010 | [The Insanity Crawler](../../resources/data/encounters/akheva-the-insanity-crawler.json) | akheva | 0 | published |
| E010 | [Time-Corrupted Insanity Crawler](../../resources/data/encounters/akheva-time-corrupted-insanity-crawler.json) | akheva | 200 | published |
| E011 | [Arch Magus Vangl](../../resources/data/encounters/anguish-arch-magus-vangl.json) | anguish | 0 | published |
| E012 | [Jelvan](../../resources/data/encounters/anguish-jelvan.json) | anguish | 0 | published |
| E013 | [Keldovan the Harrier](../../resources/data/encounters/anguish-keldovan-the-harrier.json) | anguish | 0 | published |
| E014 | [Overlord Mata Muram](../../resources/data/encounters/anguish-overlord-mata-muram.json) | anguish | 0 | published |
| E015 | [Ture](../../resources/data/encounters/anguish-ture.json) | anguish | 0 | published |
| E016 | [Warden Hanvar](../../resources/data/encounters/anguish-warden-hanvar.json) | anguish | 0 | published |
| E017 | [Heigan the Unclean: The Safety Dance](../../resources/data/encounters/arena2-heigan-the-unclean-the-safety-dance.json) | arena2 | 6 | published |
| E018 | [Living Legacy: Battle Prowess](../../resources/data/encounters/arena2-living-legacy-battle-prowess.json) | arena2 | 7 | published |
| E019 | [Trial of the Dreadscale: Difficult](../../resources/data/encounters/arena2-trial-of-the-dreadscale-difficult.json) | arena2 | 4 | published |
| E019 | [Trial of the Dreadscale: Insane](../../resources/data/encounters/arena2-trial-of-the-dreadscale-insane.json) | arena2 | 5 | published |
| E019 | [Trial of the Dreadscale: Moderate](../../resources/data/encounters/arena2-trial-of-the-dreadscale-moderate.json) | arena2 | 3 | published |
| E019 | [Trial of the Dreadscale: Simple](../../resources/data/encounters/arena2-trial-of-the-dreadscale-simple.json) | arena2 | 2 | published |
| E020 | [Ixvet Pox / Colossus of War](../../resources/data/encounters/barindu-ixvet-pox-colossus-of-war.json) | barindu | 0 | published |
| E021 | [Kyv Rux Vhedt](../../resources/data/encounters/barindu-kyv-rux-vhedt.json) | barindu | 0 | published |
| E022 | [Discordling Dark Animist](../../resources/data/encounters/bloodfields-discordling-dark-animist.json) | bloodfields | 0 | published |
| E023 | [Elite Dragorn Jekisia](../../resources/data/encounters/bloodfields-elite-dragorn-jekisia.json) | bloodfields | 0 | published |
| E024 | [Gazz the Gargantuan](../../resources/data/encounters/bloodfields-gazz-the-gargantuan.json) | bloodfields | 0 | published |
| E025 | [Kragsmash](../../resources/data/encounters/bloodfields-kragsmash.json) | bloodfields | 0 | published |
| E026 | [Marshal Histent / Grinbik the Fertile rescue](../../resources/data/encounters/bloodfields-marshal-histent-grinbik-the-fertile-rescue.json) | bloodfields | 0 | published |
| E027 | [Reclusive girplan chain / Rolthee and Myrhee](../../resources/data/encounters/bloodfields-reclusive-girplan-chain-rolthee-and-myrhee.json) | bloodfields | 0 | published |
| E028 | [The Keeper](../../resources/data/encounters/bloodfields-the-keeper.json) | bloodfields | 0 | published |
| E029 | [War Caller Kaavi](../../resources/data/encounters/bloodfields-war-caller-kaavi.json) | bloodfields | 0 | published |
| E030, E031, E032 | [Agnarr Event](../../resources/data/encounters/bothunder-agnarr-event.json) | bothunder | 0 | draft |
| E030, E031, E032 | [Agnarr Event (Seasonal)](../../resources/data/encounters/bothunder-agnarr-event-seasonal.json) | bothunder | 200 | published |
| E033 | [Praklion of the Cauldron](../../resources/data/encounters/cauldron-praklion-of-the-cauldron.json) | cauldron | 0 | published |
| E034 | [Alpha Feran](../../resources/data/encounters/causeway-alpha-feran.json) | causeway | 0 | published |
| E035 | [Bazu Smasher](../../resources/data/encounters/causeway-bazu-smasher.json) | causeway | 0 | published |
| E036 | [Bazu Terror](../../resources/data/encounters/causeway-bazu-terror.json) | causeway | 0 | published |
| E037 | [Essence of Kreljnok](../../resources/data/encounters/causeway-essence-of-kreljnok.json) | causeway | 0 | published |
| E038 | [Magician1.5 elemental mastery event](../../resources/data/encounters/causeway-magician1-5-elemental-mastery-event.json) | causeway | 0 | published |
| E039 | [Oshiruk](../../resources/data/encounters/causeway-oshiruk.json) | causeway | 0 | published |
| E040 | [Siflu](../../resources/data/encounters/causeway-siflu.json) | causeway | 0 | published |
| E041 | [Slavedriver Menlo](../../resources/data/encounters/causeway-slavedriver-menlo.json) | causeway | 0 | published |
| E042 | [Stone Thrower](../../resources/data/encounters/causeway-stone-thrower.json) | causeway | 200 | published |
| E043 | [Withering Murkglider](../../resources/data/encounters/causeway-withering-murkglider.json) | causeway | 0 | published |
| E044 | [Gimlik Cogboggle escort](../../resources/data/encounters/cazicthule-gimlik-cogboggle-escort.json) | cazicthule | 0 | published |
| E045 | [Ring of Fear](../../resources/data/encounters/cazicthule-ring-of-fear.json) | cazicthule | 0 | published |
| E046 | [Sickly mosquito](../../resources/data/encounters/cazicthule-sickly-mosquito.json) | cazicthule | 0 | published |
| E047 | [Mastery of Fear](../../resources/data/encounters/chambersa-mastery-of-fear.json) | chambersa | 1 | published |
| E048 | [Mastery of Hate](../../resources/data/encounters/chambersa-mastery-of-hate.json) | chambersa | 2 | published |
| E049 | [Mastery of Endurance](../../resources/data/encounters/chambersb-mastery-of-endurance.json) | chambersb | 2 | published |
| E050 | [Mastery of Weaponry](../../resources/data/encounters/chambersb-mastery-of-weaponry.json) | chambersb | 1 | published |
| E051 | [Mastery of Foresight](../../resources/data/encounters/chambersc-mastery-of-foresight.json) | chambersc | 2 | published |
| E052 | [Mastery of Subversion](../../resources/data/encounters/chambersc-mastery-of-subversion.json) | chambersc | 1 | published |
| E053 | [Mastery of Efficiency](../../resources/data/encounters/chambersd-mastery-of-efficiency.json) | chambersd | 1 | published |
| E054 | [Mastery of Specialization](../../resources/data/encounters/chambersd-mastery-of-specialization.json) | chambersd | 2 | published |
| E055 | [Mastery of Adaptation](../../resources/data/encounters/chamberse-mastery-of-adaptation.json) | chamberse | 2 | published |
| E056 | [Mastery of Ingenuity](../../resources/data/encounters/chamberse-mastery-of-ingenuity.json) | chamberse | 1 | published |
| E057 | [Mastery of Corruption](../../resources/data/encounters/chambersf-mastery-of-corruption.json) | chambersf | 2 | published |
| E058 | [Mastery of Destruction](../../resources/data/encounters/chambersf-mastery-of-destruction.json) | chambersf | 1 | published |
| E059 | [Korucust](../../resources/data/encounters/chardokb-korucust.json) | chardokb | 0 | published |
| E060 | [Leprous chokidai](../../resources/data/encounters/chardokb-leprous-chokidai.json) | chardokb | 0 | published |
| E061 | [Black reaver / Lord Ghiosk / Lord Rak'Ashiir chain](../../resources/data/encounters/citymist-black-reaver-lord-ghiosk-lord-rak-ashiir-chain.json) | citymist | 0 | published |
| E062 | [Gnashing Killer Shark and companions](../../resources/data/encounters/cobaltscar-gnashing-killer-shark-and-companions.json) | cobaltscar | 0 | published |
| E063 | [Bertoxxulous](../../resources/data/encounters/codecay-bertoxxulous.json) | codecay | 0 | published |
| E064 | [Carprin Deatharn cycle](../../resources/data/encounters/codecay-carprin-deatharn-cycle.json) | codecay | 0 | published |
| E065 | [Overlord Banord Paffa](../../resources/data/encounters/codecay-overlord-banord-paffa.json) | codecay | 0 | published |
| E066 | [Taskmaster](../../resources/data/encounters/corathus-taskmaster.json) | corathus | 0 | published |
| R003 | [Find Fibblebrap 5](../../resources/data/encounters/corathusb-find-fibblebrap-5.json) | corathusb | 2 | draft |
| E067 | [Find Fibblebrap 1: The Mines](../../resources/data/encounters/corathusb-find-fibblebrap1-the-mines.json) | corathusb | 1 | published |
| E068 | [Hunter's Pike beast challenge](../../resources/data/encounters/dawnshroud-hunter-s-pike-beast-challenge.json) | dawnshroud | 0 | published |
| E069 | [A Halfling's Greed](../../resources/data/encounters/delvea-a-halfling-s-greed.json) | delvea | 2 | published |
| E070 | [Lavaspinner Hunting](../../resources/data/encounters/delvea-lavaspinner-hunting.json) | delvea | 8 | published |
| E071 | [Stonemaw](../../resources/data/encounters/delvea-stonemaw.json) | delvea | 0 | published |
| E072 | [The Drake Menace / Drake Matriarch](../../resources/data/encounters/delvea-the-drake-menace-drake-matriarch.json) | delvea | 5 | published |
| E073 | [Volkara's Bite](../../resources/data/encounters/delvea-volkara-s-bite.json) | delvea | 9 | published |
| E074 | [A Goblin's Escort](../../resources/data/encounters/delveb-a-goblin-s-escort.json) | delveb | 2 | published |
| E075 | [Emoush the Destroyer](../../resources/data/encounters/delveb-emoush-the-destroyer.json) | delveb | 4 | published |
| E076 | [Have Note, Will Travel](../../resources/data/encounters/delveb-have-note-will-travel.json) | delveb | 6 | published |
| E077 | [Tirranun: Fanning the Flames](../../resources/data/encounters/delveb-tirranun-fanning-the-flames.json) | delveb | 5 | published |
| E078 | [Drithnak the Exiled](../../resources/data/encounters/drachnidhive-drithnak-the-exiled.json) | drachnidhive | 0 | published |
| E079 | [The Lost Notebook](../../resources/data/encounters/drachnidhive-the-lost-notebook.json) | drachnidhive | 4 | published |
| E080 | [Find Fibblebrap 3: The Hive](../../resources/data/encounters/drachnidhivea-find-fibblebrap3-the-hive.json) | drachnidhivea | 1 | published |
| E081 | [The Lost Gnomes](../../resources/data/encounters/drachnidhivea-the-lost-gnomes.json) | drachnidhivea | 2 | published |
| E082 | [Sendaii the Hive Queen](../../resources/data/encounters/drachnidhivec-sendaii-the-hive-queen.json) | drachnidhivec | 1 | published |
| E083 | [Living Legacy: Marathon](../../resources/data/encounters/dragonscalea-living-legacy-marathon.json) | dragonscalea | 1 | published |
| E084 | [Living Legacy: Sprint](../../resources/data/encounters/dragonscalea-living-legacy-sprint.json) | dragonscalea | 2 | published |
| E085 | [Battlemaster Rhorious](../../resources/data/encounters/dranik-battlemaster-rhorious.json) | dranik | 0 | published |
| E086 | [Bloated Girplan](../../resources/data/encounters/dranik-bloated-girplan.json) | dranik | 0 | published |
| E087 | [Discord Fluctuation trio](../../resources/data/encounters/dranik-discord-fluctuation-trio.json) | dranik | 0 | published |
| E088 | [Discordling Dark Animist](../../resources/data/encounters/dranik-discordling-dark-animist.json) | dranik | 0 | published |
| E089 | [Kyv Sharpshooters: Jaeth, Mihl and Nass](../../resources/data/encounters/dranik-kyv-sharpshooters-jaeth-mihl-and-nass.json) | dranik | 0 | published |
| E090 | [Lhranc and his minions](../../resources/data/encounters/dranik-lhranc-and-his-minions.json) | dranik | 0 | published |
| E091 | [Lirah the Bridgekeeper](../../resources/data/encounters/dranik-lirah-the-bridgekeeper.json) | dranik | 0 | published |
| E091 | [Lirah the Bridgekeeper: Seasonal](../../resources/data/encounters/dranik-lirah-the-bridgekeeper-seasonal.json) | dranik | 200 | published |
| E092 | [Murkglider Breeder](../../resources/data/encounters/dranik-murkglider-breeder.json) | dranik | 0 | published |
| E093 | [Sverins / Uisima rescue](../../resources/data/encounters/dranik-sverins-uisima-rescue.json) | dranik | 0 | published |
| E094 | [Tiorpat Tornwing](../../resources/data/encounters/dranik-tiorpat-tornwing.json) | dranik | 0 | published |
| E095 | [Wren Simsy](../../resources/data/encounters/dranik-wren-simsy.json) | dranik | 0 | published |
| E096 | [Zun'Muram Volklana](../../resources/data/encounters/dranik-zun-muram-volklana.json) | dranik | 0 | published |
| E097 | [Girplan Spiritleech](../../resources/data/encounters/draniksscar-girplan-spiritleech.json) | draniksscar | 0 | published |
| E098 | [Gorenaire: Seasonal](../../resources/data/encounters/dreadlands-gorenaire-seasonal.json) | dreadlands | 200 | published |
| E099 | [Redfang](../../resources/data/encounters/dreadspire-redfang.json) | dreadspire | 1 | published |
| E100 | [Zi-Thuuli of the Granite Claw](../../resources/data/encounters/dreadspire-zi-thuuli-of-the-granite-claw.json) | dreadspire | 1 | published |
| E101 | [Quigli](../../resources/data/encounters/dulak-quigli.json) | dulak | 0 | published |
| E102 | [Althele's druid gathering defense](../../resources/data/encounters/eastkarana-althele-s-druid-gathering-defense.json) | eastkarana | 0 | published |
| E103 | [General Veronhar](../../resources/data/encounters/eastkorlach-general-veronhar.json) | eastkorlach | 0 | published |
| E104 | [Bloodeye](../../resources/data/encounters/eastkorlacha-bloodeye.json) | eastkorlacha | 2 | published |
| E105 | [Boridain Glacierbane escort (Coldain ring2)](../../resources/data/encounters/eastwastes-boridain-glacierbane-escort-coldain-ring2.json) | eastwastes | 0 | published |
| E106 | [Corbin Blackwell rescue (Coldain ring7)](../../resources/data/encounters/eastwastes-corbin-blackwell-rescue-coldain-ring7.json) | eastwastes | 0 | published |
| E107 | [Icefang / Poxbreath pursuit (Coldain ring6)](../../resources/data/encounters/eastwastes-icefang-poxbreath-pursuit-coldain-ring6.json) | eastwastes | 0 | published |
| E108 | [Peffin Ambersnow / Berradin confrontation](../../resources/data/encounters/eastwastes-peffin-ambersnow-berradin-confrontation.json) | eastwastes | 0 | published |
| E109 | [Ry'Gorr assault / Chief Ry'Gorr (Coldain ring8)](../../resources/data/encounters/eastwastes-ry-gorr-assault-chief-ry-gorr-coldain-ring8.json) | eastwastes | 0 | published |
| E110 | [Scarbrow Ga'Hruk assault (Coldain ring5)](../../resources/data/encounters/eastwastes-scarbrow-ga-hruk-assault-coldain-ring5.json) | eastwastes | 0 | published |
| E111 | [Tain Hammerfrost defense (Coldain ring4)](../../resources/data/encounters/eastwastes-tain-hammerfrost-defense-coldain-ring4.json) | eastwastes | 0 | published |
| E112 | [Severilous: Seasonal](../../resources/data/encounters/emeraldjungle-severilous-seasonal.json) | emeraldjungle | 200 | published |
| E113 | [Cazic Thule](../../resources/data/encounters/fearplane-cazic-thule.json) | fearplane | 0 | published |
| E113 | [Cazic Thule: Static Mode](../../resources/data/encounters/fearplane-cazic-thule-static-mode.json) | fearplane | 255 | published |
| E114 | [Dracoliche: Static Mode](../../resources/data/encounters/fearplane-dracoliche-static-mode.json) | fearplane | 255 | published |
| E115 | [Fear golems: Dread, Fright and Terror](../../resources/data/encounters/fearplane-fear-golems-dread-fright-and-terror.json) | fearplane | 0 | published |
| E116 | [Fear-Touched Dracolich ambush](../../resources/data/encounters/fearplane-fear-touched-dracolich-ambush.json) | fearplane | 0 | published |
| E117 | [Ireblind Imp](../../resources/data/encounters/fearplane-ireblind-imp.json) | fearplane | 0 | published |
| E118 | [Swamp Terror](../../resources/data/encounters/feerrott-swamp-terror.json) | feerrott | 0 | published |
| E119 | [Hexxt Pzik Shziaak](../../resources/data/encounters/ferubi-hexxt-pzik-shziaak.json) | ferubi | 0 | published |
| E120 | [Packmaster Skoiat Pizak](../../resources/data/encounters/ferubi-packmaster-skoiat-pizak.json) | ferubi | 0 | published |
| E121 | [Pxet elite ambushes](../../resources/data/encounters/ferubi-pxet-elite-ambushes.json) | ferubi | 0 | published |
| E122 | [Smith Rondo / Weapon Master cycle](../../resources/data/encounters/ferubi-smith-rondo-weapon-master.json) | ferubi | 0 | published |
| E122 | [Smith Rondo / Weapon Master cycle (Seasonal)](../../resources/data/encounters/ferubi-smith-rondo-weapon-master-seasonal.json) | ferubi | 200 | published |
| E123 | [Zun-Muram Votal](../../resources/data/encounters/ferubi-zun-muram-votal.json) | ferubi | 0 | published |
| E124 | [Berserker's Image](../../resources/data/encounters/fieldofbone-berserkers-image.json) | fieldofbone | 0 | published |
| E125 | [Sir Lucan D'Lere](../../resources/data/encounters/freportw-sir-lucan-dlere.json) | freportw | 0 | published |
| E126 | [Goblin King Dronan event](../../resources/data/encounters/frontiermtns-goblin-king-dronan-event.json) | frontiermtns | 0 | published |
| E127 | [Calling Beasties (Murkin, Groo and Torgal)](../../resources/data/encounters/fungusgrove-calling-beasties-murkin-groo-and-torgal.json) | fungusgrove | 0 | published |
| E128 | [Draz Nurakk](../../resources/data/encounters/fungusgrove-draz-nurakk.json) | fungusgrove | 0 | published |
| E129 | [Seana and Stefan Marsinger](../../resources/data/encounters/gfaydark-seana-and-stefan-marsinger.json) | gfaydark | 0 | published |
| E130 | [High Priest Maltan assault](../../resources/data/encounters/greatdivide-high-priest-maltan-assault.json) | greatdivide | 0 | published |
| E131 | [Tenth Coldain Ring War / Narandi](../../resources/data/encounters/greatdivide-tenth-coldain-ring-war-narandi.json) | greatdivide | 0 | published |
| E132 | [Captain Necin's raid](../../resources/data/encounters/grimling-captain-necins-raid.json) | grimling | 0 | published |
| E133 | [Final Grimling War / General Staginar's raid](../../resources/data/encounters/grimling-final-grimling-war-general-staginars-raid.json) | grimling | 0 | published |
| E134 | [Scout Danarin's raid](../../resources/data/encounters/grimling-scout-danarins-raid.json) | grimling | 0 | published |
| E135 | [Scout Derrin's raid](../../resources/data/encounters/grimling-scout-derrins-raid.json) | grimling | 0 | published |
| E136 | [Scout Husman's raid](../../resources/data/encounters/grimling-scout-husmans-raid.json) | grimling | 0 | published |
| E137 | [Veteran Vadrel's raid](../../resources/data/encounters/grimling-veteran-vadrels-raid.json) | grimling | 0 | published |
| E138 | [Tunare](../../resources/data/encounters/growthplane-tunare.json) | growthplane | 0 | published |
| E139 | [Deepest Guk raid: Executioner Gimdk / First Witness](../../resources/data/encounters/guke-deepest-guk-raid-executioner-gimdk-first-witness.json) | guke | 2 | published |
| E140 | [Gragna the Cursed](../../resources/data/encounters/gukg-gragna-the-cursed.json) | gukg | 2 | published |
| E141 | [Leklos the Bonekeeper](../../resources/data/encounters/gukg-leklos-the-bonekeeper.json) | gukg | 2 | published |
| R006 | [The Cavern Creeper](../../resources/data/encounters/gukg-the-cavern-creeper.json) | gukg | 2 | published |
| E142 | [The Cursed Keeper](../../resources/data/encounters/gukg-the-cursed-keeper.json) | gukg | 2 | published |
| E143 | [The Cursed Spore](../../resources/data/encounters/gukg-the-cursed-spore.json) | gukg | 2 | published |
| E144 | [Dimaal the Spiritmaster's waves](../../resources/data/encounters/gunthak-dimaal-the-spiritmaster-s-waves.json) | gunthak | 0 | published |
| E145 | [Lairyn Debeian defense / Krill the Backbleeder](../../resources/data/encounters/gunthak-lairyn-debeian-defense-krill-the-backbleeder.json) | gunthak | 0 | published |
| E146 | [Attendant of Light](../../resources/data/encounters/harbingers-attendant-of-light.json) | harbingers | 0 | published |
| E147 | [Azibelle Spavin and Glenfire Telzir](../../resources/data/encounters/harbingers-azibelle-spavin-and-glenfire-telzir.json) | harbingers | 0 | published |
| E148 | [Windrush](../../resources/data/encounters/harbingers-windrush.json) | harbingers | 0 | published |
| E149 | [Innoruuk](../../resources/data/encounters/hateplaneb-innoruuk.json) | hateplaneb | 0 | published |
| E150 | [Lanys T'Vyl / Teir'Dal guardian event](../../resources/data/encounters/hateplaneb-lanys-t-vyl-teir-dal-guardian-event.json) | hateplaneb | 0 | published |
| E151 | [Maestro of Rancor](../../resources/data/encounters/hateplaneb-maestro-of-rancor.json) | hateplaneb | 0 | published |
| E152 | [Broken Skull Armsmaster](../../resources/data/encounters/hatesfury-broken-skull-armsmaster.json) | hatesfury | 0 | published |
| E153 | [Captain Krasnok](../../resources/data/encounters/hatesfury-captain-krasnok.json) | hatesfury | 0 | published |
| E155 | [Alekson Garn's trial](../../resources/data/encounters/hohonora-alekson-garn-s-trial.json) | hohonora | 0 | published |
| E156 | [Rhaliq Trell's trial](../../resources/data/encounters/hohonora-rhaliq-trell-s-trial.json) | hohonora | 0 | published |
| E157 | [Rydda'Dar / Trydan Faye's trial](../../resources/data/encounters/hohonora-rydda-dar-trydan-faye-s-trial.json) | hohonora | 0 | published |
| E158 | [Lord Mithaniel Marr](../../resources/data/encounters/hohonorb-lord-mithaniel-marr.json) | hohonorb | 0 | published |
| E159 | [Master Yael](../../resources/data/encounters/hole-master-yael.json) | hole | 0 | published |
| E160 | [Hollowshade Moor war](../../resources/data/encounters/hollowshade-hollowshade-moor-war.json) | hollowshade | 0 | published |
| E161 | [General Bragmur escort (Coldain shawl finale)](../../resources/data/encounters/iceclad-general-bragmur-escort-coldain-shawl-finale.json) | iceclad | 0 | published |
| E162 | [Noble Oldencamp and the necromancer delegation](../../resources/data/encounters/iceclad-noble-oldencamp-and-the-necromancer-delegation.json) | iceclad | 0 | published |
| E163 | [Chambers of Destruction / Keeper of the Altar](../../resources/data/encounters/ikkinz-chambers-of-destruction-keeper-of-the-altar.json) | ikkinz | 6 | published |
| E164 | [Chambers of Glorification](../../resources/data/encounters/ikkinz-chambers-of-glorification.json) | ikkinz | 4 | published |
| E165 | [Chambers of Righteousness](../../resources/data/encounters/ikkinz-chambers-of-righteousness.json) | ikkinz | 3 | published |
| R007 | [Chambers of Singular Might](../../resources/data/encounters/ikkinz-chambers-of-singular-might.json) | ikkinz | 0 | published |
| E166 | [Chambers of the Tri-Fates](../../resources/data/encounters/ikkinz-chambers-of-the-tri-fates.json) | ikkinz | 2 | published |
| E167 | [Chambers of Transcendence](../../resources/data/encounters/ikkinz-chambers-of-transcendence.json) | ikkinz | 5 | published |
| E168 | [Chambers of Twin Struggles](../../resources/data/encounters/ikkinz-chambers-of-twin-struggles.json) | ikkinz | 1 | published |
| E169 | [Ritesmaster Verok](../../resources/data/encounters/illsalin-ritesmaster-verok.json) | illsalin | 0 | published |
| R008 | [Find Fibblebrap 4: The Korlach](../../resources/data/encounters/illsalinb-find-fibblebrap-4-the-korlach.json) | illsalinb | 2 | draft |
| E170 | [The Council of Nine](../../resources/data/encounters/illsalinb-the-council-of-nine.json) | illsalinb | 1 | published |
| E171 | [Deserting the Ranks](../../resources/data/encounters/illsalinc-deserting-the-ranks.json) | illsalinc | 1 | published |
| E172 | [Emperor Draygun, the Lich King](../../resources/data/encounters/illsalinc-emperor-draygun-the-lich-king.json) | illsalinc | 2 | published |
| E173 | [Kelekdrix, Herald of Trushar](../../resources/data/encounters/inktuta-kelekdrix-herald-of-trushar.json) | inktuta | 0 | published |
| E174 | [Noqufiel: true and mirror images](../../resources/data/encounters/inktuta-noqufiel-true-and-mirror-images.json) | inktuta | 0 | published |
| E175 | [The Cursecallers](../../resources/data/encounters/inktuta-the-cursecallers.json) | inktuta | 0 | published |
| E176 | [The Exiles / Stonemite trial](../../resources/data/encounters/inktuta-the-exiles-stonemite-trial.json) | inktuta | 0 | published |
| E177 | [Dire kodiak / forest dragon transformation](../../resources/data/encounters/jaggedpine-dire-kodiak-forest-dragon-transformation.json) | jaggedpine | 0 | published |
| E178 | [Fabled King Tormax](../../resources/data/encounters/kael-fabled-king-tormax.json) | kael | 0 | published |
| E179 | [Fabled Statue, Idol and Avatar of War](../../resources/data/encounters/kael-fabled-statue-idol-and-avatar-of-war.json) | kael | 0 | published |
| E180 | [Kael armor / plate cycle](../../resources/data/encounters/kael-kael-armor-plate-cycle.json) | kael | 0 | published |
| E181 | [King Tormax](../../resources/data/encounters/kael-king-tormax.json) | kael | 0 | published |
| E182 | [Statue, Idol and Avatar of War](../../resources/data/encounters/kael-statue-idol-and-avatar-of-war.json) | kael | 0 | published |
| E183 | [Rakshasa skull ritual](../../resources/data/encounters/katta-rakshasa-skull-ritual.json) | katta | 0 | published |
| E184 | [Vampyre Troubles: Autarkic Lord Sfarosh](../../resources/data/encounters/katta-vampyre-troubles-autarkic-lord-sfarosh.json) | katta | 0 | published |
| R010 | [Seasons: Watery Death](../../resources/data/encounters/kedge-seasons-watery-death.json) | kedge | 200 | draft |
| E185 | [Tissa, Sovereign of Terror and the nine lives of Kerra](../../resources/data/encounters/kerraridge-tissa-sovereign-of-terror-and-the-nine-lives-of-kerra.json) | kerraridge | 0 | published |
| E186 | [Blackened Treant and Blackened Dryad](../../resources/data/encounters/kithicor-blackened-treant-and-blackened-dryad.json) | kithicor | 0 | published |
| E187 | [Cloaked figure / shadow thief](../../resources/data/encounters/kithicor-cloaked-figure-shadow-thief.json) | kithicor | 0 | published |
| E188 | [Pixtt Grand Summoner ring](../../resources/data/encounters/kodtaz-pixtt-grand-summoner-ring.json) | kodtaz | 0 | published |
| E189 | [Temple of the Damned](../../resources/data/encounters/kodtaz-temple-of-the-damned.json) | kodtaz | 0 | published |
| R012 | [Scorpion hatchling ambush](../../resources/data/encounters/lakeofillomen-scorpion-hatchling-ambush.json) | lakeofillomen | 0 | draft |
| E190 | [Vorash, Deep and Xenevorash](../../resources/data/encounters/lakeofillomen-vorash-deep-and-xenevorash.json) | lakeofillomen | 0 | published |
| E191 | [Kazen Fecae undead trial](../../resources/data/encounters/lakerathe-kazen-fecae-undead-trial.json) | lakerathe | 0 | published |
| E192 | [Man-eating Freshwater Shark](../../resources/data/encounters/lakerathe-man-eating-freshwater-shark.json) | lakerathe | 0 | published |
| E193 | [High Priestess Shima ambush](../../resources/data/encounters/lavastorm-high-priestess-shima-ambush.json) | lavastorm | 0 | published |
| E194 | [Taskmaster Mirot and the reanimated minions](../../resources/data/encounters/lfaydark-taskmaster-mirot-and-the-reanimated-minions.json) | lfaydark | 0 | published |
| E195 | [Frozen Nightmare: chromatic bonewalkers](../../resources/data/encounters/mirb-frozen-nightmare-chromatic-bonewalkers.json) | mirb | 2 | published |
| E196 | [Frozen Nightmare: Frostfoot goblins](../../resources/data/encounters/mirb-frozen-nightmare-frostfoot-goblins.json) | mirb | 2 | published |
| E197 | [Frozen Nightmare: Laskuth the Colossus](../../resources/data/encounters/mirb-frozen-nightmare-laskuth-the-colossus.json) | mirb | 2 | published |
| E198 | [Frozen Nightmare: Marrow the Broken](../../resources/data/encounters/mirb-frozen-nightmare-marrow-the-broken.json) | mirb | 2 | published |
| E199 | [Frozen Nightmare: Sharalla](../../resources/data/encounters/mirb-frozen-nightmare-sharalla.json) | mirb | 2 | published |
| E200 | [Frozen Nightmare: sundering sludge](../../resources/data/encounters/mirb-frozen-nightmare-sundering-sludge.json) | mirb | 2 | published |
| E201 | [Bristlebane the King of Thieves (2.0)](../../resources/data/encounters/mischiefplane-bristlebane-the-king-of-thieves-2-0.json) | mischiefplane | 0 | published |
| E202 | [The Archivist's Midnight Vigil](../../resources/data/encounters/mistmoore-the-archivist-s-midnight-vigil.json) | mistmoore | 11 | published |
| E203 | [Struggles within the Progeny / Valdoon Kel'Novar](../../resources/data/encounters/mmcc-struggles-within-the-progeny-valdoon-kel-novar.json) | mmcc | 2 | published |
| E205 | [The Luggald Broodmother](../../resources/data/encounters/nadox-the-luggald-broodmother.json) | nadox | 0 | published |
| E206 | [Cragbeast Queen (seasonal)](../../resources/data/encounters/natimbi-cragbeast-queen-seasonal.json) | natimbi | 200 | published |
| E207 | [Ritual Conduit](../../resources/data/encounters/natimbi-ritual-conduit.json) | natimbi | 0 | published |
| E208 | [Spiritbinder Trenzar and Senvial of the Mist](../../resources/data/encounters/natimbi-spiritbinder-trenzar-and-senvial-of-the-mist.json) | natimbi | 0 | published |
| E209 | [Tybone Biggums](../../resources/data/encounters/natimbi-tybone-biggums.json) | natimbi | 0 | published |
| E210 | [Garzicor's Corpse and Wraith](../../resources/data/encounters/necropolis-garzicor-s-corpse-and-wraith.json) | necropolis | 0 | published |
| E211 | [Vesthon Marijakin and the Dracoliche of Hsagra](../../resources/data/encounters/necropolis-vesthon-marijakin-and-the-dracoliche-of-hsagra.json) | necropolis | 0 | published |
| E212 | [Vesthon Marijakin: first confrontation](../../resources/data/encounters/necropolis-vesthon-marijakin-first-confrontation.json) | necropolis | 0 | published |
| E213 | [Zlandicar (seasonal)](../../resources/data/encounters/necropolis-zlandicar-seasonal.json) | necropolis | 200 | published |
| E214 | [Terris Thule](../../resources/data/encounters/nightmareb-terris-thule.json) | nightmareb | 0 | published |
| E215 | [Dire griffon / plains dragon transformation](../../resources/data/encounters/northkarana-dire-griffon-plains-dragon-transformation.json) | northkarana | 0 | published |
| E216 | [Remal the Black](../../resources/data/encounters/oasis-remal-the-black.json) | oasis | 0 | published |
| E217 | [Glowing cliff golem and Watch Sergeant Grolj](../../resources/data/encounters/overthere-glowing-cliff-golem-and-watch-sergeant-grolj.json) | overthere | 0 | published |
| E218 | [Lady Vox (seasonal)](../../resources/data/encounters/permafrost-lady-vox-seasonal.json) | permafrost | 200 | published |
| E219 | [Chamberlain Escalardian](../../resources/data/encounters/poair-chamberlain-escalardian.json) | poair | 0 | published |
| E220 | [Elemental Masterpiece](../../resources/data/encounters/poair-elemental-masterpiece.json) | poair | 0 | published |
| E221 | [Melernil Faal'Armanna](../../resources/data/encounters/poair-melernil-faal-armanna.json) | poair | 0 | published |
| E222 | [Sigismond Windwalker](../../resources/data/encounters/poair-sigismond-windwalker.json) | poair | 0 | published |
| E223 | [Stormrider island](../../resources/data/encounters/poair-stormrider-island.json) | poair | 0 | published |
| E224 | [Xegony, Queen of Air](../../resources/data/encounters/poair-xegony-queen-of-air.json) | poair | 0 | published |
| E225 | [Dust ring / Perfected Warder of Earth](../../resources/data/encounters/poeartha-dust-ring-perfected-warder-of-earth.json) | poeartha | 0 | published |
| E226 | [Mud ring / Monstrous Mudwalker](../../resources/data/encounters/poeartha-mud-ring-monstrous-mudwalker.json) | poeartha | 0 | published |
| E227 | [Mystical Arbitor of Earth](../../resources/data/encounters/poeartha-mystical-arbitor-of-earth.json) | poeartha | 0 | published |
| E228 | [Stone ring / Peregrin Rockskull](../../resources/data/encounters/poeartha-stone-ring-peregrin-rockskull.json) | poeartha | 0 | published |
| E229 | [Vine ring / Derugoak Bloodwalker](../../resources/data/encounters/poeartha-vine-ring-derugoak-bloodwalker.json) | poeartha | 0 | published |
| E230 | [Rathe Council and Avatar of Earth](../../resources/data/encounters/poearthb-rathe-council-and-avatar-of-earth.json) | poearthb | 0 | published |
| E231 | [Warlord Gintolaken](../../resources/data/encounters/poearthb-warlord-gintolaken.json) | poearthb | 0 | published |
| E232 | [Fennin Ro, Tyrant of Fire](../../resources/data/encounters/pofire-fennin-ro-tyrant-of-fire.json) | pofire | 0 | published |
| E233 | [Manaetic Behemoth](../../resources/data/encounters/poinnovation-manaetic-behemoth.json) | poinnovation | 0 | published |
| E234 | [Nitram Anizok / Xanamech Nezmirthafen](../../resources/data/encounters/poinnovation-nitram-anizok-xanamech-nezmirthafen.json) | poinnovation | 0 | published |
| E235 | [The Seventh Hammer](../../resources/data/encounters/pojustice-the-seventh-hammer.json) | pojustice | 0 | published |
| E236 | [Trial of Execution](../../resources/data/encounters/pojustice-trial-of-execution.json) | pojustice | 0 | published |
| E237 | [Trial of Flame](../../resources/data/encounters/pojustice-trial-of-flame.json) | pojustice | 0 | published |
| E238 | [Trial of Hanging](../../resources/data/encounters/pojustice-trial-of-hanging.json) | pojustice | 0 | published |
| E239 | [Trial of Lashing](../../resources/data/encounters/pojustice-trial-of-lashing.json) | pojustice | 0 | published |
| E240 | [Trial of Stoning](../../resources/data/encounters/pojustice-trial-of-stoning.json) | pojustice | 0 | published |
| E241 | [Trial of Torture](../../resources/data/encounters/pojustice-trial-of-torture.json) | pojustice | 0 | published |
| E204 | [Bonded Hunts: companion challenges (36 targets)](../../resources/data/encounters/poknowledge-bonded-hunts-companion-challenges-36-targets.json) | poknowledge | 0 | published |
| E242 | [Aid Eino escort / Dreamkeeper](../../resources/data/encounters/ponightmare-aid-eino-escort-dreamkeeper.json) | ponightmare | 0 | published |
| E243 | [Deyid the Twisted](../../resources/data/encounters/ponightmare-deyid-the-twisted.json) | ponightmare | 0 | published |
| E244 | [Mujaki the Devourer](../../resources/data/encounters/ponightmare-mujaki-the-devourer.json) | ponightmare | 0 | published |
| E245 | [Terror Matriarch](../../resources/data/encounters/ponightmare-terror-matriarch.json) | ponightmare | 0 | published |
| E246 | [Drornok Tok Vo'Lok](../../resources/data/encounters/postorms-drornok-tok-vo-lok.json) | postorms | 0 | published |
| E247 | [Falto, Lord of Thunder](../../resources/data/encounters/postorms-falto-lord-of-thunder.json) | postorms | 0 | published |
| E248 | [Ston'Ruak, Ancient of Trees](../../resources/data/encounters/postorms-ston-ruak-ancient-of-trees.json) | postorms | 0 | published |
| E249 | [Rallos Zek the Warlord](../../resources/data/encounters/potactics-rallos-zek-the-warlord.json) | potactics | 0 | published |
| E250 | [Tallon Zek](../../resources/data/encounters/potactics-tallon-zek.json) | potactics | 0 | published |
| E251 | [Vallon Zek](../../resources/data/encounters/potactics-vallon-zek.json) | potactics | 0 | published |
| E252 | [Plane of Time: phase 4 gods](../../resources/data/encounters/potimeb-plane-of-time-phase-4-gods.json) | potimeb | 0 | published |
| E253 | [Plane of Time: phase 5 gods](../../resources/data/encounters/potimeb-plane-of-time-phase-5-gods.json) | potimeb | 0 | published |
| E254 | [Plane of Time: phases 1-3](../../resources/data/encounters/potimeb-plane-of-time-phases-1-3.json) | potimeb | 0 | published |
| E255 | [Quarm](../../resources/data/encounters/potimeb-quarm.json) | potimeb | 0 | published |
| R014 | [Baraguj Szuul mouth event](../../resources/data/encounters/potorment-baraguj-szuul-mouth-event.json) | potorment | 0 | published |
| R015 | [Keeper of Sorrows / Tylis' torment](../../resources/data/encounters/potorment-keeper-of-sorrows-tylis-torment.json) | potorment | 0 | published |
| E256 | [Maareq the Prophet](../../resources/data/encounters/potorment-maareq-the-prophet.json) | potorment | 0 | published |
| E257 | [Salczek the Fleshgrinder](../../resources/data/encounters/potorment-salczek-the-fleshgrinder.json) | potorment | 0 | published |
| E258 | [Saryrn](../../resources/data/encounters/potorment-saryrn.json) | potorment | 0 | published |
| E259 | [Aerin'Dar](../../resources/data/encounters/povalor-aerin-dar.json) | povalor | 0 | published |
| E260 | [Coirnav, Avatar of Water](../../resources/data/encounters/powater-coirnav-avatar-of-water.json) | powater | 0 | published |
| E261 | [Lightning Warrior Spiritseeker](../../resources/data/encounters/provinggrounds-lightning-warrior-spiritseeker.json) | provinggrounds | 0 | published |
| E262 | [Yuanda: werewolf ambush](../../resources/data/encounters/qey2hh1-yuanda-werewolf-ambush.json) | qey2hh1 | 0 | published |
| E263 | [Enchanted Rat Experiment](../../resources/data/encounters/qeynos2-enchanted-rat-experiment.json) | qeynos2 | 0 | published |
| E264 | [Execution: Rescue Kreshin Silentcog](../../resources/data/encounters/qinimi-execution-rescue-kreshin-silentcog.json) | qinimi | 0 | published |
| E265 | [Mastruq Commander Gorlakt and the Spiritlords](../../resources/data/encounters/qinimi-mastruq-commander-gorlakt-and-the-spiritlords.json) | qinimi | 0 | published |
| E266 | [Thunderdome: First Chamber](../../resources/data/encounters/qinimi-thunderdome.json) | qinimi | 0 | published |
| E267 | [Cynosure Kvanjji](../../resources/data/encounters/qvic-cynosure-kvanjji.json) | qvic | 0 | published |
| E268 | [Hexxt Ilk Klokk](../../resources/data/encounters/qvic-hexxt-ilk-klokk.json) | qvic | 0 | published |
| E269 | [Hexxt Jkak Miq](../../resources/data/encounters/qvic-hexxt-jkak-miq.json) | qvic | 0 | published |
| E270 | [Hexxt Pvin Nki](../../resources/data/encounters/qvic-hexxt-pvin-nki.json) | qvic | 0 | published |
| E271 | [Iqthinxa Karnkvi: Zoo Event](../../resources/data/encounters/qvic-iqthinxa-karnkvi-zoo-event.json) | qvic | 0 | published |
| E272 | [Warrior Spirit Chalex: Captain Krignok](../../resources/data/encounters/rathemtn-warrior-spirit-chalex-captain-krignok.json) | rathemtn | 0 | published |
| E273 | [Chailak](../../resources/data/encounters/riftseekers-chailak.json) | riftseekers | 0 | published |
| E274 | [Craftmaster Tieranu](../../resources/data/encounters/riftseekers-craftmaster-tieranu.json) | riftseekers | 0 | published |
| E275 | [King Gelaqua and the Princes](../../resources/data/encounters/riftseekers-king-gelaqua-and-the-princes.json) | riftseekers | 0 | published |
| E276 | [Queen Pyrilonis and the Princesses](../../resources/data/encounters/riftseekers-queen-pyrilonis-and-the-princesses.json) | riftseekers | 0 | published |
| E277 | [Arena: Turlini and the enslaved yunjo](../../resources/data/encounters/riwwi-arena-turlini-and-the-enslaved-yunjo.json) | riwwi | 0 | published |
| E278 | [Taskmistress Krisz](../../resources/data/encounters/riwwi-taskmistress-krisz.json) | riwwi | 0 | published |
| E279 | [Viqu the Blindeye](../../resources/data/encounters/riwwi-viqu-the-blindeye.json) | riwwi | 0 | published |
| E280 | [Rujarkian Hills adventures (shared rules)](../../resources/data/encounters/ruja-rujarkian-hills-adventures-shared-rules.json) | ruja | 1 | published |
| E281 | [Prison Break: Warden Neyremal and High Shaman Yenner](../../resources/data/encounters/rujd-prison-break-warden-neyremal-and-high-shaman-yenner.json) | rujd | 2 | published |
| E282 | [Flawless Experimental Battlelord](../../resources/data/encounters/rujg-flawless-experimental-battlelord.json) | rujg | 2 | published |
| E283 | [Disciple of Sun](../../resources/data/encounters/scarlet-disciple-of-sun.json) | scarlet | 0 | published |
| E284 | [High Priest Valon and the Plasmatic followers](../../resources/data/encounters/scarlet-high-priest-valon-and-the-plasmatic-followers.json) | scarlet | 0 | published |
| E285 | [Sebilite Protector](../../resources/data/encounters/sebilis-sebilite-protector.json) | sebilis | 0 | published |
| E286 | [Trakanon (Seasonal)](../../resources/data/encounters/sebilis-trakanon-seasonal.json) | sebilis | 200 | published |
| E287 | [A twitching swordfish](../../resources/data/encounters/sirens-a-twitching-swordfish.json) | sirens | 0 | published |
| E288 | [Rithnok the Tormented](../../resources/data/encounters/skyfire-rithnok-the-tormented.json) | skyfire | 0 | published |
| E289 | [Talendor (Seasonal)](../../resources/data/encounters/skyfire-talendor-seasonal.json) | skyfire | 200 | published |
| E290 | [Lord Yelinak (Seasonal)](../../resources/data/encounters/skyshrine-lord-yelinak-seasonal.json) | skyshrine | 200 | published |
| R017 | [Awakening the Sleeper](../../resources/data/encounters/sleeper-awakening-the-sleeper.json) | sleeper | 0 | published |
| E291 | [Master of the Guard](../../resources/data/encounters/sleeper-master-of-the-guard.json) | sleeper | 0 | published |
| E292 | [Sleep Walking expedition](../../resources/data/encounters/sleeper-sleep-walking-expedition.json) | sleeper | 1 | published |
| E293 | [The Fabled Kerafyrm the Awakened](../../resources/data/encounters/sleeper-the-fabled-kerafyrm-the-awakened.json) | sleeper | 1 | published |
| E291 | [The Fabled Master of the Guard](../../resources/data/encounters/sleeper-the-fabled-master-of-the-guard.json) | sleeper | 1 | published |
| E294 | [Gzifa the Pure One](../../resources/data/encounters/sncrematory-gzifa-the-pure-one.json) | sncrematory | 0 | published |
| E295 | [Sewers of Nihilia: Lair tool recovery](../../resources/data/encounters/snlair-sewers-of-nihilia-lair-tool-recovery.json) | snlair | 0 | published |
| E296 | [Ancient Kayserops: Sewers stonemite event](../../resources/data/encounters/snplant-ancient-kayserops-sewers-stonemite-event.json) | snplant | 0 | published |
| E297 | [Sewers of Nihilia: Slime Cube](../../resources/data/encounters/snpool-sewers-of-nihilia-slime-cube.json) | snpool | 0 | published |
| E298 | [Lord Nagafen (Seasonal)](../../resources/data/encounters/soldungb-lord-nagafen-seasonal.json) | soldungb | 200 | published |
| E299 | [Culthor the Gatekeeper](../../resources/data/encounters/soldungc-culthor-the-gatekeeper.json) | soldungc | 0 | published |
| E300 | [Fireback Queen](../../resources/data/encounters/soldungc-fireback-queen.json) | soldungc | 0 | published |
| E301 | [Protector of Fire / Pure Flame Elemental](../../resources/data/encounters/soldungc-protector-of-fire-pure-flame-elemental.json) | soldungc | 0 | published |
| E302 | [Arlyxir](../../resources/data/encounters/solrotower-arlyxir.json) | solrotower | 0 | published |
| E303 | [Galremos](../../resources/data/encounters/solrotower-galremos.json) | solrotower | 0 | published |
| E304 | [Jiva](../../resources/data/encounters/solrotower-jiva.json) | solrotower | 0 | published |
| E305 | [Protector of Dresolik](../../resources/data/encounters/solrotower-protector-of-dresolik.json) | solrotower | 0 | published |
| E306 | [Rizlona](../../resources/data/encounters/solrotower-rizlona.json) | solrotower | 0 | published |
| E307 | [Solusek Ro](../../resources/data/encounters/solrotower-solusek-ro.json) | solrotower | 0 | published |
| E308 | [Xuzl](../../resources/data/encounters/solrotower-xuzl.json) | solrotower | 0 | published |
| E309 | [Lord Inquisitor Seru](../../resources/data/encounters/sseru-lord-inquisitor-seru.json) | sseru | 0 | published |
| E310 | [Emperor Ssraeshza and the Blood](../../resources/data/encounters/ssratemple-emperor-ssraeshza-and-the-blood.json) | ssratemple | 0 | published |
| E310 | [Emperor Ssraeshza and the Blood (Seasonal)](../../resources/data/encounters/ssratemple-emperor-ssraeshza-and-the-blood-seasonal.json) | ssratemple | 200 | published |
| E311 | [Rhag cycle / Arch Lich](../../resources/data/encounters/ssratemple-rhag-cycle-arch-lich.json) | ssratemple | 0 | published |
| E312 | [Vyzh`dra cycle](../../resources/data/encounters/ssratemple-vyzh-dra-cycle.json) | ssratemple | 0 | published |
| E313 | [Cargo Clockwork ambush](../../resources/data/encounters/steamfont-cargo-clockwork-ambush.json) | steamfont | 0 | published |
| E314 | [Yama Tolk and the Inactive Clockwork](../../resources/data/encounters/steamfont-yama-tolk-and-the-inactive-clockwork.json) | steamfont | 0 | published |
| E315 | [Animated Statue Plans](../../resources/data/encounters/stillmoona-animated-statue-plans.json) | stillmoona | 2 | published |
| E316 | [Best Laid Plans](../../resources/data/encounters/stillmoona-best-laid-plans.json) | stillmoona | 3 | published |
| E317 | [Guardian of the Sands: Shogurei](../../resources/data/encounters/stillmoona-guardian-of-the-sands-shogurei.json) | stillmoona | 5 | published |
| E318 | [Keepers of Strength and Wisdom](../../resources/data/encounters/stillmoona-keepers-of-strength-and-wisdom.json) | stillmoona | 0 | published |
| E319 | [Scales of Justice](../../resources/data/encounters/stillmoona-scales-of-justice.json) | stillmoona | 6 | published |
| E320 | [Sickness of the Spirit](../../resources/data/encounters/stillmoona-sickness-of-the-spirit.json) | stillmoona | 1 | published |
| E321 | [Tea for Thy Master](../../resources/data/encounters/stillmoona-tea-for-thy-master.json) | stillmoona | 7 | published |
| E322 | [Tracking the Kirin](../../resources/data/encounters/stillmoona-tracking-the-kirin.json) | stillmoona | 8 | published |
| R018 | [Trial of Perseverance](../../resources/data/encounters/stillmoona-trial-of-perseverance.json) | stillmoona | 9 | published |
| E323 | [Death Comes Swiftly: Lair Mistress](../../resources/data/encounters/stillmoonb-death-comes-swiftly-lair-mistress.json) | stillmoonb | 3 | published |
| E324 | [Drake Eggs](../../resources/data/encounters/stillmoonb-drake-eggs.json) | stillmoonb | 4 | published |
| E325 | [Kessdona's Perch](../../resources/data/encounters/stillmoonb-kessdona-s-perch.json) | stillmoonb | 1 | published |
| E326 | [Rikkukin the Defender](../../resources/data/encounters/stillmoonb-rikkukin-the-defender.json) | stillmoonb | 2 | published |
| E327 | [Storm Dragon Scales: The Storm Caller](../../resources/data/encounters/stillmoonb-storm-dragon-scales-the-storm-caller.json) | stillmoonb | 5 | published |
| R019 | [Sudden Tremors: Ancient Golem](../../resources/data/encounters/stillmoonb-sudden-tremors-ancient-golem.json) | stillmoonb | 6 | published |
| E328 | [Mountain dragon transformation](../../resources/data/encounters/stonebrunt-mountain-dragon-transformation.json) | stonebrunt | 0 | published |
| E329 | [Swamp dragon transformation](../../resources/data/encounters/swampofnohope-swamp-dragon-transformation.json) | swampofnohope | 0 | published |
| E330 | [Pixtt Kretv Krakxt](../../resources/data/encounters/tacvi-pixtt-kretv-krakxt.json) | tacvi | 0 | published |
| E331 | [Pixtt Riel Tavas](../../resources/data/encounters/tacvi-pixtt-riel-tavas.json) | tacvi | 0 | published |
| E332 | [Pixtt Xxeric Kex](../../resources/data/encounters/tacvi-pixtt-xxeric-kex.json) | tacvi | 0 | published |
| E333 | [Tunat`Muram Cuu Vauax](../../resources/data/encounters/tacvi-tunat-muram-cuu-vauax.json) | tacvi | 0 | published |
| E334 | [Zun`Muram Kvxe Pirik](../../resources/data/encounters/tacvi-zun-muram-kvxe-pirik.json) | tacvi | 0 | published |
| E335 | [Zun`Muram Mordl Delt](../../resources/data/encounters/tacvi-zun-muram-mordl-delt.json) | tacvi | 0 | published |
| E336 | [Zun`Muram Shaldn Boc](../../resources/data/encounters/tacvi-zun-muram-shaldn-boc.json) | tacvi | 0 | published |
| E337 | [Zun`Muram Yihst Vor](../../resources/data/encounters/tacvi-zun-muram-yihst-vor.json) | tacvi | 0 | published |
| E338 | [Takish-Hiz adventures (shared rules)](../../resources/data/encounters/taka-takish-hiz-adventures-shared-rules.json) | taka | 1 | published |
| E339 | [Quintessence of Sand and Ritana](../../resources/data/encounters/takc-quintessence-of-sand-and-ritana.json) | takc | 2 | published |
| E340 | [Living Legacy: Sandkeep Endurance Raid](../../resources/data/encounters/takishruinsa-living-legacy-sandkeep-endurance-raid.json) | takishruinsa | 1 | published |
| E341 | [Aaryonar](../../resources/data/encounters/templeveeshan-aaryonar.json) | templeveeshan | 0 | published |
| E342 | [Lady Mirenilla](../../resources/data/encounters/templeveeshan-lady-mirenilla.json) | templeveeshan | 0 | published |
| E343 | [Lord Feshlak](../../resources/data/encounters/templeveeshan-lord-feshlak.json) | templeveeshan | 0 | published |
| E344 | [Lord Kreizenn](../../resources/data/encounters/templeveeshan-lord-kreizenn.json) | templeveeshan | 0 | published |
| E345 | [Lord Vyemm](../../resources/data/encounters/templeveeshan-lord-vyemm.json) | templeveeshan | 0 | published |
| E346 | [Vulak`Aerr ring event](../../resources/data/encounters/templeveeshan-vulak-aerr-ring-event.json) | templeveeshan | 0 | published |
| R020 | [Disciple of Focus](../../resources/data/encounters/tenebrous-disciple-of-focus.json) | tenebrous | 0 | published |
| E347 | [Hoober](../../resources/data/encounters/tenebrous-hoober.json) | tenebrous | 0 | published |
| E348 | [Johanius Barleou](../../resources/data/encounters/tenebrous-johanius-barleou.json) | tenebrous | 0 | published |
| E349 | [Deklean Korgad: invisible bridge](../../resources/data/encounters/thedeep-deklean-korgad-invisible-bridge.json) | thedeep | 0 | published |
| E350 | [The Burrower Beast](../../resources/data/encounters/thedeep-the-burrower-beast.json) | thedeep | 0 | published |
| E351 | [Thought Horror Overfiend (Seasonal)](../../resources/data/encounters/thedeep-thought-horror-overfiend-seasonal.json) | thedeep | 200 | published |
| E352 | [A Failed Expedition: Shargone](../../resources/data/encounters/thenest-a-failed-expedition-shargone.json) | thenest | 8 | published |
| E353 | [Circle of Drakes](../../resources/data/encounters/thenest-circle-of-drakes.json) | thenest | 4 | published |
| E354 | [In the Shadows: Vishimtar](../../resources/data/encounters/thenest-in-the-shadows-vishimtar.json) | thenest | 3 | published |
| E355 | [Rampaging Monolith](../../resources/data/encounters/thenest-rampaging-monolith.json) | thenest | 14 | published |
| E356 | [Rivals](../../resources/data/encounters/thenest-rivals.json) | thenest | 11 | published |
| E357 | [T'Shara](../../resources/data/encounters/thenest-t-shara.json) | thenest | 0 | published |
| E358 | [The Curse of Jurek](../../resources/data/encounters/thenest-the-curse-of-jurek.json) | thenest | 6 | published |
| E359 | [Web of Lies](../../resources/data/encounters/thenest-web-of-lies.json) | thenest | 15 | published |
| E360 | [A Simple Task](../../resources/data/encounters/thundercrest-a-simple-task.json) | thundercrest | 8 | published |
| E361 | [An End to the Storms: Yar`Lir](../../resources/data/encounters/thundercrest-an-end-to-the-storms-yar-lir.json) | thundercrest | 200 | draft |
| E362 | [Behind Closed Doors](../../resources/data/encounters/thundercrest-behind-closed-doors.json) | thundercrest | 4 | published |
| E363 | [Four Corners](../../resources/data/encounters/thundercrest-four-corners.json) | thundercrest | 0 | published |
| E364 | [Holy Hour](../../resources/data/encounters/thundercrest-holy-hour.json) | thundercrest | 2 | published |
| E365 | [House of the Autumn Rose](../../resources/data/encounters/thundercrest-house-of-the-autumn-rose.json) | thundercrest | 5 | published |
| E366 | [Lair Unguarded](../../resources/data/encounters/thundercrest-lair-unguarded.json) | thundercrest | 6 | published |
| E367 | [Lore Warden](../../resources/data/encounters/thundercrest-lore-warden.json) | thundercrest | 0 | published |
| E368 | [Plunder the Hoard](../../resources/data/encounters/thundercrest-plunder-the-hoard.json) | thundercrest | 13 | published |
| E369 | [Scions of Thundercrest](../../resources/data/encounters/thundercrest-scions-of-thundercrest.json) | thundercrest | 7 | published |
| E370 | [Splitting the Storm](../../resources/data/encounters/thundercrest-splitting-the-storm.json) | thundercrest | 9 | published |
| E371 | [Storm Chasers / Raging Thunderhead](../../resources/data/encounters/thundercrest-storm-chasers-raging-thunderhead.json) | thundercrest | 0 | published |
| E372 | [Stormreach Challenge: Goblin Dojo](../../resources/data/encounters/thundercrest-stormreach-challenge-goblin-dojo.json) | thundercrest | 10 | published |
| R021 | [The Creator](../../resources/data/encounters/thundercrest-the-creator.json) | thundercrest | 11 | published |
| E373 | [Throes of Contagion](../../resources/data/encounters/thundercrest-throes-of-contagion.json) | thundercrest | 12 | published |
| E374 | [Draz Nurakk and his images](../../resources/data/encounters/timorous-draz-nurakk-and-his-images.json) | timorous | 0 | published |
| E375 | [Gefaari Drokaz](../../resources/data/encounters/timorous-gefaari-drokaz.json) | timorous | 0 | published |
| E376 | [Tipt mountain trials / Kyv Heartstriker Jhiru](../../resources/data/encounters/tipt-tipt-mountain-trials-kyv-heartstriker-jhiru.json) | tipt | 0 | published |
| E377 | [Overseer Wrank and the Captains](../../resources/data/encounters/torgiran-overseer-wrank-and-the-captains.json) | torgiran | 0 | published |
| E378 | [Taskmaster Lugald Brokenskull](../../resources/data/encounters/torgiran-taskmaster-lugald-brokenskull.json) | torgiran | 0 | published |
| E379 | [Ancient Cragbeast Matriarch](../../resources/data/encounters/txevu-ancient-cragbeast-matriarch.json) | txevu | 0 | published |
| E380 | [High Priest Nkosi Bakari](../../resources/data/encounters/txevu-high-priest-nkosi-bakari.json) | txevu | 0 | published |
| E381 | [Ikaav Nysf Lleiv](../../resources/data/encounters/txevu-ikaav-nysf-lleiv.json) | txevu | 0 | published |
| E382 | [Mastruq Champion / Ixt Hsek Syat](../../resources/data/encounters/txevu-mastruq-champion-ixt-hsek-syat.json) | txevu | 0 | published |
| E383 | [Ukun Bloodfeaster](../../resources/data/encounters/txevu-ukun-bloodfeaster.json) | txevu | 0 | published |
| E384 | [Zun`Muram Tkarish Zyk](../../resources/data/encounters/txevu-zun-muram-tkarish-zyk.json) | txevu | 0 | published |
| E385 | [Tqiv Araxt the Enraged and Tqiv Qukret the Furious](../../resources/data/encounters/uqua-tqiv-araxt-the-enraged-and-tqiv-qukret-the-furious.json) | uqua | 0 | published |
| E386 | [Uqua gas chambers](../../resources/data/encounters/uqua-uqua-gas-chambers.json) | uqua | 0 | published |
| E387 | [Vrex Barxt Qurat and Guardian of Destruction](../../resources/data/encounters/uqua-vrex-barxt-qurat-and-guardian-of-destruction.json) | uqua | 0 | published |
| E388 | [Phara Dar and the Ring of Scale](../../resources/data/encounters/veeshan-phara-dar-and-the-ring-of-scale.json) | veeshan | 0 | published |
| E389 | [Decaying Lord Galuk Drek](../../resources/data/encounters/veksar-decaying-lord-galuk-drek.json) | veksar | 0 | published |
| E390 | [Raging Bloodgill](../../resources/data/encounters/veksar-raging-bloodgill.json) | veksar | 0 | published |
| E391 | [Aten Ha Ra progression / Akhevan Warders](../../resources/data/encounters/vexthal-aten-ha-ra-progression-akhevan-warders.json) | vexthal | 0 | published |
| R024 | [NPC 158006: teleport hunt](../../resources/data/encounters/vexthal-npc-158006-teleport-hunt.json) | vexthal | 0 | draft |
| E392 | [Vxed trial / Stonespiritist Ekikoa](../../resources/data/encounters/vxed-vxed-trial-stonespiritist-ekikoa.json) | vxed | 0 | published |
| E393 | [Cristoc Bonethug](../../resources/data/encounters/wakening-cristoc-bonethug.json) | wakening | 0 | published |
| E394 | [Dark Disciple Master](../../resources/data/encounters/wakening-dark-disciple-master.json) | wakening | 0 | published |
| E395 | [Wuoshi (Seasonal)](../../resources/data/encounters/wakening-wuoshi-seasonal.json) | wakening | 200 | published |
| E396 | [Discordling Spiritcaller](../../resources/data/encounters/wallofslaughter-discordling-spiritcaller.json) | wallofslaughter | 0 | published |
| E397 | [Durunal the Cursebearer](../../resources/data/encounters/wallofslaughter-durunal-the-cursebearer.json) | wallofslaughter | 0 | published |
| E398 | [Lightning Lord](../../resources/data/encounters/wallofslaughter-lightning-lord.json) | wallofslaughter | 0 | published |
| E399 | [Murkglider Hivequeen](../../resources/data/encounters/wallofslaughter-murkglider-hivequeen.json) | wallofslaughter | 0 | published |
| E400 | [Pyrique Redwing](../../resources/data/encounters/wallofslaughter-pyrique-redwing.json) | wallofslaughter | 0 | published |
| E401 | [Tarn Icewind](../../resources/data/encounters/wallofslaughter-tarn-icewind.json) | wallofslaughter | 0 | published |
| E401 | [Tarn Icewind (Seasonal)](../../resources/data/encounters/wallofslaughter-tarn-icewind-seasonal.json) | wallofslaughter | 200 | published |
| E402 | [Raving Goblinmaster](../../resources/data/encounters/warslikswood-raving-goblinmaster.json) | warslikswood | 0 | published |
| E403 | [Grokui, the Slumbering Basilisk](../../resources/data/encounters/westkorlach-grokui-the-slumbering-basilisk.json) | westkorlach | 0 | published |
| E404 | [Matriarch Shyra](../../resources/data/encounters/westkorlacha-matriarch-shyra.json) | westkorlacha | 0 | published |
| E405 | [Find Fibblebrap 2: Lost Caverns](../../resources/data/encounters/westkorlachb-find-fibblebrap-2-lost-caverns.json) | westkorlachb | 1 | published |
| E406 | [Disciple of Moon](../../resources/data/encounters/westwastes-disciple-of-moon.json) | westwastes | 0 | published |
| E407 | [Klandicar (Seasonal)](../../resources/data/encounters/westwastes-klandicar-seasonal.json) | westwastes | 200 | published |
| E408 | [Scout Charisa: Kromzek Captain](../../resources/data/encounters/westwastes-scout-charisa-kromzek-captain.json) | westwastes | 0 | published |
| E409 | [Hexxt Huntmaster](../../resources/data/encounters/yxtta-hexxt-huntmaster.json) | yxtta | 0 | published |
| E410 | [Pixtt Suir Mindrider](../../resources/data/encounters/yxtta-pixtt-suir-mindrider.json) | yxtta | 0 | published |
| E411 | [Primal door riddle](../../resources/data/encounters/yxtta-primal-door-riddle.json) | yxtta | 0 | draft |
| E412 | [Xounii Shifter / Tqiv Trusik Fanatic](../../resources/data/encounters/yxtta-xounii-shifter-tqiv-trusik-fanatic.json) | yxtta | 0 | published |

## Disapproved entries

| ID | Zone | Encounter |
| --- | --- | --- |
| E002 | acrylia | Foreman Gworknop |
| E007 | acrylia | Witchdoctor ring |
| E008 | airplane | Spiroc island progression / The Spiroc Lord |
| E154 | highkeep | Xentil Herkanon assassination |
| R001 | acrylia | Burrower |
| R002 | blackburrow | Blackburrow invasion (Chunky / king event) |
| R004 | dreadlands | The Turkey King / Tainted Turkey invasion |
| R005 | dreadspire | Hatchet the Torturer |
| R009 | inktuta | Mimezpo the Oracle |
| R011 | kodtaz | Ba'Zrath, the Bound Lich |
| R013 | mischiefplane | Chess event |
| R016 | powar | Turkey Zek the Gobble Lord |
| R022 | unassigned | Greater Rift system (unwired module) |
| R023 | unrest | Wilhavyn Estate / Master Operative |

## Validation

Approval coverage and source checks: passed. 717 distinct reviewed source files were checked against recorded SHA-256 hashes. The saved workbook hash was checked to detect later edits.

Recorded application checks on 2026-09-29: schema validation passed for 436 documents, including the five original Mistmoore entries. The focused journal test suite passed 24 tests with 146 assertions. These checks ran in the existing local PHP preview container.

Schema command: `php artisan encounters:validate`.

Focused test command: `php artisan test tests/Unit/Services/EncounterJournal tests/Feature/EncounterJournalTest.php tests/Feature/Console/EncounterCommandsTest.php`.

Publication checks on 2026-09-29: the local public index lists 428 entries. A newly published guide returns HTTP 200, a held draft returns HTTP 404 and is absent from public search, and Mayong remains behind the spoiler reveal. Schema validation still passes for 436 documents. Production deployment was not performed by these checks.

Run the Library schema validator with `php artisan encounters:validate`. When the quest repository is mounted beside the application, pass `--source-root` to that command for a full source freshness check. Host-side coverage and source checks can be repeated with `node docs/encounter-audit/verify_generation.mjs`.

Source review and schema validation do not establish matching live database data, deployed quest versions, combat behavior or balance.
