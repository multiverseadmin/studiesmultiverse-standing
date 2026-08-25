#!/usr/bin/env python3
"""
Canada — designated learning institutions ingest.

    python scripts/ca_ingest.py --latest      fetch today's page
    python scripts/ca_ingest.py --backfill    reconstruct from the Internet Archive

THE BACKFILL IS THE POINT

IRCC publishes no archive, no CSV and no suspension list. The brief recorded
Canada as unrecoverable — a record that could only start the day we began
collecting. It isn't: the Internet Archive holds roughly 500 unique-content
captures of the page from February 2018 onward, and they contain the full
table, not a stub. That turns Canada from "we'll know something in a year" into
eight years of history on day one.

Coverage is uneven — 147 captures in 2025 against 19 in 2019 — so the early
record is coarse and the recent record dense. The archive index says so per
edition rather than implying even coverage.

LICENCE

Change-record only. canada.ca permits non-commercial reproduction with
attribution but requires written permission for commercial redistribution, and
this site carries advertising. Rows are reduced to reportable facts before they
reach the public repository, and the raw HTML is never committed. If IRCC grants
permission, one field in engine/snapshot.py flips this to a full mirror.
"""

from __future__ import annotations

import argparse
import datetime as _dt
import json
import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine import diff as diffmod
from engine import sanity
from engine.snapshot import Archive, meta_for, sha256_rows
from engine.sources import ca_dli as ca

SOURCE = "ca-dli"


def ingest(raw: bytes, edition_date: str, archive: Archive, *, strict: bool) -> dict:
    rows = ca.parse(raw)
    src_date = ca.source_date(raw)

    prev = archive.previous_of(edition_date)
    prev_rows = prev["rows"] if prev else None

    def snap(r, date, sha=None):
        d = None
        if date:
            try:
                d = _dt.date.fromisoformat(date)
            except ValueError:
                d = None
        return sanity.Snapshot(
            source=SOURCE, rows=list(r), key_field=ca.KEY_FIELD, source_date=d, content_sha256=sha
        )

    verdict = sanity.evaluate(
        snap(rows, src_date, sha256_rows(rows)),
        snap(prev_rows, (prev or {}).get("source_date"), (prev or {}).get("content_sha256")) if prev_rows is not None else None,
        sanity.thresholds_for(SOURCE),
    )

    for w in verdict.warnings:
        print(f"      warn: {w}")

    if not verdict.ok:
        print(sanity.format_alert(verdict), file=sys.stderr)
        if strict:
            raise sanity.SanityError(verdict.source, verdict.failures)
        if verdict.structural:
            print("      REFUSED — could not read this capture; not archived", file=sys.stderr)
            return {"edition_date": edition_date, "skipped": True, "reason": "structural"}

    # Reduced before it touches the public repository — see the licence note.
    archive.write_edition(
        edition_date=edition_date,
        rows=rows,
        meta=meta_for(SOURCE),
        source_date=src_date,
        projection=ca.archive_projection,
        extra={"parsed_rows": len(rows), "unique_keys": len({r[ca.KEY_FIELD] for r in rows})},
    )

    result = {"edition_date": edition_date, "rows": len(rows), "source_date": src_date}

    if verdict.movement_only:
        result["flagged"] = [f.check for f in verdict.failures]
        archive.append_changes([{
            "kind": "edition_held_not_interpreted",
            "level": "meta",
            "register": "designated learning institutions list",
            "country": "Canada",
            "old_edition": prev["edition_date"] if prev else None,
            "new_edition": edition_date,
            "key": f"meta|{edition_date}",
            "name": f"DLI list capture {edition_date}",
            "statement": (
                f"The capture of {edition_date} is recorded, but no change entries were derived "
                f"from the step into it."
            ),
            "caveat": (
                "Movement between this capture and the previous one exceeded the limits we set for "
                "automatic interpretation. Archive coverage of this page is uneven — there are long "
                "gaps in the early years — so a large apparent change often means months passed "
                "between captures rather than that anything happened at once. A person needs to "
                "confirm before we describe it."
            ),
        }])
        print(f"      FLAGGED — held, not interpreted ({result['flagged']})", file=sys.stderr)
        return result

    if prev_rows is not None:
        d = diffmod.diff_editions(
            register="designated learning institutions list",
            country="Canada",
            old_rows=prev_rows,
            new_rows=ca.archive_projection(rows),
            key_field=ca.KEY_FIELD,
            name_field=ca.NAME_FIELD,
            old_edition=prev["edition_date"],
            new_edition=edition_date,
            watch_fields=("city", "province"),
            persistent_id=True,  # the DLI number is stable and published
        )
        diffmod.assert_editorial_safety(d.changes)
        archive.append_changes({**c.to_dict(), "level": "institution"} for c in d.changes)
        result["changes"] = d.counts

    print(f"      rows={len(rows)} source_date={src_date} {result.get('changes','')}", flush=True)
    return result


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--latest", action="store_true")
    g.add_argument("--backfill", action="store_true", help="reconstruct from Internet Archive captures")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--sleep", type=float, default=2.0, help="be polite to the archive")
    ap.add_argument("--strict", action="store_true", default=None)
    args = ap.parse_args()

    strict = args.strict if args.strict is not None else bool(args.latest)
    archive = Archive(SOURCE)
    held = set(archive.edition_dates())
    print(f"archive holds {len(held)} editions" + (f", latest {max(held)}" if held else ""))

    results = []

    if args.latest:
        raw = ca.fetch_live()
        date = ca.source_date(raw) or _dt.date.today().isoformat()
        if date in held:
            print(f"already hold the edition dated {date} — nothing to do")
            return 0
        print(f"  → {date} (live)")
        results.append(ingest(raw, date, archive, strict=strict))
    else:
        stamps = ca.list_captures()
        print(f"Internet Archive holds {len(stamps)} unique-content captures")
        todo = []
        seen_dates = set(held)
        for ts in stamps:
            d = ca.capture_date(ts)
            if d in seen_dates:
                continue
            seen_dates.add(d)  # one capture per day is enough
            todo.append((ts, d))
        if args.limit:
            todo = todo[: args.limit]
        print(f"ingesting {len(todo)} capture(s)")
        for i, (ts, d) in enumerate(todo, 1):
            print(f"  → {d}  (capture {ts})", flush=True)
            try:
                raw = ca.fetch_capture(ts)
                results.append(ingest(raw, d, archive, strict=strict))
            except Exception as exc:  # a single bad capture must not end the run
                print(f"      capture failed: {exc}", file=sys.stderr)
                results.append({"edition_date": d, "skipped": True, "reason": str(exc)[:120]})
            if i < len(todo):
                time.sleep(args.sleep)

    out = pathlib.Path(f"data/{SOURCE}/last_run.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    summary = {
        "ingested": len([r for r in results if not r.get("skipped")]),
        "skipped": len([r for r in results if r.get("skipped")]),
        "editions": results[-40:],
    }
    out.write_text(json.dumps(summary, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"\ndone — {summary['ingested']} ingested, {summary['skipped']} skipped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
