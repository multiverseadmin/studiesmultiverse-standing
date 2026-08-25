"""
Japan — accredited Japanese-language education institutions (MEXT).

Verified directly on 25 August 2026, after a desk survey got this country
wrong. What that survey claimed — that Japan publishes a named list of schools
classified by student overstay rates, affecting visa document requirements —
could NOT be found on either the Immigration Services Agency's pages or MEXT's.
It may exist as something schools are told privately. It is not published in a
form anyone can cite, and this module makes no use of it.

What IS published, and is genuinely good:

  * an undocumented but clean JSON API behind the MEXT accreditation portal,
    returning every accredited institution in one call
  * `certification_number` — a persistent identifier, so renames prove out
  * `certification_date` — when accreditation was granted, which gives licence
    tenure for free
  * the institution's name in Japanese, kana AND romanised form, which matters
    enormously for an English-language site reporting on a Japanese register

Scale, stated honestly: 96 institutions as of this writing, all accredited
between 2024 and 2026, running at roughly 35 a year. Thin against a pure
cadence test.

The reason to carry Japan anyway is that this register is mid-transition. Under
the 2024 accreditation law, several hundred schools on the older notification
regime — the Immigration Services Agency lists them, PDF only, most recently
dated 7 August 2026 — must migrate to this scheme. Which of them arrive here,
and which quietly do not, is a dated and consequential story that nobody is
tracking, and this API hands it over cleanly.

LICENCE

MEXT's website terms of use state that published content may be freely
reproduced, publicly transmitted, translated and adapted, and say explicitly:
商用利用も可能です — commercial use is permitted. Attribution (出典) required.
Compatible with CC BY 4.0. This is a full mirror, no permission needed.
https://www.mext.go.jp/b_menu/1351168.htm
"""

from __future__ import annotations

import time as _time
from typing import Any

import requests

BASE = "https://www.nihongokyouiku.mext.go.jp"
LIST_PATH = "/api/publish/japanese-language-institution/get-jpLangInst-list"

USER_AGENT = (
    "studiesmultiverse-standing/1.0 (+https://studiesmultiverse.com/standing/methodology/) "
    "public-register archival bot"
)

KEY_FIELD = "certification_number"
NAME_FIELD = "jp_inst_name"

# Fields worth watching for change. Quoted verbatim, never interpreted.
WATCH_FIELDS = (
    "opening_status",
    "jp_inst_name_alpha",
    "inst_location_prefecture_code",
    "total_accommodation_capacity",
)


def _session(session: requests.Session | None = None) -> requests.Session:
    if session:
        return session
    s = requests.Session()
    s.headers.update({"User-Agent": USER_AGENT, "Accept": "application/json"})
    return s


def _params(per_page: int) -> list[tuple[str, str]]:
    """
    The parameter set the portal's own page sends.

    Captured from the live request rather than guessed. The endpoint is GET-only
    (POST returns 405) and returns an EMPTY array when called without this full
    set — so a bare request looks like a working API reporting no institutions,
    which is the most misleading failure a source can have. Every key below is
    required for the call to mean anything, including the empty ones.
    """
    p: list[tuple[str, str]] = [("freeWords", "")]
    # Institutions open now, and those with a confirmed opening date.
    p += [("openingStatus[]", "1"), ("openingStatus[]", "2")]
    for k in (
        "skillObjective", "skillGoalAbove", "skillGoalBelow", "studentFee",
        "trainingPeriodYearAbove", "trainingPeriodMonthAbove",
        "trainingPeriodYearBelow", "trainingPeriodMonthBelow", "startDate",
        "accommodationCapacityAbove", "accommodationCapacityBelow",
        "instOpeningYearFrom", "instOpeningYearTo", "courseName",
    ):
        p.append((k, ""))
    p += [("orderBy", "1"), ("order", "asc"), ("perPage", str(per_page))]
    return p


