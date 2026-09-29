# Encounter candidates

Quest working-tree scan dated September 28, 2026.

Scanned **8,114 Lua/Perl scripts** (358,551 lines) across **310 zone directories**, plus shared support code. All scripts were read successfully. Includes **262 encounter modules** and uncommitted quest changes.

Base quest revision at scan start: `ad251da365c0241c2f37673ed2af58e93b578c09`. Revision at completion: `0a5b300a911d7226a02573e4e6d89fb6ff740bc2`. The working-tree changes were committed during the scan; all scanned source-file bytes were rechecked and unchanged. File hashes in the inventory identify the exact scanned contents.

Choose **Approve**, **Disapprove**, or leave **Pending**. Reply using encounter IDs, ranges, or entire zones. No new journal entries have been generated or published from this list.

A clear candidate has identifiable scripted combat or event objectives. This is an encounter inventory, not a complete mechanics audit. Source presence does not establish that the live database spawns or enables it. Journal generation will verify NPC IDs, zone versions, dependencies and detailed mechanics for the approved entries.

Related waves, controllers and add scripts are grouped into the encounter they support. Dialogue, merchants, ordinary turn-ins, generic loot/achievement hooks and ordinary named mobs without meaningful encounter behavior are omitted. Distinct seasonal mechanics are identified separately where present.

## Candidates by zone

412 new candidates, 24 entries needing investigation, and 5 entries already documented.

## Clear candidates


