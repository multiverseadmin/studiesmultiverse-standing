#!/usr/bin/env python3
"""
Independent witnesses for the archive.

The repository's own history proves that *we* recorded an edition on a date.
That is a good position, and it is still our word. This script asks two
parties that do not answer to us to hold the same fact:

1. **OpenTimestamps.** Every edition's *published* digests — ``content_sha256``
   and, where the raw source bytes were kept, ``raw_sha256``, exactly as they
   appear in ``public/<source>/archive.json`` — are stamped with the four
   public OpenTimestamps calendars. A proof commits to the digest already on
   the page, so anyone can check the number the site publishes against the
   Bitcoin block chain with the reference client, using nothing we hold::

       ots verify -d <content_sha256> data/<source>/witness/<date>.content.ots

   Calendars answer immediately with a pending attestation and anchor it in a
   Bitcoin block later (usually within a day). Later runs upgrade pending
   proofs and record the block height.

2. **The Internet Archive.** Save Page Now is asked to capture, per edition,
   the edition file at its immutable commit URL, the commit page, and — once a
   day per register — the published hash index. Those copies live on a server
   we cannot edit.

Rules that matter:

* A derived layer never rewrites a record. This script reads
  ``public/<source>/archive.json`` and the git log; it never opens an edition
  to change it.
* An edition whose published digest differs from the digest already witnessed
  is an **error**, not a new fact. The run stops for that source. Someone
  rewrote an edition file, and that has to be looked at by a person.
* No network in tests. Everything that talks to a calendar or to archive.org
  is behind a small seam so the logic can be exercised offline.

Outputs:

    data/<source>/witness/<date>.content.ots   proof for content_sha256
    data/<source>/witness/<date>.raw.ots       proof for raw_sha256 (if kept)
    data/<source>/witness/index.json           the witness record
    public/<source>/witness.json               what the site publishes

Usage:

    python scripts/witness.py                       # all sources, stamp + upgrade + archive
    python scripts/witness.py --source uk-sponsors
    python scripts/witness.py --no-archive          # calendars only
    python scripts/witness.py --archive-limit 25    # more SPN captures per source than the default 10
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import logging
import os
import pathlib
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, PendingAttestation  # noqa: E402
from opentimestamps.core.op import OpAppend, OpSHA256  # noqa: E402
from opentimestamps.core.serialize import BytesDeserializationContext, BytesSerializationContext  # noqa: E402
from opentimestamps.core.timestamp import DetachedTimestampFile, Timestamp  # noqa: E402

log = logging.getLogger("witness")

REPO = os.environ.get("GITHUB_REPOSITORY", "multiverseadmin/studiesmultiverse-standing")
SOURCES = ("au-cricos", "ca-dli", "jp-mext", "uk-sponsors")

CALENDARS = (
    "https://a.pool.opentimestamps.org",
    "https://b.pool.opentimestamps.org",
    "https://a.pool.eternitywall.com",
    "https://ots.btc.catallaxy.com",
)

# A calendar needs time to aggregate and broadcast. Asking before this is
# a wasted request; the reference client waits too.
UPGRADE_AFTER = dt.timedelta(hours=2)

DATA = ROOT / "data"
PUBLIC = ROOT / "public"

HOW_TO_VERIFY = (
    "Install the reference client (pip install opentimestamps-client). Take the "
    "content_sha256 shown for an edition on studiesmultiverse.com or in "
    "public/<source>/archive.json, download the matching .ots proof from the "
    "proof_url, and run: ots verify -d <content_sha256> <date>.content.ots. "
    "A proof with status 'bitcoin' verifies against the block chain without "
    "trusting this repository or its publisher; a 'pending' proof is held by the "
    "calendars named in the file and upgrades on its own. The Wayback Machine "
    "links are the Internet Archive's own copies of the edition file at its "
    "immutable commit URL and of the commit page."
)


def utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def iso(t: dt.datetime) -> str:
    return t.isoformat()


# --------------------------------------------------------------------------
# OpenTimestamps
# --------------------------------------------------------------------------


def build_stamp(digest_hex: str, nonce: bytes | None = None) -> tuple[DetachedTimestampFile, Timestamp]:
    """
    Build the detached timestamp for a published SHA-256 digest, the way the
    reference client does for a file: nonce appended, hashed again, and that
    hash is what the calendars see. The nonce means a proof leaks nothing
    about neighbouring digests when many are stamped at once.

    Returns the detached file (what we save) and the merkle root Timestamp
    (what we submit; merging the calendar's answer into it completes the file).
    """
    digest = bytes.fromhex(digest_hex)
    if len(digest) != 32:
        raise ValueError(f"not a SHA-256 digest: {digest_hex!r}")
    detached = DetachedTimestampFile(OpSHA256(), Timestamp(digest))
    nonce_appended = detached.timestamp.ops.add(OpAppend(nonce if nonce is not None else os.urandom(16)))
    merkle_root = nonce_appended.ops.add(OpSHA256())
    return detached, merkle_root


def serialize(detached: DetachedTimestampFile) -> bytes:
    ctx = BytesSerializationContext()
    detached.serialize(ctx)
    return ctx.getbytes()


def deserialize(raw: bytes) -> DetachedTimestampFile:
    return DetachedTimestampFile.deserialize(BytesDeserializationContext(raw))


def attestation_status(detached: DetachedTimestampFile) -> tuple[str, int | None]:
    """'bitcoin' with the lowest block height, or 'pending', or 'none'."""
    heights = []
    pending = False
    for _msg, att in detached.timestamp.all_attestations():
        if isinstance(att, BitcoinBlockHeaderAttestation):
            heights.append(att.height)
        elif isinstance(att, PendingAttestation):
            pending = True
    if heights:
        return "bitcoin", min(heights)
    return ("pending" if pending else "none"), None


class Calendars:
    """The seam. Tests replace ``submit`` and ``fetch``."""

    def __init__(self, urls=CALENDARS, timeout: int = 20):
        self.urls = list(urls)
        self.timeout = timeout

    def _remote(self, url):
        from opentimestamps.calendar import RemoteCalendar

        return RemoteCalendar(url, user_agent="studiesmultiverse-witness")

    def submit(self, msg: bytes) -> list[Timestamp]:
        """All calendars at once, as the reference client does; a slow or
        dead calendar costs one timeout, not one timeout per stamp."""
        from concurrent.futures import ThreadPoolExecutor

        def one(url):
            try:
                return self._remote(url).submit(msg, timeout=self.timeout)
            except Exception as exc:  # noqa: BLE001 — one calendar down is fine
                log.warning("calendar %s refused the submission: %s", url, exc)
                return None

        with ThreadPoolExecutor(max_workers=len(self.urls) or 1) as pool:
            return [ts for ts in pool.map(one, self.urls) if ts is not None]

    def fetch(self, url: str, commitment: bytes) -> Timestamp | None:
        from opentimestamps.calendar import CommitmentNotFoundError

        try:
            return self._remote(url).get_timestamp(commitment, timeout=self.timeout)
        except CommitmentNotFoundError:
            return None
        except Exception as exc:  # noqa: BLE001
            log.warning("calendar %s: %s", url, exc)
            return None


def stamp_digest(digest_hex: str, calendars: Calendars) -> DetachedTimestampFile | None:
    detached, merkle_root = build_stamp(digest_hex)
    answers = calendars.submit(merkle_root.msg)
    if not answers:
        return None
    for ts in answers:
        merkle_root.merge(ts)
    return detached


def upgrade(detached: DetachedTimestampFile, calendars: Calendars) -> bool:
    """Ask each pending calendar for its Bitcoin attestation. True if changed."""
    changed = False

    def walk(stamp: Timestamp):
        yield stamp
        for sub in stamp.ops.values():
            yield from walk(sub)

    for stamp in list(walk(detached.timestamp)):
        for att in list(stamp.attestations):
            if not isinstance(att, PendingAttestation):
                continue
            uri = att.uri
            # Ask the calendar the proof names, not the address we submitted
            # to. They are not the same: a submission to a.pool.opentimestamps.org
            # comes back naming alice.btc.calendar.opentimestamps.org. Filtering
            # the URI against the submission list upgraded nothing, ever.
            if not uri.startswith("https://"):
                log.warning("proof names a calendar we will not fetch over: %s", uri)
                continue
            upgraded = calendars.fetch(uri, stamp.msg)
            if upgraded is None:
                continue
            before = set(a for _m, a in stamp.all_attestations())
            stamp.merge(upgraded)
            after = set(a for _m, a in stamp.all_attestations())
            if after - before:
                changed = True
    if changed:
        # Once a Bitcoin attestation exists the pending ones are noise; the
        # reference client prunes them too.
        for stamp in list(walk(detached.timestamp)):
            if any(isinstance(a, BitcoinBlockHeaderAttestation) for a in stamp.attestations):
                stamp.attestations = {a for a in stamp.attestations if not isinstance(a, PendingAttestation)}
    return changed


# --------------------------------------------------------------------------
# git and archive.org
# --------------------------------------------------------------------------


def first_commit_of(path: pathlib.Path) -> str | None:
    """The commit that added the file — the edition's permanent address."""
    rel = path.relative_to(ROOT).as_posix()
    try:
        out = subprocess.run(
            ["git", "log", "--format=%H", "--diff-filter=A", "--follow", "--", rel],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=True,
        ).stdout.split()
    except (subprocess.CalledProcessError, FileNotFoundError):
        return None
    return out[-1] if out else None


def permalink(commit: str, rel_path: str) -> str:
    return f"https://raw.githubusercontent.com/{REPO}/{commit}/{rel_path}"


def commit_url(commit: str) -> str:
    return f"https://github.com/{REPO}/commit/{commit}"


def proof_url(source: str, filename: str) -> str:
    return f"https://raw.githubusercontent.com/{REPO}/main/data/{source}/witness/{filename}"


class SavePageNow:
    """
    archive.org's Save Page Now. With IA_ACCESS_KEY / IA_SECRET_KEY it uses the
    authenticated SPN2 endpoint (faster, higher limits, JSON answers); without
    them the anonymous one. Either way one capture per call, politely spaced.
    Tests replace ``capture``.
    """

    def __init__(self, access_key: str | None = None, secret_key: str | None = None, pause: float = 6.0):
        self.access_key = access_key
        self.secret_key = secret_key
        self.pause = pause
        self.calls = 0

    def capture(self, url: str) -> dict:
        self.calls += 1
        if self.calls > 1:
            time.sleep(self.pause)
        if self.access_key and self.secret_key:
            req = urllib.request.Request(
                "https://web.archive.org/save",
                data=urllib.parse.urlencode({"url": url, "skip_first_archive": "1"}).encode(),
                headers={
                    "Accept": "application/json",
                    "Authorization": f"LOW {self.access_key}:{self.secret_key}",
                    "User-Agent": "studiesmultiverse-witness",
                },
                method="POST",
            )
        else:
            req = urllib.request.Request(
                "https://web.archive.org/save/" + url,
                headers={"User-Agent": "studiesmultiverse-witness"},
            )
        try:
            with urllib.request.urlopen(req, timeout=45) as resp:
                location = resp.headers.get("Content-Location") or ""
                body = resp.read(2000)
        except urllib.error.HTTPError as exc:
            return {"ok": False, "status": exc.code, "requested_at": iso(utcnow())}
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            return {"ok": False, "error": str(exc)[:200], "requested_at": iso(utcnow())}
        record = {"ok": True, "requested_at": iso(utcnow()), "wayback": f"https://web.archive.org/web/2/{url}"}
        if location.startswith("/web/"):
            record["wayback"] = "https://web.archive.org" + location
        if self.access_key and body[:1] == b"{":
            try:
                job = json.loads(body).get("job_id")
                if job:
                    record["job_id"] = job
            except ValueError:
                pass
        return record


# --------------------------------------------------------------------------
# the witness record
# --------------------------------------------------------------------------


class WitnessError(RuntimeError):
    pass


def load_index(source: str) -> dict:
    p = DATA / source / "witness" / "index.json"
    if p.exists():
        return json.loads(p.read_text(encoding="utf-8"))
    return {"source": source, "editions": {}}


def save_index(source: str, index: dict) -> None:
    d = DATA / source / "witness"
    d.mkdir(parents=True, exist_ok=True)
    (d / "index.json").write_text(json.dumps(index, indent=1, sort_keys=True) + "\n", encoding="utf-8")


def read_archive(source: str) -> dict:
    p = PUBLIC / source / "archive.json"
    if not p.exists():
        raise WitnessError(f"{source}: no published archive.json — run publish.py first")
    return json.loads(p.read_text(encoding="utf-8"))


def check_unchanged(source: str, date: str, kind: str, recorded: str | None, published: str | None) -> None:
    """The one refusal in this file."""
    if recorded and published and recorded != published:
        raise WitnessError(
            f"{source} {date}: published {kind} digest {published[:12]}… differs from the "
            f"witnessed {recorded[:12]}…. An edition file was rewritten. Stopping."
        )


def witness_source(
    source: str,
    *,
    calendars: Calendars,
    spn: SavePageNow | None,
    archive_limit: int,
    do_upgrade: bool = True,
    now: dt.datetime | None = None,
    deadline: float | None = None,
) -> dict:
    """
    ``deadline`` is a time.monotonic() value. Past it, nothing new is started:
    no stamps, no captures. What was done is saved either way, and the next
    run continues where this one stopped. This is what keeps a first run over
    435 editions inside the job's time limit instead of losing all of it.
    """
    now = now or utcnow()
    archive = read_archive(source)
    index = load_index(source)
    editions = index.setdefault("editions", {})
    wdir = DATA / source / "witness"
    wdir.mkdir(parents=True, exist_ok=True)
    stats = {"stamped": 0, "upgraded": 0, "captured": 0, "failed_stamps": 0}
    captures_left = archive_limit
    index_captured_today = index.get("archive_index_captured_on") == now.date().isoformat()

    def out_of_time() -> bool:
        return deadline is not None and time.monotonic() > deadline

    def save() -> None:
        index["updated_at"] = iso(now)
        save_index(source, index)
        write_public(source, archive, index)

    since_save = 0
    for ed in archive["editions"]:
        date = ed["edition_date"]
        rec = editions.setdefault(date, {})
        check_unchanged(source, date, "content", rec.get("content_sha256"), ed.get("content_sha256"))
        check_unchanged(source, date, "raw", rec.get("raw_sha256"), ed.get("raw_sha256"))
        rec["content_sha256"] = ed["content_sha256"]
        rec["raw_sha256"] = ed.get("raw_sha256")
        rec["source_date"] = ed.get("source_date")
        rec["row_count"] = ed.get("row_count")
        proofs = rec.setdefault("proofs", {})

        for kind in ("content", "raw"):
            digest = rec.get(f"{kind}_sha256")
            if not digest:
                continue
            fname = f"{date}.{kind}.ots"
            path = wdir / fname
            p = proofs.setdefault(kind, {"file": fname})
            if out_of_time():
                continue
            if not path.exists():
                detached = stamp_digest(digest, calendars)
                if detached is None:
                    stats["failed_stamps"] += 1
                    log.warning("%s %s: no calendar answered for %s", source, date, kind)
                    continue
                path.write_bytes(serialize(detached))
                status, height = attestation_status(detached)
                p.update({"stamped_at": iso(now), "status": status, "bitcoin_block_height": height})
                stats["stamped"] += 1
                since_save += 1
            elif do_upgrade and p.get("status") == "pending":
                stamped_at = p.get("stamped_at")
                if stamped_at and now - dt.datetime.fromisoformat(stamped_at) < UPGRADE_AFTER:
                    continue
                detached = deserialize(path.read_bytes())
                if upgrade(detached, calendars):
                    path.write_bytes(serialize(detached))
                    status, height = attestation_status(detached)
                    p.update({"status": status, "bitcoin_block_height": height, "upgraded_at": iso(now)})
                    if status == "bitcoin":
                        stats["upgraded"] += 1

        # The edition's permanent address.
        if not rec.get("commit"):
            edition_path = DATA / source / "editions" / f"{date}.json.gz"
            c = first_commit_of(edition_path) if edition_path.exists() else None
            if c:
                rec["commit"] = c
                rec["edition_path"] = edition_path.relative_to(ROOT).as_posix()

        # Internet Archive.
        if spn is not None and rec.get("commit") and captures_left > 0 and not out_of_time():
            wb = rec.setdefault("wayback", {})
            for key, url in (
                ("edition", permalink(rec["commit"], rec["edition_path"])),
                ("commit", commit_url(rec["commit"])),
            ):
                if captures_left <= 0 or out_of_time():
                    break
                if wb.get(key, {}).get("ok"):
                    continue
                wb[key] = spn.capture(url)
                captures_left -= 1
                since_save += 1
                if wb[key].get("ok"):
                    stats["captured"] += 1

        # A killed job keeps what was done so far.
        if since_save >= 20:
            save()
            since_save = 0

    if spn is not None and not index_captured_today and captures_left > 0 and not out_of_time():
        url = f"https://raw.githubusercontent.com/{REPO}/main/public/{source}/archive.json"
        result = spn.capture(url)
        if result.get("ok"):
            index["archive_index_captured_on"] = now.date().isoformat()
            index["archive_index_wayback"] = result.get("wayback")
            stats["captured"] += 1

    if out_of_time():
        log.warning("%s: time budget reached; the rest continues next run", source)
        stats["out_of_time"] = True
    save()
    return stats


def summarise(index: dict) -> dict:
    eds = index.get("editions", {})
    stamped = sum(1 for r in eds.values() if r.get("proofs", {}).get("content", {}).get("status") in ("pending", "bitcoin"))
    bitcoin = sum(1 for r in eds.values() if r.get("proofs", {}).get("content", {}).get("status") == "bitcoin")
    archived = sum(1 for r in eds.values() if r.get("wayback", {}).get("edition", {}).get("ok"))
    return {
        "editions": len(eds),
        "editions_stamped": stamped,
        "editions_with_bitcoin_attestation": bitcoin,
        "editions_archived_at_internet_archive": archived,
    }


def write_public(source: str, archive: dict, index: dict) -> None:
    rows = []
    for date, rec in sorted(index.get("editions", {}).items()):
        proofs = {}
        for kind, p in rec.get("proofs", {}).items():
            proofs[kind] = {
                "digest": rec.get(f"{kind}_sha256"),
                "proof_url": proof_url(source, p["file"]),
                "status": p.get("status", "none"),
                "bitcoin_block_height": p.get("bitcoin_block_height"),
                "stamped_at": p.get("stamped_at"),
            }
        wb = rec.get("wayback", {})
        rows.append(
            {
                "edition_date": date,
                "source_date": rec.get("source_date"),
                "row_count": rec.get("row_count"),
                "content_sha256": rec.get("content_sha256"),
                "raw_sha256": rec.get("raw_sha256"),
                "commit": rec.get("commit"),
                "commit_url": commit_url(rec["commit"]) if rec.get("commit") else None,
                "edition_url": permalink(rec["commit"], rec["edition_path"]) if rec.get("commit") else None,
                "proofs": proofs,
                "wayback": {k: v.get("wayback") for k, v in wb.items() if v.get("ok")},
            }
        )
    payload = {
        "publisher": archive.get("publisher"),
        "site": archive.get("site"),
        "generated_at": index.get("updated_at"),
        "our_licence": archive.get("our_licence"),
        "our_licence_url": archive.get("our_licence_url"),
        "source": archive.get("source"),
        "what_this_is": (
            "Independent witnesses to the editions in archive.json: OpenTimestamps proofs "
            "that commit each edition's published digest to the Bitcoin block chain, and "
            "Internet Archive captures of the edition file at its immutable commit URL. "
            "Neither is under this publisher's control after the fact."
        ),
        "how_to_verify": HOW_TO_VERIFY,
        "archive_index_wayback": index.get("archive_index_wayback"),
        "summary": summarise(index),
        "editions": rows,
    }
    out = PUBLIC / source / "witness.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")


