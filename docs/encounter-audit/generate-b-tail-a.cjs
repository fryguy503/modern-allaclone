const fs = require('fs');
const crypto = require('crypto');
const questRoot = 'F:/EQ1/Bastion_Dev/quests/';
const commonFiles = ['qvic/zone_status.lua', 'qvic/#Gozer_The_Gatekeeper.lua'];
const drafts = [
  {
    id: 'E267', slug: 'qvic-cynosure-kvanjji', title: 'Cynosure Kvanjji',
    npc_ids: [295140,295149,295145,295146,295147,295148],
    summary: 'Defeat Cynosure while managing four arbiters that heal him and copy his spells.',
    overview: [
      'Defeating the first Cynosure brings out his true form and four arbiters. Assign tanks to the arbiters before engaging the new boss; he calls them onto raid members shortly after combat begins.',
      'Each surviving arbiter can heal Cynosure for 10% of his maximum health and periodically copy his next spell. Killing all four causes the complete arbiter set to return. Plan which arbiters to defeat and which to control while the raid finishes Cynosure.'
    ],
    roles: [
      {id:'tank',label:'Tanks',tips:['Keep separate control of Cynosure and the arbiters. Be ready for four replacements if the entire arbiter set dies.']},
      {id:'healer',label:'Healers',tips:['Expect bursts when the surviving arbiters mimic the same spell. Keep their tanks covered while the raid damages Cynosure.']},
      {id:'damage',label:'Damage dealers',tips:['Coordinate arbiter kills; clearing all four restarts their full set. A healing announcement starts a ten-second delay, but killing its arbiter does not cancel an already queued heal in this script.']}
    ],
    phases: [
      {id:'first-form',label:'First form',trigger:'Initial encounter',description:'Defeat the initial Cynosure. His death creates the true boss, who immediately summons all four arbiters.'},
      {id:'arbiters',label:'Cynosure and the arbiters',trigger:'True form appears',description:'Control the arbiters while damaging Cynosure. Their independent healing schedules begin 20 to 60 seconds after they appear and then repeat every two and a half minutes.'},
      {id:'replacement',label:'Arbiter replacement',trigger:'All four arbiters die',description:'A repeating five-second check replaces the entire set and begins new healing schedules. Cynosure remains the kill objective.'}
    ],
    abilities: [
      {id:'arbiter-healing',name:'Arbiter healing',summary:'Each announced heal restores 10% of Cynosure’s maximum health after ten seconds.',description:['The heal is capped at full health. Once announced, the delayed heal does not check whether its arbiter is still alive.'],tags:[],roles:[],spell_ids:[]},
      {id:'mimicked-spells',name:'Mimicked spells',summary:'Surviving arbiters periodically copy Cynosure’s next spell.',description:['Cynosure selects a spell every ten seconds during combat. Every 30 seconds the script readies surviving arbiters to copy the next selection, each against a random target on its own hate list.'],tags:[],roles:[],spell_ids:[4121,4722,4723,4734,4749]},
      {id:'portal-energy',name:'Portal energy',summary:'A portal-energy announcement accompanies an additional spell every 90 seconds in combat.',description:[],tags:[],roles:[],spell_ids:[4748]}
    ],
    sections:[{id:'completion',title:'Completion',paragraphs:['Defeating the true Cynosure removes all four arbiter types and records a 72-hour encounter lockout in the Qvic expedition.'],bullets:[],items:[]}],
    files:['qvic/#Cynosure_Kvanjji.lua','qvic/##Cynosure_Kvanjji.lua','qvic/#Vishai_the_First_Arbitor.lua','qvic/#Svi-pral_the_Second_Arbitor.lua','qvic/#Ytvagi_the_Third_Arbitor.lua','qvic/#Qkav-d_the_Fourth_Arbitor.lua',...commonFiles],
    issues:[
      'The reset timer is started as reset but handled as Reset. The intended one-minute reset is not reliable as written; healing and mimic timers also remain active after combat ends. Verify recovery before publication.',
      'Queued arbiter heals are unconditional when delivered, even if the matching arbiter died during the ten-second delay. This draft intentionally does not advise killing an arbiter to interrupt a queued heal.',
      'Spell 4748 is cast by the 90-second portal-energy timer, but its loaded effect has not been verified. The draft does not infer an effect from the emote.'
    ]
  },
  {
    id:'E271',slug:'qvic-iqthinxa-karnkvi-zoo-event',title:'Iqthinxa Karnkvi: Zoo Event',
    npc_ids:[295130,295131,295132,295133],
    summary:'Balance the health of three Ravs during Iqthinxa’s protected intermission, then finish the boss.',
    overview:[
      'At 75% health, Iqthinxa becomes inactive and summons Rav Gernkki, Rav Marnkki and Rav Karnkki. All three initially attack the boss’s current hate leader, so have their tanks ready to take control.',
      'Keep the surviving Ravs less than ten percentage points apart in health. An imbalance can make them stronger and force them all onto the wounded Rav’s target. Defeating all three makes Iqthinxa active again.'
    ],
    roles:[
      {id:'tank',label:'Tanks',tips:['Assign one tank to each Rav before pushing the boss to 75%.','Keep every Rav inside the arena. If their health becomes unbalanced, prepare for them to converge on one tank; regain control as damage balance is restored.']},
      {id:'healer',label:'Healers',tips:['Watch for the frenzy announcement and immediately support the tank being attacked by multiple Ravs.']},
      {id:'damage',label:'Damage dealers',tips:['Compare all surviving Rav health bars and switch targets to keep their spread below ten percentage points.','Stop attacking Iqthinxa during the Rav stage. Resume when all three Ravs are dead.']}
    ],
    phases:[
      {id:'opening',label:'Iqthinxa',trigger:'100% to 75% health',description:'Fight the boss and prepare the Rav tanks before reaching 75%.'},
      {id:'ravs',label:'Balance the Ravs',trigger:'75% health',description:'Iqthinxa clears his hate list and becomes inactive. Defeat all three Ravs while keeping their health close together and controlling them inside the arena.'},
      {id:'finish',label:'Finish Iqthinxa',trigger:'All three Ravs defeated',description:'The boss becomes active again. Reestablish tank control and defeat him to complete the encounter.'}
    ],
    abilities:[
      {id:'pack-frenzy',name:'Pack frenzy',summary:'A health gap of ten percentage points can strengthen the Ravs and concentrate their attacks.',description:['The script checks surviving Rav health pairs. When it selects an injured Rav, all surviving Ravs clear their hate lists and heavily favor that Rav’s current target. Their maximum hits increase and their attack settings change to include area rampage.','Restoring the health balance signals the Ravs to return to their normal strength.'],tags:[],roles:[],spell_ids:[]},
      {id:'arena-leash',name:'Arena leash',summary:'Pulling a Rav too far away returns it and its current player target to the arena.',description:['Ravs more than 250 paces from the arena center are moved back. If the current hate leader is a player, that player is moved with the Rav.'],tags:[],roles:[],spell_ids:[]}
    ],
    sections:[{id:'completion',title:'Completion',paragraphs:['Iqthinxa’s death records a 72-hour Zoo Event lockout in the Qvic expedition.'],bullets:[],items:[]}],
    files:['qvic/Iqthinxa_Karnkvi.lua','qvic/Rav_Gernkki.lua','qvic/Rav_Marnkki.lua','qvic/Rav_Karnkki.lua',...commonFiles],
    issues:[
      'The three-Rav lowest-health selection misses the case where rav1 is below rav2 but rav3 is below rav1. An imbalance may therefore fail to trigger frenzy in that arrangement. Guidance keeps the health spread below ten points rather than relying on the defect.',
      'The Rav check begins at ten-second intervals and changes to one second after an imbalance. It does not restore the original interval when balance returns.',
      'The reviewed boss script does not define an out-of-combat cleanup or rearming of the 75% transition. Verify recovery after a wipe before publication.'
    ]
  }
];
const decisions = JSON.parse(fs.readFileSync('docs/encounter-audit/saved-decisions.json')).rows;
const report=[];
for(const e of drafts){
  if(decisions.find(r=>r.id===e.id)?.decision!=='A')throw new Error('Not approved: '+e.id);
  const d={schema_version:1,slug:e.slug,title:e.title,group:'Gates of Discord',type:'raid',zone:{short_name:'qvic',name:'Qvic, Prayer Grounds of Calling',version:0},npc_ids:e.npc_ids,status:'draft',spoiler:false,summary:e.summary,overview:e.overview,roles:e.roles,phases:e.phases,abilities:e.abilities,sections:e.sections,loot:[],sources:{reviewed_at:'2026-09-29',revision:'f3100d1a539093c717344860d4499558c48c7453',verification:'source-reviewed',files:e.files.map(path=>({path,sha256:crypto.createHash('sha256').update(fs.readFileSync(questRoot+path)).digest('hex')})),notes:['Approved row '+e.id+'. Source reviewed; live behavior, loaded database spell effects and loot have not been verified.','Qvic version 0 is explicit in the Gozer expedition launcher. Zone status supplies the encounter spawns and lockouts.',...e.issues]}};
  const path='resources/data/encounters/'+e.slug+'.json';
  if(fs.existsSync(path))throw new Error('Refusing to overwrite '+path);
  fs.writeFileSync(path,JSON.stringify(d,null,2)+'\n');
  report.push({id:e.id,slug:e.slug,issues:e.issues});
}
fs.writeFileSync('docs/encounter-audit/generated-b-tail-a.json',JSON.stringify(report,null,2)+'\n');
console.log(JSON.stringify({documents:report.length}));
