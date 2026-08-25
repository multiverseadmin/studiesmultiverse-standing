#!/usr/bin/env python3
"""
Australia — CRICOS ingest.

Two modes, same code path, so the backfill and the monthly run can never drift
apart:

    python scripts/au_ingest.py --backfill      reconstruct all 57 editions
    python scripts/au_ingest.py --latest        ingest any edition we don't hold

The backfill is the whole point of building Australia first. Nobody starting
tomorrow can catch up nearly five years of monthly editions, because every
other country overwrites.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine import diff as diffmod
from engine import sanity
from engine.snapshot import Archive, meta_for
from engine.sources import au_cricos as au

PROVIDERS = "au-cricos-providers"
COURSES = "au-cricos-courses"


def _snapshot(source: str, rows: list[dict], key: str, source_date, sha) -> sanity.Snapshot:
    import datetime as dt

    sd = None
    if source_date:
        try:
            sd = dt.date.fromisoformat(source_date)
        except ValueError:
            sd = None
    return sanity.Snapshot(
        source=source, rows=rows, key_field=key, source_date=sd, content_sha256=sha
    )


def ingest_edition(edition: dict, archive: Archive, *, strict: bool) -> dict:
    """Fetch, parse, gate, archive and diff one CRICOS edition."""
    from engine.snapshot import sha256_rows

    date = edition["edition_date"]
    print(f"  → {date}  {edition['name'][:58]}", flush=True)

    raw = au.fetch_edition(edition["url"])
    parsed = au.parse_edition(raw)
    institutions = parsed["institutions"]
    courses = au.add_course_keys(parsed["courses"])
    source_date = parsed["source_date"]

    prev = archive.previous_of(date)
    prev_inst = prev["rows"] if prev else None
    prev_courses = (prev.get("extra", {}) or {}).get("courses") if prev else None

    # ---- the gate, before anything is written or interpreted --------------
    prov_snap = _snapshot(PROVIDERS, institutions, au.INSTITUTION_KEY, source_date, sha256_rows(institutions))
    prov_prev = (
        _snapshot(PROVIDERS, prev_inst, au.INSTITUTION_KEY, (prev or {}).get("source_date"), (prev or {}).get("content_sha256"))
        if prev_inst is not None
        else None
    )
    verdict = sanity.evaluate(prov_snap, prov_prev, sanity.thresholds_for(PROVIDERS))

    course_snap = _snapshot(COURSES, courses, "_key", source_date, sha256_rows(courses))
    course_prev = (
        _snapshot(COURSES, prev_courses, "_key", (prev or {}).get("source_date"), None)
        if prev_courses is not None
        else None
    )
    course_verdict = sanity.evaluate(course_snap, course_prev, sanity.thresholds_for(COURSES))

    verdicts = [verdict, course_verdict]
    for v in verdicts:
        for w in v.warnings:
            print(f"      warn: {w}")

    failed = [v for v in verdicts if not v.ok]
    structural = [v for v in failed if v.structural]
    movement = [v for v in failed if v.movement_only]

    if failed:
        for v in failed:
            print(sanity.format_alert(v), file=sys.stderr)
        if strict:
            raise sanity.SanityError(failed[0].source, failed[0].failures)

    # A file we could not READ must not enter the archive at any cost.
    if structural:
        print("      REFUSED — could not read this edition; not archived", file=sys.stderr)
        return {"edition_date": date, "skipped": True, "reason": "structural",
                "stats": structural[0].stats}

    # A file we could read, whose movement looks implausible, IS archived — so
    # the chain of edition-to-edition comparisons never breaks — but we publish
    # no interpretation of it. See Verdict.movement_only for why this matters.
    flagged = bool(movement)

    # ---- archive ----------------------------------------------------------
    archive.write_edition(
        edition_date=date,
        rows=institutions,
        meta=meta_for("au-cricos"),
        source_date=source_date,
        raw_bytes=raw,
        raw_ext="xlsx",
        extra={
            "source_stamp": parsed["source_stamp"],
            "sheet_counts": parsed["sheet_counts"],
            "course_count": len(courses),
            "courses": courses,
            "resource_name": edition["name"],
            "resource_url": edition["url"],
        },
    )

    # ---- diff and change log ---------------------------------------------
    result = {"edition_date": date, "providers": len(institutions), "courses": len(courses)}

    if flagged:
        # Record the gap honestly rather than silently having none. A reader
        # comparing our record against the source deserves to know we hold this
        # edition but did not interpret the step into it.
        result["flagged"] = [f.check for v in movement for f in v.failures]
        archive.append_changes(
            [
                {
                    "kind": "edition_held_not_interpreted",
                    "level": "meta",
                    "register": "CRICOS",
                    "country": "Australia",
                    "old_edition": prev["edition_date"] if prev else None,
                    "new_edition": date,
                    "key": f"meta|{date}",
                    "name": f"CRICOS edition {date}",
                    "statement": (
                        f"The CRICOS edition published {date} is archived and can be inspected, but "
                        f"no change entries were derived from the step into it."
                    ),
                    "caveat": (
                        "Movement between this edition and the previous one exceeded the limits we "
                        "set for automatic interpretation, so a person needs to confirm what the "
                        "source actually did before we describe it. This says nothing about any "
                        "institution — it is a statement about our own confidence. Checks exceeded: "
                        + ", ".join(sorted({f.check for v in movement for f in v.failures}))
                    ),
                }
            ]
        )
        print(f"      FLAGGED — archived but not interpreted ({result['flagged']})", file=sys.stderr)
        return result

    if prev_inst is not None:
        prov_diff = diffmod.diff_editions(
            register="CRICOS",
            country="Australia",
            old_rows=prev_inst,
            new_rows=institutions,
            key_field=au.INSTITUTION_KEY,
            name_field=au.INSTITUTION_NAME,
            old_edition=prev["edition_date"],
            new_edition=date,
            watch_fields=au.PROVIDER_WATCH_FIELDS,
            persistent_id=True,
        )
        diffmod.assert_editorial_safety(prov_diff.changes)
        archive.append_changes(
            {**c.to_dict(), "level": "provider"} for c in prov_diff.changes
        )
        result["provider_changes"] = prov_diff.counts

        if prev_courses is not None:
            course_diff = diffmod.diff_editions(
                register="CRICOS course register",
                country="Australia",
                old_rows=prev_courses,
                new_rows=courses,
                key_field="_key",
                name_field=au.COURSE_NAME,
                old_edition=prev["edition_date"],
                new_edition=date,
                persistent_id=True,
            )
            diffmod.assert_editorial_safety(course_diff.changes)
            archive.append_changes(
                {**c.to_dict(), "level": "course"} for c in course_diff.changes
            )
            result["course_changes"] = course_diff.counts

            orphans = au.courses_removed_at_live_providers(prev_courses, courses, institutions)
            result["courses_removed_at_live_provider"] = len(orphans)
            if orphans:
                archive.append_changes(
                    {
                        "kind": "course_withdrawn_provider_still_listed",
                        "level": "course",
                        "register": "CRICOS course register",
                        "country": "Australia",
                        "old_edition": prev["edition_date"],
                        "new_edition": date,
                        "key": f"{o['provider_code']}|{o['course_code']}",
                        "name": o["course_name"],
                        "provider_code": o["provider_code"],
                        "provider_name": o["provider_name"],
                        "statement": (
                            f"The course “{o['course_name']}” (CRICOS course code {o['course_code']}) "
                            f"appears on the CRICOS edition published {prev['edition_date']} and does not "
                            f"appear on the edition published {date}. The provider, "
                            f"{o['provider_name']} (CRICOS provider code {o['provider_code']}), "
                            "remains listed on both editions."
                        ),
                        "caveat": (
                            "A course leaving the register is not evidence of wrongdoing by the provider. "
                            "Courses are withdrawn for many ordinary reasons, including low enrolment, "
                            "curriculum replacement, superseded training packages and voluntary withdrawal. "
                            "The register publishes a listing, not a cause. This entry is recorded because a "
                            "student enrolled on this course is affected even though the provider's own "
                            "standing is unchanged."
                        ),
                    }
                    for o in orphans
                )

    print(
        f"      providers={result['providers']} courses={result['courses']}"
        + (f" changes={result.get('provider_changes')}" if "provider_changes" in result else "")
        + (
            f" course-withdrawals-at-live-provider={result['courses_removed_at_live_provider']}"
            if "courses_removed_at_live_provider" in result
            else ""
        ),
        flush=True,
    )
    return result


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--backfill", action="store_true", help="ingest every edition we do not already hold")
    g.add_argument("--latest", action="store_true", help="ingest only editions newer than our latest")
    ap.add_argument("--limit", type=int, default=0, help="stop after N editions (for testing)")
    ap.add_argument(
        "--strict",
        action="store_true",
        default=None,
        help="abort the whole run on a sanity failure (default: strict for --latest, lenient for --backfill)",
    )
    ap.add_argument("--sleep", type=float, default=1.5, help="seconds between downloads, be polite")
    args = ap.parse_args()

    strict = args.strict if args.strict is not None else bool(args.latest)

    archive = Archive("au-cricos")
    held = set(archive.edition_dates())
    print(f"archive holds {len(held)} editions" + (f", latest {max(held)}" if held else ""))

    editions = au.list_editions()
    print(f"data.gov.au advertises {len(editions)} dated editions "
          f"({editions[0]['edition_date']} to {editions[-1]['edition_date']})")

    todo = [e for e in editions if e["edition_date"] not in held]
    if args.latest and held:
        newest = max(held)
        todo = [e for e in todo if e["edition_date"] > newest]
    if args.limit:
        todo = todo[: args.limit]

    if not todo:
        print("nothing to do — archive is current")
        return 0

    print(f"ingesting {len(todo)} edition(s)")
    results = []
    for i, e in enumerate(todo, 1):
        results.append(ingest_edition(e, archive, strict=strict))
        if i < len(todo):
            time.sleep(args.sleep)

    summary = {
        "ingested": len([r for r in results if not r.get("skipped")]),
        "skipped": len([r for r in results if r.get("skipped")]),
        "editions": results,
    }
    out = pathlib.Path("data/au-cricos/last_run.json")
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(summary, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(f"\ndone — {summary['ingested']} ingested, {summary['skipped']} skipped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
