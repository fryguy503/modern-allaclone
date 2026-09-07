"""Validate patch artifacts structurally, with an optional pinned snapshot."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
import os
import re
from collections import Counter
from datetime import date
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import urlsplit


CONTROL_OR_REPLACEMENT = re.compile(r"[\u0080-\u009f\ufffd]")
FORMULA_PREFIXES = ("=", "+", "-", "@", "\t", "\r")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
ISO_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
SLUG_VALUE = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
CSV_HEADERS = (
    "id", "slug", "patch_date", "effective_date", "display_date", "title", "sequence", "year", "month",
    "kind", "era", "expansion", "expansion_code", "categories", "sections", "change_count", "word_count",
    "summary", "content", "content_hash", "source_files", "source_titles", "source_url", "source_urls",
    "occurrence_count", "source_occurrences", "year_inferred",
)
REQUIRED_PATCH_FIELDS = frozenset(CSV_HEADERS)
PATCH_KINDS = frozenset({"beta", "hotfix", "live", "news"})
REQUIRED_OCCURRENCE_FIELDS = frozenset({
    "occurrence_id", "filename", "source_offset", "heading", "source_url", "year_inferred",
})
SCRIPT_DIRECTORY = Path(__file__).resolve().parent
DEFAULT_CONFIG_PATH = SCRIPT_DIRECTORY / "patch-import.config.json"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def is_integer(value: object) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def require_nonempty_string(value: object, location: str) -> str:
    require(isinstance(value, str) and bool(value.strip()), f"{location} must be a non-empty string")
    return value


def require_string_list(value: object, location: str, *, allow_empty: bool = True) -> list[str]:
    require(isinstance(value, list), f"{location} must be an array")
    require(allow_empty or bool(value), f"{location} must be non-empty")
    require(all(isinstance(item, str) and item.strip() for item in value), f"{location} must contain non-empty strings")
    require(len(value) == len(set(value)), f"{location} contains duplicates")
    return value


def require_http_url(value: object, location: str) -> str:
    require(isinstance(value, str) and bool(value), f"{location} must be an HTTP(S) URL")
    try:
        parsed = urlsplit(value)
        parsed.port
    except ValueError as error:
        raise AssertionError(f"{location} must be an HTTP(S) URL") from error
    require(
        parsed.scheme.lower() in {"http", "https"}
        and bool(parsed.hostname)
        and parsed.username is None
        and parsed.password is None,
        f"{location} must be an HTTP(S) URL without credentials",
    )
    return value


def spreadsheet_safe(value: str) -> str:
    return "'" + value if value.startswith(FORMULA_PREFIXES) else value


def csv_value(patch: dict[str, Any], header: str) -> str:
    value: Any = patch.get(header, "")
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


def safe_source_path(root: Path, relative: str) -> Path:
    posix = PurePosixPath(relative)
    require(not posix.is_absolute() and ".." not in posix.parts, f"unsafe source path: {relative!r}")
    candidate = (root / Path(*posix.parts)).resolve()
    source_root = root.resolve()
    require(candidate == source_root or source_root in candidate.parents, f"source path escapes corpus: {relative!r}")
    return candidate


def javascript_number(value: float) -> str:
    """Serialize finite JSON numbers like JSON.stringify for importer config hashes."""
    require(math.isfinite(value), "importer config contains a non-finite number")
    if value == 0:
        return "0"
    absolute = abs(value)
    representation = repr(value).lower()
    if 1e-6 <= absolute < 1e21:
        if "e" not in representation:
            return representation[:-2] if representation.endswith(".0") else representation
        mantissa, raw_exponent = representation.split("e", 1)
        exponent = int(raw_exponent)
        negative = mantissa.startswith("-")
        digits = mantissa.lstrip("-").replace(".", "")
        decimal_position = (mantissa.lstrip("-").find(".") if "." in mantissa else len(digits)) + exponent
        if decimal_position <= 0:
            result = f"0.{('0' * -decimal_position)}{digits}"
        elif decimal_position >= len(digits):
            result = f"{digits}{'0' * (decimal_position - len(digits))}"
        else:
            result = f"{digits[:decimal_position]}.{digits[decimal_position:]}"
        return f"-{result}" if negative else result
    if "e" not in representation:
        representation = format(value, ".15e")
    mantissa, raw_exponent = representation.split("e", 1)
    mantissa = mantissa.rstrip("0").rstrip(".")
    exponent = int(raw_exponent)
    return f"{mantissa}e{'+' if exponent >= 0 else ''}{exponent}"


def stable_json(value: Any) -> str:
    """Match the builder's recursively key-sorted stableStringify contract."""
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        return javascript_number(value)
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    if isinstance(value, list):
        return f"[{','.join(stable_json(item) for item in value)}]"
    if isinstance(value, dict):
        require(all(isinstance(key, str) for key in value), "importer config object keys must be strings")
        pairs = (
            f"{json.dumps(key, ensure_ascii=False)}:{stable_json(value[key])}"
            for key in sorted(value)
        )
        return f"{{{','.join(pairs)}}}"
    raise AssertionError(f"importer config contains an unsupported value: {type(value).__name__}")