def fetch(
    session: requests.Session | None = None,
    *,
    per_page: int = 2000,
    attempts: int = 3,
    backoff: float = 5.0,
) -> dict[str, Any]:
    """
    Fetch the whole register in one page.

    perPage is deliberately far above the current 96 rows so the result is a
    single request with no pagination to walk. The returned payload still
    carries Laravel's `total` and `last_page`, which the caller checks — if the
    register ever outgrows one page, that check catches it rather than silently
    truncating the register.
    """
    s = _session(session)
    url = BASE + LIST_PATH
    last: Exception | None = None

    for attempt in range(1, attempts + 1):
        try:
            r = s.get(url, params=_params(per_page), timeout=90)
            if r.status_code in (429, 503, 504):
                raise requests.HTTPError(f"portal returned {r.status_code}")
            r.raise_for_status()
            payload = r.json()
            if not isinstance(payload, dict) or "data" not in payload:
                raise ValueError("unexpected payload shape — the API contract may have changed")
            return payload
        except Exception as exc:  # noqa: BLE001
            last = exc
            if attempt < attempts:
                _time.sleep(backoff * attempt)

    raise RuntimeError(f"MEXT portal fetch failed after {attempts} attempts: {last}")


def _clean(value: Any) -> str:
    """
    Trim and normalise whitespace, including full-width spaces.

    The romanised names arrive separated by U+3000 IDEOGRAPHIC SPACE, not the
    ASCII space — "Higashikawa　Training　College　of　International　Culture".
    Left alone that renders wrong in an English sentence and, worse, breaks
    search: a reader typing "Higashikawa Training College" would not match the
    institution they are looking at. Japanese and kana names keep their own
    characters untouched; only the spacing is normalised.
    """
    s = str(value if value is not None else "")
    s = s.replace("　", " ")
    return " ".join(s.split())


def parse(payload: dict[str, Any]) -> list[dict]:
    """
    Normalise the API rows.

    Values are carried through as strings exactly as published. Nothing here
    interprets `opening_status` into words — the register publishes a code, and
    turning a code into a judgement is precisely what this project does not do.
    """
    rows: list[dict] = []
    for r in payload.get("data") or []:
        if not isinstance(r, dict):
            continue
        key = _clean(r.get(KEY_FIELD))
        if not key:
            continue
        rows.append(
            {
                KEY_FIELD: key,
                NAME_FIELD: _clean(r.get("jp_inst_name")),
                "jp_inst_name_kana": _clean(r.get("jp_inst_name_kana")),
                "jp_inst_name_alpha": _clean(r.get("jp_inst_name_alpha")),
                "opening_status": _clean(r.get("opening_status")),
                "certification_date": _clean(r.get("certification_date")),
                "inst_location_prefecture_code": _clean(r.get("inst_location_prefecture_code")),
                "inst_location_post_code_address": _clean(r.get("inst_location_post_code_address")),
                "objective_type": _clean(r.get("objective_type")),
                "total_accommodation_capacity": _clean(r.get("total_accommodation_capacity")),
                "inst_location_homepage": _clean(r.get("inst_location_homepage")),
            }
        )
    return rows


def pagination_is_complete(payload: dict[str, Any], rows: list[dict]) -> tuple[bool, str]:
    """
    Did we actually get the whole register?

    A partial fetch that looks complete is the quiet way a register loses
    institutions: everything beyond page one would read as a mass removal on the
    next comparison. The sanity gate would catch a drop that large, but it is
    better to refuse here, where the reason is obvious.
    """
    total = payload.get("total")
    last_page = payload.get("last_page")
    if isinstance(total, int) and len(rows) < total:
        return False, f"received {len(rows)} rows but the API reports {total} in total"
    if isinstance(last_page, int) and last_page > 1:
        return False, f"the API reports {last_page} pages; this fetch read only the first"
    return True, ""


def source_date(payload: dict[str, Any], rows: list[dict]) -> str | None:
    """
    MEXT stamps no edition date on the API.

    The most recent certification date is the closest honest proxy: it moves
    when the register gains an institution, which is the change that matters
    here. It is recorded, not trusted as an edition boundary — the ingest does
    not require it to advance.
    """
    dates = sorted(d for d in (r.get("certification_date") or "" for r in rows) if d)
    return dates[-1][:10] if dates else None
