"""
Canada — IRCC designated learning institutions.

Verified 25 August 2026:
  * the full table is in the raw HTML response; no JavaScript needed
  * 1,445 rows covering 1,128 unique DLI numbers across twelve provinces
    and territories (Nunavut has no listed institution)
  * columns: Province, Institution, DLI #, City, Campus, Grad Program,
    Public/Private — the header row repeats "Public/Private" twice
  * the live page also carries three EMPTY table shells that JavaScript fills
    for filtered views, so a parser must select the table that has rows, never
    the first table
  * IRCC publishes no CSV, no JSON and no API. Requests for one have sat on the
    open-data portal since November 2020.

WHY CANADA IS WORTH THE TROUBLE

IRCC's own documentation says the list updates in real time when a school is
"added, suspended, or delisted" — but the suspension list itself is never
published. A row quietly disappearing is the only public signal that anything
happened, and the consequences are severe: an invalid study permit, and the
loss of post-graduation work permit eligibility.

THE LICENCE POSITION, AND WHAT IT MEANS FOR THIS FILE

canada.ca permits non-commercial reproduction with attribution but requires
written permission for commercial redistribution. This site carries advertising
and plans a paid tier, so it does not republish the register.

That constraint reaches into this module. `archive_projection()` reduces each
row to the handful of facts a change statement actually needs — who, where, and
the identifier — before anything is written to a public repository. We keep
enough to say "this institution in this city is no longer listed on the edition
of this date", and enough to prove we saw it, without publishing a copy of
IRCC's compilation.
"""

from __future__ import annotations

import datetime as _dt
import hashlib
import json as _json
import re
import time as _time
from typing import Iterable

import requests
from lxml import html as lxml_html

LIVE_URL = (
    "https://www.canada.ca/en/immigration-refugees-citizenship/services/study-canada/"
    "study-permit/prepare/designated-learning-institutions-list.html"
)

CDX_URL = "https://web.archive.org/cdx/search/cdx"
WAYBACK_RAW = "https://web.archive.org/web/{ts}id_/{url}"

USER_AGENT = (
    "studiesmultiverse-standing/1.0 (+https://studiesmultiverse.com/standing/methodology/) "
    "public-register archival bot"
)

KEY_FIELD = "key"
NAME_FIELD = "name"

# Header text -> our field name. Matched loosely because the wording has drifted
# across eight years of captures ("Institution" vs "DLI name", "DLI #" vs
# "DLI number", "Public/Private" vs "Public/private").
_HEADER_MAP = [
    (r"province|territor", "province"),
    (r"dli\s*(#|number|no)", "dli"),
    (r"dli\s*name|institution|school|establishment", "name"),
    (r"^city$|city|municipal", "city"),
    (r"campus", "campus"),
    (r"grad|post.?grad|pgwp|eligible program", "grad_program"),
    (r"public|private|sector", "sector"),
]


def _session() -> requests.Session:
    s = requests.Session()
    s.headers.update({"User-Agent": USER_AGENT})
    return s


def fetch_live(session: requests.Session | None = None) -> bytes:
    s = session or _session()
    r = s.get(LIVE_URL, timeout=90)
    r.raise_for_status()
    return r.content


# ---------------------------------------------------------------------------
# Parsing
# ---------------------------------------------------------------------------


def _map_headers(cells: list[str]) -> dict[int, str]:
    out: dict[int, str] = {}
    for i, cell in enumerate(cells):
        text = re.sub(r"\s+", " ", cell or "").strip().lower()
        if not text:
            continue
        for pattern, field in _HEADER_MAP:
            if re.search(pattern, text):
                # The header row repeats "Public/Private"; keep the first.
                if field not in out.values():
                    out[i] = field
                break
    return out


def _text(el) -> str:
    return re.sub(r"\s+", " ", (el.text_content() or "")).strip()