def clone_json(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: clone_json(item) for key, item in value.items()}
    if isinstance(value, list):
        return [clone_json(item) for item in value]
    return value


def deep_merge_json(base: Any, override: Any) -> Any:
    """Mirror the importer's object merge and array replacement behavior."""
    if isinstance(override, list):
        return clone_json(override)
    if not isinstance(override, dict):
        return override
    merged = clone_json(base) if isinstance(base, dict) else {}
    for key, value in override.items():
        merged[key] = deep_merge_json(merged.get(key), value)
    return merged


def load_importer_config(config_path: Path = DEFAULT_CONFIG_PATH) -> tuple[dict[str, Any], str]:
    default_path = DEFAULT_CONFIG_PATH.resolve()
    active_path = config_path.resolve()
    defaults = json.loads(default_path.read_text(encoding="utf-8"))
    override = {} if active_path == default_path else json.loads(active_path.read_text(encoding="utf-8"))
    require(isinstance(defaults, dict), "default importer config must be an object")
    require(isinstance(override, dict), "importer config override must be an object")
    config = deep_merge_json(defaults, override)
    if isinstance(override.get("source_overrides"), list):
        config["source_overrides"] = [
            *clone_json(defaults.get("source_overrides", [])),
            *clone_json(override["source_overrides"]),
        ]
    require(config.get("schema_version") == 1, f"unsupported importer config schema: {config.get('schema_version')!r}")
    fingerprint = hashlib.sha256(stable_json(config).encode("utf-8")).hexdigest()
    return config, fingerprint


def normalize_relative_path(value: str) -> str:
    return value.replace("\\", "/").removeprefix("./")


def glob_to_regex(glob: str) -> re.Pattern[str]:
    source = normalize_relative_path(glob)
    expression = "^"
    index = 0
    while index < len(source):
        character = source[index]
        if character == "*" and index + 1 < len(source) and source[index + 1] == "*":
            index += 2
            if index < len(source) and source[index] == "/":
                expression += "(?:.*/)?"
                index += 1
            else:
                expression += ".*"
        elif character == "*":
            expression += "[^/]*"
            index += 1
        elif character == "?":
            expression += "[^/]"
            index += 1
        else:
            expression += re.escape(character)
            index += 1
    return re.compile(f"{expression}$", re.IGNORECASE)


def config_string_list(value: object, location: str, default: list[str]) -> list[str]:
    if value is None:
        return default
    require(isinstance(value, list), f"{location} must be an array")
    require(all(isinstance(item, str) for item in value), f"{location} must contain strings")
    return value


