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

		ob_start();
		?>
		<div class="sm-embed sm-embed-search">
			<?php if ( $a['heading'] ) : ?><h3><?php echo esc_html( $a['heading'] ); ?></h3><?php endif; ?>
			<form class="searchbox" role="search" action="<?php echo esc_url( home_url( '/standing/' ) ); ?>"
				method="get" data-country="<?php echo esc_attr( sanitize_title( $a['country'] ) ); ?>">
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
