<?php
/**
 * The public API.
 *
 * This is how other sites and AI systems consume the record. It is free, it
 * needs no key, it is CORS-open, and it is cached hard — because the strategy
 * is to be the source everyone cites, and you do not become that by putting a
 * key in front of the data.
 *
 *   GET /wp-json/standing/v1/                 service description
 *   GET /wp-json/standing/v1/countries        what we hold, per country
 *   GET /wp-json/standing/v1/changes          the change record, filterable
 *   GET /wp-json/standing/v1/institutions     search by name
 *   GET /wp-json/standing/v1/institutions/(id) one institution's full record
 *   GET /wp-json/standing/v1/check            THE OFFER-LETTER CHECK
 *   GET /wp-json/standing/v1/archive          every edition, dated and hashed
 *   GET /wp-json/standing/v1/openapi.json     machine-readable spec
 *
 * THE OFFER-LETTER CHECK
 *
 * A student pastes the codes off their offer letter — institution name, CRICOS
 * provider code and CRICOS course code for Australia, DLI number for Canada,
 * sponsor name for the UK — and every one is validated against the register in
 * a single answer. A legitimate Australian offer letter carries a CRICOS course
 * code; fraudulent and stale ones often do not, or carry one belonging to a
 * different provider. No such checker exists anywhere.
 *
 * It answers carefully. It will say a code is not on the register we hold. It
 * will never say an offer is fake, because a mismatch has innocent explanations
 * and we are not in a position to know which applies.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Api {

	private static ?self $instance = null;

	private const NS = 'standing/v1';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_filter( 'rest_pre_serve_request', [ $this, 'cors' ], 10, 4 );
	}

	/** Open data means open access. */
	public function cors( $served, $result, $request, $server ) {
		$route = ltrim( (string) $request->get_route(), '/' );
		if ( str_starts_with( $route, self::NS ) ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Access-Control-Allow-Methods: GET, OPTIONS' );

			// The offer-letter check is not bulk open data and must not be
			// cached like it.
			//
			// Two reasons, and the second is the one that matters. A shared
			// cache would be storing a URL carrying somebody's provider,
			// course or DLI number off their own offer letter, which is not
			// ours to leave lying in an intermediary. And
			// stale-while-revalidate=86400 permits an answer up to a day old:
			// the response names the edition it read, so it would not be
			// false, but a register check that quietly answers from yesterday
			// is the wrong instinct for the one question this site exists to
			// answer. It is a single hand-typed request, so there is no load
			// worth trading for either.
			if ( str_starts_with( $route, self::NS . '/check' ) ) {
				header( 'Cache-Control: no-store, private' );
			} else {
				header( 'Cache-Control: public, max-age=900, stale-while-revalidate=86400' );
			}

			header( 'X-Licence: CC-BY-4.0' );
		}
		return $served;
	}

	public function routes(): void {
		$public = '__return_true';

		register_rest_route( self::NS, '/', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'service' ],
		] );

		register_rest_route( self::NS, '/countries', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'countries' ],
		] );

		register_rest_route( self::NS, '/changes', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'changes' ],
			'args'                => [
				'country' => [ 'type' => 'string' ],
				'kind'    => [ 'type' => 'string', 'enum' => [ 'removed', 'added', 'renamed', 'modified', 'course_withdrawn_provider_still_listed' ] ],
				'since'   => [ 'type' => 'string', 'description' => 'ISO date; only changes recorded on or after this edition' ],
				'limit'   => [ 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 1000 ],
			],
		] );

		register_rest_route( self::NS, '/institutions', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'institutions' ],
			'args'                => [
				'q'       => [ 'type' => 'string', 'required' => true ],
				'country' => [ 'type' => 'string' ],
				'limit'   => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
			],
		] );

		register_rest_route( self::NS, '/institutions/(?P<country>[a-z0-9-]+)/(?P<key>[^/]+)', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'institution' ],
		] );

		register_rest_route( self::NS, '/check', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'check' ],
			'args'                => [
				'country'       => [ 'type' => 'string', 'required' => true ],
				'institution'   => [ 'type' => 'string' ],
				'provider_code' => [ 'type' => 'string', 'description' => 'CRICOS provider code (Australia)' ],
				'course_code'   => [ 'type' => 'string', 'description' => 'CRICOS course code (Australia)' ],
				'dli'           => [ 'type' => 'string', 'description' => 'DLI number (Canada)' ],
			],
		] );

		register_rest_route( self::NS, '/archive', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'archive' ],
			'args'                => [ 'country' => [ 'type' => 'string' ] ],
		] );

		register_rest_route( self::NS, '/openapi.json', [
			'methods'             => 'GET',
			'permission_callback' => $public,
			'callback'            => [ $this, 'openapi' ],
		] );
	}

	// -----------------------------------------------------------------

	private function envelope( array $payload ): array {
		return array_merge(
			[
				'licence'     => 'CC BY 4.0',
				'licence_url' => 'https://creativecommons.org/licenses/by/4.0/',
				'attribution' => 'Studies Multiverse Standing Register, studiesmultiverse.com',
				'caveat'      => 'A row appearing or disappearing between editions is not evidence of wrongdoing. '
					. 'Registers publish a status, not a cause. An entry can leave a register through withdrawal, '
					. 'merger, rename, voluntary surrender, corporate restructure, lapse at renewal, or a correction '
					. 'by the publisher, and the source does not tell us which.',
				'corrections' => home_url( '/standing/corrections/' ),
				'generated'   => gmdate( 'c' ),
			],
			$payload
		);
	}

	public function service(): \WP_REST_Response {
		return rest_ensure_response(
			$this->envelope(
				[
					'name'        => 'Studies Multiverse Standing Register API',
					'description' => 'Which institutions are officially permitted to enrol international students, '
						. 'what the official registers said before, and what changed.',
					'free'        => true,
					'auth'        => 'none',
					'endpoints'   => [
						'countries'    => rest_url( self::NS . '/countries' ),
						'changes'      => rest_url( self::NS . '/changes' ),
						'institutions' => rest_url( self::NS . '/institutions?q=' ),
						'check'        => rest_url( self::NS . '/check' ),
						'archive'      => rest_url( self::NS . '/archive' ),
						'openapi'      => rest_url( self::NS . '/openapi.json' ),
					],
				]
			)
		);
	}

	public function countries(): \WP_REST_Response {
		return rest_ensure_response( $this->envelope( [ 'countries' => Data::instance()->countries() ] ) );
	}

	public function changes( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$data    = Data::instance();
		$country = (string) $r->get_param( 'country' );
		$kind    = (string) $r->get_param( 'kind' );
		$since   = (string) $r->get_param( 'since' );
		$limit   = (int) $r->get_param( 'limit' );

		if ( $country ) {
			$c = $data->country( sanitize_title( $country ) );
			if ( ! $c ) {
				return rest_ensure_response( new \WP_Error( 'unknown_country', 'No record held for that country.', [ 'status' => 404 ] ) );
			}
			$changes = $data->changes( $c['source_id'] );
			foreach ( $changes as &$ch ) {
				$ch['country'] = $c['country'];
			}
			unset( $ch );
		} else {
			$changes = $data->recent_changes( 5000 );
		}

		if ( $kind ) {
			$changes = array_values( array_filter( $changes, static fn( $c ) => ( $c['kind'] ?? '' ) === $kind ) );
		}
		if ( $since ) {
			$changes = array_values( array_filter( $changes, static fn( $c ) => (string) ( $c['new_edition'] ?? '' ) >= $since ) );
		}

		return rest_ensure_response(
			$this->envelope(
				[
					'count'   => count( $changes ),
					'limit'   => $limit,
					'changes' => array_slice( $changes, 0, $limit ),
				]
			)
		);
	}

	public function institutions( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$q     = trim( (string) $r->get_param( 'q' ) );
		$scope = sanitize_title( (string) $r->get_param( 'country' ) );
		$limit = (int) $r->get_param( 'limit' );

		if ( mb_strlen( $q ) < 2 ) {
			return rest_ensure_response( new \WP_Error( 'query_too_short', 'Give at least two characters.', [ 'status' => 400 ] ) );
		}

		$results = $this->search( $q, $scope, $limit );
		return rest_ensure_response( $this->envelope( [ 'query' => $q, 'count' => count( $results ), 'results' => $results ] ) );
	}

	public function institution( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$data    = Data::instance();
		$slug    = sanitize_title( (string) $r->get_param( 'country' ) );
		$key     = (string) $r->get_param( 'key' );
		$country = $data->country( $slug );

		if ( ! $country ) {
			return rest_ensure_response( new \WP_Error( 'unknown_country', 'No record held for that country.', [ 'status' => 404 ] ) );
		}

		$matches = [];
		foreach ( $data->changes( $country['source_id'] ) as $ch ) {
			if ( (string) ( $ch['key'] ?? '' ) === $key || sanitize_title( (string) ( $ch['name'] ?? '' ) ) === sanitize_title( $key ) ) {
				$matches[] = $ch;
			}
		}

		$row = null;
		foreach ( ( $data->register( $country['source_id'] )['rows'] ?? [] ) as $candidate ) {
			foreach ( $candidate as $v ) {
				if ( is_scalar( $v ) && ( (string) $v === $key || sanitize_title( (string) $v ) === sanitize_title( $key ) ) ) {
					$row = $candidate;
					break 2;
				}
			}
		}

		if ( ! $row && ! $matches ) {
			return rest_ensure_response( new \WP_Error( 'not_found', 'Nothing held under that identifier.', [ 'status' => 404 ] ) );
		}

		return rest_ensure_response(
			$this->envelope(
				[
					'country'        => $country['country'],
					'register'       => $country['register'],
					'key'            => $key,
					'currently_listed' => (bool) $row,
					'latest_edition' => $country['latest_edition'],
					'record'         => $row,
					'changes'        => $matches,
					'page'           => home_url( "/standing/{$slug}/" . sanitize_title( $key ) . '/' ),
				]
			)
		);
	}

	/**
	 * The offer-letter check.
	 */
	public function check( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$data    = Data::instance();
		$slug    = sanitize_title( (string) $r->get_param( 'country' ) );
		$country = $data->country( $slug );

		if ( ! $country ) {
			return rest_ensure_response(
				new \WP_Error(
					'unknown_country',
					'We hold no record for that country. That does not mean an institution there is unapproved. '
					. 'it means we cannot check it. See /standing/countries/.',
					[ 'status' => 404 ]
				)
			);
		}

		$rows    = $data->register( $country['source_id'] )['rows'] ?? [];
		$mirror  = 'mirror' === ( $country['publication_layer'] ?? '' );
		$checks  = [];

		$institution   = trim( (string) $r->get_param( 'institution' ) );
		$provider_code = strtoupper( trim( (string) $r->get_param( 'provider_code' ) ) );
		$course_code   = strtoupper( trim( (string) $r->get_param( 'course_code' ) ) );
		$dli           = strtoupper( trim( (string) $r->get_param( 'dli' ) ) );

		if ( ! $mirror ) {
			return rest_ensure_response(
				$this->envelope(
					[
						'country'   => $country['country'],
						'checkable' => false,
						'reason'    => sprintf(
							'%s reserves republication rights over this register, so we hold only the change record '
							. 'and cannot validate individual codes against it. Check directly with the publisher.',
							$country['publisher']
						),
						'official_source' => $country['source_url'] ?? ( $country['endpoints']['register'] ?? null ),
					]
				)
			);
		}

		if ( $provider_code ) {
			$hit = $this->find_row( $rows, 'CRICOS Provider Code', $provider_code );
			$checks['provider_code'] = [
				'value'  => $provider_code,
				'found'  => (bool) $hit,
				'name'   => $hit['Institution Name'] ?? null,
				'says'   => $hit
					? sprintf( 'CRICOS provider code %s is listed on the edition published %s, as “%s”.', $provider_code, $country['latest_edition'], $hit['Institution Name'] ?? '' )
					: sprintf( 'CRICOS provider code %s does not appear on the edition published %s.', $provider_code, $country['latest_edition'] ),
			];
		}

		if ( $course_code ) {
			// Courses come from their own published file, not the institution
			// register — see the note in publish.py about why.
			$course_rows = ( $data->get( "{$country['source_id']}/courses.json" )['courses'] ?? [] );
			$hit = null;
			foreach ( $course_rows as $c ) {
				if ( strtoupper( trim( (string) ( $c['course_code'] ?? '' ) ) ) === $course_code ) {
					$hit = [
						'CRICOS Course Code'   => $c['course_code'] ?? '',
						'CRICOS Provider Code' => $c['provider_code'] ?? '',
						'Course Name'          => $c['course_name'] ?? '',
						'Institution Name'     => $c['provider_name'] ?? '',
					];
					break;
				}
			}
			$checks['course_code'] = [
				'value'  => $course_code,
				'found'  => (bool) $hit,
				'course_name' => $hit['Course Name'] ?? null,
				'says'   => $hit
					? sprintf(
						'CRICOS course code %s is listed on the edition published %s, as “%s”.',
						$course_code,
						$country['latest_edition'],
						$hit['Course Name'] ?? ''
					)
					: sprintf( 'CRICOS course code %s does not appear on the edition published %s.', $course_code, $country['latest_edition'] ),
			];
			// The high-value cross-check: does the course belong to this provider?
			if ( $hit && $provider_code ) {
				$belongs = strtoupper( (string) ( $hit['CRICOS Provider Code'] ?? '' ) ) === $provider_code;
				$checks['course_belongs_to_provider'] = [
					'match' => $belongs,
					'says'  => $belongs
						? 'The course code is registered to the provider code given.'
						: sprintf(
							'The course code %s is registered on this edition to provider code %s, not to %s. '
							. 'That mismatch usually has an innocent explanation: a teaching partnership, a '
							. 'transcription error, an out-of-date letter. It is still worth asking the institution '
							. 'about it before you pay anything.',
							$course_code,
							$hit['CRICOS Provider Code'] ?? '?',
							$provider_code
						),
				];
			}
		}

		if ( $dli ) {
			$hit = $this->find_row( $rows, 'DLI number', $dli );
			$checks['dli'] = [
				'value' => $dli,
				'found' => (bool) $hit,
				'says'  => $hit
					? sprintf( 'DLI number %s is listed on the edition published %s.', $dli, $country['latest_edition'] )
					: sprintf( 'DLI number %s does not appear on the edition published %s.', $dli, $country['latest_edition'] ),
			];
		}

		if ( $institution ) {
			$found = $this->search( $institution, $slug, 3 );
			$checks['institution'] = [
				'value'   => $institution,
				'found'   => (bool) $found,
				'matches' => $found,
				'says'    => $found
					? sprintf( 'A listed institution matching “%s” was found on the edition published %s.', $institution, $country['latest_edition'] )
					: sprintf(
						'No institution matching “%s” appears on the edition published %s. Institutions are often listed '
						. 'under a legal name that differs from their trading name, so check the exact name on the register '
						. 'before drawing any conclusion.',
						$institution,
						$country['latest_edition']
					),
			];
		}

		if ( ! $checks ) {
			return rest_ensure_response(
				new \WP_Error( 'nothing_to_check', 'Give at least one of: institution, provider_code, course_code, dli.', [ 'status' => 400 ] )
			);
		}

		// Every check must pass, including the ones that report a `match`
		// rather than a `found`.
		//
		// An earlier version read only the `found` keys. A course code that
		// existed but was registered to a DIFFERENT provider therefore came
		// back all_confirmed: true — the single case this endpoint exists to
		// catch, reported as if everything were fine. A checker that says yes
		// to the thing it was built to say no to is worse than no checker.
		$all_found = true;
		foreach ( $checks as $check ) {
			if ( array_key_exists( 'found', $check ) && false === $check['found'] ) {
				$all_found = false;
			}
			if ( array_key_exists( 'match', $check ) && false === $check['match'] ) {
				$all_found = false;
			}
		}

		return rest_ensure_response(
			$this->envelope(
				[
					'country'        => $country['country'],
					'register'       => $country['register'],
					'edition'        => $country['latest_edition'],
					'checkable'      => true,
					'all_confirmed'  => $all_found,
					'checks'         => $checks,
					'what_this_is_not' => 'This confirms what the official register says on the edition named above. '
						. 'It is not a judgement about your offer, your institution or your agent, and a code we cannot '
						. 'find is not proof of fraud. If something here does not match your paperwork, ask the '
						. 'institution directly and check the official register yourself.',
					'official_source' => $country['source_url'] ?? ( $country['endpoints']['register'] ?? null ),
				]
			)
		);
	}

	public function archive( \WP_REST_Request $r ): \WP_REST_Response|\WP_Error {
		$data = Data::instance();
		$slug = sanitize_title( (string) $r->get_param( 'country' ) );

		if ( $slug ) {
			$c = $data->country( $slug );
			if ( ! $c ) {
				return rest_ensure_response( new \WP_Error( 'unknown_country', 'No record held.', [ 'status' => 404 ] ) );
			}
			return rest_ensure_response( $this->envelope( [ 'country' => $c['country'], 'editions' => $data->archive( $c['source_id'] ) ] ) );
		}

		$out = [];
		foreach ( $data->countries() as $c ) {
			$out[ $c['country'] ] = $data->archive( $c['source_id'] );
		}
		return rest_ensure_response( $this->envelope( [ 'archive' => $out ] ) );
	}

	// -----------------------------------------------------------------

	private function find_row( array $rows, string $field, string $value ): ?array {
		foreach ( $rows as $r ) {
			if ( isset( $r[ $field ] ) && strtoupper( trim( (string) $r[ $field ] ) ) === $value ) {
				return $r;
			}
		}
		return null;
	}

	private function search( string $q, string $scope, int $limit ): array {
		$data    = Data::instance();
		$needle  = $this->fold( $q );
		$results = [];

		// A query that survives folding as nothing — "!!!", a lone symbol — must
		// match nothing. str_contains() would otherwise treat it as "match all"
		// and hand back an arbitrary slice of the register as though it were a
		// search result.
		if ( '' === $needle ) {
			return $results;
		}

		foreach ( $data->countries() as $c ) {
			$slug = $data->slug( $c['country'] );
			if ( $scope && $slug !== $scope ) {
				continue;
			}
			foreach ( ( $data->register( $c['source_id'] )['rows'] ?? [] ) as $row ) {
				foreach ( Data::NAME_FIELDS as $f ) {
					if ( empty( $row[ $f ] ) ) {
						continue;
					}
					$name = (string) $row[ $f ];
					if ( str_contains( $this->fold( $name ), $needle ) ) {
						$key       = Data::instance()->row_key( $row ) ?? $name;
						$results[] = array_filter(
							[
								'name'     => $name,
								'key'      => (string) $key,
								'country'  => $c['country'],
								'listed'   => true,
								'edition'  => $c['latest_edition'],
								'url'      => home_url( "/standing/{$slug}/" . $data->entity_slug( (string) $key ) . '/' ),
								// Quoted exactly as the publisher writes them.
								'standing' => $data->row_standing( $row ),
							],
							static fn( $v ) => [] !== $v
						);
						break;
					}
				}
				if ( count( $results ) >= $limit ) {
					break 2;
				}
			}
		}
		return $results;
	}

	/**
	 * Normalise a name or a query for comparison, without deleting the world.
	 *
	 * The first version of this stripped everything outside [a-z0-9 ]. On a
	 * register that was entirely Latin-script that looked harmless. It is not:
	 * folding "東川" produces the empty string, and in PHP str_contains( $any, '' )
	 * is TRUE for every haystack — so a Japanese-language query matched all 1,445
	 * Australian institutions and answered "Canberra Institute of Technology".
	 * Returning nothing would have been a poor answer; returning a confident
	 * wrong one is a much worse answer, and on a site whose front door is "type
	 * your school" it is the answer a reader is least equipped to doubt.
	 *
	 * Accents are still folded so "Café" matches "Cafe". What is removed now is
	 * punctuation and symbols, not every non-Latin letter.
	 */
	private function fold( string $s ): string {
		$s = remove_accents( $s );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$stripped = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $s );
		// preg_* returns null on invalid UTF-8; keep the un-stripped string
		// rather than silently folding a real name to nothing.
		$s = null === $stripped ? $s : $stripped;
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	public function openapi(): \WP_REST_Response {
		$base = rest_url( self::NS );
		return rest_ensure_response(
			[
				'openapi' => '3.1.0',
				'info'    => [
					'title'       => 'Studies Multiverse Standing Register API',
					'version'     => SM_STANDING_VERSION,
					'description' => 'Which institutions are officially permitted to enrol international students, '
						. 'what changed, and when. Free, no authentication, CC BY 4.0.',
					'license'     => [ 'name' => 'CC BY 4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0/' ],
					'contact'     => [ 'url' => home_url( '/standing/corrections/' ) ],
				],
				'servers' => [ [ 'url' => $base ] ],
				'paths'   => [
					'/countries'    => [ 'get' => [ 'summary' => 'Countries we hold a record for', 'responses' => [ '200' => [ 'description' => 'OK' ] ] ] ],
					'/changes'      => [ 'get' => [ 'summary' => 'The change record', 'parameters' => [
						[ 'name' => 'country', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'kind', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'since', 'in' => 'query', 'schema' => [ 'type' => 'string', 'format' => 'date' ] ],
						[ 'name' => 'limit', 'in' => 'query', 'schema' => [ 'type' => 'integer' ] ],
					], 'responses' => [ '200' => [ 'description' => 'OK' ] ] ] ],
					'/institutions' => [ 'get' => [ 'summary' => 'Search institutions by name', 'parameters' => [
						[ 'name' => 'q', 'in' => 'query', 'required' => true, 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'country', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
					], 'responses' => [ '200' => [ 'description' => 'OK' ] ] ] ],
					'/check'        => [ 'get' => [ 'summary' => 'Validate the codes on an offer letter against the official register', 'parameters' => [
						[ 'name' => 'country', 'in' => 'query', 'required' => true, 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'institution', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'provider_code', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'course_code', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
						[ 'name' => 'dli', 'in' => 'query', 'schema' => [ 'type' => 'string' ] ],
					], 'responses' => [ '200' => [ 'description' => 'OK' ] ] ] ],
					'/archive'      => [ 'get' => [ 'summary' => 'Every edition held, dated and hashed', 'responses' => [ '200' => [ 'description' => 'OK' ] ] ] ],
				],
			]
		);
	}
}