def discover_source_files(source_directory: Path, data_directory: Path, config: dict[str, Any]) -> list[str]:
    """Inventory regular files using the Node importer's recursive discovery rules."""
    source_root = source_directory.resolve()
    data_root = data_directory.resolve()
    discovery = config.get("discovery")
    require(discovery is None or isinstance(discovery, dict), "importer config discovery must be an object")
    discovery = discovery or {}
    included = [glob_to_regex(value) for value in config_string_list(
        discovery.get("include_globs"), "discovery.include_globs", ["**/*"],
    )]
    excluded = [glob_to_regex(value) for value in config_string_list(
        discovery.get("exclude_globs"), "discovery.exclude_globs", [],
    )]
    excluded_directories = {
        value.lower() for value in config_string_list(
            discovery.get("exclude_directories"), "discovery.exclude_directories", [],
        )
    }
    generated_output_targets = {
        str((data_root / filename).resolve()).lower()
        for filename in (
            "everquest-patch-history.json",
            "everquest-patch-history.csv",
            "everquest-patch-suggestions.json",
            "everquest-patch-import-report.json",
        )
    }

    try:
        output_relative = normalize_relative_path(os.path.relpath(data_root, source_root))
        if output_relative == ".":
            output_relative = ""
        output_inside_source = bool(output_relative) and not output_relative.startswith("..") and not os.path.isabs(output_relative)
    except ValueError:  # Different Windows drives cannot have a relative path.
        output_relative = ""
        output_inside_source = False

    files: list[str] = []

    def walk(directory: Path, relative_directory: str = "") -> None:
        with os.scandir(directory) as iterator:
            entries = sorted(iterator, key=lambda item: item.name.lower())
        for entry in entries:
            relative_path = normalize_relative_path(
                f"{relative_directory}/{entry.name}" if relative_directory else entry.name,
            )
            if output_inside_source and (
                relative_path == output_relative or relative_path.startswith(f"{output_relative}/")
            ):
                continue
            if entry.is_symlink():
                continue
            if entry.is_dir(follow_symlinks=False):
                if entry.name.lower() not in excluded_directories:
                    walk(Path(entry.path), relative_path)
                continue
            if not entry.is_file(follow_symlinks=False):
                continue
            if str(Path(entry.path).resolve()).lower() in generated_output_targets:
                continue
            if not any(pattern.fullmatch(relative_path) for pattern in included):
                continue
            if any(pattern.fullmatch(relative_path) for pattern in excluded):
                continue
            files.append(relative_path)

    walk(source_root)
    return sorted(files, key=lambda value: value.lower())


def validate_corpus_freshness(
    source_directory: Path,
    data_directory: Path,
    manifest: list[dict[str, Any]],
    config: dict[str, Any],
) -> list[str]:
    discovered = discover_source_files(source_directory, data_directory, config)
    manifest_names = [item["filename"] for item in manifest]
    newly_added = sorted(set(discovered) - set(manifest_names))
    no_longer_discovered = sorted(set(manifest_names) - set(discovered))
    require(
        not newly_added,
        f"corpus has unimported discoverable source file(s): {', '.join(newly_added)}",
    )
    require(
        not no_longer_discovered,
        f"manifest contains source file(s) not discoverable with the active config: {', '.join(no_longer_discovered)}",
    )
    return discovered


def validate_expected(actual: Any, expected: Any, location: str = "snapshot") -> None:
    """Treat an expected snapshot as a recursively matched JSON subset."""
    if isinstance(expected, dict):
        require(isinstance(actual, dict), f"{location} must be an object")
        for key, value in expected.items():
            require(key in actual, f"{location}.{key} is missing")
            validate_expected(actual[key], value, f"{location}.{key}")
        return
    require(actual == expected, f"{location}: expected {expected!r}, got {actual!r}")


