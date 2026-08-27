<?php
/**
 * URL structure.
 *
 * The principle, from the brief: a student does not search "sponsor register".
 * A student searches "is X university approved for international students",
 * "X college DLI number", "is X on the CRICOS list", "can I still get a visa
 * for X". Every title, heading and URL below is written in the student's
 * words, not the regulator's.
 *
 *   /standing/                        One box. Type your school.
 *   /standing/changes/                Live feed, all countries
 *   /standing/no-longer-listed/       The artefact nobody else has
 *   /standing/watchlist/              Conditions, probation, action plans
 *   /standing/<country>/              What standing means here, and what to do
 *   /standing/methodology/            How each source is read, what we refuse to say
 *   /standing/corrections/            The permanent right-of-reply route
 *   /standing/archive/                Every snapshot, dated and hashed
 *   /standing/countries/              Which countries publish a list — and which don't
 *
 * THE THIN-CONTENT RULE
 *
 * 950 UK institutions across several countries is a few thousand potential
 * pages. Generating them all is precisely the scaled-content pattern that got
 * a sister site rejected by AdSense and buried another sister site's directory.
 *
 * So: an institution page is indexed only when it has something to say — a
 * status change, a compliance flag, a disappearance, or a cross-border sibling.
 * Everything else renders dynamically from the search box and is noindex. The
 * indexed set grows as the record grows, which is honest anyway, because on day
 * one there is genuinely nothing to say about most of them.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Routes {

	private static ?self $instance = null;

	private const BASE = 'standing';

	/** Fixed sub-pages that are not country slugs. */
	private const SECTIONS = [
		'changes',
		'no-longer-listed',
		'watchlist',
		'methodology',
		'corrections',
		'archive',
		'countries',
		'data',
	];

	private ?array $context = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_rules' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'wp', [ $this, 'claim_query' ], 1 );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_filter( 'robots_txt', [ $this, 'robots' ], 10, 2 );
	}

	/**
	 * Tell WordPress these are real 200 pages.
	 *
	 * Without this, the main query finds no post and WordPress considers the
	 * request a 404 internally — even though we send a 200 header. Everything
	 * that is context-aware then behaves as if the page does not exist.
	 *
	 * That is not theoretical: it silently stopped Google Analytics loading on
	 * the entire register section, because Site Kit skips 404s. Rank Math,
	 * caching layers and anything else keyed on is_404() were making the same
	 * wrong assumption. A page that reports 200 to the browser and 404 to its
	 * own plugins is the worst of both worlds.
	 */
	public function claim_query(): void {
		if ( ! $this->is_standing_request() ) {
			return;
		}
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query ) {
			return;
		}
		// Describe the request as an archive rather than leaving every flag
		// false. A query that claims to be nothing at all is almost as bad as
		// a 404: plugins that classify the request before acting simply bail,
		// which is how Analytics went missing here in the first place. And
		// "archive" is honest — a register listing is exactly that.
		$wp_query->is_404                = false;
		$wp_query->is_home               = false;
		$wp_query->is_singular           = false;
		$wp_query->is_page               = false;
		$wp_query->is_single             = false;
		$wp_query->is_archive            = true;
		$wp_query->is_post_type_archive  = false;
		$wp_query->is_tax                = false;
		$wp_query->is_feed               = false;
		$wp_query->found_posts           = 1;
		$wp_query->post_count            = 1;
		$wp_query->max_num_pages         = 1;
		status_header( 200 );
	}

	public function register_rules(): void {
		$base = self::BASE;

		add_rewrite_rule( "^{$base}/?$", 'index.php?sm_standing=home', 'top' );
		add_rewrite_rule( "^{$base}/([^/]+)/?$", 'index.php?sm_standing=section&sm_standing_a=$matches[1]', 'top' );
		add_rewrite_rule( "^{$base}/([^/]+)/([^/]+)/?$", 'index.php?sm_standing=entity&sm_standing_a=$matches[1]&sm_standing_b=$matches[2]', 'top' );
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'sm_standing';
		$vars[] = 'sm_standing_a';
		$vars[] = 'sm_standing_b';
		return $vars;
	}

	public function is_standing_request(): bool {
		return (bool) get_query_var( 'sm_standing' );
	}

	/**
	 * Resolve the request into a view, a title and breadcrumbs.
	 */
	public function context(): array {
		if ( null !== $this->context ) {
			return $this->context;
		}

		$mode = (string) get_query_var( 'sm_standing' );
		if ( ! $mode ) {
			return $this->context = [];
		}

		$a    = sanitize_title( (string) get_query_var( 'sm_standing_a' ) );
		$b    = sanitize_title( (string) get_query_var( 'sm_standing_b' ) );
		$data = Data::instance();

		$crumbs = [
			[ 'label' => 'Home', 'url' => home_url( '/' ) ],
			[ 'label' => 'Standing', 'url' => home_url( '/standing/' ) ],
		];

		if ( 'home' === $mode ) {
			return $this->context = [
				'view'    => 'home',
				'title'   => 'Is your school still approved to take international students?',
				'desc'    => 'Type your school. We tell you where it stands on the official register of its '
					. 'country, what it said before, and what that means if you hold an offer.',
				'crumbs'  => $crumbs,
				'index'   => true,
			];
		}

		if ( 'section' === $mode ) {
			if ( in_array( $a, self::SECTIONS, true ) ) {
				$titles = [
					'changes'          => [ 'What changed on the official registers', 'Every recorded change across every register we hold, newest first.' ],
					'no-longer-listed' => [ 'Institutions no longer listed', 'Entries that appeared on one edition of an official register and not on the next. A disappearance is not evidence of wrongdoing.' ],
					'watchlist'        => [ 'Institutions carrying a published condition', 'Where a register publishes something beyond listed or not listed, such as probation, conditions or an action plan, we quote it exactly as it appears.' ],
					'methodology'      => [ 'How we read each register, and what we refuse to say', 'The sources, the retrieval schedule, the checks that stop us publishing a false removal, and the sentences we will not write.' ],
					'corrections'      => [ 'Corrections and right of reply', 'If your institution appears here and the record is wrong, this is how to have it corrected. We respond to every request.' ],
					'archive'          => [ 'Every edition we hold', 'Each retrieved edition, dated and hashed, so any claim on this site can be checked against the source it came from.' ],
					'countries'        => [ 'Which countries publish a list of approved institutions, and which do not', 'Not every destination maintains a public register. Where one does not exist, no monitor can tell you anything, and you deserve to know that.' ],
					'data'             => [ 'The open data behind this site', 'The change record, the archive index and the licences. Free to use, with attribution.' ],
				];
				[ $title, $desc ] = $titles[ $a ] ?? [ ucfirst( $a ), '' ];
				$crumbs[]         = [ 'label' => $title, 'url' => home_url( "/standing/{$a}/" ) ];

				return $this->context = [
					'view'    => $a,
					'title'   => $title,
					'desc'    => $desc,
					'crumbs'  => $crumbs,
					'index'   => true,
				];
			}

			$country = $data->country( $a );
			if ( $country ) {
				// The sitemap lists the country-name form, so that is the one
				// address of record. Reaching the page by source id is
				// legitimate and stays reachable; it just stops competing.
				$primary  = home_url( '/standing/' . $data->slug( $country['country'] ) . '/' );
				$crumbs[] = [ 'label' => $country['country'], 'url' => $primary ];
				return $this->context = [
					'canonical' => $primary,
					'view'    => 'country',
					'country' => $country,
					'title'   => sprintf( 'Is your school approved to take international students in %s?', $country['country'] ),
					'desc'    => sprintf(
						'The %s, published by %s. We hold %d %s since %s and have recorded %d %s.',
						$country['register'],
						$country['publisher'],
						(int) $country['editions_held'],
						1 === (int) $country['editions_held'] ? 'edition' : 'editions',
						$country['recording_since'],
						(int) $country['changes_recorded'],
						1 === (int) $country['changes_recorded'] ? 'change' : 'changes'
					),
					'crumbs'  => $crumbs,
					'index'   => true,
				];
			}

			return $this->context = [ 'view' => '404' ];
		}

		// entity: /standing/<country>/<institution-key>/
		$country = $data->country( $a );
		if ( ! $country ) {
			return $this->context = [ 'view' => '404' ];
		}

		$record = $this->find_entity( $country['source_id'], $b );
		if ( ! $record ) {
			return $this->context = [ 'view' => '404' ];
		}

		$crumbs[] = [ 'label' => $country['country'], 'url' => home_url( "/standing/{$a}/" ) ];
		$crumbs[] = [ 'label' => $record['name'], 'url' => home_url( "/standing/{$a}/{$b}/" ) ];

		return $this->context = [
			'view'    => 'entity',
			'country' => $country,
			'record'  => $record,
			'title'   => sprintf( '%s: standing on the %s', $record['name'], $country['register'] ),
			'desc'    => sprintf(
				'What the official %s register records about %s, and what it recorded before.',
				$country['country'],
				$record['name']
			),
			'crumbs'  => $crumbs,
			// THE THIN-CONTENT RULE: index only if this entity has a story.
			'index'   => ! empty( $record['changes'] ) || ! empty( $record['flags'] ),
		];
	}

	/**
	 * Assemble everything we know about one institution.
	 */
	private function find_entity( string $source_id, string $key ): ?array {
		$data     = Data::instance();
		$register = $data->register( $source_id );
		$changes  = $data->changes( $source_id );

		$name    = null;
		$row     = null;
		$listed  = false;

		foreach ( ( $register['rows'] ?? [] ) as $r ) {
			foreach ( Data::KEY_FIELDS as $k ) {
				if ( ! empty( $r[ $k ] ) && $data->matches_slug( (string) $r[ $k ], $key ) ) {
					$row    = $r;
					$listed = true;
					break 2;
				}
			}
			foreach ( Data::NAME_FIELDS as $k ) {
				if ( ! empty( $r[ $k ] ) && $data->matches_slug( (string) $r[ $k ], $key ) ) {
					$row    = $r;
					$listed = true;
					break 2;
				}
			}
		}

		// Full history from the compact index — one lookup, not a scan.
		$mine = $data->entity_history( $source_id, $key );
		if ( $mine ) {
			$name ??= $mine[0]['name'] ?? null;
		} else {
			// Fall back to the recent slice if the index has not arrived yet.
			foreach ( $changes as $ch ) {
				if ( $data->matches_slug( (string) ( $ch['key'] ?? '' ), $key )
					|| $data->matches_slug( (string) ( $ch['name'] ?? '' ), $key ) ) {
					$mine[] = $ch;
					$name ??= $ch['name'] ?? null;
				}
			}
		}

		if ( ! $row && ! $mine ) {
			return null;
		}

		if ( $row ) {
			foreach ( Data::NAME_FIELDS as $k ) {
				if ( ! empty( $row[ $k ] ) ) {
					$name = (string) $row[ $k ];
					break;
				}
			}
		}

		// Shared with the sitemap, which must advertise exactly the pages this
		// decides to index. See Data::FLAG_FIELDS.
		$flags = $data->row_flags( is_array( $row ) ? $row : [] );

		return [
			'key'     => $key,
			'name'    => $name ?: $key,
			'listed'  => $listed,
			'row'     => $row,
			'flags'   => $flags,
			'changes' => $mine,
		];
	}

	public function maybe_render(): void {
		if ( ! $this->is_standing_request() ) {
			return;
		}
		$ctx = $this->context();
		if ( ( $ctx['view'] ?? '' ) === '404' ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		Render::instance()->page( $ctx );
		exit;
	}

	public function robots( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$extra = [
			'',
			'# The Standing Register: open data, free to crawl and cite.',
			'Sitemap: ' . home_url( '/standing-sitemap.xml' ),
			'',
			'# Machine-readable summary for AI systems:',
			'# ' . home_url( '/llms.txt' ),
		];
		return $output . implode( "\n", $extra ) . "\n";
	}
}
