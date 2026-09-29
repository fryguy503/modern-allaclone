import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';
const root=path.resolve(import.meta.dirname,'../..');
const quests='F:/EQ1/Bastion_Dev/quests';
const revision=process.argv[2];
if(!/^[a-f0-9]{40}$/.test(revision??''))throw new Error('Pass the reviewed quest revision');
const audit=JSON.parse(await fs.readFile(path.join(import.meta.dirname,'encounter-candidates.json'),'utf8'));
const decisions=JSON.parse(await fs.readFile(path.join(import.meta.dirname,'saved-decisions.json'),'utf8'));
const zones={qey2hh1:'West Karana',qeynos2:'North Qeynos',qinimi:'Qinimi, Court of Nihilia',qvic:'Qvic, Prayer Grounds of Calling',rathemtn:'The Rathe Mountains',riftseekers:"Riftseekers' Sanctum",riwwi:'Riwwi, Coliseum of Games'};
const docs=[],report=[];
const phase=(id,label,trigger,description)=>({id,label,trigger,description});
const section=(id,title,paragraphs,items=[])=>({id,title,paragraphs,bullets:[],items});
const item=(id,name)=>({id,name,quantity:1});
const ability=(id,name,summary,description,spell_ids=[])=>({id,name,summary,description,tags:[],roles:[],spell_ids});
const role=(id,label,tips)=>({id,label,tips});
async function add(id,npc_ids,summary,overview,extra={}){
  if(!decisions.rows.some(r=>r.id===id&&r.decision==='A'))throw new Error('Unapproved '+id);
  const row=audit.rows.find(r=>r.id===id);
  const slug=extra.slug??row.zone+'-'+row.title.toLowerCase().replaceAll('`','').replaceAll("'",'').replace(/[^a-z0-9]+/g,'-').replace(/-$/,'');
  const paths=[...new Set([...row.sources.map(s=>s.path),...(extra.files??[])])];
  const issues=extra.issues??[];
  docs.push({schema_version:1,slug,title:extra.title??row.title,group:row.group,type:extra.type??(/raid/i.test(row.kind)?'raid':/group/i.test(row.kind)?'group':'event'),zone:{short_name:row.zone,name:zones[row.zone],version:0},npc_ids,status:'draft',spoiler:false,summary,overview,roles:extra.roles??[],phases:extra.phases??[],abilities:extra.abilities??[],sections:extra.sections??[],loot:[],sources:{reviewed_at:'2026-09-29',revision,verification:'source-reviewed',files:await Promise.all(paths.map(async p=>({path:p,sha256:createHash('sha256').update(await fs.readFile(path.join(quests,p))).digest('hex')}))),notes:[`Approved row ${id}. Source review only; live behavior and database spell/loot data have not been verified. Tactical recommendations are inferred from the scripted mechanics.`,...issues]}});
  report.push({id,slug,issues});
}

await add('E262',[12007,12009,12010],'Survive five werewolves and follow the trail to the shady bandit.',[
  'A necromancer at the appropriate pre-epic stage can give Yuanda the note to summon four regular werewolves and one stronger werewolf.',
  'The stronger wolf increases its maximum melee hit at 75%, 50% and 25% health. Defeating all five wolves summons the shady bandit for the next step.',
  'The wolves have a 30-minute despawn timer that is not paused in combat.'
],{files:['qey2hh1/#Yuanda.lua'],phases:[phase('wolves','Werewolf ambush','Note accepted','Control all five wolves and prepare for the stronger wolf to hit harder at each health threshold.'),phase('bandit','Follow the trail','All five wolves defeated','Find the newly spawned shady bandit to continue the quest.')],sections:[section('start','Starting the ambush',['The necromancer must have the matching recorded pre-epic stage.'],[item(15809,'Note to Yuanda')])]});

await add('E263',[2052,2179,2180,2181],'Use three enchanted rats to cure a randomized board before time expires.',[
  'Give Velarte the research briefing at the appropriate badge quest stage. A random four-by-four board appears and an 18-minute timer begins.',
  'Trade each filled rat jar to an empty tile. The released rat cures diseased rats in straight cardinal lines when they lie between it and the nearest healthy rat in that direction.',
  'Use all three jars, cure every diseased rat, and return the three empty jars together to Velarte before the experiment times out.'
],{files:['qeynos2/Velarte_Selire.lua','qeynos2/2177.lua','qeynos2/#Enchanted_Rat.lua'],sections:[section('supplies','Experiment supplies',['Bring the briefing and all three filled jars before starting. Empty jars are returned when the rats are placed.'],[item(18295,'Research Briefing'),item(2584,'Filled enchanted rat jar'),item(2585,'Filled enchanted rat jar'),item(2586,'Filled enchanted rat jar')])],issues:['Jar IDs and tile behavior are source-verified; the individual jar display names still require database confirmation.']});

