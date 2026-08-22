"""Extract the supplied PDF highlights into a deterministic, reviewable JSON companion."""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path

import pdfplumber


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("source_pdf", type=Path)
    parser.add_argument("output_json", type=Path)
    args = parser.parse_args()

    source_bytes = args.source_pdf.read_bytes()
    pages: list[dict[str, object]] = []

    with pdfplumber.open(args.source_pdf) as document:
        for page_number, page in enumerate(document.pages, start=1):
            text = (page.extract_text(x_tolerance=2, y_tolerance=3) or "").replace("\r\n", "\n").replace("\r", "\n").strip()
            pages.append({
                "page": page_number,
                "text": text,
                "character_count": len(text),
            })

    artifact = {
        "schema_version": 1,
        "source_filename": args.source_pdf.name,
        "source_sha256": hashlib.sha256(source_bytes).hexdigest(),
        "title": "ZAM EverQuest Patch Highlights",
        "coverage_note": "Curated highlights spanning April 1999 through June 2007; supplemental to the canonical patch records.",
        "page_count": len(pages),
        "pages": pages,
    }

    args.output_json.parent.mkdir(parents=True, exist_ok=True)
    args.output_json.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