def validate_archive(archive: dict[str, Any], raw_json: str) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    require(isinstance(archive, dict), "archive root must be an object")
    require(archive.get("schema_version") == 1, "archive.schema_version must be 1")
    require_nonempty_string(archive.get("generated_at"), "archive.generated_at")
    require_nonempty_string(archive.get("title"), "archive.title")
    require_nonempty_string(archive.get("description"), "archive.description")
    require(isinstance(archive.get("filters"), dict), "archive.filters must be an object")
    provenance = archive.get("provenance")
    require(isinstance(provenance, dict), "archive.provenance must be an object")
    require_nonempty_string(provenance.get("note"), "archive.provenance.note")
    patches = archive.get("patches")
    coverage = archive.get("coverage")
    require(isinstance(patches, list) and patches, "archive.patches must be a non-empty array")
    require(isinstance(coverage, dict), "archive.coverage must be an object")
    require(not CONTROL_OR_REPLACEMENT.search(raw_json), "archive contains C1 controls or replacement characters")
    require(is_integer(archive.get("record_count")) and archive["record_count"] == len(patches), "record_count mismatch")
    require(is_integer(coverage.get("patch_count")) and coverage["patch_count"] == len(patches), "coverage.patch_count mismatch")

    slugs: set[str] = set()
    occurrence_ids: set[str] = set()
    ordering: list[tuple[str, int, str]] = []
    year_counts: Counter[str] = Counter()
    kind_counts: Counter[str] = Counter()
    category_counts: Counter[str] = Counter()
    for index, patch in enumerate(patches):
        prefix = f"patches[{index}]"
        require(isinstance(patch, dict), f"{prefix} must be an object")
        missing = sorted(REQUIRED_PATCH_FIELDS - patch.keys())
        require(not missing, f"{prefix} missing required fields: {', '.join(missing)}")
        patch_date = patch.get("patch_date")
        effective = patch.get("effective_date")
        require(isinstance(patch_date, str) and ISO_DATE.fullmatch(patch_date) is not None, f"{prefix}.patch_date malformed")
        require(isinstance(effective, str) and ISO_DATE.fullmatch(effective) is not None, f"{prefix}.effective_date malformed")
        date.fromisoformat(patch_date)
        date.fromisoformat(effective)
        sequence = patch.get("sequence")
        require(is_integer(sequence) and sequence >= 1, f"{prefix}.sequence invalid")
        slug = f"{patch_date}-{sequence}"
        require_nonempty_string(patch.get("slug"), f"{prefix}.slug")
        require_nonempty_string(patch.get("id"), f"{prefix}.id")
        require(patch["slug"] == slug and patch["id"] == slug, f"{prefix} slug/id mismatch")
        require(slug not in slugs, f"duplicate slug: {slug}")
        slugs.add(slug)

        require_nonempty_string(patch.get("display_date"), f"{prefix}.display_date")
        require_nonempty_string(patch.get("title"), f"{prefix}.title")
        require(is_integer(patch.get("year")) and patch["year"] == int(patch_date[:4]), f"{prefix}.year mismatch")
        require(is_integer(patch.get("month")) and patch["month"] == int(patch_date[5:7]), f"{prefix}.month mismatch")
        require(patch.get("kind") in PATCH_KINDS, f"{prefix}.kind invalid")
        require_nonempty_string(patch.get("era"), f"{prefix}.era")
        require_nonempty_string(patch.get("expansion"), f"{prefix}.expansion")
        expansion_code = require_nonempty_string(patch.get("expansion_code"), f"{prefix}.expansion_code")
        require(SLUG_VALUE.fullmatch(expansion_code) is not None, f"{prefix}.expansion_code invalid")

        categories = patch.get("categories")
        require(isinstance(categories, list), f"{prefix}.categories must be an array")
        category_slugs: set[str] = set()
        for category_index, category in enumerate(categories):
            category_prefix = f"{prefix}.categories[{category_index}]"
            require(isinstance(category, dict), f"{category_prefix} must be an object")
            category_slug = require_nonempty_string(category.get("slug"), f"{category_prefix}.slug")
            require(SLUG_VALUE.fullmatch(category_slug) is not None, f"{category_prefix}.slug invalid")
            require_nonempty_string(category.get("label"), f"{category_prefix}.label")
            require(category_slug not in category_slugs, f"{prefix}.categories contains duplicate slug {category_slug!r}")
            category_slugs.add(category_slug)
            category_counts[category_slug] += 1

        require_string_list(patch.get("sections"), f"{prefix}.sections")
        require(is_integer(patch.get("change_count")) and patch["change_count"] >= 0, f"{prefix}.change_count invalid")
        require(is_integer(patch.get("word_count")) and patch["word_count"] >= 1, f"{prefix}.word_count invalid")
        require_nonempty_string(patch.get("summary"), f"{prefix}.summary")
        require_nonempty_string(patch.get("content"), f"{prefix}.content")
        content_hash = patch.get("content_hash")
        require(isinstance(content_hash, str) and SHA256.fullmatch(content_hash) is not None, f"{prefix}.content_hash invalid")

        source_files = require_string_list(patch.get("source_files"), f"{prefix}.source_files", allow_empty=False)
        source_titles = require_string_list(patch.get("source_titles"), f"{prefix}.source_titles", allow_empty=False)
        source_urls = require_string_list(patch.get("source_urls"), f"{prefix}.source_urls")
        require(source_files == sorted(source_files), f"{prefix}.source_files must be sorted")
        require(source_titles == sorted(source_titles), f"{prefix}.source_titles must be sorted")
        require(source_urls == sorted(source_urls), f"{prefix}.source_urls must be sorted")
        for source_url_index, source_url in enumerate(source_urls):
            require_http_url(source_url, f"{prefix}.source_urls[{source_url_index}]")
        primary_source_url = patch.get("source_url")
        require(primary_source_url is None or isinstance(primary_source_url, str), f"{prefix}.source_url must be a string or null")
        if primary_source_url is not None:
            require_http_url(primary_source_url, f"{prefix}.source_url")
            require(primary_source_url in source_urls, f"{prefix}.source_url is not represented in source_urls")

        occurrences = patch.get("source_occurrences")
        require(isinstance(occurrences, list) and occurrences, f"{prefix}.source_occurrences empty")
        require(
            is_integer(patch.get("occurrence_count")) and patch["occurrence_count"] == len(occurrences),
            f"{prefix}.occurrence_count mismatch",
        )
        occurrence_files: set[str] = set()
        occurrence_titles: set[str] = set()
        occurrence_urls: set[str] = set()
        for occurrence_index, occurrence in enumerate(occurrences):
            occurrence_prefix = f"{prefix}.source_occurrences[{occurrence_index}]"
            require(isinstance(occurrence, dict), f"{occurrence_prefix} must be an object")
            occurrence_missing = sorted(REQUIRED_OCCURRENCE_FIELDS - occurrence.keys())
            require(not occurrence_missing, f"{occurrence_prefix} missing required fields: {', '.join(occurrence_missing)}")
            occurrence_id = occurrence.get("occurrence_id")
            require(isinstance(occurrence_id, str) and SHA256.fullmatch(occurrence_id) is not None, f"{occurrence_prefix}.occurrence_id invalid")
            require(occurrence_id not in occurrence_ids, f"duplicate occurrence id: {occurrence_id}")
            occurrence_ids.add(occurrence_id)
            filename = occurrence.get("filename")
            require_nonempty_string(filename, f"{occurrence_prefix}.filename")
            safe_source_path(Path("."), filename)
            occurrence_files.add(filename)
            source_offset = occurrence.get("source_offset")
            require(is_integer(source_offset) and source_offset >= 0, f"{occurrence_prefix}.source_offset invalid")
            heading = require_nonempty_string(occurrence.get("heading"), f"{occurrence_prefix}.heading")
            occurrence_titles.add(heading)
            source_url = occurrence.get("source_url")
            require(source_url is None or isinstance(source_url, str), f"{occurrence_prefix}.source_url must be a string or null")
            if source_url is not None:
                require_http_url(source_url, f"{occurrence_prefix}.source_url")
                occurrence_urls.add(source_url)
            require(isinstance(occurrence.get("year_inferred"), bool), f"{occurrence_prefix}.year_inferred must be boolean")
            if "adapter" in occurrence:
                require_nonempty_string(occurrence.get("adapter"), f"{occurrence_prefix}.adapter")
            if "item_index" in occurrence:
                item_index = occurrence.get("item_index")
                require(item_index is None or (is_integer(item_index) and item_index >= 1), f"{occurrence_prefix}.item_index invalid")
            if "source_url_raw" in occurrence:
                source_url_raw = occurrence.get("source_url_raw")
                require(source_url_raw is None or isinstance(source_url_raw, str), f"{occurrence_prefix}.source_url_raw invalid")
                if source_url_raw is not None:
                    require_http_url(source_url_raw, f"{occurrence_prefix}.source_url_raw")
            if "published" in occurrence:
                require(occurrence.get("published") is None or isinstance(occurrence["published"], str), f"{occurrence_prefix}.published invalid")
            if "source_kind" in occurrence:
                require_nonempty_string(occurrence.get("source_kind"), f"{occurrence_prefix}.source_kind")
            if "excerpt" in occurrence:
                require(isinstance(occurrence.get("excerpt"), bool), f"{occurrence_prefix}.excerpt must be boolean")
        require(source_files == sorted(occurrence_files), f"{prefix}.source_files mismatch")
        require(source_titles == sorted(occurrence_titles), f"{prefix}.source_titles mismatch")
        require(source_urls == sorted(occurrence_urls), f"{prefix}.source_urls mismatch")
        require(isinstance(patch.get("year_inferred"), bool), f"{prefix}.year_inferred must be boolean")
        ordering.append((patch_date, sequence, content_hash))
        year_counts[patch_date[:4]] += 1
        kind_counts[patch["kind"]] += 1

    require(ordering == sorted(ordering), "patches are not sorted by date/sequence/hash")
    require(coverage.get("first_patch") == patches[0]["patch_date"], "coverage.first_patch mismatch")
    require(coverage.get("last_patch") == patches[-1]["patch_date"], "coverage.last_patch mismatch")
    require(coverage.get("years") == dict(sorted(year_counts.items())), "coverage.years mismatch")
    require(coverage.get("kinds") == dict(sorted(kind_counts.items())), "coverage.kinds mismatch")
    require(coverage.get("categories") == dict(sorted(category_counts.items())), "coverage.categories mismatch")
    return patches, coverage


