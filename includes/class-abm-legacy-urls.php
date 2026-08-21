<?php
/**
 * Legacy permalink redirects.
 *
 * Events previously lived at /event-archive/<slug>/ under Modern Events
 * Calendar and now live under the plugin's own rewrite base. Those old URLs are
 * scattered across Instagram posts and bios that nobody can edit, and Instagram
 * is the site's single largest referrer, so they have to keep resolving.
 *
 * The map is derived from stored meta rather than hand-maintained: the importer
 * records each event's original slug, so the lookup stays correct as events are
 * added, renamed or removed.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Legacy_URLs {

	/** @var ABM_Legacy_URLs|null */
	private static $instance = null;

	/** Meta key holding an event's pre-migration slug. */
	const SLUG_META = 'abm_legacy_slug';

	/** The old MEC single-event base. */
	const DEFAULT_BASE = 'event-archive';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 1: get in before canonical redirects or a 404 template start
		// doing their own thing with the request.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * The legacy base segment, without slashes.
	 *
	 * @return string
	 */
	public static function base() {
		$base = trim( (string) abm_get_setting( 'legacy_base', self::DEFAULT_BASE ), '/' );
		return $base ? $base : self::DEFAULT_BASE;
	}

	/**
	 * 301 an old event URL to its current permalink.
	 */
	public function maybe_redirect() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return;
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = self::base();

		// Respect a subdirectory install.
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( $home_path && '/' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
		}

		if ( ! preg_match( '#^/' . preg_quote( $base, '#' ) . '(?:/(.*))?$#', $path, $m ) ) {
			return;
		}

		$remainder = isset( $m[1] ) ? trim( $m[1], '/' ) : '';

		// Bare /event-archive/ (or a paged version of it) goes to the calendar.
		if ( '' === $remainder || preg_match( '#^page/\d+$#', $remainder ) ) {
			$target = self::calendar_url();
			if ( $target ) {
				$this->go( $target );
			}
			return;
		}

		// Keep any trailing endpoint such as /ical/ so those links survive too.
		$parts    = explode( '/', $remainder );
		$slug     = sanitize_title( array_shift( $parts ) );
		$endpoint = implode( '/', array_map( 'sanitize_title', $parts ) );

		if ( '' === $slug ) {
			return;
		}

		$post_id = self::find_by_legacy_slug( $slug );
		if ( ! $post_id ) {
			return; // Let WordPress 404 rather than guessing.
		}

		$target = get_permalink( $post_id );
		if ( ! $target ) {
			return;
		}

		if ( '' !== $endpoint ) {
			$target = user_trailingslashit( trailingslashit( $target ) . $endpoint );
		}

		// Carry the query string through, so ?occ=… and campaign tags survive.
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}

		$this->go( $target );
	}

	/**
	 * Issue the redirect. Split out so tests can stub it.
	 *
	 * @param string $target Destination URL.
	 */
	private function go( $target ) {
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Resolve a legacy slug to an event.
	 *
	 * Checks the recorded original slug first, then the post's own slug, which
	 * covers events created directly in the plugin whose slug happens to match an
	 * old link.
	 *
	 * @param string $slug Legacy slug.
	 * @return int Post ID or 0.
	 */
	public static function find_by_legacy_slug( $slug ) {
		global $wpdb;

		$post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s
				   AND p.post_type = %s AND p.post_status = 'publish'
				 LIMIT 1",
				self::SLUG_META,
				$slug,
				ABM_POST_TYPE
			)
		);

		if ( $post_id ) {
			return $post_id;
		}

		$post = get_page_by_path( $slug, OBJECT, ABM_POST_TYPE );
		return ( $post && 'publish' === $post->post_status ) ? (int) $post->ID : 0;
	}

	/**
	 * Where a bare legacy archive request should land.
	 *
	 * @return string
	 */
	public static function calendar_url() {
		$page_id = absint( abm_get_setting( 'calendar_page_id', 0 ) );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		// Fall back to the post type's own rewrite base, which is the page the
		// calendar shortcode normally lives on.
		$obj = get_post_type_object( ABM_POST_TYPE );
		if ( $obj && ! empty( $obj->rewrite['slug'] ) ) {
			return home_url( user_trailingslashit( $obj->rewrite['slug'] ) );
		}

		return home_url( '/' );
	}
}