await add('E264',[281123,281119,281124],'Clear timed waves, defeat the Pixtt, and stop Kreshin\'s execution.',[
  'Hold the entry stone on your cursor and say "I wish to enter" near the device. Nearby group members within 75 paces are transported with you.',
  'Three waves activate on a fixed schedule. Kill them promptly so they do not overlap, then defeat Pixtt Tixxrt Kvrok to make the executioner vulnerable.',
  'Kill the executioner within the final three-minute window. After the rescue, show Kreshin the entry stone before the group is ejected.'
],{type:'group',files:['qinimi/script_init.lua'],phases:[phase('waves','Opening waves','Ten seconds after staging','The first wave has two spiritbrutes and one bloodhunter. Two minutes later the second matching wave activates; two minutes after that, one spiritbrute and two bloodhunters activate.'),phase('pixtt','Pixtt and guards','Two minutes after the third wave','The Pixtt becomes active with two guards. The executioner begins moving and the three-minute failure timer starts.'),phase('executioner','Stop the execution','Pixtt defeated','The executioner loses protection. Kill him to stop the failure timer.'),phase('rescue','Speak to Kreshin','Executioner defeated','The party has seven minutes and thirty seconds before transport out, with a warning for the final twenty-five seconds.')],sections:[section('entry','Entry key',['The device checks the cursor slot rather than a trade. The same key is shown to Kreshin after success and returned.'],[item(67415,'Stone of Entry')])]});

await add('E265',[281089,281090,281091],'Choose which Spiritlord to defeat first, then handle Gorlakt\'s inherited spell.',[
  'An eligible druid approaching the trigger at the required epic stage summons Gorlakt and the two Spiritlords. This sets a two-hour event cooldown and a 30-minute cleanup timer.',
  'Gorlakt starts protected. The first Spiritlord death removes his protection and determines the spell he inherits: Mind Warp from Mind or Body Warp from Body.',
  'The first inherited spell choice persists. Killing the other Spiritlord does not grant him a second spell timer.'
],{files:['qinimi/#Spiritlord_Mind.lua','qinimi/#Spiritlord_Body.lua','qinimi/#druid_trap.lua'],abilities:[ability('mind','Mind Warp','Mind and a Mind-empowered Gorlakt cast on their current target.',['The first cast follows a random five-to-fifteen-second delay, then repeats every thirty seconds.'],[5808]),ability('body','Body Warp','Body and a Body-empowered Gorlakt cast on their current target.',['The first cast follows a random five-to-twenty-second delay, then repeats every forty-five seconds.'],[5809])],issues:['Gorlakt stops both spell timers when combat ends but keeps spell_active set. The reviewed script does not restart those timers on re-engagement; verify recovery before publication.']});

await add('E266',[281125,281149,281150,281151],'Clear the chamber waves and identify the real boss before the Thunderdome deadline.',[
  'At the required Qinimi quest stage, tell Councilman Sislono Nislan you are ready while in a raid with at least six members in the zone. He assigns an available chamber.',
  'In the reviewed first chamber, clear twenty-one opening enemies and three ratuks within ten minutes. The complete event has a separate twenty-seven-minute timer.',
  'After Kabeka falls, test the boss images by taking them to 90% health. Finding the real image advances the fight immediately; a false image removes the set and produces fewer choices.'
],{title:'Thunderdome: First Chamber',phases:[phase('waves','Chamber waves','Event begins','Six guardians spawn after five seconds, six noc and ukun at one minute, and nine more at three minutes. Clear all twenty-one.'),phase('ratuks','Three ratuks','Opening enemies defeated','Defeat the three ratuks. Completing this stage stops the first ten-minute deadline.'),phase('kabeka','Kabeka','Three ratuks defeated','Manage arrow volleys and the self-buff at 5% health.'),phase('images','Find the real image','Kabeka defeated','Push images to 90% until the real boss appears. Wrong choices reduce the next set; exhausting the choices also produces the final boss.'),phase('final','Final boss','Real image identified','Defeat the final incarnation before the overall deadline.')],issues:['Only thunder_dome_one combat logic was reviewed. The launcher also offers chambers two and three; those branches need their own source comparison before this guide claims to cover every room.','The launcher dialogue says eighteen participants and the module declares player_limit=18, but this module does not enforce that variable. Do not present eighteen as a verified admission cap.','The final boss calls stop_timer("fail_2") on itself although the overall timer belongs to the controller, then cleanup depops the final boss. Verify the victory, loot and delayed-ejection path before publication.']});

