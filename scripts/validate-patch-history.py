"""Validate generated patch artifacts against the supplied corpus and export contract."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
from datetime import date
from pathlib import Path


EXPECTED_RECORDS = 674
EXPECTED_OCCURRENCES = 1016
EXPECTED_SOURCES = 55
EXPECTED_FIRST = "1998-07-07"
EXPECTED_LAST = "2022-02-15"


def spreadsheet_safe(value: str) -> str:
    return "'" + value if value.startswith(("=", "+", "-", "@", "\t", "\r")) else value


def csv_value(patch: dict[str, object], header: str) -> str:
    value = patch.get(header, "")
    if header == "categories":
        value = "|".join(category["label"] for category in patch["categories"])
    elif header == "source_occurrences":
        value = json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    elif isinstance(value, list):
        value = "|".join(str(item) for item in value)
    elif isinstance(value, bool):
        value = "true" if value else "false"
    elif value is None:
        value = ""
    return spreadsheet_safe(str(value))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_directory", type=Path)
    parser.add_argument("data_directory", type=Path, nargs="?", default=Path("database/data"))
    args = parser.parse_args()

    archive_path = args.data_directory / "everquest-patch-history.json"
    csv_path = args.data_directory / "everquest-patch-history.csv"
    suggestion_path = args.data_directory / "everquest-patch-suggestions.json"
    summary_path = args.data_directory / "patch-summary-highlights.json"

    raw_json = archive_path.read_text(encoding="utf-8")
    archive = json.loads(raw_json)
    patches = archive["patches"]
    coverage = archive["coverage"]

    assert len(patches) == archive["record_count"] == coverage["patch_count"] == EXPECTED_RECORDS
    assert coverage["first_patch"] == patches[0]["patch_date"] == EXPECTED_FIRST
    assert coverage["last_patch"] == patches[-1]["patch_date"] == EXPECTED_LAST
    assert len({patch["slug"] for patch in patches}) == EXPECTED_RECORDS
    assert sum(patch["occurrence_count"] for patch in patches) == EXPECTED_OCCURRENCES
    assert all(patch["occurrence_count"] == len(patch["source_occurrences"]) for patch in patches)
    assert all(date.fromisoformat(patch["patch_date"]) for patch in patches)
    assert all(date.fromisoformat(patch["effective_date"]) for patch in patches)
    assert not re.search(r"[\u0080-\u009f\ufffd]", raw_json)

    manifest = archive["provenance"]["files"]
    source_files = sorted(path for path in args.source_directory.iterdir() if path.is_file())
    assert len(manifest) == len(source_files) == coverage["source_file_count"] == EXPECTED_SOURCES
    manifest_by_name = {item["filename"]: item for item in manifest}
    for source in source_files:
        details = manifest_by_name[source.name]
        source_bytes = source.read_bytes()
        assert details["bytes"] == len(source_bytes)
        assert details["sha256"] == hashlib.sha256(source_bytes).hexdigest()

    with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.reader(handle))
    assert csv_path.read_bytes().startswith(b"\xef\xbb\xbf")
    assert len(rows) == EXPECTED_RECORDS + 1
    headers = rows[0]
    assert all(len(row) == len(headers) for row in rows)
    for patch, row in zip(patches, rows[1:], strict=True):
        expected = [csv_value(patch, header) for header in headers]
        if row != expected:
            mismatch = next(index for index, (actual, wanted) in enumerate(zip(row, expected, strict=True)) if actual != wanted)
            raise AssertionError(
                f"CSV mismatch for {patch['slug']} field {headers[mismatch]}: "
                f"actual={row[mismatch]!r} expected={expected[mismatch]!r}"
            )
        assert not any(cell.startswith(("=", "+", "-", "@", "\t", "\r")) for cell in row)

    suggestions = json.loads(suggestion_path.read_text(encoding="utf-8"))
    assert suggestions["record_count"] == len(suggestions["patches"]) == EXPECTED_RECORDS
    assert suggestions["generated_at"] == archive["generated_at"]
    assert len({patch["slug"] for patch in suggestions["patches"]}) == EXPECTED_RECORDS

    summary = json.loads(summary_path.read_text(encoding="utf-8"))
    pdf = args.source_directory / summary["source_filename"]
    assert summary["source_sha256"] == hashlib.sha256(pdf.read_bytes()).hexdigest()
    assert summary["page_count"] == len(summary["pages"]) == 15
    assert manifest_by_name[pdf.name]["semantic_extraction"] == summary

    print(json.dumps({
        "records": len(patches),
        "occurrences": sum(patch["occurrence_count"] for patch in patches),
        "sources": len(manifest),
        "coverage": [coverage["first_patch"], coverage["last_patch"]],
        "csv_columns": len(headers),
        "pdf_pages": summary["page_count"],
        "suggestions": suggestions["record_count"],
    }, indent=2))


if __name__ == "__main__":
    main()
