"""Politely synchronize the one allowlisted official EverQuest patch-note RSS feed.

This helper is intentionally not a forum crawler. The official XenForo robots policy
disallows crawling, so historical backfill must come from locally saved thread HTML.
Only the recent-item RSS document is requested; links inside it are never followed.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, BinaryIO, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlsplit
from urllib.request import (
    HTTPRedirectHandler,
    OpenerDirector,
    Request,
    build_opener,
)
from xml.etree import ElementTree

try:
    from defusedxml import ElementTree as SafeElementTree
    from defusedxml.common import DefusedXmlException
except ImportError:  # Optional; the encoding-aware declaration scan remains mandatory.
    SafeElementTree = ElementTree

    class DefusedXmlException(Exception):
        """Compatibility placeholder when defusedxml is not installed."""


OFFICIAL_FEED = "https://forums.everquest.com/index.php?forums/game-update-notes-live.9/index.rss"
OFFICIAL_HOST = "forums.everquest.com"
DEFAULT_USER_AGENT = (
    "Modern-Allaclone Patch Archive RSS Sync/1.0 "
    "(+https://github.com/chadw/modern-allaclone; one official feed; no forum crawler)"
)
DEFAULT_TIMEOUT_SECONDS = 15.0
DEFAULT_MAX_BYTES = 5 * 1024 * 1024
DEFAULT_MAX_REDIRECTS = 3
MAX_CACHE_BYTES = 64 * 1024
CACHE_SCHEMA_VERSION = 1
REDIRECT_CODES = {301, 302, 303, 307, 308}
XML_SECURITY_BACKEND = (
    "defusedxml_with_encoding_aware_declaration_scan"
    if SafeElementTree is not ElementTree
    else "stdlib_with_encoding_aware_declaration_scan"
)
UNSAFE_XML_DECLARATION = re.compile(r"<!\s*(?:DOCTYPE|ENTITY)\b", re.IGNORECASE)


class FeedSyncError(RuntimeError):
    """An expected, safely reportable synchronization failure."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code


def configure_utf8_stdout(stream: Any | None = None) -> None:
    """Make JSON output deterministic even under a legacy Windows console code page."""

    target = stream if stream is not None else sys.stdout
    reconfigure = getattr(target, "reconfigure", None)
    if callable(reconfigure):
        reconfigure(encoding="utf-8", errors="strict")


def xml_scan_encoding(data: bytes) -> str:
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
    """Reject DTD/entity syntax in UTF-8/16/32 before the XML parser runs."""

    encoding = xml_scan_encoding(data)
    try:
        decoded = data.decode(encoding, errors="strict")
    except UnicodeError:
        decoded = data.decode(encoding, errors="replace")
    if UNSAFE_XML_DECLARATION.search(decoded):
        raise FeedSyncError(
            "unsafe_xml",
            "XML containing DOCTYPE or ENTITY declarations is not accepted.",
        )


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def safe_header_value(value: object, maximum_length: int = 1024) -> str | None:
    if not isinstance(value, str) or not value or len(value) > maximum_length:
        return None
    if re.search(r"[\r\n\x00]", value):
        return None
    return value


def validate_feed_url(url: str, *, require_official: bool = False) -> str:
    """Validate both the starting URL and every proposed redirect before use."""

    try:
        parsed = urlsplit(url)
        port = parsed.port
    except ValueError as error:
        raise FeedSyncError("invalid_url", "The feed URL is invalid.") from error

    if parsed.scheme.lower() != "https":
        raise FeedSyncError(
            "https_required",
            "The official patch feed and every redirect must use HTTPS.",
        )
    if (parsed.hostname or "").lower() != OFFICIAL_HOST or port not in (None, 443):
        raise FeedSyncError(
            "host_not_allowed",
            f"The feed host is not allowlisted: {parsed.hostname or '(missing)'!r}.",
        )
    if parsed.username or parsed.password:
        raise FeedSyncError("credentials_not_allowed", "Credentials are not allowed in the feed URL.")
    if parsed.fragment:
        raise FeedSyncError("fragment_not_allowed", "Fragments are not allowed in the feed URL.")
    endpoint = parsed.path + (f"?{parsed.query}" if parsed.query else "")
    if not endpoint.lower().endswith(".rss"):
        raise FeedSyncError(
            "rss_endpoint_required",
            "Redirects are accepted only when they remain on an explicit .rss endpoint.",
        )
    if require_official and url != OFFICIAL_FEED:
        raise FeedSyncError(
            "official_feed_required",
            "Synchronization may start only from the official EverQuest Game Update Notes RSS URL.",
        )
    return url


