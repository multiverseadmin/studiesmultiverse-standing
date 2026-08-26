#!/usr/bin/env python3
"""
Publish the archive as static JSON and RSS.

The licence rule is enforced here, in code, not in a policy document that
someone forgets:

    publication_layer == "mirror"
        We hold an open licence or written permission. Rows are republished
        verbatim, with the licence and attribution attached to every file.

    publication_layer == "change-record"
        We do not have republication rights. Only dated change events are
        published — what the source said on which date, cited and linked. No
        row dump, ever. `emit_rows` physically cannot run for these sources.

Everything published here is static. WordPress renders it; WordPress does not
parse, diff, or query it. No server load, no cron, no database growth, and a
CDN can cache the lot.
"""

from __future__ import annotations

import argparse
import datetime as _dt
import json
import pathlib
import sys
import xml.etree.ElementTree as ET

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine.snapshot import Archive, SOURCES, meta_for

PUBLIC = pathlib.Path(__file__).resolve().parent.parent / "public"
SITE = "https://studiesmultiverse.com"
ARCHIVE = "https://raw.githubusercontent.com/multiverseadmin/studiesmultiverse-standing/main/public/"
OUR_LICENCE = "CC BY 4.0"
OUR_LICENCE_URL = "https://creativecommons.org/licenses/by/4.0/"


def _envelope(meta, **extra) -> dict:
    """Provenance travels with every file. This is not optional."""
    return {
        "publisher": "A.I.T. Multiverse Consulting Ltd, Nicosia, Cyprus",
        "site": SITE,
        "generated_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
        "our_licence": OUR_LICENCE,
        "our_licence_url": OUR_LICENCE_URL,
        "source": {
            "country": meta.country,
            "register": meta.register_name,
            "publisher": meta.publisher,
            "url": meta.source_url,
            "licence": meta.licence,
            "licence_url": meta.licence_url,
            "attribution": meta.attribution,
            "publication_layer": meta.publication_layer,
        },
        "caveat": (
            "A row appearing or disappearing between editions is not evidence of wrongdoing. "
            "Registers publish a status, not a cause. An entry can leave a register through "
            "withdrawal, merger, rename, voluntary surrender, corporate restructure, lapse at "
            "renewal, or a correction by the publisher, and the source does not tell us which."
        ),
        "corrections": f"{SITE}/standing/corrections/",
        **extra,
    }


def _write(path: pathlib.Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"  wrote {path.relative_to(PUBLIC.parent)}  ({path.stat().st_size // 1024} KB)")


