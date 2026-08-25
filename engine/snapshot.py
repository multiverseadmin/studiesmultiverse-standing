"""
The archive.

Every edition we fetch is written to disk as a dated, hashed snapshot and
committed. The git history is the tamper-evident public record: each commit
carries a timestamp and a content hash that neither the site owner nor an
automated agent can quietly rewrite.

That matters for one specific moment. When an institution's lawyer asks "on
what date did your site say we were not listed, and what did the source say?",
the answer is a commit URL and an archived copy of the source edition — a far
stronger position than "our database says so".

Layout:

    data/<source>/editions/<YYYY-MM-DD>.json.gz   parsed rows, one per edition
    data/<source>/raw/<YYYY-MM-DD>.<ext>.gz       the source bytes as fetched
    data/<source>/current.json                    rolling pointer + metadata
    data/<source>/changes.jsonl                   append-only change log
"""

from __future__ import annotations

import dataclasses
import datetime as _dt
import gzip
import hashlib
import json
import pathlib
from typing import Any, Iterable, Sequence

ROOT = pathlib.Path(__file__).resolve().parent.parent
DATA = ROOT / "data"


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_rows(rows: Sequence[dict]) -> str:
    """Stable hash of parsed content, independent of source file formatting."""
    canon = json.dumps(rows, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(canon.encode("utf-8")).hexdigest()


@dataclasses.dataclass
class SourceMeta:
    """Provenance travels with every published byte. Non-negotiable."""

    source_id: str
    country: str
    register_name: str
    publisher: str
    source_url: str
    licence: str
    licence_url: str
    attribution: str
    # "mirror" = we republish rows verbatim (open licence confirmed)
    # "change-record" = we publish only dated change events and cite the source
    publication_layer: str = "mirror"
    language: str = "en"
    notes: str = ""

    def to_dict(self) -> dict:
        return dataclasses.asdict(self)


class Archive:
    def __init__(self, source_id: str, base: pathlib.Path | None = None):
        self.source_id = source_id
        self.base = (base or DATA) / source_id
        self.editions = self.base / "editions"
        self.raw = self.base / "raw"
        for p in (self.editions, self.raw):
            p.mkdir(parents=True, exist_ok=True)

    # ---- reading ---------------------------------------------------------

    def edition_dates(self) -> list[str]:
        return sorted(p.name.removesuffix(".json.gz") for p in self.editions.glob("*.json.gz"))

    def latest_date(self) -> str | None:
        dates = self.edition_dates()
        return dates[-1] if dates else None

    def load_edition(self, date: str) -> dict | None:
        p = self.editions / f"{date}.json.gz"
        if not p.exists():
            return None
        with gzip.open(p, "rt", encoding="utf-8") as fh:
            return json.load(fh)

    def load_latest(self) -> dict | None:
        d = self.latest_date()
        return self.load_edition(d) if d else None

    def previous_of(self, date: str) -> dict | None:
        dates = [d for d in self.edition_dates() if d < date]
        return self.load_edition(dates[-1]) if dates else None

    # ---- writing ---------------------------------------------------------

    def write_edition(
        self,
        *,
        edition_date: str,
        rows: Sequence[dict],
        meta: SourceMeta,
        source_date: str | None = None,
        raw_bytes: bytes | None = None,
        raw_ext: str = "bin",
        extra: dict | None = None,
    ) -> dict:
        content_hash = sha256_rows(list(rows))
        record = {
            "source_id": self.source_id,
            "edition_date": edition_date,
            "source_date": source_date,
            "fetched_at": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
            "row_count": len(rows),
            "content_sha256": content_hash,
            "raw_sha256": sha256_bytes(raw_bytes) if raw_bytes else None,
            "provenance": meta.to_dict(),
            "extra": extra or {},
            "rows": list(rows),
        }

        path = self.editions / f"{edition_date}.json.gz"
        with gzip.open(path, "wt", encoding="utf-8") as fh:
            json.dump(record, fh, ensure_ascii=False, separators=(",", ":"))

        if raw_bytes is not None:
            with gzip.open(self.raw / f"{edition_date}.{raw_ext}.gz", "wb") as fh:
                fh.write(raw_bytes)

        # Rolling pointer, without the row payload.
        pointer = {k: v for k, v in record.items() if k != "rows"}
        pointer["editions_held"] = len(self.edition_dates())
        pointer["earliest_edition"] = self.edition_dates()[0] if self.edition_dates() else edition_date
        (self.base / "current.json").write_text(
            json.dumps(pointer, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
        )
        return record

    def append_changes(self, entries: Iterable[dict]) -> int:
        path = self.base / "changes.jsonl"
        n = 0
        with path.open("a", encoding="utf-8") as fh:
            for e in entries:
                fh.write(json.dumps(e, ensure_ascii=False, separators=(",", ":")) + "\n")
                n += 1
        return n

    def read_changes(self, limit: int | None = None) -> list[dict]:
        path = self.base / "changes.jsonl"
        if not path.exists():
            return []
        with path.open(encoding="utf-8") as fh:
            rows = [json.loads(line) for line in fh if line.strip()]
        rows.sort(key=lambda r: (r.get("new_edition") or "", r.get("kind") or ""), reverse=True)
        return rows[:limit] if limit else rows


# ---------------------------------------------------------------------------
# Provenance for every source we are permitted to publish.
# Layer is enforced downstream: a "change-record" source never has its rows
# written to public/, only its dated change events.
# ---------------------------------------------------------------------------

SOURCES: dict[str, SourceMeta] = {
    "au-cricos": SourceMeta(
        source_id="au-cricos",
        country="Australia",
        register_name="Commonwealth Register of Institutions and Courses for Overseas Students (CRICOS)",
        publisher="Australian Government Department of Education",
        source_url="https://data.gov.au/data/dataset/cricos",
        licence="Creative Commons Attribution 2.5 Australia",
        licence_url="https://creativecommons.org/licenses/by/2.5/au/",
        attribution="Contains data from the Australian Government Department of Education, "
        "licensed under CC BY 2.5 AU.",
        publication_layer="mirror",
    ),
    "uk-sponsors": SourceMeta(
        source_id="uk-sponsors",
        country="United Kingdom",
        register_name="Register of licensed student sponsors",
        publisher="UK Home Office",
        source_url="https://www.gov.uk/government/publications/register-of-licensed-sponsors-students",
        licence="Open Government Licence v3.0",
        licence_url="https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/",
        attribution="Contains public sector information licensed under the Open Government Licence v3.0.",
        publication_layer="mirror",
    ),
    "deqar-heis": SourceMeta(
        source_id="deqar-heis",
        country="Europe (EHEA)",
        register_name="DEQAR — Database of External Quality Assurance Results",
        publisher="European Quality Assurance Register for Higher Education (EQAR)",
        source_url="https://www.eqar.eu/qa-results/download-data-sets/",
        licence="Open Data Commons Public Domain Dedication and Licence (PDDL)",
        licence_url="https://opendatacommons.org/licenses/pddl/",
        attribution="Contains data from DEQAR, published by EQAR under the PDDL.",
        publication_layer="mirror",
    ),
    "nl-ind": SourceMeta(
        source_id="nl-ind",
        country="Netherlands",
        register_name="Openbaar register erkende referenten — onderwijs",
        publisher="Immigratie- en Naturalisatiedienst (IND)",
        source_url="https://ind.nl/en/public-register-recognised-sponsors/public-register-study",
        licence="Reuse permitted with attribution (IND proclaimer)",
        licence_url="https://ind.nl/en/proclaimer",
        attribution="Source: Immigratie- en Naturalisatiedienst (IND), ind.nl.",
        publication_layer="mirror",
        language="nl",
    ),
    "pl-polon": SourceMeta(
        source_id="pl-polon",
        country="Poland",
        register_name="POL-on / RAD-on — institutions of the higher education and science system",
        publisher="Ministerstwo Nauki i Szkolnictwa Wyższego",
        source_url="https://radon.nauka.gov.pl/dane/instytucje-systemu-szkolnictwa-wyzszego-i-nauki",
        licence="Public sector information, reusable under the Polish open data act — CONFIRM BEFORE MIRRORING",
        licence_url="https://dane.gov.pl/",
        attribution="Source: RAD-on / POL-on, Ministerstwo Nauki i Szkolnictwa Wyższego.",
        publication_layer="change-record",  # upgraded to mirror once licence is read directly
        language="pl",
        notes="Licence not yet read from an authoritative page. Holds at change-record until it is.",
    ),
    "ca-dli": SourceMeta(
        source_id="ca-dli",
        country="Canada",
        register_name="Designated learning institutions list",
        publisher="Immigration, Refugees and Citizenship Canada (IRCC)",
        source_url="https://www.canada.ca/en/immigration-refugees-citizenship/services/study-canada/"
        "study-permit/prepare/designated-learning-institutions-list.html",
        licence="Crown copyright — commercial redistribution requires prior written permission",
        licence_url="https://www.canada.ca/en/transparency/terms.html",
        attribution="Source: Immigration, Refugees and Citizenship Canada.",
        publication_layer="change-record",
        notes="Verified 25 Aug 2026: canada.ca permits non-commercial reproduction with attribution "
        "but requires written permission for commercial redistribution. Permission request pending.",
    ),
}


def meta_for(source_id: str) -> SourceMeta:
    try:
        return SOURCES[source_id]
    except KeyError as exc:
        raise KeyError(
            f"No provenance registered for {source_id!r}. Every source must declare its licence, "
            "attribution and publication layer before it can be ingested."
        ) from exc
