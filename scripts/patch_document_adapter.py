"""Extract local patch-history documents into text without executing embedded content.

This helper intentionally performs no network requests and no OCR. JSON is written to
stdout so the Node importer can normalize every supported format through bounded,
format-aware record handling.
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
import zipfile
from datetime import datetime
from email.utils import parsedate_to_datetime
from html.parser import HTMLParser
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit
from xml.etree import ElementTree

try:
    from defusedxml import ElementTree as SafeElementTree
    from defusedxml.common import DefusedXmlException
except ImportError:  # Optional hardening; the bounded encoding-aware scan remains mandatory.
    SafeElementTree = ElementTree

class DefusedXmlException(Exception):
        """Compatibility placeholder when defusedxml is not installed."""


BLOCK_TAGS = {
    "address", "article", "aside", "blockquote", "br", "dd", "div", "dl", "dt",
    "figcaption", "figure", "footer", "h1", "h2", "h3", "h4", "h5", "h6",
    "header", "hr", "li", "main", "nav", "ol", "p", "pre", "section", "table",
    "tbody", "td", "tfoot", "th", "thead", "tr", "ul",
}
IGNORED_TAGS = {"script", "style", "noscript", "template", "svg"}
DEFAULT_MAX_XML_BYTES = 10 * 1024 * 1024
DEFAULT_MAX_PDF_PAGES = 1_000
DEFAULT_MAX_TEXT_CHARACTERS = 5_000_000
DEFAULT_MAX_DOCX_ENTRIES = 2_048
DEFAULT_MAX_DOCX_COMPRESSED_BYTES = 50 * 1024 * 1024
DEFAULT_MAX_DOCX_UNCOMPRESSED_BYTES = 200 * 1024 * 1024
DEFAULT_MAX_DOCX_ENTRY_COMPRESSED_BYTES = 25 * 1024 * 1024
DEFAULT_MAX_DOCX_ENTRY_UNCOMPRESSED_BYTES = 50 * 1024 * 1024
DEFAULT_MAX_DOCX_PARAGRAPHS = 100_000
DEFAULT_MAX_DOCX_COMPRESSION_RATIO = 200.0
STRUCTURED_TITLE_FIELDS = ("title", "display_date", "name")
STRUCTURED_CONTENT_FIELDS = ("content", "body", "notes", "description")
STRUCTURED_DATE_FIELDS = ("published", "patch_date", "date")
STRUCTURED_URL_FIELDS = ("source_url", "url", "link")
ARCHIVE_RECORD_FIELDS = {
    "id", "slug", "patch_date", "display_date", "title", "content",
    "content_hash", "source_occurrences",
}
ARCHIVE_CSV_FIELDS = ARCHIVE_RECORD_FIELDS | {"effective_date", "sequence"}
XML_SECURITY_BACKEND = (
    "defusedxml_with_encoding_aware_declaration_scan"
    if SafeElementTree is not ElementTree
    else "stdlib_with_encoding_aware_declaration_scan"
)
UNSAFE_XML_DECLARATION = re.compile(r"<!\s*(?:DOCTYPE|ENTITY)\b", re.IGNORECASE)


class ResourceLimitExceeded(Exception):
    def __init__(self, limit: str, maximum: int, observed: int, message: str) -> None:
        super().__init__(message)
        self.limit = limit
        self.maximum = maximum
        self.observed = observed


def configure_utf8_stdout(stream: Any | None = None) -> None:
    """Make JSON output deterministic even under a legacy Windows console code page."""

    target = stream if stream is not None else sys.stdout
    reconfigure = getattr(target, "reconfigure", None)
    if callable(reconfigure):
        reconfigure(encoding="utf-8", errors="strict")


def xml_scan_encoding(data: bytes) -> str:
    """Choose an XML-safe decoding from BOM/signature bytes before any parser runs."""

    if data.startswith(b"\x00\x00\xfe\xff") or data.startswith(b"\x00\x00\x00<"):
        return "utf-32-be"
    if data.startswith(b"\xff\xfe\x00\x00") or data.startswith(b"<\x00\x00\x00"):
        return "utf-32-le"
    if data.startswith(b"\xfe\xff") or data.startswith(b"\x00<"):
        return "utf-16-be"
    if data.startswith(b"\xff\xfe") or data.startswith(b"<\x00"):
        return "utf-16-le"
    return "utf-8-sig"


def reject_unsafe_xml_declarations(data: bytes) -> None:
    """Reject DTD/entity syntax before XML parsing, including UTF-16/UTF-32 input.

    defusedxml is used when available. The bundled runtime does not require it, so this
    encoding-aware full-document scan is also always applied before the stdlib fallback.
    """

    encoding = xml_scan_encoding(data)
    try:
        decoded = data.decode(encoding, errors="strict")
    except UnicodeError:
        decoded = data.decode(encoding, errors="replace")
    if UNSAFE_XML_DECLARATION.search(decoded):
        raise ValueError("XML containing DOCTYPE or ENTITY declarations is not accepted.")


def parse_safe_xml(data: bytes) -> ElementTree.Element:
    reject_unsafe_xml_declarations(data)
    return SafeElementTree.fromstring(data)


def limit_result(
    adapter: str,
    limit: str,
    maximum: int | float,
    observed: int | float,
    message: str,
    **details: object,
) -> dict[str, Any]:
    return {
        "status": "limit_exceeded",
        "adapter": adapter,
        "message": message,
        "limit": limit,
        "maximum": maximum,
        "observed": observed,
        "records": [],
        "text": "",
        **details,
    }


def normalize_text(value: str) -> str:
    value = (
        value.replace("\r\n", "\n")
        .replace("\r", "\n")
        .replace("\x00", "")
        .replace("\u200b", "")
        .replace("\ufeff", "")
    )
    value = re.sub(r"[ \t]+\n", "\n", value)
    value = re.sub(r"\n[ \t]+", "\n", value)
    value = re.sub(r"\n{3,}", "\n\n", value)
    return value.strip()


class SafeTextParser(HTMLParser):
    """Strip tags while ignoring active/non-content elements."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.parts: list[str] = []
        self.ignored_depth = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        if tag in IGNORED_TAGS:
            self.ignored_depth += 1
        elif not self.ignored_depth and re.fullmatch(r"h[1-6]", tag):
            self.parts.append(f"\n{'#' * int(tag[1])} ")
        elif not self.ignored_depth and tag in BLOCK_TAGS:
            self.parts.append("\n")

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if not self.ignored_depth and tag.lower() in BLOCK_TAGS:
            self.parts.append("\n")

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag in IGNORED_TAGS and self.ignored_depth:
            self.ignored_depth -= 1
        elif not self.ignored_depth and tag in BLOCK_TAGS:
            self.parts.append("\n")

    def handle_data(self, data: str) -> None:
        if not self.ignored_depth:
            self.parts.append(data)

    @property
    def text(self) -> str:
        return normalize_text("".join(self.parts))


