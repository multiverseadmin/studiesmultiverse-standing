"""
Tests for the witness layer. No network: the calendar and Save Page Now seams
are replaced with fakes that answer the way the real services do.
"""

from __future__ import annotations

import datetime as dt
import hashlib
import json
import pathlib
import sys

import pytest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent))
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent.parent / "scripts"))

from opentimestamps.core.notary import BitcoinBlockHeaderAttestation, PendingAttestation
from opentimestamps.core.timestamp import Timestamp

import witness  # noqa: E402

DIGEST = hashlib.sha256(b"edition 2026-09-07").hexdigest()


class FakeCalendars(witness.Calendars):
    """Answers a pending attestation on submit; a Bitcoin one on fetch."""

    def __init__(self, urls=("https://a.pool.opentimestamps.org",), anchor=True):
        super().__init__(urls=urls)
        self.anchor = anchor
        self.submitted = []
        self.fetched = []

    def submit(self, msg):
        self.submitted.append(msg)
        out = []
        for url in self.urls:
            ts = Timestamp(msg)
            ts.attestations.add(PendingAttestation(url))
            out.append(ts)
        return out

    def fetch(self, url, commitment):
        self.fetched.append((url, commitment))
        if not self.anchor:
            return None
        ts = Timestamp(commitment)
        ts.attestations.add(BitcoinBlockHeaderAttestation(912345))
        return ts


class FakeSPN(witness.SavePageNow):
    def __init__(self):
        super().__init__(pause=0)
        self.urls = []

    def capture(self, url):
        self.urls.append(url)
        return {"ok": True, "requested_at": "2026-09-08T09:37:00+00:00", "wayback": f"https://web.archive.org/web/2/{url}"}


def test_stamp_commits_to_the_published_digest():
    """The proof's file digest is the digest on the page; the calendar sees sha256(digest||nonce)."""
    detached, root = witness.build_stamp(DIGEST, nonce=b"\x00" * 16)
    assert detached.file_digest == bytes.fromhex(DIGEST)
    assert root.msg == hashlib.sha256(bytes.fromhex(DIGEST) + b"\x00" * 16).digest()
    with pytest.raises(ValueError):
        witness.build_stamp("abcd")


def test_proof_round_trips_and_reports_status():
    cal = FakeCalendars()
    detached = witness.stamp_digest(DIGEST, cal)
    assert detached is not None
    assert witness.attestation_status(detached) == ("pending", None)
    again = witness.deserialize(witness.serialize(detached))
    assert again.file_digest == bytes.fromhex(DIGEST)
    assert witness.upgrade(again, cal) is True
    assert witness.attestation_status(again) == ("bitcoin", 912345)
    # Upgrading a complete proof changes nothing and asks nobody.
    n = len(cal.fetched)
    assert witness.upgrade(again, cal) is False
    assert len(cal.fetched) == n


@pytest.fixture
def repo(tmp_path, monkeypatch):
    """A one-source repository with a published archive.json and no witness record."""
    monkeypatch.setattr(witness, "ROOT", tmp_path)
    monkeypatch.setattr(witness, "DATA", tmp_path / "data")
    monkeypatch.setattr(witness, "PUBLIC", tmp_path / "public")
    monkeypatch.setattr(witness, "first_commit_of", lambda p: "deadbeef" * 5)
    (tmp_path / "data/uk-sponsors/editions").mkdir(parents=True)
    (tmp_path / "data/uk-sponsors/editions/2026-09-07.json.gz").write_bytes(b"x")
    (tmp_path / "public/uk-sponsors").mkdir(parents=True)
    archive = {
        "publisher": "A.I.T. Multiverse Consulting Ltd, Nicosia, Cyprus",
        "site": "https://studiesmultiverse.com",
        "our_licence": "CC BY 4.0",
        "our_licence_url": "https://creativecommons.org/licenses/by/4.0/",
        "source": {"country": "United Kingdom"},
        "editions": [
            {
                "edition_date": "2026-09-07",
                "source_date": "2026-09-07",
                "row_count": 1306,
                "content_sha256": DIGEST,
                "raw_sha256": hashlib.sha256(b"raw").hexdigest(),
                "fetched_at": "2026-09-08T06:31:58+00:00",
            }
        ],
    }
    (tmp_path / "public/uk-sponsors/archive.json").write_text(json.dumps(archive))
    return tmp_path


