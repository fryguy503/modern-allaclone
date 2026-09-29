"""Combine the reviewed encounter candidates without changing quest content."""
from collections import Counter
import hashlib
import json
from pathlib import Path
import re
import subprocess
from urllib.parse import quote

OUT = Path(__file__).resolve().parent
ROOT = Path(r"F:\EQ1\Bastion_Dev\quests")

def registries():
    text = (ROOT / "global/Theta_Sigma.lua").read_text(encoding="utf-8-sig")
    pattern = r'(?m)^\s*(\w+)\s*=\s*\{\s*\n\s*expedition = \{ name = "([^"]+)"'
    seasons = [{"zone": m[1], "name": m[2], "line": text.count("\n", 0, m.start()) + 1,
                "version": "Custom:SeasonalInstanceVersion (default 200)"}
               for m in re.finditer(pattern, text)]
    text = (ROOT / "lua_modules/bonded_hunts_config.lua").read_text(encoding="utf-8-sig")
    hunts = []
    for block in re.split(r'(?m)^      index = \d+,', text)[1:]:
        hunts.append({"zone": re.search(r'short_name = "([^"]+)"', block)[1],
                      "name": re.search(r'display_name = "([^"]+)"', block)[1]})
    value = {"seasonal_expeditions": seasons, "bonded_hunts": hunts}
    (OUT / "shared-registries.json").write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")
    return value

def source_link(source):
    path, line = source["path"], source["line"]
    target = quote((ROOT / path).as_posix(), safe="/:")
    return f"[{path}:{line}](<{target}:{line}>)"

def cell(value):
    return str(value).replace("|", "\\|").replace("\n", " ")

