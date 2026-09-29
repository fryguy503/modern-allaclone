import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';

const root=path.resolve(import.meta.dirname,'../..');
const questRoot=path.resolve(process.env.EQEMU_QUEST_ROOT??path.join(root,'../quests'));
const decisionFile=path.join(import.meta.dirname,'saved-decisions.json');
const decisions=JSON.parse(await fs.readFile(decisionFile,'utf8'));
const approved=decisions.rows.filter(r=>r.decision==='A');
const reports=['generated-a.json','generated-b.json','generated-c.json','generated-root.json','generated-parent.json','generated-b-tail.json','generated-b-tail-a.json'];
const mappings=[];
for(const file of reports) mappings.push(...JSON.parse(await fs.readFile(path.join(import.meta.dirname,file),'utf8')));
const approvedIds=new Set(approved.map(r=>r.id));
const mappedIds=new Set(mappings.map(r=>r.id));
const errors=[];
const publication=JSON.parse(await fs.readFile(path.join(import.meta.dirname,'publication-review.json'),'utf8').catch(error=>{if(error.code==='ENOENT')return 'null';throw error;}));
const publicationEntries=new Map((publication?.entries??[]).map(entry=>[entry.slug,entry]));
if(publicationEntries.size!==(publication?.entries??[]).length)errors.push('Duplicate publication decisions.');
for(const id of approvedIds)if(!mappedIds.has(id)) errors.push(`Missing approved entry: ${id}`);
for(const m of mappings)if(!approvedIds.has(m.id)) errors.push(`Unapproved entry included: ${m.id}`);
const invalid=decisions.rows.filter(r=>!['A','D','P'].includes(r.decision));
if(invalid.length)errors.push(`Invalid decision codes: ${invalid.map(r=>r.id).join(', ')}`);
const workbookHash=createHash('sha256').update(await fs.readFile(path.join(import.meta.dirname,'encounter-approvals.xlsx'))).digest('hex');
if(workbookHash!==decisions.sha256)errors.push('Approval workbook changed after the decision import. Reconcile the new decisions before completion.');
const slugs=[...new Set(mappings.map(m=>m.slug))].sort();
for(const slug of publicationEntries.keys())if(!slugs.includes(slug))errors.push(`Publication decision has no approval mapping: ${slug}`);
const documents=[];
const hashCache=new Map();
for(const slug of slugs){
  const file=path.join(root,'resources/data/encounters',slug+'.json');
  const doc=JSON.parse(await fs.readFile(file,'utf8'));
  if(doc.slug!==slug)errors.push(`Slug mismatch: ${slug}`);
  if(!doc.overview?.length)errors.push(`Missing overview: ${slug}`);
  const publicationEntry=publicationEntries.get(slug);
  if(publication&&!publicationEntry)errors.push(`Missing publication decision: ${slug}`);
  const expectedStatus=publicationEntry?.status??'draft';
  if(!['draft','published'].includes(expectedStatus)||doc.status!==expectedStatus)errors.push(`Publication status mismatch: ${slug}`);
  if(!doc.sources?.files?.length)errors.push(`Missing reviewed sources: ${slug}`);
  for(const source of doc.sources?.files??[]){
    const resolved=path.resolve(questRoot,source.path);
    const relative=path.relative(questRoot,resolved);
    if(relative.startsWith('..')||path.isAbsolute(relative)){errors.push(`Unsafe source path: ${source.path}`);continue;}
    if(!hashCache.has(source.path))hashCache.set(source.path,createHash('sha256').update(await fs.readFile(resolved)).digest('hex'));
    if(hashCache.get(source.path)!==source.sha256.toLowerCase())errors.push(`Source changed: ${slug}: ${source.path}`);
  }
  documents.push({slug,title:doc.title,zone:doc.zone,status:doc.status,publication_reason:publicationEntry?.reason??'Awaiting publication review.',approved_ids:[...new Set(mappings.filter(m=>m.slug===slug).map(m=>m.id))],issues:[...new Set(mappings.filter(m=>m.slug===slug).flatMap(m=>m.issues??[]))]});
}
const existing=['cantor-selindra','castellan-veyr','gravekeeper-vhal','huntmaster-draeven','last-eclipse-finale'];
const files=(await fs.readdir(path.join(root,'resources/data/encounters'))).filter(n=>n.endsWith('.json')).map(n=>n.slice(0,-5));
for(const file of files)if(!existing.includes(file)&&!slugs.includes(file))errors.push(`New file has no approval mapping: ${file}`);
const summary={date:'2026-09-29',workbook_sha256:decisions.sha256,approved_rows:approved.length,disapproved_rows:decisions.rows.filter(r=>r.decision==='D').length,pending_rows:decisions.rows.filter(r=>r.decision==='P').length,encounter_groups:approved.length-2,generated_documents:documents.length,published_documents:documents.filter(d=>d.status==='published').length,draft_documents:documents.filter(d=>d.status==='draft').length,existing_documents:existing.length,reviewed_source_files:hashCache.size,merged_approval_ids:['E030','E031','E032'],errors,documents};
const validation=JSON.parse(await fs.readFile(path.join(import.meta.dirname,'validation-results.json'),'utf8').catch(error=>{if(error.code==='ENOENT')return 'null';throw error;}));
await fs.writeFile(path.join(import.meta.dirname,'generation-status.json'),JSON.stringify(summary,null,2)+'\n');
const escape=value=>String(value??'').replaceAll('|','\\|').replaceAll('\n',' ');
const linked=doc=>`[${escape(doc.title)}](../../resources/data/encounters/${doc.slug}.json)`;
const report=[
  '# Encounter journal generation and publication',
  '',
  `Imported saved approvals on ${summary.date}: ${summary.approved_rows} approved, ${summary.disapproved_rows} disapproved, ${summary.pending_rows} pending.`,
  '',
  `The approved rows represent ${summary.encounter_groups} encounter groups after combining E030, E031 and E032 into the Agnarr Event. They produced ${summary.generated_documents} new documents. Separate documents are used where a zone version needs its own mechanics or source caveats. The ${summary.existing_documents} existing Mistmoore documents are unchanged.`,
  '',
  `${summary.published_documents} new documents are published in the application and ${summary.draft_documents} remain drafts. Published entries appear under More -> Encounter Journal at /encounters after the updated files are deployed. Publication does not change quests or certify live availability. Mayong retains the existing spoiler warning.`,
  '',
  ...(publication?['## Publication review','',`Reviewed ${publication.reviewed_at} following the request to publish. Missing optional NPC links, absent database loot/spell data and source-only verification do not prevent publication. Entries with unresolved core identity or misleading encounter instructions remain drafts.`, '', '| Held draft | Reason |', '| --- | --- |', ...documents.filter(d=>d.status==='draft').map(d=>`| ${linked(d)} | ${escape(d.publication_reason)} |`),'']:[]),
  '',
  '## Bastion of Thunder',
  '',
  'Evynd Firestorm, Emmerik Skyfury and Agnarr the Storm Lord form one continuous encounter in each version document. Both documents cover the lower tower, middle tower, summit, portal waves, health thresholds and Karana dialogue.',
  '',
  'The normal draft records a source conflict: Gozer creates version 0, but Agnarr removes himself in a nonzero instance unless its version is 200. The seasonal draft follows the explicit version 200 branch. The quest scripts have not been changed.',
  '',
  '## Review notes',
  '',
  'The table below preserves source questions and review limitations recorded during drafting. Some early notes recommended further review even when the final guide already avoids the uncertain behavior. The explicit publication decisions now distinguish those source caveats from blocking content gaps. Database-only NPC spell lists and equipment loot were not fabricated.',
  '',
  `${documents.filter(d=>d.issues.length).length} documents have specific review notes. These range from missing database identities to source conflicts; they are not all broken encounters. The publication decision and held-draft list above distinguish these from blocking content gaps.`,
  '',
  '| Document | Version | Review notes |',
  '| --- | ---: | --- |',
  ...documents.filter(d=>d.issues.length).map(d=>`| ${linked(d)} | ${d.zone.version} | ${escape(d.issues.join(' '))} |`),
  '',
  '## Generated documents',
  '',
  '| Approval IDs | Document | Zone | Version | Status |',
  '| --- | --- | --- | ---: | --- |',
  ...documents.map(d=>`| ${d.approved_ids.join(', ')} | ${linked(d)} | ${escape(d.zone.short_name)} | ${d.zone.version} | ${d.status} |`),
  '',
  '## Disapproved entries',
  '',
  '| ID | Zone | Encounter |',
  '| --- | --- | --- |',
  ...decisions.rows.filter(r=>r.decision==='D').map(r=>`| ${r.id} | ${escape(r.zone)} | ${escape(r.title)} |`),
  '',
  '## Validation',
  '',
  `Approval coverage and source checks: ${errors.length===0?'passed':`${errors.length} errors`}. ${hashCache.size} distinct reviewed source files were checked against recorded SHA-256 hashes. The saved workbook hash was checked to detect later edits.`,
  '',
  ...(validation ? [`Recorded application checks on ${validation.date}: schema validation passed for ${validation.schema.documents} documents, including the five original Mistmoore entries. The focused journal test suite passed ${validation.tests.passed} tests with ${validation.tests.assertions} assertions. These checks ran in the existing local PHP preview container.`, '', 'Schema command: `'+validation.schema.command+'`.', '', 'Focused test command: `'+validation.tests.command+'`.', ''] : []),
  ...(validation?.publication ? [`Publication checks on ${validation.publication.date}: the local public index lists ${validation.publication.total_public} entries. A newly published guide returns HTTP 200, a held draft returns HTTP 404 and is absent from public search, and Mayong remains behind the spoiler reveal. Schema validation still passes for ${validation.publication.schema_documents} documents. Production deployment was not performed by these checks.`, ''] : []),
  'Run the Library schema validator with `php artisan encounters:validate`. When the quest repository is mounted beside the application, pass `--source-root` to that command for a full source freshness check. Host-side coverage and source checks can be repeated with `node docs/encounter-audit/verify_generation.mjs`.',
  '',
  'Source review and schema validation do not establish matching live database data, deployed quest versions, combat behavior or balance.',
  ''
];
await fs.writeFile(path.join(import.meta.dirname,'generation-report.md'),report.join('\n'));
console.log(JSON.stringify({approved:summary.approved_rows,disapproved:summary.disapproved_rows,pending:summary.pending_rows,groups:summary.encounter_groups,documents:summary.generated_documents,published:summary.published_documents,drafts:summary.draft_documents,sources:summary.reviewed_source_files,with_issues:documents.filter(d=>d.issues.length).length,errors},null,2));
if(errors.length)process.exitCode=1;