def publish_source(source_id: str) -> None:
    meta = meta_for(source_id)
    archive = Archive(source_id)
    dates = archive.edition_dates()
    if not dates:
        print(f"  {source_id}: no editions held, nothing to publish")
        return

    latest = archive.load_latest()
    mirror = meta.publication_layer == "mirror"

    print(f"{source_id}  [{meta.publication_layer}]  {len(dates)} editions {dates[0]} .. {dates[-1]}")

    # ---- the register itself, mirror sources only -------------------------
    if mirror:
        _write(
            PUBLIC / source_id / "register.json",
            _envelope(
                meta,
                edition_date=latest["edition_date"],
                source_date=latest.get("source_date"),
                content_sha256=latest["content_sha256"],
                row_count=latest["row_count"],
                rows=latest["rows"],
            ),
        )
    else:
        _write(
            PUBLIC / source_id / "register.json",
            _envelope(
                meta,
                edition_date=latest["edition_date"],
                row_count=latest["row_count"],
                rows=[],
                withheld=(
                    "The rows of this register are not republished here. "
                    f"{meta.publisher} reserves republication rights, so this site publishes only "
                    "dated change events with citations back to the official source. "
                    f"The official register is at {meta.source_url}."
                ),
            ),
        )

    # ---- the change record, every source ----------------------------------
    # ---- courses, where the source has them --------------------------------
    #
    # Without this the offer-letter check cannot do the one thing that makes it
    # worth having. register.json carries institutions; the courses live in the
    # edition's extra payload and were never published, so every CRICOS course
    # code a student pasted came back "not found" — including valid ones.
    #
    # Only the fields needed to answer "is this course real, and is it
    # registered to this provider" are published. The full course rows are in
    # the archive for anyone who wants them.
    courses = ( latest.get( "extra", {} ) or {} ).get( "courses" ) if mirror else None
    if courses:
        compact = [
            {
                "provider_code": c.get( "CRICOS Provider Code", "" ),
                "course_code": c.get( "CRICOS Course Code", "" ),
                "course_name": c.get( "Course Name", "" ),
                "level": c.get( "Course Level", "" ),
                "provider_name": c.get( "Institution Name", "" ),
            }
            for c in courses
            if c.get( "CRICOS Course Code" )
        ]
        _write(
            PUBLIC / source_id / "courses.json",
            _envelope(
                meta,
                edition_date=latest["edition_date"],
                source_date=latest.get("source_date"),
                count=len(compact),
                note="Course-level listings for the current edition, published so that a course code on an "
                     "offer letter can be checked against the register — including whether it is registered "
                     "to the provider named on the letter.",
                courses=compact,
            ),
        )

    # ---- the change record, in three shapes -------------------------------
    #
    # The full Australian backfill produced 42,521 changes. Written as one file
    # with every statement and caveat inline, that is 32 MB — which WordPress
    # would have to download and json_decode before it could render a page. A
    # register that is too heavy to read is not a register.
    #
    # So the same record is published three ways, each for a different consumer:
    #
    #   changes.json       the most recent entries, in full. What the site
    #                      renders. Small enough to parse on every request.
    #   entities.json      every change, compacted to the fields needed to
    #                      answer "what happened to THIS institution" — no
    #                      prose, because statements and caveats are generated
    #                      from the kind and can be rebuilt on render.
    #   changes-full.json  everything, in full, for API consumers and anyone
    #                      auditing the record. Never fetched by the site.
    #
    # The prose is the bulk: the caveat text alone repeats across tens of
    # thousands of entries. Hoisting it into a per-kind lookup is what makes
    # entities.json roughly a twentieth the size of the full file.
    changes = archive.read_changes()
    RECENT = 3_000
    recent = changes[:RECENT]

    _write(
        PUBLIC / source_id / "changes.json",
        _envelope(
            meta,
            recording_since=dates[0],
            latest_edition=dates[-1],
            count=len(changes),
            published_count=len(recent),
            truncated=(
                None
                if len(recent) == len(changes)
                else (
                    f"This file carries the {len(recent)} most recent entries. The complete record of "
                    f"{len(changes)} is published at changes-full.json and in the repository's "
                    f"changes.jsonl — nothing is discarded."
                )
            ),
            full_record=f"{SITE}/standing/{source_id}/changes-full.json",
            changes=recent,
        ),
    )

    _write(
        PUBLIC / source_id / "changes-full.json",
        _envelope(meta, recording_since=dates[0], latest_edition=dates[-1],
                  count=len(changes), changes=changes),
    )

    # Compact per-institution history.
    entities: dict[str, list] = {}
    caveats: dict[str, str] = {}
    for ch in changes:
        key = str(ch.get("key") or "")
        if not key:
            continue
        kind = str(ch.get("kind") or "")
        if kind and kind not in caveats and ch.get("caveat"):
            caveats[kind] = str(ch["caveat"])
        entities.setdefault(key, []).append(
            [
                kind,
                ch.get("old_edition"),
                ch.get("new_edition"),
                ch.get("name"),
                ch.get("previous_name"),
            ]
        )

    _write(
        PUBLIC / source_id / "entities.json",
        _envelope(
            meta,
            recording_since=dates[0],
            latest_edition=dates[-1],
            entity_count=len(entities),
            change_count=len(changes),
            schema=["kind", "old_edition", "new_edition", "name", "previous_name"],
            caveats=caveats,
            entities=entities,
        ),
    )

    # ---- the archive index — the tamper-evident record ---------------------
    editions = []
    for d in dates:
        rec = archive.load_edition(d)
        editions.append(
            {
                "edition_date": d,
                "source_date": rec.get("source_date"),
                "row_count": rec["row_count"],
                "content_sha256": rec["content_sha256"],
                "raw_sha256": rec.get("raw_sha256"),
                "fetched_at": rec.get("fetched_at"),
            }
        )
    _write(PUBLIC / source_id / "archive.json", _envelope(meta, editions=editions))

    _write_rss(source_id, meta, changes[:100])