def validate_manifest(source_directory: Path, archive: dict[str, Any]) -> list[dict[str, Any]]:
    manifest = archive.get("provenance", {}).get("files")
    require(isinstance(manifest, list) and manifest, "provenance.files must be non-empty")
    names = [item.get("filename") for item in manifest]
    require(all(isinstance(name, str) and name for name in names), "manifest filename invalid")
    require(len(names) == len(set(names)), "duplicate manifest filename")
    total_bytes = 0
    for item in manifest:
        filename = item["filename"]
        source = safe_source_path(source_directory, filename)
        require(source.is_file() and not source.is_symlink(), f"source missing or symlinked: {filename}")
        source_bytes = source.read_bytes()
        total_bytes += len(source_bytes)
        require(item.get("bytes") == len(source_bytes), f"source size mismatch: {filename}")
        require(item.get("sha256") == hashlib.sha256(source_bytes).hexdigest(), f"source hash mismatch: {filename}")
        if item.get("status") is not None:
            require(item["status"] in {"adapted", "supporting", "unsupported", "rejected"}, f"source status invalid: {filename}")
    coverage = archive["coverage"]
    require(coverage.get("source_file_count") == len(manifest), "coverage.source_file_count mismatch")
    require(coverage.get("source_bytes") == total_bytes, "coverage.source_bytes mismatch")
    occurrence_files = {name for patch in archive["patches"] for name in patch["source_files"]}
    require(occurrence_files <= set(names), "record provenance references an unmanifested file")
    return manifest


