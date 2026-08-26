=== Studies Multiverse — Standing Register ===
Requires at least: 6.4
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later

The worldwide record of which institutions are officially permitted to enrol
international students.

== What it does ==

1. Serves the whole /standing/ section — search, country hubs, the change
   record, institution pages, methodology, corrections, archive and open data.
2. Fixes the site's structured-data identity. Measured on the live home page on
   25 August 2026, the site was emitting THREE Organization nodes and TWO
   WebSite nodes in one document. This plugin makes Rank Math the single owner
   of identity and unhooks the competing emitters.
3. Adds Dataset and DataCatalog schema, llms.txt, a filtered sitemap and
   citation metadata — so Google Dataset Search, OpenAIRE and AI assistants can
   read and cite the record.

== Installation ==

1. Plugins → Add New → Upload Plugin → choose the zip → Install → Activate.
2. Settings → Permalinks → Save (once, to flush rewrite rules).
3. Visit /standing/. If it 404s, save permalinks again.

The plugin pulls its data from the public GitHub repository on activation and
hourly thereafter. Until that repository exists and has run its first ingest,
/standing/ will render with empty lists — that is expected, not broken.

== Configuration ==

Two options, both optional, set via WP-CLI or the options table:

  sm_standing_doi            The Zenodo concept DOI, once minted. Adding it
                             switches on citation metadata and the DOI in the
                             Dataset schema.
  sm_standing_indexnow_key   IndexNow key, to notify search engines the moment
                             a register moves.

== Performance ==

No database queries, no parsing, no remote calls while rendering a page. CSS and
JS are inlined; the search index is fetched lazily only when someone types.
Measured render: 17–25 KB per page with ZERO external requests, against the
existing site's 194 KB with 16 stylesheets and 21 external scripts.

== Cloudflare ==

The /standing/ pages send Cache-Control: public, max-age=900,
stale-while-revalidate=86400. A Cloudflare cache rule on /standing/* that
respects origin cache headers is worth adding — these pages are identical for
every visitor.

Note the known gotcha on this hosting: GoDaddy fronts the site with its own
Cloudflare layer, so after any change, verify logged out with a cache-buster
before concluding it did not work.

== What it deliberately does not do ==

It does not delete anything. The competing schema snippets are unhooked at
runtime, not removed — deletions are the site owner's call, not an automated
one, because they are not reversible from here.

It never writes that an institution was revoked, banned or shut down. It states
what the register said, on what date, and names the alternatives.

== Changelog ==

= 1.0.0 =
* First release. Standing Register section, identity fix, Dataset schema,
  llms.txt, sitemap, RSS, lazy client-side search.
