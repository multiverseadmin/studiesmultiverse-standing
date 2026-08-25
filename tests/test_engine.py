"""
Tests for the two modules that can cause real harm if they are wrong: the
sanity gate and the diff.

These use synthetic data calibrated to the real measurements taken on
25 August 2026, so a regression that would have published a false mass-removal
fails the build.
"""

from __future__ import annotations

import datetime as dt
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))

from engine import diff as diffmod
from engine import sanity


def rows(n, start=0, name="Provider"):
    return [
        {"CRICOS Provider Code": f"{i:06d}A", "Institution Name": f"{name} {i}", "Institution Type": "University"}
        for i in range(start, start + n)
    ]


def snap(r, date="2026-07-01", sha="x"):
    return sanity.Snapshot(
        source="au-cricos-providers",
        rows=r,
        key_field="CRICOS Provider Code",
        source_date=dt.date.fromisoformat(date),
        content_sha256=sha,
    )


T = sanity.thresholds_for("au-cricos-providers")


def test_normal_month_passes():
    """Jun->Jul 2026: 1,544 -> 1,545, 5 removed, 6 added. Must publish."""
    old = rows(1544)
    new = rows(1539, start=5) + rows(6, start=2000, name="New")
    v = sanity.evaluate(snap(new), snap(old, "2026-06-01", "y"), T)
    assert v.ok, v.failures
    assert v.stats["removed"] == 5
    assert v.stats["added"] == 6


def test_worst_real_month_still_passes():
    """
    Apr->May 2026 removed 35 providers of 1,556 — 2.25%. This is the
    calibration point. A genuine event of this size MUST publish, or the gate
    is useless.
    """
    old = rows(1556)
    new = rows(1521, start=35) + rows(12, start=3000, name="New")
    v = sanity.evaluate(snap(new), snap(old, "2026-04-01", "y"), T)
    assert v.ok, f"the gate suppressed a real event: {v.failures}"


def test_phantom_mass_removal_is_refused():
    """A truncated download looks exactly like 300 institutions vanishing."""
    old = rows(1550)
    new = rows(1250)
    v = sanity.evaluate(snap(new), snap(old, "2026-06-01", "y"), T)
    assert not v.ok
    assert any(f.check == "max_removed_fraction" for f in v.failures)


def test_truncated_fetch_hits_the_floor():
    v = sanity.evaluate(snap(rows(40)), snap(rows(1550), "2026-06-01", "y"), T)
    assert not v.ok
    assert any(f.check == "min_rows" for f in v.failures)


def test_key_column_shift_is_caught_as_churn():
    """
    The nastiest failure: a column moves, so every key changes, and every row
    looks simultaneously removed and added.
    """
    old = rows(1500)
    new = [{**r, "CRICOS Provider Code": r["Institution Name"]} for r in old]
    v = sanity.evaluate(snap(new), snap(old, "2026-06-01", "y"), T)
    assert not v.ok
    assert any(f.check in ("max_churn_fraction", "max_removed_fraction") for f in v.failures)


def test_layout_change_caught_by_required_columns():
    bad = [{"Provider": "x", "Name": "y"} for _ in range(1500)]
    s = sanity.Snapshot(source="au-cricos-providers", rows=bad, key_field="CRICOS Provider Code")
    v = sanity.evaluate(s, snap(rows(1500), "2026-06-01", "y"), T)
    assert not v.ok
    assert any(f.check == "require_columns" for f in v.failures)


def test_stale_file_does_not_produce_a_new_edition():
    old = rows(1544)
    v = sanity.evaluate(snap(rows(1540), "2026-06-01"), snap(old, "2026-06-01", "y"), T)
    assert v.stats.get("no_new_edition") is True


def test_source_date_going_backwards_is_refused():
    v = sanity.evaluate(snap(rows(1540), "2026-05-01"), snap(rows(1544), "2026-06-01", "y"), T)
    assert not v.ok
    assert any(f.check == "source_date_regression" for f in v.failures)


def test_first_run_is_allowed_but_flagged():
    v = sanity.evaluate(snap(rows(1500)), None, T)
    assert v.ok
    assert any("first run" in w for w in v.warnings)