class AllowlistedRedirectHandler(HTTPRedirectHandler):
    """Reject an unsafe Location before urllib can make the redirected request."""

    def __init__(self, maximum_redirects: int = DEFAULT_MAX_REDIRECTS) -> None:
        super().__init__()
        self.max_redirections = maximum_redirects
        self.redirects: list[dict[str, object]] = []

    def redirect_request(
        self,
        request: Request,
        file_pointer: BinaryIO,
        code: int,
        message: str,
        headers: Any,
        new_url: str,
    ) -> Request | None:
        resolved = urljoin(request.full_url, new_url)
        validate_feed_url(resolved)
        redirected = super().redirect_request(
            request,
            file_pointer,
            code,
            message,
            headers,
            resolved,
        )
        if redirected is not None:
            validate_feed_url(redirected.full_url)
            self.redirects.append({"status": code, "to": redirected.full_url})
        return redirected


def atomic_write(path: Path, data: bytes) -> None:
    """Flush a same-directory temporary file and atomically replace the target."""

    path = path.resolve()
    if path.is_symlink() or (path.exists() and not path.is_file()):
        raise FeedSyncError(
            "unsafe_output_target",
            "The output and metadata targets must be regular files, not symlinks or directories.",
        )
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            dir=path.parent,
            prefix=f".{path.name}.",
            suffix=".tmp",
            delete=False,
        ) as handle:
            temporary = Path(handle.name)
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        temporary = None
    except FeedSyncError:
        raise
    except OSError as error:
        raise FeedSyncError("atomic_write_failed", f"Could not atomically update {path.name}.") from error
    finally:
        if temporary is not None:
            try:
                temporary.unlink(missing_ok=True)
            except OSError:
                pass


def read_cache_metadata(path: Path) -> tuple[dict[str, object] | None, str]:
    try:
        details = path.stat()
        if path.is_symlink() or not path.is_file() or details.st_size > MAX_CACHE_BYTES:
            return None, "ignored_invalid"
        parsed = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError:
        return None, "missing"
    except (OSError, UnicodeError) as error:
        raise FeedSyncError("cache_read_failed", "The HTTP cache metadata could not be read.") from error
    except (json.JSONDecodeError, TypeError):
        return None, "ignored_invalid"

    if not isinstance(parsed, dict):
        return None, "ignored_invalid"
    if parsed.get("schema_version") != CACHE_SCHEMA_VERSION or parsed.get("feed_url") != OFFICIAL_FEED:
        return None, "ignored_invalid"

    metadata = dict(parsed)
    metadata["etag"] = safe_header_value(parsed.get("etag"))
    metadata["last_modified"] = safe_header_value(parsed.get("last_modified"), 256)
    digest = parsed.get("sha256")
    metadata["sha256"] = digest.lower() if isinstance(digest, str) and re.fullmatch(r"[a-fA-F0-9]{64}", digest) else None
    return metadata, "loaded"


def read_existing_feed(path: Path, maximum_bytes: int) -> bytes | None:
    try:
        details = path.stat()
        if path.is_symlink() or not path.is_file() or details.st_size > maximum_bytes:
            return None
        return path.read_bytes()
    except FileNotFoundError:
        return None
    except OSError as error:
        raise FeedSyncError("output_read_failed", "The existing feed output could not be read.") from error


def local_name(tag: str) -> str:
    return tag.rsplit("}", 1)[-1].rsplit(":", 1)[-1].lower()