# --------------------------------------------------------------------------


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--source", choices=SOURCES)
    ap.add_argument("--no-archive", action="store_true", help="skip archive.org")
    ap.add_argument("--no-upgrade", action="store_true", help="skip calendar upgrades")
    ap.add_argument("--archive-limit", type=int, default=10, help="SPN captures per source per run")
    ap.add_argument("--calendar-timeout", type=int, default=15)
    ap.add_argument(
        "--budget-seconds",
        type=int,
        default=1500,
        help="stop starting new stamps or captures after this many seconds (0 = no limit)",
    )
    args = ap.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

    calendars = Calendars(timeout=args.calendar_timeout)
    spn = None if args.no_archive else SavePageNow(os.environ.get("IA_ACCESS_KEY"), os.environ.get("IA_SECRET_KEY"))
    deadline = time.monotonic() + args.budget_seconds if args.budget_seconds > 0 else None
    sources = [args.source] if args.source else list(SOURCES)

    # Two passes. Proofs first for every register — they are cheap, and they
    # are the layer nobody else can reconstruct later. archive.org captures
    # second, with whatever time is left; anonymous Save Page Now can take a
    # minute per URL and must never crowd out a stamp.
    rc = 0
    totals: dict[str, dict] = {}
    for pass_name, pass_spn in (("proofs", None), ("captures", spn)):
        if pass_spn is None and pass_name == "captures":
            break
        for source in sources:
            try:
                stats = witness_source(
                    source,
                    calendars=calendars,
                    spn=pass_spn,
                    archive_limit=args.archive_limit,
                    do_upgrade=not args.no_upgrade and pass_name == "proofs",
                    deadline=deadline,
                )
            except WitnessError as exc:
                log.error("%s", exc)
                rc = 2
                continue
            t = totals.setdefault(source, {"stamped": 0, "upgraded": 0, "captured": 0, "failed_stamps": 0})
            for k in t:
                t[k] += stats.get(k, 0)
            if stats.get("out_of_time"):
                t["out_of_time"] = True

    for source, t in totals.items():
        summary = summarise(load_index(source))
        log.info(
            "%s: stamped %d, upgraded %d, captured %d, failed %d — %d editions, %d with a Bitcoin attestation%s",
            source,
            t["stamped"],
            t["upgraded"],
            t["captured"],
            t["failed_stamps"],
            summary["editions"],
            summary["editions_with_bitcoin_attestation"],
            " (time budget reached)" if t.get("out_of_time") else "",
        )
    return rc


if __name__ == "__main__":
    sys.exit(main())