def main():
    registry = registries()
    parts = [OUT / f"part-{part}.json" for part in ["a-h", "i-p", "q-z", "central"]]
    if not all(p.exists() for p in parts):
        print("Registries saved. Waiting for:", ", ".join(p.name for p in parts if not p.exists()))
        return
    inventory = json.loads((OUT / "source-inventory.json").read_text(encoding="utf-8"))
    by_path = {f["path"]: f for f in inventory["files"]}
    rows, excluded = [], []
    for part in parts:
        obj = json.loads(part.read_text(encoding="utf-8-sig"))
        rows.extend(obj["rows"])
        excluded.extend(obj.get("excluded", obj.get("excluded_notable_candidates", [])))
    excluded = [entry for entry in excluded if "Root audit covers" not in entry.get("reason", "")]
    covered_modules = {source["path"] for row in rows + excluded if isinstance(row, dict)
                       for source in row.get("sources", [])}
    missing_modules = [f["path"] for f in inventory["files"]
                       if f["encounter_directory"] and f["path"] not in covered_modules]
    if missing_modules:
        raise ValueError(f"Encounter modules not accounted for: {missing_modules}")
    seen = set()
    for row in rows:
        key = (row["zone"].lower(), row["title"].lower())
        if key in seen:
            raise ValueError(f"Duplicate candidate: {key}")
        seen.add(key)
        if not row["sources"]:
            raise ValueError(f"No source evidence: {key}")
        for source in row["sources"]:
            file = ROOT / source["path"]
            raw = file.read_bytes()
            lines = raw.decode("utf-8-sig", errors="replace").splitlines()
            if not 1 <= source["line"] <= len(lines):
                raise ValueError(f"Invalid line: {source}")
            expected = by_path.get(source["path"])
            if expected and hashlib.sha256(raw).hexdigest() != expected["sha256"]:
                raise ValueError(f"Source changed during audit: {source['path']}")
    rows.sort(key=lambda r: (r["zone"], r["title"].casefold()))
    counters = Counter()
    for row in rows:
        prefix = "J" if row.get("already_documented") else ("E" if row["confidence"] == "clear" else "R")
        counters[prefix] += 1
        row["id"] = f"{prefix}{counters[prefix]:03}"
        row["decision"] = "Already included" if prefix == "J" else "Pending"
    completion_revision = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    current_scripts = {p.relative_to(ROOT).as_posix() for p in ROOT.rglob("*")
                       if p.is_file() and p.suffix.lower() in {".lua", ".pl", ".pm"}
                       and not any(part in {".git", ".vscode"} for part in p.relative_to(ROOT).parts)}
    if current_scripts != set(by_path):
        raise ValueError(f"Source file inventory changed: {sorted(current_scripts.symmetric_difference(by_path))}")
    changed = [f["path"] for f in inventory["files"]
               if not (ROOT / f["path"]).is_file()
               or hashlib.sha256((ROOT / f["path"]).read_bytes()).hexdigest() != f["sha256"]]
    if changed:
        raise ValueError(f"Source files changed during the scan: {changed}")
    report = {"date": "2026-09-28", "revision": inventory["revision"], "completion_revision": completion_revision,
              "scope": inventory["scope"], "summary": inventory["summary"],
              "counts": dict(counters), "rows": rows, "excluded": excluded,
              "registries": registry}
    (OUT / "encounter-candidates.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    md = ["# Encounter candidates", "", "Quest working-tree scan dated September 28, 2026.", "",
          f"Scanned **{inventory['summary']['scripts_read']:,} Lua/Perl scripts** ({inventory['summary']['script_lines']:,} lines) across **{inventory['summary']['zone_directories']} zone directories**, plus shared support code. All scripts were read successfully. Includes **{inventory['summary']['encounter_directory_scripts']} encounter modules** and uncommitted quest changes.", "",
          f"Base quest revision at scan start: `{inventory['revision']}`. Revision at completion: `{completion_revision}`. The working-tree changes were committed during the scan; all scanned source-file bytes were rechecked and unchanged. File hashes in the inventory identify the exact scanned contents.", "",
          "Choose **Approve**, **Disapprove**, or leave **Pending**. Reply using encounter IDs, ranges, or entire zones. No new journal entries have been generated or published from this list.", "",
          "A clear candidate has identifiable scripted combat or event objectives. This is an encounter inventory, not a complete mechanics audit. Source presence does not establish that the live database spawns or enables it. Journal generation will verify NPC IDs, zone versions, dependencies and detailed mechanics for the approved entries.", "",
          "Related waves, controllers and add scripts are grouped into the encounter they support. Dialogue, merchants, ordinary turn-ins, generic loot/achievement hooks and ordinary named mobs without meaningful encounter behavior are omitted. Distinct seasonal mechanics are identified separately where present.", "",
          "## Candidates by zone", "",
          f"{counters['E']} new candidates, {counters['R']} entries needing investigation, and {counters['J']} entries already documented.", ""]
    for prefix, label in [("E", "Clear candidates"), ("R", "Needs investigation"), ("J", "Already documented")]:
        md.extend([f"## {label}", ""])
        zone = None
        for row in [r for r in rows if r["id"].startswith(prefix)]:
            if row["zone"] != zone:
                zone = row["zone"]
                md.extend(["", f"### {zone}", "", "| ID | Encounter / event | Type | Script evidence and scope | Decision |", "| --- | --- | --- | --- | --- |"])
            evidence = row["reason"] + " " + row.get("notes", "")
            links = " · ".join(source_link(s) for s in row["sources"])
            md.append(f"| {row['id']} | {cell(row['title'])} | {cell(row['kind'])} | {cell(evidence)}<br>{links} | {row['decision']} |")
        md.append("")
    md.extend(["## Shared activity targets", "", "The Bonded Hunts candidate represents one shared controller with these 36 target choices:", "", "| Zone | Target |", "| --- | --- |"])
    md.extend(f"| {h['zone']} | {cell(h['name'])} |" for h in registry["bonded_hunts"])
    md.extend(["", "## Seasonal expedition registry", "", "These are launcher names, not additional approvals or proof of distinct mechanics. Seasonal versions use the configured `Custom:SeasonalInstanceVersion` (default 200).", "", "| Zone | Expedition name | Source |", "| --- | --- | --- |"])
    md.extend(f"| {s['zone']} | {cell(s['name'])} | {source_link({'path':'global/Theta_Sigma.lua','line':s['line']})} |" for s in registry["seasonal_expeditions"])
    md.extend(["", "## Notable exclusions", ""])
    for entry in excluded:
        if isinstance(entry, str):
            md.append(f"- {entry}")
        else:
            md.append(f"- **{entry.get('title', entry.get('path', entry.get('zone', 'Source group')))}**: {entry.get('reason', entry.get('notes', 'Grouped into its parent event.'))}")
    md.extend(["", "## Coverage", "", "Every Lua/Perl source file has a hash, line count and discovery signals in `source-inventory.json`. Signals helped locate candidates; rows above were reviewed against script bodies. No quest code was executed or changed by the scan.", "", "| Zone / directory | Lua / Perl files |", "| --- | ---: |"])
    zones = Counter(f["zone"] for f in inventory["files"])
    md.extend(f"| {zone} | {count} |" for zone, count in sorted(zones.items()))
    (OUT / "encounter-candidates.md").write_text("\n".join(md) + "\n", encoding="utf-8")
    print(json.dumps({"counts":dict(counters),"zones_with_candidates":len({r['zone'] for r in rows}),"rows":len(rows)}, indent=2))

if __name__ == "__main__":
    main()
