from __future__ import annotations

import importlib.util
import io
import json
import sys
import unittest
from datetime import datetime, timezone
from email.message import Message
from pathlib import Path
from unittest import mock
from urllib.request import Request
from uuid import uuid4


MODULE_PATH = Path(__file__).resolve().parents[1] / "scripts" / "sync-patch-feed.py"
SPEC = importlib.util.spec_from_file_location("sync_patch_feed", MODULE_PATH)
assert SPEC and SPEC.loader
sync = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = sync
SPEC.loader.exec_module(sync)

FEED = b"""<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Game Update Notes</title>
<item><title>August 2026 Update</title><description>Patch notes</description></item>
</channel></rss>
"""
FEED_2 = FEED.replace(b"August 2026", b"September 2026")


class FakeResponse:
    def __init__(
        self,
        body: bytes = b"",
        *,
        status: int = 200,
        url: str = sync.OFFICIAL_FEED,
        headers: dict[str, str] | None = None,
    ) -> None:
        self.status = status
        self._url = url
        self._stream = io.BytesIO(body)
        self.headers = Message()
        self.closed = False
        for key, value in (headers or {}).items():
            self.headers[key] = value

    def read(self, size: int = -1) -> bytes:
        return self._stream.read(size)

    def geturl(self) -> str:
        return self._url

    def getcode(self) -> int:
        return self.status

    def close(self) -> None:
        self.closed = True


class FakeOpener:
    def __init__(self, *responses: FakeResponse | Exception) -> None:
        self.responses = list(responses)
        self.requests: list[Request] = []
        self.timeouts: list[float] = []

    def open(self, request: Request, *, timeout: float) -> FakeResponse:
        self.requests.append(request)
        self.timeouts.append(timeout)
        result = self.responses.pop(0)
        if isinstance(result, Exception):
            raise result
        return result


