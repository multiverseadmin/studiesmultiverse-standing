"""
The sanity gate.

This is the most important module in the project.

Automatic collection is easy. Automatic *interpretation* is where these systems
cause real harm. A timeout, a redirect, a changed page layout or a truncated
download all look identical to "300 institutions were removed today" — and a
naive pipeline would then publish a false, defamatory claim about real
universities, automatically, at 2am, with nobody awake to stop it.

The rule this module enforces:

    A quiet day is fine. A wrong day is not recoverable.

Every source must pass through `evaluate()` before anything is written to the
public archive. If the gate fails, the run aborts, the previous snapshot stands,
and a human is alerted. Failing closed is always correct here: publishing
nothing costs us a day of freshness, publishing garbage costs us the project.
"""

from __future__ import annotations

import dataclasses
import datetime as _dt
from typing import Any, Iterable, Sequence


class SanityError(Exception):
    """Raised when a fetched snapshot must not be published."""

    def __init__(self, source: str, failures: Sequence["Failure"]):
        self.source = source
        self.failures = list(failures)
        detail = "; ".join(f.message for f in self.failures)
        super().__init__(f"[{source}] sanity gate REFUSED publication: {detail}")


# Checks that mean "we could not read this file properly". A structural failure
# means the parse is untrustworthy, so the edition must not enter the archive.
STRUCTURAL_CHECKS = frozenset(
    {"min_rows", "require_columns", "key_integrity", "first_run", "source_date_regression"}
)

# Checks that mean "we read the file fine, but a lot moved". The data is sound;
# what is in doubt is our interpretation of it. These are handled differently —
# see the note on cascades in Verdict.movement_only.
MOVEMENT_CHECKS = frozenset(
    {"max_removed_fraction", "max_removed_absolute", "max_added_fraction", "max_churn_fraction"}
)


@dataclasses.dataclass(frozen=True)
class Failure:
    check: str
    message: str
    observed: Any = None
    threshold: Any = None

    @property
    def structural(self) -> bool:
        return self.check in STRUCTURAL_CHECKS


@dataclasses.dataclass(frozen=True)
class Thresholds:
    """
    Per-source limits. Set these from *measured* history, never from a guess.

    min_rows
        Absolute floor. Fewer rows than this means the fetch or the parse broke,
        not that the register emptied. Set well below the smallest edition ever
        observed.

    max_removed_fraction
        Ceiling on how much of the register may disappear in one edition before
        we refuse to publish and ask a human.

        Calibration note for Australia: the worst genuine month observed in
        five years of CRICOS editions was April to May 2026, which removed 35
        providers from about 1,556 — roughly 2.3%. A real event of that size
        must publish. A phantom deletion of 20% must not. 0.06 sits between
        them with room on both sides.

    max_added_fraction
        Same logic in the other direction. A register that suddenly doubles has
        usually been concatenated with itself or joined wrongly.

    max_churn_fraction
        Combined guard. Catches the case where a key column changed meaning and
        every row therefore looks both removed and added at once — the single
        most common way a diff pipeline goes catastrophically wrong.

    require_source_date_advance
        If the publisher stamps its own edition date, refuse to treat an
        unchanged stamp as a new edition. Prevents re-diffing a cached file and
        inventing changes.

    allow_first_run
        A first run has no baseline, so removal checks cannot apply. Everything
        else still does.
    """

    min_rows: int
    max_removed_fraction: float = 0.06
    max_added_fraction: float = 0.15
    max_churn_fraction: float = 0.20
    max_removed_absolute: int | None = None
    require_source_date_advance: bool = True
    require_columns: tuple[str, ...] = ()
    allow_first_run: bool = True
    # Some registers legitimately republish an identical file for days.
    allow_identical_content: bool = True


@dataclasses.dataclass
class Snapshot:
    """A parsed edition, ready to be gated."""

    source: str
    rows: list[dict]
    key_field: str
    source_date: _dt.date | None = None
    content_sha256: str | None = None
    fetched_at: _dt.datetime | None = None
    notes: dict = dataclasses.field(default_factory=dict)

    @property
    def keys(self) -> set[str]:
        return {str(r[self.key_field]) for r in self.rows if r.get(self.key_field) not in (None, "")}

    @property
    def columns(self) -> set[str]:
        return set(self.rows[0].keys()) if self.rows else set()


