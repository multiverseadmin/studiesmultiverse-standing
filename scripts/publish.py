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

    publication_layer == "statistics"
        We do not have COMMERCIAL republication rights, and this site carries
        advertising. So not even the change events are published in named form:
        the record is collapsed to dated counts before anything is written. No
        institution name, no register key, no per-row statement leaves this
        file for such a source — which also keeps those names out of the site's
        search index, its RSS and its API, because all three read what is
        written here.

        A count is our own measurement of the register, not a reproduction of
        it. It is also the only part of the record nobody else holds, so this
        is a narrower licence position and a stronger product at the same time.

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


_AGGREGATE_LABEL = {
    "removed": "no longer listed",
    "added": "newly listed",
    "renamed": "recorded under a new name",
    "modified": "recorded with a changed field",
    "course_withdrawn_provider_still_listed": "recorded with a course withdrawn while the provider stayed listed",
}


def _aggregate(changes: list[dict]) -> list[dict]:
    """
    Collapse a change record to dated counts, dropping every identifier.

    This is what `publication_layer == "statistics"` means in practice. The
    publisher reserves commercial republication rights and this site carries
    advertising, so nothing that identifies a row survives: name, previous
    name, register key and the per-row statement are all dropped before the
    file is written.

    What remains is how many rows changed, in which direction, between which
    two dated editions. That is a measurement of the register rather than a
    copy of it, and it is the part no one else holds - the official register
    publishes only today, so only an archive can count what left it.
    """
    buckets: dict[tuple, dict] = {}
    passthrough: list[dict] = []
    for ch in changes:
        level = str(ch.get("level") or "institution")
        if level != "institution":
            # Register-level notes - "this edition is held but was not
            # interpreted" - name no institution and carry no row from the
            # source. They are our own account of the archive's limits, and
            # dropping them would quietly remove the honesty they exist for.
            passthrough.append(ch)
            continue
        key = (ch.get("new_edition"), ch.get("old_edition"), str(ch.get("kind") or ""))
        bucket = buckets.setdefault(
            key,
            {
                "kind": key[2],
                "old_edition": key[1],
                "new_edition": key[0],
                "count": 0,
                "level": "aggregate",
                "name": None,
                "previous_name": None,
                "key": None,
                "fields": {},
                "caveat": ch.get("caveat"),
            },
        )
        bucket["count"] += 1

    out = []
    for bucket in buckets.values():
        n = bucket["count"]
        label = _AGGREGATE_LABEL.get(bucket["kind"], "recorded as changed")
        noun = "institution was" if n == 1 else "institutions were"
        bucket["summary"] = f"{n} {label}"
        bucket["statement"] = (
            f"{n} {noun} {label} on the edition published {bucket['new_edition']}, "
            f"compared with the edition published {bucket['old_edition']}."
        )
        bucket["withheld"] = (
            "Institution names are not published for this register. The publisher reserves "
            "commercial republication rights, so this site publishes dated counts only. "
            "The official register names them."
        )
        out.append(bucket)

    out.extend(passthrough)
    out.sort(key=lambda b: (str(b.get("new_edition") or ""), str(b.get("kind") or "")), reverse=True)
    return out


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
    change_count = len(changes)

    # Statistics sources are collapsed here, once, before any file is written.
    # Doing it at the single point where the record enters the public directory
    # is what makes the licence rule hold for changes.json, changes-full.json,
    # entities.json, the RSS feed and - because WordPress only ever reads these
    # files - the site's search index and API as well.
    if meta.publication_layer == "statistics":
        changes = _aggregate(changes)

    RECENT = 3_000
    recent = changes[:RECENT]

    _write(
        PUBLIC / source_id / "changes.json",
        _envelope(
            meta,
            recording_since=dates[0],
            latest_edition=dates[-1],
            count=change_count,
            aggregated_rows=(len(changes) if meta.publication_layer == "statistics" else None),
            published_count=len(recent),
            truncated=(
                None
                if len(recent) == len(changes)
                else (
                    f"This file carries the {len(recent)} most recent entries. The complete record of "
                    f"{change_count} is published at changes-full.json and in the repository's "
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
                  count=change_count, changes=changes),
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
            change_count=change_count,
            withheld=(
                None
                if meta.publication_layer != "statistics"
                else (
                    "Per-institution histories are not published for this register. The publisher "
                    "reserves commercial republication rights, so this site publishes dated counts "
                    f"only. The official register is at {meta.source_url}."
                )
            ),
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
        name = c.get("name")
        ET.SubElement(it, "title").text = (
            f"{kind}: {name}" if name else str(c.get("summary") or c.get("statement") or kind)
        )
        ET.SubElement(it, "description").text = f"{c.get('statement','')} {c.get('caveat','')}".strip()
        guid = ET.SubElement(it, "guid", isPermaLink="false")
        guid.text = f"{source_id}:{c.get('new_edition')}:{c.get('kind')}:{c.get('key') or 'aggregate'}"

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
                # Where the publisher itself puts the register.
                #
                # Every per-file payload already carries this under source.url,
                # but the country index did not, so the site had no publisher
                # link to offer and fell back to "endpoints", which are ours.
                # A Canadian reader clicking "published by them", or asking the
                # offer-letter check to confirm a DLI number, was sent to our
                # own register.json - deliberately empty for a change-record
                # source. Pointing someone at an empty file of ours instead of
                # IRCC's page is the opposite of what this site is for.
                "source_url": meta.source_url,
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