def parse(raw: bytes | str) -> list[dict]:
    """
    Parse every data table on the page into normalised rows.

    Structure-tolerant by necessity. The current page uses one combined table;
    captures before roughly mid-2025 split the same data across twelve
    per-province tables, and the empty JavaScript template tables must be
    ignored in both cases. Selecting by "has rows" rather than by position or
    id is what makes one parser work across the whole archive.
    """
    doc = lxml_html.fromstring(raw)
    rows: list[dict] = []

    for table in doc.xpath("//table"):
        body_rows = table.xpath(".//tbody/tr") or table.xpath(".//tr")
        if len(body_rows) < 5:
            continue  # template shell or a stray layout table

        header_cells = table.xpath(".//thead//th") or table.xpath(".//tr[1]/th")
        mapping = _map_headers([_text(h) for h in header_cells])
        if "dli" not in mapping.values() and "name" not in mapping.values():
            continue  # not the register

        # Province is sometimes only in the table's heading, not in the rows.
        table_province = ""
        prev = table.getprevious()
        hops = 0
        while prev is not None and hops < 4:
            if prev.tag in ("h2", "h3", "h4"):
                table_province = _text(prev)
                break
            prev = prev.getprevious()
            hops += 1

        for tr in body_rows:
            cells = tr.xpath("./td")
            if not cells:
                continue
            rec = {v: "" for v in dict(_HEADER_MAP).values()}
            for i, cell in enumerate(cells):
                field = mapping.get(i)
                if field:
                    rec[field] = _text(cell)
            if not rec.get("province"):
                rec["province"] = table_province
            if not (rec.get("dli") or rec.get("name")):
                continue
            rows.append(rec)

    for r in rows:
        r[KEY_FIELD] = make_key(r)
        r[NAME_FIELD] = r.get("name", "")

    return rows


def make_key(row: dict) -> str:
    """
    A DLI number identifies the institution; a campus identifies the listing.

    Keying on the number alone would collapse an institution's campuses into one
    row and hide a single campus being delisted — which is exactly the event a
    student at that campus needs to know about.
    """
    dli = (row.get("dli") or "").strip().upper()
    campus = re.sub(r"\s+", " ", (row.get("campus") or "").strip()).lower()
    if dli:
        return f"{dli}|{campus}" if campus else dli
    # No identifier: fall back to name + city, and accept the ambiguity.
    return f"{(row.get('name') or '').strip().lower()}|{(row.get('city') or '').strip().lower()}"


def source_date(raw: bytes | str) -> str | None:
    """
    IRCC stamps no edition date on the table, but canada.ca carries a
    "Date modified" value. It moves when the page is republished, which is the
    closest thing to an edition date this source offers.
    """
    text = raw.decode("utf-8", "ignore") if isinstance(raw, bytes) else raw
    for pattern in (
        # canada.ca's actual markup, confirmed on the live page: the date is in
        # a Dublin Core meta tag, not the visible "Date modified" block and not
        # a <time> element. Checked first because it is the one that is there.
        r'<meta[^>]+name=["\']dcterms\.modified["\'][^>]*content=["\'](\d{4}-\d{2}-\d{2})',
        r'<meta[^>]+content=["\'](\d{4}-\d{2}-\d{2})["\'][^>]*name=["\']dcterms\.modified["\']',
        r'dateModified["\']?\s*[:>]\s*["\']?(\d{4}-\d{2}-\d{2})',
        r'"dateModified"\s*:\s*"(\d{4}-\d{2}-\d{2})',
        r'<time[^>]*>\s*(\d{4}-\d{2}-\d{2})\s*</time>',
    ):
        m = re.search(pattern, text)
        if m:
            return m.group(1)
    return None


# ---------------------------------------------------------------------------
# What may be written to a public repository
# ---------------------------------------------------------------------------

# The facts a change statement needs, and nothing else.
ARCHIVE_FIELDS = ("key", "dli", "name", "city", "province")


def archive_projection(rows: Iterable[dict]) -> list[dict]:
    """
    Reduce rows to the reportable facts before they touch the public archive.

    Everything dropped here — campus detail, sector, programme eligibility — is
    still read at fetch time and still feeds the comparison in memory. What we
    decline to do is republish IRCC's compilation, which their terms reserve.

    The row hash preserves the ability to detect that *something* changed in a
    field we do not store, so a modification is still recorded even though its
    content is not.
    """
    out = []
    for r in rows:
        payload = "|".join(str(r.get(f, "")) for f in sorted(r) if f not in ("key", "name"))
        out.append(
            {
                **{f: r.get(f, "") for f in ARCHIVE_FIELDS},
                "row_sha1": hashlib.sha1(payload.encode("utf-8")).hexdigest()[:12],
            }
        )
    return out


# ---------------------------------------------------------------------------
# The archive nobody has assembled
# ---------------------------------------------------------------------------


