<?php
/**
 * Minimal WordPress stub so the Standing Register plugin can be rendered and
 * inspected outside WordPress. This is a test harness, not part of the plugin.
 */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['wp'] = (object) [ 'request' => 'standing' ];
$GLOBALS['sm_query_vars'] = [];
$GLOBALS['sm_options'] = [];
$GLOBALS['wp_filter'] = [];

function add_action( $h, $f, $p = 10, $a = 1 ) { $GLOBALS['sm_actions'][$h][] = $f; return true; }
function add_filter( $h, $f, $p = 10, $a = 1 ) { $GLOBALS['sm_filters'][$h][] = $f; return true; }
function remove_action( $h, $f, $p = 10 ) { return true; }
function do_action( $h, ...$a ) {}
function apply_filters( $h, $v, ...$a ) { return $v; }
function register_activation_hook( $f, $c ) {}
function register_deactivation_hook( $f, $c ) {}
function add_rewrite_rule( ...$a ) {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://studiesmultiverse.com/wp-content/plugins/sm/'; }
function wp_upload_dir() { return [ 'basedir' => '/tmp', 'baseurl' => 'https://studiesmultiverse.com/uploads' ]; }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function home_url( $p = '/' ) { return rtrim( 'https://studiesmultiverse.com', '/' ) . $p; }
function admin_url( $p = '' ) { return 'https://studiesmultiverse.com/wp-admin/' . $p; }
function get_option( $k, $d = false ) { return $GLOBALS['sm_options'][$k] ?? $d; }
function update_option( $k, $v, $a = true ) { $GLOBALS['sm_options'][$k] = $v; return true; }
function get_query_var( $k, $d = '' ) { return $GLOBALS['sm_query_vars'][$k] ?? $d; }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_trim_words( $t, $n = 55, $m = '…' ) { $w = preg_split( '/\s+/', (string) $t ); return count( $w ) <= $n ? $t : implode( ' ', array_slice( $w, 0, $n ) ) . $m; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function status_header( $c ) {}
function get_language_attributes() { return 'lang="en"'; }
function is_admin() { return false; }
function is_front_page() { return false; }
function is_page() { return false; }
function current_user_can( $c ) { return false; }
function wp_next_scheduled( $h ) { return false; }
function wp_schedule_event( ...$a ) {}
function wp_clear_scheduled_hook( $h ) {}
function flush_rewrite_rules() {}
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function add_query_arg( ...$a ) { return $a[1] ?? '/'; }
function wp_remote_get( ...$a ) { return new WP_Error(); }
function wp_remote_post( ...$a ) { return null; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function wp_safe_redirect( $u ) {}
function check_admin_referer( $a ) { return true; }
function wp_die( $m ) { die( $m ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_shortcode( $t, $c ) {}
function did_action( $h ) { return 0; }
function register_rest_route( ...$a ) {}
function rest_url( $p = '' ) { return 'https://studiesmultiverse.com/wp-json/' . $p; }
function remove_accents( $s ) { return $s; }
function shortcode_atts( $p, $a ) { return array_merge( $p, (array) $a ); }
function wp_dequeue_style( $h ) {} function wp_deregister_style( $h ) {}
function wp_dequeue_script( $h ) {} function wp_deregister_script( $h ) {}
function get_pages( $a = [] ) { return [ (object) [ 'ID' => 1, 'post_title' => 'About' ] ]; }
function get_the_title( $p ) { return is_object( $p ) ? $p->post_title : 'Untitled'; }
function get_permalink( $p ) { return 'https://studiesmultiverse.com/about/'; }
class WP_Error { public function get_error_message() { return 'stub'; } }

// data_dir() resolves to <uploads basedir>/sm-standing, i.e. /tmp/sm-standing.
require_once __DIR__ . '/studiesmultiverse-standing/studiesmultiverse-standing.php';

use SM\Standing\Data;
use SM\Standing\Routes;
use SM\Standing\Render;

$view = $argv[1] ?? 'home';
$GLOBALS['sm_query_vars'] = [ 'sm_standing' => $view === 'home' ? 'home' : 'section', 'sm_standing_a' => $view === 'home' ? '' : $view ];

Data::instance();
\SM\Standing\Identity::instance();
Routes::instance();

ob_start();
Render::instance()->page( Routes::instance()->context() );
$html = ob_get_clean();

file_put_contents( "/tmp/render-{$view}.html", $html );

// Report
$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( $html );
$errs = array_filter( libxml_get_errors(), fn( $e ) => $e->level >= LIBXML_ERR_ERROR );
libxml_clear_errors();

$x = new DOMXPath( $doc );
printf(
	"view=%s  bytes=%d  h1=%d  changes=%d  links=%d  externalReq=%d  htmlErrors=%d\n",
	$view,
	strlen( $html ),
	$x->query( '//h1' )->length,
	$x->query( '//li[contains(@class,"ch ")]' )->length,
	$x->query( '//a' )->length,
	$x->query( '//link[@rel="stylesheet"]|//script[@src]|//img[@src]' )->length,
	count( $errs )
);