### acrylia

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E001 | Archmage Gorraferg ring | event | Eight-wave controller culminates in the archmage and includes failure/reset behavior. <br>[acrylia/#archmage_spawner.lua:71](<F:/EQ1/Bastion_Dev/quests/acrylia/%23archmage_spawner.lua:71>) · [acrylia/#the_grimling_archmage.lua:9](<F:/EQ1/Bastion_Dev/quests/acrylia/%23the_grimling_archmage.lua:9>) | Pending |
| E002 | Foreman Gworknop | event | Killing elite guards triggers an attackable foreman and replacement undead guards. <br>[acrylia/#foreman_control.lua:10](<F:/EQ1/Bastion_Dev/quests/acrylia/%23foreman_control.lua:10>) · [acrylia/#foreman_control.lua:34](<F:/EQ1/Bastion_Dev/quests/acrylia/%23foreman_control.lua:34>) | Pending |
| E003 | High Priest Gakkernog ring | event | Proximity trigger runs timed waves and spawns the high priest after the wave sequence. <br>[acrylia/#HP_shackles.lua:54](<F:/EQ1/Bastion_Dev/quests/acrylia/%23HP_shackles.lua:54>) · [acrylia/#High_Priest_Gakkernog.lua:1](<F:/EQ1/Bastion_Dev/quests/acrylia/%23High_Priest_Gakkernog.lua:1>) | Pending |
| E004 | Khati Sha the Twisted | raid | Warders, spiritists, minions and staged activation form a full coordinated raid encounter. Include the prerequisite warder/chant sequence under this entry.<br>[acrylia/Khati_Sha_the_Twisted.lua:46](<F:/EQ1/Bastion_Dev/quests/acrylia/Khati_Sha_the_Twisted.lua:46>) · [acrylia/#arcanist_trigger.lua:62](<F:/EQ1/Bastion_Dev/quests/acrylia/%23arcanist_trigger.lua:62>) · [acrylia/WDTrpMn.lua:1](<F:/EQ1/Bastion_Dev/quests/acrylia/WDTrpMn.lua:1>) | Pending |
| E005 | Ring of Fire | event | Controller progresses rounds, trash waves, minibosses and final bosses. <br>[acrylia/#RoF_spawner.lua:45](<F:/EQ1/Bastion_Dev/quests/acrylia/%23RoF_spawner.lua:45>) · [acrylia/#RoF_spawner.lua:143](<F:/EQ1/Bastion_Dev/quests/acrylia/%23RoF_spawner.lua:143>) | Pending |
| E006 | Trondol Shir escort | event | Key quest escort spawns hostile guards and a soulstealer and checks completion. <br>[acrylia/Trondol_Shir.lua:41](<F:/EQ1/Bastion_Dev/quests/acrylia/Trondol_Shir.lua:41>) · [acrylia/Trondol_Shir.lua:66](<F:/EQ1/Bastion_Dev/quests/acrylia/Trondol_Shir.lua:66>) | Pending |
| E007 | Witchdoctor ring | event | Timed waves and elemental summoners culminate in a witchdoctor whose resistances depend on the summoners. <br>[acrylia/#witchdoctor_spawner.lua:44](<F:/EQ1/Bastion_Dev/quests/acrylia/%23witchdoctor_spawner.lua:44>) · [acrylia/154391.lua:13](<F:/EQ1/Bastion_Dev/quests/acrylia/154391.lua:13>) | Pending |

### airplane

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E008 | Spiroc island progression / The Spiroc Lord | raid | Linked spiroc NPCs repop one another until their guardian chain is broken; Lord victory and Sirran access depend on the guardian state. Consolidated island sequence rather than a row per spiroc helper.<br>[airplane/The_Spiroc_Guardian.lua:17](<F:/EQ1/Bastion_Dev/quests/airplane/The_Spiroc_Guardian.lua:17>) · [airplane/The_Spiroc_Lord.lua:1](<F:/EQ1/Bastion_Dev/quests/airplane/The_Spiroc_Lord.lua:1>) · [airplane/a_spiroc_vanquisher.lua:27](<F:/EQ1/Bastion_Dev/quests/airplane/a_spiroc_vanquisher.lua:27>) | Pending |

### akheva

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E009 | Shei Vinitras | raid | Loaded encounter scripts death touch, fake/real forms and adds on player kills. <br>[akheva/encounters/Shei.lua:12](<F:/EQ1/Bastion_Dev/quests/akheva/encounters/Shei.lua:12>) · [akheva/Shei_Vinitras.lua:5](<F:/EQ1/Bastion_Dev/quests/akheva/Shei_Vinitras.lua:5>) | Pending |
| E010 | The Insanity Crawler / Time-Corrupted Insanity Crawler | raid | Seasonal burrow/vulnerability thresholds, tremors and kill-triggered mind worms; normal crawler also has kill-triggered adds. Instance trigger selects the custom crawler; preserve distinct normal and seasonal behavior in eventual document.<br>[akheva/A_Time-Corrupted_Insanity_Crawler.lua:31](<F:/EQ1/Bastion_Dev/quests/akheva/A_Time-Corrupted_Insanity_Crawler.lua:31>) · [akheva/A_Time-Corrupted_Insanity_Crawler.lua:70](<F:/EQ1/Bastion_Dev/quests/akheva/A_Time-Corrupted_Insanity_Crawler.lua:70>) · [akheva/The_Insanity_Crawler.lua:6](<F:/EQ1/Bastion_Dev/quests/akheva/The_Insanity_Crawler.lua:6>) · [akheva/insanity.lua:10](<F:/EQ1/Bastion_Dev/quests/akheva/insanity.lua:10>) | Pending |

### anguish

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E011 | Arch Magus Vangl | raid | HP stages, Vangl's Focus and recurring languished converts. <br>[anguish/encounters/amv.lua:31](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/amv.lua:31>) · [anguish/encounters/amv.lua:72](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/amv.lua:72>) · [anguish/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:5>) | Pending |
| E012 | Jelvan | raid | Three linked tormentors, shared state checks and Jelvan blessings. <br>[anguish/encounters/jelvan.lua:38](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/jelvan.lua:38>) · [anguish/encounters/jelvan.lua:226](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/jelvan.lua:226>) · [anguish/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:2>) | Pending |
| E013 | Keldovan the Harrier | raid | Pit fiend/hound adds, curse rotation and add-death interactions. <br>[anguish/encounters/keldovan.lua:52](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/keldovan.lua:52>) · [anguish/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:1>) | Pending |
| E014 | Overlord Mata Muram | raid | Multiple health phases, linked lieutenants/adds and scripted failure responses. <br>[anguish/encounters/omm.lua:112](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/omm.lua:112>) · [anguish/script_init.lua:6](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:6>) | Pending |
| E015 | Ture | raid | Timed destruction/rampage abilities and combat resets. <br>[anguish/encounters/ture.lua:51](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/ture.lua:51>) · [anguish/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:3>) | Pending |
| E016 | Warden Hanvar | raid | Five guards, guard-death progression and scripted combat spells. <br>[anguish/encounters/hanvar.lua:45](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/hanvar.lua:45>) · [anguish/encounters/hanvar.lua:70](<F:/EQ1/Bastion_Dev/quests/anguish/encounters/hanvar.lua:70>) · [anguish/script_init.lua:4](<F:/EQ1/Bastion_Dev/quests/anguish/script_init.lua:4>) | Pending |

### arena2

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E017 | Heigan the Unclean: The Safety Dance | event | Server-controlled movement encounter with phase timing, geometry, damage and failure handling. Verified instance version6.<br>[arena2/encounters/arena2_heigan_safety_dance.lua:1](<F:/EQ1/Bastion_Dev/quests/arena2/encounters/arena2_heigan_safety_dance.lua:1>) · [arena2/script_init.lua:13](<F:/EQ1/Bastion_Dev/quests/arena2/script_init.lua:13>) | Pending |
| E018 | Living Legacy: Battle Prowess | raid | Custom timed raid with phase scaling, adds and participant capture. Verified instance version7.<br>[arena2/encounters/living_legacy_battle_prowess.lua:1](<F:/EQ1/Bastion_Dev/quests/arena2/encounters/living_legacy_battle_prowess.lua:1>) · [arena2/script_init.lua:15](<F:/EQ1/Bastion_Dev/quests/arena2/script_init.lua:15>) | Pending |
| E019 | Trial of the Dreadscale | group | Four difficulty profiles with combat phases and movement telegraphs. Verified versions2-5; intentionally reward-free training encounter.<br>[arena2/encounters/arena2_kunark_trial.lua:67](<F:/EQ1/Bastion_Dev/quests/arena2/encounters/arena2_kunark_trial.lua:67>) · [arena2/encounters/arena2_kunark_trial.lua:1319](<F:/EQ1/Bastion_Dev/quests/arena2/encounters/arena2_kunark_trial.lua:1319>) · [arena2/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/arena2/script_init.lua:11>) | Pending |

### barindu

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E020 | Ixvet Pox / Colossus of War | raid | Controllers activate constructs and colossus; alternate poison sequence changes Ixvet's state. Grouped linked event; some source comments note unparsed Nihil behavior.<br>[barindu/encounters/iip.lua:18](<F:/EQ1/Bastion_Dev/quests/barindu/encounters/iip.lua:18>) · [barindu/encounters/iip.lua:105](<F:/EQ1/Bastion_Dev/quests/barindu/encounters/iip.lua:105>) · [barindu/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/barindu/script_init.lua:1>) | Pending |
| E021 | Kyv Rux Vhedt | group | Health threshold switches the named kyv into ranged behavior with a timer. <br>[barindu/#Kyv_Rux_Vhedt.lua:7](<F:/EQ1/Bastion_Dev/quests/barindu/%23Kyv_Rux_Vhedt.lua:7>) | Pending |

### bloodfields

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E022 | Discordling Dark Animist | event | Beastlord2.0 boss appears with six savage ferans and a bounded encounter duration. <br>[bloodfields/#Discordling_Dark_Animist.lua:3](<F:/EQ1/Bastion_Dev/quests/bloodfields/%23Discordling_Dark_Animist.lua:3>) | Pending |
| E023 | Elite Dragorn Jekisia | event | Repeated health thresholds relocate and root the boss while summoning dragorn flunkies. <br>[bloodfields/#Elite_Dragorn_Jekisia.lua:98](<F:/EQ1/Bastion_Dev/quests/bloodfields/%23Elite_Dragorn_Jekisia.lua:98>) | Pending |
| E024 | Gazz the Gargantuan | raid | Three dreamwalkers control boss awakening and reset timers. <br>[bloodfields/encounters/gaz.lua:51](<F:/EQ1/Bastion_Dev/quests/bloodfields/encounters/gaz.lua:51>) · [bloodfields/encounters/gaz.lua:70](<F:/EQ1/Bastion_Dev/quests/bloodfields/encounters/gaz.lua:70>) · [bloodfields/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/bloodfields/script_init.lua:1>) | Pending |
| E025 | Kragsmash | event | Monk challenge summons paired strength/speed adds at three health thresholds. <br>[bloodfields/#Kragsmash.lua:29](<F:/EQ1/Bastion_Dev/quests/bloodfields/%23Kragsmash.lua:29>) · [bloodfields/#Kragsmash.lua:42](<F:/EQ1/Bastion_Dev/quests/bloodfields/%23Kragsmash.lua:42>) | Pending |
| E026 | Marshal Histent / Grinbik the Fertile rescue | event | Linked marshal combat and Grinbik rescue/surrender event. <br>[bloodfields/encounters/ranger_1_5.lua:9](<F:/EQ1/Bastion_Dev/quests/bloodfields/encounters/ranger_1_5.lua:9>) · [bloodfields/marshall_trap.lua:27](<F:/EQ1/Bastion_Dev/quests/bloodfields/marshall_trap.lua:27>) | Pending |
| E027 | Reclusive girplan chain / Rolthee and Myrhee | event | Sequential girplan deaths lead to the rogue epic targets. <br>[bloodfields/301033.lua:1](<F:/EQ1/Bastion_Dev/quests/bloodfields/301033.lua:1>) · [bloodfields/301076.lua:1](<F:/EQ1/Bastion_Dev/quests/bloodfields/301076.lua:1>) · [bloodfields/#Rolthee_Roundbelly.lua:1](<F:/EQ1/Bastion_Dev/quests/bloodfields/%23Rolthee_Roundbelly.lua:1>) | Pending |
| E028 | The Keeper | event | Monk challenge progresses health thresholds and a timed encounter state. <br>[bloodfields/The_Keeper.lua:29](<F:/EQ1/Bastion_Dev/quests/bloodfields/The_Keeper.lua:29>) · [bloodfields/The_Keeper.lua:36](<F:/EQ1/Bastion_Dev/quests/bloodfields/The_Keeper.lua:36>) | Pending |
| E029 | War Caller Kaavi | event | Sentries/guardians gate Kaavi and health threshold summons additional sentinels. <br>[bloodfields/encounters/war.lua:5](<F:/EQ1/Bastion_Dev/quests/bloodfields/encounters/war.lua:5>) · [bloodfields/War_Caller_Kaavi.lua:21](<F:/EQ1/Bastion_Dev/quests/bloodfields/War_Caller_Kaavi.lua:21>) · [bloodfields/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/bloodfields/script_init.lua:2>) | Pending |

### bothunder

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E030 | Agnarr the Storm Lord | raid | Health-based elemental boss summons and seasonal storm mechanics. <br>[bothunder/Agnarr_the_Storm_Lord.lua:49](<F:/EQ1/Bastion_Dev/quests/bothunder/Agnarr_the_Storm_Lord.lua:49>) · [bothunder/Agnarr_the_Storm_Lord.lua:77](<F:/EQ1/Bastion_Dev/quests/bothunder/Agnarr_the_Storm_Lord.lua:77>) | Pending |
| E031 | Emmerik Skyfury | raid | Timed combat signals activate portal adds, and victory advances tower progression. <br>[bothunder/Emmerik_Skyfury.lua:4](<F:/EQ1/Bastion_Dev/quests/bothunder/Emmerik_Skyfury.lua:4>) | Pending |
| E032 | Evynd Firestorm | raid | Timed combat signals activate portal adds, and victory advances tower progression. <br>[bothunder/Evynd_Firestorm.lua:4](<F:/EQ1/Bastion_Dev/quests/bothunder/Evynd_Firestorm.lua:4>) | Pending |

### cauldron

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E033 | Praklion of the Cauldron | event | Two health thresholds require the event to be resolved correctly for the quest reward. <br>[cauldron/Praklion_of_the_Cauldron.lua:8](<F:/EQ1/Bastion_Dev/quests/cauldron/Praklion_of_the_Cauldron.lua:8>) | Pending |

### causeway

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E034 | Alpha Feran | group | Timed howl links combat to attendant/defender helpers. <br>[causeway/Alpha_Feran.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/Alpha_Feran.lua:1>) | Pending |
| E035 | Bazu Smasher | event | Multiple health thresholds summon pairs of Bazu Hulks. <br>[causeway/Bazu_Smasher.lua:4](<F:/EQ1/Bastion_Dev/quests/causeway/Bazu_Smasher.lua:4>) | Pending |
| E036 | Bazu Terror | event | Beastlord2.0 trigger creates the boss and four Bazu Crushers. <br>[causeway/#Jillaa_Oogblat.lua:19](<F:/EQ1/Bastion_Dev/quests/causeway/%23Jillaa_Oogblat.lua:19>) · [causeway/#Bazu_Terror.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/%23Bazu_Terror.lua:1>) | Pending |
| E037 | Essence of Kreljnok | event | Timed Power/Rage adds and add-alive checks support the warrior2.0 encounter. <br>[causeway/Essence_of_Kreljnok.lua:10](<F:/EQ1/Bastion_Dev/quests/causeway/Essence_of_Kreljnok.lua:10>) · [causeway/Essence_of_Kreljnok.lua:21](<F:/EQ1/Bastion_Dev/quests/causeway/Essence_of_Kreljnok.lua:21>) | Pending |
| E038 | Magician1.5 elemental mastery event | event | Multi-NPC elemental event with timed casts, health states and spawned opposition. <br>[causeway/encounters/mageepic_1_5.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/encounters/mageepic_1_5.lua:1>) · [causeway/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/script_init.lua:1>) | Pending |
| E039 | Oshiruk | event | Bard2.0 final encounter runs a recurring Tendrils of Oshiruk area attack. <br>[causeway/#Oshiruk.lua:3](<F:/EQ1/Bastion_Dev/quests/causeway/%23Oshiruk.lua:3>) | Pending |
| E040 | Siflu | event | Ranger2.0 fight has a scripted surrender health threshold and quest progression. <br>[causeway/#Siflu.lua:8](<F:/EQ1/Bastion_Dev/quests/causeway/%23Siflu.lua:8>) | Pending |
| E041 | Slavedriver Menlo | event | Timed refugee waves join combat with repeated hate checks. <br>[causeway/#Slavedriver_Menlo.lua:12](<F:/EQ1/Bastion_Dev/quests/causeway/%23Slavedriver_Menlo.lua:12>) · [causeway/#Slavedriver_Menlo.lua:22](<F:/EQ1/Bastion_Dev/quests/causeway/%23Slavedriver_Menlo.lua:22>) | Pending |
| E042 | Stone Thrower | raid | Seasonal enhancement adds timed combat mechanics to the named encounter. <br>[causeway/#Stone_Thrower.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/%23Stone_Thrower.lua:1>) | Pending |
| E043 | Withering Murkglider | event | Repeated health thresholds spawn murkglider adds. <br>[causeway/#Withering_Murkglider.lua:1](<F:/EQ1/Bastion_Dev/quests/causeway/%23Withering_Murkglider.lua:1>) | Pending |

### cazicthule

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E044 | Gimlik Cogboggle escort | event | Escort waypoints trigger successive ambushes and a final tracker. <br>[cazicthule/Gimlik_Cogboggle.lua:12](<F:/EQ1/Bastion_Dev/quests/cazicthule/Gimlik_Cogboggle.lua:12>) · [cazicthule/Gimlik_Cogboggle.lua:82](<F:/EQ1/Bastion_Dev/quests/cazicthule/Gimlik_Cogboggle.lua:82>) | Pending |
| E045 | Ring of Fear | event | High-priest trigger frees Tahia and begins waves that activate Dread, Fright and Terror avatars. <br>[cazicthule/#a_Thul_Tae_Ew_High_Priest.lua:11](<F:/EQ1/Bastion_Dev/quests/cazicthule/%23a_Thul_Tae_Ew_High_Priest.lua:11>) · [cazicthule/Avatar_of_Fear.lua:15](<F:/EQ1/Bastion_Dev/quests/cazicthule/Avatar_of_Fear.lua:15>) | Pending |
| E046 | Sickly mosquito | event | Ranger1.5 combat target has health-threshold and timed behavior. <br>[cazicthule/a_sickly_mosquito.lua:3](<F:/EQ1/Bastion_Dev/quests/cazicthule/a_sickly_mosquito.lua:3>) | Pending |

### chambersa

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E047 | Mastery of Fear | group | Trial distinguishes fearable/fearless enemies, timed behavior and completion chest. <br>[chambersa/encounters/mpg_fear.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersa/encounters/mpg_fear.lua:1>) · [chambersa/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersa/script_init.lua:1>) | Pending |
| E048 | Mastery of Hate | raid | Feran waves precede a hate-management master encounter. <br>[chambersa/encounters/mpg_hate.lua:40](<F:/EQ1/Bastion_Dev/quests/chambersa/encounters/mpg_hate.lua:40>) · [chambersa/encounters/mpg_hate.lua:123](<F:/EQ1/Bastion_Dev/quests/chambersa/encounters/mpg_hate.lua:123>) · [chambersa/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chambersa/script_init.lua:2>) | Pending |

### chambersb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E049 | Mastery of Endurance | raid | Timed multi-NPC endurance raid trial. <br>[chambersb/encounters/mpg_endurance.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersb/encounters/mpg_endurance.lua:1>) · [chambersb/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chambersb/script_init.lua:2>) | Pending |
| E050 | Mastery of Weaponry | group | Weapon-specialized opponents and event timers form a group mastery trial. <br>[chambersb/encounters/mpg_weaponry.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersb/encounters/mpg_weaponry.lua:1>) · [chambersb/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersb/script_init.lua:1>) | Pending |

### chambersc

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E051 | Mastery of Foresight | raid | Timed warnings and multi-NPC responses implement the foresight raid trial. <br>[chambersc/encounters/mpg_foresight.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersc/encounters/mpg_foresight.lua:1>) · [chambersc/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chambersc/script_init.lua:2>) | Pending |
| E052 | Mastery of Subversion | group | Scripted trial coordinates a master, guardians, event timers and completion. <br>[chambersc/encounters/mpg_subversion.lua:102](<F:/EQ1/Bastion_Dev/quests/chambersc/encounters/mpg_subversion.lua:102>) · [chambersc/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersc/script_init.lua:1>) | Pending |

### chambersd

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E053 | Mastery of Efficiency | group | Trial applies resource-limiting debuffs and timed enforcer/kill progression. <br>[chambersd/encounters/mpg_efficiency.lua:55](<F:/EQ1/Bastion_Dev/quests/chambersd/encounters/mpg_efficiency.lua:55>) · [chambersd/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersd/script_init.lua:1>) | Pending |
| E054 | Mastery of Specialization | raid | Specialized enemy handling and completion logic define a raid trial. <br>[chambersd/encounters/mpg_specialization.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersd/encounters/mpg_specialization.lua:1>) · [chambersd/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chambersd/script_init.lua:2>) | Pending |

### chamberse

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E055 | Mastery of Adaptation | raid | Adaptive enemy state and multi-phase raid trial. <br>[chamberse/encounters/mpg_adaptation.lua:1](<F:/EQ1/Bastion_Dev/quests/chamberse/encounters/mpg_adaptation.lua:1>) · [chamberse/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chamberse/script_init.lua:2>) | Pending |
| E056 | Mastery of Ingenuity | group | Timed group trial challenges the party against a scripted master with combat checks and completion handling. <br>[chamberse/encounters/mpg_ingenuity.lua:74](<F:/EQ1/Bastion_Dev/quests/chamberse/encounters/mpg_ingenuity.lua:74>) · [chamberse/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chamberse/script_init.lua:1>) | Pending |

### chambersf

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E057 | Mastery of Corruption | raid | Multi-NPC corruption trial with health/timer-driven event states. <br>[chambersf/encounters/mpg_corruption.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersf/encounters/mpg_corruption.lua:1>) · [chambersf/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/chambersf/script_init.lua:2>) | Pending |
| E058 | Mastery of Destruction | group | Timed destruction trial with enemy waves and reward completion. <br>[chambersf/encounters/mpg_destruction.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersf/encounters/mpg_destruction.lua:1>) · [chambersf/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/chambersf/script_init.lua:1>) | Pending |

### chardokb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E059 | Korucust | raid | Oblivion/Vengeance spells and an HP-triggered Phantasmal Shift second phase. <br>[chardokb/#Korucust.lua:20](<F:/EQ1/Bastion_Dev/quests/chardokb/%23Korucust.lua:20>) · [chardokb/#Korucust.lua:58](<F:/EQ1/Bastion_Dev/quests/chardokb/%23Korucust.lua:58>) | Pending |
| E060 | Leprous chokidai | event | Ranger1.5 encounter with low-health behavior and Epidermal Rot on death. <br>[chardokb/a_leprous_chokidai.lua:3](<F:/EQ1/Bastion_Dev/quests/chardokb/a_leprous_chokidai.lua:3>) · [chardokb/a_leprous_chokidai.lua:29](<F:/EQ1/Bastion_Dev/quests/chardokb/a_leprous_chokidai.lua:29>) | Pending |

### citymist

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E061 | Black reaver / Lord Ghiosk / Lord Rak'Ashiir chain | event | Repeated black reaver deaths conditionally replace them with named encounters. Includes fabled/normal branch in90200.lua; authoring should verify the applicable spawn IDs.<br>[citymist/90005.lua:2](<F:/EQ1/Bastion_Dev/quests/citymist/90005.lua:2>) · [citymist/90200.lua:1](<F:/EQ1/Bastion_Dev/quests/citymist/90200.lua:1>) | Pending |

### cobaltscar

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E062 | Gnashing Killer Shark and companions | event | Shaman1.5 trigger spawns the Gnashing Killer Shark, Darting Shark and Feeder Shark together. <br>[cobaltscar/Yoppa_Greenthumb.lua:15](<F:/EQ1/Bastion_Dev/quests/cobaltscar/Yoppa_Greenthumb.lua:15>) | Pending |

### codecay

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E063 | Bertoxxulous | raid | Crypt waves, named kings and kill counters culminate in Bertoxxulous. <br>[codecay/encounters/Bertoxx_Event.lua:89](<F:/EQ1/Bastion_Dev/quests/codecay/encounters/Bertoxx_Event.lua:89>) · [codecay/encounters/Bertoxx_Event.lua:142](<F:/EQ1/Bastion_Dev/quests/codecay/encounters/Bertoxx_Event.lua:142>) · [codecay/#Spectre_of_Corruption.lua:8](<F:/EQ1/Bastion_Dev/quests/codecay/%23Spectre_of_Corruption.lua:8>) | Pending |
| E064 | Carprin Deatharn cycle | raid | Guard gating leads through Avhi, Bishop and the final high-priest/guard stage. Keep dependent named steps as phases of this cycle.<br>[codecay/#_Carprin_Deatharn.lua:20](<F:/EQ1/Bastion_Dev/quests/codecay/%23_Carprin_Deatharn.lua:20>) · [codecay/Avhi_Escron.lua:38](<F:/EQ1/Bastion_Dev/quests/codecay/Avhi_Escron.lua:38>) · [codecay/Bishop_Toluwon.lua:10](<F:/EQ1/Bastion_Dev/quests/codecay/Bishop_Toluwon.lua:10>) | Pending |
| E065 | Overlord Banord Paffa | event | Wave and failure timers with pusling checks culminate in the real overlord. <br>[codecay/encounters/Overlord_Banord_Paffa.lua:64](<F:/EQ1/Bastion_Dev/quests/codecay/encounters/Overlord_Banord_Paffa.lua:64>) · [codecay/A_deep.lua:14](<F:/EQ1/Bastion_Dev/quests/codecay/A_deep.lua:14>) | Pending |

### corathus

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E066 | Taskmaster | event | Reaper deaths release the taskmaster, then health thresholds activate more adds. <br>[corathus/encounters/taskmaster.lua:34](<F:/EQ1/Bastion_Dev/quests/corathus/encounters/taskmaster.lua:34>) · [corathus/encounters/taskmaster.lua:82](<F:/EQ1/Bastion_Dev/quests/corathus/encounters/taskmaster.lua:82>) · [corathus/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/corathus/script_init.lua:1>) | Pending |

### corathusb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E067 | Find Fibblebrap1: The Mines | mission | Instanced mission handles named objectives, pickups, combat state and reward completion. <br>[corathusb/encounters/find_fibblebrap_one.lua:49](<F:/EQ1/Bastion_Dev/quests/corathusb/encounters/find_fibblebrap_one.lua:49>) · [corathusb/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/corathusb/script_init.lua:2>) | Pending |

### dawnshroud

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E068 | Hunter's Pike beast challenge | event | Carre Harger summons two beasts; each death transforms into another beast opponent. <br>[dawnshroud/#Carre_Harger.lua:8](<F:/EQ1/Bastion_Dev/quests/dawnshroud/%23Carre_Harger.lua:8>) · [dawnshroud/174322.lua:2](<F:/EQ1/Bastion_Dev/quests/dawnshroud/174322.lua:2>) · [dawnshroud/174323.lua:2](<F:/EQ1/Bastion_Dev/quests/dawnshroud/174323.lua:2>) | Pending |

### delvea

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E069 | A Halfling's Greed | mission | Cursed/gold chests have guards, inspection/opening rules and triggered consequences. <br>[delvea/encounters/a_halflings_greed.lua:63](<F:/EQ1/Bastion_Dev/quests/delvea/encounters/a_halflings_greed.lua:63>) · [delvea/script_init.lua:15](<F:/EQ1/Bastion_Dev/quests/delvea/script_init.lua:15>) | Pending |
| E070 | Lavaspinner Hunting | mission | Kill progression spawns cracked and pristine eggs for mission objectives. <br>[delvea/encounters/lavaspinner_hunting.lua:25](<F:/EQ1/Bastion_Dev/quests/delvea/encounters/lavaspinner_hunting.lua:25>) · [delvea/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/delvea/script_init.lua:5>) | Pending |
| E071 | Stonemaw | group | Magma, Molten and Smolder spawn when Stonemaw enters combat. <br>[delvea/Stonemaw.lua:1](<F:/EQ1/Bastion_Dev/quests/delvea/Stonemaw.lua:1>) | Pending |
| E072 | The Drake Menace / Drake Matriarch | mission | Kill/egg progression triggers the Drake Matriarch. <br>[delvea/encounters/the_drake_menace.lua:45](<F:/EQ1/Bastion_Dev/quests/delvea/encounters/the_drake_menace.lua:45>) · [delvea/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/delvea/script_init.lua:11>) | Pending |
| E073 | Volkara's Bite | raid | Health stages place eggs that hatch into magma spiderlings. <br>[delvea/encounters/volkaras_bite.lua:10](<F:/EQ1/Bastion_Dev/quests/delvea/encounters/volkaras_bite.lua:10>) · [delvea/encounters/volkaras_bite.lua:21](<F:/EQ1/Bastion_Dev/quests/delvea/encounters/volkaras_bite.lua:21>) · [delvea/script_init.lua:19](<F:/EQ1/Bastion_Dev/quests/delvea/script_init.lua:19>) | Pending |

### delveb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E074 | A Goblin's Escort | mission | Sequential goblin escorts use arrival checks, dialogue stages and nearby hostile aggro. <br>[delveb/encounters/a_goblins_escort.lua:38](<F:/EQ1/Bastion_Dev/quests/delveb/encounters/a_goblins_escort.lua:38>) · [delveb/script_init.lua:9](<F:/EQ1/Bastion_Dev/quests/delveb/script_init.lua:9>) | Pending |
| E075 | Emoush the Destroyer | raid | Three mystics control vulnerability and power-up states of Emoush. <br>[delveb/encounters/emoush.lua:17](<F:/EQ1/Bastion_Dev/quests/delveb/encounters/emoush.lua:17>) · [delveb/encounters/emoush.lua:29](<F:/EQ1/Bastion_Dev/quests/delveb/encounters/emoush.lua:29>) · [delveb/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/delveb/script_init.lua:5>) | Pending |
| E076 | Have Note, Will Travel | mission | Kill counts summon a Goblin Task Master, whose death spawns guard waves. <br>[delveb/encounters/have_note_will_travel.lua:3](<F:/EQ1/Bastion_Dev/quests/delveb/encounters/have_note_will_travel.lua:3>) · [delveb/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/delveb/script_init.lua:3>) | Pending |
| E077 | Tirranun: Fanning the Flames | raid | Banish phase transitions through a false death and regeneration into a stronger form with ash adds; bridge hazards also scripted. The encounter module alone is largely notes, but the separate named NPC script contains the actual boss behavior.<br>[delveb/#Tirranun.lua:9](<F:/EQ1/Bastion_Dev/quests/delveb/%23Tirranun.lua:9>) · [delveb/#Tirranun.lua:27](<F:/EQ1/Bastion_Dev/quests/delveb/%23Tirranun.lua:27>) · [delveb/encounters/fanning_the_flames.lua:41](<F:/EQ1/Bastion_Dev/quests/delveb/encounters/fanning_the_flames.lua:41>) · [delveb/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/delveb/script_init.lua:11>) | Pending |

### drachnidhive

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E078 | Drithnak the Exiled | group | Combat timers create drachnid egg sacs, coordinate hate and enforce distance checks. <br>[drachnidhive/#Drithnak_the_Exiled.lua:1](<F:/EQ1/Bastion_Dev/quests/drachnidhive/%23Drithnak_the_Exiled.lua:1>) | Pending |
| E079 | The Lost Notebook | mission | Mission-specific enemy deaths and trap-triggered spawns progress the notebook recovery. <br>[drachnidhive/encounters/the_lost_notebook.lua:68](<F:/EQ1/Bastion_Dev/quests/drachnidhive/encounters/the_lost_notebook.lua:68>) · [drachnidhive/encounters/the_lost_notebook.lua:80](<F:/EQ1/Bastion_Dev/quests/drachnidhive/encounters/the_lost_notebook.lua:80>) · [drachnidhive/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/drachnidhive/script_init.lua:3>) | Pending |

### drachnidhivea

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E080 | Find Fibblebrap3: The Hive | mission | Stage progression controls a foreman encounter and mission reward. <br>[drachnidhivea/encounters/find_fibblebrap_three.lua:86](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/encounters/find_fibblebrap_three.lua:86>) · [drachnidhivea/encounters/find_fibblebrap_three.lua:101](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/encounters/find_fibblebrap_three.lua:101>) · [drachnidhivea/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/script_init.lua:3>) | Pending |
| E081 | The Lost Gnomes | mission | Destroy cocoons and protect/rescue gnomes amid hostile bursts. <br>[drachnidhivea/encounters/the_lost_gnomes.lua:94](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/encounters/the_lost_gnomes.lua:94>) · [drachnidhivea/encounters/the_lost_gnomes.lua:160](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/encounters/the_lost_gnomes.lua:160>) · [drachnidhivea/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/drachnidhivea/script_init.lua:5>) | Pending |

### drachnidhivec

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E082 | Sendaii the Hive Queen | raid | Egg trigger, curse progression and extensive staged combat/add waves form a full raid event. <br>[drachnidhivec/encounters/Sendaii.lua:58](<F:/EQ1/Bastion_Dev/quests/drachnidhivec/encounters/Sendaii.lua:58>) · [drachnidhivec/encounters/Sendaii.lua:111](<F:/EQ1/Bastion_Dev/quests/drachnidhivec/encounters/Sendaii.lua:111>) · [drachnidhivec/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/drachnidhivec/script_init.lua:1>) | Pending |

### dragonscalea

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E083 | Living Legacy: Marathon | raid | Round-based raid with scaling add budgets, immunities and timed attacks. Shares encounter implementation with Sprint; central LivingLegacy profiles distinguish the modes.<br>[dragonscalea/encounters/living_legacy_ira.lua:1](<F:/EQ1/Bastion_Dev/quests/dragonscalea/encounters/living_legacy_ira.lua:1>) · [dragonscalea/script_init.lua:6](<F:/EQ1/Bastion_Dev/quests/dragonscalea/script_init.lua:6>) | Pending |
| E084 | Living Legacy: Sprint | raid | Timed variant of the Living Legacy round-based raid. Shares encounter implementation with Marathon; central LivingLegacy profiles distinguish the modes.<br>[dragonscalea/encounters/living_legacy_ira.lua:1](<F:/EQ1/Bastion_Dev/quests/dragonscalea/encounters/living_legacy_ira.lua:1>) · [dragonscalea/script_init.lua:6](<F:/EQ1/Bastion_Dev/quests/dragonscalea/script_init.lua:6>) | Pending |

### dranik

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E085 | Battlemaster Rhorious | raid | Controller spawns linked named lieutenants, groundpounders and the battlemaster. <br>[dranik/encounters/battlemaster.lua:27](<F:/EQ1/Bastion_Dev/quests/dranik/encounters/battlemaster.lua:27>) · [dranik/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/dranik/script_init.lua:1>) | Pending |
| E086 | Bloated Girplan | group | Timed Rain of Bile, Fetid Breath and Disconcerting Presence rotation. <br>[dranik/#Bloated_Girplan.lua:1](<F:/EQ1/Bastion_Dev/quests/dranik/%23Bloated_Girplan.lua:1>) · [dranik/#Bloated_Girplan.lua:13](<F:/EQ1/Bastion_Dev/quests/dranik/%23Bloated_Girplan.lua:13>) | Pending |
| E087 | Discord Fluctuation trio | event | Necromancer epic trigger summons Rargari, Gornogg and Arlagai together. <br>[dranik/#Discord_Fluctuation.lua:11](<F:/EQ1/Bastion_Dev/quests/dranik/%23Discord_Fluctuation.lua:11>) | Pending |
| E088 | Discordling Dark Animist | event | Beastlord1.5 boss spawns five beast adds and uses timed Discordant Feedback/Corporeal Torment. <br>[dranik/#Discordling_Dark_Animist.lua:3](<F:/EQ1/Bastion_Dev/quests/dranik/%23Discordling_Dark_Animist.lua:3>) · [dranik/#Discordling_Dark_Animist.lua:11](<F:/EQ1/Bastion_Dev/quests/dranik/%23Discordling_Dark_Animist.lua:11>) | Pending |
| E089 | Kyv Sharpshooters: Jaeth, Mihl and Nass | event | Three linked sharpshooters share spawn/death coordination and switch combat behavior below45 percent health. <br>[dranik/kyv_controller.lua:5](<F:/EQ1/Bastion_Dev/quests/dranik/kyv_controller.lua:5>) · [dranik/#Kyv_Sharpshooter_Jaeth.lua:10](<F:/EQ1/Bastion_Dev/quests/dranik/%23Kyv_Sharpshooter_Jaeth.lua:10>) · [dranik/#Kyv_Sharpshooter_Mihl.lua:9](<F:/EQ1/Bastion_Dev/quests/dranik/%23Kyv_Sharpshooter_Mihl.lua:9>) · [dranik/#Kyv_Sharpshooter_Nass.lua:9](<F:/EQ1/Bastion_Dev/quests/dranik/%23Kyv_Sharpshooter_Nass.lua:9>) | Pending |
| E090 | Lhranc and his minions | event | Shadow knight2.0 trigger progresses through minions into Lhranc. <br>[dranik/#Filligno_the_Slayer.lua:68](<F:/EQ1/Bastion_Dev/quests/dranik/%23Filligno_the_Slayer.lua:68>) · [dranik/#Lhranc.lua:1](<F:/EQ1/Bastion_Dev/quests/dranik/%23Lhranc.lua:1>) | Pending |
| E091 | Lirah the Bridgekeeper | raid | Seasonal encounter links Dragorn Protector spawns, respawns and combat behavior. <br>[dranik/Lirah_the_Bridgekeeper.lua:24](<F:/EQ1/Bastion_Dev/quests/dranik/Lirah_the_Bridgekeeper.lua:24>) · [dranik/Lirah_the_Bridgekeeper.lua:100](<F:/EQ1/Bastion_Dev/quests/dranik/Lirah_the_Bridgekeeper.lua:100>) | Pending |
| E092 | Murkglider Breeder | event | Shadow knight1.5 encounter repeatedly spawns murkgliders with lifecycle signals. <br>[dranik/#Murkglider_Breeder.lua:5](<F:/EQ1/Bastion_Dev/quests/dranik/%23Murkglider_Breeder.lua:5>) · [dranik/#Murkglider_Breeder.lua:15](<F:/EQ1/Bastion_Dev/quests/dranik/%23Murkglider_Breeder.lua:15>) | Pending |
| E093 | Sverins / Uisima rescue | event | Ranger1.5 linked encounter combines timed attacks, spirit adds and Uisima surrender. <br>[dranik/encounters/ranger_1_5.lua:10](<F:/EQ1/Bastion_Dev/quests/dranik/encounters/ranger_1_5.lua:10>) · [dranik/encounters/ranger_1_5.lua:104](<F:/EQ1/Bastion_Dev/quests/dranik/encounters/ranger_1_5.lua:104>) · [dranik/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/dranik/script_init.lua:3>) | Pending |
| E094 | Tiorpat Tornwing | event | Crowd factions, timed actions and related NPC signals control the event. <br>[dranik/encounters/tiorpat.lua:15](<F:/EQ1/Bastion_Dev/quests/dranik/encounters/tiorpat.lua:15>) · [dranik/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/dranik/script_init.lua:2>) | Pending |
| E095 | Wren Simsy | event | Rogue2.0 event creates assassins and checks participant conditions during combat. <br>[dranik/#Wren_Simsy.lua:8](<F:/EQ1/Bastion_Dev/quests/dranik/%23Wren_Simsy.lua:8>) · [dranik/#Wren_Simsy.lua:32](<F:/EQ1/Bastion_Dev/quests/dranik/%23Wren_Simsy.lua:32>) | Pending |
| E096 | Zun'Muram Volklana | event | Health threshold behavior and recurring terror checks. <br>[dranik/336141.lua:17](<F:/EQ1/Bastion_Dev/quests/dranik/336141.lua:17>) · [dranik/336141.lua:30](<F:/EQ1/Bastion_Dev/quests/dranik/336141.lua:30>) | Pending |

### draniksscar

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E097 | Girplan Spiritleech | event | Beastlord1.5 turn-in starts a boss and eight spiritminions. <br>[draniksscar/#Yerika_Sisslak.lua:14](<F:/EQ1/Bastion_Dev/quests/draniksscar/%23Yerika_Sisslak.lua:14>) · [draniksscar/#Yerika_Sisslak.lua:29](<F:/EQ1/Bastion_Dev/quests/draniksscar/%23Yerika_Sisslak.lua:29>) | Pending |

### dreadlands

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E098 | Gorenaire | raid | Combat roar timer and a low-health seasonal phase. <br>[dreadlands/Gorenaire.lua:9](<F:/EQ1/Bastion_Dev/quests/dreadlands/Gorenaire.lua:9>) · [dreadlands/Gorenaire.lua:37](<F:/EQ1/Bastion_Dev/quests/dreadlands/Gorenaire.lua:37>) | Pending |

### dreadspire

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E099 | Redfang | raid | Initial screecher-bat kills spawn Redfang and drive subsequent bat/position mechanics. <br>[dreadspire/encounters/Redfang.lua:37](<F:/EQ1/Bastion_Dev/quests/dreadspire/encounters/Redfang.lua:37>) · [dreadspire/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/dreadspire/script_init.lua:1>) | Pending |
| E100 | Zi-Thuuli of the Granite Claw | raid | Multiple health gates and encounter transitions culminate in a chest. <br>[dreadspire/encounters/thuuli.lua:12](<F:/EQ1/Bastion_Dev/quests/dreadspire/encounters/thuuli.lua:12>) · [dreadspire/encounters/thuuli.lua:24](<F:/EQ1/Bastion_Dev/quests/dreadspire/encounters/thuuli.lua:24>) · [dreadspire/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/dreadspire/script_init.lua:2>) | Pending |

### dulak

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E101 | Quigli | event | Monk1.5 challenge spawns four support enemies shortly after activation. <br>[dulak/Quigli.lua:3](<F:/EQ1/Bastion_Dev/quests/dulak/Quigli.lua:3>) · [dulak/Quigli.lua:38](<F:/EQ1/Bastion_Dev/quests/dulak/Quigli.lua:38>) | Pending |

### eastkarana

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E102 | Althele's druid gathering defense | event | Timed gathering is attacked by a Dark Elf Corruptor and Reavers; protect the druids. <br>[eastkarana/Althele.lua:131](<F:/EQ1/Bastion_Dev/quests/eastkarana/Althele.lua:131>) · [eastkarana/Althele.lua:145](<F:/EQ1/Bastion_Dev/quests/eastkarana/Althele.lua:145>) | Pending |

### eastkorlach

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E103 | General Veronhar | event | General and commanders have scripted positioning, healing/leash behavior and coordinated setup. <br>[eastkorlach/encounters/General_Veronhar.lua:5](<F:/EQ1/Bastion_Dev/quests/eastkorlach/encounters/General_Veronhar.lua:5>) · [eastkorlach/encounters/General_Veronhar.lua:19](<F:/EQ1/Bastion_Dev/quests/eastkorlach/encounters/General_Veronhar.lua:19>) · [eastkorlach/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/eastkorlach/script_init.lua:1>) | Pending |

### eastkorlacha

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E104 | Bloodeye | event | 75-percent health gate summons commanders and encounter enforces leash behavior. <br>[eastkorlacha/encounters/Bloodeye.lua:17](<F:/EQ1/Bastion_Dev/quests/eastkorlacha/encounters/Bloodeye.lua:17>) · [eastkorlacha/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/eastkorlacha/script_init.lua:1>) | Pending |

### eastwastes

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E105 | Boridain Glacierbane escort (Coldain ring2) | event | Escort path culminates in a scripted Rabid Tundra Kodiak spawn and quest completion. <br>[eastwastes/Boridain_Glacierbane.lua:28](<F:/EQ1/Bastion_Dev/quests/eastwastes/Boridain_Glacierbane.lua:28>) | Pending |
| E106 | Corbin Blackwell rescue (Coldain ring7) | event | Rescue escort has two ambush groups; warden combat summons guards. <br>[eastwastes/Corbin_Blackwell.lua:3](<F:/EQ1/Bastion_Dev/quests/eastwastes/Corbin_Blackwell.lua:3>) · [eastwastes/Warden_Bruke.lua:10](<F:/EQ1/Bastion_Dev/quests/eastwastes/Warden_Bruke.lua:10>) | Pending |
| E107 | Icefang / Poxbreath pursuit (Coldain ring6) | event | Wolf escort leads to scripted orc/Poxbreath ambush and cleanup. <br>[eastwastes/Icefang.lua:12](<F:/EQ1/Bastion_Dev/quests/eastwastes/Icefang.lua:12>) · [eastwastes/Icefang.lua:54](<F:/EQ1/Bastion_Dev/quests/eastwastes/Icefang.lua:54>) | Pending |
| E108 | Peffin Ambersnow / Berradin confrontation | event | Scripted betrayal/confrontation summons elite guards and changes event states. Associated with later Coldain ring progression; do not infer a version from filenames.<br>[eastwastes/Peffin_Ambersnow.lua:1](<F:/EQ1/Bastion_Dev/quests/eastwastes/Peffin_Ambersnow.lua:1>) · [eastwastes/#Peffin_Ambersnow.lua:1](<F:/EQ1/Bastion_Dev/quests/eastwastes/%23Peffin_Ambersnow.lua:1>) · [eastwastes/Captain_Berradin.lua:1](<F:/EQ1/Bastion_Dev/quests/eastwastes/Captain_Berradin.lua:1>) | Pending |
| E109 | Ry'Gorr assault / Chief Ry'Gorr (Coldain ring8) | event | Turn-in begins a coordinated Coldain assault with a large scripted enemy force. <br>[eastwastes/Gloradin_Coldheart.lua:2](<F:/EQ1/Bastion_Dev/quests/eastwastes/Gloradin_Coldheart.lua:2>) · [eastwastes/Gloradin_Coldheart.lua:18](<F:/EQ1/Bastion_Dev/quests/eastwastes/Gloradin_Coldheart.lua:18>) · [eastwastes/Garadain_Glacierbane.lua:1](<F:/EQ1/Bastion_Dev/quests/eastwastes/Garadain_Glacierbane.lua:1>) | Pending |
| E110 | Scarbrow Ga'Hruk assault (Coldain ring5) | event | Lookout trigger starts Scarbrow and Ry'Gorr invader waves. <br>[eastwastes/a_coldain_lookout.lua:8](<F:/EQ1/Bastion_Dev/quests/eastwastes/a_coldain_lookout.lua:8>) · [eastwastes/a_coldain_lookout.lua:29](<F:/EQ1/Bastion_Dev/quests/eastwastes/a_coldain_lookout.lua:29>) | Pending |
| E111 | Tain Hammerfrost defense (Coldain ring4) | event | Dialogue starts a five-enemy assault on Tain. <br>[eastwastes/Tain_Hammerfrost.lua:8](<F:/EQ1/Bastion_Dev/quests/eastwastes/Tain_Hammerfrost.lua:8>) | Pending |

### emeraldjungle

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E112 | Severilous | raid | Seasonal toxic attacks and low-health phase modify dragon combat. <br>[emeraldjungle/Severilous.lua:12](<F:/EQ1/Bastion_Dev/quests/emeraldjungle/Severilous.lua:12>) · [emeraldjungle/Severilous.lua:43](<F:/EQ1/Bastion_Dev/quests/emeraldjungle/Severilous.lua:43>) | Pending |

### fearplane

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E113 | Cazic Thule | raid | Instance/seasonal branches implement health phases, spawned adds and fear behavior. Distinct normal and instance branches must be preserved when authoring.<br>[fearplane/Cazic_Thule.lua:118](<F:/EQ1/Bastion_Dev/quests/fearplane/Cazic_Thule.lua:118>) · [fearplane/Cazic_Thule.lua:1](<F:/EQ1/Bastion_Dev/quests/fearplane/Cazic_Thule.lua:1>) | Pending |
| E114 | Dracoliche | raid | Instance encounter has a false-death/reanimation sequence and adds. Special false-death branch is gated to instance version255 in event_spawn.<br>[fearplane/a_dracoliche.lua:28](<F:/EQ1/Bastion_Dev/quests/fearplane/a_dracoliche.lua:28>) · [fearplane/a_dracoliche.lua:59](<F:/EQ1/Bastion_Dev/quests/fearplane/a_dracoliche.lua:59>) | Pending |
| E115 | Fear golems: Dread, Fright and Terror | raid | Dread and Fright have scripted death-touch cycles; the three golems can spawn an iksar broodling when killed. A shared golem entry must make clear that Terror lacks the scripted death-touch cycle.<br>[fearplane/Dread.lua:1](<F:/EQ1/Bastion_Dev/quests/fearplane/Dread.lua:1>) · [fearplane/Fright.lua:1](<F:/EQ1/Bastion_Dev/quests/fearplane/Fright.lua:1>) · [fearplane/Terror.lua:8](<F:/EQ1/Bastion_Dev/quests/fearplane/Terror.lua:8>) | Pending |
| E116 | Fear-Touched Dracolich ambush | event | Zone kills can trigger a dracolich that attacks the credited player. Enabled by Rotten_Hand content flag in script_init.lua; separate from ordinary Dracoliche.<br>[fearplane/encounters/fear_touched_dracolich.lua:5](<F:/EQ1/Bastion_Dev/quests/fearplane/encounters/fear_touched_dracolich.lua:5>) · [fearplane/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/fearplane/script_init.lua:1>) | Pending |
| E117 | Ireblind Imp | event | Berserker epic health stages summon waves of Essence of Rage adds. <br>[fearplane/Ireblind_Imp.lua:7](<F:/EQ1/Bastion_Dev/quests/fearplane/Ireblind_Imp.lua:7>) | Pending |

### feerrott

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E118 | Swamp Terror | event | Druid epic proximity trigger runs a dialogue sequence and summons Swamp Terror. <br>[feerrott/#druid_epic_trap.lua:32](<F:/EQ1/Bastion_Dev/quests/feerrott/%23druid_epic_trap.lua:32>) | Pending |

### ferubi

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E119 | Hexxt Pzik Shziaak | group | Named kyv switches combat at a health threshold and runs a ranged timer. <br>[ferubi/Hexxt_Pzik_Shziaak.lua:6](<F:/EQ1/Bastion_Dev/quests/ferubi/Hexxt_Pzik_Shziaak.lua:6>) · [ferubi/Hexxt_Pzik_Shziaak.lua:12](<F:/EQ1/Bastion_Dev/quests/ferubi/Hexxt_Pzik_Shziaak.lua:12>) | Pending |
| E120 | Packmaster Skoiat Pizak | event | Linked Rav guards assist the packmaster and use distance checks. <br>[ferubi/Packmaster_Skoiat_Pizak.lua:4](<F:/EQ1/Bastion_Dev/quests/ferubi/Packmaster_Skoiat_Pizak.lua:4>) · [ferubi/#Rav_Pizak.lua:1](<F:/EQ1/Bastion_Dev/quests/ferubi/%23Rav_Pizak.lua:1>) | Pending |
| E121 | Pxet elite ambushes | event | Kill-count/signaled controllers summon elite Blademaster, Spearmaster, Hammermaster and Brawler. <br>[ferubi/Event_Spawner.lua:13](<F:/EQ1/Bastion_Dev/quests/ferubi/Event_Spawner.lua:13>) | Pending |
| E122 | Smith Rondo / Weapon Master cycle | raid | Trades/signals, Weapon Master apprentices and seasonal combat phases comprise the linked encounter. Weapon Master prerequisite is retained as a stage instead of duplicate candidate.<br>[ferubi/#Smith_Rondo.lua:62](<F:/EQ1/Bastion_Dev/quests/ferubi/%23Smith_Rondo.lua:62>) · [ferubi/#Smith_Rondo.lua:110](<F:/EQ1/Bastion_Dev/quests/ferubi/%23Smith_Rondo.lua:110>) · [ferubi/Weapon_Master_Vtiink_Vzaan.lua:4](<F:/EQ1/Bastion_Dev/quests/ferubi/Weapon_Master_Vtiink_Vzaan.lua:4>) | Pending |
| E123 | Zun-Muram Votal | event | Encounter has linked Pixtt adds, aggro coordination and out-of-bounds checks. <br>[ferubi/Zun-Muram_Votal.lua:1](<F:/EQ1/Bastion_Dev/quests/ferubi/Zun-Muram_Votal.lua:1>) · [ferubi/Pixtt_Votal.lua:1](<F:/EQ1/Bastion_Dev/quests/ferubi/Pixtt_Votal.lua:1>) | Pending |

### fieldofbone

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E124 | Berserker's Image | event | Three health stages alter the epic trial and death spawns the noncombat follow-up. <br>[fieldofbone/#Berserker-s_Image.lua:7](<F:/EQ1/Bastion_Dev/quests/fieldofbone/%23Berserker-s_Image.lua:7>) | Pending |

### freportw

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E125 | Sir Lucan D'Lere | event | Killing the living Lucan triggers a second NPC form at the death location. Second form NPC9147 requires database identity/combat verification before detailed authoring.<br>[freportw/Sir_Lucan_D-Lere.lua:9](<F:/EQ1/Bastion_Dev/quests/freportw/Sir_Lucan_D-Lere.lua:9>) | Pending |

### frontiermtns

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E126 | Goblin King Dronan event | event | Druid1.5 controller manages seekers, the king and linked named goblins. <br>[frontiermtns/encounters/druid_1_5.lua:10](<F:/EQ1/Bastion_Dev/quests/frontiermtns/encounters/druid_1_5.lua:10>) · [frontiermtns/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/frontiermtns/script_init.lua:1>) | Pending |

### fungusgrove

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E127 | Calling Beasties (Murkin, Groo and Torgal) | event | Three cavern callers run paid timed waves with pauses between sets and survival rewards. Consolidated variants of the same wave event.<br>[fungusgrove/#Caller_Murkin.lua:17](<F:/EQ1/Bastion_Dev/quests/fungusgrove/%23Caller_Murkin.lua:17>) · [fungusgrove/#Caller_Murkin.lua:69](<F:/EQ1/Bastion_Dev/quests/fungusgrove/%23Caller_Murkin.lua:69>) · [fungusgrove/#Caller_Groo.lua:1](<F:/EQ1/Bastion_Dev/quests/fungusgrove/%23Caller_Groo.lua:1>) · [fungusgrove/#Caller_Torgal.lua:1](<F:/EQ1/Bastion_Dev/quests/fungusgrove/%23Caller_Torgal.lua:1>) | Pending |
| E128 | Draz Nurakk | event | Beastlord epic challenge calls its pet into combat and manages timed reset/despawn. <br>[fungusgrove/Draz_Nurakk.lua:17](<F:/EQ1/Bastion_Dev/quests/fungusgrove/Draz_Nurakk.lua:17>) | Pending |

### gfaydark

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E129 | Seana and Stefan Marsinger | event | Turn-in activates both linked bards; Seana gives a stone then teleports the current target, while the pair repeatedly assist one another. <br>[gfaydark/Seana_Marsinger.lua:79](<F:/EQ1/Bastion_Dev/quests/gfaydark/Seana_Marsinger.lua:79>) · [gfaydark/Stefan_Marsinger.lua:73](<F:/EQ1/Bastion_Dev/quests/gfaydark/Stefan_Marsinger.lua:73>) | Pending |

### greatdivide

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E130 | High Priest Maltan assault | event | Cleric pre1.5 encounter spawns Maltan and five plasmatic priests/priestesses. <br>[greatdivide/Gavon_Morant.lua:5](<F:/EQ1/Bastion_Dev/quests/greatdivide/Gavon_Morant.lua:5>) | Pending |
| E131 | Tenth Coldain Ring War / Narandi | raid | Large multiwave Coldain/giant war with allied commanders, escape/failure and Narandi finale. Source contains TODO testing comments; runtime acceptance not established.<br>[greatdivide/encounters/RingTen.lua:34](<F:/EQ1/Bastion_Dev/quests/greatdivide/encounters/RingTen.lua:34>) · [greatdivide/Sentry_Badain.lua:34](<F:/EQ1/Bastion_Dev/quests/greatdivide/Sentry_Badain.lua:34>) | Pending |

### grimling

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E132 | Captain Necin's raid | event | Coordinated assault covers two camps with independent camp checks. <br>[grimling/encounters/Necins_Raid.lua:36](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Necins_Raid.lua:36>) · [grimling/Captain_Necin.lua:67](<F:/EQ1/Bastion_Dev/quests/grimling/Captain_Necin.lua:67>) | Pending |
| E133 | Final Grimling War / General Staginar's raid | raid | Final war runs multiple camps, high commanders and win/fail state. <br>[grimling/encounters/Final_War.lua:48](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Final_War.lua:48>) · [grimling/Veteran_Cullin.lua:229](<F:/EQ1/Bastion_Dev/quests/grimling/Veteran_Cullin.lua:229>) | Pending |
| E134 | Scout Danarin's raid | event | Second camp assault has scripted waves, progression and failure checks. <br>[grimling/encounters/Danarins_Raid.lua:26](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Danarins_Raid.lua:26>) · [grimling/Scout_Danarin.lua:68](<F:/EQ1/Bastion_Dev/quests/grimling/Scout_Danarin.lua:68>) | Pending |
| E135 | Scout Derrin's raid | event | Third scout camp assault has waves, camp checks and completion. <br>[grimling/encounters/Derrins_Raid.lua:24](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Derrins_Raid.lua:24>) · [grimling/Scout_Derrin.lua:69](<F:/EQ1/Bastion_Dev/quests/grimling/Scout_Derrin.lua:69>) | Pending |
| E136 | Scout Husman's raid | event | Camp assault runs spawn waves, camp checks and win/fail cleanup. <br>[grimling/encounters/Husmans_Raid.lua:23](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Husmans_Raid.lua:23>) · [grimling/Scout_Husman.lua:94](<F:/EQ1/Bastion_Dev/quests/grimling/Scout_Husman.lua:94>) | Pending |
| E137 | Veteran Vadrel's raid | event | North camp assault coordinates two camps, waves and failure checks. <br>[grimling/encounters/Vadrels_Raid.lua:36](<F:/EQ1/Bastion_Dev/quests/grimling/encounters/Vadrels_Raid.lua:36>) · [grimling/Veteran_Vadrel.lua:61](<F:/EQ1/Bastion_Dev/quests/grimling/Veteran_Vadrel.lua:61>) | Pending |

### growthplane

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E138 | Tunare | raid | Tree form transitions to ground encounter; aggro calls specific zone populations to assist. Includes fabled helper variant; verify selected NPC identity when authoring.<br>[growthplane/#_Tunare.lua:3](<F:/EQ1/Bastion_Dev/quests/growthplane/%23_Tunare.lua:3>) · [growthplane/#Tunare.lua:10](<F:/EQ1/Bastion_Dev/quests/growthplane/%23Tunare.lua:10>) · [growthplane/#The_Fabled_Tunare.lua:3](<F:/EQ1/Bastion_Dev/quests/growthplane/%23The_Fabled_Tunare.lua:3>) | Pending |

### guke

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E139 | Deepest Guk raid: Executioner Gimdk / First Witness | raid | Protect scouts through waves, stop executioner sacrifices and destroy focus defenses to release First Witness. Verified raid version2; present as a linked raid sequence.<br>[guke/encounters/gukeraid.lua:37](<F:/EQ1/Bastion_Dev/quests/guke/encounters/gukeraid.lua:37>) · [guke/encounters/gukeraid.lua:173](<F:/EQ1/Bastion_Dev/quests/guke/encounters/gukeraid.lua:173>) · [guke/encounters/gukeraid.lua:232](<F:/EQ1/Bastion_Dev/quests/guke/encounters/gukeraid.lua:232>) · [guke/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/guke/zone_status.lua:8>) | Pending |

### gukg

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E140 | Gragna the Cursed | raid | Timed combat behavior and raid progression control the Gragna stage. Verified raid version2.<br>[gukg/encounters/gukgraid.lua:353](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:353>) · [gukg/encounters/gukgraid.lua:357](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:357>) · [gukg/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/gukg/zone_status.lua:8>) | Pending |
| E141 | Leklos the Bonekeeper | raid | Opening boss has health-triggered adds and advances the raid to Cavern Creeper. Verified raid version2.<br>[gukg/encounters/gukgraid.lua:82](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:82>) · [gukg/encounters/gukgraid.lua:90](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:90>) · [gukg/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/gukg/zone_status.lua:8>) | Pending |
| E142 | The Cursed Keeper | raid | Health gates and combat timers implement the raid finale. Verified raid version2.<br>[gukg/encounters/gukgraid.lua:401](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:401>) · [gukg/encounters/gukgraid.lua:409](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:409>) · [gukg/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/gukg/zone_status.lua:8>) | Pending |
| E143 | The Cursed Spore | raid | HP stages remove slow immunity and reduce resistances, with reset behavior. Verified raid version2.<br>[gukg/encounters/gukgraid.lua:253](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:253>) · [gukg/encounters/gukgraid.lua:274](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:274>) · [gukg/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/gukg/zone_status.lua:8>) | Pending |

### gunthak

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E144 | Dimaal the Spiritmaster's waves | event | Three selectable difficulties run four waves ending in a named spirit. <br>[gunthak/Dimaal_the_Spiritmaster.lua:67](<F:/EQ1/Bastion_Dev/quests/gunthak/Dimaal_the_Spiritmaster.lua:67>) · [gunthak/Dimaal_the_Spiritmaster.lua:97](<F:/EQ1/Bastion_Dev/quests/gunthak/Dimaal_the_Spiritmaster.lua:97>) | Pending |
| E145 | Lairyn Debeian defense / Krill the Backbleeder | event | Rogue epic protection event runs five hostile waves and a named finale. <br>[gunthak/Lairyn_Debeian.lua:54](<F:/EQ1/Bastion_Dev/quests/gunthak/Lairyn_Debeian.lua:54>) · [gunthak/Lairyn_Debeian.lua:81](<F:/EQ1/Bastion_Dev/quests/gunthak/Lairyn_Debeian.lua:81>) | Pending |

### harbingers

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E146 | Attendant of Light | event | Sycophant kills and controller signals summon attendant plus harasser support. <br>[harbingers/Attendant_Spawner.lua:3](<F:/EQ1/Bastion_Dev/quests/harbingers/Attendant_Spawner.lua:3>) · [harbingers/a_dragorn_sycophant.lua:1](<F:/EQ1/Bastion_Dev/quests/harbingers/a_dragorn_sycophant.lua:1>) | Pending |
| E147 | Azibelle Spavin and Glenfire Telzir | event | Druid epic proximity trigger creates both encounter targets. <br>[harbingers/#druid_epic_trap.lua:5](<F:/EQ1/Bastion_Dev/quests/harbingers/%23druid_epic_trap.lua:5>) | Pending |
| E148 | Windrush | event | Monk epic health phases and timed Mana Spectrum/Stunning Strike attacks. <br>[harbingers/Windrush.lua:29](<F:/EQ1/Bastion_Dev/quests/harbingers/Windrush.lua:29>) · [harbingers/Windrush.lua:36](<F:/EQ1/Bastion_Dev/quests/harbingers/Windrush.lua:36>) · [harbingers/Windrush.lua:47](<F:/EQ1/Bastion_Dev/quests/harbingers/Windrush.lua:47>) | Pending |

### hateplaneb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E149 | Innoruuk | raid | Controller and real/fake forms coordinate adds and staged boss combat. <br>[hateplaneb/encounters/inny.lua:42](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/inny.lua:42>) · [hateplaneb/encounters/inny.lua:53](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/inny.lua:53>) · [hateplaneb/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/hateplaneb/script_init.lua:2>) | Pending |
| E150 | Lanys T'Vyl / Teir'Dal guardian event | event | Rogue epic kill trigger leads to dialogue activation and timed boss spells. <br>[hateplaneb/encounters/rogue_1_5.lua:6](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/rogue_1_5.lua:6>) · [hateplaneb/encounters/rogue_1_5.lua:58](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/rogue_1_5.lua:58>) · [hateplaneb/player.lua:37](<F:/EQ1/Bastion_Dev/quests/hateplaneb/player.lua:37>) | Pending |
| E151 | Maestro of Rancor | raid | Accompanists, banshees and health/timer-driven mechanics support the maestro. <br>[hateplaneb/encounters/maestro.lua:33](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/maestro.lua:33>) · [hateplaneb/encounters/maestro.lua:43](<F:/EQ1/Bastion_Dev/quests/hateplaneb/encounters/maestro.lua:43>) · [hateplaneb/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/hateplaneb/script_init.lua:1>) | Pending |

### hatesfury

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E152 | Broken Skull Armsmaster | event | Berserker pre1.5 encounter has a low-health weakened state and task credit. <br>[hatesfury/Broken_Skull_Armsmaster.lua:10](<F:/EQ1/Bastion_Dev/quests/hatesfury/Broken_Skull_Armsmaster.lua:10>) · [hatesfury/Broken_Skull_Armsmaster.lua:18](<F:/EQ1/Bastion_Dev/quests/hatesfury/Broken_Skull_Armsmaster.lua:18>) | Pending |
| E153 | Captain Krasnok | raid | Turn-in spawns captain and fists behind event doors; supporting spell scripts drive hazards. <br>[hatesfury/Attendant_Mi-Ta.lua:22](<F:/EQ1/Bastion_Dev/quests/hatesfury/Attendant_Mi-Ta.lua:22>) · [hatesfury/Attendant_Mi-Ta.lua:38](<F:/EQ1/Bastion_Dev/quests/hatesfury/Attendant_Mi-Ta.lua:38>) | Pending |

### highkeep

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E154 | Xentil Herkanon assassination | event | Quest trigger summons Xentil with bodyguards Lartin and Grex. <br>[highkeep/Fenn_Kaedrick.lua:10](<F:/EQ1/Bastion_Dev/quests/highkeep/Fenn_Kaedrick.lua:10>) | Pending |

### hohonora

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E155 | Alekson Garn's trial | event | Three-room rescue/defense trial spawns attackers and protects maidens. <br>[hohonora/encounters/Alekson_Trial.lua:21](<F:/EQ1/Bastion_Dev/quests/hohonora/encounters/Alekson_Trial.lua:21>) · [hohonora/Alekson_Garn.lua:18](<F:/EQ1/Bastion_Dev/quests/hohonora/Alekson_Garn.lua:18>) | Pending |
| E156 | Rhaliq Trell's trial | event | Villager trial has timed attacks, named transformations and failure/reset state. <br>[hohonora/encounters/Rhaliq_Trial.lua:34](<F:/EQ1/Bastion_Dev/quests/hohonora/encounters/Rhaliq_Trial.lua:34>) · [hohonora/Rhaliq_Trell.lua:18](<F:/EQ1/Bastion_Dev/quests/hohonora/Rhaliq_Trell.lua:18>) | Pending |
| E157 | Rydda'Dar / Trydan Faye's trial | raid | Defeating the custodian summons the raid boss; boss death completes trial progression. <br>[hohonora/#_A_Custodian_of_Marr.lua:15](<F:/EQ1/Bastion_Dev/quests/hohonora/%23_A_Custodian_of_Marr.lua:15>) · [hohonora/#Rydda-Dar.lua:8](<F:/EQ1/Bastion_Dev/quests/hohonora/%23Rydda-Dar.lua:8>) | Pending |

### hohonorb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E158 | Lord Mithaniel Marr | raid | Signal-gated attackability with timed deactivation and expedition boss lockouts. <br>[hohonorb/Lord_Mithaniel_Marr.lua:11](<F:/EQ1/Bastion_Dev/quests/hohonorb/Lord_Mithaniel_Marr.lua:11>) · [hohonorb/zone_status.lua:42](<F:/EQ1/Bastion_Dev/quests/hohonorb/zone_status.lua:42>) | Pending |

### hole

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E159 | Master Yael | raid | Named raid boss has a dedicated scripted death-touch cycle. <br>[hole/Master_Yael.lua:1](<F:/EQ1/Bastion_Dev/quests/hole/Master_Yael.lua:1>) | Pending |

### hollowshade

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E160 | Hollowshade Moor war | event | Owlbear, sonic wolf and grimling factions attack/take over camps and trigger a zone-wide victory. <br>[hollowshade/War_Trigger.lua:47](<F:/EQ1/Bastion_Dev/quests/hollowshade/War_Trigger.lua:47>) · [hollowshade/War_Trigger.lua:99](<F:/EQ1/Bastion_Dev/quests/hollowshade/War_Trigger.lua:99>) · [hollowshade/War_Trigger.lua:159](<F:/EQ1/Bastion_Dev/quests/hollowshade/War_Trigger.lua:159>) | Pending |

### iceclad

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E161 | General Bragmur escort (Coldain shawl finale) | escort | Waypoint-driven escort has repeated wolf and giant ambushes, commanders and a final ritual. Escort uses numeric NPC script 110227; database grid must be checked for detailed guide.<br>[iceclad/110227.lua:25](<F:/EQ1/Bastion_Dev/quests/iceclad/110227.lua:25>) · [iceclad/General_Bragmur.lua:7](<F:/EQ1/Bastion_Dev/quests/iceclad/General_Bragmur.lua:7>) | Pending |
| E162 | Noble Oldencamp and the necromancer delegation | event | Bard pre-epic spawns a necromancer delegation and frost giant escort with timed cleanup. Includes Amilia, Puella, Xeegarn and Locis as one event.<br>[iceclad/#Vas_Thorel.lua:27](<F:/EQ1/Bastion_Dev/quests/iceclad/%23Vas_Thorel.lua:27>) · [iceclad/#Noble_Oldencamp.lua:3](<F:/EQ1/Bastion_Dev/quests/iceclad/%23Noble_Oldencamp.lua:3>) | Pending |

### ikkinz

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E163 | Chambers of Destruction / Keeper of the Altar | raid | Altar progression, class-specific adherents, sentry HP stages and Keeper timers define the raid. Keeper and altar helpers are consolidated under the trial.<br>[ikkinz/#Altar_Sentry.lua:47](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Altar_Sentry.lua:47>) · [ikkinz/#Altar_Adherent.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Altar_Adherent.lua:1>) · [ikkinz/encounters/keeper.lua:18](<F:/EQ1/Bastion_Dev/quests/ikkinz/encounters/keeper.lua:18>) · [ikkinz/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/script_init.lua:1>) | Pending |
| E164 | Chambers of Glorification | raid | Visionaries change at HP stages; guardian stages split while supporting kills weaken their stats. <br>[ikkinz/#Visionary_of_Glory.lua:6](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Visionary_of_Glory.lua:6>) · [ikkinz/##Guardian_of_Glorification.lua:10](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23%23Guardian_of_Glorification.lua:10>) · [ikkinz/294474.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/294474.lua:1>) | Pending |
| E165 | Chambers of Righteousness | raid | Priest/custodian HP phases, defender/guardian progression and door gates form a raid sequence. Can be one guide with separate boss sections.<br>[ikkinz/Priest_of_Righteousness.lua:6](<F:/EQ1/Bastion_Dev/quests/ikkinz/Priest_of_Righteousness.lua:6>) · [ikkinz/Custodian_of_Righteousness.lua:7](<F:/EQ1/Bastion_Dev/quests/ikkinz/Custodian_of_Righteousness.lua:7>) · [ikkinz/#Trigger_Ikkinz_1.lua:31](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Trigger_Ikkinz_1.lua:31>) | Pending |
| E166 | Chambers of the Tri-Fates | group trial | Flesh hunters and a trigger coordinate kill success, failure and trial progression. <br>[ikkinz/#Trigger_Ikkinz_2.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Trigger_Ikkinz_2.lua:1>) · [ikkinz/#Flesh_Hunter.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Flesh_Hunter.lua:1>) · [ikkinz/Pixtt_Annuller.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/Pixtt_Annuller.lua:1>) | Pending |
| E167 | Chambers of Transcendence | raid | Acolyte HP stages and class-related final blows lead to Guardian and Vrex progression. <br>[ikkinz/##Transcendent_Acolyte.lua:16](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23%23Transcendent_Acolyte.lua:16>) · [ikkinz/294593.lua:32](<F:/EQ1/Bastion_Dev/quests/ikkinz/294593.lua:32>) · [ikkinz/#Vrex_Xalkak_Nixki.lua:12](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Vrex_Xalkak_Nixki.lua:12>) | Pending |
| E168 | Chambers of Twin Struggles | group trial | Malevolent priests and constrained servitors have linked progression and combat scripts. Duplicate NPC-name variants consolidated; numeric version not inferred.<br>[ikkinz/#Malevolent_Priest.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/%23Malevolent_Priest.lua:1>) · [ikkinz/294086.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/294086.lua:1>) · [ikkinz/player.lua:18](<F:/EQ1/Bastion_Dev/quests/ikkinz/player.lua:18>) | Pending |

### illsalin

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E169 | Ritesmaster Verok | raid boss | Locks room doors and spawns/activates eggs at HP thresholds with leash/reset behavior. <br>[illsalin/#Ritesmaster_Verok.lua:6](<F:/EQ1/Bastion_Dev/quests/illsalin/%23Ritesmaster_Verok.lua:6>) · [illsalin/a_spider_egg.lua:1](<F:/EQ1/Bastion_Dev/quests/illsalin/a_spider_egg.lua:1>) | Pending |

### illsalinb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E170 | The Council of Nine | raid | Avatar changes form/stats by HP stage and has combat timers. Version selected by instance_version constant; no literal version asserted.<br>[illsalinb/encounters/council_of_nine.lua:52](<F:/EQ1/Bastion_Dev/quests/illsalinb/encounters/council_of_nine.lua:52>) · [illsalinb/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/illsalinb/script_init.lua:2>) | Pending |

### illsalinc

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E171 | Deserting the Ranks | mission | Triggered second phase, Draygun movement/timers and captain death advance the mission. Normal/hard task identifiers declared; runtime version uses a named constant.<br>[illsalinc/encounters/Deserting_the_Ranks.lua:111](<F:/EQ1/Bastion_Dev/quests/illsalinc/encounters/Deserting_the_Ranks.lua:111>) · [illsalinc/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/illsalinc/script_init.lua:3>) | Pending |
| E172 | Emperor Draygun, the Lich King | raid boss | HP-driven forms, soul adds and cast-on-target handling implement a healing/damage phase. <br>[illsalinc/encounters/Emperor_Draygun.lua:85](<F:/EQ1/Bastion_Dev/quests/illsalinc/encounters/Emperor_Draygun.lua:85>) · [illsalinc/encounters/Emperor_Draygun.lua:110](<F:/EQ1/Bastion_Dev/quests/illsalinc/encounters/Emperor_Draygun.lua:110>) · [illsalinc/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/illsalinc/script_init.lua:5>) | Pending |

### inktuta

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E173 | Kelekdrix, Herald of Trushar | raid boss | Boss activation and add deaths coordinate with reset and timer behavior. Part of Inktuta; includes watchers/ushers.<br>[inktuta/encounters/kelekdrix.lua:37](<F:/EQ1/Bastion_Dev/quests/inktuta/encounters/kelekdrix.lua:37>) · [inktuta/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/inktuta/script_init.lua:1>) | Pending |
| E174 | Noqufiel: true and mirror images | raid boss | Coordinated images swap/reveal through HP events, combat timers and final death transition. <br>[inktuta/encounters/noqufiel.lua:100](<F:/EQ1/Bastion_Dev/quests/inktuta/encounters/noqufiel.lua:100>) · [inktuta/encounters/noqufiel.lua:132](<F:/EQ1/Bastion_Dev/quests/inktuta/encounters/noqufiel.lua:132>) · [inktuta/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/inktuta/script_init.lua:2>) | Pending |
| E175 | The Cursecallers | raid event | Noqufiel summons six cursecallers; a trigger spawns pursuit cursebearers and detects completion. Consolidates six callers and pursuit helpers.<br>[inktuta/#Noqufiel.lua:52](<F:/EQ1/Bastion_Dev/quests/inktuta/%23Noqufiel.lua:52>) · [inktuta/#curse_trigger.lua:39](<F:/EQ1/Bastion_Dev/quests/inktuta/%23curse_trigger.lua:39>) | Pending |
| E176 | The Exiles / Stonemite trial | raid event | Four exiles require coordinated phrases in a timed window with explicit success/failure. Part of Inktuta.<br>[inktuta/zone_status.lua:209](<F:/EQ1/Bastion_Dev/quests/inktuta/zone_status.lua:209>) · [inktuta/zone_status.lua:271](<F:/EQ1/Bastion_Dev/quests/inktuta/zone_status.lua:271>) | Pending |

### jaggedpine

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E177 | Dire kodiak / forest dragon transformation | epic encounter | At its health threshold the dire kodiak becomes a forest dragon; combat exit controls persistence. Bard 1.5.<br>[jaggedpine/a_dire_kodiak.lua:10](<F:/EQ1/Bastion_Dev/quests/jaggedpine/a_dire_kodiak.lua:10>) · [jaggedpine/#a_forest_dragon.lua:6](<F:/EQ1/Bastion_Dev/quests/jaggedpine/%23a_forest_dragon.lua:6>) | Pending |

### kael

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E178 | Fabled King Tormax | raid boss | Fabled controller registers Tormax combat, guard assistance, timers and death behavior. Do not assume this equals central seasonal registry version.<br>[kael/encounters/fabled_kael_encounter.lua:78](<F:/EQ1/Bastion_Dev/quests/kael/encounters/fabled_kael_encounter.lua:78>) · [kael/zone_status.lua:41](<F:/EQ1/Bastion_Dev/quests/kael/zone_status.lua:41>) | Pending |
| E179 | Fabled Statue, Idol and Avatar of War | raid sequence | Fabled three-boss sequence has death transitions, timers and expedition lockouts. <br>[kael/encounters/fabled_kael_encounter.lua:16](<F:/EQ1/Bastion_Dev/quests/kael/encounters/fabled_kael_encounter.lua:16>) · [kael/encounters/fabled_kael_encounter.lua:34](<F:/EQ1/Bastion_Dev/quests/kael/encounters/fabled_kael_encounter.lua:34>) · [kael/zone_status.lua:41](<F:/EQ1/Bastion_Dev/quests/kael/zone_status.lua:41>) | Pending |
| E180 | Kael armor / plate cycle | event | Proximity phrase starts Doldigun and successive giant waves. Named giant helpers consolidated.<br>[kael/Plate_Cycle_Trigger.lua:9](<F:/EQ1/Bastion_Dev/quests/kael/Plate_Cycle_Trigger.lua:9>) · [kael/Doldigun_Steinwielder.lua:5](<F:/EQ1/Bastion_Dev/quests/kael/Doldigun_Steinwielder.lua:5>) · [kael/Grondon_Zekkin.lua:5](<F:/EQ1/Bastion_Dev/quests/kael/Grondon_Zekkin.lua:5>) | Pending |
| E181 | King Tormax | raid boss | Combat timer recruits surviving named guards into the fight. Normal encounter, distinct from Fabled variant.<br>[kael/King_Tormax.lua:43](<F:/EQ1/Bastion_Dev/quests/kael/King_Tormax.lua:43>) · [kael/King_Tormax.lua:61](<F:/EQ1/Bastion_Dev/quests/kael/King_Tormax.lua:61>) | Pending |
| E182 | Statue, Idol and Avatar of War | raid sequence | Statue death spawns Idol, followed by Avatar with timed presence and cycle state. One three-stage raid sequence.<br>[kael/The_Statue_of_Rallos_Zek.lua:4](<F:/EQ1/Bastion_Dev/quests/kael/The_Statue_of_Rallos_Zek.lua:4>) · [kael/#The_Idol_of_Rallos_Zek.lua:1](<F:/EQ1/Bastion_Dev/quests/kael/%23The_Idol_of_Rallos_Zek.lua:1>) · [kael/The_Avatar_of_War.lua:3](<F:/EQ1/Bastion_Dev/quests/kael/The_Avatar_of_War.lua:3>) | Pending |

### katta

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E183 | Rakshasa skull ritual | quest event | Courier and ritual signals trigger an eight-spawn undead ambush. Optional short quest-event guide.<br>[katta/Roshawna_Rhorer.lua:51](<F:/EQ1/Bastion_Dev/quests/katta/Roshawna_Rhorer.lua:51>) | Pending |
| E184 | Vampyre Troubles: Autarkic Lord Sfarosh | quest event | Dialogue ritual and allies culminate in hostile Sfarosh and three shade adds with a time limit. Can include imprisoned shade as the earlier quest battle.<br>[katta/Autarkic_Lord_Sfarosh.lua:13](<F:/EQ1/Bastion_Dev/quests/katta/Autarkic_Lord_Sfarosh.lua:13>) · [katta/Autarkic_Lord_Sfarosh.lua:32](<F:/EQ1/Bastion_Dev/quests/katta/Autarkic_Lord_Sfarosh.lua:32>) · [katta/#Autarkic_Lord_Sfarosh.lua:2](<F:/EQ1/Bastion_Dev/quests/katta/%23Autarkic_Lord_Sfarosh.lua:2>) | Pending |

### kerraridge

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E185 | Tissa, Sovereign of Terror and the nine lives of Kerra | custom raid | Eight generals weaken Tissa when killed; full-power Tissa offers a bonus reward. Requires GM-Kerra content flag and nonzero instance. Includes Ahed split, Dou linked kittens, Siete physical resistance, Ath healer final blow, Tin, Ano, Piat, Exi. Generals can become separate entries.<br>[kerraridge/event_controller.lua:25](<F:/EQ1/Bastion_Dev/quests/kerraridge/event_controller.lua:25>) · [kerraridge/#Tissa,_Sovereign_of_Terror.lua:28](<F:/EQ1/Bastion_Dev/quests/kerraridge/%23Tissa%2C_Sovereign_of_Terror.lua:28>) | Pending |

### kithicor

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E186 | Blackened Treant and Blackened Dryad | epic event | Treant splits into limbs; clearing limbs summons a Dryad with timed Withering Glare. <br>[kithicor/#Blackened_Treant.lua:1](<F:/EQ1/Bastion_Dev/quests/kithicor/%23Blackened_Treant.lua:1>) · [kithicor/#Blackened_Tree_Limb.lua:1](<F:/EQ1/Bastion_Dev/quests/kithicor/%23Blackened_Tree_Limb.lua:1>) · [kithicor/#Blackened_Dryad.lua:9](<F:/EQ1/Bastion_Dev/quests/kithicor/%23Blackened_Dryad.lua:9>) | Pending |
| E187 | Cloaked figure / shadow thief | epic event | Hail starts malignant-shadow respawns; kill count reveals a shadow thief with an HP mechanic. Bard 1.5.<br>[kithicor/encounters/cloaked.lua:21](<F:/EQ1/Bastion_Dev/quests/kithicor/encounters/cloaked.lua:21>) · [kithicor/encounters/cloaked.lua:64](<F:/EQ1/Bastion_Dev/quests/kithicor/encounters/cloaked.lua:64>) | Pending |

### kodtaz

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E188 | Pixtt Grand Summoner ring | raid event | Priest deaths activate the summoner and golem stages, with combat/leash/timer handlers. <br>[kodtaz/encounters/summoners.lua:62](<F:/EQ1/Bastion_Dev/quests/kodtaz/encounters/summoners.lua:62>) · [kodtaz/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/kodtaz/script_init.lua:1>) | Pending |
| E189 | Temple of the Damned | raid event | Destroying remains triggers ambushers and Dire Summoner activation through kill signals. <br>[kodtaz/encounters/totd.lua:16](<F:/EQ1/Bastion_Dev/quests/kodtaz/encounters/totd.lua:16>) · [kodtaz/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/kodtaz/script_init.lua:2>) | Pending |

### lakeofillomen

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E190 | Vorash, Deep and Xenevorash | epic event | Triggered opponents and death signals coordinate the final monk-epic boss activation. <br>[lakeofillomen/Vorash.lua:18](<F:/EQ1/Bastion_Dev/quests/lakeofillomen/Vorash.lua:18>) · [lakeofillomen/monk_trigger.lua:1](<F:/EQ1/Bastion_Dev/quests/lakeofillomen/monk_trigger.lua:1>) | Pending |

### lakerathe

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E191 | Kazen Fecae undead trial | epic event | Quest-started bone golem death continues a chain of undead opponents. Necromancer 1.0.<br>[lakerathe/Kazen_Fecae.lua:86](<F:/EQ1/Bastion_Dev/quests/lakerathe/Kazen_Fecae.lua:86>) · [lakerathe/a_bone_golem.lua:2](<F:/EQ1/Bastion_Dev/quests/lakerathe/a_bone_golem.lua:2>) | Pending |
| E192 | Man-eating Freshwater Shark | epic boss | Necromancer pre-epic shark has successive HP stages. <br>[lakerathe/#Man-eating_Freshwater_Shark.lua:12](<F:/EQ1/Bastion_Dev/quests/lakerathe/%23Man-eating_Freshwater_Shark.lua:12>) | Pending |

### lavastorm

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E193 | High Priestess Shima ambush | epic event | Quest dialogue or turn-in creates Shima and five priests/priestesses at the fight location. Cleric pre-1.5. Short event without extensive phase logic.<br>[lavastorm/Laura_Rako.lua:19](<F:/EQ1/Bastion_Dev/quests/lavastorm/Laura_Rako.lua:19>) | Pending |

### lfaydark

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E194 | Taskmaster Mirot and the reanimated minions | epic event | Wayfarer scene becomes hostile Mirot plus six minions with linked aggro and range checks. Cleric 1.5.<br>[lfaydark/Taskmaster_Mirot.lua:46](<F:/EQ1/Bastion_Dev/quests/lfaydark/Taskmaster_Mirot.lua:46>) · [lfaydark/#Taskmaster_Mirot.lua:34](<F:/EQ1/Bastion_Dev/quests/lfaydark/%23Taskmaster_Mirot.lua:34>) · [lfaydark/Reanimated_Minion.lua:13](<F:/EQ1/Bastion_Dev/quests/lfaydark/Reanimated_Minion.lua:13>) | Pending |

### mirb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E195 | Frozen Nightmare: chromatic bonewalkers | raid event | Bonewalkers cycle through colored forms with a coordinated icy-form completion condition. <br>[mirb/encounters/skeletons.lua:4](<F:/EQ1/Bastion_Dev/quests/mirb/encounters/skeletons.lua:4>) · [mirb/Durgin_Skell.lua:41](<F:/EQ1/Bastion_Dev/quests/mirb/Durgin_Skell.lua:41>) | Pending |
| E196 | Frozen Nightmare: Frostfoot goblins | raid event | Scout escape spawns Raid Leader Sig Chol and Taskmaster Suttalp, who summon ongoing henchmen. Raider module is shared trash behavior.<br>[mirb/encounters/goblins.lua:58](<F:/EQ1/Bastion_Dev/quests/mirb/encounters/goblins.lua:58>) · [mirb/zone_status.lua:9](<F:/EQ1/Bastion_Dev/quests/mirb/zone_status.lua:9>) | Pending |
| E197 | Frozen Nightmare: Laskuth the Colossus | raid boss | Durgin unlocks the finale after prior events, spawning Laskuth and sleet flurries. Six subevents may instead be one Frozen Nightmare raid document.<br>[mirb/Durgin_Skell.lua:121](<F:/EQ1/Bastion_Dev/quests/mirb/Durgin_Skell.lua:121>) · [mirb/#Laskuth_the_Colossus.lua:1](<F:/EQ1/Bastion_Dev/quests/mirb/%23Laskuth_the_Colossus.lua:1>) | Pending |
| E198 | Frozen Nightmare: Marrow the Broken | raid boss | Successive HP thresholds shrink the golem and create splinterbone skeleton adds. Script notes missing splinterbone dervish alternative in the database.<br>[mirb/Marrow_the_Broken.lua:12](<F:/EQ1/Bastion_Dev/quests/mirb/Marrow_the_Broken.lua:12>) · [mirb/Durgin_Skell.lua:43](<F:/EQ1/Bastion_Dev/quests/mirb/Durgin_Skell.lua:43>) | Pending |
| E199 | Frozen Nightmare: Sharalla | raid event | Defend Sharalla's corpse from animals; accumulated bites trigger failure. <br>[mirb/encounters/sharalla.lua:54](<F:/EQ1/Bastion_Dev/quests/mirb/encounters/sharalla.lua:54>) · [mirb/encounters/sharalla.lua:37](<F:/EQ1/Bastion_Dev/quests/mirb/encounters/sharalla.lua:37>) | Pending |
| E200 | Frozen Nightmare: sundering sludge | raid event | Sundering sludge splits into severing then slippery sludges; completion signals the raid controller. <br>[mirb/encounters/sludge.lua:31](<F:/EQ1/Bastion_Dev/quests/mirb/encounters/sludge.lua:31>) · [mirb/Durgin_Skell.lua:39](<F:/EQ1/Bastion_Dev/quests/mirb/Durgin_Skell.lua:39>) | Pending |

### mischiefplane

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E201 | Bristlebane the King of Thieves (2.0) | raid boss | HP stages summon changing jester groups and update boss phases with reset handling. 2.0 is script encounter label, not an asserted instance version.<br>[mischiefplane/encounters/Bristlebane_the_King_of_Thieves.lua:49](<F:/EQ1/Bastion_Dev/quests/mischiefplane/encounters/Bristlebane_the_King_of_Thieves.lua:49>) · [mischiefplane/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/mischiefplane/script_init.lua:1>) | Pending |

### mistmoore

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E202 | The Archivist's Midnight Vigil | group mission | Group expedition hunts respawning castle enemies, tracks verified kills and awards four relics needed to request the raid. Version 11 explicitly configured, group size 3-6 and level 50 minimum. New mission document distinct from the five existing version-10 raid boss journals.<br>[mistmoore/encounters/mistmoore_eclipse.lua:31](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:31>) · [mistmoore/encounters/mistmoore_eclipse.lua:2925](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:2925>) · [mistmoore/encounters/mistmoore_eclipse.lua:3123](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:3123>) | Pending |

### mmcc

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E203 | Struggles within the Progeny / Valdoon Kel'Novar | raid | Real/fake Valdoon, guardian deaths, lookout adds, powerdown windows and raid completion are scripted. <br>[mmcc/encounters/mmccraid.lua:134](<F:/EQ1/Bastion_Dev/quests/mmcc/encounters/mmccraid.lua:134>) · [mmcc/zone_status.lua:9](<F:/EQ1/Bastion_Dev/quests/mmcc/zone_status.lua:9>) | Pending |

### multiple

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E204 | Bonded Hunts: companion challenges (36 targets) | Repeatable challenge system | An assigned owner summons a tuned echo with a reusable beacon, completes a companion kill audit, and unlocks or recharges a reward. The 36 targets share one encounter controller. Approve as a shared activity guide if wanted. Do not manufacture 36 distinct boss mechanic guides: the target wrappers delegate to the same controller. Native audit rules require a later source review beyond quest Lua. Targets are listed in the supporting registry.<br>[lua_modules/bonded_hunts_config.lua:77](<F:/EQ1/Bastion_Dev/quests/lua_modules/bonded_hunts_config.lua:77>) · [lua_modules/bonded_hunt_echo.lua:54](<F:/EQ1/Bastion_Dev/quests/lua_modules/bonded_hunt_echo.lua:54>) · [lua_modules/bonded_hunts.lua:465](<F:/EQ1/Bastion_Dev/quests/lua_modules/bonded_hunts.lua:465>) · [poknowledge/991136.lua:1](<F:/EQ1/Bastion_Dev/quests/poknowledge/991136.lua:1>) · [hole/991100.lua:1](<F:/EQ1/Bastion_Dev/quests/hole/991100.lua:1>) | Pending |

### nadox

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E205 | The Luggald Broodmother | event | Waypoint sequence escalates defender/archseeker waves before spawning the combat Broodmother. <br>[nadox/The_Luggald_Broodmother.lua:3](<F:/EQ1/Bastion_Dev/quests/nadox/The_Luggald_Broodmother.lua:3>) · [nadox/#The_Luggald_Broodmother.lua:5](<F:/EQ1/Bastion_Dev/quests/nadox/%23The_Luggald_Broodmother.lua:5>) | Pending |

### natimbi

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E206 | Cragbeast Queen (seasonal) | raid boss | Seasonal boss has periodic abilities and recruits nearby NPCs into combat. Version 200 checked explicitly; deduplicate central seasonal registry.<br>[natimbi/#Cragbeast_Queen.lua:12](<F:/EQ1/Bastion_Dev/quests/natimbi/%23Cragbeast_Queen.lua:12>) · [natimbi/#Cragbeast_Queen.lua:43](<F:/EQ1/Bastion_Dev/quests/natimbi/%23Cragbeast_Queen.lua:43>) | Pending |
| E207 | Ritual Conduit | epic event | Conduit/Reborn setup leads to HP-triggered Juggernauts and timed encounter limits. Druid 1.5.<br>[natimbi/encounters/druid_1_5.lua:16](<F:/EQ1/Bastion_Dev/quests/natimbi/encounters/druid_1_5.lua:16>) · [natimbi/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/natimbi/script_init.lua:1>) | Pending |
| E208 | Spiritbinder Trenzar and Senvial of the Mist | epic event | Timed elemental adds and flame waves combine with Senvial HP and state changes. Ranger 1.5.<br>[natimbi/encounters/ranger_1_5.lua:18](<F:/EQ1/Bastion_Dev/quests/natimbi/encounters/ranger_1_5.lua:18>) · [natimbi/encounters/ranger_1_5.lua:85](<F:/EQ1/Bastion_Dev/quests/natimbi/encounters/ranger_1_5.lua:85>) | Pending |
| E209 | Tybone Biggums | custom event | Boss changes golem/mastruq/skeleton forms with untargetable add intermissions. Gathering Event 2022; current availability requires content-gate confirmation.<br>[natimbi/Tybone_Biggums.lua:49](<F:/EQ1/Bastion_Dev/quests/natimbi/Tybone_Biggums.lua:49>) · [natimbi/#Tybone_Biggums.lua:17](<F:/EQ1/Bastion_Dev/quests/natimbi/%23Tybone_Biggums.lua:17>) | Pending |

### necropolis

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E210 | Garzicor's Corpse and Wraith | quest event | Blood offering starts a corpse fight; its defeat spawns the final dragon wraith, followed by the reward shade. Consolidates ritual and two combat forms.<br>[necropolis/#a_ghostly_presence.lua:13](<F:/EQ1/Bastion_Dev/quests/necropolis/%23a_ghostly_presence.lua:13>) · [necropolis/#Garzicor-s_Corpse.lua:13](<F:/EQ1/Bastion_Dev/quests/necropolis/%23Garzicor-s_Corpse.lua:13>) · [necropolis/#Garzicor-s_Wraith.lua:13](<F:/EQ1/Bastion_Dev/quests/necropolis/%23Garzicor-s_Wraith.lua:13>) | Pending |
| E211 | Vesthon Marijakin and the Dracoliche of Hsagra | epic event | Masters and respawning minions gate Vesthon; his defeat activates a dracoliche finale. Bard 1.5 second fight.<br>[necropolis/encounters/vesthon.lua:60](<F:/EQ1/Bastion_Dev/quests/necropolis/encounters/vesthon.lua:60>) | Pending |
| E212 | Vesthon Marijakin: first confrontation | epic event | Hsagra turn-in spawns Vesthon and guards; a timed scene and HP escape control the encounter. Bard 1.5.<br>[necropolis/#Hsagra-s_Shade.lua:62](<F:/EQ1/Bastion_Dev/quests/necropolis/%23Hsagra-s_Shade.lua:62>) · [necropolis/123167.lua:10](<F:/EQ1/Bastion_Dev/quests/necropolis/123167.lua:10>) | Pending |
| E213 | Zlandicar (seasonal) | raid boss | Telegraphed incision, septic and lobotomy mechanics plus operating-table and burn phases are implemented. Deduplicate central seasonal registry; native variant differs.<br>[necropolis/Zlandicar.lua:201](<F:/EQ1/Bastion_Dev/quests/necropolis/Zlandicar.lua:201>) · [necropolis/Zlandicar.lua:418](<F:/EQ1/Bastion_Dev/quests/necropolis/Zlandicar.lua:418>) | Pending |

### nightmareb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E214 | Terris Thule | raid boss | HP-triggered defiler waves, dream disruption and gargoyle activation combine with a lair leash. <br>[nightmareb/Terris_Thule.lua:33](<F:/EQ1/Bastion_Dev/quests/nightmareb/Terris_Thule.lua:33>) | Pending |

### northkarana

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E215 | Dire griffon / plains dragon transformation | epic encounter | Dire griffon becomes a plains dragon at its scripted HP trigger. Bard 1.5.<br>[northkarana/a_dire_griffon.lua:10](<F:/EQ1/Bastion_Dev/quests/northkarana/a_dire_griffon.lua:10>) · [northkarana/#a_plains_dragon.lua:1](<F:/EQ1/Bastion_Dev/quests/northkarana/%23a_plains_dragon.lua:1>) | Pending |

### oasis

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E216 | Remal the Black | epic event | Keelee starts repeating orc waves and banishment checks before the final Remal fight. Paladin 2.0.<br>[oasis/encounters/paladin_epic.lua:43](<F:/EQ1/Bastion_Dev/quests/oasis/encounters/paladin_epic.lua:43>) · [oasis/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/oasis/script_init.lua:1>) | Pending |

### overthere

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E217 | Glowing cliff golem and Watch Sergeant Grolj | quest event | Granika creates a golem encounter and a coordinated undead guard group. Greenmist final quest; little phase logic.<br>[overthere/Alchemist_Granika.lua:19](<F:/EQ1/Bastion_Dev/quests/overthere/Alchemist_Granika.lua:19>) | Pending |

### permafrost

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E218 | Lady Vox (seasonal) | raid boss | Seasonal branch adds successive HP abilities and add waves with reset/leash handling. Version 200 checked explicitly; deduplicate central registry.<br>[permafrost/Lady_Vox.lua:118](<F:/EQ1/Bastion_Dev/quests/permafrost/Lady_Vox.lua:118>) · [permafrost/Lady_Vox.lua:209](<F:/EQ1/Bastion_Dev/quests/permafrost/Lady_Vox.lua:209>) | Pending |

### poair

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E219 | Chamberlain Escalardian | raid event | Castellan/constable kills unlock Chamberlain, whose combat uses timed memblur. <br>[poair/encounters/Chamberlain_Event.lua:12](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Chamberlain_Event.lua:12>) · [poair/#isle_one_controller.lua:13](<F:/EQ1/Bastion_Dev/quests/poair/%23isle_one_controller.lua:13>) | Pending |
| E220 | Elemental Masterpiece | raid event | Trash kills activate four elemental champions followed by the masterpiece. <br>[poair/encounters/Masterpiece_Event.lua:18](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Masterpiece_Event.lua:18>) · [poair/#isle_three_controller.lua:13](<F:/EQ1/Bastion_Dev/quests/poair/%23isle_three_controller.lua:13>) | Pending |
| E221 | Melernil Faal'Armanna | raid event | Phoenix island clear leads through firesurger/windsurger stages to the boss. <br>[poair/encounters/Melernil_Event.lua:23](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Melernil_Event.lua:23>) · [poair/#isle_four_controller.lua:15](<F:/EQ1/Bastion_Dev/quests/poair/%23isle_four_controller.lua:15>) | Pending |
| E222 | Sigismond Windwalker | raid event | Spider kills progress the event, activating archwalker and timed adds. <br>[poair/encounters/Sigismond_Event.lua:26](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Sigismond_Event.lua:26>) · [poair/#isle_five_controller.lua:14](<F:/EQ1/Bastion_Dev/quests/poair/%23isle_five_controller.lua:14>) | Pending |
| E223 | Stormrider island | raid event | Clearing stormriders starts successive add/priest stages and island completion. <br>[poair/encounters/Stormrider_Event.lua:27](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Stormrider_Event.lua:27>) · [poair/#isle_two_controller.lua:13](<F:/EQ1/Bastion_Dev/quests/poair/%23isle_two_controller.lua:13>) | Pending |
| E224 | Xegony, Queen of Air | raid event | Dedicated controller coordinates the boss, waves of adds and resets. <br>[poair/encounters/Xegony_Event.lua:1](<F:/EQ1/Bastion_Dev/quests/poair/encounters/Xegony_Event.lua:1>) · [poair/#event_control_Xegony.lua:16](<F:/EQ1/Bastion_Dev/quests/poair/%23event_control_Xegony.lua:16>) | Pending |

### poeartha

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E225 | Dust ring / Perfected Warder of Earth | raid event | Devotees and Triumvirate of Soil progression unlock the Warder. <br>[poeartha/encounters/Dust_Event.lua:20](<F:/EQ1/Bastion_Dev/quests/poeartha/encounters/Dust_Event.lua:20>) · [poeartha/#dust_controller.lua:14](<F:/EQ1/Bastion_Dev/quests/poeartha/%23dust_controller.lua:14>) | Pending |
| E226 | Mud ring / Monstrous Mudwalker | raid event | Sludge lurker HP stages produce add waves before the final mudwalker. <br>[poeartha/encounters/Mud_Event.lua:24](<F:/EQ1/Bastion_Dev/quests/poeartha/encounters/Mud_Event.lua:24>) · [poeartha/#mud_controller.lua:14](<F:/EQ1/Bastion_Dev/quests/poeartha/%23mud_controller.lua:14>) | Pending |
| E227 | Mystical Arbitor of Earth | raid boss | Ring completion leads to Arbitor, with timed memblur and final progression. Old arbitor controller is commented out; zone_status now owns progression.<br>[poeartha/#A_Mystical_Arbitor_of_Earth.lua:10](<F:/EQ1/Bastion_Dev/quests/poeartha/%23A_Mystical_Arbitor_of_Earth.lua:10>) · [poeartha/zone_status.lua:1](<F:/EQ1/Bastion_Dev/quests/poeartha/zone_status.lua:1>) | Pending |
| E228 | Stone ring / Peregrin Rockskull | raid event | Multiple stone types, waves and counters drive a staged ring event. <br>[poeartha/encounters/Stone_Event.lua:35](<F:/EQ1/Bastion_Dev/quests/poeartha/encounters/Stone_Event.lua:35>) · [poeartha/#stone_controller.lua:14](<F:/EQ1/Bastion_Dev/quests/poeartha/%23stone_controller.lua:14>) | Pending |
| E229 | Vine ring / Derugoak Bloodwalker | raid event | Tainted-beast deaths create vegerogs; clearing them activates the boss. <br>[poeartha/encounters/Vine_Event.lua:21](<F:/EQ1/Bastion_Dev/quests/poeartha/encounters/Vine_Event.lua:21>) · [poeartha/#vine_controller.lua:14](<F:/EQ1/Bastion_Dev/quests/poeartha/%23vine_controller.lua:14>) | Pending |

### poearthb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E230 | Rathe Council and Avatar of Earth | raid event | Mezzable/non-mezzable council handling, HP-based damage, death timing and Avatar finale. <br>[poearthb/encounters/Rathe_Event.lua:55](<F:/EQ1/Bastion_Dev/quests/poearthb/encounters/Rathe_Event.lua:55>) · [poearthb/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/poearthb/script_init.lua:1>) | Pending |
| E231 | Warlord Gintolaken | raid event | Three clan/chieftain kill chains unlock Warlord with failure/reset timers. <br>[poearthb/encounters/Warlord_Event.lua:43](<F:/EQ1/Bastion_Dev/quests/poearthb/encounters/Warlord_Event.lua:43>) · [poearthb/#warlord_controller.lua:21](<F:/EQ1/Bastion_Dev/quests/poearthb/%23warlord_controller.lua:21>) | Pending |

### pofire

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E232 | Fennin Ro, Tyrant of Fire | raid event | Four explicit phases progress from trash to bridge, council and Fennin. <br>[pofire/encounters/Fennin_Event.lua:28](<F:/EQ1/Bastion_Dev/quests/pofire/encounters/Fennin_Event.lua:28>) · [pofire/script_init.lua:4](<F:/EQ1/Bastion_Dev/quests/pofire/script_init.lua:4>) | Pending |

### poinnovation

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E233 | Manaetic Behemoth | raid event | Bomb/device control activates the targetable boss with leash, failure and success signals. <br>[poinnovation/Manaetic_Behemoth.lua:24](<F:/EQ1/Bastion_Dev/quests/poinnovation/Manaetic_Behemoth.lua:24>) · [poinnovation/#Manaetic_Behemoth.lua:25](<F:/EQ1/Bastion_Dev/quests/poinnovation/%23Manaetic_Behemoth.lua:25>) | Pending |
| E234 | Nitram Anizok / Xanamech Nezmirthafen | raid event | Quest event replaces fake dragon with the real combat NPC and monitors win/fail timers. <br>[poinnovation/Nitram_Anizok.lua:109](<F:/EQ1/Bastion_Dev/quests/poinnovation/Nitram_Anizok.lua:109>) | Pending |

### pojustice

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E235 | The Seventh Hammer | raid boss | Timed Tribunal signals cast Verdict of Eternity or Tremor of Judgment. Distinct from monk epic NPC sharing its name.<br>[pojustice/The_Seventh_Hammer.lua:36](<F:/EQ1/Bastion_Dev/quests/pojustice/The_Seventh_Hammer.lua:36>) | Pending |
| E236 | Trial of Execution | group trial | Protect a prisoner through waves before Prime Executioner Vathoch. <br>[pojustice/#Event_Execution_Control.lua:25](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Execution_Control.lua:25>) | Pending |
| E237 | Trial of Flame | group trial | Timed flame waves and AE punishment lead to Punisher of Flame. <br>[pojustice/#Event_Flame_Control.lua:26](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Flame_Control.lua:26>) | Pending |
| E238 | Trial of Hanging | group trial | Protect prisoners from suffocation spirits before Gallows Master Teion. <br>[pojustice/#Event_Hanging_Control.lua:30](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Hanging_Control.lua:30>) | Pending |
| E239 | Trial of Lashing | group trial | Manage prisoners/spirits and waves before Lashman Azakal. <br>[pojustice/#Event_Lashing_Control.lua:26](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Lashing_Control.lua:26>) | Pending |
| E240 | Trial of Stoning | group trial | Incoming waves and prisoner defense culminate in Yurae Zhaleem. <br>[pojustice/#Event_Stoning_Control.lua:27](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Stoning_Control.lua:27>) | Pending |
| E241 | Trial of Torture | group trial | Tormentor waves, healing wraith and failure checks lead to Punisher Veshtaq. <br>[pojustice/#Event_Torture_Control.lua:47](<F:/EQ1/Bastion_Dev/quests/pojustice/%23Event_Torture_Control.lua:47>) | Pending |

### ponightmare

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E242 | Aid Eino escort / Dreamkeeper | escort | Waypoint ambushes attack Eino; Dreamkeeper death finishes the event. <br>[ponightmare/#Aid_Eino.lua:13](<F:/EQ1/Bastion_Dev/quests/ponightmare/%23Aid_Eino.lua:13>) · [ponightmare/#The_Dreamkeeper.lua:23](<F:/EQ1/Bastion_Dev/quests/ponightmare/%23The_Dreamkeeper.lua:23>) | Pending |
| E243 | Deyid the Twisted | raid boss | Trees spawn, close in at successive HP stages and become hostile adds. <br>[ponightmare/Deyid_the_Twisted.lua:16](<F:/EQ1/Bastion_Dev/quests/ponightmare/Deyid_the_Twisted.lua:16>) | Pending |
| E244 | Mujaki the Devourer | raid event | Servant waves, guardian checks and timers control Mujaki activation. <br>[ponightmare/encounters/Mujaki_1.lua:67](<F:/EQ1/Bastion_Dev/quests/ponightmare/encounters/Mujaki_1.lua:67>) · [ponightmare/Mujaki_the_Devourer.lua:27](<F:/EQ1/Bastion_Dev/quests/ponightmare/Mujaki_the_Devourer.lua:27>) | Pending |
| E245 | Terror Matriarch | boss | HP trigger starts repeating hatchling spawns during combat. <br>[ponightmare/#Terror_Matriarch.lua:19](<F:/EQ1/Bastion_Dev/quests/ponightmare/%23Terror_Matriarch.lua:19>) | Pending |

### postorms

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E246 | Drornok Tok Vo'Lok | event | Clearing pond enemies triggers Drornok; his HP thresholds spawn more adds. <br>[postorms/#Drornok_trigger.lua:5](<F:/EQ1/Bastion_Dev/quests/postorms/%23Drornok_trigger.lua:5>) · [postorms/#Drornok_Tok_Vo-Lok.lua:21](<F:/EQ1/Bastion_Dev/quests/postorms/%23Drornok_Tok_Vo-Lok.lua:21>) | Pending |
| E247 | Falto, Lord of Thunder | event | Area clears and mephit progression summon Falto; controller supports reset. <br>[postorms/#Falto_trigger.lua:11](<F:/EQ1/Bastion_Dev/quests/postorms/%23Falto_trigger.lua:11>) · [postorms/#Falto_trigger.lua:33](<F:/EQ1/Bastion_Dev/quests/postorms/%23Falto_trigger.lua:33>) | Pending |
| E248 | Ston'Ruak, Ancient of Trees | event | Tree clears trigger extra waves then the Ancient, with reset signals. <br>[postorms/#Ston_trigger.lua:11](<F:/EQ1/Bastion_Dev/quests/postorms/%23Ston_trigger.lua:11>) · [postorms/#Ston_trigger.lua:49](<F:/EQ1/Bastion_Dev/quests/postorms/%23Ston_trigger.lua:49>) | Pending |

### potactics

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E249 | Rallos Zek the Warlord | raid event | Three stages cover Tallon/Vallon, fake Rallos and the Warlord with pit adds. <br>[potactics/encounters/rztw_event.lua:27](<F:/EQ1/Bastion_Dev/quests/potactics/encounters/rztw_event.lua:27>) · [potactics/encounters/rztw_event.lua:170](<F:/EQ1/Bastion_Dev/quests/potactics/encounters/rztw_event.lua:170>) · [potactics/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/potactics/script_init.lua:1>) | Pending |
| E250 | Tallon Zek | raid boss | Combat timers execute targeted attacks and aggro bouncing with completion flagging. <br>[potactics/encounters/tallon_event.lua:18](<F:/EQ1/Bastion_Dev/quests/potactics/encounters/tallon_event.lua:18>) · [potactics/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/potactics/script_init.lua:2>) | Pending |
| E251 | Vallon Zek | raid boss | HP-triggered splits/waves and a dedicated controller govern progression. <br>[potactics/encounters/vallon_event.lua:26](<F:/EQ1/Bastion_Dev/quests/potactics/encounters/vallon_event.lua:26>) · [potactics/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/potactics/script_init.lua:3>) | Pending |

### potimeb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E252 | Plane of Time: phase 4 gods | raid sequence | Terris/Saryrn summon threshold adds, Vallon splits and Tallon has tether/kill progression. May split into four god entries after approval.<br>[potimeb/Terris_Thule.lua:26](<F:/EQ1/Bastion_Dev/quests/potimeb/Terris_Thule.lua:26>) · [potimeb/Saryrn.lua:45](<F:/EQ1/Bastion_Dev/quests/potimeb/Saryrn.lua:45>) · [potimeb/Vallon_Zek.lua:53](<F:/EQ1/Bastion_Dev/quests/potimeb/Vallon_Zek.lua:53>) · [potimeb/Tallon_Zek.lua:20](<F:/EQ1/Bastion_Dev/quests/potimeb/Tallon_Zek.lua:20>) | Pending |
| E253 | Plane of Time: phase 5 gods | raid sequence | Bertoxxulous stats escalate, Innoruuk summons adds, Rallos gains rampage/flurry, Cazic completes the quartet. May split into four god entries after approval.<br>[potimeb/#Bertoxxulous.lua:35](<F:/EQ1/Bastion_Dev/quests/potimeb/%23Bertoxxulous.lua:35>) · [potimeb/#Innoruuk.lua:19](<F:/EQ1/Bastion_Dev/quests/potimeb/%23Innoruuk.lua:19>) · [potimeb/#Rallos_Zek.lua:40](<F:/EQ1/Bastion_Dev/quests/potimeb/%23Rallos_Zek.lua:40>) · [potimeb/#Cazic_Thule.lua:20](<F:/EQ1/Bastion_Dev/quests/potimeb/%23Cazic_Thule.lua:20>) | Pending |
| E254 | Plane of Time: phases 1-3 | raid sequence | Elemental trial triggers, phase-two waves and phase-three progression are scripted. Early trials can be sections of one guide.<br>[potimeb/zone_status.lua:160](<F:/EQ1/Bastion_Dev/quests/potimeb/zone_status.lua:160>) · [potimeb/phase_two_controller.lua:98](<F:/EQ1/Bastion_Dev/quests/potimeb/phase_two_controller.lua:98>) | Pending |
| E255 | Quarm | raid boss | Losing heads changes AE sets; repeating adds and reset/finale progression are scripted. <br>[potimeb/Quarm.lua:99](<F:/EQ1/Bastion_Dev/quests/potimeb/Quarm.lua:99>) · [potimeb/Quarm.lua:80](<F:/EQ1/Bastion_Dev/quests/potimeb/Quarm.lua:80>) | Pending |

### potorment

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E256 | Maareq the Prophet | raid boss | HP combat changes and recurring minions lead into Tylis/Keeper progression. <br>[potorment/Maareq_the_Prophet.lua:8](<F:/EQ1/Bastion_Dev/quests/potorment/Maareq_the_Prophet.lua:8>) · [potorment/Maareq_the_Prophet.lua:38](<F:/EQ1/Bastion_Dev/quests/potorment/Maareq_the_Prophet.lua:38>) | Pending |
| E257 | Salczek the Fleshgrinder | boss | Combat changes at 40 and 20 percent with reset handling. <br>[potorment/Salczek_the_Fleshgrinder.lua:17](<F:/EQ1/Bastion_Dev/quests/potorment/Salczek_the_Fleshgrinder.lua:17>) | Pending |
| E258 | Saryrn | raid boss | Ravens appear at successive HP thresholds; Sorrowsong activates late in combat. <br>[potorment/Saryrn.lua:33](<F:/EQ1/Bastion_Dev/quests/potorment/Saryrn.lua:33>) | Pending |

### povalor

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E259 | Aerin'Dar | raid boss | HP stages activate golem adds and reset them when the boss recovers. <br>[povalor/#Aerin-Dar.lua:13](<F:/EQ1/Bastion_Dev/quests/povalor/%23Aerin-Dar.lua:13>) | Pending |

### powater

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E260 | Coirnav, Avatar of Water | raid event | Timed waves and intermediate bosses activate final Coirnav stage. <br>[powater/encounters/Coirnav_Event.lua:52](<F:/EQ1/Bastion_Dev/quests/powater/encounters/Coirnav_Event.lua:52>) · [powater/Guardian_of_Coirnav.lua:19](<F:/EQ1/Bastion_Dev/quests/powater/Guardian_of_Coirnav.lua:19>) | Pending |

### provinggrounds

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E261 | Lightning Warrior Spiritseeker | epic encounter | Quest proximity spawns Spiritseeker; combat summons two spiritsappers and a cleanup timer. Shaman 2.0.<br>[provinggrounds/#Stilled_Lightning_Warrior.lua:9](<F:/EQ1/Bastion_Dev/quests/provinggrounds/%23Stilled_Lightning_Warrior.lua:9>) · [provinggrounds/#Lightning_Warrior_Spiritseeker.lua:1](<F:/EQ1/Bastion_Dev/quests/provinggrounds/%23Lightning_Warrior_Spiritseeker.lua:1>) | Pending |

### qey2hh1

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E262 | Yuanda: werewolf ambush | Quest event | Five werewolves, HP-triggered behavior, kill counter and Shady Bandit follow-up. Loaded by #Yuanda.lua:25.<br>[qey2hh1/encounters/Necro_pre_15.lua:9](<F:/EQ1/Bastion_Dev/quests/qey2hh1/encounters/Necro_pre_15.lua:9>) | Pending |

### qeynos2

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E263 | Enchanted Rat Experiment | Puzzle event | Timed rat-board experiment with tile conversion and success/failure signals. Noncombat event; optional journal scope.<br>[qeynos2/#game_spawner.lua:105](<F:/EQ1/Bastion_Dev/quests/qeynos2/%23game_spawner.lua:105>) | Pending |

### qinimi

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E264 | Execution: Rescue Kreshin Silentcog | Wave event | Four timed combat waves, execution sequence, fail/ejection timers and win handling. Loaded by script_init.lua:1.<br>[qinimi/encounters/execution.lua:67](<F:/EQ1/Bastion_Dev/quests/qinimi/encounters/execution.lua:67>) | Pending |
| E265 | Mastruq Commander Gorlakt and the Spiritlords | Quest boss | Defeating Spiritlord Mind or Body releases the commander and selects his timed spell behavior. Triggered by #druid_trap.lua:21.<br>[qinimi/#Mastruq_Commander_Gorlakt.lua:34](<F:/EQ1/Bastion_Dev/quests/qinimi/%23Mastruq_Commander_Gorlakt.lua:34>) | Pending |
| E266 | Thunderdome | Raid event | Five phases with timed waves, sacrifices, Mass of Stone and boss transformations. Three parallel controller copies (one/two/three) represent concurrent arenas, consolidated as one event.<br>[qinimi/encounters/thunder_dome_one.lua:110](<F:/EQ1/Bastion_Dev/quests/qinimi/encounters/thunder_dome_one.lua:110>) · [qinimi/#Councilman_Sislono_Nislan.lua:45](<F:/EQ1/Bastion_Dev/quests/qinimi/%23Councilman_Sislono_Nislan.lua:45>) | Pending |

### qvic

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E267 | Cynosure Kvanjji | Raid boss | Four arbiters heal the boss, mimic its spellcasts and respawn together if all four die. Related arbiters belong in this guide. Reset timer is started as reset but checked as Reset (lines 52 and 60); validate reset behavior before documenting it.<br>[qvic/##Cynosure_Kvanjji.lua:44](<F:/EQ1/Bastion_Dev/quests/qvic/%23%23Cynosure_Kvanjji.lua:44>) · [qvic/##Cynosure_Kvanjji.lua:129](<F:/EQ1/Bastion_Dev/quests/qvic/%23%23Cynosure_Kvanjji.lua:129>) · [qvic/##Cynosure_Kvanjji.lua:175](<F:/EQ1/Bastion_Dev/quests/qvic/%23%23Cynosure_Kvanjji.lua:175>) | Pending |
| E268 | Hexxt Ilk Klokk | Named encounter | Below-45% HP arrow volley behavior is scripted. Other NPC stats/spells require database review. Shares generic Kyv volley mechanic; lower priority than full raid events.<br>[qvic/Hexxt_Ilk_Klokk.lua:2](<F:/EQ1/Bastion_Dev/quests/qvic/Hexxt_Ilk_Klokk.lua:2>) | Pending |
| E269 | Hexxt Jkak Miq | Named encounter | Below-45% HP arrow volley behavior is scripted. Other NPC stats/spells require database review. Shares generic Kyv volley mechanic; lower priority than full raid events.<br>[qvic/Hexxt_Jkak_Miq.lua:2](<F:/EQ1/Bastion_Dev/quests/qvic/Hexxt_Jkak_Miq.lua:2>) | Pending |
| E270 | Hexxt Pvin Nki | Named encounter | Below-45% HP arrow volley behavior is scripted. Other NPC stats/spells require database review. Shares generic Kyv volley mechanic; lower priority than full raid events.<br>[qvic/#Hexxt_Pvin_Nki.lua:2](<F:/EQ1/Bastion_Dev/quests/qvic/%23Hexxt_Pvin_Nki.lua:2>) | Pending |
| E271 | Iqthinxa Karnkvi: Zoo Event | Raid boss | Boss becomes inactive at 75%; three Rav adds must be kept within the scripted HP balance. Rav helper scripts consolidated.<br>[qvic/Iqthinxa_Karnkvi.lua:21](<F:/EQ1/Bastion_Dev/quests/qvic/Iqthinxa_Karnkvi.lua:21>) | Pending |

### rathemtn

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E272 | Warrior Spirit Chalex: Captain Krignok | Quest event | Chalex pathing brings Captain Krignok into combat; captain timers spawn successive undead troll waves. Illusion: Guktan quest; include Troll_Captain_Krignok.lua rather than individual marauders.<br>[rathemtn/Warrior_Spirit_Chalex.lua:19](<F:/EQ1/Bastion_Dev/quests/rathemtn/Warrior_Spirit_Chalex.lua:19>) | Pending |

### riftseekers

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E273 | Chailak | Raid boss | Linked aggression with an associated add and a five-minute out-of-combat reset. Loaded by script_init.lua:4; include #Chailak.lua when drafting.<br>[riftseekers/encounters/chailak.lua:1](<F:/EQ1/Bastion_Dev/quests/riftseekers/encounters/chailak.lua:1>) | Pending |
| E274 | Craftmaster Tieranu | Quest boss | HP phases spawn named elementals followed by additional elemental waves. Ranger epic 2.0; loaded by script_init.lua:3.<br>[riftseekers/encounters/ranger_2_0.lua:16](<F:/EQ1/Bastion_Dev/quests/riftseekers/encounters/ranger_2_0.lua:16>) | Pending |
| E275 | King Gelaqua and the Princes | Raid event | Princes gate the king; portal spawns, hate links and damaging ground effects structure the fight. Loaded by script_init.lua:1.<br>[riftseekers/encounters/king.lua:121](<F:/EQ1/Bastion_Dev/quests/riftseekers/encounters/king.lua:121>) | Pending |
| E276 | Queen Pyrilonis and the Princesses | Raid event | Princess deaths remove immunity; portals, chimera adds and timed AE effects. Loaded by script_init.lua:2.<br>[riftseekers/encounters/queen.lua:107](<F:/EQ1/Bastion_Dev/quests/riftseekers/encounters/queen.lua:107>) | Pending |

### riwwi

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E277 | Arena: Turlini and the enslaved yunjo | Wave event | Officiator spawns a succession of arena opponents; deaths signal the next challenge. Consolidates numerous named arena wave scripts.<br>[riwwi/#an_officiator.lua:1](<F:/EQ1/Bastion_Dev/quests/riwwi/%23an_officiator.lua:1>) | Pending |
| E278 | Taskmistress Krisz | Raid boss | Prerequisite Pixtt kills spawn her; multiple HP thresholds increase melee damage. Includes #Pixtt_Kekken.lua and #Pixtt_Uxnikk.lua spawn wiring.<br>[riwwi/#Taskmistress_Krisz.lua:10](<F:/EQ1/Bastion_Dev/quests/riwwi/%23Taskmistress_Krisz.lua:10>) | Pending |
| E279 | Viqu the Blindeye | Named encounter | HP-triggered archery volley with combat timer and reset. Generic Kyv-style mechanic; lower priority.<br>[riwwi/#Viqu_the_Blindeye.lua:12](<F:/EQ1/Bastion_Dev/quests/riwwi/%23Viqu_the_Blindeye.lua:12>) | Pending |

### ruja

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E280 | Rujarkian Hills adventures (shared rules) | Adventure system | Shared scaled assassination/kill/collect/rescue mission controller, traps, minibosses and completion. Same system duplicated across ruja-rujj. Prefer one theme overview rather than 10 identical guides.<br>[ruja/encounters/ruj.lua:164](<F:/EQ1/Bastion_Dev/quests/ruja/encounters/ruj.lua:164>) | Pending |

### rujd

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E281 | Prison Break: Warden Neyremal and High Shaman Yenner | Raid event | Prisoner rescues, taskmasters, cleared rooms, Prison Guard Talkor and final boss pair. Version 2 controller in zone_status.lua:31; #Raid_Timer.lua loads raid and goblin helper.<br>[rujd/encounters/rujdraid.lua:129](<F:/EQ1/Bastion_Dev/quests/rujd/encounters/rujdraid.lua:129>) · [rujd/#Raid_Timer.lua:2](<F:/EQ1/Bastion_Dev/quests/rujd/%23Raid_Timer.lua:2>) · [rujd/encounters/goblins.lua:53](<F:/EQ1/Bastion_Dev/quests/rujd/encounters/goblins.lua:53>) | Pending |

### rujg

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E282 | Flawless Experimental Battlelord | Raid event | Researcher kills unlock mutations and the battlelord; timers control adds and reward conditions. Crispen_Koloff.lua loads raid on spawn.<br>[rujg/encounters/rujgraid.lua:137](<F:/EQ1/Bastion_Dev/quests/rujg/encounters/rujgraid.lua:137>) · [rujg/Crispen_Koloff.lua:2](<F:/EQ1/Bastion_Dev/quests/rujg/Crispen_Koloff.lua:2>) | Pending |

### scarlet

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E283 | Disciple of Sun | Quest boss | Monk challenge removes immunity and starts a 15-minute fight deadline. <br>[scarlet/#Disciple_of_Sun.lua:16](<F:/EQ1/Bastion_Dev/quests/scarlet/%23Disciple_of_Sun.lua:16>) | Pending |
| E284 | High Priest Valon and the Plasmatic followers | Quest event | Jimmic Adle summons a priest and five followers at the encounter location. Trigger/spawn event; no separate boss combat script in this zone.<br>[scarlet/Jimmic_Adle.lua:19](<F:/EQ1/Bastion_Dev/quests/scarlet/Jimmic_Adle.lua:19>) | Pending |

### sebilis

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E285 | Sebilite Protector | Quest boss | Juggernaut kill trigger, protector HP stages and Sebilite guardians. Berserker epic 1.5; loaded in script_init.lua.<br>[sebilis/encounters/berserkerepic_1_5.lua:128](<F:/EQ1/Bastion_Dev/quests/sebilis/encounters/berserkerepic_1_5.lua:128>) | Pending |
| E286 | Trakanon (Seasonal) | Raid boss | Bile burst, random deathgaze, top-threat banishment and final vulnerability phase. Instance version 200 explicitly required at line 18.<br>[sebilis/Trakanon.lua:33](<F:/EQ1/Bastion_Dev/quests/sebilis/Trakanon.lua:33>) | Pending |

### sirens

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E287 | A twitching swordfish | Quest boss | Combat state and 25% HP transition support a distinct epic encounter. Review full epic eligibility while authoring.<br>[sirens/a_twitching_swordfish.lua:3](<F:/EQ1/Bastion_Dev/quests/sirens/a_twitching_swordfish.lua:3>) | Pending |

### skyfire

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E288 | Rithnok the Tormented | Quest event | Dry Sapara spawns Rithnok; combat opens with Harm Touch and has a despawn deadline. Full encounter mechanics are small; include Dry_Sapara.lua:22.<br>[skyfire/#Rithnok_the_Tormented.lua:7](<F:/EQ1/Bastion_Dev/quests/skyfire/%23Rithnok_the_Tormented.lua:7>) | Pending |
| E289 | Talendor (Seasonal) | Raid boss | Timed combat behavior and a 20% HP phase are implemented in the boss script. Resolve seasonal registry/version centrally.<br>[skyfire/Talendor.lua:11](<F:/EQ1/Bastion_Dev/quests/skyfire/Talendor.lua:11>) | Pending |

### skyshrine

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E290 | Lord Yelinak (Seasonal) | Raid boss | Multi-phase dragon encounter with Skyborne, Judgment and Fury transitions. Resolve seasonal registry/version centrally.<br>[skyshrine/Lord_Yelinak.lua:294](<F:/EQ1/Bastion_Dev/quests/skyshrine/Lord_Yelinak.lua:294>) | Pending |

### sleeper

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E291 | Master of the Guard | Raid boss | Timed sentry waves and out-of-combat reset, registered for normal and fabled NPC IDs. Loaded by script_init.lua. Separate variants only if desired.<br>[sleeper/encounters/motg.lua:6](<F:/EQ1/Bastion_Dev/quests/sleeper/encounters/motg.lua:6>) | Pending |
| E292 | Sleep Walking expedition | Raid sequence | Explicit nine-boss lockout/spawn table and Kerafyrm finale. Other fabled bosses mostly have only death-lockout scripts; use one overview plus scripted Master of the Guard/Kerafyrm guides. Version 1 verified.<br>[sleeper/#Gozer_The_Gatekeeper.lua:38](<F:/EQ1/Bastion_Dev/quests/sleeper/%23Gozer_The_Gatekeeper.lua:38>) | Pending |
| E293 | The Fabled Kerafyrm the Awakened | Raid boss | Repeating single-target and AE spell timers in the fabled finale. Sleep Walking expedition is explicitly version 1 in #Gozer_The_Gatekeeper.lua:5.<br>[sleeper/#The_Fabled_Kerafrym_the_Awakened.lua:5](<F:/EQ1/Bastion_Dev/quests/sleeper/%23The_Fabled_Kerafrym_the_Awakened.lua:5>) | Pending |

### sncrematory

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E294 | Gzifa the Pure One | Group event | Four named spirits produce ghostly essences and unlock Gzifa via the trigger. Includes #A_Ghostly_Essence.lua and four spirit scripts.<br>[sncrematory/#gzifa_trigger.lua:35](<F:/EQ1/Bastion_Dev/quests/sncrematory/%23gzifa_trigger.lua:35>) | Pending |

### snlair

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E295 | Sewers of Nihilia: Lair tool recovery | Group event | Alej Leraji activates a dedicated tool-room population and event progression. Loaded by script_init.lua and triggered by Alej_Leraji.lua:69.<br>[snlair/encounters/tools.lua:46](<F:/EQ1/Bastion_Dev/quests/snlair/encounters/tools.lua:46>) | Pending |

### snplant

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E296 | Ancient Kayserops: Sewers stonemite event | Group event | Nine aged stonemite kills unlock Ancient Kayserops and progression credit. Loaded by script_init.lua:1.<br>[snplant/encounters/stonemites.lua:1](<F:/EQ1/Bastion_Dev/quests/snplant/encounters/stonemites.lua:1>) | Pending |

### snpool

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E297 | Sewers of Nihilia: Slime Cube | Group boss | Utandi spawns the Slime Cube; death splits it into six fragments. Include #Utandi.lua:22 as trigger.<br>[snpool/#Slime_Cube.lua:1](<F:/EQ1/Bastion_Dev/quests/snpool/%23Slime_Cube.lua:1>) | Pending |

### soldungb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E298 | Lord Nagafen (Seasonal) | Raid boss | HP gates, add waves and timed seasonal combat mechanics. Resolve seasonal version centrally.<br>[soldungb/Lord_Nagafen.lua:139](<F:/EQ1/Bastion_Dev/quests/soldungb/Lord_Nagafen.lua:139>) | Pending |

### soldungc

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E299 | Culthor the Gatekeeper | Quest boss | Rogue epic trigger summons the boss; linked guards increase his maximum melee damage as their remaining count falls. Include the_Firewatcher.lua:25 trigger.<br>[soldungc/#Culthor_the_Gatekeeper.lua:10](<F:/EQ1/Bastion_Dev/quests/soldungc/%23Culthor_the_Gatekeeper.lua:10>) · [soldungc/#Culthor_the_Gatekeeper.lua:24](<F:/EQ1/Bastion_Dev/quests/soldungc/%23Culthor_the_Gatekeeper.lua:24>) · [soldungc/the_Firewatcher.lua:23](<F:/EQ1/Bastion_Dev/quests/soldungc/the_Firewatcher.lua:23>) | Pending |
| E300 | Fireback Queen | Group event | Counter and proximity trigger generate spiderling waves before the queen. Includes #fireback_trigger.lua and a_fireback_spiderling.lua.<br>[soldungc/#fireback_counter.lua:17](<F:/EQ1/Bastion_Dev/quests/soldungc/%23fireback_counter.lua:17>) | Pending |
| E301 | Protector of Fire / Pure Flame Elemental | Group event | Sequential guardian and champion kills produce the final elemental encounter. Final outcome branches; include Protector_of_Fire.lua reset.<br>[soldungc/_.lua:17](<F:/EQ1/Bastion_Dev/quests/soldungc/_.lua:17>) | Pending |

### solrotower

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E302 | Arlyxir | Raid boss | Room boundary reset and timed full heal with Searing Flames. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Arlyxir.lua:14](<F:/EQ1/Bastion_Dev/quests/solrotower/Arlyxir.lua:14>) | Pending |
| E303 | Galremos | Raid boss | Multiple HP thresholds spawn growing magmite groups. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Galremos.lua:20](<F:/EQ1/Bastion_Dev/quests/solrotower/Galremos.lua:20>) | Pending |
| E304 | Jiva | Raid boss | Efreeti adds and linked combat control. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Jiva.lua:25](<F:/EQ1/Bastion_Dev/quests/solrotower/Jiva.lua:25>) | Pending |
| E305 | Protector of Dresolik | Raid boss | Guardian prerequisite spawns protector; combat leash and completion projection. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/#The_Protector_of_Dresolik.lua:8](<F:/EQ1/Bastion_Dev/quests/solrotower/%23The_Protector_of_Dresolik.lua:8>) | Pending |
| E306 | Rizlona | Raid boss | First form transforms into the second Rizlona, with tether and flag completion. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Rizlona.lua:1](<F:/EQ1/Bastion_Dev/quests/solrotower/Rizlona.lua:1>) | Pending |
| E307 | Solusek Ro | Raid boss | Room tether and progression flag/projection on completion. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Solusek_Ro.lua:10](<F:/EQ1/Bastion_Dev/quests/solrotower/Solusek_Ro.lua:10>) | Pending |
| E308 | Xuzl | Raid boss | Repeated animated sword summons and leash/reset. Zone_status.lua tracks raid progression and respawns.<br>[solrotower/Xuzl.lua:16](<F:/EQ1/Bastion_Dev/quests/solrotower/Xuzl.lua:16>) | Pending |

### sseru

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E309 | Lord Inquisitor Seru | Raid boss | Time-chamber boundary check moves the boss back into the room. Script alone supplies tether, not full spell or damage database.<br>[sseru/Lord_Inquisitor_Seru.lua:13](<F:/EQ1/Bastion_Dev/quests/sseru/Lord_Inquisitor_Seru.lua:13>) | Pending |

### ssratemple

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E310 | Emperor Ssraeshza and the Blood | Raid event | Blood prerequisite, emperor activation and timed combat/room control. Include #Blood_of_Ssraeshza.lua and EmpInitSpawn.lua.<br>[ssratemple/Emperor_Ssraeshza.lua:42](<F:/EQ1/Bastion_Dev/quests/ssratemple/Emperor_Ssraeshza.lua:42>) | Pending |
| E311 | Rhag cycle / Arch Lich | Raid sequence | Rhag deaths spawn the next boss and guards, concluding with Arch Lich. AL_Cycle.lua and 162192.lua contain later steps; source gives sequence more than combat mechanics.<br>[ssratemple/162178.lua:1](<F:/EQ1/Bastion_Dev/quests/ssratemple/162178.lua:1>) | Pending |
| E312 | Vyzh`dra cycle | Raid sequence | Glyph/runed serpent triggers Exiled/Banished/Cursed forms, with combat logic and respawn gates. Consolidates EventInit.lua, both serpent forms and three Vyzh`dra scripts.<br>[ssratemple/Vyzh-dra_the_Banished.lua:51](<F:/EQ1/Bastion_Dev/quests/ssratemple/Vyzh-dra_the_Banished.lua:51>) | Pending |

### steamfont

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E313 | Cargo Clockwork ambush | Escort event | Delivery route triggers Hector, Renaldo and Jerald; clockwork death signals cleanup. <br>[steamfont/Cargo_Clockwork.lua:18](<F:/EQ1/Bastion_Dev/quests/steamfont/Cargo_Clockwork.lua:18>) | Pending |
| E314 | Yama Tolk and the Inactive Clockwork | Quest event | Timed spell-type charging trial with staged prompts and short failure windows. Loaded by script_init.lua:1.<br>[steamfont/encounters/wizard_pre_15.lua:93](<F:/EQ1/Bastion_Dev/quests/steamfont/encounters/wizard_pre_15.lua:93>) | Pending |

### stillmoona

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E315 | Animated Statue Plans | Mission | Kill-count spawns warlord; workers, guards and statues respond to combat signals.  Script-selected instance version 2.<br>[stillmoona/encounters/animated_statue_plans.lua:4](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/animated_statue_plans.lua:4>) · [stillmoona/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:11>) · [lua_modules/constants/instance_versions.lua:42](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:42>) | Pending |
| E316 | Best Laid Plans | Mission | Temple-plan handoff unlocks the opposing contact and assistants.  Script-selected instance version 3.<br>[stillmoona/encounters/best_laid_plans.lua:15](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/best_laid_plans.lua:15>) · [stillmoona/script_init.lua:7](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:7>) · [lua_modules/constants/instance_versions.lua:43](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:43>) | Pending |
| E317 | Guardian of the Sands: Shogurei | Mission | Sand traps create servants; task stages activate guardian and Denial of Flight.  Script-selected instance version 5.<br>[stillmoona/encounters/guardian_of_the_sands.lua:129](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/guardian_of_the_sands.lua:129>) · [stillmoona/script_init.lua:17](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:17>) · [lua_modules/constants/instance_versions.lua:45](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:45>) | Pending |
| E318 | Keepers of Strength and Wisdom | Group event | Death/interaction chain releases Spirit Keeper, Sung Li and animated/ancient guardians. Consolidate both keeper branches and associated protectors; validate intended grouping.<br>[stillmoona/#The_Keeper_of_Strength.lua:1](<F:/EQ1/Bastion_Dev/quests/stillmoona/%23The_Keeper_of_Strength.lua:1>) | Pending |
| E319 | Scales of Justice | Mission | Drake attack sequence and HP-triggered behavior.  Script-selected instance version 6.<br>[stillmoona/encounters/scales_of_justice.lua:16](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/scales_of_justice.lua:16>) · [stillmoona/script_init.lua:9](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:9>) · [lua_modules/constants/instance_versions.lua:46](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:46>) | Pending |
| E320 | Sickness of the Spirit | Mission | Sequential kill/interaction stages spawn Nethran and the scroll thieves.  Script-selected instance version 1.<br>[stillmoona/encounters/sickness_of_the_spirit.lua:52](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/sickness_of_the_spirit.lua:52>) · [stillmoona/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:3>) · [lua_modules/constants/instance_versions.lua:41](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:41>) | Pending |
| E321 | Tea for Thy Master | Mission | Trigger spawns poison master, pet and assistants; pet handling and completion event.  Script-selected instance version 7.<br>[stillmoona/encounters/tea_for_thy_master.lua:1](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/tea_for_thy_master.lua:1>) · [stillmoona/script_init.lua:15](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:15>) · [lua_modules/constants/instance_versions.lua:47](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:47>) | Pending |
| E322 | Tracking the Kirin | Mission | Ordered footprint trail reveals the Elusive Kirin.  Script-selected instance version 8.<br>[stillmoona/encounters/tracking_the_kirin.lua:36](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/tracking_the_kirin.lua:36>) · [stillmoona/script_init.lua:13](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:13>) · [lua_modules/constants/instance_versions.lua:48](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:48>) | Pending |

### stillmoonb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E323 | Death Comes Swiftly: Lair Mistress | Mission | Five crawling warder deaths unlock Lair Mistress.  Script-selected instance version 3.<br>[stillmoonb/encounters/death_comes_swiftly.lua:3](<F:/EQ1/Bastion_Dev/quests/stillmoonb/encounters/death_comes_swiftly.lua:3>) · [stillmoonb/script_init.lua:7](<F:/EQ1/Bastion_Dev/quests/stillmoonb/script_init.lua:7>) · [lua_modules/constants/instance_versions.lua:52](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:52>) | Pending |
| E324 | Drake Eggs | Mission | Mission trigger populates the dead-contact ambush with guards and guardian statues.  Script-selected instance version 4.<br>[stillmoonb/encounters/drake_eggs.lua:8](<F:/EQ1/Bastion_Dev/quests/stillmoonb/encounters/drake_eggs.lua:8>) · [stillmoonb/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/stillmoonb/script_init.lua:3>) · [lua_modules/constants/instance_versions.lua:53](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:53>) | Pending |
| E325 | Kessdona's Perch | Mission | Dragon phases and active/inactive manashard guardian rotation.  Script-selected instance version 1.<br>[stillmoonb/encounters/kessdonas_perch.lua:32](<F:/EQ1/Bastion_Dev/quests/stillmoonb/encounters/kessdonas_perch.lua:32>) · [stillmoonb/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/stillmoonb/script_init.lua:5>) · [lua_modules/constants/instance_versions.lua:50](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:50>) | Pending |
| E326 | Rikkukin the Defender | Raid boss | Emote warnings precede attacks; ice protection locks HP and blindness phases. NPC filename script; confirm raid version via launcher/task data when authoring.<br>[stillmoonb/Rikkukin_the_Defender.lua:85](<F:/EQ1/Bastion_Dev/quests/stillmoonb/Rikkukin_the_Defender.lua:85>) | Pending |
| E327 | Storm Dragon Scales: The Storm Caller | Mission | Controller spawns a Storm Caller and Ascent storms after mission progression.  Script-selected instance version 5.<br>[stillmoonb/encounters/storm_dragon_scales.lua:13](<F:/EQ1/Bastion_Dev/quests/stillmoonb/encounters/storm_dragon_scales.lua:13>) · [stillmoonb/script_init.lua:9](<F:/EQ1/Bastion_Dev/quests/stillmoonb/script_init.lua:9>) · [lua_modules/constants/instance_versions.lua:54](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:54>) | Pending |

### stonebrunt

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E328 | Mountain dragon transformation | Quest boss | Dire panda transforms at 80% HP into the mountain dragon. Dragon script includes combat handling; use epic context.<br>[stonebrunt/a_dire_panda.lua:10](<F:/EQ1/Bastion_Dev/quests/stonebrunt/a_dire_panda.lua:10>) | Pending |

### swampofnohope

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E329 | Swamp dragon transformation | Quest boss | Dire leech transforms at 80% HP into the swamp dragon. Dragon script includes combat handling; use epic context.<br>[swampofnohope/a_dire_leech.lua:10](<F:/EQ1/Bastion_Dev/quests/swampofnohope/a_dire_leech.lua:10>) | Pending |

### tacvi

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E330 | Pixtt Kretv Krakxt | Raid boss | HP stages, reflections, brood waves and final AE rampage. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/pkk.lua:107](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/pkk.lua:107>) | Pending |
| E331 | Pixtt Riel Tavas | Raid boss | Door seal, construct waves, flurry and venom phases. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/prt.lua:44](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/prt.lua:44>) | Pending |
| E332 | Pixtt Xxeric Kex | Raid boss | HP-driven defenses, cleaver/rage timers and ukun waves. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/pxk.lua:54](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/pxk.lua:54>) | Pending |
| E333 | Tunat`Muram Cuu Vauax | Raid boss | Multi-phase finale reproduces prior bosses then reveals the true boss and lifedrain mechanics. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/tmcv.lua:94](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/tmcv.lua:94>) | Pending |
| E334 | Zun`Muram Kvxe Pirik | Raid boss | Four balance NPCs and timed balance stages govern boss power. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/zmkp.lua:28](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/zmkp.lua:28>) | Pending |
| E335 | Zun`Muram Mordl Delt | Raid boss | Boss splits into increasingly numerous smaller copies. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/zmmd.lua:71](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/zmmd.lua:71>) | Pending |
| E336 | Zun`Muram Shaldn Boc | Raid boss | Alternating rage/weakness changes duration as health declines. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/zmsb.lua:27](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/zmsb.lua:27>) | Pending |
| E337 | Zun`Muram Yihst Vor | Raid boss | HP damage stages, Allure timer and final rampage. Loaded by script_init.lua; helpers consolidated.<br>[tacvi/encounters/zmyv.lua:52](<F:/EQ1/Bastion_Dev/quests/tacvi/encounters/zmyv.lua:52>) | Pending |

### taka

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E338 | Takish-Hiz adventures (shared rules) | Adventure system | Shared scaled assassination/kill/collect/rescue mission controller, traps, minibosses and completion. Same system duplicated across taka-takj. Prefer one theme overview rather than 10 identical guides.<br>[taka/encounters/tak.lua:167](<F:/EQ1/Bastion_Dev/quests/taka/encounters/tak.lua:167>) | Pending |

### takc

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E339 | Queen of Sand and Ritana | Raid event | Named guardians, HP transition, split adds, bonus reward conditions and final queen. Version 2 explicitly loads in zone_status.lua:9.<br>[takc/encounters/takcraid.lua:19](<F:/EQ1/Bastion_Dev/quests/takc/encounters/takcraid.lua:19>) | Pending |

### takishruinsa

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E340 | Living Legacy: Sandkeep Endurance Raid | Survival raid | A staged survival field replaces defeated adds, measures pressure-window damage, enters recovery on a missed quota, and saves earned checkpoints until the raid fails. Verified instance version 1. Survival and sustained add control, with unkillable Eothar, rather than a conventional boss-kill victory.<br>[takishruinsa/encounters/living_legacy_endurance.lua:1](<F:/EQ1/Bastion_Dev/quests/takishruinsa/encounters/living_legacy_endurance.lua:1>) · [takishruinsa/script_init.lua:2](<F:/EQ1/Bastion_Dev/quests/takishruinsa/script_init.lua:2>) · [lua_modules/living_legacy.lua:101](<F:/EQ1/Bastion_Dev/quests/lua_modules/living_legacy.lua:101>) · [sro/35180.lua:1](<F:/EQ1/Bastion_Dev/quests/sro/35180.lua:1>) | Pending |

### templeveeshan

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E341 | Aaryonar | Raid boss | Boss and named guards are explicitly linked by combat aggro script. Only linked-aggro mechanics present here; fuller guide needs database spells.<br>[templeveeshan/#Aaryonar.lua:2](<F:/EQ1/Bastion_Dev/quests/templeveeshan/%23Aaryonar.lua:2>) | Pending |
| E342 | Lady Mirenilla | Raid boss | Boss and named guards are explicitly linked by combat aggro script. Only linked-aggro mechanics present here; fuller guide needs database spells.<br>[templeveeshan/#Lady_Mirenilla.lua:2](<F:/EQ1/Bastion_Dev/quests/templeveeshan/%23Lady_Mirenilla.lua:2>) | Pending |
| E343 | Lord Feshlak | Raid boss | Boss and named guards are explicitly linked by combat aggro script. Only linked-aggro mechanics present here; fuller guide needs database spells.<br>[templeveeshan/#Lord_Feshlak.lua:2](<F:/EQ1/Bastion_Dev/quests/templeveeshan/%23Lord_Feshlak.lua:2>) | Pending |
| E344 | Lord Kreizenn | Raid boss | Boss and named guards are explicitly linked by combat aggro script. Only linked-aggro mechanics present here; fuller guide needs database spells.<br>[templeveeshan/#Lord_Kreizenn.lua:2](<F:/EQ1/Bastion_Dev/quests/templeveeshan/%23Lord_Kreizenn.lua:2>) | Pending |
| E345 | Lord Vyemm | Raid boss | Boss and named guards are explicitly linked by combat aggro script. Only linked-aggro mechanics present here; fuller guide needs database spells.<br>[templeveeshan/#Lord_Vyemm.lua:2](<F:/EQ1/Bastion_Dev/quests/templeveeshan/%23Lord_Vyemm.lua:2>) | Pending |
| E346 | Vulak`Aerr ring event | Raid event | Thylex launches a multiwave ring with named waves and destroyer failure handling. Loaded by #Thylex_of_Veeshan.lua:58. Keep fabled lockout-only variant distinct.<br>[templeveeshan/encounters/Vulak_Event.lua:45](<F:/EQ1/Bastion_Dev/quests/templeveeshan/encounters/Vulak_Event.lua:45>) | Pending |

### tenebrous

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E347 | Hoober | Quest event | Subdue to 20% HP, then use the elixir during a five-minute noncombat window. <br>[tenebrous/Hoober.lua:76](<F:/EQ1/Bastion_Dev/quests/tenebrous/Hoober.lua:76>) | Pending |
| E348 | Johanius Barleou | Quest event | Triggered transformation/escort encounter includes family NPCs and Slayer follow-up. Loaded from #Johanius_Barleou.lua:27.<br>[tenebrous/encounters/Johanius.lua:1](<F:/EQ1/Bastion_Dev/quests/tenebrous/encounters/Johanius.lua:1>) | Pending |

### thedeep

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E349 | Deklean Korgad: invisible bridge | Escort event | Turn-in starts a specific escort route with bridge beacons. Noncombat navigation event; optional journal scope.<br>[thedeep/Deklean_Korgad.lua:29](<F:/EQ1/Bastion_Dev/quests/thedeep/Deklean_Korgad.lua:29>) | Pending |
| E350 | The Burrower Beast | Raid event | Death launches a timed burrower wave sequence ending in parasite stages. Do not confuse with orphan encounters/Burrower.lua using unrelated 154xxx NPC IDs.<br>[thedeep/The_Burrower_Beast.lua:9](<F:/EQ1/Bastion_Dev/quests/thedeep/The_Burrower_Beast.lua:9>) | Pending |
| E351 | Thought Horror Overfiend (Seasonal) | Raid boss | HP phases change horror, rage and magic-vulnerability behavior. Resolve seasonal registry/version centrally.<br>[thedeep/Thought_Horror_Overfiend.lua:64](<F:/EQ1/Bastion_Dev/quests/thedeep/Thought_Horror_Overfiend.lua:64>) | Pending |

### thenest

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E352 | A Failed Expedition: Shargone | Mission | Kill threshold awakens Shargone the Ice Drake.  Script-selected instance version 8.<br>[thenest/encounters/lost_comrades.lua:4](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/lost_comrades.lua:4>) · [thenest/script_init.lua:9](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:9>) · [lua_modules/constants/instance_versions.lua:93](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:93>) | Pending |
| E353 | Circle of Drakes | Mission | Drake magus wave controller and combat activation.  Script-selected instance version 4.<br>[thenest/encounters/circle_of_drakes.lua:98](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/circle_of_drakes.lua:98>) · [thenest/script_init.lua:15](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:15>) · [lua_modules/constants/instance_versions.lua:89](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:89>) | Pending |
| E354 | In the Shadows: Vishimtar | Mission | Eggs, incorporeal adds, HP lock and curse/lifedrain mechanics. Includes seasonal task variation; keep task-specific differences explicit. Script-selected instance version 3.<br>[thenest/encounters/in_the_shadows.lua:73](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/in_the_shadows.lua:73>) · [thenest/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:3>) · [lua_modules/constants/instance_versions.lua:88](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:88>) | Pending |
| E355 | Rampaging Monolith | Named encounter | HP thresholds split off debris and rubble adds. Named script is independent of mission modules.<br>[thenest/Rampaging_Monolith.lua:7](<F:/EQ1/Bastion_Dev/quests/thenest/Rampaging_Monolith.lua:7>) | Pending |
| E356 | Rivals | Mission | Faction-specific rival party, scripted negotiation/fighting and Silverclaw interaction.  Script-selected instance version 11.<br>[thenest/encounters/rivals.lua:126](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/rivals.lua:126>) · [thenest/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:11>) · [lua_modules/constants/instance_versions.lua:96](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:96>) | Pending |
| E357 | T'Shara | Raid boss | Aspect adds alter the main encounter and respawn through timed stages. Default script_init branch, outside named mission variants.<br>[thenest/encounters/tshara.lua:42](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/tshara.lua:42>) · [thenest/script_init.lua:25](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:25>) | Pending |
| E358 | The Curse of Jurek | Mission | Repeating HP stages summon harsh winds and control the encounter.  Script-selected instance version 6.<br>[thenest/encounters/the_curse_of_jurek.lua:3](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/the_curse_of_jurek.lua:3>) · [thenest/script_init.lua:23](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:23>) · [lua_modules/constants/instance_versions.lua:91](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:91>) | Pending |
| E359 | Web of Lies | Mission | Negotiation, weapon check and faction-specific combat outcomes.  Script-selected instance version 15.<br>[thenest/encounters/web_of_lies.lua:1](<F:/EQ1/Bastion_Dev/quests/thenest/encounters/web_of_lies.lua:1>) · [thenest/script_init.lua:7](<F:/EQ1/Bastion_Dev/quests/thenest/script_init.lua:7>) · [lua_modules/constants/instance_versions.lua:100](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:100>) | Pending |

### thundercrest

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E360 | A Simple Task | Mission | Timed flower decay and courier spawning make the retrieval mission time-sensitive.  Script-selected instance version 8.<br>[thundercrest/encounters/simple_task.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/simple_task.lua:1>) · [thundercrest/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:11>) · [lua_modules/constants/instance_versions.lua:64](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:64>) | Pending |
| E361 | An End to the Storms: Yar`Lir | Raid event | Dragon changes storm forms and spawns form-specific adds. Includes explicit seasonal task IDs 401017/401018. Script-selected instance version 3.<br>[thundercrest/encounters/an_end_to_the_storms.lua:23](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/an_end_to_the_storms.lua:23>) · [thundercrest/script_init.lua:29](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:29>) · [lua_modules/constants/instance_versions.lua:59](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:59>) | Pending |
| E362 | Behind Closed Doors | Mission | Door interactions release two generals and their sentries.  Script-selected instance version 4.<br>[thundercrest/encounters/behind_closed_doors.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/behind_closed_doors.lua:1>) · [thundercrest/script_init.lua:21](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:21>) · [lua_modules/constants/instance_versions.lua:60](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:60>) | Pending |
| E363 | Four Corners | Raid event | Four bosses form one open-world raid with coordinated completion/reset. Default open-world branch in script_init.<br>[thundercrest/encounters/four_corners.lua:12](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/four_corners.lua:12>) · [thundercrest/script_init.lua:31](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:31>) | Pending |
| E364 | Holy Hour | Mission | Timed departures reduce available enemies and can fail the mission.  Script-selected instance version 2.<br>[thundercrest/encounters/holy_hour.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/holy_hour.lua:1>) · [thundercrest/script_init.lua:7](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:7>) · [lua_modules/constants/instance_versions.lua:58](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:58>) | Pending |
| E365 | House of the Autumn Rose | Mission | Combat triggers drop assassin ambushes at distinct temple locations.  Script-selected instance version 5.<br>[thundercrest/encounters/house_of_the_autumn_rose.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/house_of_the_autumn_rose.lua:1>) · [thundercrest/script_init.lua:23](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:23>) · [lua_modules/constants/instance_versions.lua:61](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:61>) | Pending |
| E366 | Lair Unguarded | Mission | Locked-gate progression with Claws, Wings and Breath of Yar`Lir and knockback checks. Source labels spawn-location list possibly incomplete. Script-selected instance version 6.<br>[thundercrest/encounters/lair_unguarded.lua:5](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/lair_unguarded.lua:5>) · [thundercrest/script_init.lua:9](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:9>) · [lua_modules/constants/instance_versions.lua:62](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:62>) | Pending |
| E367 | Lore Warden | Raid event | Messenger drakes, summoned guardians, signal progression and time limit. Includes Guardian_of_the_Past.lua, Sentinel_of_Antiquity.lua, Messenger_Drake.lua.<br>[thundercrest/#Lore_Warden.lua:7](<F:/EQ1/Bastion_Dev/quests/thundercrest/%23Lore_Warden.lua:7>) | Pending |
| E368 | Plunder the Hoard | Mission | Guardian of the Blades cycles movement/equipment/combat with a half-health transition.  Script-selected instance version 13.<br>[thundercrest/encounters/plunder_the_hoard.lua:25](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/plunder_the_hoard.lua:25>) · [thundercrest/script_init.lua:25](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:25>) · [lua_modules/constants/instance_versions.lua:69](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:69>) | Pending |
| E369 | Scions of Thundercrest | Mission | Three tome searches and spoken phrases activate Wind, Sky and Storm scions.  Script-selected instance version 7.<br>[thundercrest/encounters/scions_of_thundercrest.lua:35](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/scions_of_thundercrest.lua:35>) · [thundercrest/script_init.lua:15](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:15>) · [lua_modules/constants/instance_versions.lua:63](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:63>) | Pending |
| E370 | Splitting the Storm | Mission | Escort Lila with owner-follow state and captor spawns.  Script-selected instance version 9.<br>[thundercrest/encounters/splitting_the_storm.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/splitting_the_storm.lua:1>) · [thundercrest/script_init.lua:17](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:17>) · [lua_modules/constants/instance_versions.lua:65](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:65>) | Pending |
| E371 | Storm Chasers / Raging Thunderhead | Raid event | Storm Chaser deaths spawn Thunderheads and Raging Thunderhead; Thunderheads split into Stormfronts. Consolidated weather-creature chain.<br>[thundercrest/Storm_Chaser.lua:1](<F:/EQ1/Bastion_Dev/quests/thundercrest/Storm_Chaser.lua:1>) | Pending |
| E372 | Stormreach Challenge: Goblin Dojo | Raid event | Multi-stage timed trials of elements, emotions, animals, steel/silk and sensei. Source explicitly models live retry behavior; verify intended behavior in guide. Script-selected instance version 10.<br>[thundercrest/encounters/goblin_dojo.lua:12](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/goblin_dojo.lua:12>) · [thundercrest/script_init.lua:27](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:27>) · [lua_modules/constants/instance_versions.lua:66](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:66>) | Pending |
| E373 | Throes of Contagion | Mission | Three toxin stages culminate in a timed evacuation; failure triggers Poison Cloud. Loaded by script_init.lua:19.<br>[thundercrest/encounters/throes_of_contagion.lua:45](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/throes_of_contagion.lua:45>) | Pending |

### timorous

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E374 | Draz Nurakk and his images | Quest event | Triggered encounter creates six images including one explicitly unslowable variant. <br>[timorous/Draz_Nurakk.lua:15](<F:/EQ1/Bastion_Dev/quests/timorous/Draz_Nurakk.lua:15>) | Pending |
| E375 | Gefaari Drokaz | Quest boss | Multiple HP phases summon Zordak minions. Triggered by Omat_Vastsea.lua:111; epic encounter.<br>[timorous/#Gefaari_Drokaz.lua:15](<F:/EQ1/Bastion_Dev/quests/timorous/%23Gefaari_Drokaz.lua:15>) | Pending |

### tipt

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E376 | Tipt mountain trials / Kyv Heartstriker Jhiru | Group expedition | Cragbeast trial, ghost section, riddler penalty waves and final boss progression. Consolidate ghosts.lua, #Rikabi_the_Riddler.lua and #Kyv_Heartstriker_Jhiru.lua; module load verified in script_init.<br>[tipt/encounters/cragbeasts.lua:1](<F:/EQ1/Bastion_Dev/quests/tipt/encounters/cragbeasts.lua:1>) | Pending |

### torgiran

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E377 | Overseer Wrank and the Captains | Group event | Controller spawns four captains then transitions Wrank on signals. Captains are helpers in this sequence.<br>[torgiran/#wrank_trigger.lua:1](<F:/EQ1/Bastion_Dev/quests/torgiran/%23wrank_trigger.lua:1>) | Pending |
| E378 | Taskmaster Lugald Brokenskull | Group event | Overlord death counter unlocks Taskmaster. Short unlock sequence, lower priority than fully phased bosses.<br>[torgiran/#overlord_counter.lua:1](<F:/EQ1/Bastion_Dev/quests/torgiran/%23overlord_counter.lua:1>) | Pending |

### txevu

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E379 | Ancient Cragbeast Matriarch | Raid event | Hatchling waves, AE casts and melee mitigation/ability changes. Loaded by script_init.lua; zone_status.lua spawns controller.<br>[txevu/encounters/acm.lua:49](<F:/EQ1/Bastion_Dev/quests/txevu/encounters/acm.lua:49>) | Pending |
| E380 | High Priest Nkosi Bakari | Raid event | Chanter/ritualist progression with charm and final priest. Loaded by script_init.lua; zone_status.lua spawns controller.<br>[txevu/encounters/highpriest.lua:98](<F:/EQ1/Bastion_Dev/quests/txevu/encounters/highpriest.lua:98>) | Pending |
| E381 | Ikaav Nysf Lleiv | Raid boss | HP thresholds summon Onyx Rockchanters and advance boss phases. Spawned by zone_status.lua:76.<br>[txevu/#Ikaav_Nysf_Lleiv.lua:84](<F:/EQ1/Bastion_Dev/quests/txevu/%23Ikaav_Nysf_Lleiv.lua:84>) | Pending |
| E382 | Mastruq Champion / Ixt Hsek Syat | Raid event | Arena sequence including the Runt, champions and Ixt finale. Loaded by script_init.lua; zone_status.lua spawns controller.<br>[txevu/encounters/champ.lua:64](<F:/EQ1/Bastion_Dev/quests/txevu/encounters/champ.lua:64>) | Pending |
| E383 | Ukun Bloodfeaster | Raid event | Staged mauler/stonemite adds, immunity gates and timed reset. Loaded by script_init.lua; zone_status.lua spawns controller.<br>[txevu/encounters/bloodfeaster.lua:25](<F:/EQ1/Bastion_Dev/quests/txevu/encounters/bloodfeaster.lua:25>) | Pending |
| E384 | Zun`Muram Tkarish Zyk | Raid boss | Top-threat banish, ritualist waves, ghost jailers and linked combat. Include jail/inquisitor/ritualist helpers.<br>[txevu/#Zun-Muram_Tkarish_Zyk.lua:13](<F:/EQ1/Bastion_Dev/quests/txevu/%23Zun-Muram_Tkarish_Zyk.lua:13>) | Pending |

### uqua

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E385 | Tqiv Araxt the Enraged and Tqiv Qukret the Furious | Raid event | Each side summons rage/fury constructs and unlocks orb/specter progression. Include #Tqiv_Qukret_the_Furious.lua and associated constructs/specters.<br>[uqua/#Tqiv_Araxt_the_Enraged.lua:17](<F:/EQ1/Bastion_Dev/quests/uqua/%23Tqiv_Araxt_the_Enraged.lua:17>) | Pending |
| E386 | Uqua gas chambers | Raid puzzle | Two timed gas-chamber puzzles with flame and trap penalties. Part of Uqua expedition; combine duplicate chamber variants.<br>[uqua/#Gas_Chamber_1.lua:1](<F:/EQ1/Bastion_Dev/quests/uqua/%23Gas_Chamber_1.lua:1>) | Pending |
| E387 | Vrex Barxt Qurat and Guardian of Destruction | Raid event | Guardian HP transition activates Vrex, with aura healing, summoners and staged bonded mechanics. Finale; module registered in script_init.lua.<br>[uqua/encounters/vrex.lua:28](<F:/EQ1/Bastion_Dev/quests/uqua/encounters/vrex.lua:28>) | Pending |

### veeshan

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E388 | Phara Dar and the Ring of Scale | Raid event | Five dragon deaths remove Phara Dar immunity; HP phases summon protectors. NPC script comment labels Phara Dar 2.0; that is a content name, not verified instance version.<br>[veeshan/108048.lua:6](<F:/EQ1/Bastion_Dev/quests/veeshan/108048.lua:6>) | Pending |

### veksar

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E389 | Decaying Lord Galuk Drek | Named encounter | Repeated Darkened Decay spell timer during combat. Compact mechanics; full NPC stats are database-backed.<br>[veksar/Decaying_Lord_Galuk_Drek.lua:1](<F:/EQ1/Bastion_Dev/quests/veksar/Decaying_Lord_Galuk_Drek.lua:1>) | Pending |
| E390 | Raging Bloodgill | Group event | Bloodgill warrior HP trigger starts a chance-based, instance-scoped raging encounter. Use both warrior and shaman helpers to describe spawn sequence.<br>[veksar/#a_bloodgill_warrior.lua:5](<F:/EQ1/Bastion_Dev/quests/veksar/%23a_bloodgill_warrior.lua:5>) | Pending |

### vexthal

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E391 | Aten Ha Ra progression / Akhevan Warders | Raid sequence | Boss deaths remove specific warders and trigger the valid Aten variant. Consolidated progression overview; named boss files mostly provide warder cleanup instead of distinctive attacks.<br>[vexthal/#aten_trigger.lua:11](<F:/EQ1/Bastion_Dev/quests/vexthal/%23aten_trigger.lua:11>) | Pending |

### vxed

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E392 | Vxed trial / Stonespiritist Ekikoa | Group expedition | Zone death counter unlocks Ekikoa and trial completion. Loaded by script_init.lua.<br>[vxed/encounters/vxed.lua:1](<F:/EQ1/Bastion_Dev/quests/vxed/encounters/vxed.lua:1>) | Pending |

### wakening

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E393 | Cristoc Bonethug | Quest event | Cristoc spawns five mercenaries and controls their linked aggression. Triggered by #Lantaric-Dar.lua.<br>[wakening/Cristoc_Bonethug.lua:1](<F:/EQ1/Bastion_Dev/quests/wakening/Cristoc_Bonethug.lua:1>) | Pending |
| E394 | Dark Disciple Master | Quest boss | HP phases summon Plavo remains and additional disciples. Triggered by Scout_Leader_Plavo.lua:50.<br>[wakening/Dark_Disciple_Master.lua:14](<F:/EQ1/Bastion_Dev/quests/wakening/Dark_Disciple_Master.lua:14>) | Pending |
| E395 | Wuoshi (Seasonal) | Raid boss | Large custom phased dragon script with combat state, queued HP gates and encounter resets. Resolve seasonal registry/version centrally.<br>[wakening/Wuoshi.lua:773](<F:/EQ1/Bastion_Dev/quests/wakening/Wuoshi.lua:773>) | Pending |

### wallofslaughter

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E396 | Discordling Spiritcaller | Quest boss | Feran adds split into chimeras; combat/death signals coordinate the event. Spawned by #Hudar_Trittan.lua:23.<br>[wallofslaughter/#Discordling_Spiritcaller.lua:1](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/%23Discordling_Spiritcaller.lua:1>) | Pending |
| E397 | Durunal the Cursebearer | Quest boss | Triggered boss spawns three named lightning elementals. Player.lua contains epic trigger.<br>[wallofslaughter/#Durunal_the_Cursebearer.lua:7](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/%23Durunal_the_Cursebearer.lua:7>) | Pending |
| E398 | Lightning Lord | Quest boss | Multiple health thresholds summon loyalists after nervous-guardian trigger. Druid epic 2.0 trap/Retired Soldier chain.<br>[wallofslaughter/#Lightning_Lord.lua:8](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/%23Lightning_Lord.lua:8>) | Pending |
| E399 | Murkglider Hivequeen | Quest boss | Combat spawns murkglider hatchling waves. Epic encounter; egg/hatchling scripts belong to same guide.<br>[wallofslaughter/#The_Murkglider_Hivequeen.lua:1](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/%23The_Murkglider_Hivequeen.lua:1>) | Pending |
| E400 | Pyrique Redwing | Raid boss | Splits into six shadows, shuffles them and requires finding the real one. Loaded by script_init.lua:1.<br>[wallofslaughter/encounters/redwing.lua:45](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/encounters/redwing.lua:45>) | Pending |
| E401 | Tarn Icewind | Raid boss | Portal-crystal setup gates combat and the boss calls reinforcements. Include Tarn_Icewind_Spawner.lua and portal crystal helpers.<br>[wallofslaughter/#Tarn_Icewind.lua:40](<F:/EQ1/Bastion_Dev/quests/wallofslaughter/%23Tarn_Icewind.lua:40>) | Pending |

### warslikswood

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E402 | Raving Goblinmaster | Quest event | Triggered dialogue challenge summons successive frantic goblin waves. Includes #Raving_Goblinmaster.lua finale and a_frantic_goblin.lua signals.<br>[warslikswood/Raving_Goblinmaster.lua:55](<F:/EQ1/Bastion_Dev/quests/warslikswood/Raving_Goblinmaster.lua:55>) | Pending |

### westkorlach

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E403 | Grokui, the Slumbering Basilisk | Raid boss | HP phases repeatedly heal the boss while escalating melee damage. Combat loss resets HP and damage.<br>[westkorlach/Grokui,_the_Slumbering_Basilisk.lua:14](<F:/EQ1/Bastion_Dev/quests/westkorlach/Grokui%2C_the_Slumbering_Basilisk.lua:14>) | Pending |

### westkorlacha

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E404 | Matriarch Shyra | Raid boss | Shyra and protector/enforcer adds share aggro and a distance tether. Loaded by script_init.lua.<br>[westkorlacha/encounters/Shyra.lua:1](<F:/EQ1/Bastion_Dev/quests/westkorlacha/encounters/Shyra.lua:1>) | Pending |

### westkorlachb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E405 | Find Fibblebrap 2: Lost Caverns | Mission | Task-stage controller with Captain Therimel and Therigal scholar dialogue/progression. Version 1 and supported task levels documented in source header; initializer checks version constant.<br>[westkorlachb/encounters/find_fibblebrap_two.lua:71](<F:/EQ1/Bastion_Dev/quests/westkorlachb/encounters/find_fibblebrap_two.lua:71>) | Pending |

### westwastes

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E406 | Disciple of Moon | Quest boss | Epic proximity trap spawns the disciple, with a 30-minute combat deadline. <br>[westwastes/Disciple_of_Moon.lua:1](<F:/EQ1/Bastion_Dev/quests/westwastes/Disciple_of_Moon.lua:1>) | Pending |
| E407 | Klandicar (Seasonal) | Raid boss | Custom combat state and phased HP transitions with reset handling. Resolve seasonal registry/version centrally.<br>[westwastes/Klandicar.lua:620](<F:/EQ1/Bastion_Dev/quests/westwastes/Klandicar.lua:620>) | Pending |
| E408 | Scout Charisa: Kromzek Captain | Quest event | Scout-triggered encounter spawns a captain and seven warriors. Explicit loader Scout_Charisa.lua:13; compact quest ambush.<br>[westwastes/encounters/Scout_Charisa.lua:1](<F:/EQ1/Bastion_Dev/quests/westwastes/encounters/Scout_Charisa.lua:1>) | Pending |

### yxtta

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| E409 | Hexxt Huntmaster | Named encounter | HP-triggered archery volley with combat/reset behavior. Shares generic Kyv mechanic; lower priority.<br>[yxtta/#Hexxt_Huntmaster.lua:12](<F:/EQ1/Bastion_Dev/quests/yxtta/%23Hexxt_Huntmaster.lua:12>) | Pending |
| E410 | Pixtt Suir Mindrider | Raid boss | Repeated HP thresholds cast Curse of the Ikaav on multiple random targets. Room boundary and reset also scripted.<br>[yxtta/#Pixtt_Suir_Mindrider.lua:17](<F:/EQ1/Bastion_Dev/quests/yxtta/%23Pixtt_Suir_Mindrider.lua:17>) | Pending |
| E411 | Primal door riddle | Raid puzzle | Ordered riddle-door answers open access; mistakes summon stone attackers. Supports raid-zone guide; do not publish fixed answer order because source randomizes it.<br>[yxtta/player.lua:75](<F:/EQ1/Bastion_Dev/quests/yxtta/player.lua:75>) | Pending |
| E412 | Xounii Shifter / Tqiv Trusik Fanatic | Named encounter | Roaming disguises change at 50% and 15% HP. Source explicitly identifies both names.<br>[yxtta/#Tqiv_Trusik_Fanatic.lua:27](<F:/EQ1/Bastion_Dev/quests/yxtta/%23Tqiv_Trusik_Fanatic.lua:27>) | Pending |

## Needs investigation


### acrylia

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R001 | Burrower | raid | Acrylia initializer loads Burrower; a misplaced module under thedeep contains154xxx NPC registrations and life-cycle phases. No matching local/global module is in Acrylia. The implementation under thedeep is not proven to be loaded here; resolve routing before documenting.<br>[acrylia/script_init.lua:1](<F:/EQ1/Bastion_Dev/quests/acrylia/script_init.lua:1>) · [thedeep/encounters/Burrower.lua:151](<F:/EQ1/Bastion_Dev/quests/thedeep/encounters/Burrower.lua:151>) | Pending |

### blackburrow

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R002 | Blackburrow invasion (Chunky / king event) | event | Zone-wide kill progression drives enraged/chief/warlord/king stages and scaling. Explicitly GM-started in player.lua; event availability and intended title need owner approval.<br>[blackburrow/encounters/bbevent.lua:66](<F:/EQ1/Bastion_Dev/quests/blackburrow/encounters/bbevent.lua:66>) · [blackburrow/player.lua:4](<F:/EQ1/Bastion_Dev/quests/blackburrow/player.lua:4>) | Pending |

### corathusb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R003 | Find Fibblebrap5 | mission | Separate instance selector loads another Fibblebrap mission with objective handlers. Header erroneously repeats Find Fibblebrap1; verify mission title and stage data before publication.<br>[corathusb/encounters/find_fibblebrap_five.lua:48](<F:/EQ1/Bastion_Dev/quests/corathusb/encounters/find_fibblebrap_five.lua:48>) · [corathusb/script_init.lua:4](<F:/EQ1/Bastion_Dev/quests/corathusb/script_init.lua:4>) | Pending |

### dreadlands

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R004 | The Turkey King / Tainted Turkey invasion | event | Thanksgiving controller escalates turkey populations into captain/god spawns. Holiday availability is not established by quest source alone; owner should confirm intended availability.<br>[dreadlands/The_Turkey_King.lua:17](<F:/EQ1/Bastion_Dev/quests/dreadlands/The_Turkey_King.lua:17>) · [dreadlands/The_Turkey_King.lua:65](<F:/EQ1/Bastion_Dev/quests/dreadlands/The_Turkey_King.lua:65>) | Pending |

### dreadspire

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R005 | Hatchet the Torturer | raid | Has partial HP-lock/trap code and extensive reference logs. Loader registers undefined Hanvar_Spawn/Hanvar_Combat/Hanvar_Timer instead of Hatchet functions; mechanics are incomplete and cannot be presented as implemented.<br>[dreadspire/encounters/Hatchet.lua:87](<F:/EQ1/Bastion_Dev/quests/dreadspire/encounters/Hatchet.lua:87>) · [dreadspire/encounters/Hatchet.lua:113](<F:/EQ1/Bastion_Dev/quests/dreadspire/encounters/Hatchet.lua:113>) · [dreadspire/script_init.lua:3](<F:/EQ1/Bastion_Dev/quests/dreadspire/script_init.lua:3>) | Pending |

### gukg

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R006 | The Cavern Creeper | raid | Raid boss progression and health-stage emotes are present. Health handlers explicitly question missing functional effects; do not turn emote claims into actual mechanics.<br>[gukg/encounters/gukgraid.lua:221](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:221>) · [gukg/encounters/gukgraid.lua:229](<F:/EQ1/Bastion_Dev/quests/gukg/encounters/gukgraid.lua:229>) · [gukg/zone_status.lua:8](<F:/EQ1/Bastion_Dev/quests/gukg/zone_status.lua:8>) | Pending |

### ikkinz

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R007 | Chambers of Singular Might | group trial | Named trial completion and instance-specific access are present. Combat details are thin in Lua; confirm database abilities before a detailed guide.<br>[ikkinz/Pixtt_Annihilator.lua:1](<F:/EQ1/Bastion_Dev/quests/ikkinz/Pixtt_Annihilator.lua:1>) · [ikkinz/player.lua:17](<F:/EQ1/Bastion_Dev/quests/ikkinz/player.lua:17>) | Pending |

### illsalinb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R008 | Find Fibblebrap 4: The Korlach | mission | Open-world cocoon rescue and instance scaling, death progress and exit hooks are present. Instance script has TODO Create NPC for Bilitan (349999); verify completeness.<br>[illsalin/encounters/find_fibblebrap_four.lua:37](<F:/EQ1/Bastion_Dev/quests/illsalin/encounters/find_fibblebrap_four.lua:37>) · [illsalinb/encounters/find_fribblebrap_four.lua:14](<F:/EQ1/Bastion_Dev/quests/illsalinb/encounters/find_fribblebrap_four.lua:14>) · [illsalinb/script_init.lua:4](<F:/EQ1/Bastion_Dev/quests/illsalinb/script_init.lua:4>) | Pending |

### inktuta

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R009 | Mimezpo the Oracle | raid boss | Named spawns a guard group and has a raid death transition. Limited combat logic in Lua; inspect database abilities before a detailed guide.<br>[inktuta/#Mimezpo_the_Oracle.lua:1](<F:/EQ1/Bastion_Dev/quests/inktuta/%23Mimezpo_the_Oracle.lua:1>) | Pending |

### kedge

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R010 | Seasons: Watery Death (launcher only) | Seasonal expedition | The seasonal launcher defines a Kedge expedition, but the zone's five quest scripts contain epic quest NPC handlers and no matching seasonal boss controller. Check database spawn and ability definitions before documenting a boss. The launcher alone does not establish encounter identity or mechanics.<br>[global/Theta_Sigma.lua:22](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:22>) | Pending |

### kodtaz

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R011 | Ba'Zrath, the Bound Lich | prototype | Proposed weakness/challenge boss exists as an encounter file. No loader/registration found; placeholder NPC IDs and undefined npc references. Not ready for player guide.<br>[kodtaz/encounters/lich.lua:31](<F:/EQ1/Bastion_Dev/quests/kodtaz/encounters/lich.lua:31>) · [kodtaz/encounters/lich.lua:91](<F:/EQ1/Bastion_Dev/quests/kodtaz/encounters/lich.lua:91>) | Pending |

### lakeofillomen

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R012 | Scorpion hatchling ambush | epic event | Quest-qualified death trigger spawns a large scorpion ring. Unusual trigger name needs an NPC identity check before a player-facing title is chosen.<br>[lakeofillomen/#_.lua:12](<F:/EQ1/Bastion_Dev/quests/lakeofillomen/%23_.lua:12>) | Pending |

### mischiefplane

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R013 | Chess event | prototype | Chess wave design and partial registration skeleton exist. Empty mob lists, TODO king and loot, no current loader found.<br>[mischiefplane/encounters/Chess_Event.lua:3](<F:/EQ1/Bastion_Dev/quests/mischiefplane/encounters/Chess_Event.lua:3>) · [mischiefplane/encounters/Chess_Event.lua:49](<F:/EQ1/Bastion_Dev/quests/mischiefplane/encounters/Chess_Event.lua:49>) | Pending |

### potorment

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R014 | Baraguj Szuul mouth event | event | Mouth trigger populates a multi-level gauntlet and final Baraguj state. Legacy Perl trigger coexists with Lua NPCs; entry/completion wiring needs review.<br>[potorment/mouth_trigger.pl:1](<F:/EQ1/Bastion_Dev/quests/potorment/mouth_trigger.pl:1>) · [potorment/Baraguj_Szuul.lua:5](<F:/EQ1/Bastion_Dev/quests/potorment/Baraguj_Szuul.lua:5>) | Pending |
| R015 | Keeper of Sorrows / Tylis' torment | raid event | Tylis activates a sequence ending in Keeper, whose death frees Tylis. Associated chamber NPC wiring needs review before a full guide.<br>[potorment/Tylis_Newleaf.lua:10](<F:/EQ1/Bastion_Dev/quests/potorment/Tylis_Newleaf.lua:10>) · [potorment/The_Keeper_of_Sorrows.lua:5](<F:/EQ1/Bastion_Dev/quests/potorment/The_Keeper_of_Sorrows.lua:5>) | Pending |

### powar

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R016 | Turkey Zek the Gobble Lord | prototype | Holiday raid design and partial directional attacks, collection barrier and HP handlers exist. NPC IDs all placeholder 123456, reward TBD and loader not established.<br>[powar/encounters/turkey.lua:31](<F:/EQ1/Bastion_Dev/quests/powar/encounters/turkey.lua:31>) · [powar/encounters/turkey.lua:63](<F:/EQ1/Bastion_Dev/quests/powar/encounters/turkey.lua:63>) | Pending |

### sleeper

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R017 | Awakening the Sleeper | Raid event | Wardstone controls the sleeper; sleeper swaps to Kerafyrm and scripted departure. Legacy variant; confirm intended availability versus seasonal/Fabled variants.<br>[sleeper/#The_Sleeper.lua:1](<F:/EQ1/Bastion_Dev/quests/sleeper/%23The_Sleeper.lua:1>) | Pending |

### stillmoona

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R018 | Trial of Perseverance | Mission | Timed gong defense against replenishing goblins followed by a warlord. Goblin_Spawn is defined twice (lines30,72); timer initialization is overwritten. Verify/fix before describing exact failure timing. Script-selected instance version 9.<br>[stillmoona/encounters/perseverance.lua:34](<F:/EQ1/Bastion_Dev/quests/stillmoona/encounters/perseverance.lua:34>) · [stillmoona/script_init.lua:5](<F:/EQ1/Bastion_Dev/quests/stillmoona/script_init.lua:5>) · [lua_modules/constants/instance_versions.lua:49](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:49>) | Pending |

### stillmoonb

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R019 | Sudden Tremors: Ancient Golem | Mission | Runed chest controls golem earthquake/emote stage. Source notes mission may become incomplete if golem dies before chest opens; verify task gates. Script-selected instance version 6.<br>[stillmoonb/encounters/sudden_tremors.lua:4](<F:/EQ1/Bastion_Dev/quests/stillmoonb/encounters/sudden_tremors.lua:4>) · [stillmoonb/script_init.lua:11](<F:/EQ1/Bastion_Dev/quests/stillmoonb/script_init.lua:11>) · [lua_modules/constants/instance_versions.lua:55](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:55>) | Pending |

### tenebrous

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R020 | Disciple of Focus | Quest boss | Monk challenge and intended recurring Quivering Nightmares mechanic. Combat starts timer cast but handler checks startcast; verify/fix actual spell behavior.<br>[tenebrous/#Disciple_of_Focus.lua:27](<F:/EQ1/Bastion_Dev/quests/tenebrous/%23Disciple_of_Focus.lua:27>) | Pending |

### thundercrest

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R021 | The Creator | Mission | Defunct sentry kills and Identify reveal Creator Kro`val and sentinel guards. Source warns lower difficulty NPC IDs require work; verify supported difficulty. Script-selected instance version 11.<br>[thundercrest/encounters/the_creator.lua:11](<F:/EQ1/Bastion_Dev/quests/thundercrest/encounters/the_creator.lua:11>) · [thundercrest/script_init.lua:13](<F:/EQ1/Bastion_Dev/quests/thundercrest/script_init.lua:13>) · [lua_modules/constants/instance_versions.lua:67](<F:/EQ1/Bastion_Dev/quests/lua_modules/constants/instance_versions.lua:67>) | Pending |

### unassigned

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R022 | Greater Rift system (unwired module) | Prototype / reusable module | The module implements timed kill goals, boss completion, scaling and rewards, but the repository scan found no external require, OfferRift or RegisterProfile call sites. Its Lower Guk example is commented out. Needs an actual configured zone and launcher before a player-facing encounter document is useful. No active Greater Rift event is inferred from the example.<br>[lua_modules/greater_rift.lua:1](<F:/EQ1/Bastion_Dev/quests/lua_modules/greater_rift.lua:1>) · [lua_modules/greater_rift.lua:461](<F:/EQ1/Bastion_Dev/quests/lua_modules/greater_rift.lua:461>) · [lua_modules/greater_rift.lua:757](<F:/EQ1/Bastion_Dev/quests/lua_modules/greater_rift.lua:757>) | Pending |

### unrest

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R023 | Wilhavyn Estate / Master Operative | GM event | Estate party, guards, Wilhavyn family and disguised spies lead to phased Master Operative. Header says GM-Unrest content-flag event; controller spawn logic is partly commented and notes unfinished spawning. Confirm launch path and intended publication.<br>[unrest/event_controller.lua:24](<F:/EQ1/Bastion_Dev/quests/unrest/event_controller.lua:24>) | Pending |

### vexthal

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| R024 | NPC 158006: teleport hunt | Scripted boss | 40% and 20% HP transitions teleport, heal and hide the boss with one-hour hunt timers. NPC display name not present in this script; resolve database identity before approval.<br>[vexthal/158006.lua:26](<F:/EQ1/Bastion_Dev/quests/vexthal/158006.lua:26>) | Pending |

## Already documented


### mistmoore

| ID | Encounter / event | Type | Script evidence and scope | Decision |
| --- | --- | --- | --- | --- |
| J001 | Cantor Selindra | raid boss | Existing journal derived from current Mistmoore Eclipse scripts. Already documented: cantor-selindra<br>[mistmoore/encounters/mistmoore_eclipse.lua:1](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:1>) | Already included |
| J002 | Castellan Veyr | raid boss | Existing journal derived from current Mistmoore Eclipse scripts. Already documented: castellan-veyr<br>[mistmoore/encounters/mistmoore_eclipse.lua:1](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:1>) | Already included |
| J003 | Gravekeeper Vhal | raid boss | Existing journal derived from current Mistmoore Eclipse scripts. Already documented: gravekeeper-vhal<br>[mistmoore/encounters/mistmoore_eclipse.lua:1](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:1>) | Already included |
| J004 | Huntmaster Draeven | raid boss | Existing journal derived from current Mistmoore Eclipse scripts. Already documented: huntmaster-draeven<br>[mistmoore/encounters/mistmoore_eclipse.lua:1](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:1>) | Already included |
| J005 | Mayong Mistmoore | raid boss | Existing journal derived from current Mistmoore Eclipse scripts. Already documented: last-eclipse-finale<br>[mistmoore/encounters/mistmoore_eclipse.lua:1](<F:/EQ1/Bastion_Dev/quests/mistmoore/encounters/mistmoore_eclipse.lua:1>) | Already included |

## Shared activity targets

The Bonded Hunts candidate represents one shared controller with these 36 target choices:

| Zone | Target |
| --- | --- |
| hole | Tzitzi the Crazed |
| gukbottom | the ghoul lord |
| oot | Allizewsaur |
| jaggedpine | GoldenTalon |
| veksar | an undead chef |
| droga | Chief RokGus |
| sebilis | crypt caretaker |
| chardok | Korocust |
| cobaltscar | Azureake |
| westwastes | Icehackle |
| mischiefplane | a forsaken hand |
| greatdivide | Murdrick Tardok |
| paludal | Ch'ktok |
| ssratemple | Yasiz the Devourer |
| dawnshroud | An Age Old Rockhopper |
| maiden | Xi Thall |
| ponightmare | Terror Matriarch |
| podisease | Rallius Rattican |
| powater | Fishlord Craiyk |
| pofire | Flame Overlord |
| dulak | Galikor Sevalin |
| torgiran | Foreman Deslug |
| nadox | Kdansol Borgir |
| hatesfury | Cabin Gnome Fitzgerald |
| barindu | Mastruq Utk Hykat |
| qvic | Pixtt Ttinq Val |
| tipt | Rikabi the Riddler |
| snplant | Grimy Turepta |
| wallofslaughter | Bazu Bonesmasher |
| causeway | Grinbar the Ancient |
| dranik | Souldrainer |
| riftseekers | Mistwalker |
| broodlands | Snowbrow |
| stillmoona | Hong Lei |
| thundercrest | Kodama the Voltaic |
| delvea | Rysok the Vile |

## Seasonal expedition registry

These are launcher names, not additional approvals or proof of distinct mechanics. Seasonal versions use the configured `Custom:SeasonalInstanceVersion` (default 200).

| Zone | Expedition name | Source |
| --- | --- | --- |
| soldungb | Seasons: Inferno Unchained | [global/Theta_Sigma.lua:7](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:7>) |
| permafrost | Seasons: Winter`s Requiem | [global/Theta_Sigma.lua:14](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:14>) |
| kedge | Seasons: Watery Death | [global/Theta_Sigma.lua:21](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:21>) |
| sebilis | Seasons: The Blight Below | [global/Theta_Sigma.lua:28](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:28>) |
| dreadlands | Seasons: The Cold Maw | [global/Theta_Sigma.lua:35](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:35>) |
| emeraldjungle | Seasons: Venom in the Veil | [global/Theta_Sigma.lua:42](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:42>) |
| skyfire | Seasons: Ashes of the Wyrmblood | [global/Theta_Sigma.lua:49](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:49>) |
| skyshrine | Seasons: Storm upon the Spine | [global/Theta_Sigma.lua:56](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:56>) |
| wakening | Seasons: The Grove`s Last Breath | [global/Theta_Sigma.lua:63](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:63>) |
| westwastes | Seasons: Echoes of Wyrmkind | [global/Theta_Sigma.lua:70](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:70>) |
| necropolis | Seasons: The Devourer of Kin | [global/Theta_Sigma.lua:77](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:77>) |
| thedeep | Seasons: Whispers from the Deep Mind | [global/Theta_Sigma.lua:84](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:84>) |
| akheva | Seasons: The Maw Beneath Reason | [global/Theta_Sigma.lua:91](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:91>) |
| ssratemple | Seasons: The Emperor Below the Eclipse | [global/Theta_Sigma.lua:98](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:98>) |
| bothunder | Seasons: Lord of Shackled Storms | [global/Theta_Sigma.lua:105](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:105>) |
| ferubi | Seasons: Rondo of Nightmare | [global/Theta_Sigma.lua:112](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:112>) |
| natimbi | Seasons: Queen of the Shattered Tides | [global/Theta_Sigma.lua:119](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:119>) |
| dranik | Seasons: Bridge four will never die | [global/Theta_Sigma.lua:126](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:126>) |
| wallofslaughter | Seasons: The Tarn Accord | [global/Theta_Sigma.lua:133](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:133>) |
| causeway | Seasons: Fury Made Stone | [global/Theta_Sigma.lua:140](<F:/EQ1/Bastion_Dev/quests/global/Theta_Sigma.lua:140>) |

## Notable exclusions

- **Augment miniboss spawning**: Zone trash-kill threshold summons augment reward mobs; retain as Anguish raid field notes rather than an independent combat-mechanics entry.
- **Telegraph demonstration**: No-damage movement course exercises telegraph rendering; optional tutorial rather than an encounter guide.
- **Training dummy system**: Training Master menu and per-player dummies; actual trial bosses are listed separately.
- **Encounter script test**: Debug all-death/say echo handlers.
- **Diving for Lavarocks**: Only tracks kills to issue cosmetic earthquake emotes; no encounter mechanics defined here.
- **Forbin's Elixir**: Distributes three poison sacs and cosmetic kill emotes; no scripted boss/event progression.
- **Grounding the Drakes**: Adds mission Drake Fang drops on spawn only.
- **Infested**: Only tracks kills to issue cosmetic earthquake emotes.
- **Lavaspinner's Locals**: Adds mission items on NPC spawn only.
- **Storming the Goblin Palace**: Only Amaro hail text in this module; database task content would be needed to establish the rest.
- **Demi-Plane augment minibosses**: File contains comments naming Maggotmiser, Ur-Gorloch, Swirling Bloodspirit, Madrillah and Legionnaire Silkbinder; no executable handlers.
- **Eternal Animist blessing ceremony**: Timed epic reward dialogue spawns spirits for their blessing; no hostile encounter.
- **Ancient crocodile spawn cycle**: Population/placeholder controller without an encounter objective.
- **Amalgamation of Honor's Spirits**: Midnight spawn controller only; boss combat mechanics not present in this script.
- **Tower of Frozen Shadow group doors**: Group teleport/key handling only.
- **Caerlyna bag check**: Bag/token exchanges only.
- **Patchious**: Test dialogue only.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Generic Deepest Guk LDoN adventure engine**: Generic randomized adventure/task population and objective handling; not an individually authored encounter. Distinct raid modules are listed separately.
- **Old theater and Bristlebane 1.0**: Archived old/ behavior or blank encounter stub without current loader.
- **Anniversary bread collection**: Module gives chance-based bread quest credit on generic goblin kills, not a distinct battle.
- **Baylan Thanksgiving collection / Storyteller Frank**: Hub collection and dialogue scripts, not combat encounters.
- **Boar stampede**: Ambient repeated waves lack a discrete objective/boss/win condition; suitable for zone hazard note.
- **Raider helpers**: Reusable goblin fleeing/aggro behavior belongs within Frozen Nightmare, not its own event.
- **Grummus**: Local script primarily death flagging; central seasonal registry reviewed separately.
- **Venril Sathir variants**: Local scripts mainly death/respawn/quest setup without substantive distinct combat phases.
- **Yelloweyes**: Anniversary proximity/illusion behavior is commented out.
- **Seahorse epic spawn chain / timed quest NPCs**: Only simple death-spawn, chance-spawn and despawn handlers found; no substantive self-contained encounter mechanics. Seasonal launcher is audited centrally.
- **Elite kyv hunter**: Generic trash combat ability with a health-triggered arrow volley; not a distinct named encounter.
- **General V'ghera request**: Seven rogue-epic requester NPCs only spawn NPC20205 on item turn-in; no local boss combat script to support a substantive journal.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **Generic LDoN adventure helper**: Shared adventure/scaling registration, not a distinct named encounter.
- **trumpy**: Conversation/waypoint ambience, no defined player combat event.
- **thunder_dome_two**: Parallel arena copy consolidated into Thunderdome row.
- **thunder_dome_three**: Parallel arena copy consolidated into Thunderdome row.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **ruj**: Duplicate shared Rujarkian Hills adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **tak**: Duplicate shared Takish-Hiz adventure controller; covered by theme overview.
- **area_emotes**: Ambient area text, helper for sewer setting.
- **Mask_of_the_Hidden**: Personal mask-to-class-reward chest conversion, not an encounter; outside combat journal scope.
- **Burrower**: Misplaced or unwired: registers 154xxx Acrylia NPCs. Acrylia loader names Burrower but has no encounters directory; The Deep has separate functioning Beast script.
- **clues**: Kill-count/random clue distribution plus boss flavor emotes only.
- **dragons_egg**: Only random mission item drops.
- **rival_party**: Only random mission item drops.
- **scrap_metal**: Only random mission item drops.
- **spiders_eye**: Only kill-count/random mission-item drop system.
- **history_of_the_isle**: Guard deaths expose artifact loot; mission loot/progression helper without additional combat behavior.
- **the_gilded_scroll**: Scroll-distribution and mission loot system; no distinct encounter behavior.
- **ghosts**: Consolidated into Tipt mountain trials row.
- **druid_1_5**: Dialogue/song/turn-in puzzle; no combat encounter, outside initial journal scope.
- **General_Dalinastalarix**: Waypoint conversation and city ambience.
- **Verte**: Tavern/banking dialogue signals.
- **Grand_Historian_Thoridain**: Shawl quest lecture and turn-in rewards.
- **#Spider_Tamer_Gugan**: Combat/death flavor emotes only; same pattern on other tutorial named scripts.
- **#The_Gloomingdeep_Jailor**: Arias dialogue signals only, not mechanics for a standalone encounter guide.
- **Julius_Oresko**: Combat taunt only.
- **#Pixtt_Ttinq_Val**: Static race/weapon modifiers only, no encounter controller or staged mechanics.
- **Pixtt_Llan_Kvish**: Static race/weapon modifiers only.
- **#Pixtt_Flamedirge**: Static weapon modifiers only.
- **#Mastered_Destroyer**: Static weapon modifiers only.
- **#The_Fabled_Final_Arbiter**: Death/lockout only; included in Sleep Walking overview instead of standalone mechanics guide.
- **#The_Fabled_Vulak-Aerr**: Fabled death/lockout/announcement only; not the separately scripted Vulak ring.
- **forager_trigger**: Random rare-spawn cycle, not a scripted encounter.
- **hunter_trigger**: Random rare-spawn cycle, not a scripted encounter.
- **14003**: Quillmane rare-spawn cycle, not a phased encounter.
- **#Councilor_Juliah_Lockheart**: Turn-in spawned enemy with only despawn timer; quest guide scope rather than encounter mechanics.
- **corrupted_hill_giant**: Timed despawn paused in combat only; no distinct fight logic.
- **Florist**: Decoration/flower spawning, not combat encounter.
- **991125**: Bonded Hunt echo wrapper; root audit covers shared system.
- **Global NPC seasonal scaling, trophy and raid credit**: Shared behavior and achievement hooks, not additional standalone encounters.
- **Seasonal telegraph beacon**: Visual and damage-mechanic helper. Grouped into the seasonal bosses that use it.
- **Global items, spells, plugins and support modules**: Item use, class quests, transports, tradeskills, progression, loot and shared quest infrastructure are not separately listed as combat encounters.
- **Test fixtures and review scripts**: Not live quest handlers. Used only to cross-check intended associations where needed.

## Coverage

Every Lua/Perl source file has a hash, line count and discovery signals in `source-inventory.json`. Signals helped locate candidates; rows above were reviewed against script bodies. No quest code was executed or changed by the scan.

| Zone / directory | Lua / Perl files |
| --- | ---: |
| abysmal | 243 |
| acrylia | 60 |
| airplane | 119 |
| akanon | 59 |
| akheva | 23 |
| anguish | 14 |
| arena | 3 |
| arena2 | 13 |
| barindu | 25 |
| bazaar | 16 |
| befallen | 11 |
| beholder | 2 |
| blackburrow | 2 |
| bloodfields | 30 |
| bothunder | 31 |
| broodlands | 6 |
| burningwood | 12 |
| butcher | 41 |
| cabeast | 70 |
| cabwest | 38 |
| cauldron | 14 |
| causeway | 31 |
| cazicthule | 33 |
| chambersa | 5 |
| chambersb | 5 |
| chambersc | 5 |
| chambersd | 5 |
| chamberse | 5 |
| chambersf | 5 |
| charasis | 6 |
| chardok | 6 |
| chardokb | 18 |
| citymist | 22 |
| cobaltscar | 11 |
| codecay | 32 |
| commons | 15 |
| corathus | 20 |
| corathusb | 3 |
| crescent | 1 |
| crushbone | 19 |
| crystal | 3 |
| cshome | 7 |
| dalnir | 7 |
| dawnshroud | 35 |
| delvea | 15 |
| delveb | 9 |
| drachnidhive | 8 |
| drachnidhivea | 3 |
| drachnidhivec | 2 |
| dragonscalea | 2 |
| dranik | 43 |
| dranikcatacombsa | 2 |
| dranikcatacombsb | 2 |
| dranikcatacombsc | 3 |
| dranikhollowsa | 3 |
| dranikhollowsb | 3 |
| dranikhollowsc | 4 |
| draniksewersa | 2 |
| draniksewersb | 2 |
| draniksewersc | 3 |
| draniksscar | 18 |
| dreadlands | 20 |
| dreadspire | 7 |
| droga | 10 |
| dulak | 5 |
| eastkarana | 40 |
| eastkorlach | 3 |
| eastkorlacha | 2 |
| eastwastes | 49 |
| echo | 25 |
| ecommons | 14 |
| emeraldjungle | 10 |
| erudnext | 50 |
| erudnint | 19 |
| erudsxing | 14 |
| everfrost | 25 |
| fearplane | 30 |
| feerrott | 15 |
| felwithea | 26 |
| felwitheb | 9 |
| ferubi | 41 |
| fhalls | 1 |
| fieldofbone | 17 |
| firiona | 73 |
| freporte | 71 |
| freportn | 53 |
| freportw | 58 |
| frontiermtns | 66 |
| frozenshadow | 43 |
| fungusgrove | 25 |
| gfaydark | 49 |
| global | 131 |
| greatdivide | 31 |
| griegsend | 19 |
| grimling | 29 |
| grobb | 26 |
| growthplane | 16 |
| guildhall | 4 |
| guildlobby | 11 |
| guka | 3 |
| gukb | 7 |
| gukbottom | 5 |
| gukc | 6 |
| gukd | 6 |
| guke | 8 |
| gukf | 6 |
| gukg | 8 |
| gukh | 5 |
| guktop | 12 |
| gunthak | 54 |
| halas | 43 |
| harbingers | 9 |
| hateplane | 21 |
| hateplaneb | 7 |
| hatesfury | 34 |
| highkeep | 59 |
| highpass | 22 |
| highpasshold | 25 |
| hohonora | 49 |
| hohonorb | 6 |
| hole | 12 |
| hollowshade | 32 |
| iceclad | 44 |
| ikkinz | 82 |
| illsalin | 4 |
| illsalinb | 3 |
| illsalinc | 3 |
| inktuta | 48 |
| innothule | 6 |
| jaggedpine | 27 |
| kael | 70 |
| kaesora | 6 |
| kaladima | 20 |
| kaladimb | 33 |
| karnor | 7 |
| katta | 133 |
| kedge | 5 |
| kerraridge | 33 |
| kithicor | 32 |
| kodtaz | 51 |
| kurn | 2 |
| lakeofillomen | 30 |
| lakerathe | 36 |
| lavastorm | 37 |
| letalis | 5 |
| lfaydark | 20 |
| lua_modules | 58 |
| maiden | 9 |
| mira | 3 |
| mirb | 11 |
| mirc | 3 |
| mird | 3 |
| mire | 3 |
| mirf | 3 |
| mirg | 3 |
| mirh | 3 |
| miri | 3 |
| mirj | 3 |
| mischiefplane | 63 |
| mistmoore | 12 |
| misty | 25 |
| mmca | 3 |
| mmcb | 3 |
| mmcc | 7 |
| mmcd | 3 |
| mmce | 3 |
| mmcf | 3 |
| mmcg | 3 |
| mmch | 3 |
| mmci | 3 |
| mmcj | 3 |
| mseru | 4 |
| nadox | 42 |
| najena | 7 |
| natimbi | 31 |
| necropolis | 29 |
| nedaria | 57 |
| nektulos | 8 |
| neriaka | 14 |
| neriakb | 34 |
| neriakc | 33 |
| netherbian | 5 |
| nexus | 26 |
| nightmareb | 4 |
| northkarana | 21 |
| nro | 19 |
| nurga | 5 |
| oasis | 9 |
| oggok | 34 |
| oot | 27 |
| overthere | 46 |
| paineel | 69 |
| paludal | 9 |
| paw | 1 |
| permafrost | 5 |
| plugins | 31 |
| poair | 50 |
| podisease | 11 |
| poeartha | 58 |
| poearthb | 12 |
| pofire | 17 |
| poinnovation | 27 |
| pojustice | 189 |
| poknowledge | 283 |
| ponightmare | 39 |
| postorms | 62 |
| potactics | 27 |
| potimea | 10 |
| potimeb | 141 |
| potorment | 31 |
| potranquility | 42 |
| povalor | 28 |
| powar | 2 |
| powater | 20 |
| provinggrounds | 12 |
| qcat | 33 |
| qey2hh1 | 56 |
| qeynos | 132 |
| qeynos2 | 91 |
| qeytoqrg | 45 |
| qinimi | 21 |
| qrg | 22 |
| qvic | 36 |
| rathemtn | 64 |
| review | 1 |
| riftseekers | 9 |
| rivervale | 78 |
| riwwi | 59 |
| ruja | 3 |
| rujb | 3 |
| rujc | 3 |
| rujd | 6 |
| ruje | 4 |
| rujf | 3 |
| rujg | 5 |
| rujh | 3 |
| ruji | 3 |
| rujj | 3 |
| runnyeye | 15 |
| scarlet | 7 |
| sebilis | 10 |
| shadeweaver | 35 |
| shadowhaven | 261 |
| shadowrest | 2 |
| sharvahl | 188 |
| sirens | 7 |
| skyfire | 13 |
| skyshrine | 59 |
| sleeper | 36 |
| sncrematory | 10 |
| snlair | 4 |
| snplant | 5 |
| snpool | 5 |
| soldunga | 4 |
| soldungb | 4 |
| soldungc | 25 |
| solrotower | 24 |
| soltemple | 27 |
| southkarana | 27 |
| sro | 22 |
| sseru | 46 |
| ssratemple | 39 |
| steamfont | 42 |
| stillmoona | 17 |
| stillmoonb | 8 |
| stonebrunt | 22 |
| swampofnohope | 19 |
| tacvi | 29 |
| taka | 5 |
| takb | 5 |
| takc | 6 |
| takd | 5 |
| take | 5 |
| takf | 5 |
| takg | 5 |
| takh | 5 |
| taki | 5 |
| takishruinsa | 2 |
| takj | 5 |
| templeveeshan | 44 |
| tenebrous | 27 |
| tests | 39 |
| thedeep | 9 |
| thegrey | 14 |
| thenest | 16 |
| thundercrest | 30 |
| thurgadina | 92 |
| thurgadinb | 27 |
| timorous | 33 |
| tipt | 15 |
| torgiran | 27 |
| tox | 21 |
| trakanon | 43 |
| tutoriala | 3 |
| tutorialb | 41 |
| twilight | 36 |
| txevu | 45 |
| umbral | 6 |
| unrest | 21 |
| uqua | 59 |
| veeshan | 11 |
| veksar | 8 |
| velketor | 6 |
| vexthal | 77 |
| vxed | 10 |
| wakening | 41 |
| wallofslaughter | 34 |
| warrens | 8 |
| warslikswood | 17 |
| weddingchapel | 2 |
| westkorlach | 1 |
| westkorlacha | 2 |
| westkorlachb | 2 |
| westwastes | 25 |
| yxtta | 36 |