def validate_feed_xml(data: bytes) -> dict[str, object]:
    """Perform a bounded full parse after an encoding-aware declaration scan."""

    if not data:
        raise FeedSyncError("empty_response", "The feed response is empty.")
    reject_unsafe_xml_declarations(data)
    try:
        root = SafeElementTree.fromstring(data)
    except DefusedXmlException as error:
        raise FeedSyncError(
            "unsafe_xml",
            "XML containing DTD or entity declarations is not accepted.",
        ) from error
    except ElementTree.ParseError as error:
        raise FeedSyncError("invalid_xml", "The response is not complete, well-formed feed XML.") from error

    root_name = local_name(root.tag)
    if root_name not in {"rss", "feed", "rdf"}:
        raise FeedSyncError(
            "unexpected_xml_root",
            "The response is XML but does not have an RSS, Atom, or RDF feed root.",
        )
    if root_name == "rss" and not any(local_name(child.tag) == "channel" for child in root):
        raise FeedSyncError("invalid_rss", "The RSS document has no channel element.")
    item_name = "entry" if root_name == "feed" else "item"
    item_count = sum(1 for element in root.iter() if local_name(element.tag) == item_name)
    return {
        "root": root_name,
        "item_count": item_count,
        "raw_item_count": item_count,
        "xml_security_backend": XML_SECURITY_BACKEND,
    }


def response_headers(response: Any) -> Any:
    return getattr(response, "headers", {})


def response_header(response: Any, name: str) -> str | None:
    headers = response_headers(response)
    value = headers.get(name) if hasattr(headers, "get") else None
    return safe_header_value(value, 256 if name.lower() in {"last-modified", "content-type"} else 1024)


def read_limited_response(response: Any, maximum_bytes: int) -> bytes:
    declared = response_header(response, "Content-Length")
    if declared and declared.isdigit() and int(declared) > maximum_bytes:
        raise FeedSyncError(
            "response_too_large",
            f"The feed exceeds the configured {maximum_bytes}-byte response limit.",
        )

    chunks: list[bytes] = []
    total = 0
    while True:
        chunk = response.read(min(64 * 1024, maximum_bytes - total + 1))
        if not chunk:
            break
        total += len(chunk)
        if total > maximum_bytes:
            raise FeedSyncError(
                "response_too_large",
                f"The feed exceeds the configured {maximum_bytes}-byte response limit.",
            )
        chunks.append(chunk)
    return b"".join(chunks)


def request_headers(cache: dict[str, object] | None, conditional: bool) -> dict[str, str]:
    headers = {
        "Accept": "application/rss+xml, application/xml;q=0.9, text/xml;q=0.8",
        "User-Agent": DEFAULT_USER_AGENT,
    }
    if conditional and cache:
        if cache.get("etag"):
            headers["If-None-Match"] = str(cache["etag"])
        if cache.get("last_modified"):
            headers["If-Modified-Since"] = str(cache["last_modified"])
    return headers


def open_request(
    opener: OpenerDirector,
    headers: dict[str, str],
    timeout_seconds: float,
) -> Any:
    request = Request(OFFICIAL_FEED, headers=headers, method="GET")
    try:
        return opener.open(request, timeout=timeout_seconds)
    except HTTPError as error:
        if error.code == 304:
            return error
        raise FeedSyncError(
            "unexpected_http_status",
            f"The official feed returned HTTP {error.code}; expected 200 or 304.",
        ) from error
    except TimeoutError as error:
        raise FeedSyncError(
            "request_timeout",
            f"The official feed request exceeded the {timeout_seconds:g}-second timeout.",
        ) from error
    except URLError as error:
        if isinstance(error.reason, TimeoutError):
            raise FeedSyncError(
                "request_timeout",
                f"The official feed request exceeded the {timeout_seconds:g}-second timeout.",
            ) from error
        raise FeedSyncError("network_error", "The official feed request failed.") from error
    except OSError as error:
        raise FeedSyncError("network_error", "The official feed request failed.") from error


def response_status(response: Any) -> int:
    status = getattr(response, "status", None)
    if status is None and hasattr(response, "getcode"):
        status = response.getcode()
    return int(status)


