<?php
/**
 * Update the plugin from its GitHub releases.
 *
 * Uses the mechanism WordPress added in 5.8 rather than a third-party library:
 * an "Update URI" header in the plugin file whose host is github.com makes core
 * fire update_plugins_github.com for this plugin, and nothing else. The plugin
 * is never confused with a wordpress.org one, no other plugin's updates are
 * touched, and there is no dependency to keep current.
 *
 * Set ABM_GITHUB_REPO to the "owner/name" of the repository. It must match the
 * Update URI header; if the two disagree the header wins, because that is what
 * core reads to decide which filter to fire.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Updater {

	/** @var ABM_Updater|null */
	private static $instance = null;

	/** How long to cache a release lookup. GitHub allows 60 unauthenticated calls an hour. */
	const CACHE_HOURS = 6;

	const TRANSIENT = 'abm_github_release';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_for_update' ), 10, 3 );

		// "View details" on the Plugins screen opens a modal that asks the
		// wordpress.org API about this slug. Nothing self-hosted is there, so
		// without this the modal reads "Plugin not found." See below.
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 10, 3 );

		// A GitHub source archive unpacks to "name-1.2.3", not "arkon-bar-manager".
		// WordPress would then install it alongside the real plugin as a second
		// copy rather than updating it. Rename the extracted folder first.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );

		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_cache' ), 10, 0 );
	}

	/**
	 * The plugin's directory name, which is the slug WordPress addresses it by.
	 *
	 * @return string
	 */
	public static function slug() {
		return dirname( plugin_basename( ABM_FILE ) );
	}

	/**
	 * owner/name of the GitHub repository.
	 *
	 * @return string
	 */
	public static function repo() {
		$repo = defined( 'ABM_GITHUB_REPO' ) ? ABM_GITHUB_REPO : '';
		/**
		 * Filter the GitHub repository this plugin updates from.
		 *
		 * @param string $repo "owner/name".
		 */
		return (string) apply_filters( 'abm_github_repo', $repo );
	}

	/**
	 * Latest release from the GitHub API, or null.
	 *
	 * Cached either way: a failed lookup is cached for a shorter period so a rate
	 * limit or an outage does not mean a request on every admin page load.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null
	 */
	public static function latest_release( $force = false ) {
		$repo = self::repo();
		if ( '' === $repo ) {
			return null;
		}

		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'none' === $cached ) {
				return null;
			}
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ArkonBarManager/' . ABM_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// Prefer a zip attached to the release. The source archive GitHub generates
		// automatically unpacks to the wrong folder name; fix_source_folder()
		// handles that, but an asset built with `git archive --prefix=` is the
		// intended artifact and carries no repository metadata.
		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( (string) $asset['name'], -4 ) ) ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( '' === $package && ! empty( $body['zipball_url'] ) ) {
			$package = (string) $body['zipball_url'];
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'url'       => ! empty( $body['html_url'] ) ? (string) $body['html_url'] : 'https://github.com/' . $repo,
			'notes'     => ! empty( $body['body'] ) ? (string) $body['body'] : '',
			'published' => ! empty( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_transient( self::TRANSIENT, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Tell WordPress whether a newer release exists.
	 *
	 * Core calls this only for plugins whose Update URI host is github.com, so
	 * the guard below is belt and braces rather than the main defence.
	 *
	 * @param array|false $update      Existing update payload.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file, relative to the plugins dir.
	 * @return array|false
	 */
	public static function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( ABM_FILE ) !== $plugin_file ) {
			return $update;
		}

		// "Check again" has to mean check again. WordPress's force-check clears its
		// own update transient but knows nothing about the cache below, so without
		// this a release published in the last six hours stays invisible however
		// many times the button is pressed — which reads as the updater being broken.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; core does not nonce this either.
		$force = ! empty( $_GET['force-check'] );

		$release = self::latest_release( $force );
		if ( ! $release || '' === $release['package'] ) {
			return $update;
		}

		/*
		 * Returned whether or not it is newer, and core decides which list it
		 * belongs in: newer goes to the update transient's `response`, anything
		 * else to `no_update`. Both matter. The Plugins screen only renders a
		 * "View details" link for a plugin it holds a slug for, and it reads that
		 * slug from one of those two lists -- so returning nothing when the
		 * plugin is current is what makes the link appear only while an update is
		 * pending, which is a confusing place for it to live.
		 */
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::slug(),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			// "Tested up to" is not a header WordPress reads, so $plugin_data
			// never carries it however it is spelled. readme.txt is where it is.
			'tested'       => ABM_Changelog::header( 'Tested up to' ),
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Answer the plugin-details modal for this plugin.
	 *
	 * "View details" on the Plugins screen opens plugin-install.php in a
	 * thickbox, which calls plugins_api() -- and plugins_api() asks
	 * api.wordpress.org. A self-hosted plugin is not there, so the request 404s
	 * and the modal renders "Plugin not found."
	 *
	 * That is not a limitation of hosting updates yourself; the API call is just
	 * a filterable default. Returning an object here short-circuits it, and the
	 * modal renders whatever is provided. The content comes from readme.txt and
	 * the GitHub release, so there is no third copy of it to keep current.
	 *
	 * The filter must return $result untouched for every other plugin, or this
	 * would answer for the whole site.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Arguments for the request.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin = get_plugin_data( ABM_FILE, false, false );

		$release = self::latest_release();

		// Modal tab => readme.txt section. Missing sections are skipped, so this
		// does not have to match any particular readme.
		$sections = array();
		foreach ( array(
			'description'  => 'Description',
			'installation' => 'Installation',
			'faq'          => 'Frequently Asked Questions',
			'reference'    => 'Reference',
			'updates'      => 'Updates',
			'notes'        => 'Notes',
			'changelog'    => 'Changelog',
		) as $tab => $heading ) {
			$body = ABM_Changelog::to_html( ABM_Changelog::section( $heading ) );
			if ( '' !== $body ) {
				$sections[ $tab ] = $body;
			}
		}

		$package = ( $release && '' !== $release['package'] ) ? $release['package'] : '';

		/*
		 * The newest release, unless what is installed is newer than it. That
		 * happens whenever a build is installed before its release is published,
		 * and it would otherwise make the modal report a version older than the
		 * one the reader is running -- which reads as the plugin having been
		 * downgraded behind their back.
		 */
		$latest  = ( $release && ! empty( $release['version'] ) ) ? $release['version'] : '';
		$version = ( '' !== $latest && version_compare( $latest, ABM_VERSION, '>' ) ) ? $latest : ABM_VERSION;

		$information = array(
			'name'             => $plugin['Name'],
			'slug'             => self::slug(),
			'version'          => $version,
			'author'           => $plugin['Author'],
			'author_profile'   => $plugin['AuthorURI'],
			'homepage'         => $plugin['PluginURI'],
			'requires'         => $plugin['RequiresWP'],
			'requires_php'     => $plugin['RequiresPHP'],
			'tested'           => ABM_Changelog::header( 'Tested up to' ),
			'short_description' => $plugin['Description'],
			'download_link'    => $package,
			'sections'         => $sections,
		);

		// Ratings and install counts do not exist for a plugin that is not on
		// wordpress.org. Left unset rather than faked; the modal omits those
		// panels rather than showing zeroes.
		if ( $release && ! empty( $release['published'] ) ) {
			$information['last_updated'] = $release['published'];
		}

		return (object) $information;
	}

	/**
	 * Rename the unpacked folder so an update replaces the plugin instead of
	 * installing a second copy beside it.
	 *
	 * This is the failure that produces two entries on the Plugins screen, one
	 * active and one not, with the update appearing to have done nothing.
	 *
	 * @param string      $source        Path the package was unpacked to.
	 * @param string      $remote_source Parent of $source.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $args          Extra args; carries the plugin being updated.
	 * @return string|WP_Error
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader, $args = array() ) {
		if ( empty( $args['plugin'] ) || plugin_basename( ABM_FILE ) !== $args['plugin'] ) {
			return $source;
		}

		$wanted = trailingslashit( $remote_source ) . dirname( plugin_basename( ABM_FILE ) );
		if ( untrailingslashit( $source ) === $wanted ) {
			return $source; // Already correct: a properly built release asset.
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $wanted, true ) ) {
			return $source; // Leave it alone rather than half-move it.
		}

		return trailingslashit( $wanted );
	}

	/**
	 * Drop the cached lookup after any update runs, so the Plugins screen does
	 * not keep offering a version that is already installed.
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT );
	}
}
