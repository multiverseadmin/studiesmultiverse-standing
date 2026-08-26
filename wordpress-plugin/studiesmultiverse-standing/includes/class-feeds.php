<?php
/**
 * Feeds, sitemap, llms.txt and change notification.
 *
 * RSS comes first and email comes later, deliberately. The measured problem on
 * this site is not traffic — it is that nobody returns and almost nobody
 * subscribes. A student who has applied to three institutions has a real reason
 * to subscribe to those three rows, and RSS delivers that for free with no
 * deliverability risk. This portfolio has a history of mail problems; email
 * alerts ship only after a delivery test passes.
 *
 * llms.txt and the Dataset schema are the other half: increasingly the question
 * "is X still approved?" is answered by a machine reading a page rather than a
 * person visiting one. Being the source that machine reads is worth more here
 * than a ranking position.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Feeds {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		// Priority 1, and the reason matters.
		//
		// add_rewrite_rule( ..., 'top' ) does not mean "first". It appends to the
		// top-priority array in the order the rules are registered, and Routes
		// registers first because it is instantiated first. Its entity rule,
		//
		//     ^standing/([^/]+)/([^/]+)/?$
		//
		// matches /standing/australia/feed/ perfectly well — country "australia",
		// institution "feed" — so every country feed was resolving to an
		// institution page that does not exist and returning 404. The same rule
		// swallowed /standing/changes/feed/, the feed advertised in every page's
		// <head>. The handlers were never the problem: ?sm_feed=all returned
		// valid RSS the whole time. Only the URL never reached them.
		//
		// Registering earlier puts these four narrow rules ahead of the general
		// ones, which is the correct precedence anyway: a specific path should
		// always be matched before a wildcard that happens to fit it.
		add_action( 'init', [ $this, 'rules' ], 1 );
		add_filter( 'query_vars', [ $this, 'vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );

		// Tell search engines the moment a register moves, rather than waiting
		// to be crawled. A delisting is time-sensitive for the student.
		add_action( 'sm_standing_changed', [ $this, 'ping_indexnow' ] );
	}

	public function rules(): void {
		add_rewrite_rule( '^standing/changes/feed/?$', 'index.php?sm_feed=all', 'top' );
		add_rewrite_rule( '^standing/([^/]+)/feed/?$', 'index.php?sm_feed=country&sm_feed_a=$matches[1]', 'top' );
		// Two paths to the same sitemap. Rank Math claims the "<something>-sitemap.xml"
		// shape for its own sitemap index, so standing-sitemap.xml is contested
		// ground; standing/sitemap.xml is not, and is the one we advertise.
		add_rewrite_rule( '^standing/sitemap\.xml$', 'index.php?sm_feed=sitemap', 'top' );
		add_rewrite_rule( '^standing-sitemap\.xml$', 'index.php?sm_feed=sitemap', 'top' );
		add_rewrite_rule( '^llms\.txt$', 'index.php?sm_feed=llms', 'top' );
	}

	public function vars( array $v ): array {
		$v[] = 'sm_feed';
		$v[] = 'sm_feed_a';
		return $v;
	}

	public function maybe_serve(): void {
		$mode = (string) get_query_var( 'sm_feed' );
		if ( ! $mode ) {
			return;
		}

		switch ( $mode ) {
			case 'all':
				$this->rss( Data::instance()->recent_changes( 100 ), 'All countries' );
				break;
			case 'country':
				$slug    = sanitize_title( (string) get_query_var( 'sm_feed_a' ) );
				$country = Data::instance()->country( $slug );
				if ( ! $country ) {
					status_header( 404 );
					exit;
				}
				$this->rss(
					array_slice( Data::instance()->changes( $country['source_id'] ), 0, 100 ),
					$country['country']
				);
				break;
			case 'sitemap':
				$this->sitemap();
				break;
			case 'llms':
				$this->llms();
				break;
		}
		exit;
	}

	private function rss( array $changes, string $scope ): void {
		header( 'Content-Type: application/rss+xml; charset=utf-8' );
		header( 'Cache-Control: public, max-age=1800' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
		printf( '<title>%s</title>', esc_html( "Standing Register — {$scope}: recorded changes" ) );
		printf( '<link>%s</link>', esc_url( home_url( '/standing/changes/' ) ) );
		printf(
			'<description>%s</description>',
			esc_html(
				'Dated changes recorded against official registers of institutions permitted to enrol '
				. 'international students. A row appearing or disappearing is not evidence of wrongdoing.'
			)
		);
		echo '<language>en</language>';
		printf( '<lastBuildDate>%s</lastBuildDate>', esc_html( gmdate( 'r' ) ) );

		foreach ( $changes as $ch ) {
			$labels = [
				'removed'  => 'No longer listed',
				'added'    => 'Newly listed',
				'renamed'  => 'Name changed',
				'modified' => 'Record changed',
				'course_withdrawn_provider_still_listed' => 'Course withdrawn, provider still listed',
			];
			$label = $labels[ $ch['kind'] ?? '' ] ?? 'Change';

			// Where this change can be read in full, with its provenance.
			//
			// A change record carries `country` and `key`, not `source_id` - the
			// first version of this feed built its guid from source_id and so
			// emitted a leading empty segment on every item. Country is what the
			// record actually has, and it is what the entity route matches on.
			//
			// Routes resolves an entity by sanitize_title() of either the key or
			// the name, so the key is preferred: it survives a rename, which is
			// exactly the moment a reader most wants to follow the link. Canadian
			// keys are composite (DLI number + campus) and slugify cleanly.
			$country_slug = sanitize_title( (string) ( $ch['country'] ?? '' ) );
			$entity_slug  = sanitize_title( (string) ( $ch['key'] ?? '' ) ?: (string) ( $ch['name'] ?? '' ) );
			$permalink    = ( $country_slug && $entity_slug )
				? home_url( "/standing/{$country_slug}/{$entity_slug}/" )
				: home_url( '/standing/changes/' );

			echo '<item>';
			printf( '<title>%s</title>', esc_html( "{$label}: " . ( $ch['name'] ?? '' ) ) );
			printf( '<link>%s</link>', esc_url( $permalink ) );
			printf(
				'<description>%s</description>',
				esc_html( trim( ( $ch['statement'] ?? '' ) . ' ' . ( $ch['caveat'] ?? '' ) ) )
			);

			// The edition date is the substance of a change record, not metadata.
			// Without it a reader has no time axis and falls back to fetch time,
			// which would date a 2018 Canadian delisting to this morning.
			$edition = (string) ( $ch['new_edition'] ?? '' );
			$stamp   = $edition ? strtotime( $edition . ' 00:00:00 UTC' ) : false;
			if ( $stamp ) {
				printf( '<pubDate>%s</pubDate>', esc_html( gmdate( 'r', $stamp ) ) );
			}

			printf(
				'<guid isPermaLink="false">%s</guid>',
				esc_html( implode( ':', [ $country_slug, $edition, $ch['kind'] ?? '', $ch['key'] ?? '' ] ) )
			);
			echo '</item>';
		}
		echo '</channel></rss>';
	}

	/**
	 * A sitemap of only what deserves indexing.
	 *
	 * The thin-content rule applies here as hard as anywhere: an institution
	 * page enters the sitemap when it has a story, not because it exists.
	 */
	private function sitemap(): void {
		header( 'Content-Type: application/xml; charset=utf-8' );
		$data = Data::instance();

		$urls = [ home_url( '/standing/' ) ];
		foreach ( [ 'changes', 'no-longer-listed', 'watchlist', 'countries', 'archive', 'methodology', 'corrections', 'data' ] as $s ) {
			$urls[] = home_url( "/standing/{$s}/" );
		}

		$seen = [];
		foreach ( $data->countries() as $c ) {
			$slug   = $data->slug( $c['country'] );
			$urls[] = home_url( "/standing/{$slug}/" );

			foreach ( $data->changes( $c['source_id'] ) as $ch ) {
				$key = sanitize_title( (string) ( $ch['key'] ?? $ch['name'] ?? '' ) );
				if ( ! $key ) {
					continue;
				}
				$id = "{$slug}/{$key}";
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$urls[]      = home_url( "/standing/{$id}/" );
			}
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $urls as $u ) {
			printf( '<url><loc>%s</loc></url>', esc_url( $u ) );
		}
		echo '</urlset>';
	}

	private function llms(): void {
		header( 'Content-Type: text/plain; charset=utf-8' );
		$data  = Data::instance();
		$index = $data->index();

		$out = [
			'# Studies Multiverse — The Standing Register',
			'',
			'> The worldwide record of which institutions are officially permitted to enrol international',
			'> students, and which have quietly left those registers.',
			'',
			'This site earns nothing from where you apply. No institution referral fees, no agent',
			'commissions, no paid inclusion, no paid removal.',
			'',
			'## What is here',
			'',
		];

		foreach ( $data->countries() as $c ) {
			$slug  = $data->slug( $c['country'] );
			$out[] = sprintf(
				'- **%s** — %s (%s). %d editions held since %s; %d changes recorded. Page: %s Data: %s',
				$c['country'],
				$c['register'],
				$c['publisher'],
				(int) $c['editions_held'],
				$c['recording_since'],
				(int) $c['changes_recorded'],
				home_url( "/standing/{$slug}/" ),
				$c['endpoints']['changes'] ?? ''
			);
		}

		// The rest of the site, so this file can replace the one a separate
		// plugin currently writes.
		//
		// /llms.txt is presently served by the "Website LLMs.txt" plugin as a
		// physical file, which beats a rewrite rule, and its version lists the
		// calculators and guides without mentioning the Standing Register once —
		// the file AI crawlers read to learn what this site is, describing
		// everything except the thing nobody else has. This section makes our
		// version a superset, so switching the two over loses nothing.
		//
		// One get_pages() call on an endpoint crawlers hit occasionally. The
		// plugin's no-queries-while-rendering contract is about page views; it
		// is noted here rather than quietly bent.
		$pages = get_pages( [ 'sort_column' => 'post_title', 'number' => 200 ] );
		if ( $pages ) {
			$out[] = '';
			$out[] = '## Guides and tools';
			$out[] = '';
			foreach ( $pages as $p ) {
				$out[] = sprintf( '- %s: %s', get_the_title( $p ), get_permalink( $p ) );
			}
		}

		$doi = Identity::instance()->doi();
		$out = array_merge(
			$out,
			[
				'',
				'## How to cite',
				'',
				'Every change entry carries the edition dates it was derived from and a SHA-256 of the archived',
				'source edition. Cite the edition date, not the date the page was read.',
				$doi ? "Permanent DOI: {$doi}" : '',
				'',
				'## What this source will not claim',
				'',
				'A row disappearing from a register is not evidence of wrongdoing. This site never states that an',
				'institution was revoked, banned or shut down. It states that an institution is no longer listed',
				'on the edition published on a given date, and names the alternatives: withdrawal, merger, rename,',
				'voluntary surrender, lapse at renewal, or a correction by the publisher.',
				'',
				'Corrections: ' . home_url( '/standing/corrections/' ),
				'Method: ' . home_url( '/standing/methodology/' ),
				'Licence: CC BY 4.0',
				'Generated: ' . ( $index['generated_at'] ?? gmdate( 'c' ) ),
				'',
			]
		);

		echo implode( "\n", array_filter( $out, static fn( $l ) => '' !== $l || true ) );
	}

	/**
	 * IndexNow. Free, instant, supported by Bing and Yandex; harmless elsewhere.
	 */
	public function ping_indexnow( array $urls = [] ): void {
		$key = (string) get_option( 'sm_standing_indexnow_key', '' );
		if ( ! $key || ! $urls ) {
			return;
		}
		wp_remote_post(
			'https://api.indexnow.org/indexnow',
			[
				'timeout'  => 8,
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => wp_json_encode(
					[
						'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
						'key'         => $key,
						'keyLocation' => home_url( "/{$key}.txt" ),
						'urlList'     => array_slice( $urls, 0, 10000 ),
					]
				),
			]
		);
	}
}
