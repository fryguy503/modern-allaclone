const fs=require('fs'),path=require('path'),crypto=require('crypto');
const root='F:/EQ1/Bastion_Dev/quests';
const base=path.resolve(__dirname,'../..');
const decisions=JSON.parse(fs.readFileSync(path.join(__dirname,'saved-decisions.json'),'utf8')).rows;
const records=[];
function add(id,version,npcs,summary,bullets,opts={}){records.push({id,version,npcs,summary,bullets,...opts});}
const zoneNames={gukg:'The Accursed Sanctuary',gunthak:'The Gulf of Gunthak',harbingers:"Harbinger's Spire",hateplaneb:'The Plane of Hate',hatesfury:'Hate’s Fury',hohonora:'Halls of Honor',hohonorb:'Temple of Marr',hole:'The Hole',hollowshade:'Hollowshade Moor',iceclad:'Iceclad Ocean',ikkinz:'Ikkinz',illsalin:'Ruins of Illsalin',illsalinb:'Temple of the Korlach',illsalinc:'The Nargilor Pits',inktuta:'Inktu’ta',jaggedpine:'Jaggedpine Forest',kael:'Kael Drakkel',katta:'Katta Castellum',kerraridge:'Kerra Isle',kithicor:'Kithicor Forest',kodtaz:'Kod’Taz',lakeofillomen:'Lake of Ill Omen',lakerathe:'Lake Rathetear',lavastorm:'Lavastorm Mountains',lfaydark:'Lesser Faydark',mirb:'The Frozen Nightmare',mischiefplane:'Plane of Mischief',mistmoore:'Castle Mistmoore',mmcc:'The Asylum of Invoked Stone',poknowledge:'Plane of Knowledge',nadox:'Crypt of Nadox',natimbi:'Natimbi, the Broken Shores',necropolis:'Dragon Necropolis',nightmareb:'Lair of Terris Thule',northkarana:'North Karana',oasis:'Oasis of Marr',overthere:'The Overthere',permafrost:'Permafrost Keep',poair:'Eryslai, the Kingdom of Wind',poeartha:'Vegarlson, the Earthen Badlands',poearthb:'Ragrax, Stronghold of the Twelve',pofire:'Doomfire, the Burning Lands',poinnovation:'Plane of Innovation',pojustice:'Plane of Justice',ponightmare:'Plane of Nightmare',postorms:'Plane of Storms',potactics:'Drunder, the Fortress of Zek',potimeb:'Plane of Time',potorment:'Plane of Torment',povalor:'Plane of Valor',powater:'Reef of Coirnav',provinggrounds:'Muramite Proving Grounds',qey2hh1:'West Karana',qeynos2:'North Qeynos',qinimi:'Qinimi',qvic:'Qvic',rathemtn:'Rathe Mountains',riftseekers:"Riftseekers’ Sanctum",riwwi:'Riwwi',kedge:'Kedge Keep'};
const baseNote='Reviewed against quest source only; encounter behavior, database tuning and availability have not been verified in a live zone. Tactical advice is inferred from the scripted mechanics.';
function emit(){
 let report=[];
 for(const r of records){
  const d=decisions.find(d=>d.id===r.id); if(!d||d.decision!=='A')throw Error('Not approved '+r.id);
  const zone=r.zone||d.zone;
  const title=r.title||d.title;
  const slug=r.slug||(zone+'-'+title.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'')).slice(0,100).replace(/-$/,'');
  const files=[...new Set((d.source||'').split('\n').filter(Boolean).map(v=>v.replace(/:\d+$/,'')).concat(r.extra||[]))];
  const issues=r.issues||[];
  if(!r.npcs.length)issues.push('Resolve the encounter NPC template ID before publication; the reviewed files do not identify it unambiguously.');
  const doc={schema_version:1,slug,title,group:r.group||d.series,type:r.type||(/raid/i.test(d.type)?'raid':/group|trial|mission/i.test(d.type)?'group':'event'),zone:{short_name:zone,name:zoneNames[zone]||zone,version:r.version},npc_ids:r.npcs,status:'draft',spoiler:r.spoiler||false,summary:r.summary,overview:r.overview||[r.summary],roles:r.roles||[],phases:(r.phases||[]).map((p,i)=>({id:'phase-'+(i+1),label:p[0],trigger:p[1],description:p[2]})),abilities:r.abilities||[],sections:[{id:'encounter-guide',title:r.sectionTitle||'Encounter guide',paragraphs:[],bullets:r.bullets,items:r.items||[]}].concat(r.sections||[]),loot:r.loot||[],sources:{reviewed_at:'2026-09-29',revision:'f3100d1a539093c717344860d4499558c48c7453',verification:'source-reviewed',files:files.map(p=>({path:p,sha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(root,p))).digest('hex')})),notes:[baseNote].concat(r.notes||[],issues)}};
  const out=path.join(base,'resources/data/encounters',slug+'.json');
  if(fs.existsSync(out)&&JSON.parse(fs.readFileSync(out,'utf8')).status!=='draft')throw Error('Existing published document '+out);
  fs.writeFileSync(out,JSON.stringify(doc,null,2)+'\n');report.push({id:r.id,slug,issues});
 }
 fs.writeFileSync(path.join(__dirname,'generated-b.json'),JSON.stringify(report,null,2)+'\n');
 console.log('Generated '+report.length+' approved draft documents');
}

add('E141',2,[259147],'Leklos guards the first stretch of the Accursed Sanctuary and changes into a skeletal form as his health falls.',[
'At 25% health, Leklos transforms from his normal form into a skeleton. Keep the same tank established through the change.',
'Fight inside his area. Pulling him across the scripted boundary returns him home and clears his hate list.',
'Defeating Leklos advances the rescue through the sanctuary; follow the allied NPCs’ directions to the next encounter.'
],{notes:['The audited summary suggested summoned adds, but the reviewed health handler only changes Leklos’s appearance. No add wave is documented for this health transition.']});
add('E142',2,[259254],'Finish the rescue by defeating the Cursed Keeper after Gragna falls.',[
'At 50% health, the Keeper replaces area rampage with rampage. At 20%, the remaining rampage ability is removed.',
'Keep the boss in the rear encounter area. Crossing its leash boundary returns it home and clears combat.',
'Healing back above the phase boundaries restores the earlier special-attack state. Maintain steady pressure through the final stages.'
]);
add('E143',2,[259235],'Help the Prophets of Gukta weaken the Cursed Spore while your raid drives its health down.',[
'The Spore begins immune to slow. At 90% health, the prophets remove that immunity, creating an opportunity to slow its attacks.',
'At 75%, 50% and 25% health, its elemental and magical resistances fall in stages. Casters should expect greater resistance early in the fight.',
'Regaining health can restore earlier resistance stages. Leaving combat restores the initial resistances and slow immunity.',
'Its defeat opens the route toward Gragna and the final Keeper encounter.'
]);
add('E144',0,[224115,224444,224448,224439],'Choose a spirit challenge with Dimaal, then survive four waves in the Cave of the Damned.',[
'Give Dimaal four matching ritual bones while no challenge is running. Their condition selects the easy, medium or hard spirit set.',
'The first wave arrives after six seconds. Three further waves arrive at ten-minute intervals; waves are timed rather than gated by clearing every enemy.',
'The fourth wave includes the named spirit: Simati the Cursed, Tagai Darkheart or Phizan Crindo, depending on the chosen set.',
'Defeat casters alongside the fighters and clear each wave before the next timed arrival. A fleeing spirit can cause Dimaal to abandon the challenge.'
],{items:[{id:56001,name:'Cracked Finger Bone',quantity:4},{id:56002,name:'Broken Finger Bone',quantity:4},{id:56003,name:'Pristine Finger Bone',quantity:4}],notes:['All three difficulty selections use the same zone version and wave controller; they are presented as selectable variants in one guide.']});
add('E145',0,[224436],'Escort Lairyn to his stand against the shadows, protect him through five attacks, then confront Krill the Backbleeder.',[
'A rogue at the relevant epic stage begins the defense by telling Lairyn that Lirprin sent them. Follow him while he moves to the defense location.',
'Five waves each contain a shadowed thug, assassin and enforcer. They arrive about 55 seconds apart and immediately attack Lairyn.',
'Assign someone to pick up each arriving trio. Krill also attacks Lairyn when summoned, so keep the protected NPC alive through the final fight.',
'A healing potion hand-in restores 3,000 of Lairyn’s health. After Krill falls, complete Lairyn’s requested quest hand-in for credit.'
],{items:[{id:52354,name:'Cloudy Silver Potion',quantity:1}]});
add('E146',0,[335051],'Defeating a dragorn sycophant can call the Attendant of Light and a group of discordling harassers.',[
'The ritual begins with eight sycophants around the spawner. A sycophant death summons the Attendant if one is not already present.',
'Four discordling harassers appear with the Attendant. Prepare to control the escort enemies when triggering the boss.',
'The spawner tracks all eight sycophant deaths separately; killing all eight is not required by the Attendant’s spawn condition.'
]);
add('E147',0,[335064,335065],'A druid epic ambush brings Azibelle Spavin and Glenfire Telzir into Harbinger’s Spire.',[
'The hidden proximity trigger checks the druid’s quest state before summoning both opponents.',
'Prepare for a two-enemy engagement rather than approaching the trigger alone. Establish separate control of the pair before focusing one down.',
'The summoned enemies have a fifteen-minute cleanup window. Finish the encounter while both are available.'
]);
add('E148',0,[],'Windrush adds recurring spell attacks as the fight progresses.',[
'At 70% health, Windrush begins using Stunning Strike, repeating every twenty seconds after the initial cast.',
'At 40% health, Mana Spectrum joins the rotation and repeats every fifteen seconds.',
'Allow for increasing pressure on the tank and the group in the final stages. The encounter has a thirty-minute depop timer once engaged.'
]);
add('E149',0,[186196,186107,186197],'Defeat Innoruuk’s first form, survive the Evangelist’s reinforcements, and end the god’s renewed manifestation.',[
'The first Innoruuk’s death summons the Evangelist of Hate. After the ritual dialogue, the true Innoruuk appears.',
'The Evangelist repeatedly replenishes imps, spite golems and clerics of hate. Assign add control while the main tank holds Innoruuk.',
'At 30% health, the Evangelist becomes attackable and joins combat. Kill it before trying to finish Innoruuk: at 5%, Innoruuk becomes immune while the Evangelist lives.',
'Remain within Innoruuk’s chamber. Pulling him too far north causes a return to his bind point.',
'Five minutes without combat clears the event; the controller schedules another attempt several hours later.'
],{notes:['This guide covers the revamped encounter templates. The controller selects an older Innoruuk template while global expansion progression is below 2; that alternate fight is not described here.']});
add('E150',0,[186190],'A rogue’s taunts reveal Lanys T’Vyl, whose defenses alternate between physical and magical damage.',[
'Twenty qualifying trash kills summon the Teir’Dal guardian. A rogue at the appropriate epic stage can provoke her through the dialogue.',
'The final taunt, Laarthik, activates Lanys. Prepare the raid before speaking it.',
'Every twenty seconds, watch the emote: when she pauses to catch her breath, magic is effective and weapon damage is heavily reduced. When she regains strength, weapon damage is effective and several spell skills are heavily reduced.',
'Pulse of Pain and Avatar Power repeat on separate thirty-five-second rotations after their opening casts. Healers should expect repeated pressure while damage dealers adapt to the defensive state.'
]);
add('E151',0,[186111,186191,186192,186193,186194],'The Maestro’s performance brings supporting musicians and banshees into the hall.',[
'At 95% health, three silent banshees appear.',
'At 75%, two accompanists grant the Maestro very high health regeneration. Kill both accompanists to remove it.',
'At 40%, the silent banshees are replaced with screeching, moaning and wailing banshees. The moaning banshee repeatedly attacks the top hate target’s mana.',
'Killing every active singing banshee while the Maestro lives summons two more. Manage the remaining banshees while finishing the boss.',
'Stay inside the music hall. The boss leashes outside its boundaries and the event resets after sixty seconds out of combat.'
],{notes:['This guide covers the revamped Maestro template. The controller selects an older template when global expansion progression is below 2.']});
add('E152',0,[],'Weaken the Broken Skull Armsmaster as part of the berserker’s preliminary epic trial.',[
'At 20% health, the Armsmaster exposes a weakness and begins checking the initiating fighter for War Cry.',
'The special reaction requires a berserker at the correct preliminary epic stage with War Cry active. Coordinate the health push with that character.',
'The weakness state resets after five minutes out of combat. Eligible berserkers receive quest advancement when the Armsmaster dies.'
],{notes:['The War Cry reaction and the death-credit check are separate in the reviewed script; the death-credit check does not test whether the reaction occurred.']});
add('E153',0,[228121,228122],'Captain Krasnok appears with four Fists of Krasnok when the attendant receives the required quest hand-in.',[
'Prepare the group before the hand-in to Attendant Mi-Ta; it creates the captain and his four defenders together.',
'Assign control of the Fists while a tank establishes the captain. Clear supporting enemies to reduce the simultaneous pressure.',
'Use the attendant’s passage NPCs to enter and leave the encounter area.'
],{notes:['The reviewed attendant script establishes the spawn and access sequence; no additional captain combat mechanics are asserted from its hand-in handler.']});
add('E155',0,[211085,211095,211093],'Clear Alekson Garn’s three guarded rooms while protecting the maidens of Marr.',[
'Speak with Alekson to enter the trial, then defeat its Custodian of Marr to start the room encounters.',
'Each room begins with five enemies. Clear the room to bring out its named opponent: Advocent Joran, Halgoz Rell or Freegan Haun.',
'Protect the maidens throughout the trial. Their loss can fail the event even if the attackers are being defeated.',
'Defeat all three named opponents, then return to Alekson’s projection for trial credit.'
]);
add('E156',0,[211100,211096,211112,211109,211099,211110,211102,211104,211106,211092,211094,211108],'Defend Rhaliq Trell’s villagers and defeat the twelve named attackers.',[
'Speak to Rhaliq to reach the trial and kill its Custodian of Marr to begin.',
'The event places seven villagers and twelve named opponents in the trial area. Secure the attackers before they overwhelm the protected villagers.',
'The village gnome is a failure target. Keep defenders assigned to the villagers while the rest of the raid eliminates the named enemies.',
'After all twelve named opponents die, speak with Rhaliq’s projection for completion credit.'
]);
add('E157',0,[211105],'Trydan Faye’s trial culminates in a confrontation with Rydda’Dar.',[
'Defeat the trial’s Custodian of Marr to summon Rydda’Dar.',
'Gather and prepare before triggering the dragon; its availability is limited by a two-hour timer.',
'After Rydda’Dar falls, speak to Trydan Faye’s projection to complete the trial.'
]);
add('E158',0,[220020],'Defeating Ralthazor opens the confrontation with Lord Mithaniel Marr.',[
'Clear the preceding temple encounter with Ralthazor to activate Marr.',
'Marr’s activation lasts two hours. When that window expires, he returns to his inactive state once no longer engaged.',
'Defeat Marr and speak with the planar projection that appears for progression credit.'
]);
add('E159',0,[],'Master Yael repeatedly uses a lethal touch against the top of his hate list.',[
'Expect the first touch almost immediately after engagement, followed by a thirty-second cycle.',
'The touch selects the highest-hate target. If that target is a pet, the script redirects the attack to its owner.',
'The script removes several Divine Aura-style protections from the selected top-hate target before casting. Plan tank recovery and replacements around the touch cycle.'
],{abilities:[{id:'lethal-touch',name:'Cazic Touch',summary:'A recurring attack against the highest-hate target.',description:['The first cast occurs within about two seconds of engagement, then repeats every thirty seconds while combat continues.'],tags:['Tank'],roles:[],spell_ids:[982]}]});
add('E160',0,[166257],'The owlbears, sonic wolves and grimlings battle for three camps across Hollowshade Moor.',[
'A camp boss’s death opens an attack against that camp. The defending race and ownership of the north, east and south camps determine the attackers.',
'Attack waves have a fifteen-minute resolution window. Help a faction defeat the opposition to change control of a camp.',
'Continue the war until one race holds all three camps. The resulting occupation changes the creatures available across the moor.',
'Track camp ownership between attacks; the same boss kill can lead to different waves depending on the current war state.'
],{notes:['The linked template is the war coordinator, not a combat boss. Camp creature placement and ordinary loot tables remain database-defined.']});