class FeedSyncTest(unittest.TestCase):
    def temporary_paths(self) -> tuple[Path, Path]:
        token = uuid4().hex
        output = MODULE_PATH.parents[1] / f".patch-feed-sync-{token}.rss"
        metadata = MODULE_PATH.parents[1] / f".patch-feed-sync-{token}.http-cache.json"
        self.addCleanup(lambda: output.unlink(missing_ok=True))
        self.addCleanup(lambda: metadata.unlink(missing_ok=True))
        return output, metadata

    def test_exact_official_feed_and_url_boundaries(self) -> None:
        self.assertEqual(
            sync.OFFICIAL_FEED,
            "https://forums.everquest.com/index.php?forums/game-update-notes-live.9/index.rss",
        )
        self.assertEqual(sync.validate_feed_url(sync.OFFICIAL_FEED, require_official=True), sync.OFFICIAL_FEED)
        with self.assertRaisesRegex(sync.FeedSyncError, "HTTPS") as caught:
            sync.validate_feed_url("http://forums.everquest.com/update.rss")
        self.assertEqual(caught.exception.code, "https_required")
        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.validate_feed_url("https://example.com/update.rss")
        self.assertEqual(caught.exception.code, "host_not_allowed")
        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.validate_feed_url("https://forums.everquest.com/index.php?threads/update.1")
        self.assertEqual(caught.exception.code, "rss_endpoint_required")
        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.validate_feed_url("https://user:secret@forums.everquest.com/update.rss")
        self.assertEqual(caught.exception.code, "credentials_not_allowed")

    def test_redirect_handler_validates_before_following(self) -> None:
        handler = sync.AllowlistedRedirectHandler()
        request = Request(sync.OFFICIAL_FEED)
        headers = Message()
        with self.assertRaises(sync.FeedSyncError) as caught:
            handler.redirect_request(
                request,
                io.BytesIO(),
                302,
                "Found",
                headers,
                "https://example.com/thread.rss",
            )
        self.assertEqual(caught.exception.code, "host_not_allowed")
        self.assertEqual(handler.redirects, [])

        redirected = handler.redirect_request(
            request,
            io.BytesIO(),
            301,
            "Moved",
            headers,
            "/feeds/game-update-notes.rss",
        )
        self.assertIsNotNone(redirected)
        self.assertEqual(redirected.full_url, "https://forums.everquest.com/feeds/game-update-notes.rss")
        self.assertEqual(handler.redirects, [{"status": 301, "to": redirected.full_url}])

    def test_xml_sanity_checks_reject_active_nonfeed_and_truncated_documents(self) -> None:
        self.assertEqual(sync.validate_feed_xml(FEED)["root"], "rss")
        self.assertEqual(sync.validate_feed_xml(FEED)["item_count"], 1)
        for payload, code in (
            (b"<!DOCTYPE rss><rss><channel /></rss>", "unsafe_xml"),
            (b"<html><body>Sign in</body></html>", "unexpected_xml_root"),
            (b"<rss><channel>", "invalid_xml"),
        ):
            with self.subTest(code=code), self.assertRaises(sync.FeedSyncError) as caught:
                sync.validate_feed_xml(payload)
            self.assertEqual(caught.exception.code, code)

        utf16_dtd = (
            '<?xml version="1.0" encoding="utf-16"?>'
            '<!DOCTYPE rss [<!ENTITY x "unsafe">]>'
            '<rss><channel><title>&x;</title></channel></rss>'
        ).encode("utf-16")
        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.validate_feed_xml(utf16_dtd)
        self.assertEqual(caught.exception.code, "unsafe_xml")

    def test_stdout_is_forced_to_utf8(self) -> None:
        class Console:
            def __init__(self) -> None:
                self.configuration = None

            def reconfigure(self, **options: str) -> None:
                self.configuration = options

        console = Console()
        sync.configure_utf8_stdout(console)
        self.assertEqual(console.configuration, {"encoding": "utf-8", "errors": "strict"})

    def test_writes_valid_feed_and_cache_metadata_with_descriptive_headers(self) -> None:
        output, metadata = self.temporary_paths()
        opener = FakeOpener(FakeResponse(FEED, headers={
            "Content-Type": "application/rss+xml; charset=utf-8",
            "ETag": '"feed-v1"',
            "Last-Modified": "Sat, 22 Aug 2026 12:00:00 GMT",
        }))
        report = sync.sync_feed(
            output,
            cache_metadata=metadata,
            opener=opener,
            timeout_seconds=7,
            clock=lambda: datetime(2026, 8, 22, 12, 0, tzinfo=timezone.utc),
        )

        request = opener.requests[0]
        self.assertEqual(request.full_url, sync.OFFICIAL_FEED)
        self.assertEqual(request.get_header("User-agent"), sync.DEFAULT_USER_AGENT)
        self.assertIn("application/rss+xml", request.get_header("Accept"))
        self.assertEqual(opener.timeouts, [7])
        self.assertEqual(report["status"], "updated")
        self.assertEqual(report["scope"], "official_recent_feed_only")
        self.assertEqual(report["historical_backfill"], "local_saved_xenforo_html")
        self.assertEqual(output.read_bytes(), FEED)

        sidecar = json.loads(metadata.read_text(encoding="utf-8"))
        self.assertEqual(sidecar["feed_url"], sync.OFFICIAL_FEED)
        self.assertEqual(sidecar["etag"], '"feed-v1"')
        self.assertEqual(sidecar["bytes"], len(FEED))
        self.assertRegex(sidecar["sha256"], r"^[a-f0-9]{64}$")
        self.assertEqual(sidecar["synchronized_at"], "2026-08-22T12:00:00Z")

    def test_conditional_get_uses_sidecar_and_valid_304(self) -> None:
        output, metadata = self.temporary_paths()
        sync.sync_feed(
            output,
            cache_metadata=metadata,
            opener=FakeOpener(FakeResponse(FEED, headers={
                "ETag": '"feed-v1"',
                "Last-Modified": "Sat, 22 Aug 2026 12:00:00 GMT",
            })),
        )

        cached_response = FakeResponse(status=304)
        opener = FakeOpener(cached_response)
        report = sync.sync_feed(output, cache_metadata=metadata, opener=opener)
        request = opener.requests[0]
        self.assertEqual(request.get_header("If-none-match"), '"feed-v1"')
        self.assertEqual(
            request.get_header("If-modified-since"),
            "Sat, 22 Aug 2026 12:00:00 GMT",
        )
        self.assertEqual(report["status"], "not_modified")
        self.assertFalse(report["wrote_output"])
        self.assertTrue(cached_response.closed)
        self.assertEqual(output.read_bytes(), FEED)

    def test_invalid_cache_after_304_is_recovered_unconditionally(self) -> None:
        output, metadata = self.temporary_paths()
        sync.sync_feed(
            output,
            cache_metadata=metadata,
            opener=FakeOpener(FakeResponse(FEED, headers={"ETag": '"feed-v1"'})),
        )
        output.write_bytes(b"tampered")

        opener = FakeOpener(FakeResponse(status=304), FakeResponse(FEED_2, headers={"ETag": '"feed-v2"'}))
        report = sync.sync_feed(output, cache_metadata=metadata, opener=opener)
        self.assertEqual(len(opener.requests), 2)
        self.assertEqual(opener.requests[0].get_header("If-none-match"), '"feed-v1"')
        self.assertIsNone(opener.requests[1].get_header("If-none-match"))
        self.assertTrue(report["cache_recovery"])
        self.assertEqual(output.read_bytes(), FEED_2)

    def test_dry_run_fetches_and_validates_without_writes(self) -> None:
        output, metadata = self.temporary_paths()
        report = sync.sync_feed(
            output,
            cache_metadata=metadata,
            dry_run=True,
            opener=FakeOpener(FakeResponse(FEED)),
        )
        self.assertEqual(report["status"], "would_update")
        self.assertFalse(report["wrote_output"])
        self.assertFalse(report["wrote_metadata"])
        self.assertFalse(output.exists())
        self.assertFalse(metadata.exists())

    def test_size_and_xml_failures_preserve_existing_output(self) -> None:
        output, metadata = self.temporary_paths()
        sync.sync_feed(output, cache_metadata=metadata, opener=FakeOpener(FakeResponse(FEED)))

        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.sync_feed(
                output,
                cache_metadata=metadata,
                maximum_bytes=32,
                opener=FakeOpener(FakeResponse(b"A" * 33)),
            )
        self.assertEqual(caught.exception.code, "response_too_large")
        self.assertEqual(output.read_bytes(), FEED)

        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.sync_feed(
                output,
                cache_metadata=metadata,
                opener=FakeOpener(FakeResponse(b"<html><body>Sign in</body></html>")),
            )
        self.assertEqual(caught.exception.code, "unexpected_xml_root")
        self.assertEqual(output.read_bytes(), FEED)

    def test_final_redirect_url_is_revalidated_even_with_injected_opener(self) -> None:
        output, metadata = self.temporary_paths()
        opener = FakeOpener(FakeResponse(FEED, url="https://example.com/stolen.rss"))
        with self.assertRaises(sync.FeedSyncError) as caught:
            sync.sync_feed(output, cache_metadata=metadata, opener=opener)
        self.assertEqual(caught.exception.code, "host_not_allowed")
        self.assertFalse(output.exists())

    def test_atomic_failure_cleans_temporary_file_and_preserves_target(self) -> None:
        target = MODULE_PATH.parents[1] / f".patch-feed-atomic-{uuid4().hex}.rss"
        self.addCleanup(lambda: target.unlink(missing_ok=True))
        target.write_bytes(b"old")
        with mock.patch.object(sync.os, "replace", side_effect=OSError("simulated")):
            with self.assertRaises(sync.FeedSyncError) as caught:
                sync.atomic_write(target, FEED)
        self.assertEqual(caught.exception.code, "atomic_write_failed")
        self.assertEqual(target.read_bytes(), b"old")
        self.assertEqual(list(target.parent.glob(f".{target.name}.*.tmp")), [])


if __name__ == "__main__":
    unittest.main()
