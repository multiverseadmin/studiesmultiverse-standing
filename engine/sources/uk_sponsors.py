"""
United Kingdom — register of licensed student sponsors (Home Office).

The richest of the four registers, and the only one that publishes a licence
*tier* and a compliance action alongside the listing.

WHAT THE SOURCE ACTUALLY PUBLISHES

Measured on the 25 August 2026 edition, from the published CSV:

  Sponsor Name | Town/City | Additional Locations | Sponsor Type | Status |
  Route | Immigration Compliance

Three of those columns carry information no aggregator republishes as a dated
series:

  * Status is a tier, not a boolean. "Student Sponsor - Track Record" (196 of
    the first 1,000 rows), plain "Student Sponsor" (743) and "Probationary
    Sponsor" (61) are three different standings. A sponsor moving down that
    ladder has not been removed from the register and would be invisible to
    anyone who only checks presence — but it is a material change for someone
    holding an offer.

  * Immigration Compliance is the Home Office saying, in public, that a sponsor
    is under action: 7 of the first 1,000 rows read "Subject To Action Plan".
    Rare, consequential, and dated.

  * Route splits Student from Child Student. A school can lose one and keep the
    other, which again is a real event that presence-checking cannot see.

We quote all three verbatim and never translate them into a verdict.

IDENTITY

There is no sponsor licence number in this file. The key is therefore composed,
and the composition was measured rather than assumed:

    Sponsor Name + Town/City + Route     0 collisions in 1,000 rows
    Sponsor Name + Town/City           283 collisions (the two routes)
    Sponsor Name + Route                 3 collisions (sponsors in two towns)

Because the identity is a name, a rename is indistinguishable from a departure
plus an arrival. That ambiguity is stated on every affected record rather than
resolved by guessing — see persistent_id=False in the ingest.

LICENCE

Open Government Licence v3.0. Reproduction and commercial use are permitted
with attribution, so this is a full mirror: rows are republished verbatim.
https://www.nationalarchives.gov.uk/doc/open-government-licence/version/3/
"""

from __future__ import annotations

import csv
import io
import re
import time as _time
from typing import Any

import requests

# The publication, not the file. The CSV's URL carries a content hash and the
# edition date, so it changes with every edition; only this page is stable.
CONTENT_API = "https://www.gov.uk/api/content/government/publications/register-of-licensed-sponsors-students"
PUBLICATION = "https://www.gov.uk/government/publications/register-of-licensed-sponsors-students"

USER_AGENT = (
    "studiesmultiverse-standing/1.0 (+https://studiesmultiverse.com/standing/methodology/) "
    "public-register archival bot"
)

KEY_FIELD = "key"
NAME_FIELD = "Sponsor Name"

# Quoted verbatim when they change. Route is part of the key, so it cannot
# change without becoming a different record.
WATCH_FIELDS = ("Status", "Immigration Compliance", "Sponsor Type", "Town/City", "Additional Locations")

# Header text -> our field name. GOV.UK has renamed these columns before
# ("Organisation Name" is the wording the rest of this project inherited from a
# desk survey; the file itself says "Sponsor Name"), so match loosely and let
# the sanity gate's require_columns catch a rename we did not anticipate.
_HEADER_MAP = [
    (r"sponsor\s*name|organisation\s*name|name", "Sponsor Name"),
    (r"town|city", "Town/City"),
    (r"additional\s*locations?", "Additional Locations"),
    (r"sponsor\s*type|type", "Sponsor Type"),
    (r"status|tier|rating", "Status"),
    (r"route|sub.?tier", "Route"),
    (r"immigration\s*compliance|compliance|action", "Immigration Compliance"),
]

FIELDS = tuple(field for _, field in _HEADER_MAP)


def _session(session: requests.Session | None = None) -> requests.Session:
    if session:
        return session
    s = requests.Session()
    s.headers.update({"User-Agent": USER_AGENT})
    return s