@dataclasses.dataclass
class Verdict:
    ok: bool
    source: str
    failures: list[Failure]
    warnings: list[str]
    stats: dict

    def raise_if_failed(self) -> "Verdict":
        if not self.ok:
            raise SanityError(self.source, self.failures)
        return self

    @property
    def structural(self) -> bool:
        """Any failure meaning the file could not be read properly."""
        return any(f.structural for f in self.failures)

    @property
    def movement_only(self) -> bool:
        """
        Failed, but only because a lot moved — the parse itself is sound.

        This distinction exists because of a real cascade observed on the first
        Australian backfill. The October 2022 edition removed 1,561 courses of
        26,935 (5.8%), which tripped a ceiling calibrated from 2026 data where
        monthly course churn runs under 1%. That edition was skipped and NOT
        archived — so the next edition compared itself against September 2022,
        and the next, and the next. The gap widened every month until every
        remaining edition failed too. One tripped threshold silently cost 46 of
        57 editions.

        The fix: an edition we could read stays in the archive even when its
        movement looks implausible, so the chain of comparisons never breaks.
        What we withhold is the interpretation — no change entries are published
        for that step, and it is flagged for a human to look at. An edition we
        could NOT read is still refused outright.
        """
        return bool(self.failures) and not self.structural


def evaluate(
    new: Snapshot,
    previous: Snapshot | None,
    thresholds: Thresholds,
) -> Verdict:
    """
    Decide whether `new` may be published.

    Returns a Verdict. Call .raise_if_failed() to abort the run, or inspect it
    to log a warning-only outcome.
    """
    failures: list[Failure] = []
    warnings: list[str] = []

    n_new = len(new.rows)
    stats: dict = {
        "rows": n_new,
        "unique_keys": len(new.keys),
        "source_date": new.source_date.isoformat() if new.source_date else None,
        "sha256": new.content_sha256,
    }

    # ---- structural checks (always apply, including first run) -------------

    if n_new < thresholds.min_rows:
        failures.append(
            Failure(
                "min_rows",
                f"parsed {n_new} rows, floor is {thresholds.min_rows} — "
                "treat as a broken fetch or parse, not an emptied register",
                n_new,
                thresholds.min_rows,
            )
        )

    if not new.rows:
        # Nothing further is meaningful.
        return Verdict(False, new.source, failures, warnings, stats)

    missing = [c for c in thresholds.require_columns if c not in new.columns]
    if missing:
        failures.append(
            Failure(
                "require_columns",
                f"expected columns absent from parse: {', '.join(missing)} — "
                "the publisher probably changed the file layout",
                sorted(new.columns),
                list(thresholds.require_columns),
            )
        )

    blank_keys = sum(1 for r in new.rows if r.get(new.key_field) in (None, ""))
    if blank_keys:
        frac = blank_keys / n_new
        msg = f"{blank_keys} of {n_new} rows have no value in key field '{new.key_field}'"
        if frac > 0.02:
            failures.append(Failure("key_integrity", msg + " — key column likely shifted", blank_keys))
        else:
            warnings.append(msg)

    dupes = n_new - len(new.keys) - blank_keys
    if dupes > 0:
        # Duplicates are normal in some registers (one row per campus or route).
        warnings.append(f"{dupes} duplicate key values — confirm the key is row-unique for this source")

    # ---- first run ---------------------------------------------------------

    if previous is None:
        if not thresholds.allow_first_run:
            failures.append(Failure("first_run", "no baseline snapshot and allow_first_run is False"))
        else:
            warnings.append("first run: no baseline, removal and churn checks skipped")
        stats.update(removed=0, added=0, removed_fraction=0.0, added_fraction=0.0)
        return Verdict(not failures, new.source, failures, warnings, stats)

    # ---- edition freshness -------------------------------------------------

    if thresholds.require_source_date_advance and new.source_date and previous.source_date:
        if new.source_date < previous.source_date:
            failures.append(
                Failure(
                    "source_date_regression",
                    f"source edition date went backwards: {previous.source_date} -> {new.source_date} — "
                    "we are probably looking at a stale or cached file",
                    str(new.source_date),
                    str(previous.source_date),
                )
            )
        elif new.source_date == previous.source_date:
            # Not a failure: it means no new edition. The caller should simply
            # record a no-change tick and not diff.
            warnings.append(
                f"source edition date unchanged ({new.source_date}) — no new edition to record"
            )
            stats["no_new_edition"] = True

    if (
        new.content_sha256
        and previous.content_sha256
        and new.content_sha256 == previous.content_sha256
    ):
        stats["identical_content"] = True
        if thresholds.allow_identical_content:
            warnings.append("byte-identical to previous snapshot — quiet day, nothing to publish")
        else:
            failures.append(Failure("identical_content", "content identical to previous snapshot"))

    # ---- movement checks ---------------------------------------------------

    prev_keys, new_keys = previous.keys, new.keys
    n_prev = len(prev_keys) or 1

    removed = prev_keys - new_keys
    added = new_keys - prev_keys

    removed_frac = len(removed) / n_prev
    added_frac = len(added) / n_prev
    churn_frac = (len(removed) + len(added)) / n_prev

    stats.update(
        previous_rows=len(previous.rows),
        removed=len(removed),
        added=len(added),
        removed_fraction=round(removed_frac, 5),
        added_fraction=round(added_frac, 5),
        churn_fraction=round(churn_frac, 5),
    )

    if removed_frac > thresholds.max_removed_fraction:
        failures.append(
            Failure(
                "max_removed_fraction",
                f"{len(removed)} of {n_prev} entries ({removed_frac:.1%}) disappeared in one edition, "
                f"ceiling is {thresholds.max_removed_fraction:.1%} — refusing to publish mass removals "
                "without a human confirming the source actually said this",
                round(removed_frac, 4),
                thresholds.max_removed_fraction,
            )
        )

    if thresholds.max_removed_absolute is not None and len(removed) > thresholds.max_removed_absolute:
        failures.append(
            Failure(
                "max_removed_absolute",
                f"{len(removed)} entries removed, absolute ceiling is {thresholds.max_removed_absolute}",
                len(removed),
                thresholds.max_removed_absolute,
            )
        )

    if added_frac > thresholds.max_added_fraction:
        failures.append(
            Failure(
                "max_added_fraction",
                f"{len(added)} of {n_prev} entries ({added_frac:.1%}) appeared in one edition, "
                f"ceiling is {thresholds.max_added_fraction:.1%} — check for a duplicated or "
                "concatenated source file",
                round(added_frac, 4),
                thresholds.max_added_fraction,
            )
        )

    if churn_frac > thresholds.max_churn_fraction:
        failures.append(
            Failure(
                "max_churn_fraction",
                f"combined churn {churn_frac:.1%} exceeds {thresholds.max_churn_fraction:.1%} — "
                "this is the signature of a key column changing meaning, which makes every row "
                "look simultaneously removed and added",
                round(churn_frac, 4),
                thresholds.max_churn_fraction,
            )
        )

    return Verdict(not failures, new.source, failures, warnings, stats)


