#!/usr/bin/env python3
"""
Japan — MEXT accredited institutions ingest.

    python scripts/jp_ingest.py --latest

There is no backfill mode, and that is not an oversight. This register began in
2024 with the new accreditation law, the API exposes only its current state, and
the portal is a JavaScript application whose archived captures do not carry the
data. Japan's record therefore starts the day we start — which is worth saying
plainly on the country page rather than implying a depth we do not have.

What makes it worth carrying is what happens next: several hundred schools on
the older notification regime must migrate into this register, and from today we
will have the dated record of which ones did.
"""

from __future__ import annotations

import argparse
import datetime as _dt
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine import diff as diffmod
from engine import sanity
from engine.snapshot import Archive, meta_for, sha256_rows
from engine.sources import jp_mext as jp

SOURCE = "jp-mext"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--latest", action="store_true", required=True)
    ap.add_argument("--strict", action="store_true", default=True)
    args = ap.parse_args()

    archive = Archive(SOURCE)
    held = archive.edition_dates()
    print(f"archive holds {len(held)} editions" + (f", latest {held[-1]}" if held else ""))

    try:
        payload = jp.fetch()
    except RuntimeError as exc:
        print(f"FATAL: {exc}", file=sys.stderr)
        return 2

    rows = jp.parse(payload)
    complete, why = jp.pagination_is_complete(payload, rows)
    if not complete:
        # Refuse rather than archive a partial register. Everything missing
        # would read as a mass removal on the next comparison.
        print(f"FATAL: incomplete fetch — {why}", file=sys.stderr)
        return 2

    src_date = jp.source_date(payload, rows)
    edition = _dt.date.today().isoformat()
    print(f"fetched {len(rows)} institutions (API reports total={payload.get('total')})")

    prev = archive.previous_of(edition)
    prev_rows = prev["rows"] if prev else None

    def snap(r, sha=None):
        return sanity.Snapshot(source=SOURCE, rows=list(r), key_field=jp.KEY_FIELD, content_sha256=sha)

    verdict = sanity.evaluate(
        snap(rows, sha256_rows(rows)),
        snap(prev_rows, (prev or {}).get("content_sha256")) if prev_rows is not None else None,
        sanity.thresholds_for(SOURCE),
    )
    for w in verdict.warnings:
        print(f"  warn: {w}")

    if not verdict.ok:
        print(sanity.format_alert(verdict), file=sys.stderr)
        if verdict.structural or args.strict:
            return 3

    if edition in held:
        print(f"already hold an edition dated {edition}; refreshing it")

    archive.write_edition(
        edition_date=edition,
        rows=rows,
        meta=meta_for(SOURCE),
        source_date=src_date,
        extra={"api_total": payload.get("total"), "newest_certification": src_date},
    )

    result = {"edition_date": edition, "rows": len(rows), "newest_certification": src_date}

    if prev_rows is not None:
        d = diffmod.diff_editions(
            register="認定日本語教育機関 register",
            country="Japan",
            old_rows=prev_rows,
            new_rows=rows,
            key_field=jp.KEY_FIELD,
            name_field=jp.NAME_FIELD,
            old_edition=prev["edition_date"],
            new_edition=edition,
            watch_fields=jp.WATCH_FIELDS,
            persistent_id=True,  # certification_number is stable and published
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
    print(f"\ndone — {len(rows)} institutions recorded for {edition}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
