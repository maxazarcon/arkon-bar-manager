<?php
/**
 * Storage for what agents ask for and what happened to it.
 *
 * Two tables. Pending changes are requests the rule would not apply without a
 * person: the operation, its parameters, a fingerprint of the event as it was,
 * and a plain summary of what would change. The log is every agent write and
 * every decision, kept so "what did the agent do" has an answer.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Agent_Store {

	/** Bump to re-run dbDelta. */
	const SCHEMA_VERSION = '1';

	/** A pending change nobody decided on stops being offered after this long. */
	const EXPIRE_DAYS = 7;

	const STATUSES = array( 'pending', 'approved', 'rejected', 'stale', 'expired' );

	/** @return string */
	public static function changes_table() {
		global $wpdb;
		return $wpdb->prefix . 'abm_agent_changes';
	}

	/** @return string */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'abm_agent_log';
	}

	/**
	 * Create or update both tables. Safe to call repeatedly.
	 */
	public static function install_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$changes = self::changes_table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$changes} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				op varchar(40) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				params longtext NOT NULL,
				fingerprint varchar(64) NOT NULL DEFAULT '',
				summary longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				decided_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY status (status, created_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_gmt datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				op varchar(40) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				outcome varchar(30) NOT NULL DEFAULT '',
				removed_upcoming int(11) NOT NULL DEFAULT 0,
				summary longtext NOT NULL,
				change_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY post_id (post_id, created_gmt)
			) {$charset};"
		);

		update_option( 'abm_agent_schema', self::SCHEMA_VERSION, false );
	}

	/**
	 * Install on demand, for a site that updated the plugin files without the
	 * admin upgrade routine having run yet.
	 */
	public static function maybe_install() {
		if ( get_option( 'abm_agent_schema' ) !== self::SCHEMA_VERSION ) {
			self::install_tables();
		}
	}

	/* --------------------------------------------------------------------- */
	/* Pending changes                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array $change {op,post_id,params,fingerprint,summary,user_id}.
	 * @return int New row ID, or 0 on failure.
	 */
	public static function add_change( array $change ) {
		global $wpdb;
		self::maybe_install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			self::changes_table(),
			array(
				'op'          => (string) ( $change['op'] ?? '' ),
				'post_id'     => (int) ( $change['post_id'] ?? 0 ),
				'params'      => (string) wp_json_encode( $change['params'] ?? array() ),
				'fingerprint' => (string) ( $change['fingerprint'] ?? '' ),
				'summary'     => (string) wp_json_encode( $change['summary'] ?? array() ),
				'status'      => 'pending',
				'user_id'     => (int) ( $change['user_id'] ?? 0 ),
				'created_gmt' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get_change( $id ) {
		global $wpdb;
		self::maybe_install();
		self::expire_old();

		$table = self::changes_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? self::shape_change( $row ) : null;
	}

	/**
	 * @param string $status One of STATUSES, or '' for all.
	 * @param int    $limit  Maximum rows.
	 * @return array[]
	 */
	public static function list_changes( $status = '', $limit = 50 ) {
		global $wpdb;
		self::maybe_install();
		self::expire_old();

		$table = self::changes_table();
		$limit = max( 1, min( 200, (int) $limit ) );

		if ( in_array( $status, self::STATUSES, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		}

		return array_map( array( __CLASS__, 'shape_change' ), (array) $rows );
	}

	/**
	 * Move a pending change to a decided state. A change that is no longer
	 * pending is left alone, so a second click cannot re-decide it.
	 *
	 * @param int    $id         Row ID.
	 * @param string $status     New status.
	 * @param int    $decided_by User ID, 0 for the system.
	 * @return bool Whether this call made the transition.
	 */
	public static function set_change_status( $id, $status, $decided_by = 0 ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->update(
			self::changes_table(),
			array(
				'status'      => $status,
				'decided_gmt' => current_time( 'mysql', true ),
				'decided_by'  => (int) $decided_by,
			),
			array(
				'id'     => (int) $id,
				'status' => 'pending',
			),
			array( '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		return (bool) $changed;
	}

	/**
	 * Mark undecided changes past their window as expired.
	 *
	 * @return int Rows expired.
	 */
	public static function expire_old() {
		global $wpdb;
		$table  = self::changes_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::EXPIRE_DAYS * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired', decided_gmt = %s WHERE status = 'pending' AND created_gmt < %s", current_time( 'mysql', true ), $cutoff ) );
	}

	/**
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		if ( get_option( 'abm_agent_schema' ) !== self::SCHEMA_VERSION ) {
			return 0;
		}
		$table  = self::changes_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::EXPIRE_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND created_gmt >= %s", $cutoff ) );
	}

	/**
	 * @param array $row Database row.
	 * @return array
	 */
	private static function shape_change( array $row ) {
		$params  = json_decode( (string) $row['params'], true );
		$summary = json_decode( (string) $row['summary'], true );

		return array(
			'id'          => (int) $row['id'],
			'op'          => (string) $row['op'],
			'post_id'     => (int) $row['post_id'],
			'params'      => is_array( $params ) ? $params : array(),
			'fingerprint' => (string) $row['fingerprint'],
			'summary'     => is_array( $summary ) ? $summary : array(),
			'status'      => (string) $row['status'],
			'user_id'     => (int) $row['user_id'],
			'created_gmt' => (string) $row['created_gmt'],
			'decided_gmt' => (string) $row['decided_gmt'],
			'decided_by'  => (int) $row['decided_by'],
		);
	}

	/* --------------------------------------------------------------------- */
	/* Log                                                                   */
	/* --------------------------------------------------------------------- */

	/**
	 * @param array $entry {user_id,op,post_id,outcome,summary,change_id,removed_upcoming}.
	 */
	public static function log( array $entry ) {
		global $wpdb;
		self::maybe_install();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::log_table(),
			array(
				'created_gmt'      => current_time( 'mysql', true ),
				'user_id'          => (int) ( $entry['user_id'] ?? 0 ),
				'op'               => (string) ( $entry['op'] ?? '' ),
				'post_id'          => (int) ( $entry['post_id'] ?? 0 ),
				'outcome'          => (string) ( $entry['outcome'] ?? '' ),
				'removed_upcoming' => (int) ( $entry['removed_upcoming'] ?? 0 ),
				'summary'          => (string) wp_json_encode( $entry['summary'] ?? array() ),
				'change_id'        => (int) ( $entry['change_id'] ?? 0 ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%d' )
		);
	}

	/**
	 * @param int $limit   Maximum rows.
	 * @param int $post_id Limit to one event, or 0 for all.
	 * @return array[]
	 */
	public static function get_log( $limit = 100, $post_id = 0 ) {
		global $wpdb;
		self::maybe_install();

		$table = self::log_table();
		$limit = max( 1, min( 500, (int) $limit ) );

		if ( $post_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$summary = json_decode( (string) $row['summary'], true );
			$out[]   = array(
				'id'               => (int) $row['id'],
				'created_gmt'      => (string) $row['created_gmt'],
				'user_id'          => (int) $row['user_id'],
				'op'               => (string) $row['op'],
				'post_id'          => (int) $row['post_id'],
				'outcome'          => (string) $row['outcome'],
				'removed_upcoming' => (int) $row['removed_upcoming'],
				'summary'          => is_array( $summary ) ? $summary : array(),
				'change_id'        => (int) $row['change_id'],
			);
		}
		return $out;
	}

	/**
	 * How many upcoming dates agents have skipped from one event lately.
	 *
	 * Only skips count, and only ones that were applied. A night put back with
	 * unskip is taken off again, so skipping and restoring the same date does
	 * not use up the allowance.
	 *
	 * @param int $post_id Event ID.
	 * @param int $days    Window.
	 * @return int
	 */
	public static function recent_removed( $post_id, $days = 30 ) {
		global $wpdb;
		self::maybe_install();

		$table  = self::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$net = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( CASE WHEN op = 'skip' THEN removed_upcoming WHEN op = 'unskip' THEN -1 ELSE 0 END ), 0 )
				 FROM {$table}
				 WHERE post_id = %d AND outcome = 'applied' AND created_gmt >= %s AND op IN ('skip','unskip')",
				(int) $post_id,
				$cutoff
			)
		);

		return max( 0, $net );
	}
}
