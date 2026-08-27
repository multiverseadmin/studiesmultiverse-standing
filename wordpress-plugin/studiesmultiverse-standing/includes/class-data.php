<?php
/**
 * The data layer.
 *
 * Reads static JSON produced by GitHub Actions. Never parses a register, never
 * diffs, never calls a remote host while rendering a page.
 *
 * The refresh is conditional (ETag / Last-Modified), so a check that finds
 * nothing costs one 304 and no bandwidth. If a refresh fails for any reason,
 * the previous file keeps serving — a stale register is recoverable, a broken
 * one is not.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Data {

	private static ?self $instance = null;

	/** In-request memo so one page render never reads the same file twice. */
	private array $memo = [];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'sm_standing_refresh', [ $this, 'refresh_all' ] );
		add_action( 'admin_post_sm_standing_refresh_now', [ $this, 'handle_manual_refresh' ] );
	}

	// -----------------------------------------------------------------
	// Reading
	// -----------------------------------------------------------------

	private function path( string $file ): string {
		return trailingslashit( data_dir() ) . ltrim( $file, '/' );
	}

	/**
	 * Read one cached JSON file. Returns null rather than throwing: a missing
	 * file must degrade to "we have nothing to show" and never to a fatal.
	 */
	public function get( string $file ): ?array {
		if ( array_key_exists( $file, $this->memo ) ) {
			return $this->memo[ $file ];
		}

		$path = $this->path( $file );
		$data = null;

		if ( is_readable( $path ) ) {
			$raw = file_get_contents( $path );
			if ( false !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
				}
			}
		}

		return $this->memo[ $file ] = $data;
	}

	/** The cross-country index. */
	public function index(): ?array {
		return $this->get( 'standing.json' );
	}

	/** Every country we hold data for, sorted by name. */
	public function countries(): array {
		$index = $this->index();
		return $index['countries'] ?? [];
	}

	public function country( string $slug ): ?array {
		foreach ( $this->countries() as $c ) {
			if ( $this->slug( $c['country'] ) === $slug || $c['source_id'] === $slug ) {
				return $c;
			}
		}
		return null;
	}

	public function changes( string $source_id ): array {
		$data = $this->get( "{$source_id}/changes.json" );
		return $data['changes'] ?? [];
	}

	public function register( string $source_id ): ?array {
		return $this->get( "{$source_id}/register.json" );
	}

	/**
	 * The full recorded history of one institution.
	 *
	 * Read from the compact entity index rather than by scanning the change
	 * record, so an institution page costs one lookup instead of a walk over
	 * tens of thousands of entries. The prose is rebuilt here from the stored
	 * per-kind caveats, which is why the index can afford to omit it.
	 */
	public function entity_history( string $source_id, string $key ): array {
		$index = $this->get( "{$source_id}/entities.json" );
		if ( ! $index ) {
			return [];
		}

		$entities = $index['entities'] ?? [];
		$caveats  = $index['caveats'] ?? [];

		$rows = $entities[ $key ] ?? null;
		if ( null === $rows ) {
			// Institution keys are slugged in URLs; match on that too.
			$want = sanitize_title( $key );
			foreach ( $entities as $k => $v ) {
				if ( sanitize_title( (string) $k ) === $want ) {
					$rows = $v;
					break;
				}
			}
		}
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $r ) {
			[ $kind, $old, $new, $name, $prev ] = array_pad( (array) $r, 5, null );
			$out[] = [
				'kind'          => (string) $kind,
				'old_edition'   => $old,
				'new_edition'   => $new,
				'name'          => $name,
				'previous_name' => $prev,
				'statement'     => $this->statement_for( (string) $kind, $old, $new, (string) $name, $prev ),
				'caveat'        => (string) ( $caveats[ (string) $kind ] ?? '' ),
			];
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) $b['new_edition'], (string) $a['new_edition'] ) );
		return $out;
	}

	/**
	 * Rebuild a change statement from its parts.
	 *
	 * The wording matches what the engine writes, because it has to: this text
	 * is subject to the same editorial rules. It says what the register showed
	 * on which date, and never why.
	 */
	private function statement_for( string $kind, $old, $new, string $name, $prev ): string {
		switch ( $kind ) {
			case 'removed':
				return sprintf(
					'No longer listed on the edition published %s. It appeared on the edition published %s.',
					$new,
					$old
				);
			case 'added':
				return sprintf(
					'First appears on the edition published %s. It was not on the edition published %s.',
					$new,
					$old
				);
			case 'renamed':
				return sprintf(
					'Listed as “%s” on the edition published %s, having been listed as “%s” on the edition '
					. 'published %s. The register\'s own identifier is unchanged across both editions.',
					$name,
					$new,
					(string) $prev,
					$old
				);
			case 'course_withdrawn_provider_still_listed':
				return sprintf(
					'The course “%s” appears on the edition published %s and does not appear on the edition '
					. 'published %s. The provider remains listed on both editions.',
					$name,
					$old,
					$new
				);
			case 'edition_held_not_interpreted':
				return sprintf(
					'The edition published %s is archived and can be inspected, but no change entries were '
					. 'derived from the step into it.',
					$new
				);
			default:
				return sprintf( 'The record for this entry changed on the edition published %s.', $new );
		}
	}

	public function archive( string $source_id ): array {
		$data = $this->get( "{$source_id}/archive.json" );
		return $data['editions'] ?? [];
	}

	/**
	 * The most recent changes across every country, newest edition first.
	 * This is the site's front-page feed.
	 */
	public function recent_changes( int $limit = 50, ?string $kind = null ): array {
		$all = [];
		foreach ( $this->countries() as $c ) {
			foreach ( $this->changes( $c['source_id'] ) as $ch ) {
				$ch['country']   = $ch['country'] ?? $c['country'];
				$ch['source_id'] = $c['source_id'];
				$all[]           = $ch;
			}
		}
		if ( $kind ) {
			$all = array_values( array_filter( $all, static fn( $c ) => ( $c['kind'] ?? '' ) === $kind ) );
		}

		// Newest first, but significance first within that.
		//
		// Straight reverse-chronological order buries the register's whole
		// point. The July 2026 edition happened to carry a batch of course
		// title tidy-ups — "Systematic" corrected to "Systemic" — and those
		// filled the front page ahead of providers that had left the register
		// entirely. A student scanning this page needs the disappearances
		// first and the spelling corrections last.
		usort(
			$all,
			static function ( array $a, array $b ): int {
				$edition = strcmp( (string) ( $b['new_edition'] ?? '' ), (string) ( $a['new_edition'] ?? '' ) );
				if ( 0 !== $edition ) {
					return $edition;
				}
				return self::weight( $a ) <=> self::weight( $b );
			}
		);
		return array_slice( $all, 0, $limit );
	}

	/**
	 * How much a change matters to a student. Lower sorts first.
	 *
	 * The ordering is a judgement about consequence, not about volume. An
	 * institution leaving a register can end someone's visa; a course changing
	 * its name cannot. Volume runs almost exactly the other way round, which is
	 * why unranked recency reads as noise.
	 */
	private static function weight( array $change ): int {
		$kind  = (string) ( $change['kind'] ?? '' );
		$level = (string) ( $change['level'] ?? '' );

		if ( 'edition_held_not_interpreted' === $kind ) {
			return 0; // we are telling the reader about a gap in our own record
		}
		if ( 'provider' === $level ) {
			return match ( $kind ) {
				'removed'  => 1,
				'modified' => 2,   // compliance and status fields live here
				'renamed'  => 3,
				'added'    => 6,
				default    => 7,
			};
		}
		return match ( $kind ) {
			'course_withdrawn_provider_still_listed' => 4,
			'removed'  => 5,
			'modified' => 8,
			'added'    => 9,
			'renamed'  => 10,  // course title corrections: real, but least urgent
			default    => 11,
		};
	}

	public function age_in_hours(): ?float {
		$path = $this->path( 'standing.json' );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		return ( time() - (int) filemtime( $path ) ) / HOUR_IN_SECONDS;
	}

	public function slug( string $name ): string {
		return sanitize_title( $name );
	}

	// -----------------------------------------------------------------
	// Refreshing
	// -----------------------------------------------------------------

	/**
	 * Pull the index, then each country's files.
	 *
	 * @param bool $force Ignore stored validators and re-download.
	 */
	public function refresh_all( bool $force = false ): array {
		wp_mkdir_p( data_dir() );

		$report = [ 'fetched' => [], 'unchanged' => [], 'failed' => [] ];

		if ( ! $this->fetch( 'standing.json', $force, $report ) ) {
			// Without the index we do not know which countries to pull.
			// Leave everything else alone rather than half-updating.
			return $report;
		}

		// Clear the memo so we read the file we just wrote.
		$this->memo = [];

		foreach ( $this->countries() as $c ) {
			$id = $c['source_id'];
			// Deliberately NOT changes-full.json. The Australian backfill alone
			// produced 42,521 changes — 32 MB with every statement and caveat
			// inline. The site reads the recent slice for its feeds and the
			// compact entity index for institution history; the full file exists
			// for API consumers and auditors, and is never pulled here.
			foreach ( [ 'register.json', 'courses.json', 'changes.json', 'entities.json', 'archive.json' ] as $file ) {
				$this->fetch( "{$id}/{$file}", $force, $report );
			}
		}

		$this->memo = [];
		update_option( 'sm_standing_last_refresh', [ 'at' => time(), 'report' => $report ], false );

		// Rebuild the client-side search index once, here, so page renders
		// never have to assemble it.
		$this->build_search_index();

		return $report;
	}

	/**
	 * Conditional GET of a single file, written atomically.
	 */
	private function fetch( string $file, bool $force, array &$report ): bool {
		$url        = DATA_BASE . $file;
		$validators = get_option( 'sm_standing_validators', [] );
		$headers    = [ 'Accept' => 'application/json' ];

		if ( ! $force && ! empty( $validators[ $file ]['etag'] ) ) {
			$headers['If-None-Match'] = $validators[ $file ]['etag'];
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 20,
				'headers'    => $headers,
				'user-agent' => 'studiesmultiverse-standing/' . SM_STANDING_VERSION,
			]
		);

		if ( is_wp_error( $response ) ) {
			$report['failed'][ $file ] = $response->get_error_message();
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $code ) {
			$report['unchanged'][] = $file;
			return true;
		}

		if ( 200 !== $code ) {
			$report['failed'][ $file ] = "HTTP {$code}";
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		// Refuse to overwrite good data with something that is not JSON.
		// This is the same instinct as the sanity gate, one layer down.
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			$report['failed'][ $file ] = 'response was not valid JSON, so the previous file was kept';
			return false;
		}

		$path = $this->path( $file );
		wp_mkdir_p( dirname( $path ) );

		$tmp = $path . '.tmp';
		if ( false === file_put_contents( $tmp, $body ) ) {
			$report['failed'][ $file ] = 'could not write cache file';
			return false;
		}
		rename( $tmp, $path );

		$validators[ $file ] = [
			'etag'    => wp_remote_retrieve_header( $response, 'etag' ),
			'fetched' => time(),
		];
		update_option( 'sm_standing_validators', $validators, false );

		$report['fetched'][] = $file;
		return true;
	}

	/**
	 * Build the search index once per refresh.
	 *
	 * A prebuilt client-side index means the search box does zero server work
	 * and returns results instantly, which matters because the search box is
	 * the site's front door: "Type your school. We'll tell you where it stands."
	 */
	public function build_search_index(): void {
		$entries = [];

		foreach ( $this->countries() as $c ) {
			$country_slug = $this->slug( $c['country'] );
			$register     = $this->register( $c['source_id'] );

			// Mirror sources: index every listed institution.
			foreach ( ( $register['rows'] ?? [] ) as $row ) {
				$names = $this->row_names( $row );
				if ( ! $names ) {
					continue;
				}
				$key = $this->row_key( $row ) ?? $names[0];
				// One entry per name form, all pointing at the same record.
				foreach ( $names as $name ) {
					$entries[] = [
						'n' => $name,
						'k' => (string) $key,
						'c' => $c['country'],
						's' => $country_slug,
						'f' => 1, // currently listed
					];
				}
			}

			// Every country: index anything that has ever changed, so a
			// search for a delisted institution still finds its record.
			foreach ( $this->changes( $c['source_id'] ) as $ch ) {
				if ( empty( $ch['name'] ) ) {
					continue;
				}
				$entries[] = [
					'n' => $ch['name'],
					'k' => (string) ( $ch['key'] ?? $ch['name'] ),
					'c' => $c['country'],
					's' => $country_slug,
					'f' => ( 'removed' === ( $ch['kind'] ?? '' ) ) ? 0 : 1,
				];
			}
		}

		// De-duplicate on country + key + name, preferring the currently-listed
		// record.
		//
		// The name belongs in the identity. Keying on country + key alone would
		// collapse an institution's alternative names back into one entry, which
		// is precisely what makes a Japanese school findable by its romanised
		// name but not its Japanese one — and it would also throw away a former
		// name, so searching what an old offer letter says would find nothing.
		// Different names for the same record are the point, not duplicates.
		$seen = [];
		foreach ( $entries as $e ) {
			$id = $e['s'] . '|' . strtolower( $e['k'] ) . '|' . strtolower( $e['n'] );
			if ( ! isset( $seen[ $id ] ) || $e['f'] > $seen[ $id ]['f'] ) {
				$seen[ $id ] = $e;
			}
		}

		$index = array_values( $seen );
		usort( $index, static fn( $a, $b ) => strcasecmp( $a['n'], $b['n'] ) );

		$payload = [
			'built'   => gmdate( 'c' ),
			'count'   => count( $index ),
			'entries' => $index,
		];

		$path = $this->path( 'search-index.json' );
		file_put_contents( $path . '.tmp', wp_json_encode( $payload ) );
		rename( $path . '.tmp', $path );
	}

	private function first_of( array $row, array $keys ): ?string {
		foreach ( $keys as $k ) {
			if ( ! empty( $row[ $k ] ) ) {
				return (string) $row[ $k ];
			}
		}
		return null;
	}

	// -----------------------------------------------------------------
	// The column vocabulary
	// -----------------------------------------------------------------

	/**
	 * Every field name a source might call "the institution's name".
	 *
	 * This list used to be written out by hand in five places — the search
	 * index, two lookups in Routes, and two in Api. Japan exposed what that
	 * costs: its rows call the name `jp_inst_name` and the identifier
	 * `certification_number`, so none of the five copies recognised a single
	 * Japanese institution. The register held all 96, the country page rendered,
	 * and the search box — which this plugin calls the site's front door — found
	 * nothing. A source can be live and invisible at the same time.
	 *
	 * One list, referenced everywhere, is the fix. Adding a country now means
	 * adding its column names here once.
	 */
	public const NAME_FIELDS = [
		'Institution Name',
		'Organisation Name',
		// The UK register's column. Adding the country without adding this line
		// made all 1,306 sponsors unfindable — the identical failure Japan had,
		// against the very list built to stop it happening again. A shared
		// vocabulary only helps if adding a source means adding to it.
		'Sponsor Name',
		'sponsor',
		'name',
		'DLI name',
		'Trading Name',
		// Japan publishes three forms of the name. The romanised one comes
		// first because it is what an English-language reader will type.
		'jp_inst_name_alpha',
		'jp_inst_name',
		'jp_inst_name_kana',
	];

	/** Every field name a source might call "the persistent identifier". */
	public const KEY_FIELDS = [
		'CRICOS Provider Code',
		'DLI number',
		'kvk',
		'institutionUid',
		'id',
		'certification_number',
		// Last, so a publisher's own identifier always wins. Sources with no
		// identifier at all — the UK has no sponsor licence number — compose one
		// into 'key', and that composed value has to be what the URL uses: two
		// routes of the same school in the same town share a name, so a
		// name-derived URL would collide and hide one of them.
		'key',
	];

	/**
	 * The URL slug for one record's key.
	 *
	 * Composed keys join their parts with a pipe — "abbey college
	 * cambridge|cambridge|student", "O266157303742|toronto". sanitize_title()
	 * deletes a pipe rather than turning it into a separator, so the parts ran
	 * together: abbey-college-cambridgecambridgechild-student. That resolved
	 * correctly and read like a mistake, which on a site whose whole value is
	 * being worth citing is its own kind of defect — and it also loses the
	 * boundary, so two different keys could in principle collapse to one slug.
	 *
	 * Translating the pipe to a hyphen first fixes both. Nothing in the archive
	 * changes: the stored key is untouched and this is only how it is spelled in
	 * a URL. matches_slug() below still accepts the old spelling.
	 */
	public function entity_slug( string $key ): string {
		return sanitize_title( str_replace( '|', '-', $key ) );
	}

	/** Does this stored key correspond to this URL slug, old spelling or new? */
	public function matches_slug( string $key, string $slug ): bool {
		return $this->entity_slug( $key ) === $slug || sanitize_title( $key ) === $slug;
	}

	/**
	 * Columns whose value is worth showing a reader verbatim.
	 *
	 * Presence on a register is not the whole answer, and for the UK it is
	 * barely half of it. The Home Office publishes a licence tier — "Student
	 * Sponsor - Track Record", "Probationary Sponsor" — and a compliance action,
	 * "Subject To Action Plan". A sponsor can move down that ladder, or be put
	 * under an action plan, without ever leaving the register, so a check that
	 * only answers "is it listed?" reports good news about a school whose
	 * standing just changed materially.
	 *
	 * These are reproduced exactly as the publisher writes them, labelled with
	 * the publisher's own column name. Nothing here ranks the tiers, translates
	 * them, or infers what one means for an applicant — that judgement is not
	 * ours to make and the source does not offer it.
	 */
	public const STANDING_FIELDS = [
		'Status',
		'Immigration Compliance',
		'Sponsor Type',
		'Route',
		'Institution Type',
		'certification_date',
		// Deliberately NOT here: Japan's opening_status. The source publishes it
		// as a bare code — "1" — and this project does not turn a publisher's
		// code into words, because that is interpretation and the source offers
		// no key. But showing a reader "opening_status: 1" is not transparency
		// either; it is noise wearing transparency's clothes, and it invites the
		// reader to guess. It stays in the archive and in Japan's watch fields,
		// so a CHANGE to it is still detected and dated — we simply do not put
		// an undecodable number in front of someone checking their school.
	];

	/** The publisher-stated standing fields present on one row, in order. */
	public function row_standing( array $row ): array {
		$out = [];
		foreach ( self::STANDING_FIELDS as $f ) {
			if ( isset( $row[ $f ] ) && '' !== trim( (string) $row[ $f ] ) ) {
				$out[ $f ] = (string) $row[ $f ];
			}
		}
		return $out;
	}

	/**
	 * The fields that give an institution page a story worth indexing.
	 *
	 * Deliberately narrower than STANDING_FIELDS. A provider code, an address
	 * or an institution type describes a row; a published status or compliance
	 * entry says something happened. Only the second earns a place in search
	 * results, because the first produces one near-identical page per register
	 * row and nothing a reader could not get from the country page.
	 *
	 * This lives here because two places ask the question and they must not
	 * disagree: the router decides whether an entity page is indexable, and
	 * the sitemap decides whether to advertise it. They drifted once. The
	 * sitemap listed all 96 Japanese institutions and 1,545 Australian ones
	 * while every one of those pages rendered noindex, which asks a crawler to
	 * fetch pages we have already told it to ignore.
	 */
	public const FLAG_FIELDS = [ 'compliance', 'Immigration Compliance', 'status', 'Status' ];

	/** The published-condition fields present on one row, in order. */
	public function row_flags( array $row ): array {
		$out = [];
		foreach ( self::FLAG_FIELDS as $f ) {
			if ( isset( $row[ $f ] ) && '' !== trim( (string) $row[ $f ] ) ) {
				$out[ $f ] = (string) $row[ $f ];
			}
		}
		return $out;
	}

	public function row_name( array $row ): ?string {
		return $this->first_of( $row, self::NAME_FIELDS );
	}

	public function row_key( array $row ): ?string {
		return $this->first_of( $row, self::KEY_FIELDS );
	}

	/**
	 * Every distinct name a row carries, so search matches any of them.
	 *
	 * A reader looking for a Japanese school may type the romanised name, the
	 * Japanese name, or paste the kana from an offer letter. Indexing only one
	 * of the three would make the other two searches fail against a register we
	 * demonstrably hold.
	 */
	public function row_names( array $row ): array {
		$out = [];
		foreach ( self::NAME_FIELDS as $f ) {
			if ( ! empty( $row[ $f ] ) ) {
				$out[] = (string) $row[ $f ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	public function search_index_url(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . 'sm-standing/search-index.json';
	}

	public function handle_manual_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'sm_standing_refresh' ) ) {
			wp_die( 'Not permitted.' );
		}
		$this->refresh_all( true );
		wp_safe_redirect( admin_url( 'options-general.php?page=sm-standing&refreshed=1' ) );
		exit;
	}
}