def test_small_register_uses_absolute_guard():
    """104-row Dutch register: 3% is 3 rows, which is ordinary movement."""
    t = sanity.thresholds_for("nl-ind")
    mk = lambda n, s=0: [{"kvk": f"{i:08d}", "name": f"Org {i}"} for i in range(s, s + n)]
    s = lambda r: sanity.Snapshot(source="nl-ind", rows=r, key_field="kvk")
    assert sanity.evaluate(s(mk(101, 3)), s(mk(104)), t).ok        # 3 gone, fine
    assert not sanity.evaluate(s(mk(84, 20)), s(mk(104)), t).ok    # 20 gone, refuse


# ---------------------------------------------------------------------------
# Diff and editorial safety
# ---------------------------------------------------------------------------


def test_rename_is_proved_not_reported_as_removal():
    """
    Real case, CRICOS 02599C: Australian Academy of Commerce Pty Ltd became
    King's Own International College between the March and April 2026 editions.
    The provider code is unchanged, so this is a rename — and must never be
    counted as a disappearance.
    """
    old = [{"CRICOS Provider Code": "02599C", "Institution Name": "Australian Academy of Commerce Pty Ltd"}]
    new = [{"CRICOS Provider Code": "02599C", "Institution Name": "King's Own International College"}]
    d = diffmod.diff_editions(
        register="CRICOS", country="Australia",
        old_rows=old, new_rows=new,
        key_field="CRICOS Provider Code", name_field="Institution Name",
        old_edition="2026-03-02", new_edition="2026-04-01",
        persistent_id=True,
    )
    assert d.counts["renamed"] == 1
    assert d.counts["removed"] == 0
    assert d.counts["added"] == 0
    assert "identifier 02599C is unchanged" in d.changes[0].statement


def test_disappearance_names_the_alternatives():
    old = [{"CRICOS Provider Code": "99999Z", "Institution Name": "Some College"}]
    d = diffmod.diff_editions(
        register="CRICOS", country="Australia",
        old_rows=old, new_rows=[],
        key_field="CRICOS Provider Code", name_field="Institution Name",
        old_edition="2026-06-01", new_edition="2026-07-01",
        persistent_id=True,
    )
    c = d.changes[0]
    assert c.kind == "removed"
    assert "No longer listed" in c.statement
    for alternative in ("merger", "rename", "voluntary surrender", "correction"):
        assert alternative in c.caveat


def test_no_persistent_id_keeps_renames_ambiguous():
    """The UK register has no ID column. Ambiguity must be stated, not resolved."""
    old = [{"name": "St Marys College", "town": "Leeds"}]
    new = [{"name": "St Mary's College", "town": "Leeds"}]
    d = diffmod.diff_editions(
        register="register of licensed student sponsors", country="United Kingdom",
        old_rows=old, new_rows=new, key_field="name", name_field="name",
        old_edition="2026-08-11", new_edition="2026-08-25",
        persistent_id=False,
    )
    removed = [c for c in d.changes if c.kind == "removed"][0]
    assert "cannot tell" in removed.caveat
    assert d.counts["renamed"] == 0


def test_forbidden_vocabulary_fails_the_build():
    bad = diffmod.Change(
        kind="removed", key="k", register="r", country="c",
        old_edition="a", new_edition="b", name="n",
        statement="Its licence was revoked.",
    )
    try:
        diffmod.assert_editorial_safety([bad])
    except AssertionError as e:
        assert "revoked" in str(e)
    else:
        raise AssertionError("editorial guard failed to fire")


def test_compliance_flags_are_quoted_verbatim():
    old = [{"k": "1", "n": "X College", "compliance": ""}]
    new = [{"k": "1", "n": "X College", "compliance": "Subject To Action Plan"}]
    d = diffmod.diff_editions(
        register="register of licensed student sponsors", country="United Kingdom",
        old_rows=old, new_rows=new, key_field="k", name_field="n",
        old_edition="2026-08-11", new_edition="2026-08-25",
        watch_fields=("compliance",), persistent_id=True,
    )
    m = [c for c in d.changes if c.kind == "modified"][0]
    assert "“Subject To Action Plan”" in m.statement
    diffmod.assert_editorial_safety(d.changes)