for(const [id,filename,reducedArchery] of [['E268','Hexxt_Ilk_Klokk.lua',false],['E269','Hexxt_Jkak_Miq.lua',true],['E270','#Hexxt_Pvin_Nki.lua',true]]){
  await add(id,[],'Prepare for random-target arrow volleys below 45% health.',[
    'Crossing 45% health starts a twenty-second volley check. A successful check casts random arrow spells at random members of the hate list.',
    'The scripted branch handles hate-list sizes from one to six and makes that many target selections. Targets are selected independently, so a player may be hit more than once.',
    'Healing above 45% stops the volley timer and allows it to be armed again on the next downward crossing.'
  ],{abilities:[ability('volley','Arrow volley','Random-target pressure below 45% health.',['Keep wounded players covered during the twenty-second checks.'],[4850,4849,4851])],sections:reducedArchery?[section('archery','Weapon choice',['This NPC reduces incoming archery skill damage by 25%.'])]:[],issues:['The NPC template ID was not established from the reviewed file; the guide has no automatic NPC-page link until that identity is confirmed.','The volley implementation has no branch for hate-list counts greater than six. Do not claim the same targeting behavior for a larger raid.']});
}

await add('E272',[50329,50331,50330],'Protect Chalex through Krignok\'s assault and return the quest sword after the fight.',[
  'Give the restored quest sword to the pile of froglok remains to summon Warrior Spirit Chalex. He moves into position and calls Krignok twenty seconds after appearing.',
  'Krignok attacks Chalex and summons nine undead troll marauders in three closely spaced groups. Protect the spirit while controlling the adds.',
  'When Chalex leaves combat, his redeemed form appears. Return the quest sword to that form within its ten-minute window.'
],{files:['rathemtn/#a_pile_of_froglok_remains.lua','rathemtn/Troll_Captain_Krignok.lua','rathemtn/Chalex_the_Redeemed.lua'],sections:[section('sword','Quest sword',['The remains accept the restored sword and return the version used for the final hand-in.'],[item(67012,'Restored quest sword'),item(67020,'Returned quest sword')])],issues:['Chalex transforms on leaving combat rather than on a confirmed Krignok death. Verify this recovery/completion edge before publication.','Krignok begins a five-minute despawn timer after the third add wave; that timer is not paused in combat.']});

await add('E273',[334014,334050],'Fight Chailak with the Flawless Experimental Battlelords.',[
  'Every three seconds during combat, Chailak calls idle Flawless Experimental Battlelords onto a random member of his hate list. Prepare for the linked enemies together.',
  'After five minutes out of combat, Chailak heals fully, removes his buffs, returns home and clears hate. The Battlelords receive the same reset signal.'
],{files:['riftseekers/script_init.lua'],roles:[role('tank','Tanks',['Assign coverage for Battlelords before engaging Chailak.'])]});

await add('E274',[334087,334089,334090,334091,334097,334098,334099,334100,334101],'Control Tieranu\'s summoned elementals and deny the final healing sacrifices.',[
  'A ranger at the required epic stage triggers Tieranu by entering the zone while the two-hour event cooldown is clear.',
  'Tieranu summons Flamegore at 70%, Sizzle at 45% and Hotspot at 20%. Hotspot splits into five small elementals when killed.',
  'At each 10% health crossing, Tieranu can sacrifice one remaining small elemental and recover 10% of maximum health. Clear those elementals before the final push.'
],{files:['riftseekers/player.lua','riftseekers/script_init.lua'],phases:[phase('flamegore','Flamegore','70% health','Pick up the first elemental.'),phase('sizzle','Sizzle','45% health','Control the second elemental alongside any survivors.'),phase('hotspot','Hotspot and fragments','20% health','Prepare for five small elementals when Hotspot dies.'),phase('sacrifice','Deny the healing loop','10% health','Remove the small elementals so Tieranu cannot consume one to recover health.')],abilities:[ability('elementals','Elemental spells','Elementals strip buffs and cast Heatwave at their current target.',['The buff-stripping action repeats every ten seconds after its initial random delay. Heatwave repeats every twenty seconds after its initial delay.'],[3230,5816])],sections:[section('recovery','Recovery',['Tieranu initially remains for thirty minutes. Combat pauses that timer; leaving combat starts a five-minute cleanup timer.'])],issues:['The cleanup function omits Sizzle (334090), although it removes the other elemental IDs. Verify leftover-add behavior before publication.']});