def response_url(response: Any) -> str:
    value = response.geturl() if hasattr(response, "geturl") else OFFICIAL_FEED
    return validate_feed_url(value)


def close_response(response: Any) -> None:
    close = getattr(response, "close", None)
    if callable(close):
        close()


def sync_feed(
    output: Path,
    *,
    cache_metadata: Path | None = None,
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    maximum_bytes: int = DEFAULT_MAX_BYTES,
    maximum_redirects: int = DEFAULT_MAX_REDIRECTS,
    dry_run: bool = False,
    opener: OpenerDirector | None = None,
    clock: Callable[[], datetime] = lambda: datetime.now(timezone.utc),
) -> dict[str, object]:
    """Fetch, validate, and optionally atomically publish the official recent feed."""

    validate_feed_url(OFFICIAL_FEED, require_official=True)
    if timeout_seconds <= 0 or timeout_seconds > 300:
        raise FeedSyncError("invalid_timeout", "The timeout must be greater than 0 and at most 300 seconds.")
    if maximum_bytes < 1 or maximum_bytes > 100 * 1024 * 1024:
        raise FeedSyncError(
            "invalid_size_limit",
            "The maximum response size must be between 1 byte and 104857600 bytes.",
        )
    if maximum_redirects < 0 or maximum_redirects > 10:
        raise FeedSyncError("invalid_redirect_limit", "The redirect limit must be between 0 and 10.")

    output = output.resolve()
    cache_path = (cache_metadata or Path(f"{output}.http-cache.json")).resolve()
    if output == cache_path:
        raise FeedSyncError("path_collision", "The feed output and cache metadata paths must differ.")

    cache, cache_status = read_cache_metadata(cache_path)
    redirect_handler: AllowlistedRedirectHandler | None = None
    if opener is None:
        redirect_handler = AllowlistedRedirectHandler(maximum_redirects)
        opener = build_opener(redirect_handler)

    conditional = bool(cache and (cache.get("etag") or cache.get("last_modified")))
    cache_recovery = False
    response = open_request(opener, request_headers(cache, conditional), timeout_seconds)
    final_url = response_url(response)
    status = response_status(response)
    if status == 304:
        try:
            existing = read_existing_feed(output, maximum_bytes)
            existing_valid = False
            cached_xml: dict[str, object] | None = None
            if existing and (not cache or not cache.get("sha256") or sha256(existing) == cache["sha256"]):
                try:
                    cached_xml = validate_feed_xml(existing)
                    existing_valid = True
                except FeedSyncError:
                    existing_valid = False
            if existing_valid:
                return {
                    "status": "not_modified",
                    "dry_run": dry_run,
                    "feed_url": OFFICIAL_FEED,
                    "final_url": final_url,
                    "redirects": redirect_handler.redirects if redirect_handler else [],
                    "conditional_request": conditional,
                    "cache_status": cache_status,
                    "xml_security_backend": XML_SECURITY_BACKEND,
                    "xml_root": cached_xml["root"] if cached_xml else None,
                    "raw_item_count": cached_xml["raw_item_count"] if cached_xml else None,
                    "bytes": len(existing),
                    "sha256": sha256(existing),
                    "output": str(output),
                    "cache_metadata": str(cache_path),
                    "wrote_output": False,
                    "wrote_metadata": False,
                }
            cache_recovery = True
        finally:
            close_response(response)
    elif status != 200:
        close_response(response)
        raise FeedSyncError(
            "unexpected_http_status",
            f"The official feed returned HTTP {status}; expected 200 or 304.",
        )

    if cache_recovery:
        conditional = False
        response = open_request(opener, request_headers(None, False), timeout_seconds)
        status = response_status(response)
        if status != 200:
            close_response(response)
            raise FeedSyncError(
                "cache_recovery_failed",
                f"Cache recovery returned HTTP {status}; expected 200.",
            )

    try:
        final_url = response_url(response)
        body = read_limited_response(response, maximum_bytes)
        xml = validate_feed_xml(body)
        selected_headers = {
            "etag": response_header(response, "ETag"),
            "last_modified": response_header(response, "Last-Modified"),
            "content_type": response_header(response, "Content-Type"),
        }
    except TimeoutError as error:
        raise FeedSyncError(
            "request_timeout",
            f"The official feed request exceeded the {timeout_seconds:g}-second timeout.",
        ) from error
    except OSError as error:
        raise FeedSyncError("network_error", "The official feed response could not be read.") from error
    finally:
        close_response(response)

    body_hash = sha256(body)
    existing = read_existing_feed(output, maximum_bytes)
    output_changed = existing is None or sha256(existing) != body_hash
    metadata = {
        "schema_version": CACHE_SCHEMA_VERSION,
        "feed_url": OFFICIAL_FEED,
        "final_url": final_url,
        "etag": selected_headers["etag"],
        "last_modified": selected_headers["last_modified"],
        "sha256": body_hash,
        "bytes": len(body),
        "synchronized_at": clock().astimezone(timezone.utc).isoformat().replace("+00:00", "Z"),
    }
    metadata_bytes = (json.dumps(metadata, indent=2, sort_keys=True) + "\n").encode("utf-8")

    if not dry_run:
        if output_changed:
            atomic_write(output, body)
        atomic_write(cache_path, metadata_bytes)

    return {
        "status": (
            "would_update" if dry_run and output_changed
            else "would_refresh_metadata" if dry_run
            else "updated" if output_changed
            else "unchanged"
        ),
        "dry_run": dry_run,
        "feed_url": OFFICIAL_FEED,
        "final_url": final_url,
        "redirects": redirect_handler.redirects if redirect_handler else [],
        "conditional_request": conditional,
        "cache_status": cache_status,
        "cache_recovery": cache_recovery,
        "http_status": 200,
        "content_type": selected_headers["content_type"],
        "xml_root": xml["root"],
        "item_count": xml["item_count"],
        "raw_item_count": xml["raw_item_count"],
        "xml_security_backend": xml["xml_security_backend"],
        "scope": "official_recent_feed_only",
        "historical_backfill": "local_saved_xenforo_html",
        "bytes": len(body),
        "sha256": body_hash,
        "output": str(output),
        "cache_metadata": str(cache_path),
        "wrote_output": not dry_run and output_changed,
        "wrote_metadata": not dry_run,
    }


