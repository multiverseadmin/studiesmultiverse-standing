"""
Edition-to-edition diffing, with renames proved rather than inferred.

The editorial problem this module exists to solve:

    A revoked licence, a suspended one, a voluntarily surrendered one, a merger
    and a rename all look identical in a register: the row simply disappears.

Where a source publishes a persistent identifier — a CRICOS provider code, a
DLI number, a KvK number, a POL-on UUID, a SEVIS school ID — we can do better
than guess. If the identifier survives and the name changes, that is a rename,
demonstrably. If the identifier itself is gone, we say only that it is no
longer listed, and we name every alternative explanation in the same breath.

Nothing in this module ever emits the words "revoked", "banned", "lost its
licence" or "shut down". That vocabulary is not ours to use.
"""

from __future__ import annotations

import dataclasses
import datetime as _dt
from typing import Any, Iterable, Sequence

# Wording used wherever an entry disappears. Deliberately verbose, deliberately
# non-committal, and always accompanied by the alternatives.
DISAPPEARANCE_TEMPLATE = (
    "No longer listed on the {register} published {new_date}. "
    "It appeared on the edition published {old_date}."
)

DISAPPEARANCE_ALTERNATIVES = (
    "A row disappearing from a register is not by itself evidence of wrongdoing. "
    "It can mean the institution's approval was withdrawn, but it can equally mean "
    "a voluntary surrender, a merger, a rename, a corporate restructure, a lapse at "
    "renewal, or a correction by the publisher. The source publishes a status, not a "
    "cause, and we cannot tell which applies."
)

# Never let these reach a rendered page from this module.
FORBIDDEN_VERBS = ("revoked", "banned", "shut down", "lost its licence", "lost its license", "struck off")


@dataclasses.dataclass(frozen=True)
class Change:
    kind: str  # "added" | "removed" | "renamed" | "modified"
    key: str
    register: str
    country: str
    old_edition: str | None
    new_edition: str
    name: str
    previous_name: str | None = None
    fields: dict[str, tuple[Any, Any]] = dataclasses.field(default_factory=dict)
    statement: str = ""
    caveat: str = ""

    def to_dict(self) -> dict:
        d = dataclasses.asdict(self)
        d["fields"] = {k: {"from": v[0], "to": v[1]} for k, v in self.fields.items()}
        return d


@dataclasses.dataclass
class DiffResult:
    register: str
    country: str
    old_edition: str | None
    new_edition: str
    changes: list[Change]
    counts: dict[str, int]
    # Removals whose identifier reappeared under a different name. Reported
    # separately so they are never counted as disappearances.
    renames: list[Change]

    def to_dict(self) -> dict:
        return {
            "register": self.register,
            "country": self.country,
            "old_edition": self.old_edition,
            "new_edition": self.new_edition,
            "counts": self.counts,
            "changes": [c.to_dict() for c in self.changes],
        }


def _norm(value: Any) -> str:
    return "" if value is None else str(value).strip()


def _fold(name: str) -> str:
    """Loose comparison form for names — case, spacing and common suffixes."""
    s = _norm(name).lower()
    for junk in (" pty ltd", " pty. ltd.", " ltd", " limited", " inc.", " inc", " plc", "."):
        s = s.replace(junk, " ")
    return " ".join(s.split())


