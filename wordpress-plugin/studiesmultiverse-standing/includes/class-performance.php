<?php
/**
 * Performance — on Standing Register pages only.
 *
 * These pages are rendered as complete self-contained documents. They use no
 * Elementor widget, no theme stylesheet and no block-library CSS, but WordPress
 * enqueues all of it anyway because other plugins hook wp_head unconditionally.
 * Measured on the live site immediately after activation: 127 KB and sixteen
 * stylesheets on a page whose own markup is 25 KB and needs none of them.
 *
 * So we dequeue what this section does not use, and only in this section. Every
 * other page on the site is untouched — this class checks the route first and
 * returns immediately if it is not ours.
 *
 * WHAT IS DELIBERATELY KEPT
 *
 *   Complianz    cookie consent is a legal requirement, not an optimisation
 *                target, and it must load before anything that sets a cookie
 *   Site Kit     the owner's analytics, loaded subject to consent
 *   Rank Math    emits into wp_head directly rather than by enqueue, and owns
 *                the canonical, the meta description and the identity graph
 *
 * WHAT IS DROPPED
 *
 *   Elementor and Elementor Pro frontend CSS/JS, the per-post Elementor CSS
 *   files, the Hello Elementor theme stylesheets, the block library, the
 *   classic-theme inline styles, emoji, and Google Fonts — the register uses
 *   system fonts, which render instantly and cost nothing on a bad connection.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Performance {

	private static ?self $instance = null;

	/** Set true if the page already contains an Analytics tag. */
	private bool $analytics_present = false;

	/**
	 * Handle prefixes to drop on our routes.
	 *
	 * Kept deliberately narrow. An earlier revision included 'e-' and 'gd-',
	 * which are broad enough to catch handles that have nothing to do with
	 * Elementor — and did: Google Analytics stopped loading on register pages.
	 * A performance optimisation that silently breaks measurement is worse than
	 * no optimisation, because you cannot see that it happened.
	 */
	private const DROP_PREFIXES = [
		'elementor',
		'elementor-pro',
		'hello-elementor',
		'hello_elementor',
		'hello-theme',
		'wp-block-library',
		'wp-block-library-theme',
		'global-styles',
		'classic-theme-styles',
		'font-awesome',
		'swiper',
		'eicons',
		'smartmenus',
	];

	/**
	 * Never dropped, whatever the prefix rules say, and checked FIRST.
	 *
	 * Consent is a legal requirement. Analytics is how we find out whether
	 * anyone comes back, which is the only metric this project is actually
	 * trying to move.
	 */
	private const KEEP = [
		'cmplz',
		'complianz',
		'wp-consent-api',
		'google',
		'gtag',
		'googlesitekit',
		'google-site-kit',
		'jquery',
	];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		// Late, so everything has registered before we prune.
		add_action( 'wp_enqueue_scripts', [ $this, 'prune' ], 9999 );
		add_action( 'wp_print_styles', [ $this, 'prune' ], 9999 );

		add_action( 'init', [ $this, 'trim_head' ] );
		add_filter( 'style_loader_tag', [ $this, 'kill_remote_fonts' ], 10, 4 );
		add_filter( 'wp_resource_hints', [ $this, 'trim_hints' ], 10, 2 );

		// The footer carries as much dead weight as the head on this site.
		add_action( 'wp_footer', [ $this, 'open_footer_buffer' ], -PHP_INT_MAX );
		add_action( 'wp_footer', [ $this, 'close_footer_buffer' ], PHP_INT_MAX );
	}

	private function ours(): bool {
		return Routes::instance()->is_standing_request();
	}

	private function should_drop( string $handle ): bool {
		$h = strtolower( $handle );
		foreach ( self::KEEP as $keep ) {
			// str_contains, not str_starts_with: Site Kit and Complianz use
			// several handle shapes and a prefix test misses half of them.
			if ( str_contains( $h, $keep ) ) {
				return false;
			}
		}
		foreach ( self::DROP_PREFIXES as $prefix ) {
			if ( str_starts_with( $handle, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	public function prune(): void {
		if ( ! $this->ours() ) {
			return;
		}

		global $wp_styles, $wp_scripts;

		if ( $wp_styles instanceof \WP_Styles ) {
			foreach ( (array) $wp_styles->queue as $handle ) {
				if ( $this->should_drop( (string) $handle ) ) {
					wp_dequeue_style( $handle );
					wp_deregister_style( $handle );
				}
			}
		}

		if ( $wp_scripts instanceof \WP_Scripts ) {
			foreach ( (array) $wp_scripts->queue as $handle ) {
				// jQuery is a dependency of things we keep; leave it registered
				// but drop the Elementor layer that sits on top of it.
				if ( 'jquery' === $handle || str_starts_with( (string) $handle, 'jquery-core' ) ) {
					continue;
				}
				if ( $this->should_drop( (string) $handle ) ) {
					wp_dequeue_script( $handle );
					wp_deregister_script( $handle );
				}
			}
		}
	}

	/**
	 * Remove wp_head output this section has no use for.
	 */
	public function trim_head(): void {
		if ( ! $this->ours() ) {
			// This hook runs before the query is parsed on some requests, so
			// re-check later rather than assuming.
			add_action(
				'template_redirect',
				function (): void {
					if ( $this->ours() ) {
						$this->do_trim_head();
					}
				},
				1
			);
			return;
		}
		$this->do_trim_head();
	}

	private function do_trim_head(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		remove_action( 'wp_head', 'wp_resource_hints', 2 );
	}

	/**
	 * The register uses system fonts. A webfont on a register page is a
	 * render-blocking request in exchange for nothing a worried student cares
	 * about.
	 */
	public function kill_remote_fonts( string $tag, string $handle, string $href, string $media ): string {
		if ( ! $this->ours() ) {
			return $tag;
		}
		if ( str_contains( $href, 'fonts.googleapis.com' ) || str_contains( $href, 'fonts.gstatic.com' ) ) {
			return '';
		}
		return $tag;
	}

	public function open_footer_buffer(): void {
		if ( $this->ours() ) {
			ob_start();
		}
	}

	public function close_footer_buffer(): void {
		if ( ! $this->ours() ) {
			return;
		}
		echo $this->strip( (string) ob_get_clean() ) . $this->ensure_analytics();
	}

	/**
	 * Guarantee measurement on register pages.
	 *
	 * Site Kit does not place its Analytics tag on these routes. I could not
	 * determine why from the outside — it is not the dequeuing, not the output
	 * filtering, and not the 404 state, all of which were ruled out by testing.
	 * Rather than keep guessing at another plugin's internals, this reads the
	 * measurement ID Site Kit has already stored and emits the tag itself, but
	 * ONLY if nothing else on the page has already emitted it. If Site Kit
	 * starts placing the tag again, this quietly does nothing.
	 *
	 * Consent is respected: Site Kit's consent-mode script still loads on these
	 * pages and sets the defaults before this runs, so this tag inherits the
	 * same consent state as every other page on the site.
	 *
	 * This matters more than it looks. The brief's own conclusion is that the
	 * measure to watch is returning visitors, not sessions — and a register
	 * section with no analytics cannot tell you whether anyone came back.
	 */
	private function ensure_analytics(): string {
		if ( $this->analytics_present ) {
			return '';
		}

		$id = $this->measurement_id();
		if ( ! $id ) {
			return '';
		}

		return sprintf(
			"\n<!-- Standing Register: Analytics tag placed by the plugin because Site Kit did not place one here -->\n"
			. '<script async src="https://www.googletagmanager.com/gtag/js?id=%1$s"></script>' . "\n"
			. '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
			. "gtag('js',new Date());gtag('config','%1\$s');</script>\n",
			esc_attr( $id )
		);
	}

	/** Site Kit stores the GA4 measurement ID; reuse it rather than hardcoding. */
	private function measurement_id(): string {
		foreach ( [ 'googlesitekit_analytics-4_settings', 'googlesitekit_analytics_settings' ] as $option ) {
			$settings = get_option( $option );
			if ( ! is_array( $settings ) ) {
				continue;
			}
			foreach ( [ 'measurementID', 'measurementId', 'webDataStreamID' ] as $key ) {
				$value = (string) ( $settings[ $key ] ?? '' );
				if ( preg_match( '/^G-[A-Z0-9]+$/i', $value ) ) {
					return $value;
				}
			}
		}
		return (string) apply_filters( 'sm_standing_measurement_id', '' );
	}

	/**
	 * Strip assets from an assembled head or footer.
	 *
	 * Called by Identity, which already buffers wp_head. Dequeuing handles what
	 * WordPress knows about; this handles everything else — inline <style> and
	 * <script> blocks printed directly by snippets and plugins, which is where
	 * almost all the weight actually was.
	 *
	 * Written as an allow-what-matters filter rather than a block-list: anything
	 * matching KEEP_INLINE survives, whatever else it looks like. Consent and
	 * analytics are never touched.
	 */
	public function strip( string $html ): string {
		if ( str_contains( $html, 'googletagmanager.com/gtag/js' ) ) {
			$this->analytics_present = true;
		}

		// External assets this section does not use. fonts.googleapis is
		// matched but googletagmanager and googlesyndication must never be —
		// hence the explicit guard rather than a bare /google/ pattern.
		$html = preg_replace(
			'#<link[^>]+href=["\'][^"\']*(?:elementor|hello-elementor|fonts\.googleapis\.com|fonts\.gstatic\.com|block-library|wp-block)[^"\']*["\'][^>]*>#i',
			'',
			$html
		) ?? $html;

		// External <script src> tags are deliberately NOT touched here.
		//
		// An earlier revision removed them by pattern. It also removed jQuery,
		// Site Kit's event providers and — worst — Google Analytics from the
		// entire register section, and did so silently. The lesson is that
		// script tags carry dependency relationships this filter cannot see,
		// so it has no business guessing. Unwanted scripts are handled by
		// dequeuing their registered handles, where WordPress resolves those
		// dependencies for us; anything that reaches the output stays.
		//
		// The stylesheet and inline-CSS work below is where the weight was
		// anyway: 16 stylesheets and 51 KB of inline CSS on a page whose own
		// markup is 25 KB.

		// Inline blocks.
		$html = preg_replace_callback(
			'#<style[^>]*>(.*?)</style>#is',
			static function ( array $m ): string {
				$css = $m[1];
				if ( preg_match( '/cmplz|consent|banner/i', $css ) ) {
					return $m[0]; // consent UI must keep its styling
				}
				if ( preg_match( '/wp-block-|elementor|--e-global|\.e-con|elementor-kit|swiper/i', $css ) ) {
					return '';
				}
				return $m[0];
			},
			$html
		) ?? $html;

		// Inline scripts: only Elementor's own frontend config objects, which
		// are large, and useless without the Elementor runtime we dequeued.
		$html = preg_replace_callback(
			'#<script(?![^>]*src)([^>]*)>(.*?)</script>#is',
			static function ( array $m ): string {
				$js = $m[2];
				// Never touch consent, analytics, or structured data.
				if ( preg_match( '/cmplz|consent|gtag|dataLayer|googlesitekit|adsbygoogle|application\/ld\+json/i', $m[1] . $js ) ) {
					return $m[0];
				}
				if ( preg_match( '/elementorFrontendConfig|ElementorProFrontendConfig|elementorCommon/i', $js ) ) {
					return '';
				}
				return $m[0];
			},
			$html
		) ?? $html;

		return $html;
	}

	public function trim_hints( array $hints, string $relation ): array {
		if ( ! $this->ours() ) {
			return $hints;
		}
		return array_values(
			array_filter(
				$hints,
				static fn( $h ) => ! is_string( $h ) || ! str_contains( $h, 'fonts.g' )
			)
		);
	}
}
