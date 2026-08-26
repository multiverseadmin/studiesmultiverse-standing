#!/usr/bin/env python3
"""
United Kingdom — register of licensed student sponsors ingest.

    python scripts/uk_ingest.py --latest

Daily, because the Home Office republishes this register most working days and
gives no notice when a sponsor's status changes. The edition date comes from the
publisher's own filename, so a day on which nothing was republished records no
new edition rather than a duplicate one.

No backfill mode yet. The CSV's URL contains a per-edition content hash, so
older editions are not addressable by pattern; reconstructing history would mean
walking the Internet Archive's captures of the publication page and following
whichever attachment each capture pointed at. That is worth doing and is not
this script.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine import diff as diffmod
from engine import sanity
from engine.snapshot import Archive, meta_for, sha256_bytes, sha256_rows
from engine.sources import uk_sponsors as uk

SOURCE = "uk-sponsors"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--latest", action="store_true", required=True)
    ap.add_argument("--strict", action="store_true", default=True)
    args = ap.parse_args()

    archive = Archive(SOURCE)
    held = archive.edition_dates()
    print(f"archive holds {len(held)} editions" + (f", latest {held[-1]}" if held else ""))

    try:
        found = uk.discover()
    except RuntimeError as exc:
        print(f"FATAL: {exc}", file=sys.stderr)
        return 2

    print(f"attachment: {found.get('title')}")
    print(f"  {found['url']}")
    print(f"  edition date {found.get('edition_date')}, GOV.UK updated {found.get('public_updated_at')}")

    edition = uk.source_date(found)
    if not edition:
        # Without an edition date we cannot tell a republication from a new
        # edition, and inventing today's date would manufacture history.
        print("FATAL: no edition date in the filename or the API timestamp", file=sys.stderr)
        return 2

    if edition in held:
        print(f"already hold the edition dated {edition} — nothing to do")
        return 0

    try:
        raw = uk.fetch(found["url"])
    except RuntimeError as exc:
        print(f"FATAL: {exc}", file=sys.stderr)
        return 2

    rows = uk.parse(raw)
    print(f"parsed {len(rows)} rows")

    prev = archive.previous_of(edition)
    prev_rows = prev["rows"] if prev else None

    def snap(r, sha=None, date=None):
        import datetime as _dt

        return sanity.Snapshot(
            source=SOURCE,
            rows=list(r),
            key_field=uk.KEY_FIELD,
            source_date=_dt.date.fromisoformat(date) if date else None,
            content_sha256=sha,
        )

    verdict = sanity.evaluate(
        snap(rows, sha256_rows(rows), edition),
        snap(prev_rows, (prev or {}).get("content_sha256"), (prev or {}).get("edition_date"))
        if prev_rows is not None
        else None,
        sanity.thresholds_for(SOURCE),
    )
    for w in verdict.warnings:
        print(f"  warn: {w}")

    if not verdict.ok:
        print(sanity.format_alert(verdict), file=sys.stderr)
        if verdict.structural or args.strict:
            return 3

    archive.write_edition(
        edition_date=edition,
        rows=rows,
        meta=meta_for(SOURCE),
        source_date=edition,
        raw_bytes=raw,
        raw_ext="csv",
        extra={
            "attachment_url": found["url"],
            "public_updated_at": found.get("public_updated_at"),
            "raw_sha256_note": "the CSV exactly as the Home Office published it",
        },
    )

    result = {"edition_date": edition, "rows": len(rows), "raw_sha256": sha256_bytes(raw)}

    if prev_rows is not None:
        d = diffmod.diff_editions(
            register="register of licensed student sponsors",
            country="United Kingdom",
            old_rows=prev_rows,
            new_rows=rows,
            key_field=uk.KEY_FIELD,
            name_field=uk.NAME_FIELD,
            old_edition=prev["edition_date"],
            new_edition=edition,
            watch_fields=uk.WATCH_FIELDS,
            # There is no licence number in this file. The identity is a name,
            # so a rename cannot be told from a departure plus an arrival, and
            # the record must say so instead of guessing.
            persistent_id=False,
        )
        diffmod.assert_editorial_safety(d.changes)
        archive.append_changes({**c.to_dict(), "level": "institution"} for c in d.changes)
        result["changes"] = d.counts
        print(f"  changes: {d.counts}")
    else:
        print("  first edition — no comparison to make")

    out = pathlib.Path(f"data/{SOURCE}/last_run.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(result, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"\ndone — {len(rows)} sponsors recorded for {edition}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
