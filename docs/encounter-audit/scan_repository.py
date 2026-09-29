"""Read-only quest source inventory for the encounter approval review.

This never imports or executes quest code. Signals are discovery aids, not
evidence that a file is an encounter or that it is installed on a live server.
"""
import collections
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(r"F:\EQ1\Bastion_Dev\quests")
OUT = Path(__file__).resolve().parent
SUPPORT = {"global", "lua_modules", "plugins", "tests", "review", "sql", "docs"}
PATTERNS = {
    "hp_gate": r"set_next_hp_event|SetNextHPEvent|EVENT_HP|function\s+event_hp\b|Event\.hp\b",
    "combat": r"EVENT_COMBAT|function\s+event_combat\b|Event\.combat\b",
    "timer": r"set_timer|settimer|EVENT_TIMER|function\s+event_timer\b|Event\.timer\b",
    "spawn": r"spawn2|unique_spawn|spawn_from_spawn2|SpawnNPC",
    "signal": r"signal_with|eq\.signal|quest::signal|EVENT_SIGNAL|Event\.signal\b",
    "spell": r"CastSpell|SpellFinished|spell_finished|castspell",
    "expedition": r"CreateExpedition|create_expedition|RegisterTask|AssignTask|AssignSharedTask",
    "load_encounter": r"load_encounter",
    "mechanic_words": r"\bphase\b|\bwave\b|\bwaves\b|\btrial\b|\benrage\b|\bimmun(?:e|ity)\b",
}
compiled = {k: re.compile(v, re.I) for k, v in PATTERNS.items()}

def main():
    result = {"root": str(ROOT), "revision": subprocess.check_output(
        ["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip(),
        "scope": "All .lua, .pl and .pm files in the working tree, including untracked files; .git and .vscode excluded.",
        "files": [], "errors": []}
    top_counts = collections.Counter()
    for path in sorted(ROOT.rglob("*")):
        rel = path.relative_to(ROOT)
        if any(p in {".git", ".vscode"} for p in rel.parts) or not path.is_file():
            continue
        top_counts[rel.parts[0] if len(rel.parts) > 1 else "(root)"] += 1
        if path.suffix.lower() not in {".lua", ".pl", ".pm"}:
            continue
        try:
            raw = path.read_bytes()
            text = raw.decode("utf-8-sig", errors="replace")
        except OSError as e:
            result["errors"].append({"path": rel.as_posix(), "error": str(e)})
            continue
        matches = {k: text.count("\n", 0, m.start()) + 1 for k, pat in compiled.items() if (m := pat.search(text))}
        result["files"].append({"path": rel.as_posix(), "zone": rel.parts[0],
            "support": rel.parts[0] in SUPPORT, "lines": len(text.splitlines()),
            "sha256": hashlib.sha256(raw).hexdigest(), "signals": matches,
            "encounter_directory": "encounters" in rel.parts})
    result["summary"] = {"tree_files": sum(top_counts.values()), "scripts_read": len(result["files"]),
        "script_lines": sum(f["lines"] for f in result["files"]),
        "zone_directories": len({f["zone"] for f in result["files"] if not f["support"]}),
        "encounter_directory_scripts": sum(f["encounter_directory"] for f in result["files"]),
        "support_scripts": sum(f["support"] for f in result["files"]),
        "files_by_directory": dict(sorted(top_counts.items())),
        "signal_counts": {k: sum(k in f["signals"] for f in result["files"]) for k in PATTERNS}}
    (OUT / "source-inventory.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({k:v for k,v in result["summary"].items() if k != "files_by_directory"}, indent=2))
    print("Read errors:", len(result["errors"]))

if __name__ == "__main__":
    main()