def format_alert(verdict: Verdict) -> str:
    """Human-readable alert body for a failed gate."""
    lines = [
        f"SANITY GATE REFUSED PUBLICATION — {verdict.source}",
        "",
        "The previous snapshot still stands. Nothing was published.",
        "",
        "Failed checks:",
    ]
    for f in verdict.failures:
        lines.append(f"  - [{f.check}] {f.message}")
        if f.observed is not None:
            lines.append(f"      observed={f.observed!r} threshold={f.threshold!r}")
    if verdict.warnings:
        lines += ["", "Warnings:"] + [f"  - {w}" for w in verdict.warnings]
    lines += ["", "Stats:"] + [f"  {k}: {v}" for k, v in verdict.stats.items()]
    lines += [
        "",
        "What to do: open the source URL by hand and confirm what it actually says.",
        "If the change is real, raise the threshold for this source deliberately and",
        "record why in the commit message. Never widen a threshold just to make a",
        "run go green.",
    ]
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Per-source thresholds, calibrated from measured history.
# ---------------------------------------------------------------------------

THRESHOLDS: dict[str, Thresholds] = {
    # Measured 25 Aug 2026 across five editions plus a 12-month pair.
    # Worst genuine month: Apr->May 2026, 35 removed of 1,556 = 2.25%.
    "au-cricos-providers": Thresholds(
        min_rows=1200,
        max_removed_fraction=0.06,
        max_added_fraction=0.10,
        max_churn_fraction=0.15,
        require_columns=("CRICOS Provider Code", "Institution Name"),
    ),
    # Course churn is an order of magnitude larger than provider churn, and the
    # historical range is much wider than the recent one.
    #
    # First calibration (from four 2026 editions): monthly course removals ran
    # 81 to 280 on ~26,000 rows — 0.3% to 1.1% — so the ceiling was set at 5%.
    # That was measured on too short a window. The October 2022 edition removed
    # 1,561 of 26,935 (5.8%) in a single month, with providers moving normally
    # (2 removed, 13 added) and the file parsing cleanly — a genuine bulk course
    # cleanup, not a broken fetch. The 5% ceiling refused it and cascaded.
    #
    # Raised to 12% on that evidence, with the churn guard doing the real work.
    # This is a recalibration against a measurement, not a threshold widened to
    # make a red run go green — which the README rightly forbids.
    "au-cricos-courses": Thresholds(
        min_rows=20000,
        max_removed_fraction=0.12,
        max_added_fraction=0.15,
        max_churn_fraction=0.22,
        require_columns=("CRICOS Provider Code", "CRICOS Course Code", "Course Name"),
    ),
    # Measured: 1,317 -> 1,309 rows over 14 days, 26 recorded changes.
    #
    # require_columns said "Organisation Name" until the file was actually read.
    # The CSV calls it "Sponsor Name", so every UK edition would have been
    # refused on a missing column - the gate working correctly against a
    # threshold that was wrong. Taking a column list from a desk survey rather
    # than from the file is the same mistake as guessing a URL.
    #
    # min_rows lowered from 1000 to 800. The floor exists to catch a truncated
    # download, not a real contraction, and the UK has been tightening student
    # sponsorship hard. The exact row count is not yet measured - the file is
    # not reachable from the environment this was written in, and the preview
    # caps at 1,000 rows, reaching only "T" alphabetically - so this floor is
    # deliberately generous until a real run records the true count.
    "uk-sponsors": Thresholds(
        min_rows=800,
        max_removed_fraction=0.04,
        max_added_fraction=0.08,
        max_churn_fraction=0.10,
        require_columns=("Sponsor Name", "Status", "Route"),
        # GOV.UK republishes near-daily, often byte-identical.
        allow_identical_content=True,
    ),
    # Measured 25 Aug 2026: 1,445 rows live, 1,450 in the 31 Jul 2026 capture —
    # five gone in under a month.
    #
    # The ceilings are looser than the UK's because Canada's record is
    # reconstructed from Internet Archive captures, and that coverage is uneven:
    # 147 captures in 2025 against 19 in 2019. A step between two captures can
    # legitimately span months, so a change that would be implausible in one
    # month is ordinary across six. Anything beyond these limits is archived and
    # flagged rather than interpreted, which is the honest treatment of a gap —
    # we cannot tell a slow drift from a single event when nobody was watching
    # in between.
    "ca-dli": Thresholds(
        min_rows=900,
        max_removed_fraction=0.08,
        max_added_fraction=0.12,
        max_churn_fraction=0.18,
        require_columns=("dli", "name"),
        # canada.ca carries a dcterms.modified date, but it moves for any edit
        # to the page, not only to the table — so it is recorded, not trusted as
        # an edition boundary.
        require_source_date_advance=False,
    ),
    # 837 institutions over a JSON API with in-record status history.
    "pl-polon": Thresholds(
        min_rows=700,
        max_removed_fraction=0.03,
        max_added_fraction=0.06,
        max_churn_fraction=0.08,
        require_source_date_advance=False,
    ),
    # 104 rows. Small registers need absolute guards, not just fractions:
    # 3% of 104 is 3 rows, which is normal movement here.
    "nl-ind": Thresholds(
        min_rows=60,
        max_removed_fraction=0.15,
        max_added_fraction=0.25,
        max_churn_fraction=0.30,
        max_removed_absolute=12,
        require_source_date_advance=False,
    ),
    # 96 institutions as of 25 Aug 2026, accredited 2024-2026 under Japan's new
    # accreditation law and growing at roughly 35 a year.
    #
    # A small, GROWING register needs different guards from a mature one. Removals
    # should be near-zero — an accreditation granted last year rarely vanishes —
    # so the absolute guard is tight at 6. Additions are the normal event here and
    # can arrive in batches when a cohort is accredited together, so the added
    # fraction is deliberately generous.
    "jp-mext": Thresholds(
        min_rows=50,
        max_removed_fraction=0.10,
        max_removed_absolute=6,
        max_added_fraction=0.40,
        max_churn_fraction=0.45,
        require_columns=("certification_number", "jp_inst_name"),
        # The API publishes no edition date; the newest certification date is
        # recorded as a proxy but is not an edition boundary.
        require_source_date_advance=False,
    ),
    "deqar-heis": Thresholds(
        min_rows=3000,
        max_removed_fraction=0.03,
        max_added_fraction=0.10,
        max_churn_fraction=0.12,
        require_source_date_advance=False,
    ),
}


def thresholds_for(source: str) -> Thresholds:
    try:
        return THRESHOLDS[source]
    except KeyError as exc:  # pragma: no cover - guard against typos in workflows
        raise KeyError(
            f"No calibrated thresholds for source {source!r}. "
            "Measure the source's real change rate before adding it — never guess."
        ) from exc