add('E161',0,[110227,110219,110109],'Escort General Bragmur across Iceclad and defend the Coldain shawl ceremony.',[
'Complete the general’s required preparations before beginning the march. Follow Bragmur through his route rather than racing ahead.',
'Wolves and giants ambush the escort at fixed waypoints. Pick them up quickly so they do not kill the general.',
'The later attacks include Kvarid and Vjorik. Hold a reserve for these named attackers while keeping the escort safe.',
'Remain through the final ceremony and follow the general’s dialogue to complete the quest sequence.'
]);
add('E162',0,[110123,110128,110127,110126,110125],'Noble Oldencamp arrives with an armed delegation after Vas Thorel’s quest exchange.',[
'Prepare for the full delegation before the trigger: Oldencamp arrives with Amilia Verisue, Xeegarn, Puella Opalis, Locis Vera and six additional escorts or warriors.',
'Separate the supporting opponents from the principal target so the group can control the large initial pull.',
'The delegation has a one-hour cleanup timer. Complete the required kills and quest steps before the visit ends.'
]);
add('E163',6,[294620,294615,294622],'Break the altar’s defenses and confront a Keeper that combines throws, short-lived attackers and class-sensitive adherents.',[
'During the approach, respect the altar defenders’ room boundaries and resolve the adherents’ class clues before delivering their finishing blows.',
'The Keeper repeatedly creates assailants, which exhaust themselves after fifteen seconds, and calls up to five adherents over the encounter.',
'Throw hits several hate-list targets and brings distant players back toward the Keeper. Recover positioning promptly; distance is not a reliable escape.',
'Earthen Shrapnel and Bury continue alongside the add cycle. Assign add tanks so the main tank can stay on the Keeper.',
'At 50% and 10% health, the Keeper enters a five-second rampage burst. Prepare healing before pushing either threshold.',
'After three minutes out of combat, the Keeper heals and clears its summoned enemies.'
],{extra:['lua_modules/constants/instance_versions.lua']});
add('E164',4,[294582,294583,294584],'Weaken the stone guardians through their supporting creatures, then handle each guardian split.',[
'The Visionary of Glory uses rampage between 50% and 40% health and again below 10%. Keep the fight in its chamber.',
'Glorified supporting creatures strengthen the guardian’s health, regeneration or damage. Clearing them reduces the pressure during the guardian sequence.',
'The Guardian of Glorification splits into smaller forms. Its intermediate forms split again at 50% health, so prepare to tank additional targets rather than assuming a nearly defeated target is the end.',
'The stone forms can throw players, launch magical attacks and return players who leave their permitted area. Regroup inside the encounter space after displacement.'
],{extra:['lua_modules/constants/instance_versions.lua'],notes:['Support effects are documented qualitatively; no assumption is made that repeated same-type NPC bonuses stack beyond the assignments implemented by the script.']});
add('E165',3,[],'Clear the Chambers of Righteousness and defeat its priests, defenders and final custodian.',[
'Priests and the Custodian enter rampage at 50% health, stop at 40%, and resume at 10%. Prepare healing before each health transition.',
'The Custodian clears its hate list every forty-five seconds. Tanks should be ready to regain its attention when the weight-shifting emote appears.',
'Keep each guardian within its assigned chamber. Crossing the scripted boundaries causes a return home and clears hate.',
'Complete the required defender kills to open the way through the chamber and finish with the Custodian.'
],{extra:['lua_modules/constants/instance_versions.lua']});
add('E166',2,[294624,294135,294625],'Defeat all three Tri-Fate hunters before their replacements undo your progress.',[
'Each hunter’s death begins a twelve-minute replacement timer and starts summoning Pixtt Annuller reinforcements after a random delay.',
'Hunter replacement timers pause while a hunter is engaged and resume when combat ends. Move promptly between targets rather than taking a long break after a kill.',
'Control the annullers while finishing the remaining hunters. All three hunters must be absent together to complete the trial.',
'Completion locks the expedition to new entrants and grants the trial’s replay lockout.'
],{extra:['lua_modules/constants/instance_versions.lua']});
add('E167',5,[294590,294500,294593,294595],'Face the Transcendent Acolytes, follow the Guardian and solve the sentinels’ class requirements to release Vrex Xalkak Nixki.',[
'Transcendent Acolytes briefly rampage at 50% and 10% health. Stay inside their chamber to avoid a full heal and reset.',
'The Guardian of Transcendence retreats at 90%, 75% and 50% health, opening doors along its route. Defeat its last manifestation to reveal Vrex and his four sentinels.',
'A sentinel reveals a class clue at 10%. Let a player of the required class deliver the killing blow; an incorrect finisher causes the sentinel to return.',
'Vrex remains protected during the sentinel sequence and can still disrupt nearby players. Complete the sentinels and marauder progression to activate him.',
'While not engaged, Vrex alternates between very high and low health. Coordinate the pull around this change, then maintain combat.',
'At 4% health, Vrex enters a final five-second rampage burst.'
],{extra:['lua_modules/constants/instance_versions.lua','ikkinz/Guardian_of_Transcendence.lua'],notes:['Guardian_of_Transcendence.lua explicitly spawns NPC 294595 and identifies it as Vrex Xalkak Nixki.']});
add('E168',1,[294138,294629],'Free the constrained servitors to weaken the two Malevolent Priests, then defeat the priests together.',[
'Each servitor death removes ten percent of a priest’s maximum health. Destroy all the constrained servitors to remove the priests’ protection.',
'The priests repeatedly attack nearby players with Malevolent Assault, Coordinated Strike or Malevolent Vex. Prepare for pressure before they become directly attackable.',
'Servitors can clear their hate list shortly after combat begins. Tanks should re-establish control rather than relying only on the initial pull.',
'Keep the priests inside their narrow encounter area. Both must die to complete the trial and close the expedition.'
],{extra:['lua_modules/constants/instance_versions.lua']});
add('E169',0,[],'Ritesmaster Verok seals his chamber and brings a growing spider threat into the battle.',[
'Get the full group inside before pushing Verok below 95%, when the chamber doors lock.',
'Seven spider eggs appear at 90%. At 85%, the surrounding spider population is enabled and periodically joins Verok’s hate list.',
'At 20%, Verok signals the eggs again for the final stage. Keep add control available during the burn.',
'Pulling Verok out of his chamber resets him, clears the spiders and unlocks the doors. Leaving combat also schedules an eighteen-second reset.',
'His death clears the event’s eggs and spiders and opens both doors.'
]);
add('E170',1,[349030,349031,349074,349075,349076,349077,349078,349079,349080,349081],'The Avatar of the Council grows weaker as its nine council members join the battle.',[
'Each ten-percent health step from 90% through 10% activates another council member and sends it against a random target on the Avatar’s hate list.',
'The Avatar’s melee damage falls at each activation, but the raid must handle the accumulating council members. Assign pickup tanks before the next health push.',
'Avoid pushing several thresholds faster than the raid can establish control of the newly active enemies.',
'Leaving combat fully heals the Avatar, restores its original damage and rebuilds the council population.'
],{extra:['lua_modules/constants/instance_versions.lua']});
add('E171',1,[350101,350037],'Enter Draygun’s sealed chamber, endure his reanimated soldiers and defeat the captain left behind.',[
'Find the mission key and gather the group before entering the chamber. The entry sequence consumes the key and closes the door after a short warning.',
'Draygun remains protected while he moves between corpses in a randomized order and raises attackers. Follow his movement and intercept each soldier.',
'At the last step, he leaves a captain with additional reanimated soldiers and departs. Defeat the captain to unlock the chamber.',
'Normal and hard task selections use this same event sequence but scale enemies and reward pools differently.'
],{extra:['lua_modules/constants/instance_versions.lua'],notes:['Both task selections share version 1 and scripted objectives; they are documented together.']});
add('E172',2,[350051,350097],'Draygun’s lich form feeds on lost souls and exposes a dangerous interaction with healing magic during his split phases.',[
'Lost souls begin arriving ninety seconds into combat, in groups of five spaced five seconds apart. Kill them before absorption: each survivor heals Draygun and increases his melee damage.',
'At 85%, 60% and 40% health, the living Emperor appears for a limited window while the lich becomes immune to ordinary spells.',
'During those windows, only the listed supported healing spells damage the lich through the scripted interaction. Other spells cast on him heal him instead, so stop unapproved casts.',
'Keep tanks and healers assigned to the lost souls throughout the split phases. A wipe heals the lich, and an already-started add sequence can still finish spawning.'
],{extra:['lua_modules/constants/instance_versions.lua'],abilities:[{id:'healing-weakness',name:'Restored lifeforce',summary:'Specific healing spells injure the lich while the living Emperor is present.',description:['Use only the supported spell ranks linked here. Other cast-on events in this phase restore 60,000 health to the lich.'],tags:['Healing interaction'],roles:[],spell_ids:[6140,6142,6141,5265,5355,4901,5251,4883,5395]}],issues:['Verify the end of the split-phase spell immunity in a live test: the reviewed module enables it but does not explicitly disable it when the living Emperor despawns.']});
add('E173',0,[296024,296025,296026,296084,296083],'Clear Kelekdrix’s watchers and ushers to break her rocky protection, then maintain control as reinforcements return.',[
'Soon after engagement, Kelekdrix becomes protected and calls four minions. Defeat every watcher and usher to make her attackable.',
'Reinforcements return on a variable timer. Pick them up and clear them while keeping the raid ready for the boss’s exposed windows.',
'When active, Kelekdrix can banish her highest-hate player. Have another tank ready to take over.',
'Her defeat unlocks the lower reaches of Inktu’ta and advances the expedition.'
]);
add('E174',0,[296065,296066],'Identify Noqufiel’s true image, halt attacks on the revealed mirror and repeat the deception cycle until the true image falls.',[
'Both images initially use the name Noqufiel. Removing five percent health reveals an image’s identity.',
'Attack the True Image once revealed. Stop all attacks, pets and damage-over-time effects on the Mirror Image; after a two-second grace period, further health loss can detonate it.',
'Another five-percent push on the true image resets the pair to hidden forms. Re-identify the target at each cycle instead of assuming the same visible position is safe.',
'Hidden forms banish their highest-hate player and can spawn cursebearers. Keep backup tanks ready and avoid dragging either image beyond the room boundary.',
'Out-of-combat regeneration can reverse progress to an earlier stage. Maintain controlled pressure on the correct image.'
]);
add('E175',0,[296053,296054,296055,296056,296057,296058],'Defeat six Cursecallers while managing the cursebearers sustained by each caller.',[
'The event creates an initial set of six cursebearers after fifteen seconds.',
'Every fifteen seconds, the controller replaces a missing cursebearer if its corresponding Cursecaller still lives.',
'Focus the Cursecallers to permanently stop their paired reinforcements. Once a caller is dead, its remaining cursebearer is removed.',
'Defeating all six callers clears the remaining bearers and unlocks the next part of the expedition.'
]);
add('E176',0,[296030,296033,296035,296036],'Coordinate the four exiles’ responses within one short window to pass the Stonemite trial.',[
'Assign a player to each exile and determine the required response from that exile’s dialogue before starting the coordinated attempt.',
'The first correct response starts a twenty-second window. All four exile responses must reach the controller before it expires.',
'If the window closes without all four responses, the exiles summon stonemites and the response state resets. Clear the failure wave before trying again.',
'A successful set removes the exiles, opens the next door and advances the expedition.'
]);
add('E177',0,[181004],'A dire kodiak reveals a forest dragon when driven below 80% health.',[
'Lower the dire kodiak to the transformation threshold with the group ready for a replacement target.',
'The kodiak disappears and the forest dragon appears at its location. Re-establish the tank’s target and hate immediately.',
'If the dragon is already present, the transformation is postponed instead of creating another copy.'
]);
add('E178',0,[113001],'Fabled King Tormax calls his royal guard into the battle.',[
'The fabled expedition replaces the ordinary Tormax encounter. Prepare for Kyenka, Yetarr, Irrek and Velden to assist their king.',
'Tormax directs surviving guards at his current top-hate target on engagement and again every five minutes. Control or defeat the guards to keep them from rejoining unattended.',
'His defeat records the fabled encounter’s expedition lockout.'
],{extra:['kael/#Gozer_The_Gatekeeper.lua'],notes:['The fabled expedition explicitly uses zone version 0. Its dynamic-zone state and NPC templates distinguish it from ordinary Kael.']});
add('E179',0,[113006,113005,113000],'Fight through the Fabled Statue and Idol to summon the Fabled Avatar of War.',[
'Engaging the Statue calls the Armor of Zek for assistance. Clear or control the defenders while finishing the Statue.',
'The Statue’s death summons the Idol; the Idol’s death summons the Avatar of War.',
'The Avatar has a one-hour availability timer that pauses while it is engaged and resumes outside combat.',
'Each defeated stage records its own fabled lockout, allowing expedition state to restore the appropriate remaining stage.'
],{extra:['kael/#Gozer_The_Gatekeeper.lua'],notes:['The fabled expedition explicitly uses zone version 0; its NPC templates and expedition state distinguish it from ordinary Kael.']});
add('E180',0,[113636,113626,113633,113492],'Provoke Doldigun to begin Kael’s successive giant challenge.',[
'Near the hidden trigger, say: The dain shall be slain for the peace we must obtain. This brings out Doldigun Steinwielder.',
'Engage Doldigun or complete his quest hand-in to begin the cycle. He calls two giants and leaves.',
'Defeating the cycle’s giants brings further opponents; remain in the event area and prepare for the next arrival after each kill.',
'Doldigun departs after ten minutes if the event is not started.'
]);
add('E181',0,[113215],'King Tormax relies on his surviving royal guards throughout the fight.',[
'Kyenka, Yetarr, Irrek and Velden are directed onto Tormax’s top-hate target as combat begins.',
'Every five minutes, Tormax calls the surviving guards back into the battle. Do not leave uncontrolled guards idle elsewhere.',
'Assign guard tanks or clear the guards before committing the raid to Tormax.'
]);
add('E182',0,[113628,113629,113627],'Defeat the Statue and Idol of Rallos Zek to call forth the Avatar of War.',[
'The Statue’s death summons the Idol. Prepare the raid for the next stage before finishing the current target.',
'Defeating the Idol summons the Avatar of War in the temple.',
'The Avatar’s one-hour depop timer limits how long the raid can leave the final encounter waiting.'
]);

