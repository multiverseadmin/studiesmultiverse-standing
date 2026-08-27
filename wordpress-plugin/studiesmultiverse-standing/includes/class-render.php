<?php
/**
 * Rendering.
 *
 * These pages are served as complete, self-contained documents rather than
 * through the Elementor theme. That is a deliberate performance decision: the
 * measured home page pulls 16 stylesheets, 21 external scripts, 28 inline style
 * blocks and 37 inline script blocks for 194 KB of HTML. A register that a
 * worried student loads on a phone in a hostel cannot afford that.
 *
 * What is served instead: one document, critical CSS inlined, no external CSS,
 * no fonts, no framework, and the search index fetched lazily only when someone
 * actually types. No database queries and no remote calls on render.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Render {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function page( array $ctx ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		// Static content, safe to cache hard at the edge; revalidate often
		// enough that a new edition shows up the same day.
		header( 'Cache-Control: public, max-age=900, stale-while-revalidate=86400' );

		$title = $ctx['title'] ?? 'The Standing Register';
		$desc  = $ctx['desc'] ?? '';
		$index = (bool) ( $ctx['index'] ?? true );

		echo "<!doctype html>\n<html " . get_language_attributes() . ">\n<head>\n";
		echo '<meta charset="utf-8">' . "\n";
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		printf( "<title>%s | Studies Multiverse</title>\n", esc_html( $title ) );
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $this->meta_description( $desc ) ) );
		// A country page answers to two addresses: the country name the sitemap
		// uses, and the source id the API uses. Both resolved, both returned 200
		// and both self-canonicalised, so every country was two indexable copies
		// of one page competing with itself. The renderer may be told which
		// address is the real one; when it is not, self-reference is correct.
		printf(
			'<link rel="canonical" href="%s">' . "\n",
			esc_url( ! empty( $ctx['canonical'] ) ? (string) $ctx['canonical'] : $this->current_url() )
		);
		if ( ! $index ) {
			echo '<meta name="robots" content="noindex,follow">' . "\n";
		}
		printf(
			'<link rel="alternate" type="application/rss+xml" title="Recorded changes" href="%s">' . "\n",
			esc_url( home_url( '/standing/changes/feed/' ) )
		);
		$this->styles();
		do_action( 'wp_head' );
		echo "</head>\n<body class=\"sm-standing\">\n";

		echo '<a class="skip" href="#main">Skip to content</a>';
		$this->header( $ctx );

		echo '<main id="main">';
		switch ( $ctx['view'] ?? 'home' ) {
			case 'home':
				$this->view_home();
				break;
			case 'country':
				$this->view_country( $ctx['country'] );
				break;
			case 'entity':
				$this->view_entity( $ctx['country'], $ctx['record'] );
				break;
			case 'changes':
				$this->view_changes();
				break;
			case 'no-longer-listed':
				$this->view_changes( 'removed' );
				break;
			case 'watchlist':
				$this->view_watchlist();
				break;
			case 'countries':
				$this->view_countries();
				break;
			case 'archive':
				$this->view_archive();
				break;
			case 'methodology':
				$this->view_methodology();
				break;
			case 'corrections':
				$this->view_corrections();
				break;
			case 'data':
				$this->view_data();
				break;
		}
		echo '</main>';

		$this->footer();
		do_action( 'wp_footer' );
		echo "\n</body>\n</html>";
	}

	// -----------------------------------------------------------------
	// Views
	// -----------------------------------------------------------------

	private function view_home(): void {
		$data      = Data::instance();
		$countries = $data->countries();
		$changes   = $data->recent_changes( 12 );

		$editions = array_sum( array_column( $countries, 'editions_held' ) );
		$recorded = array_sum( array_column( $countries, 'changes_recorded' ) );

		echo '<section class="hero">';
		echo '<h1>Is your school still approved to take international students?</h1>';
		echo '<p class="lede">Every destination country publishes a list of which institutions may legally '
			. 'enrol international students. Every one of them quietly deletes rows from that list. '
			. 'We keep the deleted rows.</p>';
		$this->search_box();
		echo '</section>';

		printf(
			'<section class="stats"><div><b>%s</b><small>editions archived</small></div>'
			. '<div><b>%s</b><small>changes recorded</small></div>'
			. '<div><b>%s</b><small>countries covered</small></div>'
			. '<div><b>%s</b><small>earned from where you apply</small></div></section>',
			esc_html( number_format_i18n( $editions ) ),
			esc_html( number_format_i18n( $recorded ) ),
			esc_html( number_format_i18n( count( $countries ) ) ),
			'£0'
		);

		echo '<section><h2>What changed recently</h2>';
		$this->changes_list( $changes );
		printf( '<p class="more"><a href="%s">Every recorded change →</a></p>', esc_url( home_url( '/standing/changes/' ) ) );
		echo '</section>';

		echo '<section><h2>Where we hold a record</h2><div class="cards">';
		foreach ( $countries as $c ) {
			$slug = $data->slug( $c['country'] );
			$held    = (int) $c['editions_held'];
			$changed = (int) $c['changes_recorded'];
			$meta    = $held < 2
				? sprintf( 'first edition recorded %s · record starts here', $c['recording_since'] )
				: sprintf(
					'%s editions since %s · %s recorded',
					number_format_i18n( $held ),
					$c['recording_since'],
					1 === $changed ? 'one change' : number_format_i18n( $changed ) . ' changes'
				);
			printf(
				'<a class="card" href="%s"><h3>%s</h3><p>%s</p><p class="meta">%s</p></a>',
				esc_url( home_url( "/standing/{$slug}/" ) ),
				esc_html( $c['country'] ),
				esc_html( $c['register'] ),
				esc_html( $meta )
			);
		}
		echo '</div>';
		printf(
			'<p class="more"><a href="%s">Which countries publish a list at all, and which do not →</a></p>',
			esc_url( home_url( '/standing/countries/' ) )
		);
		echo '</section>';

		$this->charter();
	}

	private function view_country( array $c ): void {
		$data = Data::instance();
		$slug = $data->slug( $c['country'] );
		$changes = array_slice( $data->changes( $c['source_id'] ), 0, 40 );

		printf( '<h1>Is your school approved to take international students in %s?</h1>', esc_html( $c['country'] ) );

		// A register we have just started following has no history to claim, and
		// saying "1 editions, going back to" today reads as a bug and invites the
		// reader to distrust everything else on the page. Say plainly that the
		// record starts here: a comparison needs two editions, and we have one.
		$held    = (int) $c['editions_held'];
		$changes_n = (int) $c['changes_recorded'];

		if ( $held < 2 ) {
			printf(
				'<p class="lede">The official list is the <strong>%s</strong>, published by %s. '
				. 'We hold a single edition of it, recorded on %s. Our record of this register starts '
				. 'here: the next time the list is published we will have something to compare it against.</p>',
				esc_html( $c['register'] ),
				esc_html( $c['publisher'] ),
				esc_html( $c['recording_since'] )
			);
		} else {
			printf(
				'<p class="lede">The official list is the <strong>%s</strong>, published by %s. '
				. 'We hold %s editions of it, going back to %s, and have recorded %s.</p>',
				esc_html( $c['register'] ),
				esc_html( $c['publisher'] ),
				esc_html( number_format_i18n( $held ) ),
				esc_html( $c['recording_since'] ),
				esc_html( 1 === $changes_n ? 'one change' : number_format_i18n( $changes_n ) . ' changes' )
			);
		}

		$this->search_box( $slug );

		// The offer-letter check, where the register publishes codes to check
		// against. It shipped in 1.27.0 as a shortcode and was placed on no
		// page at all, so nobody could reach it. A name can be typed a dozen
		// ways and an anxious reader often has only the codes off the letter
		// in front of them, which is the case this answers. The shortcode
		// returns nothing for a register that publishes no codes, so calling
		// it for every country is safe rather than conditional here.
		echo do_shortcode( '[sm_standing_verify country="' . esc_attr( $slug ) . '"]' );

		if ( 'change-record' === ( $c['publication_layer'] ?? '' ) ) {
			// "published by them" must point at them. It pointed at our own
			// register.json, which for a change-record source is deliberately
			// empty, so the one link offering a reader the real register sent
			// them to an empty file of ours instead.
			$official = $c['source_url'] ?? ( $c['endpoints']['register'] ?? '' );
			echo '<aside class="note"><strong>We do not republish this register.</strong> '
				. esc_html( $c['publisher'] ) . ' reserves republication rights, so this page publishes only '
				. 'dated change events with citations back to the official source.'
				. ( $official
					? ' The register itself is <a href="' . esc_url( $official ) . '" rel="noopener">published by them</a>.'
					: '' )
				. '</aside>';
		}

		echo '<h2>What changed</h2>';
		$this->changes_list( $changes );

		echo '<h2>What happens if your institution\'s standing changes</h2>';
		echo '<div class="consequence">' . $this->consequence_html( $c['country'] ) . '</div>';

		printf(
			'<h2>Data and provenance</h2><ul class="links">'
			. '<li><a href="%s">Recorded changes (JSON)</a></li>'
			. '<li><a href="%s">Every edition we hold, dated and hashed</a></li>'
			. '<li><a href="%s">Subscribe to changes (RSS)</a></li>'
			. '<li>Source: <a href="%s" rel="nofollow">%s</a></li>'
			. '<li>Licence: %s</li></ul>',
			esc_url( $c['endpoints']['changes'] ?? '#' ),
			esc_url( home_url( '/standing/archive/' ) ),
			esc_url( $c['endpoints']['feed'] ?? '#' ),
			esc_url( $c['endpoints']['register'] ?? '#' ),
			esc_html( $c['publisher'] ),
			esc_html( $c['licence'] ?? 'see methodology' )
		);
	}

	private function view_entity( array $c, array $rec ): void {
		$slug = Data::instance()->slug( $c['country'] );

		printf( '<h1>%s</h1>', esc_html( $rec['name'] ) );
		printf(
			'<p class="lede">Standing on the %s, published by %s.</p>',
			esc_html( $c['register'] ),
			esc_html( $c['publisher'] )
		);

		printf(
			'<p class="status %s">%s</p>',
			$rec['listed'] ? 'is-listed' : 'not-listed',
			$rec['listed']
				? esc_html( sprintf( 'Listed on the edition published %s.', $c['latest_edition'] ) )
				: esc_html( sprintf( 'Not listed on the edition published %s.', $c['latest_edition'] ) )
		);

		if ( $rec['flags'] ) {
			echo '<h2>What the register records</h2><dl class="flags">';
			foreach ( $rec['flags'] as $k => $v ) {
				printf( '<dt>%s</dt><dd>“%s”</dd>', esc_html( $k ), esc_html( $v ) );
			}
			echo '</dl><p class="small">Quoted exactly as the register publishes it. We do not paraphrase a '
				. 'compliance flag into a verdict.</p>';
		}

		if ( $rec['changes'] ) {
			echo '<h2>Recorded history</h2>';
			$this->changes_list( $rec['changes'], false );
		} else {
			echo '<p>We have recorded no changes to this entry since our record of this register began on '
				. esc_html( $c['recording_since'] ) . '.</p>';
		}

		printf(
			'<aside class="correction"><h2>Is this record wrong?</h2>'
			. '<p>If you represent this institution and something here is inaccurate, tell us and we will '
			. 'correct it. We publish what the official register said on a given date, so a correction '
			. 'usually means either we misread the source or the source itself has changed. Either way we '
			. 'want to know.</p><p><a class="btn" href="%s">Request a correction</a></p></aside>',
			esc_url( home_url( '/standing/corrections/?ref=' . rawurlencode( $slug . '/' . $rec['key'] ) ) )
		);
	}

	/**
	 * How many left recently, and when.
	 *
	 * The list underneath is correct and unreadable as evidence: three hundred
	 * entries, newest first, with no sense of scale. Anyone who wants to cite
	 * this - a journalist, a forum, a student comparing two offers - needs a
	 * number attached to a window, and the page had none. Counting what the
	 * page is already showing costs nothing and makes it quotable.
	 *
	 * Counted on the edition an entry stopped appearing on, which is the only
	 * date we can stand behind: it is when we OBSERVED the absence, not when
	 * the publisher decided anything. The wording says so, because the gap
	 * between those two things is exactly what a careless reader would get
	 * wrong.
	 */
	private function recent_departures( array $changes ): void {
		$today   = new \DateTimeImmutable( 'today' );
		$windows = [ 30 => 0, 90 => 0, 365 => 0 ];

		foreach ( $changes as $ch ) {
			$on = (string) ( $ch['new_edition'] ?? '' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $on ) ) {
				continue;
			}
			$days = (int) $today->diff( new \DateTimeImmutable( $on ) )->days;
			foreach ( array_keys( $windows ) as $w ) {
				if ( $days <= $w ) {
					++$windows[ $w ];
				}
			}
		}

		if ( ! $windows[365] ) {
			return;
		}

		echo '<div class="sm-embed sm-embed-stats"><div class="stats">';
		foreach ( [ 30 => 'in the last 30 days', 90 => 'in the last 90 days', 365 => 'in the last year' ] as $w => $label ) {
			printf(
				'<div><b>%s</b><small>%s</small></div>',
				esc_html( number_format_i18n( $windows[ $w ] ) ),
				esc_html( $label )
			);
		}
		echo '</div></div>';
		printf(
			'<p class="sm-caveat">%s</p>',
			esc_html(
				'Counted on the edition each entry stopped appearing on, which is the date we observed '
				. 'the absence and not a date any publisher announced. Counts cover the registers we hold '
				. 'and the period we have been recording each one, so they are a floor rather than a total.'
			)
		);
	}

	private function view_changes( ?string $kind = null ): void {
		$changes = Data::instance()->recent_changes( 300, $kind );

		if ( 'removed' === $kind ) {
			echo '<h1>Institutions no longer listed</h1>';
			echo '<p class="lede">Entries that appeared on one edition of an official register and did not '
				. 'appear on the next. This is the artefact nobody else keeps. The sources overwrite, so '
				. 'once a row is gone there is normally no public trace that it was ever there.</p>';
			$this->recent_departures( $changes );
			$this->disappearance_warning();
		} else {
			echo '<h1>What changed on the official registers</h1>';
			echo '<p class="lede">Every recorded change across every register we hold, newest first.</p>';
		}

		$this->changes_list( $changes );
	}

	private function view_watchlist(): void {
		echo '<h1>Institutions carrying a published condition</h1>';
		echo '<p class="lede">Most registers publish a binary: listed, or not listed. A few publish more: '
			. 'probation, conditions, an action plan, a graded rating. Where they do, we quote it exactly '
			. 'as it appears, because that wording is the earliest warning a student can get.</p>';

		$data  = Data::instance();
		$found = false;

		foreach ( $data->countries() as $c ) {
			$register = $data->register( $c['source_id'] );
			$rows     = [];
			foreach ( ( $register['rows'] ?? [] ) as $r ) {
				foreach ( [ 'compliance', 'Immigration Compliance', 'status', 'Status' ] as $k ) {
					$v = trim( (string) ( $r[ $k ] ?? '' ) );
					if ( $v && ! in_array( strtolower( $v ), [ 'active', 'listed', '' ], true ) ) {
						$rows[] = [ $r, $k, $v ];
						break;
					}
				}
			}
			if ( ! $rows ) {
				continue;
			}
			$found = true;
			printf( '<h2>%s</h2>', esc_html( $c['country'] ) );
			echo '<div class="tablewrap"><table><thead><tr><th>Institution</th><th>Field</th><th>What the register says</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 200 ) as [ $r, $k, $v ] ) {
				$name = $r['Institution Name'] ?? $r['Organisation Name'] ?? $r['sponsor'] ?? $r['name'] ?? 'not stated';
				printf( '<tr><td>%s</td><td>%s</td><td>“%s”</td></tr>', esc_html( $name ), esc_html( $k ), esc_html( $v ) );
			}
			echo '</tbody></table></div>';
		}

		if ( ! $found ) {
			echo '<p>No register we currently hold is publishing a condition flag on any entry.</p>';
		}
	}

	private function view_countries(): void {
		echo '<h1>Which countries publish a list of approved institutions, and which do not</h1>';
		echo '<p class="lede">Not every destination maintains a public register. Where one does not exist, '
			. 'no monitor anywhere can tell you whether an institution is in good standing, and you deserve '
			. 'to know that before you choose, rather than after.</p>';
		echo '<p>This page is generated from our own source survey. Where we say a country publishes nothing '
			. 'usable, we mean we checked the official source and recorded why it failed, not that we could '
			. 'not find it.</p>';

		$data = Data::instance();
		echo '<h2>Countries we hold a record for</h2><div class="tablewrap"><table>'
			. '<thead><tr><th>Country</th><th>Register</th><th>Editions held</th><th>Since</th></tr></thead><tbody>';
		foreach ( $data->countries() as $c ) {
			$slug = $data->slug( $c['country'] );
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td class="num">%d</td><td>%s</td></tr>',
				esc_url( home_url( "/standing/{$slug}/" ) ),
				esc_html( $c['country'] ),
				esc_html( $c['register'] ),
				(int) $c['editions_held'],
				esc_html( $c['recording_since'] )
			);
		}
		echo '</tbody></table></div>';
	}

	private function view_archive(): void {
		echo '<h1>Every edition we hold</h1>';
		echo '<p class="lede">Each retrieved edition, dated and hashed. Any claim on this site can be checked '
			. 'against the edition it came from.</p>';
		echo '<p>The archive lives in a public git repository, so each snapshot is a commit with a timestamp '
			. 'and a content hash that we cannot quietly rewrite. That is deliberate: when an institution asks '
			. 'on what date our site said it was not listed, the answer should be a citable record, not our word.</p>';

		$data = Data::instance();
		foreach ( $data->countries() as $c ) {
			$editions = $data->archive( $c['source_id'] );
			if ( ! $editions ) {
				continue;
			}
			$n = count( $editions );
			printf(
				'<h2>%s <span class="small">(%s)</span></h2>',
				esc_html( $c['country'] ),
				esc_html( 1 === $n ? '1 edition' : number_format_i18n( $n ) . ' editions' )
			);
			echo '<div class="tablewrap"><table><thead><tr><th>Edition</th><th>Source date</th><th>Rows</th><th>SHA-256</th></tr></thead><tbody>';
			foreach ( array_reverse( $editions ) as $e ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td class="num">%s</td><td class="hash">%s</td></tr>',
					esc_html( $e['edition_date'] ),
					esc_html( (string) ( $e['source_date'] ?? 'not stated' ) ),
					esc_html( number_format_i18n( (int) $e['row_count'] ) ),
					esc_html( substr( (string) $e['content_sha256'], 0, 16 ) )
				);
			}
			echo '</tbody></table></div>';
		}
	}

	private function view_methodology(): void {
		echo '<h1>How we read each register, and what we refuse to say</h1>';
		echo '<p class="lede">This page exists because the difference between a useful record and a defamatory '
			. 'one is entirely in the method.</p>';

		echo '<h2>What we do</h2><ol class="steps">'
			. '<li><strong>Retrieve on a schedule.</strong> Each register is fetched by an automated job that '
			. 'runs whether or not anyone visits this site.</li>'
			. '<li><strong>Hash and archive every edition.</strong> The retrieved file is stored with a '
			. 'SHA-256 and committed to a public repository before anything is interpreted.</li>'
			. '<li><strong>Check before publishing.</strong> If a fetch returns far fewer rows than expected, '
			. 'or an implausible number of entries disappear at once, or the publisher\'s own edition date has '
			. 'not moved, we refuse to publish and raise an alert instead. A timeout looks exactly like a mass '
			. 'removal, and we would rather be a day late than wrong.</li>'
			. '<li><strong>Compare on the register\'s own identifier.</strong> Where a register publishes a '
			. 'persistent code, a name change with an unchanged code is a rename, provably rather than by guesswork. '
			. 'Where a register has no identifier, we say the ambiguity out loud rather than resolving it.</li>'
			. '<li><strong>Quote, never paraphrase.</strong> Compliance fields are reproduced in the '
			. 'register\'s own words.</li></ol>';

		echo '<h2>What we will not say</h2>';
		$this->disappearance_warning();
		echo '<p>We do not write that an institution was <em>revoked</em>, <em>banned</em>, or <em>shut down</em>. '
			. 'Our publishing pipeline fails its own build if those words ever reach a generated sentence. '
			. 'We write what the register said, on what date, and we name the alternatives.</p>';

		echo '<h2>Where our record begins</h2>'
			. '<p>Each country\'s record begins on the date of the earliest edition we hold, and we claim '
			. 'nothing before it. Where a publisher keeps dated archives we have reconstructed backwards; '
			. 'where it overwrites, our record starts when we started.</p>';

		echo '<h2>Independence</h2>'
			. '<p>We earn nothing from where you apply. No institution referral fees, no agent commissions, '
			. 'no paid inclusion, no paid removal, no paid "verified" badge. There is no arrangement under '
			. 'which an institution can influence what its record says. If there ever were, this register '
			. 'would be worthless, and so would the business built on it.</p>';
	}

	private function view_corrections(): void {
		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ref'] ) ) : '';

		echo '<h1>Corrections and right of reply</h1>';
		echo '<p class="lede">If your institution appears on this site and the record is wrong, tell us. '
			. 'We will check it against the archived source edition and correct it if we got it wrong.</p>';

		if ( $ref ) {
			printf( '<p class="note">Correction requested for: <code>%s</code></p>', esc_html( $ref ) );
		}

		echo '<h2>What we can and cannot change</h2>'
			. '<ul><li><strong>We can correct our reading of a source.</strong> If we misparsed a row, '
			. 'mismatched an identifier, or attached a change to the wrong institution, that is our error '
			. 'and we will fix it and say so.</li>'
			. '<li><strong>We can add context you provide.</strong> If an entry left a register because of a '
			. 'merger, a rename or a voluntary withdrawal, tell us and we will record that alongside the '
			. 'entry, attributed to you.</li>'
			. '<li><strong>We cannot change what the official register said.</strong> If the register itself '
			. 'is wrong, the correction has to be made there. We will record your statement that you dispute '
			. 'it, and we will link to any correction the publisher issues.</li>'
			. '<li><strong>We do not remove accurate records for payment or on request.</strong> Paid removal '
			. 'is the thing that would make this register worthless.</li></ul>';

		echo '<h2>How to reach us</h2>'
			. '<p>Write to <a href="mailto:corrections@studiesmultiverse.com">corrections@studiesmultiverse.com</a> '
			. 'with the institution name, the page in question, and what you believe is wrong. Include a '
			. 'contact we can verify you by. We aim to respond within five working days, and we act on '
			. 'clear factual errors immediately.</p>'
			. '<p>Published by A.I.T. Multiverse Consulting Ltd, Nicosia, Cyprus.</p>';
	}

	private function view_data(): void {
		$data  = Data::instance();
		$index = $data->index();

		echo '<h1>The open data behind this site</h1>';
		echo '<p class="lede">The change record, the archive index and the licences. Free to use, with '
			. 'attribution. This layer stays free permanently. It is what earns the citations.</p>';

		foreach ( $data->countries() as $c ) {
			printf( '<h2>%s</h2><ul class="links">', esc_html( $c['country'] ) );
			foreach ( [ 'changes' => 'Recorded changes (JSON)', 'archive' => 'Archive index (JSON)', 'feed' => 'Changes (RSS)' ] as $k => $label ) {
				if ( ! empty( $c['endpoints'][ $k ] ) ) {
					printf( '<li><a href="%s">%s</a></li>', esc_url( $c['endpoints'][ $k ] ), esc_html( $label ) );
				}
			}
			printf(
				'<li>Source: %s, %s</li><li>Source licence: %s</li></ul>',
				esc_html( $c['publisher'] ),
				esc_html( $c['register'] ),
				esc_html( $c['licence'] ?? 'not stated' )
			);
		}

		printf(
			'<h2>Citing this record</h2><p>Our own outputs are published under <a href="%s">CC BY 4.0</a>. '
			. 'Each change entry carries the edition dates it was derived from and the SHA-256 of the archived '
			. 'source edition. Cite the edition date, not the date you read the page.</p>',
			esc_url( 'https://creativecommons.org/licenses/by/4.0/' )
		);

		$doi = Identity::instance()->doi();
		if ( $doi ) {
			printf(
				'<p>Archived releases carry a permanent DOI: <code>%s</code>. That resolves to the newest '
				. 'archived version, and each individual release has its own version DOI.</p>',
				esc_html( $doi )
			);
		}
	}

	// -----------------------------------------------------------------
	// Components
	// -----------------------------------------------------------------

	private function search_box( string $country_slug = '' ): void {
		printf(
			'<form class="searchbox" role="search" action="%s" method="get" data-country="%s">'
			. '<label for="sm-q">Type your school</label>'
			. '<input id="sm-q" name="q" type="search" autocomplete="off" spellcheck="false" '
			. 'placeholder="e.g. a university, college or language school" '
			. 'aria-describedby="sm-q-help" value="%s">'
			. '<button type="submit">Check</button>'
			. '<p id="sm-q-help" class="small">We will tell you where it stands on the official register of '
			. 'its country, and what that register said before.</p>'
			. '<div id="sm-results" role="status" aria-live="polite"></div></form>',
			esc_url( home_url( '/standing/' ) ),
			esc_attr( $country_slug ),
			esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '' )
		);
	}

	private function changes_list( array $changes, bool $show_country = true ): void {
		if ( ! $changes ) {
			echo '<p>Nothing recorded yet.</p>';
			return;
		}

		$labels = [
			'removed'  => 'No longer listed',
			'added'    => 'Newly listed',
			'renamed'  => 'Name changed',
			'modified' => 'Record changed',
			'course_withdrawn_provider_still_listed' => 'Course withdrawn, provider still listed',
		];

		echo '<ul class="changes">';
		foreach ( $changes as $ch ) {
			$kind  = (string) ( $ch['kind'] ?? '' );
			$label = $labels[ $kind ] ?? 'Change';
			printf(
				'<li class="ch ch-%s"><div class="ch-head"><span class="tag">%s</span>'
				. '<strong>%s</strong>%s<time>%s</time></div>'
				. '<p class="ch-statement">%s</p><p class="ch-caveat">%s</p></li>',
				esc_attr( $kind ),
				esc_html( $label ),
				esc_html( (string) ( $ch['name'] ?? '' ) ),
				$show_country && ! empty( $ch['country'] ) ? ' <span class="country">' . esc_html( $ch['country'] ) . '</span>' : '',
				esc_html( (string) ( $ch['new_edition'] ?? '' ) ),
				esc_html( (string) ( $ch['statement'] ?? '' ) ),
				esc_html( (string) ( $ch['caveat'] ?? '' ) )
			);
		}
		echo '</ul>';
	}

	private function disappearance_warning(): void {
		echo '<aside class="warning"><p><strong>A row disappearing from a register is not evidence of '
			. 'wrongdoing.</strong> A withdrawn approval, a merger, a rename, a voluntary surrender, a '
			. 'corporate restructure, a lapse at renewal and a publisher\'s own correction all look identical: '
			. 'the row simply stops being there. Registers publish a status, not a cause, and neither we nor '
			. 'anyone else can tell from the register alone which applies.</p></aside>';
	}

	private function consequence_html( string $country ): string {
		$map = [
			'United Kingdom' => 'If a sponsor loses its licence while you are here, UKVI normally curtails your '
				. 'permission to stay to 60 days, and you would need a new sponsor and a new application within '
				. 'that window. If you have not yet travelled, the visa can be cancelled outright.',
			'Australia'      => 'CRICOS registration is what allows a provider to enrol you. If it is suspended '
				. 'or cancelled, provider-default obligations under the ESOS Act are triggered, and you are '
				. 'normally entitled to a placement in a comparable course or a refund.',
			'Canada'         => 'A study permit is tied to a designated learning institution. If yours loses '
				. 'designation, the permit becomes invalid. The part people miss is that eligibility for a '
				. 'post-graduation work permit can be lost entirely too.',
			'Netherlands'    => 'Only a recognised sponsor can bring in a non-EU student. If your institution '
				. 'stops being recognised, its ability to sponsor new residence permits ends.',
			'Poland'         => 'A negative assessment from the accreditation committee can halt admissions to '
				. 'a programme, and an institution in liquidation stops enrolling entirely.',
		];
		$text = $map[ $country ] ?? 'If an institution loses its standing, the immigration consequences fall on '
			. 'the student, not the institution. Check the current position with the national authority.';

		return '<p>' . esc_html( $text ) . '</p><p class="small">This is general information, not immigration '
			. 'advice, and rules change. Always confirm your own position with the relevant authority or a '
			. 'qualified adviser.</p>';
	}

	private function charter(): void {
		echo '<section class="charter"><h2>Why you can trust this</h2>'
			. '<p><strong>This site earns nothing from where you apply.</strong> No institution referral fees, '
			. 'no agent commissions, no paid inclusion, no paid removal, no paid badges. There is no '
			. 'arrangement under which an institution can influence what its record says.</p>'
			. '<p>Everything here is derived from official government registers, archived with a timestamp and '
			. 'a hash so you can check any claim against the edition it came from.</p></section>';
	}

	private function header( array $ctx ): void {
		echo '<header class="site"><a class="brand" href="' . esc_url( home_url( '/' ) ) . '">Studies Multiverse</a>';
		echo '<nav aria-label="Standing Register"><a href="' . esc_url( home_url( '/standing/' ) ) . '">Check a school</a>'
			. '<a href="' . esc_url( home_url( '/standing/changes/' ) ) . '">Changes</a>'
			. '<a href="' . esc_url( home_url( '/standing/no-longer-listed/' ) ) . '">No longer listed</a>'
			. '<a href="' . esc_url( home_url( '/standing/methodology/' ) ) . '">Method</a></nav></header>';

		if ( ! empty( $ctx['crumbs'] ) && count( $ctx['crumbs'] ) > 2 ) {
			echo '<nav class="crumbs" aria-label="Breadcrumb"><ol>';
			foreach ( $ctx['crumbs'] as $crumb ) {
				printf( '<li><a href="%s">%s</a></li>', esc_url( $crumb['url'] ), esc_html( $crumb['label'] ) );
			}
			echo '</ol></nav>';
		}
	}

	private function footer(): void {
		$age = Data::instance()->age_in_hours();
		echo '<footer class="site"><p>';
		if ( null !== $age ) {
			printf( 'Register data last refreshed %s hours ago. ', esc_html( (string) round( $age, 1 ) ) );
		}
		printf(
			'Published by A.I.T. Multiverse Consulting Ltd, Nicosia, Cyprus. '
			. '<a href="%s">Method</a> · <a href="%s">Corrections</a> · <a href="%s">Open data</a> · '
			. '<a href="%s">Privacy</a> · <a href="%s">Terms</a>',
			esc_url( home_url( '/standing/methodology/' ) ),
			esc_url( home_url( '/standing/corrections/' ) ),
			esc_url( home_url( '/standing/data/' ) ),
			esc_url( home_url( '/privacy-policy/' ) ),
			esc_url( home_url( '/terms/' ) )
		);
		echo '</p></footer>';
		$this->script();
	}

	/**
	 * A description that ends where a sentence ends.
	 *
	 * This trimmed to thirty words and appended an ellipsis, which cut the
	 * register's own description mid-phrase: "what it means if you hold an..."
	 * A search result showing a sentence that stops mid-word reads as a page
	 * that was not finished, and it is the first thing most people ever see of
	 * this site.
	 *
	 * Prefer the whole thing when it fits. When it does not, fall back to the
	 * last complete sentence, and only cut at a word boundary if even the first
	 * sentence is too long.
	 */
	private function meta_description( string $desc, int $limit = 155 ): string {
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) ?? $desc );
		if ( '' === $desc || mb_strlen( $desc ) <= $limit ) {
			return $desc;
		}

		$cut = mb_substr( $desc, 0, $limit );
		foreach ( [ '. ', '? ', '! ' ] as $stop ) {
			$at = mb_strrpos( $cut, $stop );
			if ( false !== $at && $at > $limit / 2 ) {
				return mb_substr( $cut, 0, $at + 1 );
			}
		}

		$space = mb_strrpos( $cut, ' ' );
		return ( false === $space ? $cut : mb_substr( $cut, 0, $space ) ) . '.';
	}

	private function current_url(): string {
		return home_url( add_query_arg( [] , $GLOBALS['wp']->request ? '/' . $GLOBALS['wp']->request . '/' : '/' ) );
	}

	// -----------------------------------------------------------------
	// Assets — inlined, because a render-blocking request is a render-blocking
	// request even when it is only 4 KB.
	// -----------------------------------------------------------------

	private function styles(): void {
		echo "<style>" . file_get_contents( SM_STANDING_DIR . 'assets/standing.css' ) . "</style>\n";
	}

	private function script(): void {
		printf(
			'<script>window.SM_INDEX=%s;</script>',
			wp_json_encode( Data::instance()->search_index_url() )
		);
		echo '<script>' . file_get_contents( SM_STANDING_DIR . 'assets/standing.js' ) . '</script>';
	}
}
