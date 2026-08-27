<?php
/**
 * Elementor widgets.
 *
 * The register section renders itself, deliberately, because it has to be fast.
 * But the rest of the site is built in Elementor and should stay that way — so
 * these widgets let the register be dropped into any Elementor page: put the
 * search box on the home page, the recent-changes feed in a sidebar, the
 * coverage stats on the About page.
 *
 * They are also the honest bridge between the two halves of the site. A guide
 * about applying to Australia should be able to show, inline, that eighty-six
 * providers left CRICOS last year — without the author having to remember to
 * update a number.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Elementor {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'elementor/widgets/register', [ $this, 'register' ] );
		add_action( 'elementor/elements/categories_registered', [ $this, 'category' ] );

		// Shortcodes too, so the widgets work outside Elementor as well.
		add_shortcode( 'sm_standing_search', [ $this, 'sc_search' ] );
		add_shortcode( 'sm_standing_changes', [ $this, 'sc_changes' ] );
		add_shortcode( 'sm_standing_stats', [ $this, 'sc_stats' ] );
		add_shortcode( 'sm_standing_watchlist', [ $this, 'sc_watchlist' ] );
		add_shortcode( 'sm_standing_verify', [ $this, 'sc_verify' ] );
	}

	public function category( $manager ): void {
		$manager->add_category(
			'sm-standing',
			[ 'title' => 'Standing Register', 'icon' => 'eicon-database' ]
		);
	}

	public function register( $widgets_manager ): void {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}
		require_once SM_STANDING_DIR . 'includes/elementor-widgets.php';
		$widgets_manager->register( new Widget_Search() );
		$widgets_manager->register( new Widget_Changes() );
		$widgets_manager->register( new Widget_Stats() );
	}

	// -----------------------------------------------------------------
	// Shared markup. Kept here so the widget and the shortcode can never
	// drift apart.
	// -----------------------------------------------------------------

	public function sc_search( $atts = [] ): string {
		$a = shortcode_atts( [ 'country' => '', 'heading' => 'Check where your school stands' ], (array) $atts );
		$this->enqueue();

		// Resolve whatever name the author used to the one the index is keyed on.
		//
		// A country answers to two names here: the source id the API and the
		// data files use (ca-dli), and the slug of the country name that the
		// sitemap, the page URLs and the search index use (canada). The search
		// box filters the index client-side on this attribute, so a shortcode
		// written as country="ca-dli" produced a box that matched nothing and
		// said "Nothing on the registers we hold matches that" for a school
		// that was listed on the same page a few lines below.
		//
		// That is the worst failure this site can have. It is indistinguishable
		// from a real answer, and the person most likely to see it is the one
		// checking whether the school they have an offer from is real. Accept
		// either name rather than trusting whoever writes the shortcode to know
		// which of the two is wanted.
		$country_slug = '';
		if ( '' !== trim( (string) $a['country'] ) ) {
			$data    = Data::instance();
			$country = $data->country( sanitize_title( $a['country'] ) );
			$country_slug = $country
				? $data->slug( (string) $country['country'] )
				: sanitize_title( $a['country'] );
		}

		ob_start();
		?>
		<div class="sm-embed sm-embed-search">
			<?php if ( $a['heading'] ) : ?><h3><?php echo esc_html( $a['heading'] ); ?></h3><?php endif; ?>
			<form class="searchbox" role="search" action="<?php echo esc_url( home_url( '/standing/' ) ); ?>"
				method="get" data-country="<?php echo esc_attr( $country_slug ); ?>">
				<label for="sm-q">Type your school</label>
				<input id="sm-q" name="q" type="search" autocomplete="off" spellcheck="false"
					placeholder="e.g. a university, college or language school">
				<button type="submit">Check</button>
				<div id="sm-results" role="status" aria-live="polite"></div>
			</form>
			<p class="sm-embed-note">Checked against the official government register of each country.
				<a href="<?php echo esc_url( home_url( '/standing/methodology/' ) ); ?>">How this works</a>.</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function sc_changes( $atts = [] ): string {
		$a = shortcode_atts( [ 'country' => '', 'kind' => '', 'limit' => 5, 'heading' => '' ], (array) $atts );
		$this->enqueue();

		$data  = Data::instance();
		$limit = max( 1, min( 50, (int) $a['limit'] ) );

		if ( $a['country'] ) {
			$c = $data->country( sanitize_title( $a['country'] ) );
			$changes = $c ? array_slice( $data->changes( $c['source_id'] ), 0, $limit ) : [];
		} else {
			$changes = $data->recent_changes( $limit, $a['kind'] ?: null );
		}

		if ( ! $changes ) {
			return '';
		}

		$labels = [
			'removed'  => 'No longer listed',
			'added'    => 'Newly listed',
			'renamed'  => 'Name changed',
			'modified' => 'Record changed',
			'course_withdrawn_provider_still_listed' => 'Course withdrawn, provider still listed',
		];

		ob_start();
		echo '<div class="sm-embed sm-embed-changes">';
		if ( $a['heading'] ) {
			printf( '<h3>%s</h3>', esc_html( $a['heading'] ) );
		}
		echo '<ul class="changes">';
		foreach ( $changes as $ch ) {
			printf(
				'<li class="ch ch-%s"><div class="ch-head"><span class="tag">%s</span><strong>%s</strong>'
				. '<time>%s</time></div><p class="ch-statement">%s</p></li>',
				esc_attr( (string) ( $ch['kind'] ?? '' ) ),
				esc_html( $labels[ $ch['kind'] ?? '' ] ?? 'Change' ),
				esc_html( (string) ( $ch['name'] ?? '' ) ),
				esc_html( (string) ( $ch['new_edition'] ?? '' ) ),
				esc_html( (string) ( $ch['statement'] ?? '' ) )
			);
		}
		echo '</ul>';
		printf(
			'<p class="sm-embed-note"><a href="%s">Every recorded change →</a> '
			. 'A row disappearing from a register is not evidence of wrongdoing.</p>',
			esc_url( home_url( '/standing/changes/' ) )
		);
		echo '</div>';
		return (string) ob_get_clean();
	}

	public function sc_stats( $atts = [] ): string {
		$a = shortcode_atts( [ 'country' => '' ], (array) $atts );
		$this->enqueue();

		$data      = Data::instance();
		$countries = $data->countries();
		if ( ! $countries ) {
			return '';
		}

		if ( $a['country'] ) {
			$c = $data->country( sanitize_title( $a['country'] ) );
			if ( ! $c ) {
				return '';
			}
			$cells = [
				[ number_format_i18n( (int) $c['editions_held'] ), 1 === (int) $c['editions_held'] ? 'edition archived' : 'editions archived' ],
				[ number_format_i18n( (int) $c['changes_recorded'] ), 1 === (int) $c['changes_recorded'] ? 'change recorded' : 'changes recorded' ],
				[ esc_html( $c['recording_since'] ), 'record begins' ],
			];
		} else {
			$cells = [
				[ number_format_i18n( array_sum( array_column( $countries, 'editions_held' ) ) ), 'editions archived' ],
				[ number_format_i18n( array_sum( array_column( $countries, 'changes_recorded' ) ) ), 'changes recorded' ],
				[ number_format_i18n( count( $countries ) ), 'countries covered' ],
				[ '£0', 'earned from where you apply' ],
			];
		}

		ob_start();
		echo '<div class="sm-embed sm-embed-stats"><div class="stats">';
		foreach ( $cells as [ $big, $small ] ) {
			printf( '<div><b>%s</b><small>%s</small></div>', esc_html( $big ), esc_html( $small ) );
		}
		echo '</div></div>';
		return (string) ob_get_clean();
	}

	/**
	 * The sponsors a register itself flags, quoted verbatim.
	 *
	 * The UK register carries two columns almost nobody reads: the licence
	 * Status, which distinguishes a probationary sponsor from one with a track
	 * record, and Immigration Compliance, which names an action taken against
	 * the sponsor. Twelve of the 1,306 current UK entries carry a compliance
	 * entry and seventy-one are probationary. A student choosing between two
	 * offers has no way to see that, because the register publishes it as a
	 * spreadsheet column and nobody renders it.
	 *
	 * This renders it and nothing else. It quotes the publisher's own words
	 * under the publisher's own column name, dates them to the edition they
	 * came from, and stops. It does not rank sponsors, score them, infer what
	 * an entry means for an application, or imply wrongdoing: a probationary
	 * rating is a normal stage for a new sponsor, and a compliance action is
	 * the publisher's statement, not ours.
	 *
	 * Only a source published as a mirror carries rows to read. A
	 * change-record source is reduced before it reaches the public repository,
	 * so there is nothing here to list and the shortcode renders nothing
	 * rather than pretending the question does not apply.
	 */
	public function sc_watchlist( $atts = [] ): string {
		$a = shortcode_atts(
			[ 'country' => '', 'field' => '', 'status' => '', 'limit' => 100, 'heading' => '' ],
			(array) $atts
		);
		$this->enqueue();

		$data = Data::instance();
		$c    = $a['country'] ? $data->country( sanitize_title( $a['country'] ) ) : null;
		if ( ! $c ) {
			return '';
		}

		$register = $data->register( $c['source_id'] );
		$rows     = is_array( $register ) && ! empty( $register['rows'] ) ? $register['rows'] : [];
		if ( ! $rows ) {
			return '';
		}

		$field  = (string) $a['field'];
		$status = (string) $a['status'];
		$limit  = max( 1, min( 500, (int) $a['limit'] ) );
		$hits   = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( '' !== $field && '' === trim( (string) ( $row[ $field ] ?? '' ) ) ) {
				continue;
			}
			if ( '' !== $status && $status !== trim( (string) ( $row['Status'] ?? '' ) ) ) {
				continue;
			}
			$hits[] = $row;
			if ( count( $hits ) >= $limit ) {
				break;
			}
		}

		if ( ! $hits ) {
			return '';
		}

		$column = '' !== $field ? $field : 'Status';

		ob_start();
		echo '<div class="sm-embed sm-embed-watchlist">';
		if ( '' !== $a['heading'] ) {
			printf( '<h3>%s</h3>', esc_html( (string) $a['heading'] ) );
		}
		echo '<ul class="sm-watchlist">';
		foreach ( $hits as $row ) {
			$name  = (string) ( $data->row_name( $row ) ?? '' );
			$value = '' !== $field ? (string) ( $row[ $field ] ?? '' ) : (string) ( $row['Status'] ?? '' );

			// A sponsor licensed on more than one route has one row per route,
			// so the same name legitimately appears twice. Print what tells the
			// rows apart rather than collapsing them: silently deduplicating
			// would hide that the entry applies to one route and not the other.
			$where = array_filter( [
				trim( (string) ( $row['Town/City'] ?? '' ) ),
				trim( (string) ( $row['Route'] ?? '' ) ),
			] );

			printf(
				'<li><b>%s</b><small>%s: %s</small>%s</li>',
				esc_html( $name ),
				esc_html( $column ),
				esc_html( $value ),
				$where ? '<small>' . esc_html( implode( ' · ', $where ) ) . '</small>' : ''
			);
		}
		echo '</ul>';
		printf(
			'<p class="sm-caveat">%s</p>',
			esc_html( sprintf(
				'%d of %d entries, quoted verbatim from the %s under its own column name, as published on %s. We do not rank sponsors and do not infer what an entry means for an application.',
				count( $hits ),
				count( $rows ),
				(string) $c['register'],
				(string) $c['latest_edition']
			) )
		);
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Check the codes on an offer letter against the register they claim.
	 *
	 * The API has been able to do this since it was written and nothing on the
	 * site ever asked it to. Australia publishes 26,604 courses with the
	 * provider each one belongs to, so a CRICOS course code can be checked
	 * against the CRICOS provider code printed beside it, and a course
	 * registered to a different provider than the letter claims is the single
	 * most useful thing this register can tell anyone. The search box only ever
	 * matched names, which is the check a forged letter passes easily: the
	 * institution is real, the codes are the part that does not survive
	 * scrutiny.
	 *
	 * What it still refuses to say is that an offer is fake. A mismatch has
	 * innocent explanations - a teaching partnership, a transcription error, an
	 * out-of-date letter - and the answer names them every time, because the
	 * person reading this is frightened and we are not the ones who get to
	 * decide what their letter means.
	 */
	public function sc_verify( $atts = [] ): string {
		$a = shortcode_atts(
			[ 'country' => '', 'heading' => 'Check the codes on your offer letter' ],
			(array) $atts
		);
		$this->enqueue();

		$data    = Data::instance();
		$country = $a['country'] ? $data->country( sanitize_title( $a['country'] ) ) : null;
		if ( ! $country ) {
			return '';
		}

		// Only ask for codes a register actually publishes. Australia carries
		// provider and course codes; Canada carries the DLI number and, being a
		// change-record source, cannot validate it here at all.
		$sid    = (string) $country['source_id'];
		$fields = [];
		if ( 'au-cricos' === $sid ) {
			$fields['provider_code'] = 'CRICOS provider code';
			$fields['course_code']   = 'CRICOS course code';
		} elseif ( 'ca-dli' === $sid ) {
			$fields['dli'] = 'DLI number';
		}
		if ( ! $fields ) {
			return '';
		}

		ob_start();
		echo '<div class="sm-embed sm-embed-verify">';
		printf( '<h3>%s</h3>', esc_html( (string) $a['heading'] ) );
		printf(
			'<form class="sm-verify" data-country="%s">',
			esc_attr( $data->slug( (string) $country['country'] ) )
		);
		foreach ( $fields as $name => $label ) {
			printf(
				'<label for="sm-v-%1$s">%2$s</label>'
				. '<input id="sm-v-%1$s" name="%1$s" type="text" autocomplete="off" spellcheck="false">',
				esc_attr( $name ),
				esc_html( $label )
			);
		}
		echo '<button type="submit">Check the codes</button>';
		echo '<div class="sm-verify-out" role="status" aria-live="polite"></div>';
		echo '</form>';
		printf(
			'<p class="sm-caveat">%s</p>',
			esc_html(
				'We report what the ' . (string) $country['register'] . ' said on the edition published '
				. (string) $country['latest_edition'] . '. A code we cannot find, or a course registered to a '
				. 'different provider, has innocent explanations and is worth asking the institution about. '
				. 'This check never tells you an offer is fake.'
			)
		);
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Assets for embeds only. The register's own pages inline everything and
	 * never reach this.
	 */
	public function enqueue(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		wp_register_style( 'sm-standing-embed', SM_STANDING_URL . 'assets/standing.css', [], SM_STANDING_VERSION );
		wp_enqueue_style( 'sm-standing-embed' );

		wp_register_script( 'sm-standing-embed', SM_STANDING_URL . 'assets/standing.js', [], SM_STANDING_VERSION, true );
		wp_localize_script( 'sm-standing-embed', 'SM_INDEX_DATA', [ 'url' => Data::instance()->search_index_url() ] );
		wp_add_inline_script(
			'sm-standing-embed',
			'window.SM_INDEX = window.SM_INDEX || (window.SM_INDEX_DATA && window.SM_INDEX_DATA.url);',
			'before'
		);
		wp_enqueue_script( 'sm-standing-embed' );
	}
}