await add('E275',[334041,334040,334039,334038,334037,334036,334035],'Defeat both prince formations while controlling portals, then face King Gelaqua.',[
  'Six princes guard Gelaqua in two linked trios. Each engaged prince activates its portal after five seconds, then every forty to sixty seconds, producing a feran or icy orb.',
  'Defeating one trio prompts the surviving formation to become aggressive. Kill all six princes to remove the king\'s protection.',
  'Keep the princes and king inside their chamber. Their boundary check runs every six seconds and heals, returns and clears hate from any who cross it.'
],{files:['riftseekers/script_init.lua'],phases:[phase('princes','Six princes','Opening pull','Cover a linked trio and its feran adds while watching for icy orbs. Prepare for the other trio as the first falls.'),phase('king','King Gelaqua','All princes defeated','The king becomes vulnerable and seeks a target. His active portals always produce icy orbs, beginning after five seconds and recurring every forty to sixty seconds.')],abilities:[ability('orbs','Icy orb pursuit','Orbs move toward the king, then pursue a player.',['Keep pursuing orbs away from the main group. Within three paces of their target they split into four crystals. Crystals immediately cast Gelaqua\'s Embrace, repeat every four seconds and remain for up to seven minutes.'],[5760])],issues:['One prince transition signals 334045, a princess ID, instead of the apparent remaining prince 334035. Verify both trio-clear orders before publication.','Orb target selection uses a large placeholder range marked TODO in the source. Current crowd-control susceptibility is database-dependent and is not promised here.']});

await add('E276',[334049,334048,334047,334046,334045,334044,334043],'Defeat the princess formations and keep fire constructs away from the raid.',[
  'Six princesses guard Pyrilonis in two linked trios. Their portals activate after five seconds in combat, then every forty to sixty seconds, producing a chimera or fire construct.',
  'Finishing one trio activates the other formation. All six princesses must die before the queen loses her damage protection.',
  'Keep these enemies in their chamber. Crossing the boundary triggers a full heal, return home and hate clear at the next six-second check.'
],{files:['riftseekers/script_init.lua'],phases:[phase('princesses','Six princesses','Opening pull','Control the linked trio, chimera reinforcements and constructs. Be ready for the second formation when the first falls.'),phase('queen','Queen Pyrilonis','All princesses defeated','The queen becomes vulnerable. Her portals always produce fire constructs, beginning after five seconds in combat and recurring every forty to sixty seconds.')],abilities:[ability('constructs','Fire construct pursuit','Constructs move to the queen, then pursue a player.',['A construct within three paces of its target casts Pyrilonis\' Vengeance and disappears. Keep the pursuit away from the group. It also detonates if it loses a valid target.'],[5745])],issues:['Construct target selection uses a large placeholder range marked TODO in the source. Live range and database spell effects remain unverified.']});

await add('E277',[282097,282098,282099,282100,282101,282102,282103,282104,282075,282071,282083,282086,282078,282085,282082,282074,282076,282087,282084,282070,282072,282077,282080],'Win ten arena battles by returning proof between challenges.',[
  'At the required Riwwi task stage, tell the enslaved yunjo you will "defy" the Muramites to begin the first battle and receive its proof container.',
  'After each battle, combine the required proof and return it to Turlini. Give the resulting challenge token to the enslaved yunjo to summon the next opponents and receive the next container.',
  'Progress depends on those turn-ins rather than an automatic timer between waves. Turlini requires sufficient faction before accepting proof.'
],{files:['riwwi/#an_enslaved_yunjo.lua','riwwi/#Turlini.lua'],phases:[phase('early','Battles one through four','Initial challenge and successive token turn-ins','Face Frothing Hynid, Gibbering Hynid, Handler Bvekh with Highest Hills Cragbeast, then Pfaaxle.'),phase('stone','Battles five through seven','Continue returning proof','Face one stoneworker, then two stone servants, then Master Hviqu with Directed Destroyer and Dominated Bonecrusher.'),phase('final','Battles eight through ten','Continue returning proof','Face Drax, Qiz and Xnoz together; then Kizki the Slayer; finally six Hexxt and Pixtt opponents.'),phase('proof','Final proof','Tenth battle completed','Return the last proof container to Turlini to complete this part of the escape and receive the follow-up report.')],issues:['The sequence and trade IDs are source-verified. Container recipe contents, enemy spell lists and equipment rewards require database review. No boss-specific abilities are invented for the wave roster.']});

for(const doc of docs){
  const target=path.join(root,'resources/data/encounters',doc.slug+'.json');
  await fs.writeFile(target,JSON.stringify(doc,null,2)+'\n',{flag:process.argv.includes('--refresh')?'w':'wx'});
}
await fs.writeFile(path.join(import.meta.dirname,'generated-b-tail.json'),JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify({approved_rows:report.length,documents:docs.length}));
