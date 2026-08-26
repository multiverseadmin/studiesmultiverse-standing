<?php
/**
 * Plugin Name:       Studies Multiverse — Standing Register
 * Plugin URI:        https://studiesmultiverse.com/standing/
 * Description:       The Standing Register: the worldwide record of which institutions are officially permitted to enrol international students. Renders the register, country hubs, the change record, search and feeds from static JSON built by GitHub Actions. Also owns the site's structured-data identity, replacing the competing snippets that were emitting duplicate Organization and WebSite nodes.
 * Version:           1.25.1
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            A.I.T. Multiverse Consulting Ltd
 * Author URI:        https://studiesmultiverse.com/about/
 * License:           GPL-2.0-or-later
 * Text Domain:       sm-standing
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A PLUGIN AND NOT MORE SNIPPETS
 *
 * The site currently runs 68 active Code Snippets — roughly 297 KB of PHP on
 * every request — and eight of them emit structured data. The result, measured
 * on the live home page, is three Organization nodes and two WebSite nodes in
 * one document. For a site whose entire value is being a citable authority on
 * institutional standing, an ambiguous publisher entity is the worst defect it
 * can have: the machines that would cite us cannot tell who we are.
 *
 * A plugin fixes the class of problem, not one instance of it. It is versioned
 * in git, deploys as one file, rolls back in one click, and cannot be silently
 * broken by an escaping mistake in an unrelated snippet.
 *
 * ---------------------------------------------------------------------------
 * PERFORMANCE CONTRACT
 *
 * This plugin performs NO parsing, NO diffing, NO remote calls and NO database
 * queries while rendering a page. GitHub Actions does the work; WordPress reads
 * a static JSON file from disk and prints HTML. A nightly cron refreshes that
 * file. If the refresh fails, the last good file keeps serving.
 *
 * ---------------------------------------------------------------------------
 * EDITORIAL CONTRACT
 *
 * Every statement rendered here comes from the change record with its caveat
 * attached. This plugin never composes a verdict of its own, and never uses the
 * words "revoked", "banned" or "shut down". A row disappearing from a register
 * is not evidence of wrongdoing.
 */

declare( strict_types=1 );

namespace SM\Standing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SM_STANDING_VERSION', '1.25.1' );
define( 'SM_STANDING_FILE', __FILE__ );
define( 'SM_STANDING_DIR', plugin_dir_path( __FILE__ ) );
define( 'SM_STANDING_URL', plugin_dir_url( __FILE__ ) );

/**
 * Where the static register data lives once pulled from the repository.
 * Kept in uploads so it survives plugin updates.
 */
function data_dir(): string {
	$uploads = wp_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . 'sm-standing';
}

/**
 * The published data source. Raw GitHub is deliberate: it is free, fast,
 * CDN-backed, and — most importantly — the commit history behind it is the
 * tamper-evident public archive that makes the whole record citable.
 */
const DATA_BASE = 'https://raw.githubusercontent.com/multiverseadmin/studiesmultiverse-standing/main/public/';

require_once SM_STANDING_DIR . 'includes/class-data.php';
require_once SM_STANDING_DIR . 'includes/class-identity.php';
require_once SM_STANDING_DIR . 'includes/class-routes.php';
require_once SM_STANDING_DIR . 'includes/class-render.php';
require_once SM_STANDING_DIR . 'includes/class-feeds.php';
require_once SM_STANDING_DIR . 'includes/class-performance.php';
require_once SM_STANDING_DIR . 'includes/class-api.php';
require_once SM_STANDING_DIR . 'includes/class-elementor.php';

add_action(
	'plugins_loaded',
	static function (): void {
		Data::instance();
		Identity::instance();
		Routes::instance();
		Feeds::instance();
		Performance::instance();
		Api::instance();
		Elementor::instance();
	}
);

/**
 * Derived artefacts that repair themselves when the plugin version changes.
 *
 * The activation hook flushes once, which is the documented pattern and is not
 * enough. Rules registered on `init` are absent from the request that activates
 * the plugin — the plugin's own init callbacks never ran that request — so a
 * flush at activation writes the rules that happen to exist at that moment and
 * misses the rest. Worse, an *update* runs no activation hook at all, so a
 * release that adds or reorders a rule ships a URL that quietly 404s until
 * somebody thinks to open Settings → Permalinks. That is exactly how the feeds
 * and the sitemap stayed dead: nothing was broken enough to notice.
 *
 * Stamping the version means the repair happens by itself, once, on the first
 * request after any upgrade, and never again until the next one. The cost is a
 * single option read per request.
 */
add_action(
	'init',
	static function (): void {
		if ( get_option( 'sm_standing_rules_version' ) === SM_STANDING_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );

		// The search index is derived too, and it goes stale the same silent way.
		//
		// v1.18.0 taught the index to recognise Japanese column names, and the
		// index still contained no Japanese institution afterwards, because it is
		// only rebuilt during a data refresh — and an upgrade is not a refresh.
		// The API looked fixed while the site's own search box, which reads this
		// file, stayed blind. Anything computed from the data has to be rebuilt
		// when the code that computes it changes, or "deployed" and "working" go
		// on meaning different things.
		//
		// This reads cached JSON from disk and writes one file. No network.
		Data::instance()->build_search_index();

		update_option( 'sm_standing_rules_version', SM_STANDING_VERSION, true );
	},
	99
);

/**
 * Activation: create the data directory, seed it, register rewrites.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		wp_mkdir_p( data_dir() );

		// Deny direct directory listing of the cache.
		$index = trailingslashit( data_dir() ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' );
		}

		Routes::instance()->register_rules();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( 'sm_standing_refresh' ) ) {
			// Hourly is generous: CRICOS is monthly and the UK register is daily.
			// The cost of a check that finds nothing is one conditional request.
			wp_schedule_event( time() + 300, 'hourly', 'sm_standing_refresh' );
		}

		// Pull immediately so the site is never live with an empty register.
		Data::instance()->refresh_all( true );
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( 'sm_standing_refresh' );
		flush_rewrite_rules();
	}
);

/**
 * Admin notice if the register has gone stale.
 *
 * A stale register is worse than an absent one, because a visitor cannot tell.
 * This is the same instinct as the sanity gate: fail visibly, never quietly.
 */
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$age = Data::instance()->age_in_hours();
		if ( null === $age ) {
			printf(
				'<div class="notice notice-error"><p><strong>Standing Register:</strong> no data has been pulled yet. '
				. 'Check that the repository is public and that <code>%s</code> is reachable.</p></div>',
				esc_html( DATA_BASE . 'standing.json' )
			);
			return;
		}
		if ( $age > 48 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Standing Register:</strong> the register data is %d hours old. '
				. 'The nightly pull may be failing — check the GitHub Actions runs before trusting what the site is showing.</p></div>',
				(int) $age
			);
		}
	}
);