def _write_rss(source_id: str, meta, changes: list[dict]) -> None:
    """
    RSS first, email later.

    A student who applied to three institutions has a real reason to subscribe
    to those three rows. RSS is free, instant, and carries no deliverability
    risk — which matters, because this portfolio has a history of mail problems.
    """
    rss = ET.Element("rss", version="2.0", attrib={"xmlns:atom": "http://www.w3.org/2005/Atom"})
    ch = ET.SubElement(rss, "channel")
    ET.SubElement(ch, "title").text = f"{meta.country} — {meta.register_name}: recorded changes"
    ET.SubElement(ch, "link").text = f"{SITE}/standing/{meta.country.lower().replace(' ', '-')}/"
    ET.SubElement(ch, "description").text = (
        f"Dated changes recorded against the {meta.register_name}, published by {meta.publisher}. "
        "A row appearing or disappearing is not evidence of wrongdoing."
    )
    ET.SubElement(ch, "language").text = "en"
    ET.SubElement(ch, "lastBuildDate").text = _dt.datetime.now(_dt.timezone.utc).strftime(
        "%a, %d %b %Y %H:%M:%S +0000"
    )

    for c in changes:
        it = ET.SubElement(ch, "item")
        kind = {
            "removed": "No longer listed",
            "added": "Newly listed",
            "renamed": "Name changed",
            "modified": "Record changed",
            "course_withdrawn_provider_still_listed": "Course withdrawn, provider still listed",
        }.get(c.get("kind", ""), "Change")
        ET.SubElement(it, "title").text = f"{kind}: {c.get('name', '')}"
        ET.SubElement(it, "description").text = f"{c.get('statement','')} {c.get('caveat','')}".strip()
        guid = ET.SubElement(it, "guid", isPermaLink="false")
        guid.text = f"{source_id}:{c.get('new_edition')}:{c.get('kind')}:{c.get('key')}"

    path = PUBLIC / source_id / "changes.xml"
    path.parent.mkdir(parents=True, exist_ok=True)
    ET.ElementTree(rss).write(path, encoding="utf-8", xml_declaration=True)
    print(f"  wrote {path.relative_to(PUBLIC.parent)}")


def publish_index() -> None:
    """The cross-country front door, plus llms.txt for AI discovery."""
    countries = []
    for sid, meta in SOURCES.items():
        a = Archive(sid)
        dates = a.edition_dates()
        if not dates:
            continue
        countries.append(
            {
                "source_id": sid,
                "country": meta.country,
                "register": meta.register_name,
                "publisher": meta.publisher,
                "publication_layer": meta.publication_layer,
                "licence": meta.licence,
                "editions_held": len(dates),
                "recording_since": dates[0],
                "latest_edition": dates[-1],
                "changes_recorded": len(a.read_changes()),
                "endpoints": {
                    "register": f"{ARCHIVE}{sid}/register.json",
                    "changes": f"{ARCHIVE}{sid}/changes.json",
                    "archive": f"{ARCHIVE}{sid}/archive.json",
                    "feed": f"{ARCHIVE}{sid}/changes.xml",
                },
            }
        )

    payload = {
        "name": "studiesmultiverse Standing Register",
        "description": (
            "The worldwide record of which institutions are officially permitted to enrol "
            "international students — what the official registers say, what they used to say, "
            "and what it means for the student."
        ),
        "publisher": "A.I.T. Multiverse Consulting Ltd, Nicosia, Cyprus",
        "our_licence": OUR_LICENCE,
        "our_licence_url": OUR_LICENCE_URL,
        "generated_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
        "countries": sorted(countries, key=lambda c: c["country"]),
    }
    _write(PUBLIC / "standing.json", payload)

    lines = [
        "# studiesmultiverse — Standing Register",
        "",
        "> The worldwide record of which institutions are officially permitted to enrol",
        "> international students, and which have quietly left those registers.",
        "",
        "This site earns nothing from where you apply. It carries no institution referral fees,",
        "no agent commissions, no paid inclusion and no paid removal.",
        "",
        "## What is here",
        "",
    ]
    for c in payload["countries"]:
        lines.append(
            f"- **{c['country']}** — {c['register']} ({c['publisher']}). "
            f"{c['editions_held']} editions held since {c['recording_since']}; "
            f"{c['changes_recorded']} changes recorded. "
            f"Data: {c['endpoints']['changes']}"
        )
    lines += [
        "",
        "## How to cite",
        "",
        "Every change entry carries the edition dates it was derived from and a SHA-256 of the",
        "archived source edition. Cite the edition date, not the date you read the page.",
        "",
        "## What we will not say",
        "",
        "A row disappearing from a register is not evidence of wrongdoing. We never write that an",
        "institution was revoked, banned, or shut down. We write that it is no longer listed on the",
        "edition published on a given date, and we name the alternatives — withdrawal, merger,",
        "rename, voluntary surrender, lapse at renewal, or publisher correction — in the same breath.",
        "",
        f"Corrections: {SITE}/standing/corrections/",
        "",
    ]
    (PUBLIC / "llms.txt").write_text("\n".join(lines), encoding="utf-8")
    print("  wrote public/llms.txt")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--source", help="publish one source (default: all held)")
    args = ap.parse_args()

    targets = [args.source] if args.source else list(SOURCES)
    for sid in targets:
        publish_source(sid)
    publish_index()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