def list_captures(
    session: requests.Session | None = None,
    limit: int = 3000,
    *,
    cache_path: str | None = None,
    attempts: int = 4,
    backoff: float = 8.0,
) -> list[str]:
    """
    Unique-content captures of the DLI page, oldest first.

    The brief recorded Canada as "completely unarchived". It is not: the
    Internet Archive holds roughly 500 unique-content captures from February
    2018 onward, and they contain the full table rather than a stub. Canada's
    record can therefore be reconstructed backwards rather than only
    accumulated forwards — which is the difference between a record that starts
    today and one that starts eight years ago.

    The listing is retried and then cached to disk.

    This call, not the per-capture fetch, is the fragile one: it asks the CDX
    index to scan eight years of captures, and it regularly times out. It
    crashed the first batched backfill outright — retries had been added to
    `fetch_capture` but not here, so an unhandled TimeoutError ended the run
    before a single edition was ingested.

    Caching matters for a second reason. A batched backfill invokes this script
    once per batch, and without a cache every batch would repeat the same
    expensive query. Sixteen identical scans of the archive index is both slow
    and rude to a service we are using for free.
    """
    if cache_path:
        cached = _read_cached_captures(cache_path)
        if cached:
            return cached

    s = session or _session()
    params = {
        "url": LIVE_URL.replace("https://", ""),
        "output": "json",
        "fl": "timestamp,digest,length",
        "filter": "statuscode:200",
        "collapse": "digest",
        "limit": str(limit),
    }

    last: Exception | None = None
    for attempt in range(1, attempts + 1):
        try:
            r = s.get(CDX_URL, params=params, timeout=240)
            if r.status_code in (429, 503, 504):
                raise requests.HTTPError(f"archive index returned {r.status_code}")
            r.raise_for_status()
            data = r.json()
            stamps = [row[0] for row in data[1:]] if data and len(data) > 1 else []
            if stamps and cache_path:
                _write_cached_captures(cache_path, stamps)
            return stamps
        except Exception as exc:  # noqa: BLE001
            last = exc
            if attempt < attempts:
                _time.sleep(backoff * attempt)

    raise RuntimeError(
        f"could not list archive captures after {attempts} attempts: {last}. "
        "The Internet Archive index is intermittently unavailable; try again later."
    )


def _read_cached_captures(path: str) -> list[str]:
    try:
        with open(path, encoding="utf-8") as fh:
            data = _json.load(fh)
        stamps = data.get("captures") or []
        return [str(s) for s in stamps]
    except Exception:  # noqa: BLE001 - a missing or unreadable cache is not an error
        return []


def _write_cached_captures(path: str, stamps: list[str]) -> None:
    try:
        import os

        os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
        with open(path, "w", encoding="utf-8") as fh:
            _json.dump(
                {"fetched": _dt.datetime.now(_dt.timezone.utc).isoformat(timespec="seconds"),
                 "count": len(stamps), "captures": stamps},
                fh,
            )
    except Exception:  # noqa: BLE001 - failing to cache must never fail the run
        pass




def fetch_capture(
    timestamp: str,
    session: requests.Session | None = None,
    *,
    attempts: int = 3,
    backoff: float = 5.0,
) -> bytes:
    """
    Fetch one archived capture, with retries.

    The Internet Archive is a free service under constant load and it shows:
    a long backfill reliably hits connect timeouts and dropped connections
    partway through. The first Canadian backfill lost roughly a hundred
    already-ingested editions that way. Retrying a few times with a widening
    pause turns most of those into successes, and the ones it cannot rescue are
    skipped rather than allowed to end the run.

    We are a guest here. The pause between attempts is as much politeness as
    it is patience.
    """
    s = session or _session()
    url = WAYBACK_RAW.format(ts=timestamp, url=LIVE_URL)
    last: Exception | None = None

    for attempt in range(1, attempts + 1):
        try:
            r = s.get(url, timeout=120)
            if r.status_code in (429, 503, 504):
                raise requests.HTTPError(f"archive returned {r.status_code}")
            r.raise_for_status()
            return r.content
        except Exception as exc:  # noqa: BLE001 - any transport failure is retryable
            last = exc
            if attempt < attempts:
                _time.sleep(backoff * attempt)

    raise RuntimeError(f"capture {timestamp} failed after {attempts} attempts: {last}")


def capture_date(timestamp: str) -> str:
    return _dt.datetime.strptime(timestamp[:8], "%Y%m%d").date().isoformat()