def validate_csv(csv_path: Path, patches: list[dict[str, Any]]) -> int:
    require(csv_path.read_bytes().startswith(b"\xef\xbb\xbf"), "CSV lacks UTF-8 BOM")
    with csv_path.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.reader(handle))
    require(bool(rows) and len(rows) == len(patches) + 1, "CSV row count mismatch")
    headers = rows[0]
    require(headers == list(CSV_HEADERS), "CSV headers/order do not match the 27-column patch export contract")
    require(all(len(row) == len(headers) for row in rows), "CSV has a ragged row")
    for patch, row in zip(patches, rows[1:], strict=True):
        expected = [csv_value(patch, header) for header in CSV_HEADERS]
        if row != expected:
            mismatch = next(i for i, pair in enumerate(zip(row, expected, strict=True)) if pair[0] != pair[1])
            raise AssertionError(f"CSV mismatch for {patch['slug']} field {headers[mismatch]}: actual={row[mismatch]!r} expected={expected[mismatch]!r}")
        require(not any(cell.startswith(FORMULA_PREFIXES) for cell in row), f"formula-unsafe CSV cell in {patch['slug']}")
    return len(CSV_HEADERS)


def validate_sidecars(
    data_directory: Path,
    archive: dict[str, Any],
    manifest: list[dict[str, Any]],
    *,
    require_report: bool = True,
    active_config_sha256: str | None = None,
) -> dict[str, Any]:
    result: dict[str, Any] = {}
    suggestions_path = data_directory / "everquest-patch-suggestions.json"
    require(suggestions_path.is_file(), "required suggestion index is missing")
    suggestions = json.loads(suggestions_path.read_text(encoding="utf-8"))
    require(isinstance(suggestions, dict), "suggestion index root must be an object")
    require(suggestions.get("schema_version") == 1, "suggestion schema_version must be 1")
    require(suggestions.get("record_count") == len(archive["patches"]), "suggestion count mismatch")
    suggestion_patches = suggestions.get("patches")
    require(isinstance(suggestion_patches, list), "suggestion patches must be an array")
    require(len(suggestion_patches) == len(archive["patches"]), "suggestion length mismatch")
    require(suggestions.get("generated_at") == archive.get("generated_at"), "suggestion timestamp mismatch")
    for index, (suggestion, patch) in enumerate(zip(suggestion_patches, archive["patches"], strict=True)):
        prefix = f"suggestions.patches[{index}]"
        require(isinstance(suggestion, dict), f"{prefix} must be an object")
        for field in ("slug", "title", "patch_date", "search_text"):
            require_nonempty_string(suggestion.get(field), f"{prefix}.{field}")
        require(suggestion["slug"] == patch["slug"], f"{prefix}.slug mismatch")
        require(suggestion["title"] == patch["title"], f"{prefix}.title mismatch")
        require(suggestion["patch_date"] == patch["patch_date"], f"{prefix}.patch_date mismatch")
    result["suggestions"] = suggestions["record_count"]

    report_path = data_directory / "everquest-patch-import-report.json"
    if not report_path.is_file():
        require(not require_report, "required import report is missing")
        return result

    report = json.loads(report_path.read_text(encoding="utf-8"))
    require(isinstance(report, dict), "import report root must be an object")
    require(report.get("schema_version") == 1, "import report schema_version must be 1")
    config_hash = report.get("config_sha256")
    require(isinstance(config_hash, str) and SHA256.fullmatch(config_hash) is not None, "import report config_sha256 invalid")
    archive_config_hash = archive.get("provenance", {}).get("config_sha256")
    require(
        isinstance(archive_config_hash, str) and SHA256.fullmatch(archive_config_hash) is not None,
        "archive provenance config_sha256 invalid",
    )
    require(config_hash == archive_config_hash, "import report config_sha256 does not match archive provenance")
    if active_config_sha256 is not None:
        require(
            SHA256.fullmatch(active_config_sha256) is not None,
            "active importer config fingerprint is invalid",
        )
        require(
            archive_config_hash == active_config_sha256,
            "active importer config does not match archive provenance; rerun the patch importer",
        )
        require(
            config_hash == active_config_sha256,
            "active importer config does not match the import report; rerun the patch importer",
        )
    summary = report.get("summary")
    files = report.get("files")
    rejections = report.get("rejections")
    warnings = report.get("warnings")
    slugs = report.get("slugs")
    require(isinstance(summary, dict), "import report summary must be an object")
    require(isinstance(files, list), "import report files must be an array")
    require(isinstance(rejections, list), "import report rejections must be an array")
    require(isinstance(warnings, list), "import report warnings must be an array")
    require(isinstance(slugs, dict), "import report slugs must be an object")
    require(all(isinstance(item, dict) for item in files), "import report files must contain objects")
    require(all(isinstance(item, dict) for item in rejections), "import report rejections must contain objects")
    require(all(isinstance(item, dict) for item in warnings), "import report warnings must contain objects")
    require(summary.get("discovered_files") == len(files) == len(manifest), "report discovered count mismatch")
    require([item.get("path") for item in files] == [item["filename"] for item in manifest], "report file list mismatch")
    require(summary.get("public_records") == len(archive["patches"]), "report public count mismatch")
    file_count_fields = (
        "accepted_occurrences", "rejected_occurrences", "corroborating_unmatched_occurrences",
    )
    for index, item in enumerate(files):
        for field in file_count_fields:
            require(is_integer(item.get(field)) and item[field] >= 0, f"report.files[{index}].{field} invalid")
    accepted = sum(item["accepted_occurrences"] for item in files)
    rejected = len(rejections)
    rejected_files = sum(item.get("status") == "rejected" for item in files)
    corroborating_unmatched = summary.get("corroborating_unmatched_occurrences", 0)
    require(is_integer(corroborating_unmatched) and corroborating_unmatched >= 0, "report corroborating count invalid")
    require(summary.get("accepted_occurrences") == accepted, "report accepted math mismatch")
    require(summary.get("rejected_files") == rejected_files, "report rejected file math mismatch")
    require(summary.get("rejected_occurrences") == rejected, "report rejected math mismatch")
    require(
        corroborating_unmatched == sum(item["corroborating_unmatched_occurrences"] for item in files),
        "report corroborating-only math mismatch",
    )
    require(
        summary.get("parsed_occurrences") == accepted + rejected + corroborating_unmatched,
        "report parsed math mismatch",
    )
    require(accepted == sum(item["occurrence_count"] for item in archive["patches"]), "report/archive occurrence mismatch")
    excerpt_count = sum(bool(occ.get("excerpt")) for patch in archive["patches"] for occ in patch["source_occurrences"])
    raw_excerpt_count = summary.get("feed_excerpt_occurrences")
    require(is_integer(raw_excerpt_count) and raw_excerpt_count >= 0, "report feed excerpt count invalid")
    published_excerpt_count = summary.get("published_feed_excerpt_occurrences", raw_excerpt_count)
    require(
        is_integer(published_excerpt_count) and published_excerpt_count == excerpt_count,
        "report published feed excerpt mismatch",
    )
    collisions = slugs.get("collisions")
    reassignments = slugs.get("reassignments")
    require(isinstance(collisions, list), "import report slug collisions must be an array")
    require(isinstance(reassignments, list), "import report slug reassignments must be an array")
    require(not collisions and not reassignments, "slug collision/reassignment requires review")
    require(rejected_files == 0, f"import report contains {rejected_files} rejected file(s)")
    require(rejected == 0, f"import report contains {rejected} real rejection(s)")
    result["report_rejections"] = rejected
    result["report_corroborating_unmatched"] = corroborating_unmatched
    result["report_warnings"] = len(warnings)
    if active_config_sha256 is not None:
        result["config_sha256"] = active_config_sha256
    return result


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source_directory", type=Path)
    parser.add_argument("data_directory", type=Path, nargs="?", default=Path("database/data"))
    parser.add_argument(
        "--config",
        type=Path,
        help="importer config override; defaults to PATCH_IMPORT_CONFIG or scripts/patch-import.config.json",
    )
    parser.add_argument("--expected-snapshot", type=Path, help="optional JSON subset of derived counts/coverage")
    parser.add_argument(
        "--allow-missing-report",
        action="store_true",
        help="allow a missing import report only when validating a legacy artifact set",
    )
    args = parser.parse_args()

    configured_path = args.config or (Path(os.environ["PATCH_IMPORT_CONFIG"]) if os.environ.get("PATCH_IMPORT_CONFIG") else DEFAULT_CONFIG_PATH)
    config, config_sha256 = load_importer_config(configured_path)

    archive_path = args.data_directory / "everquest-patch-history.json"
    raw_json = archive_path.read_text(encoding="utf-8")
    archive = json.loads(raw_json)
    patches, coverage = validate_archive(archive, raw_json)
    manifest = validate_manifest(args.source_directory, archive)
    validate_corpus_freshness(args.source_directory, args.data_directory, manifest, config)
    csv_columns = validate_csv(args.data_directory / "everquest-patch-history.csv", patches)
    sidecars = validate_sidecars(
        args.data_directory,
        archive,
        manifest,
        require_report=not args.allow_missing_report,
        active_config_sha256=config_sha256,
    )
    snapshot = {
        "record_count": len(patches),
        "occurrence_count": sum(patch["occurrence_count"] for patch in patches),
        "source_file_count": len(manifest),
        "source_bytes": coverage["source_bytes"],
        "first_patch": coverage["first_patch"],
        "last_patch": coverage["last_patch"],
        "years": coverage["years"],
    }
    if args.expected_snapshot:
        expected = json.loads(args.expected_snapshot.read_text(encoding="utf-8"))
        validate_expected(snapshot, expected)
    print(json.dumps({**snapshot, "csv_columns": csv_columns, **sidecars}, indent=2))


if __name__ == "__main__":
    main()