def discover(session: requests.Session | None = None, *, attempts: int = 3, backoff: float = 5.0) -> dict[str, Any]:
    """
    Find today's CSV through GOV.UK's content API.

    Scraping the HTML for a link would work until the page markup changed. The
    content API is a documented, stable contract that names the attachment, and
    it also carries public_updated_at — which is when the Home Office says the
    register moved, not when we happened to look.
    """
    s = _session(session)
    last: Exception | None = None

    for attempt in range(1, attempts + 1):
        try:
            r = s.get(CONTENT_API, timeout=60)
            r.raise_for_status()
            payload = r.json()
            attachments = (payload.get("details") or {}).get("attachments") or []
            csvs = [a for a in attachments if str(a.get("url", "")).lower().endswith(".csv")]
            if not csvs:
                raise ValueError(
                    "no CSV attachment on the publication — the Home Office may have "
                    "switched format (ODS/XLSX) or renamed the attachment"
                )
            att = csvs[0]
            url = str(att["url"])
            return {
                "url": url,
                "title": att.get("title"),
                "edition_date": _date_from_filename(url),
                "public_updated_at": payload.get("public_updated_at"),
            }
        except Exception as exc:  # noqa: BLE001
            last = exc
            if attempt < attempts:
                _time.sleep(backoff * attempt)

    raise RuntimeError(f"could not discover the UK register CSV after {attempts} attempts: {last}")


def _date_from_filename(url: str) -> str | None:
    """
    The edition date the publisher put in the filename.

    e.g. SP_-_Student_and_Child_Student_Web_Register_-_2026-08-25.csv

    This is the source's own statement of which edition this is, which makes it
    a better edition key than the day we downloaded it: re-running on a quiet
    day must not invent a second edition of the same file.
    """
    m = re.search(r"(\d{4}-\d{2}-\d{2})", url)
    return m.group(1) if m else None


def fetch(url: str, session: requests.Session | None = None, *, attempts: int = 3, backoff: float = 5.0) -> bytes:
    s = _session(session)
    last: Exception | None = None
    for attempt in range(1, attempts + 1):
        try:
            r = s.get(url, timeout=120)
            if r.status_code in (429, 503, 504):
                raise requests.HTTPError(f"assets host returned {r.status_code}")
            r.raise_for_status()
            return r.content
        except Exception as exc:  # noqa: BLE001
            last = exc
            if attempt < attempts:
                _time.sleep(backoff * attempt)
    raise RuntimeError(f"could not download the UK register CSV after {attempts} attempts: {last}")


def _map_headers(cells: list[str]) -> dict[int, str]:
    out: dict[int, str] = {}
    for i, cell in enumerate(cells):
        text = re.sub(r"\s+", " ", (cell or "")).strip().lower()
        if not text:
            continue
        for pattern, field in _HEADER_MAP:
            if re.search(pattern, text) and field not in out.values():
                out[i] = field
                break
    return out


def _clean(v: Any) -> str:
    return re.sub(r"\s+", " ", str(v if v is not None else "")).strip()


def parse(raw: bytes | str) -> list[dict]:
    """
    Parse the CSV into normalised rows.

    utf-8-sig because GOV.UK ships a byte-order mark, which would otherwise
    become part of the first header's text and break the column mapping in a way
    that looks like a renamed column.
    """
    text = raw.decode("utf-8-sig", "replace") if isinstance(raw, bytes) else raw
    reader = csv.reader(io.StringIO(text))

    try:
        header = next(reader)
    except StopIteration:
        return []

    mapping = _map_headers([_clean(h) for h in header])
    if "Sponsor Name" not in mapping.values():
        # Let the caller's gate report it; returning [] here keeps the failure
        # in one place rather than raising from a parser.
        return []

    rows: list[dict] = []
    for cells in reader:
        if not any(_clean(c) for c in cells):
            continue
        rec = {f: "" for f in FIELDS}
        for i, cell in enumerate(cells):
            field = mapping.get(i)
            if field:
                rec[field] = _clean(cell)
        if not rec["Sponsor Name"]:
            continue
        rec[KEY_FIELD] = make_key(rec)
        rows.append(rec)

    return rows


def make_key(row: dict) -> str:
    """
    Name + town + route.

    Measured against the 25 August 2026 edition: unique across 1,000 rows, where
    name+town collides 283 times and name+route collides 3 times. Route belongs
    in the identity because a sponsor can hold Student and lose Child Student;
    collapsing them would hide exactly that.
    """
    parts = [
        _clean(row.get("Sponsor Name")).lower(),
        _clean(row.get("Town/City")).lower(),
        _clean(row.get("Route")).lower(),
    ]
    return "|".join(parts)


def source_date(discovery: dict[str, Any]) -> str | None:
    """The edition date from the filename, falling back to GOV.UK's timestamp."""
    if discovery.get("edition_date"):
        return discovery["edition_date"]
    stamp = discovery.get("public_updated_at") or ""
    m = re.match(r"(\d{4}-\d{2}-\d{2})", str(stamp))
    return m.group(1) if m else None