class XenForoThreadParser(HTMLParser):
    """Extract only a saved XenForo thread's title, canonical URL, and first post."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.stack: list[dict[str, Any]] = []
        self.title_parts: list[str] = []
        self.body_parts: list[str] = []
        self.canonical: str | None = None
        self.published: str | None = None
        self.first_article_seen = False
        self.in_first_article = False
        self.in_title = False
        self.in_body = False
        self.ignored_depth = 0

    @staticmethod
    def _attributes(attrs: list[tuple[str, str | None]]) -> dict[str, str]:
        return {key.lower(): value or "" for key, value in attrs}

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        attributes = self._attributes(attrs)
        classes = set(attributes.get("class", "").split())
        marker: dict[str, Any] = {"tag": tag}

        if tag == "link" and "canonical" in attributes.get("rel", "").lower().split():
            self.canonical = attributes.get("href") or self.canonical
        if tag == "h1" and not self.title_parts:
            self.in_title = True
            marker["title"] = True
        if tag == "article" and "message" in classes and not self.first_article_seen:
            self.first_article_seen = True
            self.in_first_article = True
            marker["article"] = True
        if self.in_first_article and tag == "time" and not self.published and attributes.get("datetime"):
            self.published = attributes["datetime"]
        ancestor_classes = {name for item in self.stack for name in item.get("classes", set())}
        if self.in_first_article and "bbWrapper" in classes and "message-body" in ancestor_classes and not self.body_parts:
            self.in_body = True
            marker["body"] = True
        if tag in IGNORED_TAGS:
            self.ignored_depth += 1
            marker["ignored"] = True
        if self.in_body and not self.ignored_depth and re.fullmatch(r"h[1-6]", tag):
            self.body_parts.append(f"\n{'#' * int(tag[1])} ")
        elif self.in_body and not self.ignored_depth and tag in BLOCK_TAGS:
            self.body_parts.append("\n")
        marker["classes"] = classes
        self.stack.append(marker)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        while self.stack:
            marker = self.stack.pop()
            if marker.get("ignored") and self.ignored_depth:
                self.ignored_depth -= 1
            if marker.get("body"):
                self.in_body = False
            if marker.get("article"):
                self.in_first_article = False
            if marker.get("title"):
                self.in_title = False
            if self.in_body and not self.ignored_depth and marker["tag"] in BLOCK_TAGS:
                self.body_parts.append("\n")
            if marker["tag"] == tag:
                break

    def handle_data(self, data: str) -> None:
        if self.ignored_depth:
            return
        if self.in_title:
            self.title_parts.append(data)
        if self.in_body:
            self.body_parts.append(data)

    def record(self) -> dict[str, Any] | None:
        title = normalize_text("".join(self.title_parts))
        content = normalize_text("".join(self.body_parts))
        if not title or not content:
            return None
        return {
            "title": title,
            "published": self.published,
            "source_url": self.canonical,
            "content": content,
            "excerpt": False,
            "source_kind": "xenforo_first_post",
        }


def strip_html(value: str) -> str:
    parser = SafeTextParser()
    parser.feed(value)
    parser.close()
    return parser.text


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].lower()


def child_text(element: ElementTree.Element, names: set[str]) -> str:
    for child in element:
        if local_name(child.tag) in names:
            return "".join(child.itertext()).strip()
    return ""


def published_iso(value: str) -> str | None:
    if not value:
        return None
    try:
        parsed = parsedate_to_datetime(value)
    except (TypeError, ValueError, OverflowError):
        try:
            parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            return value
    return parsed.isoformat()


def invalid_structured_result(
    adapter_name: str,
    reason_code: str,
    message: str,
    *,
    raw_item_count: int | None = None,
) -> dict[str, Any]:
    return {
        "status": "invalid_document",
        "adapter": adapter_name,
        "reason_code": reason_code,
        "message": message,
        "raw_item_count": raw_item_count,
        "processed_item_count": 0,
        "accepted_item_count": 0,
        "rejected_item_count": 0,
        "truncated_item_count": 0,
        "rejections": [],
        "warnings": [],
        "records": [],
        "text": "",
    }


def reject_json_constant(value: str) -> None:
    raise ValueError(f"Non-finite JSON number {value!r} is not accepted.")


def reject_duplicate_json_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"Duplicate JSON object key {key!r} is not accepted.")
        result[key] = value
    return result


def normalized_structured_fields(item: dict[str, Any], reasons: list[str]) -> dict[str, Any]:
    fields: dict[str, Any] = {}
    for raw_key, value in item.items():
        if not isinstance(raw_key, str) or not raw_key.strip():
            reasons.append("invalid_field_name")
            continue
        key = raw_key.strip().casefold()
        if key in fields:
            reasons.append("duplicate_normalized_field")
            continue
        fields[key] = value
    return fields


def select_structured_text(
    fields: dict[str, Any],
    aliases: tuple[str, ...],
    logical_name: str,
    reasons: list[str],
    *,
    required: bool,
) -> str | None:
    supplied: list[tuple[str, str]] = []
    invalid_type = False
    for alias in aliases:
        value = fields.get(alias)
        if value is None or (isinstance(value, str) and not value.strip()):
            continue
        if not isinstance(value, str):
            invalid_type = True
            continue
        supplied.append((alias, value.strip()))
    if invalid_type:
        reasons.append(f"invalid_{logical_name}_type")
    if not supplied:
        if required:
            reasons.append(f"missing_{logical_name}")
        return None
    normalized_values = {normalize_text(value) for _, value in supplied}
    if len(normalized_values) > 1:
        reasons.append(f"conflicting_{logical_name}_fields")
        return None
    return supplied[0][1]


def select_structured_title(
    fields: dict[str, Any],
    reasons: list[str],
    *,
    archive_shaped: bool,
) -> str | None:
    """Use the archival display label without weakening generic alias checks.

    Our public exports intentionally contain both ``display_date`` (the original
    heading) and ``title`` (a shorter presentation title), so those values may
    legitimately differ. Generic structured inputs remain conflict-rejected.
    """

    display_date = fields.get("display_date")
    if archive_shaped and display_date is not None:
        if not isinstance(display_date, str):
            reasons.append("invalid_title_type")
            return None
        if display_date.strip():
            return display_date.strip()
    return select_structured_text(fields, STRUCTURED_TITLE_FIELDS, "title", reasons, required=True)


def parse_structured_excerpt(value: Any, reasons: list[str]) -> bool:
    if value is None or value == "":
        return False
    if isinstance(value, bool):
        return value
    if isinstance(value, str):
        normalized = value.strip().casefold()
        if normalized in {"true", "yes", "1"}:
            return True
        if normalized in {"false", "no", "0", ""}:
            return False
    reasons.append("invalid_excerpt")
    return False


def safe_structured_url(value: str | None) -> bool:
    if not value:
        return True
    try:
        parsed = urlsplit(value)
        port = parsed.port
    except ValueError:
        return False
    return (
        parsed.scheme.casefold() in {"http", "https"}
        and bool(parsed.hostname)
        and parsed.username is None
        and parsed.password is None
        and port in {None, 80, 443}
    )


def normalize_structured_item(
    item: Any,
    *,
    adapter_name: str,
    item_index: int,
    row_index: int | None = None,
    archive_shaped: bool = False,
) -> tuple[dict[str, Any] | None, dict[str, Any] | None, list[dict[str, Any]]]:
    index_details = {"item_index": item_index}
    if row_index is not None:
        index_details["row_index"] = row_index
    if not isinstance(item, dict):
        return None, {
            **index_details,
            "code": "structured_record_rejected",
            "reasons": ["record_not_object"],
        }, []
    if item.get("__column_count_mismatch__") is True:
        return None, {
            **index_details,
            "code": "structured_record_rejected",
            "reasons": ["column_count_mismatch"],
        }, []

    reasons: list[str] = []
    fields = normalized_structured_fields(item, reasons)
    title = select_structured_title(
        fields,
        reasons,
        archive_shaped=archive_shaped or ARCHIVE_RECORD_FIELDS.issubset(fields),
    )
    content = select_structured_text(fields, STRUCTURED_CONTENT_FIELDS, "content", reasons, required=True)
    published = select_structured_text(fields, STRUCTURED_DATE_FIELDS, "date", reasons, required=False)
    source_url = select_structured_text(fields, STRUCTURED_URL_FIELDS, "source_url", reasons, required=False)
    excerpt = parse_structured_excerpt(fields.get("excerpt"), reasons)

    title = re.sub(r"\s+", " ", title or "").strip()
    content = normalize_text(content or "")
    if content and len(content) < 12:
        reasons.append("content_too_short")
    if reasons:
        return None, {
            **index_details,
            "code": "structured_record_rejected",
            "reasons": sorted(set(reasons)),
        }, []

    warnings: list[dict[str, Any]] = []
    if source_url and not safe_structured_url(source_url):
        warnings.append({
            **index_details,
            "code": "unsafe_source_url",
            "message": "Structured record URL was omitted because it is not safe HTTP(S) provenance.",
        })
        source_url = None
    record = {
        **index_details,
        "title": title,
        "content": content,
        "published": published or None,
        "source_url": source_url,
        "excerpt": excerpt,
        "source_kind": f"{adapter_name}_record",
    }
    return record, None, warnings


def structured_result(
    adapter_name: str,
    items: list[tuple[int, int | None, Any]],
    raw_item_count: int,
    max_items: int,
    max_text_characters: int,
    *,
    archive_shaped: bool = False,
) -> dict[str, Any]:
    records: list[dict[str, Any]] = []
    rejections: list[dict[str, Any]] = []
    warnings: list[dict[str, Any]] = []
    text_character_count = 0
    processed = items[:max_items]
    for item_index, row_index, item in processed:
        record, rejection, item_warnings = normalize_structured_item(
            item,
            adapter_name=adapter_name,
            item_index=item_index,
            row_index=row_index,
            archive_shaped=archive_shaped,
        )
        warnings.extend(item_warnings)
        if rejection:
            rejections.append(rejection)
            continue
        assert record is not None
        next_character_count = text_character_count + len(record["title"]) + len(record["content"])
        if next_character_count > max_text_characters:
            return limit_result(
                adapter_name,
                "max_text_characters",
                max_text_characters,
                next_character_count,
                "Structured record text exceeds the configured character limit.",
                raw_item_count=raw_item_count,
                processed_item_count=len(processed),
                accepted_item_count=0,
                rejected_item_count=len(processed),
                truncated_item_count=max(0, raw_item_count - len(processed)),
                rejections=[],
                warnings=[],
                text_character_count=0,
            )
        text_character_count = next_character_count
        records.append(record)
    return {
        "status": "structured_records",
        "adapter": adapter_name,
        "raw_item_count": raw_item_count,
        "processed_item_count": len(processed),
        "accepted_item_count": len(records),
        "rejected_item_count": len(rejections),
        "truncated_item_count": max(0, raw_item_count - len(processed)),
        "text_character_count": text_character_count,
        "rejections": rejections,
        "warnings": warnings,
        "records": records,
        "text": "",
    }


def extract_json_records(
    source: Path,
    max_items: int,
    max_text_characters: int = DEFAULT_MAX_TEXT_CHARACTERS,
) -> dict[str, Any]:
    try:
        document = json.loads(
            source.read_text(encoding="utf-8-sig", errors="strict"),
            object_pairs_hook=reject_duplicate_json_keys,
            parse_constant=reject_json_constant,
        )
    except (json.JSONDecodeError, UnicodeError, ValueError, RecursionError) as error:
        return invalid_structured_result("json", "malformed_json", f"JSON could not be safely decoded: {error}")

    if isinstance(document, list):
        raw_items = document
        archive_shaped = False
    elif isinstance(document, dict):
        collection_fields = [key for key in ("patches", "records") if key in document]
        if len(collection_fields) != 1:
            return invalid_structured_result(
                "json",
                "ambiguous_or_missing_record_array",
                "Top-level JSON object must contain exactly one patches or records array.",
            )
        raw_items = document[collection_fields[0]]
        if not isinstance(raw_items, list):
            return invalid_structured_result(
                "json",
                "record_collection_not_array",
                f"Top-level {collection_fields[0]} value must be an array.",
            )
        archive_shaped = (
            collection_fields[0] == "patches"
            and document.get("schema_version") == 1
            and isinstance(document.get("record_count"), int)
        )
    else:
        return invalid_structured_result(
            "json",
            "invalid_top_level_type",
            "Top-level JSON must be an array or an object containing a patches or records array.",
        )
    items = [(index, None, item) for index, item in enumerate(raw_items, start=1)]
    return structured_result(
        "json",
        items,
        len(raw_items),
        max_items,
        max_text_characters,
        archive_shaped=archive_shaped,
    )


def extract_delimited_records(
    source: Path,
    adapter_name: str,
    delimiter: str,
    max_items: int,
    max_text_characters: int = DEFAULT_MAX_TEXT_CHARACTERS,
) -> dict[str, Any]:
    # Python's default is commonly only 128 KiB, which rejects valid historical
    # patch bodies before our configured aggregate text bound can evaluate them.
    # Keep the parser limit explicitly tied to that configured safety ceiling.
    csv.field_size_limit(min(max_text_characters, sys.maxsize))
    try:
        with source.open("r", encoding="utf-8-sig", errors="strict", newline="") as handle:
            reader = csv.reader(handle, delimiter=delimiter, strict=True)
            try:
                headers = next(reader)
            except StopIteration:
                return invalid_structured_result(adapter_name, "missing_header", "Delimited source has no header row.")
            normalized_headers = [header.strip().casefold() for header in headers]
            if not headers or any(not header for header in normalized_headers):
                return invalid_structured_result(adapter_name, "invalid_header", "Header names must be non-empty.")
            if len(normalized_headers) != len(set(normalized_headers)):
                return invalid_structured_result(adapter_name, "duplicate_header", "Header names must be unique ignoring case and whitespace.")
            if not set(normalized_headers).intersection(STRUCTURED_TITLE_FIELDS):
                return invalid_structured_result(adapter_name, "missing_title_header", "A title, display_date, or name header is required.")
            if not set(normalized_headers).intersection(STRUCTURED_CONTENT_FIELDS):
                return invalid_structured_result(adapter_name, "missing_content_header", "A content, body, notes, or description header is required.")
            archive_shaped = ARCHIVE_CSV_FIELDS.issubset(normalized_headers)

            items: list[tuple[int, int | None, Any]] = []
            for row in reader:
                row_index = reader.line_num
                if not row or all(not value.strip() for value in row):
                    continue
                if len(row) != len(headers):
                    items.append((row_index, row_index, {
                        "title": None,
                        "content": None,
                        "__column_count_mismatch__": True,
                    }))
                    continue
                items.append((row_index, row_index, dict(zip(normalized_headers, row, strict=True))))
    except (csv.Error, UnicodeError, OSError) as error:
        return invalid_structured_result(adapter_name, "malformed_delimited_text", f"Delimited source could not be safely decoded: {error}")

    return structured_result(
        adapter_name,
        items,
        len(items),
        max_items,
        max_text_characters,
        archive_shaped=archive_shaped,
    )


def extract_feed(
    source: Path,
    max_items: int,
    max_xml_bytes: int = DEFAULT_MAX_XML_BYTES,
) -> dict[str, Any]:
    source_bytes = source.read_bytes()
    if len(source_bytes) > max_xml_bytes:
        return limit_result(
            "rss",
            "max_xml_bytes",
            max_xml_bytes,
            len(source_bytes),
            "Feed XML exceeds the configured byte limit.",
            raw_item_count=None,
            processed_item_count=0,
            accepted_item_count=0,
            rejected_item_count=0,
            truncated_item_count=0,
            rejections=[],
        )
    try:
        root = parse_safe_xml(source_bytes)
    except ValueError:
        return {
            "status": "unsafe_xml_rejected",
            "adapter": "rss",
            "message": "XML containing DOCTYPE or ENTITY declarations is not accepted.",
            "xml_security_backend": XML_SECURITY_BACKEND,
            "raw_item_count": None,
            "processed_item_count": 0,
            "accepted_item_count": 0,
            "rejected_item_count": 0,
            "truncated_item_count": 0,
            "rejections": [],
            "records": [],
        }
    root_name = local_name(root.tag)
    if root_name not in {"rss", "feed", "rdf"}:
        return {
            "status": "invalid_feed",
            "adapter": "rss",
            "message": "XML does not have an RSS, Atom, or RDF feed root.",
            "xml_security_backend": XML_SECURITY_BACKEND,
            "raw_item_count": 0,
            "processed_item_count": 0,
            "accepted_item_count": 0,
            "rejected_item_count": 0,
            "truncated_item_count": 0,
            "rejections": [],
            "records": [],
        }
    entries = [element for element in root.iter() if local_name(element.tag) in {"item", "entry"}]
    records: list[dict[str, Any]] = []
    rejections: list[dict[str, Any]] = []
    for item_index, entry in enumerate(entries[:max_items], start=1):
        title = child_text(entry, {"title"})
        published = child_text(entry, {"pubdate", "published", "updated"})
        description = child_text(entry, {"encoded", "content", "description", "summary"})
        link = child_text(entry, {"link"})
        if not link:
            for child in entry:
                if local_name(child.tag) == "link" and child.attrib.get("href"):
                    link = child.attrib["href"]
                    break
        plain = strip_html(description)
        excerpt = bool(re.search(r"\bread\s+more\b", plain, re.IGNORECASE))
        reasons: list[str] = []
        if not title:
            reasons.append("missing_title")
        if not description:
            reasons.append("missing_content_element")
        elif not plain:
            reasons.append("empty_content_after_sanitization")
        if reasons:
            rejections.append({"item_index": item_index, "reasons": reasons})
            continue
        records.append({
            "item_index": item_index,
            "title": title,
            "published": published_iso(published),
            "source_url": link or None,
            "content": plain,
            "excerpt": excerpt,
            "source_kind": "feed_excerpt" if excerpt else "feed_item",
        })
    raw_item_count = len(entries)
    processed_item_count = min(raw_item_count, max_items)
    return {
        "status": "feed",
        "adapter": "rss",
        "xml_security_backend": XML_SECURITY_BACKEND,
        "raw_item_count": raw_item_count,
        "processed_item_count": processed_item_count,
        "accepted_item_count": len(records),
        "rejected_item_count": len(rejections),
        "truncated_item_count": max(0, raw_item_count - processed_item_count),
        "rejections": rejections,
        "item_count": len(records),
        "feed_excerpt_count": sum(bool(record["excerpt"]) for record in records),
        "records": records,
        "text": "",
    }


def extract_html(
    source: Path,
    max_text_characters: int = DEFAULT_MAX_TEXT_CHARACTERS,
) -> dict[str, Any]:
    raw = source.read_text(encoding="utf-8", errors="replace")
    thread = XenForoThreadParser()
    thread.feed(raw)
    thread.close()
    record = thread.record()
    if record:
        if len(record["content"]) > max_text_characters:
            return limit_result(
                "html",
                "max_text_characters",
                max_text_characters,
                len(record["content"]),
                "Saved thread text exceeds the configured character limit.",
            )
        return {"status": "xenforo_thread", "adapter": "html", "records": [record], "text": ""}
    text = strip_html(raw)
    if len(text) > max_text_characters:
        return limit_result(
            "html",
            "max_text_characters",
            max_text_characters,
            len(text),
            "Extracted HTML text exceeds the configured character limit.",
        )
    return {"status": "text", "adapter": "html", "records": [], "text": text}


def extract_pdf(
    source: Path,
    minimum_text_characters: int,
    max_pages: int = DEFAULT_MAX_PDF_PAGES,
    max_text_characters: int = DEFAULT_MAX_TEXT_CHARACTERS,
) -> dict[str, Any]:
    try:
        import pdfplumber
    except ImportError:
        return {
            "status": "dependency_missing",
            "adapter": "pdf",
            "message": "pdfplumber is required for text-layer PDF extraction.",
            "records": [],
        }
    pages: list[dict[str, Any]] = []
    with pdfplumber.open(source) as document:
        page_count = len(document.pages)
        if page_count > max_pages:
            return limit_result(
                "pdf",
                "max_pdf_pages",
                max_pages,
                page_count,
                "PDF page count exceeds the configured limit.",
                page_count=page_count,
                processed_page_count=0,
                pages=[],
                text_character_count=0,
            )
        running_text_characters = 0
        for page_number, page in enumerate(document.pages, start=1):
            text = normalize_text(page.extract_text(x_tolerance=2, y_tolerance=3) or "")
            projected = running_text_characters + len(text) + (2 if pages and text else 0)
            if projected > max_text_characters:
                return limit_result(
                    "pdf",
                    "max_text_characters",
                    max_text_characters,
                    projected,
                    "Extracted PDF text exceeds the configured character limit.",
                    page_count=page_count,
                    processed_page_count=page_number,
                    pages=[],
                    text_character_count=projected,
                )
            running_text_characters = projected
            pages.append({"page": page_number, "text": text, "character_count": len(text)})
    text = normalize_text("\n\n".join(page["text"] for page in pages if page["text"]))
    if len(text) < minimum_text_characters:
        return {
            "status": "ocr_required",
            "adapter": "pdf",
            "message": "PDF has no usable text layer. OCR is intentionally not performed automatically.",
            "page_count": len(pages),
            "text_character_count": len(text),
            "pages": pages,
            "records": [],
            "text": text,
        }
    return {
        "status": "text",
        "adapter": "pdf",
        "page_count": len(pages),
        "text_character_count": len(text),
        "pages": pages,
        "records": [],
        "text": text,
    }


def extract_docx(
    source: Path,
    minimum_text_characters: int,
    max_entries: int = DEFAULT_MAX_DOCX_ENTRIES,
    max_compressed_bytes: int = DEFAULT_MAX_DOCX_COMPRESSED_BYTES,
    max_uncompressed_bytes: int = DEFAULT_MAX_DOCX_UNCOMPRESSED_BYTES,
    max_entry_compressed_bytes: int = DEFAULT_MAX_DOCX_ENTRY_COMPRESSED_BYTES,
    max_entry_uncompressed_bytes: int = DEFAULT_MAX_DOCX_ENTRY_UNCOMPRESSED_BYTES,
    max_paragraphs: int = DEFAULT_MAX_DOCX_PARAGRAPHS,
    max_text_characters: int = DEFAULT_MAX_TEXT_CHARACTERS,
    max_compression_ratio: float = DEFAULT_MAX_DOCX_COMPRESSION_RATIO,
) -> dict[str, Any]:
    try:
        from docx import Document
    except ImportError:
        return {
            "status": "dependency_missing",
            "adapter": "docx",
            "message": "python-docx is required for DOCX text extraction.",
            "records": [],
        }

    source_bytes = source.stat().st_size
    if source_bytes > max_compressed_bytes:
        return limit_result(
            "docx",
            "max_docx_compressed_bytes",
            max_compressed_bytes,
            source_bytes,
            "DOCX compressed size exceeds the configured limit.",
        )

    try:
        with zipfile.ZipFile(source) as archive:
            entries = archive.infolist()
            if len(entries) > max_entries:
                return limit_result(
                    "docx",
                    "max_docx_entries",
                    max_entries,
                    len(entries),
                    "DOCX ZIP entry count exceeds the configured limit.",
                )
            if len({entry.filename for entry in entries}) != len(entries):
                return {
                    "status": "invalid_document",
                    "adapter": "docx",
                    "message": "DOCX contains duplicate ZIP entry names.",
                    "records": [],
                    "text": "",
                }

            total_compressed = sum(entry.compress_size for entry in entries)
            if total_compressed > max_compressed_bytes:
                return limit_result(
                    "docx",
                    "max_docx_compressed_bytes",
                    max_compressed_bytes,
                    total_compressed,
                    "DOCX aggregate compressed entry size exceeds the configured limit.",
                )
            total_uncompressed = sum(entry.file_size for entry in entries)
            if total_uncompressed > max_uncompressed_bytes:
                return limit_result(
                    "docx",
                    "max_docx_uncompressed_bytes",
                    max_uncompressed_bytes,
                    total_uncompressed,
                    "DOCX aggregate uncompressed entry size exceeds the configured limit.",
                )

            for entry in entries:
                if entry.flag_bits & 0x1:
                    return {
                        "status": "invalid_document",
                        "adapter": "docx",
                        "message": "Encrypted DOCX ZIP entries are not supported.",
                        "entry": entry.filename,
                        "records": [],
                        "text": "",
                    }
                if entry.compress_size > max_entry_compressed_bytes:
                    return limit_result(
                        "docx",
                        "max_docx_entry_compressed_bytes",
                        max_entry_compressed_bytes,
                        entry.compress_size,
                        "A DOCX ZIP entry exceeds the configured compressed-size limit.",
                        entry=entry.filename,
                    )
                if entry.file_size > max_entry_uncompressed_bytes:
                    return limit_result(
                        "docx",
                        "max_docx_entry_uncompressed_bytes",
                        max_entry_uncompressed_bytes,
                        entry.file_size,
                        "A DOCX ZIP entry exceeds the configured uncompressed-size limit.",
                        entry=entry.filename,
                    )
                compression_ratio = entry.file_size / max(1, entry.compress_size)
                if compression_ratio > max_compression_ratio:
                    return limit_result(
                        "docx",
                        "max_compression_ratio",
                        max_compression_ratio,
                        round(compression_ratio, 2),
                        "A DOCX ZIP entry exceeds the configured compression-ratio limit.",
                        entry=entry.filename,
                    )
                normalized_name = entry.filename.replace("\\", "/")
                if normalized_name.startswith("/") or ".." in normalized_name.split("/"):
                    return {
                        "status": "invalid_document",
                        "adapter": "docx",
                        "message": "DOCX contains an unsafe ZIP entry path.",
                        "entry": entry.filename,
                        "records": [],
                        "text": "",
                    }
                if normalized_name.lower().endswith((".xml", ".rels")):
                    xml_part = archive.read(entry)
                    try:
                        reject_unsafe_xml_declarations(xml_part)
                    except ValueError:
                        return {
                            "status": "unsafe_xml_rejected",
                            "adapter": "docx",
                            "message": "DOCX XML containing DOCTYPE or ENTITY declarations is not accepted.",
                            "entry": entry.filename,
                            "xml_security_backend": XML_SECURITY_BACKEND,
                            "records": [],
                            "text": "",
                        }
    except (zipfile.BadZipFile, zipfile.LargeZipFile) as error:
        return {
            "status": "invalid_document",
            "adapter": "docx",
            "message": f"DOCX ZIP container is invalid: {type(error).__name__}.",
            "records": [],
            "text": "",
        }

    document = Document(source)
    parts: list[str] = []
    paragraph_count = 0
    raw_text_character_count = 0

    def count_paragraphs(count: int) -> None:
        nonlocal paragraph_count
        paragraph_count += count
        if paragraph_count > max_paragraphs:
            raise ResourceLimitExceeded(
                "max_docx_paragraphs",
                max_paragraphs,
                paragraph_count,
                "DOCX paragraph count exceeds the configured limit.",
            )

    def append_text(value: str) -> None:
        nonlocal raw_text_character_count
        if not value.strip():
            return
        projected = raw_text_character_count + len(value) + (1 if parts else 0)
        if projected > max_text_characters:
            raise ResourceLimitExceeded(
                "max_text_characters",
                max_text_characters,
                projected,
                "Extracted DOCX text exceeds the configured character limit.",
            )
        raw_text_character_count = projected
        parts.append(value)

    def paragraph_markdown(paragraph: Any) -> str:
        value = paragraph.text
        style_name = str(getattr(getattr(paragraph, "style", None), "name", ""))
        heading = re.fullmatch(r"Heading\s+([1-6])", style_name, re.IGNORECASE)
        if heading and value.strip():
            return f"{'#' * int(heading.group(1))} {value}"
        if style_name.lower() == "title" and value.strip():
            return f"# {value}"
        return value

    try:
        for paragraph in document.paragraphs:
            count_paragraphs(1)
            append_text(paragraph_markdown(paragraph))
        for table in document.tables:
            for row in table.rows:
                cells: list[str] = []
                for cell in row.cells:
                    count_paragraphs(max(1, len(cell.paragraphs)))
                    cells.append(cell.text)
                append_text("\t".join(cells))
    except ResourceLimitExceeded as error:
        return limit_result(
            "docx",
            error.limit,
            error.maximum,
            error.observed,
            str(error),
            paragraph_count=paragraph_count,
            text_character_count=raw_text_character_count,
        )

    text = normalize_text("\n".join(parts))
    status = "text" if len(text) >= minimum_text_characters else "empty_document"
    return {
        "status": status,
        "adapter": "docx",
        "message": None if status == "text" else "DOCX contains no usable text.",
        "records": [],
        "text": text,
        "text_character_count": len(text),
        "paragraph_count": paragraph_count,
        "zip_entry_count": len(entries),
        "compressed_bytes": source_bytes,
        "uncompressed_bytes": total_uncompressed,
        "xml_security_backend": XML_SECURITY_BACKEND,
    }


def positive_integer(value: str) -> int:
    parsed = int(value)
    if parsed < 1:
        raise argparse.ArgumentTypeError("must be at least one")
    return parsed


def nonnegative_integer(value: str) -> int:
    parsed = int(value)
    if parsed < 0:
        raise argparse.ArgumentTypeError("must be zero or greater")
    return parsed


def positive_float(value: str) -> float:
    parsed = float(value)
    if parsed <= 0:
        raise argparse.ArgumentTypeError("must be greater than zero")
    return parsed


def main() -> None:
    configure_utf8_stdout()
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path)
    parser.add_argument("--adapter", choices=("pdf", "docx", "html", "rss", "json", "csv", "tsv"), required=True)
    parser.add_argument("--minimum-text-characters", type=nonnegative_integer, default=12)
    parser.add_argument("--max-items", type=positive_integer, default=500)
    parser.add_argument("--max-xml-bytes", type=positive_integer, default=DEFAULT_MAX_XML_BYTES)
    parser.add_argument("--max-pages", type=positive_integer, default=DEFAULT_MAX_PDF_PAGES)
    parser.add_argument("--max-text-characters", type=positive_integer, default=DEFAULT_MAX_TEXT_CHARACTERS)
    parser.add_argument("--max-archive-entries", type=positive_integer, default=DEFAULT_MAX_DOCX_ENTRIES)
    parser.add_argument("--max-docx-compressed-bytes", type=positive_integer, default=DEFAULT_MAX_DOCX_COMPRESSED_BYTES)
    parser.add_argument("--max-uncompressed-bytes", type=positive_integer, default=DEFAULT_MAX_DOCX_UNCOMPRESSED_BYTES)
    parser.add_argument("--max-docx-entry-compressed-bytes", type=positive_integer, default=DEFAULT_MAX_DOCX_ENTRY_COMPRESSED_BYTES)
    parser.add_argument("--max-docx-entry-uncompressed-bytes", type=positive_integer, default=DEFAULT_MAX_DOCX_ENTRY_UNCOMPRESSED_BYTES)
    parser.add_argument("--max-docx-paragraphs", type=positive_integer, default=DEFAULT_MAX_DOCX_PARAGRAPHS)
    parser.add_argument("--max-compression-ratio", type=positive_float, default=DEFAULT_MAX_DOCX_COMPRESSION_RATIO)
    args = parser.parse_args()

    try:
        if args.adapter == "pdf":
            result = extract_pdf(
                args.source,
                args.minimum_text_characters,
                args.max_pages,
                args.max_text_characters,
            )
        elif args.adapter == "docx":
            result = extract_docx(
                args.source,
                args.minimum_text_characters,
                args.max_archive_entries,
                args.max_docx_compressed_bytes,
                args.max_uncompressed_bytes,
                args.max_docx_entry_compressed_bytes,
                args.max_docx_entry_uncompressed_bytes,
                args.max_docx_paragraphs,
                args.max_text_characters,
                args.max_compression_ratio,
            )
        elif args.adapter == "html":
            result = extract_html(args.source, args.max_text_characters)
        elif args.adapter == "rss":
            result = extract_feed(args.source, args.max_items, args.max_xml_bytes)
        elif args.adapter == "json":
            result = extract_json_records(args.source, args.max_items, args.max_text_characters)
        else:
            result = extract_delimited_records(
                args.source,
                args.adapter,
                "," if args.adapter == "csv" else "\t",
                args.max_items,
                args.max_text_characters,
            )
    except (ElementTree.ParseError, DefusedXmlException, OSError, UnicodeError, ValueError) as error:
        result = {
            "status": "adapter_error",
            "adapter": args.adapter,
            "message": f"{type(error).__name__}: {error}",
            "records": [],
        }
    json.dump(result, sys.stdout, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    sys.stdout.write("\n")


if __name__ == "__main__":
    main()