add('E183',0,[160477,160476],'Roshawna’s skull ritual summons a courier and a group of reanimated Vah Shir.',[
'Complete the ritual hand-in only when your group is prepared. The sequence creates a courier and eight reanimated Vah Shir.',
'Control the supporting undead while completing the ritual’s combat objective.',
'Return to the quest giver after the fight to finish the requested collection and hand-in steps.'
]);
add('E184',0,[160481,160486],'The questioning of Autarkic Lord Sfarosh ends with a fight against him and three shadow allies.',[
'Allow the ritual dialogue to progress through the assembled spellcasters; the hostile encounter begins at its conclusion.',
'Sfarosh’s combat form appears with three shadow creatures. Have tanks ready for the simultaneous arrival.',
'The hostile Sfarosh immediately attacks the character recorded by the ritual. Complete the fight before his scripted cleanup, about twelve minutes after spawning.'
]);
add('E185',0,[74009,74013,74011,74015,74016,74017,74018,74020,74010],'Weaken Tissa by defeating her eight generals, or face the Sovereign of Terror at full strength.',[
'Enter A Fur-midable Cat-ch through Multiverse MacBeth after completing the introductory dialogue. The event requires the Kerra content flag and a nonzero instance.',
'Each general defeated reduces Tissa’s maximum health, melee damage and resistances. Clearing more generals makes the final confrontation easier.',
'Ahed splits at half health. Dou brings kittens into the fight. Siete progressively resists physical attacks, requiring the group to adapt its damage.',
'Ath must be finished by a cleric, druid or shaman; an incorrect finishing class causes the encounter to repeat.',
'Milestones for defeated generals provide additional chests. Defeating Tissa without weakening her creates a separate full-strength bonus.',
'Tissa’s death clears her remaining forces. Return the requested proof to MacBeth to complete the adventure.'
],{extra:['kerraridge/#Multiverse_MacBeth.lua','kerraridge/#Ahed,_Abbot_of_Kerra.lua','kerraridge/#Dou,_Bulwark_of_Kerra.lua','kerraridge/#Siete,_Summoner_of_Kerra.lua','kerraridge/#Ath,_Physician_of_Kerra.lua'],notes:['The launcher explicitly creates version 0. This is a gated GM event; confirm its content flag and live availability before publishing.']});
add('E186',0,[20299,20300,20301],'A blackened treant breaks apart into limbs before the dryad emerges.',[
'Defeat the Blackened Treant, then gather the Blackened Tree Limbs that replace it.',
'The limbs lead to the Blackened Dryad. Keep the group together through the successive spawns so the new target does not reach an unprepared player.',
'The dryad casts Withering Glare seven seconds after engagement and every twenty seconds thereafter. Keep healing ready for the top-hate target.'
]);
add('E187',0,[20290,20292],'A cloaked figure draws a bard into a shadow ambush that ends only when the shadow thief is defeated.',[
'A bard at the appropriate epic stage hails the cloaked figure. Ten seconds later, four malignant shadows appear around the bard and attack.',
'Each shadow immediately replaces itself on death. After the tenth shadow kill, a shadow thief appears at the dead shadow’s location.',
'Switch to the thief when it arrives; repeatedly clearing shadows alone will not end the fight.',
'The thief casts an additional spell at 25% health. Its death removes every remaining shadow and begins the event cooldown.'
]);
add('E188',0,[293212,293213,293214,293215,293216,293217],'Break the summoner ring through priests, stoneservants and a final Chaos Provoker sequence.',[
'Begin by defeating the nine Priest Summoners. Fighting the priests also brings Rav Priest Guardians into the ring.',
'Once the priests are gone, lesser and greater stoneservants appear. Their deaths reduce the Grand Summoner’s health and bring replacement golems until he is sufficiently weakened.',
'The golems take increased blunt-weapon damage and reduced slashing and archery damage. Choose weapons accordingly.',
'Keep the battle near the center of the ring. Pulling the linked opponents too far away clears hate and restores their health.',
'Defeat the exposed Grand Summoner to bring out the Hexxt Chaos Provoker. The Provoker’s death produces a final group of supreme stoneservants.'
]);
add('E189',0,[293220,293153],'Disturb the Temple of the Damned’s bones, defeat four guardians and expose the summoner.',[
'Destroying the bone pile summons four Priest Guardians and a protected summoner.',
'Kill all four guardians to make the summoner attackable.',
'The event has a fifteen-minute cleanup timer that pauses while the summoner is engaged.',
'The available summoner depends on the temple’s current respawn state. The alternate version follows the same combat sequence but does not carry the main loot pool.'
]);
add('E190',0,[85396],'Confront Vorash and Deep, then prepare for Xenevorash to appear as Vorash falls.',[
'Vorash and Deep automatically attack monks who approach within their proximity area. Bring support before entering that space.',
'Vorash’s death directly summons Xenevorash. Prepare a tank and healing for the new arrival before delivering the finishing blow.',
'Control Deep while completing the sequence; the active Vorash handler does not wait for Deep to die before summoning Xenevorash.'
],{extra:['lakeofillomen/Deep.lua'],notes:['The auxiliary monk_trigger counter is local to each signal call and does not establish a working two-kill gate. The guide follows the direct Xenevorash spawn in Vorash.lua.'],issues:['Confirm Vorash and Deep template IDs for additional NPC links; only the directly spawned Xenevorash ID is linked.']});
add('E191',0,[51153,51152],'Kazen Fecae tests a necromancer with a chain of undead opponents.',[
'Complete Kazen’s challenge hand-in with the group ready for the bone golem.',
'The bone golem’s death summons the next undead challenger at the ritual site.',
'Remain for the full succession of opponents and complete Kazen’s requested follow-up rather than leaving after the first kill.'
]);
add('E192',0,[],'The man-eating freshwater shark becomes more dangerous as it loses health.',[
'Its maximum melee hit increases at 75%, 50% and 25% health. Save tank support for the later part of the fight.',
'Keep the shark controlled while crossing each threshold, allowing healers to adjust before the next push.',
'The summoned shark despawns thirty minutes after appearing, so prepare before triggering the encounter.'
]);
add('E193',0,[27007,27009,27010],'Laura Rako’s quest exchange brings High Priestess Shima and her priestly escort into Lavastorm.',[
'Prepare before the triggering hand-in: Shima arrives with five priest adds.',
'Assign tanks or control to the escort before committing damage to the High Priestess.',
'Complete the quest’s requested follow-up after the ambush is defeated.'
]);
add('E194',0,[57151,57154,57155],'Taskmaster Mirot and his reanimated minions fight as a linked group.',[
'The quest sequence summons Mirot with six minions. Engaging either the taskmaster or a minion pulls other linked opponents into combat.',
'Minions taken more than 200 units from Mirot are brought back to him. Plan to control the group nearby rather than splitting them across the forest.',
'The summoned taskmaster has a thirty-minute cleanup timer. Finish the fight and recover the quest objective within that window.'
]);
add('E195',2,[237756,237791,237757,237789],'Turn all four chromatic bonewalkers into icy bonewalkers at the same time.',[
'Each bonewalker cycles through gray, brown, red and icy forms as it is defeated.',
'Stop attacking once a bonewalker becomes icy. Killing an icy form returns it to gray and removes it from the completion count.',
'Control completed icy forms while finishing the remaining color cycles. Four simultaneous icy bonewalkers complete the event.'
],{extra:['mirb/zone_status.lua']});
add('E196',2,[237785,237786],'Find and defeat the Frostfoot leaders before their reinforcements overwhelm the raid.',[
'Approaching the Frostfoot scout starts its run to the leaders’ room. The leaders appear when it reaches its destination.',
'Each surviving leader creates a henchman every ten seconds. Push toward Raid Leader Sig Chol and Taskmaster Suttalp instead of staying in the scout room indefinitely.',
'The event counts total spawned goblins, not only living adds. At thirty spawns, the raid is expelled and the event resets.',
'Use the warning emotes to judge urgency while tanks collect the arriving henchmen.'
]);
add('E197',2,[237797],'Laskuth the Colossus closes the Frozen Nightmare after its earlier challenges are resolved.',[
'Complete the preceding tasks for Durgin Skell to bring Laskuth into the final encounter.',
'Five sleet flurries also appear along the approach. Keep the route controlled before establishing the boss pull.',
'Every five seconds, Laskuth has a fifty-percent chance to send his current target to the back of the room and remove that target from hate lists.',
'Keep another tank ready to pick up Laskuth when the current tank is displaced. Return promptly from the back of the room while preserving the group’s healing formation.'
],{extra:['mirb/zone_status.lua','mirb/Durgin_Skell.lua']});
add('E198',2,[],'Marrow the Broken sheds pieces of himself that become attacking skeletons.',[
'Beginning at 90% health and continuing at ten-percent steps through 30%, Marrow shrinks and creates a splinterbone skeleton nearby.',
'Have an add tank ready before each threshold. Avoid pushing several health stages faster than the group can collect the skeletons.',
'Defeating Marrow advances Durgin Skell’s bone-golem objective.'
],{extra:['mirb/zone_status.lua'],notes:['The source mentions an unimplemented splinterbone dervish alternative. This guide documents only the skeleton currently spawned by the handler.']});
add('E199',2,[237772,237773,237793,237794,237795,237796],'Protect Sharalla’s remains from four starving animals.',[
'Hailing Sharalla’s warder starts the event and summons a polar bear, two snow cougars and a leopard.',
'Pull the animals away from the corpse. The corpse checks every five seconds; an animal within twenty units adds a bite toward failure.',
'Animals can drop combat and run back to the corpse. Watch for returning targets and re-establish control as soon as they can be engaged.',
'Ten bites fail the event. Kill all four animals before that happens to complete the defense.'
],{extra:['mirb/zone_status.lua']});
add('E200',2,[237748,237788,237787],'The sundering sludge divides into progressively smaller enemies.',[
'The first sludge creates four severing sludges when killed.',
'Each severing sludge creates four slippery sludges. Defeat all sixteen final sludges to complete the objective.',
'Control the splits in manageable groups and keep the original sludge inside its encounter room to avoid a leash and hate reset.'
],{extra:['mirb/zone_status.lua']});
add('E201',0,[126373,126012],'Bristlebane changes his defenses and attacks at every health stage while calling increasingly large groups of jesters.',[
'Defeat the mischievous jester to summon the King of Thieves.',
'Every ten-percent health step changes a combination of armor, resistances, melee damage and attack speed. Watch the rock-skin, glowing-aura and strength emotes.',
'He summons two jesters at 80%, three at 50% and five at 20%. Prepare additional tanks and crowd control before these thresholds.',
'Five minutes out of combat restores his health and opening state and removes his adds.',
'His two-hour availability timer pauses while engaged and resumes outside combat.'
],{notes:['The “2.0” label identifies this Bristlebane encounter implementation, not zone version 2. The reviewed script is unversioned and this draft targets the standard version 0 template.']});
add('E202',11,[59205,59206,59207,59208,59209,59308,59309,59310,59311],'Explore Mistmoore with a small group, defeat its servants and recover forbidden relics during the Archivist’s Midnight Vigil.',[
'The Archivist admits a group of three to six characters of level 50 or higher. The expedition lasts eight hours.',
'Defeat eligible servants and named enemies throughout the castle. Each new relic requires a randomly chosen eighteen to thirty-three qualifying kills.',
'A relic appears on the corpse that completes the current kill requirement. Watch the lantern announcement and check that corpse before moving on.',
'Each set contains all four relic types in a shuffled order, then begins a new set. Ordinary respawns allow continued hunting during the expedition.',
'Named enemies provide one selection from their own two-item equipment pool. Only current expedition members may claim the spoils.',
'The Vigil uses its own group expedition, progression and admission rules. Keep the group together and maintain current membership when returning to the zone.'
],{group:'The Archivist’s Midnight Vigil',type:'group',notes:['This is version 11. The separate version 10 raid and its five existing journal entries are not modified. Legacy NPC aliases remain accepted by the source; current canonical named IDs are linked here.']});
add('E203',2,[243672,243677,243636],'Weaken Valdoon through his guardians, expose the false lord and survive the true vampire’s shifting attacks.',[
'Each Guardian of Kel’Novar killed removes 30,000 health from the true Valdoon. Clear the guardians before engaging him.',
'Defeating the false Valdoon creates another guardian and enables an additional final reward container in the raid version.',
'The true Valdoon can call three to five lookouts, drain the raid’s vitality and briefly increase his melee damage. Collect lookouts quickly and reinforce the tank when his muscles-bulge emote appears.',
'Seneschal’s Petrification repeats during combat. Keep backup tank control available when the top-hate target is affected.',
'Stay inside the aviary. Leaving combat for ninety seconds heals Valdoon and clears the lookouts.'
],{notes:['The same module supports a paladin epic context with different reward handling. This draft describes the version 2 raid sequence and does not assert epic-specific loot.']});
const bondedConfig=fs.readFileSync(path.join(root,'lua_modules/bonded_hunts_config.lua'),'utf8');
const bondedZones=[...new Set([...bondedConfig.matchAll(/long_name = "([^"]+)"/g)].map(m=>m[1]))];
add('E204',0,[991136,...Array.from({length:36},(_,i)=>991100+i)],'Take a companion challenge from the Curator of Bonded Echoes and defeat an owner-bound echo with your own pet or charmed companion.',[
'Visit the Curator of Bonded Echoes in the Plane of Knowledge to select an eligible assignment. The system supports shadow knights, druids, bards, shamans, necromancers, magicians, enchanters and beastlords.',
'Travel to the assignment’s target zone and activate the hunt beacon. The echo appears beside you and scales to at least your level plus two, subject to the target’s own level.',
'Only you and your pet or charmed companion may enter the echo’s hate list for the victory audit to pass. Avoid assistance that would involve another character in the fight.',
'Druids, bards and enchanters receive an accompanying creature for the charm approach. Establish your companion before committing to the echo.',
'The echo expires after twenty minutes. A failed audit leaves the assignment available to retry.',
'After an accepted victory, return to the curator. Recharge assignments require carrying the assigned partially charged physical wand before activating the hunt.'
],{zone:'poknowledge',group:'Bonded Hunts',type:'event',sections:[{id:'target-zones',title:'Hunt destinations',paragraphs:['The curator offers thirty-six targets across these zones. The assignment identifies the exact echo and destination.'],bullets:bondedZones,items:[]}],notes:['The document is linked to the Plane of Knowledge curator hub because the schema supports only one zone per document. Echo NPC links cover all thirty-six targets, but automatic zone-page links do not cover the target destinations. Version 0 identifies the hub; activation checks the target base zone ID rather than prescribing a target instance version.'],issues:['Confirm deployment of the native Bonded Hunt combat audit before publication; the Lua reward transition depends on its audit result.']});

add('E205',0,[227321,227003,227002],'Follow the Luggald Broodmother’s procession and defeat the defenders it leaves along the route.',[
'The procession creates increasingly large groups of defenders and archseekers at successive waypoints.',
'Clear the waves while following its route; the final stop replaces the moving NPC with the hostile Broodmother.',
'The final encounter has a one-hour depop timer that pauses during combat. Its cleanup also removes remaining procession defenders.'
]);
add('E206',200,[],'The seasonal Cragbeast Queen favors piercing weapons and calls nearby creatures to her defense.',[
'Use piercing or two-handed piercing attacks where possible. The script increases those damage types while sharply reducing blunt, slashing, archery and throwing damage.',
'Every forty-five seconds in combat, the Queen draws idle creatures within a wide surrounding area onto her top-hate target.',
'Clear the nearby shore before the pull and keep an add tank ready for the call-for-help emote.',
'Leaving combat stops the repeated assist calls.'
],{notes:['Version 200 is explicitly supported for the seasonal instance. The ability timer started by the script has no matching ability handler, so no additional timed special attack is claimed.']});
add('E207',0,[280090,280091],'Defeat the ritual’s Reborn, expose the Conduit and finish the Noc Juggernaut it releases.',[
'The Conduit starts protected with ten Reborn nearby. Six Reborn deaths make it attackable.',
'At 30% health, the Conduit is replaced by a Noc Juggernaut. Keep the tank ready for the new target.',
'The Juggernaut uses Energy Conduit seven seconds after engagement, then every twenty seconds.',
'Both principal enemies must stay close to the ritual site; leaving the leash area heals them and clears hate.',
'The event’s spawned stages have fifteen-minute cleanup timers. Avoid a long pause during the transition.'
]);
add('E208',0,[280084,280089],'Break Spiritbinder Trenzar’s hold over Senvial of the Mist.',[
'Trenzar creates a bound elemental every ten seconds and brings the elementals onto the group’s hate list. Keep add control active throughout the fight.',
'Wave of Flame begins fourteen seconds after engagement and repeats every twenty seconds.',
'Senvial appears after a random delay of seventy-five to one hundred twenty seconds. Bring Senvial to 10% health and defeat Trenzar to release the spirit.',
'Once Senvial stops fighting, the eligible ranger should hail the freed spirit to advance the epic quest.'
]);
add('E209',0,[280094,280095,280096],'Strip away Tybone’s armored and fleshy forms in the Skin and Bones expedition.',[
'Complete the two gathering sets, then use Tybone’s give chase dialogue to request the group expedition.',
'The opening armored form favors blunt weapons. At 67% health, two dominated defenders appear and Tybone becomes untargetable until both are defeated.',
'The middle form favors non-blunt weapons. At 34%, two mastruq defenders appear and protect him through another transition.',
'After both defenders die, the final skeletal form again favors blunt damage. Re-establish the tank after each transition because Tybone clears his hate list.',
'Keep alternate weapon types available and finish each defender pair before trying to damage Tybone again.'
],{notes:['The launcher explicitly creates version 0. Availability requires Gather_2022_event. The combat handler also applies additional abilities when the initiating character belongs to guild ID 40; confirm whether this historical special case remains intended.'],issues:['Confirm the gathering event flag and guild-specific tuning before publication.']});
add('E210',0,[123255,123247,123253],'Release Garzicor through a corpse battle followed by his wraith.',[
'Complete the ghostly presence’s summoning sequence to bring out Garzicor’s Corpse.',
'Defeat the corpse within its twenty-minute availability window. Its death summons Garzicor’s Wraith.',
'The wraith remains for thirty minutes. Defeat it, then speak with the resulting shade to continue the quest.'
]);
add('E211',0,[123173,123175,123176,123177,123178,123179,123180,123181,123189],'Defeat Vesthon’s seven masters, then confront Vesthon and the Dracoliche of Hsagra.',[
'Each master has two minions. The minions respawn while their master lives and disappear when that master is defeated, so focus the masters while controlling their servants.',
'Masters become actively hostile at 80% health. Coordinate the first damage push so the raid is ready when each one engages.',
'Do not drag minions beyond their short leash: returning them increases their damage.',
'Defeating all seven masters activates Vesthon. His death replaces Hsagra’s relics with the Dracoliche for the final battle.',
'The event has a two-hour cleanup timer. Complete the full sequence before its expiry.'
]);
add('E212',0,[123167,123171],'Interrupt Vesthon’s first attempt to seize Hsagra’s spirit.',[
'Vesthon begins a short ritual scene and calls mercenary guards into the area. Prepare for the guards as the dialogue progresses.',
'Drive Vesthon to 20% health to break the confrontation. He is replaced by a retreating form and Hsagra’s shade is released.',
'The hostile form has a three-minute despawn timer, so establish combat promptly after the ritual begins.'
]);
add('E213',200,[],'Respond to Zlandicar’s surgical warnings, destroy his constructs and prevent risen corpses from healing him.',[
'Shared Incision: stack within twenty-five feet of the marked player before the eight-second warning ends. The damage is shared by the players in the stack.',
'Septic Burst: move more than twenty-eight feet from the marked player before the eight-second warning ends.',
'Lobotomy: the marked player must stop casting before the eight-second warning ends. Casting at resolution causes an interrupt, silence and damage.',
'At 70% health, Zlandicar becomes untouchable and summons surgical constructs. Destroy all constructs to expose him; allowing the seventy-two-second operation to time out heals him.',
'At 40%, risen corpses begin restoring his health every eight seconds. Kill them promptly.',
'At 20%, the warning rotation accelerates. A final priority corpse appears if none remain, so keep add damage available during the burn.'
],{extra:['lua_modules/seasonal_velious.lua'],phases:[['Operating table','70% health','Switch all damage to the surgical constructs while Zlandicar is protected.'],['Risen corpses','40% health','Destroy the healing corpses while continuing to respond to the surgical warnings.'],['Final procedure','20% health','Warnings arrive more quickly. Clear the final corpse before committing to the boss.']],roles:[{id:'tank',label:'Tanks',tips:['Hold Zlandicar while the group responds to the marked-target mechanics. Collect each construct or corpse wave promptly.']},{id:'group',label:'Everyone',tips:['Read the named warning before moving: Incision requires a stack, Septic requires separation, and Lobotomy requires stopping casts.']}],notes:['The seasonal helper accepts configured seasonal versions, with 200 as the default and 200–249 fallback support. This document targets the standard version 200 seasonal expedition.']});
add('E214',0,[221041],'Terris Thule calls dream defilers and animated statues while trapping the raid inside her nightmare.',[
'Keep Terris inside her lair. Pulling her outside the scripted boundaries despawns her.',
'At 75% health, fifteen dream defilers appear: eight use the mezzable template and seven use the unmezzable template. Assign crowd control and tanks accordingly.',
'At 50%, she casts Direption of Dreams; at 45%, Defilement of Hope targets the top of her hate list.',
'At 35%, four statues animate. Pick them up while the raid finishes Terris.',
'After victory, speak with the planar projection for progression credit.'
]);
add('E215',0,[13130],'A dire griffon changes into a plains dragon when reduced below 80% health.',[
'Prepare the group before crossing the griffon’s health threshold.',
'The griffon disappears and the dragon appears in its place. Switch targets and establish the dragon on the tank immediately.',
'If the dragon is already present, the script waits rather than summoning another copy.'
]);
add('E216',0,[37003,37002],'Protect Keelee through a personal paladin trial, then pursue Remal to the orc camp.',[
'An eligible paladin hails Keelee to start. During the initial ritual, other nearby characters are banished away; the initiator must handle the waves.',
'Four waves of four orcs arrive at two-minute intervals. All orcs must be dead when the final wave timer is checked or the event fails.',
'On success, Keelee and Remal move to another orc camp and Remal becomes attackable. Follow Keelee’s shout to the new location.',
'Defeat Remal within the final one-hour window, then the initiating paladin must hail Keelee for credit.'
]);
add('E217',0,[93308,93311],'Granika reveals a glowing cliff golem guarded by undead watchmen and Watch Sergeant Grolj.',[
'Complete Granika’s Greenmist quest hand-in only after preparing the group.',
'The reveal creates the glowing golem, four undead watchmen and Grolj together at the outpost.',
'Control the watchmen while the group defeats the principal targets and recovers the required quest objective.'
]);
add('E218',200,[],'Seasonal Lady Vox calls ice creatures and adds spell attacks at key health thresholds.',[
'Two ice adds appear at 70% health and three more at 40%. Reserve tanks and control for both waves.',
'Vox casts additional scripted spells at 60%, 30% and 10%. Prepare tank support before crossing those thresholds.',
'Keep Vox near her lair’s spawn area. Her combat routine checks the leash continuously.',
'The seasonal version uses a different appearance and permits the expedition’s higher-level participants; the ordinary version’s level-based banishment is not used here.'
],{notes:['This guide targets the explicit version 200 seasonal branch. The 50%, 20% and 5% messages are flavor-only in the reviewed configuration, so no additional damage mechanic is claimed at those thresholds.']});

add('E219',0,[215408,215429,215409,215410,215411],'Defeat the island’s castellans and constables to summon Chamberlain Escalardian.',[
'The fifth, tenth and fifteenth qualifying castellan kills summon a constable. Defeat all three constables to reach the final opponent.',
'Escalardian periodically clears his hate list. Tanks should be ready to reclaim him while damage dealers avoid an uncontrolled burst.',
'If the main encounter is already on cooldown, an apprentice appears instead.',
'The island event has a three-hour failure timer.'
]);
add('E220',0,[215423,215434,215400],'Clear the confused elementals, defeat four champions and confront the Elemental Masterpiece.',[
'The sixth, eleventh, sixteenth and twenty-first qualifying elemental kills bring out champions. The last required elemental’s death activates the champions.',
'Defeat all four champions to summon the Masterpiece or its cooldown replacement, an Elemental Anomaly.',
'The Masterpiece has a chance to clear hate every twelve seconds. Keep a tank ready to re-establish control.',
'Victory can summon the Avatar of Smoke when that progression stage is available. The island sequence must finish within three hours.'
]);
add('E221',0,[215435,215437,215385,215388],'Clear the phoenix island, then defeat firesurgers, windsurgers and Melernil Faal’Armanna.',[
'Clear all twenty designated island creatures to summon four phoenix firesurgers.',
'Defeat the four firesurgers to summon four windsurgers. Defeat those to bring out Melernil or the cooldown replacement.',
'Melernil may clear hate every twelve seconds. Prepare tanks to regain control throughout the final fight.',
'Victory can summon the Avatar of Mist. The event has a three-hour failure timer.'
]);
add('E222',0,[215375,215392,215393],'Defeat the spider island’s inhabitants and archwalkers to expose Sigismond Windwalker.',[
'Clear all thirty-eight designated island spawns. Sigismond grows as the required kill count advances.',
'Defeat three archwalkers. The first two deaths produce smaller vorladien spawn that must be cleared before the next archwalker appears.',
'During the later stages, erratic arachnids replenish while the archwalker or Sigismond is engaged. Assign sustained add control.',
'The third archwalker’s death makes Sigismond attackable. His periodic hate clears require active tank recovery.'
]);
add('E223',0,[215419,215390,215417,215433,215430],'Break the stormrider formations and identify Pherlondien Clawpike among his copies.',[
'Clear the twenty-seven designated island creatures to summon the Priest of Destruction and eight protected stormriders.',
'The priest activates two stormriders at a time, beginning shortly after engagement and then every thirty seconds. Secure each pair as it becomes active.',
'After the priest, defeat three sporadic stormriders to trigger the final formation.',
'Pherlondien appears at a randomized position among four false copies. The cooldown state can instead produce a loathesome stormclaw.',
'The final named opponent can clear hate; keep tanks ready while identifying and finishing the real target.'
]);
add('E224',0,[215056,215416,215414,215421,215399,215422,215418],'Defeat Xegony’s six guard formations before attempting to finish the Queen of Air.',[
'At 99%, 85%, 70%, 55%, 40% and 25% health, Xegony activates one previously unused guard family and its named leader.',
'The family order is randomized. Watch the active formation and assign tanks before pushing the next threshold.',
'Guard leaders can clear their hate lists, and idle guards are called back into the fight. Maintain control rather than leaving loose adds unattended.',
'At 13%, Xegony returns to 25% health if any of the six named leaders remain alive. Kill every leader before the final push.',
'After an extended break from combat, the event resets. Victory produces the Essence of Air.'
]);
add('E225',0,[218366,218391,218376,218348],'Clear the dust temple to release the Triumvirate of Soil and the Perfected Warder of Earth.',[
'Defeat all thirty-two Dust Devotees to activate the three members of the Triumvirate of Soil.',
'Defeat the Triumvirate to summon the Warder, or a Dust Follower if the main encounter is on cooldown.',
'The Warder randomly uses one of three setups: additional Soil enemies, repeated groups of three to six protectors, or no added wave.',
'The boss can clear hate every twelve seconds. Have tanks ready for both a target reset and a new protector group.',
'The overall event allows three hours, while the final boss has a fifty-minute hard despawn.'
]);
add('E226',0,[218371,218358,218355],'Break apart the Sludge Lurker repeatedly, then defeat the Filth Gorgers and Monstrous Mudwalker.',[
'Clear the required mudwalkers and seekers to summon the Sludge Lurker.',
'Across four successive stages, the lurker breaks into ten muck mudlets at 75%, 60%, 40% and 15%. Kill every mudlet to reform the lurker and continue.',
'Finish the lurker sequence and the four Filth Gorgers to summon the Monstrous Mudwalker, or the cooldown replacement.',
'The final boss can clear hate, and killing a player or pet creates additional mudlets. Minimize casualties while keeping an add tank available.',
'Complete the ring within three hours and the final boss within its fifty-minute appearance window.'
]);
add('E227',0,[218363],'Defeat the Mystical Arbitor after progressing through the Earthen Badlands’ rings.',[
'Prepare before the Arbitor appears: it has a fifty-minute hard despawn timer.',
'Every twelve seconds in combat, it has a chance to wipe its hate list. Tanks should watch for a sudden target change and reclaim it quickly.',
'After the Arbitor dies, speak with the planar projection for progression credit.'
]);
add('E228',0,[218388,218029,218359,218383],'Clear the stone formations, reunite the rubble and defeat six waves of heaps to expose Peregrin Rockskull.',[
'Clear the four required stone-creature families and the fortifications their deaths create.',
'Four mounds of rubble appear. Reduce each to 10% so it becomes protected and returns to the temple center.',
'When all four mounds assemble, the Rock Monstrosity reforms and becomes attackable. Defeat it to begin Peregrin’s protection phase.',
'Six waves of four stone heaps guard the final boss. Clear all six waves to activate Peregrin or the cooldown replacement.',
'The final boss can clear hate and has a fifty-minute hard despawn after activation. The entire ring has a three-hour limit.'
]);
add('E229',0,[218385,218395,218345],'Clear tainted rock beasts and bloodthirsty vegerogs to summon Derugoak Bloodwalker.',[
'Every three tainted rock-beast kills produces a bloodthirsty vegerog. Clearing the last required beast makes the vegerogs attackable.',
'Defeat all ten bloodthirsty vegerogs to summon Derugoak, or a bloodsoaked replacement if the main ring is on cooldown.',
'Derugoak summons two mangled vegerogs at 75% health and four at 25%. Prepare add tanks before either threshold.',
'The boss periodically clears hate. A five-minute break clears his adds and resets his health-stage triggers.',
'The ring has a three-hour limit and the final boss a fifty-minute hard despawn.'
]);
add('E230',0,[222140,222141,222147],'Bring all twelve Rathe Council members down within one short kill window, then defeat the Avatar of Earth.',[
'Six council members use the mezzable template and six cannot be mezzed. Divide crowd control and tank assignments before engaging.',
'Council members become less accurate and deal less damage at 75%, 50%, 25% and 11% health. Lower them in a controlled order before beginning the final kills.',
'Banishment continues while a council member has at least 11% health and is not mezzed. Protect the tank rotation while preparing the council.',
'The first death starts a 345-second window. Kill all twelve before fallen members respawn.',
'The final council death summons the Avatar of Earth. Defeat it within fifty minutes and interact with the resulting essence.'
]);
add('E231',0,[222151,222152,222153,222154],'Defeat three war chieftains to unlock Warlord Gintolaken.',[
'Clear the Rock Studded Champions, Myrmidons of Stone and Stonefist Clansmen to expose their associated chieftains.',
'Defeat Galronar, Awisano and Birak. Their expedition lockouts jointly unlock Gintolaken.',
'Gintolaken wipes hate every three minutes and has additional low-chance hate clears between those resets. Keep multiple tanks ready.',
'The named stages have fifty-minute hard despawns; the overall event has a three-hour failure limit.',
'Victory produces a planar projection for progression credit.'
]);
add('E232',0,[217050,217450,217426,217439,217440,217428,217429,217449,217453,217436],'Defeat Doomfire’s armies and Council of Fire to reach Fennin Ro.',[
'Defeating the Guardian of Doomfire begins the timed sequence.',
'Clear the first army formations, then the bridge forces and four named leaders: Reaxnous, Azobian, Hebabbilys and Javonn.',
'Defeat Chancellor Kirtra, Chancellor Traxom, Omni Magus Crato and Warlord Prollaz to summon Fennin Ro.',
'The final phase also enables elite guardians. Keep the route controlled while establishing the main tank on Fennin.',
'A fresh event begins with a two-hour limit. Existing expedition phase lockouts can resume the encounter at a later stage with a shorter remaining allowance.'
],{phases:[['Doomfire army','Guardian of Doomfire defeated','Clear all required first-stage formations.'],['Bridge','First army cleared','Defeat the bridge forces and their four leaders.'],['Council of Fire','Bridge cleared','Defeat all four council opponents.'],['Fennin Ro','Council defeated','Control the elite guardians and defeat the Tyrant of Fire.']]});

add('E233',0,[206046,206205],'Stop the clockwork devices from feeding the inactive Manaetic Behemoth, then defeat its active form.',[
'Intercept the devices before they reach their final waypoint and release an energy burst. Leave a team on each approach while preparing the main tank.',
'The dormant Behemoth uses a three-minute activation counter influenced by device bursts. Keep the incoming devices controlled until the active form appears.',
'The active Behemoth has a twenty-minute hard despawn. Fight inside its room to avoid its positional leash.',
'Its death advances the local gnome’s progression dialogue.'
],{extra:['poinnovation/206001.lua','poinnovation/AOE_Trigger.lua'],issues:['Verify the dormant activation sequence in game before publication: repeated device signals toggle first_signal and only alternate signals reset the counter, rather than consistently resetting it.']});
add('E234',0,[206067],'Help Nitram Anizok activate Xanamech Nezmirthafen, then defeat the construct and return to Nitram.',[
'Complete Nitram’s three-component hand-in and accompany him along his route to the inactive dragon.',
'At the activation point, the real Xanamech replaces the inactive model. Prepare the group before Nitram arrives.',
'The attempt has a two-hour failure timer. After Xanamech falls, return to Nitram promptly: the victory state resets after ten minutes.'
]);
add('E235',0,[201074],'Face the Seventh Hammer in the chamber of judgment.',[
'The Seventh Hammer offers a challenge through its judgment dialogue. Assemble the group before accepting.',
'When the Tribunal cycle is active, the encounter alternates judgments on a seventy-five-second timer after its opening warning.',
'Keep healing ready for repeated area pressure and defeat the Hammer before leaving the chamber.'
],{issues:['Confirm the intended Tribunal timer behavior before publication: the ready dialogue sets dialogue=true, while the combat handler starts the judgment timer only when dialogue is false. The dialogue-triggered fight may omit that cycle.']});
add('E236',0,[201470],'Save the prisoner from execution through four waves, then defeat Prime Executioner Vathoch.',[
'Each wave brings four enemies while the executioner moves toward the prisoner. The executioner moves faster with each new wave.',
'Killing a wave enemy interrupts the executioner and sends it back when more enemies remain. Use those kills to buy time, then finish the wave.',
'Do not allow the executioner to remain at the prisoner. Its execution timer causes the trial to fail.',
'After the fourth wave is cleared, defeat Vathoch to complete the trial.'
],{extra:['pojustice/#an_executioner.lua']});
add('E237',0,[201495],'Survive four waves and the trial’s flame pressure, then defeat the Punisher of Flame.',[
'The first wave begins after twenty seconds. Four enemies arrive in each wave, with subsequent waves scheduled ninety seconds apart.',
'The flame controller activates during the trial. Keep the group healed while clearing enemies before the next timed wave.',
'Defeat every enemy from the fourth wave to summon the Punisher of Flame.'
]);
add('E238',0,[201489],'Prevent the prisoners from suffocating and defeat Gallows Master Teion.',[
'Each round brings ordinary enemies and two suffocating spirits, with the second spirit arriving forty-five seconds after the first.',
'Spirits threaten different prisoners. Prioritize them as they appear; killing a spirit provides relief to its prisoner.',
'Every two spirit kills advances the next round. After eight spirits have been defeated, Gallows Master Teion appears.',
'Keep the prisoners alive through the final fight to finish the trial.'
]);
add('E239',0,[201461],'Break the spirits sustaining the Scourge before it reaches the prisoners, then defeat Lashman Azakal.',[
'Each round begins with four enemies. Thirty seconds later, three Flickering Spirits appear and an invulnerable Scourge moves toward a prisoner.',
'Kill all three spirits to remove the Scourge. Spending damage on the invulnerable Scourge delays the rescue.',
'Complete four spirit rounds while controlling the other enemies, then defeat Lashman Azakal.'
]);
add('E240',0,[201508],'Protect the accused from their avengers through four waves and defeat Yurae Zhaleem.',[
'Four enemies arrive per wave on a ninety-second schedule. Avengers threaten the accused while enemies remain alive.',
'Clear the active wave quickly to remove the avengers and stop their pressure on the prisoners.',
'The sixteenth required wave kill summons Yurae Zhaleem. Establish the boss tank while protecting the survivors.'
]);
add('E241',0,[201496],'Keep the tortured prisoners alive through four enemy waves, then defeat Punisher Veshtaq.',[
'Four-enemy waves arrive two minutes apart. Pick up every incoming enemy before it reaches the prisoners.',
'Clearing an early wave summons a Wraith of Agony. Defeat it to produce the relief effect for the trial.',
'After all sixteen wave enemies are defeated, Punisher Veshtaq appears. Finish the boss while maintaining protection of the prisoners.'
]);
add('E242',0,[204080],'Escort Aid Eino through successive nightmare ambushes and defeat the Dreamkeeper.',[
'Stay close to Eino and immediately intercept the attackers targeting him. The route includes banshees, nightstalkers, hobgoblins and bats.',
'The largest ambush combines three banshees with three bats. Preserve crowd control and healing for that stop.',
'After a brief rest, Eino reaches the Dreamkeeper. Protect Eino while killing the boss, which despawns after sixty seconds without combat.',
'Follow Eino to the return portal and complete the requested quest hand-in there.'
]);
add('E243',0,[],'Deyid the Twisted closes a ring of trees around the raid before bringing part of it to life.',[
'At 90% health, eleven trees appear around the encounter.',
'At 60% and 50%, the ring moves inward. Keep room to adjust your positioning as the trees close around you.',
'At 40%, four trees become active enemies. Assign add control before crossing this threshold and maintain the main tank on Deyid.',
'Deyid’s death removes the summoned trees and advances the local event.'
],{phases:[['Encirclement','90% health','A ring of eleven trees surrounds the fight.'],['Closing ring','60% and 50% health','The trees move progressively closer.'],['Living trees','40% health','Four trees become attackable opponents.']]});
add('E244',0,[204039],'Intercept the servants approaching Mujaki’s platform, then defeat Mujaki the Devourer.',[
'Clear the nightmare steeds around the platform to start the event.',
'Seven waves bring thirty-six servants in total from three grave sites. Intercept them before they reach the platform: unengaged servants that arrive become mounted riders.',
'Each wave is timed, so avoid letting stronger riders and fresh servants accumulate together.',
'When all thirty-six servants have either died or transformed, Mujaki becomes attackable. Control the remaining riders while defeating him.'
],{notes:['The module calls this platform Instance 1, but it selects physical spawn-point and coordinate boundaries. This is not evidence of zone version 1. The unversioned zone is represented as version 0.']});
add('E245',0,[204034],'The Terror Matriarch continually produces hatchlings once the fight begins.',[
'At 98% health, the first abhorrent hatchling appears; another can spawn every thirty seconds while the Matriarch remains engaged.',
'Assign someone to gather the hatchlings away from vulnerable group members while the main tank holds the Matriarch.',
'Finish the boss before the recurring adds overwhelm the group. Each summoned hatchling has its own five-minute cleanup timer.'
]);
add('E246',0,[210480],'Clear the pond creatures to call Drornok Tok Vo’Lok, then handle two additional frog waves during the fight.',[
'Defeat the required loktoles, amphans and toads around the pond to summon Drornok.',
'Drornok repopulates the pond when he appears. Establish a controlled approach before pulling him.',
'At 97% and 50% health, he summons two more pond creatures directly onto the tank’s hate target. Prepare an add tank at both thresholds.',
'If he heals to 99%, his summoned combat adds are cleared and both health triggers reset.'
]);
add('E247',0,[210468],'Clear the burning and blazing mephits to summon Falto, Lord of Thunder.',[
'The event stages a decrepit ent and a field of mephits. The boss trigger specifically checks that no burning or blazing mephits remain.',
'Sweep the whole event area for stragglers before expecting Falto to appear.',
'Once the final required mephit is gone, gather the group at the trigger location and engage Falto.'
],{notes:['The reviewed controller establishes Falto’s spawn objective but does not define additional boss health phases.']});
add('E248',0,[210469],'Clear the Koka’Vor defenders, defeat their reinforcements, and summon Ston’Ruak, Ancient of Trees.',[
'First clear the Koka’Vor krovians, senvars and chieftains monitored by the event.',
'Eight elders or chieftains then appear in two groups of four: one in the boss chamber and one to the northwest.',
'Defeat all eight reinforcements while keeping the original required defenders cleared. This summons Ston’Ruak in the chamber.'
]);
add('E249',0,[214296,214299,214052,214298],'Defeat the Zek brothers, drive Rallos into the arena, then finish the Warlord amid waves of animated corpses.',[
'Defeat Berik and Grunhork to summon the event versions of Vallon and Tallon. This opening phase has twenty minutes.',
'Vallon creates two copies at 50% health. Defeat both real brothers to activate Rallos on the upper level.',
'Upper Rallos summons two Decorin Elites at 75% and again at 65%. At 55%, he moves the fight into the arena. This stage has fifteen minutes.',
'In the arena, seven corpse adds begin arriving roughly sixty-five seconds into combat and then every fifty-five seconds. Keep an add team ready while damaging the Warlord.',
'The final stage has twenty minutes. The script also banishes players beyond seventy-two clients on Rallos’s hate list.'
],{phases:[['The brothers','Berik and Grunhork defeated','Defeat Vallon and Tallon; control Vallon’s copies at half health.'],['Upper Rallos','Both brothers defeated','Handle elite pairs at 75% and 65%, then push Rallos to 55%.'],['The Warlord','Upper Rallos reaches 55%','Move into the arena, control recurring corpse waves and defeat the real Rallos.']]});
add('E250',0,[214026],'Tallon Zek teleports around his chamber and repeatedly switches targets.',[
'Expect a shadowstep every six to eighteen seconds. Reposition without scattering the group outside the chamber.',
'His hate changes favor a player at 30% health or lower when one is available. Keep injured players topped up and tank pickups ready.',
'Players close to Tallon can trigger Avatar Power, an area knockback on a twenty-second cycle after the opening check.',
'After victory, hail the planar projection promptly; it remains for ten minutes.'
]);
add('E251',0,[214295],'Identify the real Vallon Zek through five rounds of copies, then defeat his final form.',[
'Before the final round, pushing the real Vallon to 61% health makes him vanish and summons five figures: one real Vallon and four copies.',
'Control the copies while locating the real target. Repeat the 61% transition through five split rounds.',
'The real Vallon in the fifth replacement group can be killed normally. Defeat him and approach the resulting planar projection.',
'An unengaged event can reset after twenty minutes, removing the copies.'
],{issues:['Verify or repair the planar projection’s flag dialogue before publication: PP_Say defines vallon_bucket but tests the undefined tallon_bucket variable.']});

add('E252',0,[223075,223076,223077,223078],'Defeat Terris Thule, Saryrn, Tallon Zek and Vallon Zek to complete the fourth phase of the Plane of Time.',[
'Terris Thule summons four adds at 91%, 51% and 11% health. Reserve add tanks for each threshold.',
'Saryrn also summons four adds per wave, at 96%, 51% and 11%. Avoid crossing a fresh threshold while the previous wave is uncontrolled.',
'Vallon creates two copies at 51% health. Hold those copies while continuing damage on the real Vallon.',
'Keep Tallon and Vallon inside their tethered area. Leaving it returns the boss home and clears hate.',
'Defeat all four gods to advance. Terris, Saryrn and Vallon reset their scripted stages after five minutes out of combat.'
]);
add('E253',0,[223142,223166,223167,223168],'Defeat Bertoxxulous, Cazic Thule, Innoruuk and Rallos Zek to open the final phase of the Plane of Time.',[
'Bertoxxulous gains melee strength at 90%, 70% and 55%, while his spell resistances fall. Below 40%, 30% and 10%, those changes reverse. Plan the strongest tank support around the middle of the fight.',
'Innoruuk summons three or four adds at 75%, then four or five at 20%. Establish add control before pushing either threshold.',
'Rallos summons four adds at 90%, 75% and 50%, then five at 25%. At 75% he gains area rampage and faster attacks; at 50% he gains flurry; at 25% his attacks accelerate again.',
'Maintain steady tank healing on Cazic Thule and finish all four gods to advance to Quarm.'
],{notes:['The Cazic Thule quest handler records completion and loot initialization; it does not define additional combat mechanics. Database spell lists have not been reviewed.']});
add('E254',0,[223169,223170,223171,223172,223173,223118,223134,223096,223146,223127,223073,223074],'Clear the five opening trials, their second-stage armies, and eight further waves to reach the gods of Time.',[
'Phase one contains the earth, air, undead, water and fire trials. Complete all five before the next phase opens.',
'Phase two presents four elemental formations with their named leaders. The undead lane advances through groups of two, two and four guardians before Ralthos Enrok appears.',
'Phase two completes only when all forty elemental kills and nine undead kills have been credited.',
'Phase three runs eight waves, each advancing after ten required kills. The final pair is the Avatar of the Elements and the Supernatural Guardian.',
'The expedition tracks phase lockouts and can resume at a later stage. Follow the zone’s timer announcements and avoid leaving required enemies alive behind the raid.'
],{phases:[['Five trials','Expedition begins','Complete earth, air, undead, water and fire.'],['Second formations','All opening trials complete','Clear the elemental armies and the undead sequence.'],['Eight waves','Phase two complete','Defeat ten required enemies in each wave, then both final guardians.']]});
add('E255',0,[223201],'Quarm loses heads as its health falls, changing its area spells while renewing a trio of time vortexes.',[
'Three time vortexes appear almost immediately on engagement and are replaced every fifty seconds. Assign add control without losing pressure on Quarm.',
'At 76%, 51% and 26% health, a head is destroyed. Each transition removes all buffs from Quarm and changes its available area spells.',
'Area spells normally repeat every twenty-five seconds. A head transition resets that timer to six seconds, so prepare the raid for a quick follow-up.',
'Reapply needed debuffs after each head loss. Keep healers ready as the spell set narrows through the final phase.',
'Leaving combat removes the vortexes. Five minutes without combat restores Quarm’s health, heads and opening phase.'
],{phases:[['Four heads','Pull to 76%','Control three vortexes and the opening five-spell selection.'],['Three heads','76%','Quarm loses the red head, clears its buffs and changes its spell set.'],['Two heads','51%','The blue head is lost and the spell set changes again.'],['Final head','26%','The white head is lost; finish Quarm through the final two-spell selection.']]});
add('E256',0,[207004],'Stop Maareq’s minions from reaching him while he transforms into an increasingly dangerous melee opponent.',[
'A minion appears every five seconds during combat. Any minion that gets within five units of Maareq is absorbed and heals him for 6,000 health.',
'Intercept the minions on their approach instead of letting them collect at the boss.',
'At 80%, Maareq grows. At 60%, he transforms and attacks faster. At 40%, he gains area rampage.',
'Prepare melee healing for the final stage. His death removes the remaining minions and activates Tylis Newleaf.'
]);
add('E257',0,[207027],'Salczek the Fleshgrinder becomes dangerous to nearby attackers as his health falls.',[
'At 40% health, Salczek gains area rampage. Keep vulnerable group members away from his melee range.',
'At 20%, area rampage becomes more frequent. Reserve defensive abilities and additional melee healing for the finish.',
'After five minutes out of combat, the scripted rampage state resets.'
]);
add('E258',0,[207001,207052],'Saryrn calls repeated raven waves and brings Sorrowsong into direct combat near the end.',[
'At 98%, Saryrn signals Sorrowsong to begin singing.',
'At every ten-percent threshold from 90% through 10%, Saryrn summons three or four servants or twisted spirits. Prepare add pickups before each health push.',
'At 25%, Sorrowsong becomes an active opponent. Assign a tank for it before crossing that threshold.',
'Keep add damage controlled through the last two raven waves and finish Saryrn. A planar projection appears after her death.',
'Five minutes without combat resets the scripted health progression and restores Sorrowsong.'
]);
add('E259',0,[208074,208176],'Aerin’Dar activates pairs of golem guards as the fight progresses.',[
'Two golems activate at each of 80%, 60%, 40% and 20% health. They choose targets from Aerin’Dar’s hate list.',
'At 20%, the named golem Rhalgon also activates. Reserve enough tanks and healing for the largest final wave.',
'Control each pair before driving the boss through the next health threshold.',
'If Aerin’Dar heals back to 98%, the golems repopulate and the health stages reset. Defeat the dragon and approach the planar projection.'
]);
add('E260',0,[216048,216279,216278,216280],'Clear Coirnav’s elemental armies, defeat three lieutenants, then kill the Avatar of Water within fifteen minutes.',[
'The event begins with twenty-five vaporfiends. Twenty-five icefiends arrive at three minutes and twenty-five waterfiends at five minutes.',
'Clear all seventy-five fiends to replace the initial lieutenants with their final combat forms: Pwelon of Vapor, Nrinda of Ice and Vamuil of Water.',
'Defeat all three lieutenants to make Coirnav vulnerable. Thirty weaker elemental adds arrive at the same time.',
'Assign add tanks for the final wave and immediately establish a tank on Coirnav after its hate reset.',
'The entire event has a fifteen-minute limit. Failure banishes the zone’s players; victory summons the Essence of Water.'
],{phases:[['Elemental armies','Start, three minutes and five minutes','Clear twenty-five fiends of each of vapor, ice and water.'],['Three lieutenants','All fiends defeated','Kill Pwelon, Nrinda and Vamuil.'],['Coirnav','All three lieutenants defeated','Handle thirty final adds and defeat the now-vulnerable Avatar before time runs out.']]});
add('E261',0,[316073],'An eligible shaman awakens the Lightning Warrior Spiritseeker and its two spiritsappers.',[
'Approaching the stilled warrior at the appropriate epic stage replaces it with the Spiritseeker.',
'Engaging the Spiritseeker summons two lightning warrior spiritsappers at its location. Establish add control immediately.',
'After disengagement, a fifteen-minute cleanup timer removes the Spiritseeker and its spiritsappers.'
],{issues:['Check the Spiritseeker cleanup timer before publication: the combat handler starts it on disengagement but does not stop an existing timer when combat resumes.']});

add('R007',0,[294042],'Reach and defeat the Pixtt Annihilator in the Chambers of Singular Might.',[
'Follow the accessible passage through the first group trial. The later sealed doors are not part of the route for Singular Might.',
'Defeat the Pixtt Annihilator to complete the named encounter. Its death grants a seven-hour replay lockout to the expedition.'
],{type:'group',extra:['lua_modules/constants/instance_versions.lua'],issues:['Review the Pixtt Annihilator’s database abilities and trial spawns before publishing a detailed combat guide; the quest handler supplies completion logic only.']});
add('R008',2,[349032],'Rescue Neran Sporestomp in Illsalin, then enter the Korlach mission and force Bilitan the Alchemist to appear.',[
'The mission begins with the cocoon rescue in the open-world Ruins of Illsalin. Follow Neran’s rescue sequence toward the instance entrance.',
'Inside the Temple of the Korlach, the scripted objective counts thirty-five to forty-two enemy deaths before summoning Bilitan.',
'The mission includes a Laboratory Guardian and scales enemies to the selected task bracket.',
'The exit door returns the group to Illsalin. A chest is scheduled when the task completes.'
],{type:'group',extra:['lua_modules/constants/instance_versions.lua'],notes:['This draft describes the intended objectives visible in unfinished source; it is not a verified playable mission. The journal uses the instance zone, while the opening rescue takes place in illsalin version 0.'],issues:['Publication blocked pending completion review: Neran uses TODO template 111111 and Bilitan uses TODO template 349999; neither placeholder is linked as a confirmed encounter NPC.','Resolve the task mismatch: headers identify tasks 8135–8139, but instance level and reward tables use 8130–8134.','Review the open-world callback entity usage and instance rare-item assignment before live validation; several references use NPC event self as a client or pass a table where an item ID is expected.']});
add('R010',200,[],'Theta Sigma offers a seasonal expedition into Kedge Keep called Seasons: Watery Death.',[
'The launcher defines a raid expedition for three to seventy-two players, with a three-day instance duration.',
'Theta Sigma requires a seasonal character. Creating the expedition also adds a two-day replay lockout.',
'Speak with Theta Sigma about repairing the timeline to request the expedition, then use the ready dialogue to enter.',
'This entry currently covers access only. Encounter opponents, combat phases and completion objectives are not established by the reviewed quest source.'
],{title:'Seasons: Watery Death',type:'raid',notes:['Version 200 is the launcher fallback; Custom:SeasonalInstanceVersion can override it. The live rule value is unverified.'],issues:['Publication blocked: identify the seasonal Kedge boss, database spawn group, abilities and completion rules. The launcher alone does not establish a Phinigel or other boss encounter.','Confirm the deployed SeasonalInstanceVersion rule and Kedge entry/return coordinates before publication.']});
add('R012',0,[85240],'A wizard’s preliminary epic challenge releases a ring of scorpion hatchlings.',[
'The ambush is triggered when a qualifying wizard kills the prone quest target at the appropriate preliminary epic stage.',
'Twenty-seven hatchlings appear together around the target area. Assemble support and prepare to control a large simultaneous spawn.',
'One randomly selected hatchling carries the quest talisman. Check the defeated hatchlings to recover it.'
],{items:[{id:16675,name:'Cursed Talisman',quantity:1}],issues:['Identify the prone trigger NPC bound to lakeofillomen/#_.lua and verify the player kill-credit requirement before publication; the file name does not reveal its template.']});
add('R014',0,[207031,207309],'Fight through the mouth gauntlet, defeat an Unimaginable Horror, then confront the returned Baraguj Szuul.',[
'The mouth event populates a descending gauntlet of enemies ending in an Unimaginable Horror.',
'Defeating the Horror casts Dimensional Return on players within two hundred units and replaces the waiting Baraguj with the combat version.',
'Regroup after the return before fighting Baraguj. His triggered form begins checking for an idle reset after fifteen minutes.'
],{extra:['potorment/An_Unimaginable_Horror.lua','potorment/#Baraguj_Szuul.lua'],issues:['Confirm the entry trigger and spawn wiring for legacy mouth_trigger.pl before publication. The completion chain from the Horror to Baraguj is present, but no reviewed source caller starts that Perl gauntlet.']});
add('R015',0,[207015],'Enter Tylis Newleaf’s torment, defeat the Keeper of Sorrows, and speak with the freed Tylis.',[
'An eligible character with the Shadyglade progression flag and the required key can tell Tylis that they will assist. Tylis moves that character’s group into the chamber.',
'Defeat the Keeper of Sorrows to summon the freed Tylis and record the expedition’s Keeper completion.',
'Hail the freed Tylis for progression credit, then gather within one hundred units before using the ready dialogue to return to the tower.',
'The freed Tylis remains for thirty minutes. Complete the dialogue and return together during that window.'
],{type:'raid',extra:['potorment/#Tylis_Newleaf.lua','potorment/zone_status.lua','potorment/Maareq_the_Prophet.lua'],notes:['Maareq’s death activates the entry Tylis. The Keeper’s death spawns a separate freed Tylis.'],issues:['Review the chamber’s database spawns and Keeper abilities before publication; the quest files confirm access and completion, but do not establish the full intervening combat sequence.']});

// More approved records are appended before this call.
emit();
