import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';

const questRoot='F:/EQ1/Bastion_Dev/quests';
const root=path.resolve(import.meta.dirname,'../..');
const revision=process.argv[2];
if(!/^[a-f0-9]{40}$/.test(revision??'')) throw new Error('Pass the verified current quest Git revision.');
const files=['bothunder/Agnarr_the_Storm_Lord.lua','bothunder/Emmerik_Skyfury.lua','bothunder/Evynd_Firestorm.lua','bothunder/zone_status.lua','bothunder/player.lua','bothunder/#Gozer_The_Gatekeeper.lua','bothunder/#Askr_the_Lost_.lua','bothunder/#_Askr_the_Lost_.lua','bothunder/#Askr_the_Lost.lua','bothunder/#Karana.lua','bothunder/A_celestial_portal.lua','bothunder/A_firestorm_portal.lua','bothunder/A_storm_portal.lua','bothunder/An_animated_meteor.lua','bothunder/A_firestorm_elemental.lua','bothunder/A_Chaos_Vortex.lua'];
async function sources(paths){return await Promise.all(paths.map(async p=>({path:p,sha256:createHash('sha256').update(await fs.readFile(path.join(questRoot,p))).digest('hex')})));}
const base={
  schema_version:1,slug:'bothunder-agnarr-event',title:'Agnarr Event',group:'Bastion of Thunder',type:'raid',
  zone:{short_name:'bothunder',name:'Bastion of Thunder',version:0},npc_ids:[209054,209053,209026],status:'draft',spoiler:false,
  summary:'Ascend Torden through Evynd Firestorm and Emmerik Skyfury, then defeat Agnarr and free Karana.',
  overview:[
    'The Agnarr Event is one tower assault with three bosses: Evynd Firestorm on the lower level, Emmerik Skyfury above him, and Agnarr the Storm Lord at the summit. Askr opens the route between stages.',
    'Each boss calls reinforcements from portals. Agnarr also summons four elemental bosses as his health falls, so the raid must handle timed waves and health-triggered reinforcements together.'
  ],
  roles:[
    {id:'tank',label:'Tanks',tips:['Assign tanks to portal reinforcements before each pull. The adds move toward the current boss.','Prepare an extra tank as Agnarr approaches 99%, 76%, 51% and 26% health.']},
    {id:'healer',label:'Healers',tips:['Expect the first portal wave 30 seconds into combat, then another every two minutes while the boss remains engaged.','Watch tanks handling new elemental bosses at Agnarr\'s health thresholds.']},
    {id:'damage',label:'Damage dealers',tips:['Help clear portal reinforcements before they overlap with the next wave.','Coordinate damage near Agnarr\'s health thresholds so the raid is ready for each additional boss.']}
  ],
  phases:[
    {id:'evynd',label:'Evynd Firestorm',trigger:'Lower tower',description:'Defeat Evynd while controlling the firestorm elementals called by his portals. After victory, ask the newly appeared Askr to transport you to the next level.'},
    {id:'emmerik',label:'Emmerik Skyfury',trigger:'Middle tower',description:'Defeat Emmerik while handling animated meteors from his celestial portals. Speak to Askr about the Storm, then hail the Chaos Vortex he creates to reach the summit.'},
    {id:'agnarr-opening',label:'Agnarr and the first elemental boss',trigger:'Agnarr reaches 99% health',description:'The first elemental boss appears at Agnarr\'s position. Timed storm-portal reinforcements continue independently of his health.'},
    {id:'agnarr-second',label:'Second elemental boss',trigger:'Agnarr reaches 76% health',description:'Another elemental boss joins the fight at Agnarr\'s position.'},
    {id:'agnarr-third',label:'Third elemental boss',trigger:'Agnarr reaches 51% health',description:'A third elemental boss joins. Keep the new arrival controlled while portal waves continue.'},
    {id:'agnarr-fourth',label:'Final elemental boss',trigger:'Agnarr reaches 26% health',description:'The final elemental boss appears near Agnarr with a small random offset. Finish the reinforcements and defeat the Storm Lord.'},
    {id:'karana',label:'Speak with Karana',trigger:'Agnarr is defeated',description:'Karana and Askr appear. Hail Karana and follow the dialogue about the path of the Fallen to receive the character flags, then ask to be sent on your path when ready to leave.'}
  ],
  abilities:[
    {id:'firestorm-portals',name:'Firestorm portals',summary:'Evynd summons firestorm elementals through the portals.',description:['The first call occurs 30 seconds after engagement, then repeats every two minutes. Each signaled firestorm portal creates an elemental and moves it toward Evynd.'],tags:['Adds','Timed waves'],roles:['tank','damage'],spell_ids:[]},
    {id:'celestial-portals',name:'Celestial portals',summary:'Emmerik summons animated meteors through the portals.',description:['The first call occurs 30 seconds after engagement, then repeats every two minutes. Each signaled celestial portal creates an animated meteor and moves it toward Emmerik.'],tags:['Adds','Timed waves'],roles:['tank','damage'],spell_ids:[]},
    {id:'storm-portals',name:'Storm portals',summary:'Agnarr calls random storm reinforcements toward the raid.',description:['Thirty seconds after engagement, Agnarr strikes his staff and signals the storm portals. The call then repeats every two minutes.','Each active outer portal selects one of four reinforcement types. The central portal does not create adds.'],tags:['Adds','Timed waves'],roles:['tank','damage'],spell_ids:[]},
    {id:'elemental-bosses',name:'Elemental bosses',summary:'Four additional bosses arrive at 99%, 76%, 51% and 26% health.',description:['These arrivals are triggered by Agnarr\'s health, independently of his timed portal waves. Prepare for overlapping reinforcements if several thresholds are crossed quickly.'],tags:['Health thresholds','Adds'],roles:['tank','healer','damage'],spell_ids:[]}
  ],
  sections:[
    {id:'entry',title:'Entering the tower',paragraphs:['Gozer offers the Stormlord expedition, A Storm Approaches. Once the expedition exists, tell him you are ready to enter.','The central tower door requires the tower symbol. Gather nearby before the holder clicks: nearby raid or group members within 100 paces can be moved into the tower together.'],bullets:[],items:[{id:9433,name:'Symbol of Torden',quantity:1}]},
    {id:'ascent',title:'Moving between stages',paragraphs:['After Evynd, Askr remains for one hour and transports each player who asks. After Emmerik, Askr remains for 45 minutes; discussing the Storm opens a Chaos Vortex that also remains for 45 minutes. Hail the vortex to reach Agnarr.'],bullets:['Evynd: hail Askr, then say transport.','Emmerik: hail Askr, discuss the Storm, then hail the Chaos Vortex.','Agnarr: speak with Karana before leaving; he remains for 20 minutes.'],items:[]},
    {id:'recovery',title:'After a failed attempt',paragraphs:['When Agnarr leaves combat, his portal timer stops, the four health thresholds reset, and his summoned storm reinforcements and elemental bosses are removed. A later reset timer repeats that cleanup after 20 minutes.','Evynd and Emmerik stop their repeating portal timer when it checks and finds them out of combat. Their summoned elementals and meteors have a five-minute cleanup check that only removes them while they are not engaged.'],bullets:[],items:[]},
    {id:'lockouts',title:'Expedition lockouts',paragraphs:['The normal expedition records a six-hour lockout for Evynd, six hours for Emmerik, and three days for Agnarr. The requester also receives a 20-minute replay lockout when creating the expedition.'],bullets:[],items:[]}
  ],loot:[],
  sources:{reviewed_at:'2026-09-29',revision,verification:'source-reviewed',files:await sources(files),notes:[
    'Approved rows E030, E031 and E032 are combined into one complete Agnarr Event as requested. Separate version documents preserve that same three-boss grouping.',
    'Publication blocker: Gozer creates version 0 and zone_status spawns Agnarr there, but Agnarr event_spawn depops him in every nonzero instance unless its version is 200. The static zone is allowed. Verify the intended normal expedition behavior before publishing this version.',
    'NPC IDs and the tower sequence are explicit in the boss scripts, zone_status and Askr scripts. Normal NPC spell lists, equipment loot tables and live spawn population were not queried; no damage, loot or unverified spell claims are included.',
    'Role advice is derived from the scripted timing and reinforcement sequence. No live encounter verification has been performed.'
  ]}
};
const seasonal=structuredClone(base);
seasonal.slug='bothunder-agnarr-event-seasonal';
seasonal.title='Agnarr Event (Seasonal)';
seasonal.group='Seasons: Lord of Shackled Storms';
seasonal.zone.version=200;
seasonal.sections[0]={id:'entry',title:'Entering the seasonal tower',paragraphs:['Theta Sigma offers Lord of Shackled Storms in Bastion of Thunder. Evynd, Emmerik and Agnarr form the same tower assault.','Gather near the central tower door before the holder uses the tower symbol. Nearby raid or group members within 100 paces can be transported together.'],bullets:[],items:[{id:9433,name:'Symbol of Torden',quantity:1}]};
seasonal.sections[3]={id:'seasonal-completion',title:'Seasonal completion',paragraphs:['The seasonal zone controller skips the normal per-boss lockouts. Agnarr\'s defeat also creates a fragment of fractured time at his location.','Hail the fragment and say bound to receive seasonal progression credit if you are an eligible seasonal character who has not received it. The fragment can bind up to 72 characters. It warns after ten minutes and disappears ten minutes later.'],bullets:[],items:[]};
seasonal.sources.files=await sources([...files,'global/Theta_Sigma.lua','global/fragment_of_fractured_time.lua']);
seasonal.sources.notes=[
  'Approved rows E030, E031 and E032 are combined into one complete Agnarr Event as requested. This is its seasonal version.',
  'Version 200 is enforced directly in Agnarr event_spawn. Theta Sigma uses Custom:SeasonalInstanceVersion with fallback 200; verify the deployed rule also resolves to 200.',
  'zone_status identifies the seasonal expedition by the exact name Seasons: Lord of Shackled Storms and spawns all three bosses together. The shared Askr progression scripts link their tower stages.',
  'The normal version 0 expedition has a conflicting Agnarr spawn gate; that issue is recorded in the separate normal draft. No quest scripts were changed.',
  ...base.sources.notes.slice(2)
];
for(const entry of [base,seasonal]){
  const dest=path.join(root,'resources/data/encounters',entry.slug+'.json');
  await fs.writeFile(dest,JSON.stringify(entry,null,2)+'\n',{flag:process.argv[3]==='--refresh'?'w':'wx'});
}
await fs.writeFile(path.join(import.meta.dirname,'generated-root.json'),JSON.stringify([base,seasonal].flatMap(entry=>['E030','E031','E032'].map(id=>({id,slug:entry.slug,issues:entry.sources.notes.filter(n=>n.startsWith('Publication blocker:')||n.startsWith('Version 200 is'))}))),null,2)+'\n');
console.log('Created two version-specific drafts, each covering the full Evynd, Emmerik and Agnarr event.');
