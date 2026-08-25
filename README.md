# studiesmultiverse — Standing Register

The worldwide record of which institutions are officially permitted to enrol international
students: what the official registers say, what they used to say, and what it means for the
student.

**This repository is the archive.** Not a cache of it — the archive itself. Every edition we
fetch is committed with a timestamp and a content hash, so when an institution's lawyer asks
*"on what date did your site say we were not listed, and what did the source say?"*, the answer
is a commit URL and an archived copy of the source edition. That is a far stronger position than
"our database says so".

---

## Why this exists

Every destination country publishes a list of which institutions may legally enrol international
students. Every one of them quietly deletes rows from that list. Nobody keeps the deleted rows,
and nobody has ever put the countries side by side.

The consequences for a student are not abstract:

| Country | If the institution loses standing while you are enrolled |
|---|---|
| UK | Permission to stay curtailed to 60 days; visas cancelled outright if you have not travelled |
| Canada | Study permit invalid; **post-graduation work permit eligibility lost** |
| Australia | CRICOS conditions, suspension or cancellation; provider-default obligations trigger |
| USA | SEVP withdrawal ends the school's ability to issue I-20s |

The student almost never hears it from the institution. They hear it from a letter.

---

## Architecture

```
GitHub Actions (scheduled)          this repo                    WordPress
─────────────────────────           ─────────                    ─────────
fetch → parse → SANITY GATE  ──►  dated snapshot  ──►  static JSON  ──►  renders pages,
        │                          + change log         + RSS            search, feeds
        └─ refuses & alerts                                              (no parsing,
           if anything looks wrong                                        no cron)
```

Three reasons the collection lives here and not in WordPress:

1. **A git history is a tamper-evident public archive, for free.** Neither the site owner nor an
   automated agent can quietly rewrite what a commit says.
2. **It makes the moat auditable.** The whole claim is *we kept the history and nobody else did.*
   A public repo lets anyone verify that in one click.
3. **Scheduled runs that actually run.** WP-Cron only fires when someone visits the site. A
   register that updates only when a visitor happens to arrive is not a register.

---

## The sanity gate

`engine/sanity.py` is the most important file here. Read it before changing anything.

Automatic collection is easy. Automatic *interpretation* is where these systems cause real harm.
A timeout, a redirect, a changed layout or a truncated download all look identical to
*"300 institutions were removed today"* — and a naive pipeline would publish that, automatically,
at 2am, about real universities.

The gate refuses to publish and raises a GitHub issue if:

- fewer rows arrive than a calibrated floor
- more of the register disappears in one edition than a calibrated ceiling
- more appears than a calibrated ceiling
- combined churn spikes (the signature of a key column changing meaning)
- the publisher's own edition date has not advanced, or has gone backwards
- expected columns are missing from the parse

**Thresholds are calibrated from measured history, never guessed.** The Australian ceiling sits
between the worst genuine month ever observed (April–May 2026: 35 providers removed of 1,556,
about 2.3%) and the kind of phantom deletion a broken fetch produces. Both cases are covered by
tests. Never widen a threshold to make a red run go green — open the source by hand, confirm what
it actually says, and if the change is real, raise the threshold deliberately and say why in the
commit message.

> A quiet day is fine. A wrong day is not recoverable.

---

## The two publication layers

Enforced in `scripts/publish.py`, in code, not in a policy document someone forgets.

| Layer | Meaning | What gets published |
|---|---|---|
| `mirror` | Open licence or written permission confirmed | Rows republished verbatim, with licence and attribution attached to every file |
| `change-record` | No republication rights | **Only dated change events**, cited and linked back to the official source. The row dump physically cannot be emitted. |

Currently `mirror`: Australia (CC BY 2.5 AU), UK (OGL v3.0), DEQAR (PDDL), Netherlands (IND
attribution terms).
Currently `change-record`: Canada (Crown copyright — commercial redistribution needs written
permission, request pending), Poland (licence not yet read from an authoritative page).

A country moves from `change-record` to `mirror` only when a written permission or an
authoritative licence page is in hand — one line in `engine/snapshot.py`, and the rows start
flowing.

---

## Editorial rules, enforced by tests

These institutions are well-lawyered. A revoked licence, a suspended one, a voluntarily
surrendered one, a merger and a rename **all look identical: the row simply disappears.**

1. Never write "revoked", "lost its licence", "banned", or "shut down". `assert_editorial_safety()`
   fails the build if those words reach a published statement.
2. Write instead: *"No longer listed on the register published [date]. It appeared on the edition
   published [date]."*
3. Always name the alternatives in the same breath — withdrawal, merger, rename, voluntary
   surrender, lapse at renewal, publisher correction — and say we cannot tell which.
4. Never infer a reason. The sources publish a status, not a cause.
5. Quote compliance flags verbatim. Never paraphrase one into a verdict.
6. Where a source has a persistent identifier, **renames are proved, not guessed**: identifier
   survives + name changes = rename, and it is never counted as a disappearance. Where a source
   has no identifier (the UK register), the ambiguity is stated, not resolved.
7. A permanent, prominent correction route on every institution page.

---

## Layout

```
engine/
  sanity.py        the gate — refuse-to-publish logic and calibrated thresholds
  diff.py          edition diffing, rename proving, editorial vocabulary guard
  snapshot.py      the archive: dated snapshots, hashing, per-source provenance
  sources/
    au_cricos.py   Australia — CKAN listing, XLSX parsing, course-level analysis
scripts/
  au_ingest.py     backfill (57 editions) and daily incremental, same code path
  publish.py       static JSON + RSS + llms.txt, with layer enforcement
tests/
  test_engine.py   calibration tests — the ones that stop a false mass-removal
data/<source>/     the archive. editions/, raw/, changes.jsonl, current.json
public/            what WordPress serves
```

## Running it

```bash
pip install -r requirements.txt
python -m pytest tests/ -q

python scripts/au_ingest.py --backfill     # one-off: reconstruct 57 editions
python scripts/au_ingest.py --latest       # daily: ingest anything new
python scripts/publish.py                  # emit static JSON + RSS
```

The backfill is why Australia is built first. data.gov.au keeps every dated edition, so nearly
five years of history can be reconstructed on day one. Every other country overwrites — nobody
starting tomorrow can catch that up.

## Adding a country

1. **Measure the real change rate first.** A register that moves a handful of rows a year cannot
   support a change record, however prestigious the country. Thirteen countries were rejected on
   exactly this.
2. Confirm the licence, from the publisher's own terms page. Record it in `SOURCES`.
3. Set `publication_layer` honestly. Default to `change-record` when unsure.
4. Calibrate thresholds from at least two real editions. Add a test using the real numbers.
5. Add a workflow. One per country, independent, so one broken source never stops the others.

## Licence

Our own outputs — the change record, the archive index, the derived data — are published under
**CC BY 4.0**. Each source's own licence and attribution travel with every file that derives from
it, and are reproduced in `public/*/`.

The free layer — the register, search, the change feed, RSS, the open JSON snapshot — stays free.
It is what earns the citations and the trust, and it is not negotiable.