def diff_editions(
    *,
    register: str,
    country: str,
    old_rows: Sequence[dict] | None,
    new_rows: Sequence[dict],
    key_field: str,
    name_field: str,
    old_edition: str | None,
    new_edition: str,
    watch_fields: Iterable[str] = (),
    persistent_id: bool = True,
) -> DiffResult:
    """
    Compare two editions of one register.

    key_field
        The persistent identifier if the source has one, otherwise the name.
    persistent_id
        Set False where the key is only a name (the UK register has no ID
        column). Renames then stay genuinely ambiguous and are reported as a
        removal plus an addition, with the ambiguity stated rather than hidden.
    """
    watch = tuple(watch_fields)
    new_by_key = {_norm(r.get(key_field)): r for r in new_rows if _norm(r.get(key_field))}

    if old_rows is None:
        counts = {"added": 0, "removed": 0, "renamed": 0, "modified": 0, "first_edition_rows": len(new_by_key)}
        return DiffResult(register, country, None, new_edition, [], counts, [])

    old_by_key = {_norm(r.get(key_field)): r for r in old_rows if _norm(r.get(key_field))}

    removed_keys = old_by_key.keys() - new_by_key.keys()
    added_keys = new_by_key.keys() - old_by_key.keys()
    common_keys = old_by_key.keys() & new_by_key.keys()

    changes: list[Change] = []
    renames: list[Change] = []

    # ---- renames and field changes among surviving identifiers ------------
    for k in sorted(common_keys):
        old, new = old_by_key[k], new_by_key[k]
        old_name, new_name = _norm(old.get(name_field)), _norm(new.get(name_field))

        if persistent_id and old_name and new_name and _fold(old_name) != _fold(new_name):
            c = Change(
                kind="renamed",
                key=k,
                register=register,
                country=country,
                old_edition=old_edition,
                new_edition=new_edition,
                name=new_name,
                previous_name=old_name,
                statement=(
                    f"Listed as “{new_name}” on the edition published {new_edition}, "
                    f"having been listed as “{old_name}” on the edition published {old_edition}. "
                    f"The identifier {k} is unchanged across both editions."
                ),
                caveat=(
                    "Because the register's own identifier is unchanged, this is a change of "
                    "recorded name for the same registered entity. It is not a new listing and "
                    "not a removal. It does not indicate anything about the institution's standing."
                ),
            )
            changes.append(c)
            renames.append(c)

        field_changes = {}
        for f in watch:
            ov, nv = _norm(old.get(f)), _norm(new.get(f))
            if ov != nv:
                field_changes[f] = (ov, nv)

        if field_changes:
            changes.append(
                Change(
                    kind="modified",
                    key=k,
                    register=register,
                    country=country,
                    old_edition=old_edition,
                    new_edition=new_edition,
                    name=new_name or old_name,
                    fields=field_changes,
                    statement=(
                        f"On the edition published {new_edition}, "
                        + "; ".join(
                            f"the {f} field reads “{nv}” where the edition published "
                            f"{old_edition} read “{ov or 'blank'}”"
                            for f, (ov, nv) in field_changes.items()
                        )
                        + "."
                    ),
                    caveat=(
                        "Field values are reproduced exactly as the source publishes them. "
                        "We do not paraphrase a compliance flag into a verdict."
                    ),
                )
            )

    # ---- disappearances ---------------------------------------------------
    # Where the key is a name rather than a real identifier, check whether a
    # very similar name appeared in the same edition. That is a possible
    # rename, and it must be flagged as *possible*, never asserted.
    added_folded = {_fold(_norm(new_by_key[k].get(name_field)) or k): k for k in added_keys}

    for k in sorted(removed_keys):
        old = old_by_key[k]
        name = _norm(old.get(name_field)) or k
        possible_rename_to = None
        if not persistent_id:
            match = added_folded.get(_fold(name))
            if match:
                possible_rename_to = _norm(new_by_key[match].get(name_field)) or match

        statement = DISAPPEARANCE_TEMPLATE.format(
            register=register, new_date=new_edition, old_date=old_edition
        )
        caveat = DISAPPEARANCE_ALTERNATIVES
        if possible_rename_to:
            caveat += (
                f" An entry named “{possible_rename_to}” appears on the newer edition. "
                "This register publishes no persistent identifier, so we cannot tell whether that "
                "is the same organisation under a new name or a different organisation, and we "
                "do not assert either."
            )

        changes.append(
            Change(
                kind="removed",
                key=k,
                register=register,
                country=country,
                old_edition=old_edition,
                new_edition=new_edition,
                name=name,
                statement=statement,
                caveat=caveat,
            )
        )

    # ---- new listings -----------------------------------------------------
    for k in sorted(added_keys):
        new = new_by_key[k]
        name = _norm(new.get(name_field)) or k
        changes.append(
            Change(
                kind="added",
                key=k,
                register=register,
                country=country,
                old_edition=old_edition,
                new_edition=new_edition,
                name=name,
                statement=(
                    f"First appears on the {register} edition published {new_edition}. "
                    f"It was not on the edition published {old_edition}."
                ),
                caveat=(
                    "First appearance in our record is not necessarily the institution's first "
                    "appearance on the register. Our record of this register begins with the "
                    "earliest edition we hold."
                ),
            )
        )

    counts = {
        "added": len(added_keys),
        "removed": len(removed_keys),
        "renamed": len(renames),
        "modified": sum(1 for c in changes if c.kind == "modified"),
    }

    return DiffResult(register, country, old_edition, new_edition, changes, counts, renames)


def assert_editorial_safety(changes: Iterable[Change]) -> None:
    """
    Belt and braces. Fails the build if forbidden vocabulary ever reaches a
    published statement, whatever route it took to get there.
    """
    for c in changes:
        blob = f"{c.statement} {c.caveat}".lower()
        for verb in FORBIDDEN_VERBS:
            if verb in blob:
                raise AssertionError(
                    f"Editorial rule violated for {c.key!r}: generated text contains {verb!r}. "
                    "The register publishes a status, not a cause."
                )
