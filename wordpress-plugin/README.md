# Studies Multiverse — Standing Register (WordPress plugin)

The site half of the project. GitHub Actions builds the record; this renders it.

Everything else here is tamper-evident — every edition dated, hashed and
committed — while the plugin lived only as a built ZIP and whatever was running
on the server. That is the wrong way round: the code that decides what a reader
is *told* about an institution deserves at least the scrutiny the data gets.
This directory fixes that.

## What it is

A single plugin that owns `/standing/*` end to end: routes, rendering, the
search index, the RSS feeds, the sitemap, `llms.txt`, the REST API, the
offer-letter check, and the site's structured-data identity.

It reads static JSON pulled from `public/` in this repository. It performs no
parsing, no diffing, no remote calls and no database queries while rendering a
page. If a refresh fails, the last good file keeps serving: a stale register is
recoverable, a broken one is not.

## Layout

```
studiesmultiverse-standing/
  studiesmultiverse-standing.php   bootstrap, version-stamped self-repair
  includes/
    class-data.php                 reads the JSON; the column vocabulary
    class-routes.php               /standing/* routes and entity lookup
    class-render.php               the self-contained documents
    class-feeds.php                RSS, sitemap, llms.txt
    class-api.php                  the public REST API and the check
    class-identity.php             structured data, one Organization node
    class-performance.php          dequeues theme and builder on these routes
    class-elementor.php            widgets and shortcodes
harness.php                        renders pages with WordPress stubbed out
```

## Deploying

**Build the ZIP, upload it through wp-admin, click "Replace current with
uploaded".** That is the only path that works on this host.

Two things will waste your time if you do not know them:

- **The wp-admin Plugin File Editor silently discards saves.** It reports
  nothing, the editor still shows your text, and the file on disk is unchanged.
  Three "successful" edits were made this way before anyone checked disk. If you
  edit there, verify by re-opening the file in a *fresh page load* — not by
  reading the editor buffer you just typed into.
- **A stale browser tab lies about what is deployed.** Fetch the live URL again
  rather than trusting the page already in front of you.

```
zip -qr studiesmultiverse-standing-X.Y.Z.zip studiesmultiverse-standing
```

Bump `Version:` in the header **and** `SM_STANDING_VERSION` together. They drive
the self-repair below, so a version that does not change means an upgrade that
does not take effect.

## Why the version stamp matters

Rewrite rules and the search index are both *derived* artefacts, and both go
stale silently:

- An update runs no activation hook, so a release that adds or reorders a
  rewrite rule ships a URL that 404s until someone opens Settings → Permalinks.
  The RSS feeds and the sitemap were dead this way, advertised in every page's
  `<head>`, and nothing was broken enough to notice.
- The search index is only rebuilt during a data refresh, and an upgrade is not
  a refresh. A release taught the index to recognise Japanese column names and
  the index still contained no Japanese institution afterwards.

So `init` compares `sm_standing_rules_version` against the constant and, when
they differ, flushes the rules and rebuilds the index exactly once. Anything
computed from the data must be rebuilt when the code that computes it changes,
or "deployed" and "working" quietly stop meaning the same thing.

## Adding a country

The engine side is the ingest, the thresholds and the workflow. On this side
there is one job, and forgetting it has now cost two countries their
searchability:

**Add the source's column names to `Data::NAME_FIELDS` and `Data::KEY_FIELDS`.**

Japan calls its name `jp_inst_name`; the UK calls its `Sponsor Name`. Neither
was in the list when the country went live, so every institution was held,
rendered and *unfindable* — the register was complete and the search box, which
the plugin itself calls the site's front door, returned nothing. A source can be
live and invisible at the same time. The shared vocabulary exists precisely to
make this one edit instead of five, and it only helps if you make it.

If the source publishes a standing beyond mere presence — a licence tier, a
compliance action — add those columns to `Data::STANDING_FIELDS` so the check
reports them. Quote them verbatim under the publisher's own column name. Do not
rank them, translate them, or infer what one means for an applicant.

Do not surface a bare code the publisher gives no key for. Japan's
`opening_status` is `"1"`; showing that to a worried student is noise wearing
transparency's clothes. It stays in the archive and in the watch fields, so a
*change* to it is still detected and dated.

## The editorial contract

Every statement rendered here comes from the change record with its caveat
attached. This plugin never composes a verdict of its own and never uses the
words "revoked", "banned" or "shut down". A row disappearing from a register is
not evidence of wrongdoing — it can equally be a merger, a rename, a voluntary
surrender, a lapse at renewal, or a correction by the publisher, and the source
does not tell us which.

The offer-letter check will say a code does not appear on the edition we hold.
It will never say an offer is fake.

## Testing

`harness.php` stubs enough of WordPress to render a page outside it, which
catches fatals and broken markup without a WordPress install:

```
php harness.php home
php harness.php changes
php harness.php methodology
```

It reports byte size, `<h1>` count, rendered change entries, link count,
external requests (must be 0 — these documents are self-contained) and HTML
parse errors. CI runs `php -l` over every file and this harness on every push
that touches this directory.