def test_first_run_stamps_captures_and_publishes(repo):
    cal, spn = FakeCalendars(), FakeSPN()
    now = dt.datetime(2026, 9, 8, 9, 37, tzinfo=dt.timezone.utc)
    stats = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=25, now=now)
    assert stats == {"stamped": 2, "upgraded": 0, "captured": 3, "failed_stamps": 0}
    wdir = repo / "data/uk-sponsors/witness"
    assert (wdir / "2026-09-07.content.ots").exists()
    assert (wdir / "2026-09-07.raw.ots").exists()
    # Both permanent addresses and the daily index capture were asked for.
    assert spn.urls[0].startswith("https://raw.githubusercontent.com/") and "/deadbeef" in spn.urls[0]
    assert spn.urls[1].startswith("https://github.com/") and spn.urls[1].endswith("deadbeef" * 5)
    assert spn.urls[2].endswith("/public/uk-sponsors/archive.json")
    pub = json.loads((repo / "public/uk-sponsors/witness.json").read_text())
    assert pub["summary"] == {
        "editions": 1,
        "editions_stamped": 1,
        "editions_with_bitcoin_attestation": 0,
        "editions_archived_at_internet_archive": 1,
    }
    row = pub["editions"][0]
    assert row["content_sha256"] == DIGEST
    assert row["proofs"]["content"]["status"] == "pending"
    assert row["proofs"]["content"]["proof_url"].endswith("/data/uk-sponsors/witness/2026-09-07.content.ots")
    assert row["edition_url"] == witness.permalink("deadbeef" * 5, "data/uk-sponsors/editions/2026-09-07.json.gz")
    assert "ots verify -d" in pub["how_to_verify"]

    # Second run, two hours later: the proof upgrades, nothing is re-stamped or re-captured.
    later = now + dt.timedelta(hours=3)
    stats2 = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=25, now=later)
    assert stats2["stamped"] == 0 and stats2["upgraded"] == 2 and stats2["captured"] == 0
    pub2 = json.loads((repo / "public/uk-sponsors/witness.json").read_text())
    assert pub2["summary"]["editions_with_bitcoin_attestation"] == 1
    assert pub2["editions"][0]["proofs"]["content"]["bitcoin_block_height"] == 912345


def test_a_rewritten_edition_is_refused(repo):
    cal = FakeCalendars()
    witness.witness_source("uk-sponsors", calendars=cal, spn=None, archive_limit=0)
    archive_path = repo / "public/uk-sponsors/archive.json"
    archive = json.loads(archive_path.read_text())
    archive["editions"][0]["content_sha256"] = hashlib.sha256(b"someone edited the edition").hexdigest()
    archive_path.write_text(json.dumps(archive))
    with pytest.raises(witness.WitnessError, match="rewritten"):
        witness.witness_source("uk-sponsors", calendars=cal, spn=None, archive_limit=0)
    # The record still holds the digest that was witnessed first.
    index = json.loads((repo / "data/uk-sponsors/witness/index.json").read_text())
    assert index["editions"]["2026-09-07"]["content_sha256"] == DIGEST


def test_capture_limit_is_honoured(repo):
    cal, spn = FakeCalendars(), FakeSPN()
    stats = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=1)
    assert stats["captured"] == 1 and len(spn.urls) == 1
    stats = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=1)
    assert stats["captured"] == 1 and len(spn.urls) == 2  # picks up where it left off


def test_time_budget_stops_new_work_but_keeps_what_was_done(repo):
    import time

    cal, spn = FakeCalendars(), FakeSPN()
    # A deadline already in the past: nothing is stamped or captured, but the
    # record is still written and the run reports that it ran out of time.
    stats = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=25, deadline=time.monotonic() - 1)
    assert stats["stamped"] == 0 and stats["captured"] == 0 and stats.get("out_of_time") is True
    assert (repo / "public/uk-sponsors/witness.json").exists()
    assert not (repo / "data/uk-sponsors/witness/2026-09-07.content.ots").exists()
    # With time, the same run picks everything up.
    stats = witness.witness_source("uk-sponsors", calendars=cal, spn=spn, archive_limit=25, deadline=time.monotonic() + 60)
    assert stats["stamped"] == 2 and stats["captured"] == 3 and "out_of_time" not in stats