def positive_float(value: str) -> float:
    parsed = float(value)
    if parsed <= 0:
        raise argparse.ArgumentTypeError("must be greater than zero")
    return parsed


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


def parse_arguments(arguments: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Synchronize only the official recent EverQuest Game Update Notes RSS feed. "
            "No forum pages or item links are crawled."
        ),
    )
    parser.add_argument("output", type=Path, help="Explicit destination for the RSS file")
    parser.add_argument(
        "--cache-metadata",
        type=Path,
        help="HTTP validator sidecar (default: <output>.http-cache.json)",
    )
    parser.add_argument("--timeout", type=positive_float, default=DEFAULT_TIMEOUT_SECONDS)
    parser.add_argument("--max-bytes", type=positive_integer, default=DEFAULT_MAX_BYTES)
    parser.add_argument("--max-redirects", type=nonnegative_integer, default=DEFAULT_MAX_REDIRECTS)
    parser.add_argument("--dry-run", action="store_true", help="Fetch and validate without writing files")
    return parser.parse_args(arguments)


def main(arguments: list[str] | None = None) -> int:
    configure_utf8_stdout()
    args = parse_arguments(arguments)
    try:
        report = sync_feed(
            args.output,
            cache_metadata=args.cache_metadata,
            timeout_seconds=args.timeout,
            maximum_bytes=args.max_bytes,
            maximum_redirects=args.max_redirects,
            dry_run=args.dry_run,
        )
    except FeedSyncError as error:
        print(
            json.dumps(
                {"status": "error", "code": error.code, "message": str(error)},
                indent=2,
                sort_keys=True,
                ensure_ascii=False,
            ),
            file=sys.stderr,
        )
        return 1
    print(json.dumps(report, indent=2, sort_keys=True, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
