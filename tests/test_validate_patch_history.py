from __future__ import annotations

import copy
import csv
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest import mock


MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "validate-patch-history.py"
SPEC = importlib.util.spec_from_file_location("validate_patch_history", MODULE_PATH)
assert SPEC and SPEC.loader
validator = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = validator
SPEC.loader.exec_module(validator)


class PatchHistoryValidatorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="patch-validator-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source_directory = self.root / "source"
        self.data_directory = self.root / "data"
        self.source_directory.mkdir()
        self.data_directory.mkdir()
        self.config, self.config_sha256 = validator.load_importer_config(validator.DEFAULT_CONFIG_PATH)

        source_bytes = b"fixture patch source\n"
        (self.source_directory / "source.txt").write_bytes(source_bytes)
        source_url = "https://forums.everquest.com/update.rss"
        occurrence = {
            "occurrence_id": "b" * 64,
            "filename": "source.txt",
            "adapter": "text",
            "source_offset": 0,
            "item_index": None,
            "heading": "August 22, 2026",
            "source_url": source_url,
            "source_url_raw": source_url,
            "published": None,
            "source_kind": "text",
            "excerpt": False,
            "year_inferred": False,
        }
        self.patch = {
            "id": "2026-08-22-1",
            "slug": "2026-08-22-1",
            "patch_date": "2026-08-22",
            "effective_date": "2026-08-22",
            "display_date": "August 22, 2026",
            "title": "August 22, 2026",
            "sequence": 1,
            "year": 2026,
            "month": 8,
            "kind": "live",
            "era": "EverQuest Live",
            "expansion": "Unmapped Expansion",
            "expansion_code": "unmapped-expansion",
            "categories": [{"slug": "bug-fixes", "label": "Bug Fixes"}],
            "sections": ["Highlights"],
            "change_count": 1,
            "word_count": 4,
            "summary": "Fixed an important crash.",
            "content": "Fixed an important crash.",
            "content_hash": "a" * 64,
            "source_files": ["source.txt"],
            "source_titles": ["August 22, 2026"],
            "source_url": source_url,
            "source_urls": [source_url],
            "occurrence_count": 1,
            "source_occurrences": [occurrence],
            "year_inferred": False,
        }
        manifest_item = {
            "filename": "source.txt",
            "basename": "source.txt",
            "bytes": len(source_bytes),
            "sha256": validator.hashlib.sha256(source_bytes).hexdigest(),
            "adapter": "text",
            "status": "adapted",
            "parsed_records": 1,
            "accepted_occurrences": 1,
            "rejected_occurrences": 0,
            "corroborating_unmatched_occurrences": 0,
            "detected_encoding": "utf-8",
            "warnings": [],
            "role": "canonical patch source",
            "description": "Fixture source.",
        }
        self.archive = {
            "schema_version": 1,
            "generated_at": "2026-08-22T12:00:00.000Z",
            "title": "EverQuest Historical Patch Archive",
            "description": "Fixture archive.",
            "record_count": 1,
            "filters": {},
            "coverage": {
                "first_patch": "2026-08-22",
                "last_patch": "2026-08-22",
                "patch_count": 1,
                "source_file_count": 1,
                "source_bytes": len(source_bytes),
                "years": {"2026": 1},
                "kinds": {"live": 1},
                "categories": {"bug-fixes": 1},
            },
            "provenance": {
                "supplied_directory_name": "source",
                "config_sha256": self.config_sha256,
                "note": "Fixture provenance.",
                "files": [manifest_item],
            },
            "patches": [self.patch],
        }
        self.suggestions = {
            "schema_version": 1,
            "generated_at": self.archive["generated_at"],
            "record_count": 1,
            "patches": [{
                "slug": self.patch["slug"],
                "title": self.patch["title"],
                "patch_date": self.patch["patch_date"],
                "search_text": "August 22, 2026\nFixed an important crash.",
            }],
        }
        self.report = {
            "schema_version": 1,
            "config_sha256": self.config_sha256,
            "summary": {
                "discovered_files": 1,
                "adapted_files": 1,
                "supporting_files": 0,
                "unsupported_files": 0,
                "rejected_files": 0,
                "parsed_occurrences": 1,
                "accepted_occurrences": 1,
                "duplicate_occurrences": 0,
                "rejected_occurrences": 0,
                "corroborating_unmatched_occurrences": 0,
                "public_records": 1,
                "feed_excerpt_occurrences": 0,
                "published_feed_excerpt_occurrences": 0,
            },
            "slugs": {
                "enabled": True,
                "prior_archive_found": False,
                "reused": 0,
                "assigned": 1,
                "collisions": [],
                "reassignments": [],
            },
            "files": [{
                "path": "source.txt",
                "adapter": "text",
                "status": "adapted",
                "role": "canonical patch source",
                "parsed_records": 1,
                "accepted_occurrences": 1,
                "rejected_occurrences": 0,
                "corroborating_unmatched_occurrences": 0,
                "warnings": [],
            }],
            "duplicates": [],
            "rejections": [],
            "warnings": [],
        }
        self.write_artifacts()

    def write_archive(self, archive: dict | None = None) -> None:
        value = self.archive if archive is None else archive
        (self.data_directory / "everquest-patch-history.json").write_text(
            json.dumps(value, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    def write_csv(self, headers: tuple[str, ...] | list[str] = validator.CSV_HEADERS) -> None:
        with (self.data_directory / "everquest-patch-history.csv").open(
            "w",
            encoding="utf-8-sig",
            newline="",
        ) as handle:
            writer = csv.writer(handle, lineterminator="\r\n")
            writer.writerow(headers)
            writer.writerow([validator.csv_value(self.patch, header) for header in headers])

    def write_suggestions(self) -> None:
        (self.data_directory / "everquest-patch-suggestions.json").write_text(
            json.dumps(self.suggestions, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    def write_report(self, report: dict | None = None) -> None:
        value = self.report if report is None else report
        (self.data_directory / "everquest-patch-import-report.json").write_text(
            json.dumps(value, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )

    def write_artifacts(self) -> None:
        self.write_archive()
        self.write_csv()
        self.write_suggestions()
        self.write_report()

    def validated_archive(self, archive: dict | None = None) -> tuple[list[dict], dict]:
        value = self.archive if archive is None else archive
        return validator.validate_archive(value, json.dumps(value, ensure_ascii=False))

    def validate_all(self, *, require_report: bool = True) -> dict:
        patches, _ = self.validated_archive()
        manifest = validator.validate_manifest(self.source_directory, self.archive)
        validator.validate_csv(self.data_directory / "everquest-patch-history.csv", patches)
        return validator.validate_sidecars(
            self.data_directory,
            self.archive,
            manifest,
            require_report=require_report,
        )

    def run_cli(self, *extra_arguments: str) -> dict:
        arguments = [
            str(MODULE_PATH),
            str(self.source_directory),
            str(self.data_directory),
            "--config",
            str(validator.DEFAULT_CONFIG_PATH),
            *extra_arguments,
        ]
        with mock.patch.object(sys, "argv", arguments), redirect_stdout(io.StringIO()) as output:
            validator.main()
        return json.loads(output.getvalue())

    def test_valid_full_contract(self) -> None:
        result = self.validate_all()
        self.assertEqual(result["suggestions"], 1)
        self.assertEqual(result["report_rejections"], 0)

    def test_cli_passes_with_current_corpus_and_config(self) -> None:
        result = self.run_cli()
        self.assertEqual(result["source_file_count"], 1)
        self.assertEqual(result["config_sha256"], self.config_sha256)

    def test_added_discoverable_source_requires_reimport(self) -> None:
        (self.source_directory / "new-source.txt").write_text("August 23, 2026\nnew patch\n", encoding="utf-8")
        with self.assertRaisesRegex(AssertionError, "unimported discoverable source file.*new-source\\.txt"):
            self.run_cli()

    def test_generated_and_http_cache_files_are_excluded_from_freshness(self) -> None:
        generated_directory = self.source_directory / "generated"
        generated_directory.mkdir()
        (generated_directory / "everquest-patch-history.json").write_text("{}\n", encoding="utf-8")
        (self.source_directory / "official.http-cache.json").write_text("{}\n", encoding="utf-8")
        discovered = validator.validate_corpus_freshness(
            self.source_directory,
            generated_directory,
            self.archive["provenance"]["files"],
            self.config,
        )
        self.assertEqual(discovered, ["source.txt"])

        same_root = self.root / "same-root"
        same_root.mkdir()
        (same_root / "source.txt").write_text("August 22, 2026\npatch body\n", encoding="utf-8")
        for filename in (
            "everquest-patch-history.json",
            "everquest-patch-history.csv",
            "everquest-patch-suggestions.json",
            "everquest-patch-import-report.json",
        ):
            (same_root / filename).write_text("{}\n", encoding="utf-8")
        self.assertEqual(
            validator.discover_source_files(same_root, same_root, self.config),
            ["source.txt"],
        )

    def test_active_config_fingerprint_must_match_generated_artifacts(self) -> None:
        override_path = self.root / "import-override.json"
        override_path.write_text(
            json.dumps({"schema_version": 1, "safety": {"maximum_removal_percent": 1}}),
            encoding="utf-8",
        )
        arguments = [
            str(MODULE_PATH),
            str(self.source_directory),
            str(self.data_directory),
            "--config",
            str(override_path),
        ]
        with mock.patch.object(sys, "argv", arguments), self.assertRaisesRegex(
            AssertionError,
            "active importer config does not match archive provenance",
        ):
            validator.main()

    def test_csv_requires_exact_27_headers_in_order(self) -> None:
        variants = [
            list(validator.CSV_HEADERS[:-1]),
            [validator.CSV_HEADERS[1], validator.CSV_HEADERS[0], *validator.CSV_HEADERS[2:]],
        ]
        for headers in variants:
            with self.subTest(headers=headers[:2]):
                self.write_csv(headers)
                with self.assertRaisesRegex(AssertionError, "27-column patch export contract"):
                    validator.validate_csv(self.data_directory / "everquest-patch-history.csv", [self.patch])

    def test_runtime_patch_fields_and_invariants_are_required(self) -> None:
        cases = []

        missing_title = copy.deepcopy(self.archive)
        missing_title["patches"][0].pop("title")
        cases.append((missing_title, "missing required fields: title"))

        boolean_sequence = copy.deepcopy(self.archive)
        boolean_sequence["patches"][0]["sequence"] = True
        cases.append((boolean_sequence, "sequence invalid"))

        wrong_year = copy.deepcopy(self.archive)
        wrong_year["patches"][0]["year"] = 2025
        cases.append((wrong_year, "year mismatch"))

        unsafe_url = copy.deepcopy(self.archive)
        unsafe_url["patches"][0]["source_url"] = "javascript:alert(1)"
        cases.append((unsafe_url, "HTTP\\(S\\) URL"))

        malformed_category = copy.deepcopy(self.archive)
        malformed_category["patches"][0]["categories"][0]["slug"] = "Bad Category"
        cases.append((malformed_category, "categories\\[0\\]\\.slug invalid"))

        zero_item_index = copy.deepcopy(self.archive)
        zero_item_index["patches"][0]["source_occurrences"][0]["item_index"] = 0
        cases.append((zero_item_index, "item_index invalid"))

        unsafe_raw_url = copy.deepcopy(self.archive)
        unsafe_raw_url["patches"][0]["source_occurrences"][0]["source_url_raw"] = "https://user:secret@example.com/private"
        cases.append((unsafe_raw_url, "source_url_raw must be an HTTP\\(S\\) URL without credentials"))

        for archive, message in cases:
            with self.subTest(message=message), self.assertRaisesRegex(AssertionError, message):
                self.validated_archive(archive)

    def test_sidecars_are_required_and_legacy_report_has_one_opt_out(self) -> None:
        report_path = self.data_directory / "everquest-patch-import-report.json"
        report_path.unlink()
        with self.assertRaisesRegex(AssertionError, "required import report is missing"):
            self.validate_all()
        result = self.validate_all(require_report=False)
        self.assertEqual(result, {"suggestions": 1})

        self.write_report()
        (self.data_directory / "everquest-patch-suggestions.json").unlink()
        with self.assertRaisesRegex(AssertionError, "required suggestion index is missing"):
            self.validate_all(require_report=False)

    def test_cli_allows_only_the_explicit_legacy_missing_report_case(self) -> None:
        (self.data_directory / "everquest-patch-import-report.json").unlink()
        self.assertEqual(self.run_cli("--allow-missing-report")["csv_columns"], 27)

    def test_real_report_rejections_fail(self) -> None:
        report = copy.deepcopy(self.report)
        report["rejections"] = [{"path": "source.txt", "code": "invalid_date_heading", "message": "bad"}]
        report["summary"]["rejected_occurrences"] = 1
        report["summary"]["parsed_occurrences"] = 2
        report["files"][0]["rejected_occurrences"] = 1
        self.write_report(report)
        with self.assertRaisesRegex(AssertionError, "1 real rejection"):
            self.validate_all()

    def test_report_config_hash_must_match_archive_provenance(self) -> None:
        report = copy.deepcopy(self.report)
        report["config_sha256"] = "d" * 64
        self.write_report(report)
        with self.assertRaisesRegex(AssertionError, "does not match archive provenance"):
            self.validate_all()

    def test_slug_collisions_fail(self) -> None:
        report = copy.deepcopy(self.report)
        report["slugs"]["collisions"] = [{"requested_slug": "2026-08-22-1"}]
        self.write_report(report)
        with self.assertRaisesRegex(AssertionError, "slug collision/reassignment"):
            self.validate_all()

    def test_corroborating_only_warnings_remain_allowed(self) -> None:
        report = copy.deepcopy(self.report)
        report["summary"]["corroborating_unmatched_occurrences"] = 1
        report["summary"]["parsed_occurrences"] = 2
        report["files"][0]["corroborating_unmatched_occurrences"] = 1
        report["warnings"] = [{
            "path": "source.txt",
            "code": "corroborating_record_unmatched",
            "message": "corroborating-only fixture",
        }]
        self.write_report(report)
        result = self.validate_all()
        self.assertEqual(result["report_corroborating_unmatched"], 1)
        self.assertEqual(result["report_warnings"], 1)


if __name__ == "__main__":
    unittest.main()
