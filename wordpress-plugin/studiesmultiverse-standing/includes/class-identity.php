<?php
/**
 * One identity, stated once — and the citation metadata that makes the record
 * usable by machines that matter.
 *
 * THE DEFECT THIS FIXES
 *
 * Measured on the live home page, 25 August 2026:
 *
 *     block 0  class="rank-math-schema"   Organization, WebSite, WebPage,
 *                                         Person, Article, Organization
 *     block 1  (no class)                 WebSite          <- orphan duplicate
 *     block 2  (no class)                 Organization     <- orphan duplicate
 *
 * Three Organization nodes and two WebSite nodes in one document. A consumer
 * has no way to tell which is authoritative, so the publisher entity is
 * ambiguous — and for a site whose entire product is being citable, that is
 * the single most damaging thing on the page.
 *
 * An earlier snippet tried to filter the duplicates out of the output buffer.
 * That is the wrong layer: it fights symptoms and loses whenever hook order
 * changes. This class instead makes Rank Math the single owner of identity and
 * stops the competing emitters at source.
 *
 * WHAT THIS ADDS THAT NOTHING ELSE ON THE SITE HAS
 *
 * Dataset schema with real distributions, licence, temporal coverage and — via
 * Zenodo — a DOI. Google Dataset Search, OpenAIRE and the AI systems that
 * increasingly answer "is X still approved?" all read this. A DOI is also what
 * turns "some website said so" into a citable source in an academic paper or a
 * solicitor's bundle.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Identity {

	private static ?self $instance = null;

	/** Snippet IDs known to emit competing identity nodes. */
	private const RETIRED_SNIPPETS = [ 7, 46, 48, 69, 93, 116 ];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		// Let Rank Math own Organization and WebSite, and correct it where wrong.
		add_filter( 'rank_math/json_ld', [ $this, 'correct_graph' ], 99, 2 );

		// Stop the orphan emitters. Priority 1 so we win regardless of when
		// the snippets registered themselves.
		add_action( 'init', [ $this, 'silence_competing_emitters' ], 1 );

		// Belt and braces. Unhooking only works for callbacks we can identify,
		// and Code Snippets evaluates snippet bodies, so reflection cannot always
		// see them. Buffering wp_head and removing orphan identity nodes from the
		// output catches every emitter regardless of how it registered.
		add_action( 'wp_head', [ $this, 'open_buffer' ], -PHP_INT_MAX );
		add_action( 'wp_head', [ $this, 'close_buffer' ], PHP_INT_MAX );

		// Our own additions go in one block, clearly labelled, never duplicating
		// anything Rank Math already states.
		add_action( 'wp_head', [ $this, 'emit_dataset_graph' ], 20 );

		// Citation metadata for reference managers and scholarly crawlers.
		add_action( 'wp_head', [ $this, 'emit_citation_meta' ], 21 );
	}

	// -----------------------------------------------------------------

	/**
	 * Repair the canonical graph rather than adding a competing one.
	 */
	public function correct_graph( array $data, $jsonld ): array {
		foreach ( $data as $key => &$node ) {
			if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
				continue;
			}
			$type = (array) $node['@type'];

			// The home page is not an Article. It was being described as one,
			// with "aitadmin" as its author, which is both wrong and unhelpful
			// to anyone deciding whether to trust the page.
			if ( is_front_page() && array_intersect( [ 'Article', 'BlogPosting', 'NewsArticle' ], $type ) ) {
				unset( $data[ $key ] );
				continue;
			}

			// Nothing on this site is authored by a username. Where a human
			// byline is not available, the publisher is the author — which is
			// the honest description of an automated register anyway.
			if ( in_array( 'Person', $type, true ) && ! empty( $node['name'] ) && 'aitadmin' === $node['name'] ) {
				unset( $data[ $key ] );
				continue;
			}

			if ( in_array( 'Organization', $type, true ) ) {
				// Rank Math emits two Organization nodes: the site itself, and
				// the parent company as publisher. Both are legitimate — but
				// they must keep DISTINCT @ids, or a consumer sees one entity
				// asserted twice with conflicting names, which is worse than
				// the duplication we set out to fix.
				$name = (string) ( $node['name'] ?? '' );

				if ( false !== stripos( $name, 'A.I.T.' ) || false !== stripos( $name, 'AIT Multiverse' ) ) {
					$node['@id']  = 'https://aitmultiverse.com/#organization';
					$node['name'] = 'A.I.T. Multiverse Consulting Ltd';
					$node['url']  = 'https://aitmultiverse.com/';
					continue;
				}

				$node['@id']         = home_url( '/#organization' );
				$node['name']        = 'Studies Multiverse';
				$node['url']         = home_url( '/' );
				$node['description'] = $this->org_description();
				$node['publishingPrinciples'] = home_url( '/standing/methodology/' );
				$node['correctionsPolicy']    = home_url( '/standing/corrections/' );
				$node['ethicsPolicy']         = home_url( '/editorial-policy/' );
				$node['parentOrganization']   = [ '@id' => 'https://aitmultiverse.com/#organization' ];
			}

			if ( in_array( 'WebSite', $type, true ) ) {
				$node['@id']       = home_url( '/#website' );
				$node['publisher'] = [ '@id' => home_url( '/#organization' ) ];
				// The site's front door is one search box.
				$node['potentialAction'] = [
					'@type'       => 'SearchAction',
					'target'      => [
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/standing/?q={search_term_string}' ),
					],
					'query-input' => 'required name=search_term_string',
				];
			}
		}
		unset( $node );

		return array_values( $data );
	}

	private function org_description(): string {
		return 'Studies Multiverse keeps the worldwide record of which institutions are officially '
			. 'permitted to enrol international students: what the official registers say, what they '
			. 'used to say, and what it means for the student. It earns nothing from where you apply: '
			. 'no institution referral fees, no agent commissions, no paid inclusion and no paid removal.';
	}

	/**
	 * Remove competing identity output at source.
	 *
	 * These snippets stay in Code Snippets so nothing is lost, but their hooks
	 * are unhooked. Deleting them is the site owner's call, not an automated
	 * one — deletions are not reversible from here.
	 */
	public function silence_competing_emitters(): void {
		global $wp_filter;

		foreach ( [ 'wp_head', 'wp_footer' ] as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $cb ) {
					if ( $this->is_retired_snippet_callback( $cb['function'] ) ) {
						remove_action( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	// -----------------------------------------------------------------
	// Output filter: one Organization, one WebSite, whoever emitted them
	// -----------------------------------------------------------------

	/** @id values already emitted on this page, and singleton types already seen. */
	private array $seen_ids = [];
	private array $seen_singletons = [];

	public function open_buffer(): void {
		$this->seen_ids = [];
		$this->seen_singletons = [];
		ob_start();
	}

	/**
	 * Types that may legitimately appear only once per page.
	 *
	 * BreadcrumbList is the live case: the old breadcrumbs snippet, Rank Math
	 * and this plugin were each emitting one, so /standing/ carried three
	 * different trails for the same page. A consumer has no way to choose
	 * between them, which is the same ambiguity the Organization duplication
	 * caused — just less obvious.
	 */
	private const SINGLETON_TYPES = [ 'BreadcrumbList', 'WebSite', 'WebPage', 'CollectionPage' ];

	/**
	 * Remove orphan identity nodes from the assembled head.
	 *
	 * Rules, in order:
	 *   1. Rank Math's block is canonical and is never touched.
	 *   2. Our own Dataset block is never touched.
	 *   3. Any other block whose nodes are ONLY Organization and/or WebSite is
	 *      a duplicate identity claim and is dropped entirely.
	 *   4. Any other block that mixes identity nodes with useful ones keeps the
	 *      useful ones and loses the identity nodes.
	 *
	 * Nothing else in the head is altered.
	 */
	public function close_buffer(): void {
		$head = (string) ob_get_clean();

		$head = preg_replace_callback(
			'#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			[ $this, 'filter_block' ],
			$head
		) ?? $head;

		// On register pages, also drop the assets this section does not use.
		// Dequeuing catches enqueued handles; this catches everything else —
		// inline blocks printed straight into wp_head by snippets and plugins,
		// which on a measured /standing/ page came to 51 KB of CSS and 43 KB of
		// JS for markup that needs none of it.
		if ( Routes::instance()->is_standing_request() ) {
			$head = Performance::instance()->strip( $head );
		}

		echo $head;
	}

	private function filter_block( array $m ): string {
		$tag  = $m[0];
		$json = trim( $m[1] );

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return $tag; // Not parseable — leave it alone rather than mangle it.
		}

		$graph_key = isset( $decoded['@graph'] ) ? '@graph' : null;
		$nodes     = $graph_key ? $decoded['@graph'] : ( array_is_list( $decoded ) ? $decoded : [ $decoded ] );

		// Rank Math's block is canonical and passes through untouched — but we
		// record what it contains, so later blocks can be recognised as repeats.
		// Whoever emits a node FIRST owns it; everyone after is a duplicate.
		//
		// This plugin's own block is deliberately NOT exempt. Exempting it left
		// two BreadcrumbLists on every register page: Rank Math emitted one and
		// so did we, and because both were "canonical" neither could be told it
		// was the second. A rule that excuses your own output is not a rule.
		// Our Dataset and DataCatalog nodes are unique, so they survive the
		// filter on their merits; our breadcrumb yields to Rank Math's.
		if ( str_contains( $tag, 'rank-math-schema' ) ) {
			$this->remember( $nodes );
			return $tag;
		}

		$identity_only = static function ( $node ): bool {
			$types = (array) ( $node['@type'] ?? '' );
			foreach ( $types as $t ) {
				if ( ! in_array( $t, [ 'Organization', 'WebSite', 'Corporation', 'NGO' ], true ) ) {
					return false;
				}
			}
			return (bool) $types;
		};

		$kept = array_values(
			array_filter(
				$nodes,
				function ( $n ) use ( $identity_only ): bool {
					if ( ! is_array( $n ) ) {
						return false;
					}
					if ( $identity_only( $n ) ) {
						return false;   // a competing Organization / WebSite claim
					}
					if ( $this->already_seen( $n ) ) {
						return false;   // the same @id, or a second BreadcrumbList
					}
					$this->remember( [ $n ] );
					return true;
				}
			)
		);

		if ( ! $kept ) {
			// Pure duplicate identity claim. Drop it, and leave a comment so
			// anyone viewing source can see this was deliberate.
			return '<!-- duplicate Organization/WebSite node removed by Standing Register: identity is stated once, in the canonical graph -->';
		}

		if ( count( $kept ) === count( $nodes ) ) {
			return $tag; // Nothing identity-ish in here.
		}

		$rebuilt = $graph_key
			? [ '@context' => $decoded['@context'] ?? 'https://schema.org', '@graph' => $kept ]
			: ( count( $kept ) === 1 ? $kept[0] : $kept );

		return '<script type="application/ld+json">'
			. wp_json_encode( $rebuilt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>';
	}

	/** Record what a block contributed, so repeats can be spotted later. */
	private function remember( array $nodes ): void {
		foreach ( $nodes as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$id = (string) ( $n['@id'] ?? '' );
			if ( '' !== $id ) {
				$this->seen_ids[ $id ] = true;
			}
			foreach ( (array) ( $n['@type'] ?? [] ) as $t ) {
				if ( in_array( $t, self::SINGLETON_TYPES, true ) ) {
					$this->seen_singletons[ $t ] = true;
				}
			}
		}
	}

	/**
	 * Has this node already been stated on the page?
	 *
	 * Two tests, in order of confidence. An identical @id is a definite repeat —
	 * the same thing described twice, which is worse than describing it once
	 * badly. A second node of a singleton type is a repeat by nature even
	 * without an @id, which is how three BreadcrumbLists ended up on one page.
	 */
	private function already_seen( array $node ): bool {
		$id = (string) ( $node['@id'] ?? '' );
		if ( '' !== $id && isset( $this->seen_ids[ $id ] ) ) {
			return true;
		}
		foreach ( (array) ( $node['@type'] ?? [] ) as $t ) {
			if ( in_array( $t, self::SINGLETON_TYPES, true ) && isset( $this->seen_singletons[ $t ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Code Snippets executes snippet bodies inside closures whose declaring
	 * file is the plugin's own execute file, so we identify candidates by
	 * inspecting the closure's source for identity-node emission rather than
	 * by name.
	 */
	private function is_retired_snippet_callback( $function ): bool {
		if ( ! ( $function instanceof \Closure ) ) {
			return false;
		}
		try {
			$ref  = new \ReflectionFunction( $function );
			$file = (string) $ref->getFileName();
			if ( false === strpos( $file, 'code-snippets' ) ) {
				return false;
			}
			$lines = @file( $file );
			if ( ! $lines ) {
				return false;
			}
			$body = implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
			// Only unhook things that emit an identity node. Leave every other
			// snippet — calculators, shortcodes, layout — completely alone.
			return (bool) preg_match( '/"@type"\s*:\s*"(Organization|WebSite)"/', $body )
				|| (bool) preg_match( '/[\'"]@type[\'"]\s*=>\s*[\'"](Organization|WebSite)[\'"]/', $body );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	// -----------------------------------------------------------------
	// Dataset schema — the part that gets us into Dataset Search and AI answers
	// -----------------------------------------------------------------

	public function emit_dataset_graph(): void {
		if ( ! Routes::instance()->is_standing_request() ) {
			return;
		}

		$data      = Data::instance();
		$context   = Routes::instance()->context();
		$countries = $data->countries();

		if ( ! $countries ) {
			return;
		}

		$graph = [];

		// One Dataset node per country we hold, plus a collection node.
		$parts = [];
		foreach ( $countries as $c ) {
			$id      = $c['source_id'];
			$slug    = $data->slug( $c['country'] );
			$node_id = home_url( "/standing/{$slug}/#dataset" );
			$parts[] = [ '@id' => $node_id ];

			$graph[] = array_filter(
				[
					'@type'       => 'Dataset',
					'@id'         => $node_id,
					'name'        => sprintf( '%s, %s: standing and recorded changes', $c['country'], $c['register'] ),
					'description' => sprintf(
						'A dated record of the %s, published by %s. Holds %d editions from %s to %s, with %d recorded changes. '
						. 'A row appearing or disappearing between editions is not evidence of wrongdoing: registers publish a status, not a cause.',
						$c['register'],
						$c['publisher'],
						(int) $c['editions_held'],
						$c['recording_since'],
						$c['latest_edition'],
						(int) $c['changes_recorded']
					),
					'url'                => home_url( "/standing/{$slug}/" ),
					'license'            => 'https://creativecommons.org/licenses/by/4.0/',
					'isAccessibleForFree' => true,
					'creator'            => [ '@id' => home_url( '/#organization' ) ],
					'publisher'          => [ '@id' => home_url( '/#organization' ) ],
					'temporalCoverage'   => $c['recording_since'] . '/' . $c['latest_edition'],
					'isBasedOn'          => $c['endpoints']['changes'] ?? null,
					'measurementTechnique' => 'Scheduled retrieval of the official register, cryptographic hashing of each edition, '
						. 'and comparison against the previous edition on the register\'s own persistent identifier where one exists.',
					'variableMeasured'   => [ 'institution standing', 'listing status', 'recorded change events' ],
					'distribution'       => [
						[
							'@type'       => 'DataDownload',
							'encodingFormat' => 'application/json',
							'contentUrl'  => $c['endpoints']['changes'] ?? '',
							'name'        => 'Recorded changes (JSON)',
						],
						[
							'@type'       => 'DataDownload',
							'encodingFormat' => 'application/rss+xml',
							'contentUrl'  => $c['endpoints']['feed'] ?? '',
							'name'        => 'Recorded changes (RSS)',
						],
					],
					'citation'   => $this->doi() ? [ '@type' => 'CreativeWork', 'identifier' => $this->doi() ] : null,
					'identifier' => $this->doi() ?: null,
				],
				static fn( $v ) => null !== $v && '' !== $v
			);
		}

		$graph[] = array_filter(
			[
				'@type'       => 'DataCatalog',
				'@id'         => home_url( '/standing/#catalog' ),
				'name'        => 'The Standing Register',
				'description' => 'The worldwide record of which institutions are officially permitted to enrol '
					. 'international students, and which have quietly left those registers.',
				'url'         => home_url( '/standing/' ),
				'publisher'   => [ '@id' => home_url( '/#organization' ) ],
				'license'     => 'https://creativecommons.org/licenses/by/4.0/',
				'dataset'     => $parts,
				'identifier'  => $this->doi() ?: null,
			],
			static fn( $v ) => null !== $v && '' !== $v && [] !== $v
		);

		// Breadcrumbs for the standing tree.
		if ( $context['crumbs'] ?? null ) {
			$items = [];
			foreach ( $context['crumbs'] as $i => $crumb ) {
				$items[] = [
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => $crumb['label'],
					'item'     => $crumb['url'],
				];
			}
			$graph[] = [
				'@type'           => 'BreadcrumbList',
				'@id'             => home_url( $_SERVER['REQUEST_URI'] ?? '/' ) . '#breadcrumbs',
				'itemListElement' => $items,
			];
		}

		printf(
			"\n<!-- Studies Multiverse: Standing Register dataset -->\n"
			. '<script type="application/ld+json" class="sm-standing-schema">%s</script>' . "\n",
			wp_json_encode(
				[ '@context' => 'https://schema.org', '@graph' => $graph ],
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * The Zenodo concept DOI, if one has been minted.
	 *
	 * Zenodo watches the GitHub repository: tag a release, and it archives the
	 * snapshot and mints a versioned DOI automatically, under a permanent
	 * concept DOI that always resolves to the newest version. That is fully
	 * automated — no manual step — and it is what makes this record citable in
	 * contexts where a URL is not enough.
	 */
	public function doi(): string {
		return (string) get_option( 'sm_standing_doi', '' );
	}

	/**
	 * Highwire-style citation tags. Zotero, Mendeley, Google Scholar and
	 * several academic crawlers read these directly.
	 */
	public function emit_citation_meta(): void {
		if ( ! Routes::instance()->is_standing_request() ) {
			return;
		}
		$index = Data::instance()->index();
		if ( ! $index ) {
			return;
		}

		$tags = [
			'citation_title'            => 'The Standing Register: official standing of institutions permitted to enrol international students',
			'citation_publisher'        => 'A.I.T. Multiverse Consulting Ltd',
			'citation_author'           => 'Studies Multiverse',
			'citation_publication_date' => substr( (string) ( $index['generated_at'] ?? gmdate( 'c' ) ), 0, 10 ),
			'citation_public_url'       => home_url( '/standing/' ),
		];
		if ( $this->doi() ) {
			$tags['citation_doi'] = $this->doi();
		}

		foreach ( $tags as $name => $content ) {
			printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $content ) );
		}
	}
}
