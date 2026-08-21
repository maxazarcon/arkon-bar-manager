<?php
/**
 * Occurrence model.
 *
 * An event post holds one date plus an optional recurrence rule. This class
 * materializes that into concrete rows in {prefix}abm_occurrences so the
 * calendar can order and paginate by real dates in SQL instead of expanding
 * rules in PHP.
 *
 * A non-recurring event gets exactly one row, so every consumer downstream
 * reads occurrences and never has to special-case the single-date event.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Occurrences {

	/** @var ABM_Occurrences|null */
	private static $instance = null;

	/**
	 * Request-scoped memo for is_recurring().
	 *
	 * The calendar and the Looper both ask this once per rendered row, and on a
	 * venue calendar most rows belong to the same two weekly events — so without
	 * a cache a 50-row batch fires 50 near-identical COUNT queries about a
	 * handful of posts. Recurrence cannot change mid-request, so memoizing is
	 * safe; every writer below clears the entry it touches.
	 *
	 * @var array<int,bool>
	 */
	private static $recurring_cache = array();

	/** Bump to force a table upgrade in maybe_install_table(). */
	const SCHEMA_VERSION = '1';

	/** How far ahead open-ended rules are materialized, in months. */
	const DEFAULT_HORIZON_MONTHS = 24;

	/** Hard ceiling on rows generated for a single event, whatever the rule says. */
	const MAX_ROWS_PER_EVENT = 1000;

	const CRON_HOOK = 'abm_extend_occurrences';

	/**
	 * Marks an event whose dates were written verbatim rather than derived from
	 * a rule — an import, essentially. Such an event has no recurrence rule to
	 * regenerate from, so regenerating it would collapse every date it has down
	 * to the single value in abm_event_date.
	 */
	const EXPLICIT_META = 'abm_occurrences_explicit';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install_table' ) );

		// Priority 25: after ABM_Meta::save (10) and sync_derived (20), so the
		// date and recurrence meta are final before we expand them.
		add_action( 'save_post_' . ABM_POST_TYPE, array( __CLASS__, 'on_save_post' ), 25, 2 );

		// REST writes meta *after* wp_update_post(), so save_post has already run
		// against the old values by the time abm_event_date lands. Without this
		// hook an event created or edited over REST gets occurrences built from
		// stale meta, or none at all.
		add_action( 'rest_after_insert_' . ABM_POST_TYPE, array( __CLASS__, 'on_rest_save' ), 10, 1 );

		// Status transitions (trash, untrash, publish from draft) change whether
		// an event should appear at all.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_post' ) );

		// A horizon change alters how far open-ended rules run.
		add_action( 'update_option_' . ABM_SETTINGS, array( __CLASS__, 'rebuild_open_ended' ) );

		// Roll the horizon forward so open-ended events never run dry.
		add_action( self::CRON_HOOK, array( __CLASS__, 'rebuild_open_ended' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ) );
	}

	/* --------------------------------------------------------------------- */
	/* Schema                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * @return string Fully-prefixed table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'abm_occurrences';
	}

	/**
	 * Create or upgrade the occurrence table. Safe to call repeatedly.
	 */
	public static function maybe_install_table() {
		if ( get_option( 'abm_occurrences_schema' ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install_table();
		update_option( 'abm_occurrences_schema', self::SCHEMA_VERSION );
	}

	/**
	 * Run the table definition through dbDelta.
	 */
	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// occur_date + start_time + id is the calendar's sort key, so it leads the
		// composite index; the keyset cursor in ABM_Calendar walks exactly this.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			occur_date date NOT NULL,
			start_time varchar(5) NOT NULL DEFAULT '',
			end_time varchar(8) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY abm_order (occur_date,start_time,id),
			KEY abm_post (post_id)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Register the daily horizon-extension event.
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Settings                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * How many months ahead open-ended recurrence is materialized.
	 *
	 * @return int
	 */
	public static function horizon_months() {
		$months = (int) abm_get_setting( 'recur_horizon_months', self::DEFAULT_HORIZON_MONTHS );
		return max( 1, min( 120, $months ) );
	}

	/* --------------------------------------------------------------------- */
	/* Hooks                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::generate_for_post( $post_id );
	}

	/**
	 * Re-derive everything once a REST request has finished writing meta.
	 *
	 * @param WP_Post $post Post object.
	 */
	public static function on_rest_save( $post ) {
		if ( ! $post instanceof WP_Post || ABM_POST_TYPE !== $post->post_type ) {
			return;
		}
		// Display strings are derived from the same meta, so refresh them here
		// too rather than leaving them a request behind.
		if ( class_exists( 'ABM_Meta' ) ) {
			ABM_Meta::instance()->sync_derived( $post->ID, $post );
		}
		self::generate_for_post( $post->ID );
	}

	/**
	 * @param string  $new New status.
	 * @param string  $old Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_transition( $new, $old, $post ) {
		if ( ! $post instanceof WP_Post || ABM_POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( $new === $old ) {
			return;
		}
		// Rows are kept for any non-deleted status; the calendar join filters on
		// post_status, so trashing an event hides it without losing the expansion.
		self::generate_for_post( $post->ID );
	}

	/**
	 * Regenerate every event whose rule has no explicit end, so the rolling
	 * horizon keeps moving forward.
	 */
	public static function rebuild_open_ended() {
		$ids = get_posts(
			array(
				'post_type'        => ABM_POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => 'abm_recur_type',
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			$rule = self::get_rule( $id );
			if ( '' === $rule['until'] && 0 === $rule['count'] ) {
				self::generate_for_post( $id );
			}
		}
	}

	/* --------------------------------------------------------------------- */
	/* Rule access                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Read and normalize an event's recurrence rule.
	 *
	 * type: '' (single) | daily | weekly | monthly_date | monthly_day
	 *   monthly_date = same day-of-month as the start date (e.g. the 14th)
	 *   monthly_day  = same weekday-of-month as the start date (e.g. 2nd Tuesday)
	 *
	 * @param int $post_id Event ID.
	 * @return array{type:string,interval:int,weekdays:int[],until:string,count:int,exceptions:string[]}
	 */
	public static function get_rule( $post_id ) {
		$type = (string) get_post_meta( $post_id, 'abm_recur_type', true );
		if ( ! in_array( $type, array( 'daily', 'weekly', 'monthly_date', 'monthly_day' ), true ) ) {
			$type = '';
		}

		$interval = (int) get_post_meta( $post_id, 'abm_recur_interval', true );
		$interval = max( 1, min( 52, $interval ?: 1 ) );

		$weekdays = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', array_filter( explode( ',', (string) get_post_meta( $post_id, 'abm_recur_weekdays', true ) ), 'strlen' ) ),
					static function ( $d ) {
						return $d >= 0 && $d <= 6;
					}
				)
			)
		);
		sort( $weekdays );

		$exceptions = array_values(
			array_filter(
				array_map( 'abm_sanitize_date', array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, 'abm_recur_exceptions', true ) ) ) ),
				'strlen'
			)
		);

		return array(
			'type'       => $type,
			'interval'   => $interval,
			'weekdays'   => $weekdays,
			'until'      => abm_sanitize_date( get_post_meta( $post_id, 'abm_recur_until', true ) ),
			'count'      => max( 0, (int) get_post_meta( $post_id, 'abm_recur_count', true ) ),
			'exceptions' => $exceptions,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Generation                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * Rebuild the occurrence rows for a single event.
	 *
	 * @param int $post_id Event ID.
	 * @return int Rows written.
	 */
	public static function generate_for_post( $post_id, $force = false ) {
		global $wpdb;

		$post_id = (int) $post_id;

		// Protect imported dates.
		//
		// An event whose occurrences were written verbatim (by the MEC importer)
		// has no recurrence rule, because its dates came from the source system
		// rather than from a pattern. Regenerating it therefore produces exactly
		// one row — the single value in abm_event_date — silently destroying
		// hundreds of real dates. This ran on every plugin update via
		// abm_maybe_upgrade() -> rebuild_all() and collapsed a finished migration
		// from 1,229 occurrences to 337.
		//
		// Only multi-date sets are protected: a single-date imported event
		// regenerates to the same single row, so letting it through keeps date
		// edits working normally for the common case.
		if ( ! $force && self::is_protected_explicit( $post_id ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . self::table() . " WHERE post_id = %d", $post_id ) ); // phpcs:ignore
		}

		self::delete_for_post( $post_id );
		self::flush_cache( $post_id );

		// A rule now governs this event, so it is no longer verbatim.
		delete_post_meta( $post_id, self::EXPLICIT_META );

		$date = abm_sanitize_date( get_post_meta( $post_id, 'abm_event_date', true ) );
		if ( '' === $date ) {
			return 0; // Nothing to expand without a start date.
		}

		$start = (string) get_post_meta( $post_id, 'abm_event_time_start', true );
		$end   = (string) get_post_meta( $post_id, 'abm_event_time_end', true );
		$dates = self::expand( $date, self::get_rule( $post_id ) );

		if ( ! $dates ) {
			return 0;
		}

		$table  = self::table();
		$values = array();
		$args   = array();
		foreach ( $dates as $d ) {
			$values[] = '(%d,%s,%s,%s)';
			array_push( $args, $post_id, $d, $start, $end );
		}

		// Chunked multi-row insert: 93 weekly occurrences should cost a handful of
		// queries, not 93.
		$chunk_size = 100;
		$written    = 0;
		$total      = count( $values );
		for ( $i = 0; $i < $total; $i += $chunk_size ) {
			$v_chunk = array_slice( $values, $i, $chunk_size );
			$a_chunk = array_slice( $args, $i * 4, $chunk_size * 4 );
			$sql     = "INSERT INTO {$table} (post_id, occur_date, start_time, end_time) VALUES " . implode( ',', $v_chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above, values passed through prepare().
			$written += (int) $wpdb->query( $wpdb->prepare( $sql, $a_chunk ) );
		}

		return $written;
	}

	/**
	 * Whether this event's rows must not be regenerated from a rule.
	 *
	 * True when the rows were written verbatim, there is no rule to rebuild
	 * from, and there is more than one of them to lose.
	 *
	 * @param int $post_id Event ID.
	 * @return bool
	 */
	public static function is_protected_explicit( $post_id ) {
		if ( ! get_post_meta( $post_id, self::EXPLICIT_META, true ) ) {
			return false;
		}

		$rule = self::get_rule( $post_id );
		if ( '' !== $rule['type'] ) {
			return false; // A rule was set; it takes precedence.
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d LIMIT 2", (int) $post_id ) );

		return count( $rows ) > 1;
	}

	/**
	 * Delete every occurrence row for an event.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function delete_for_post( $post_id ) {
		self::flush_cache( $post_id );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Expand a start date plus a rule into a list of Y-m-d strings.
	 *
	 * Bounded three ways, whichever comes first: an explicit until date, an
	 * explicit occurrence count, or the rolling horizon. MAX_ROWS_PER_EVENT is a
	 * final backstop so a pathological rule can never run away.
	 *
	 * @param string $start_date Y-m-d.
	 * @param array  $rule       Rule from get_rule().
	 * @return string[]
	 */
	public static function expand( $start_date, array $rule ) {
		$tz    = wp_timezone();
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $start_date . ' 00:00:00', $tz );
		if ( ! $start ) {
			return array();
		}

		if ( '' === $rule['type'] ) {
			return array( $start->format( 'Y-m-d' ) );
		}

		// Horizon is measured from today, not from the event's start date, so a
		// long-running weekly event keeps the same forward window as a new one.
		$horizon = ( new DateTimeImmutable( 'now', $tz ) )
			->setTime( 0, 0, 0 )
			->modify( '+' . self::horizon_months() . ' months' );

		// A start date beyond the horizon still deserves its first occurrence.
		if ( $start > $horizon ) {
			$horizon = $start;
		}

		$until = '';
		if ( '' !== $rule['until'] ) {
			$until = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $rule['until'] . ' 23:59:59', $tz );
		}

		$limit      = $rule['count'] > 0 ? min( $rule['count'], self::MAX_ROWS_PER_EVENT ) : self::MAX_ROWS_PER_EVENT;
		$exceptions = array_flip( $rule['exceptions'] );
		$out        = array();

		foreach ( self::candidates( $start, $rule, $horizon, $until ) as $candidate ) {
			$ymd = $candidate->format( 'Y-m-d' );
			if ( ! isset( $exceptions[ $ymd ] ) ) {
				$out[] = $ymd;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * Yield candidate dates for a rule in ascending order, stopping at the
	 * horizon or the until date.
	 *
	 * Note the ordering contract: candidates come out sorted, which is what lets
	 * expand() apply the count limit by simply taking the first N.
	 *
	 * @param DateTimeImmutable      $start   Start date (midnight).
	 * @param array                  $rule    Rule.
	 * @param DateTimeImmutable      $horizon Hard stop.
	 * @param DateTimeImmutable|string $until Explicit end, or ''.
	 * @return Generator<DateTimeImmutable>
	 */
	private static function candidates( DateTimeImmutable $start, array $rule, DateTimeImmutable $horizon, $until ) {
		$stop = ( $until instanceof DateTimeImmutable && $until < $horizon ) ? $until : $horizon;
		$step = $rule['interval'];

		switch ( $rule['type'] ) {

			case 'daily':
				for ( $d = $start; $d <= $stop; $d = $d->modify( '+' . $step . ' days' ) ) {
					yield $d;
				}
				return;

			case 'weekly':
				$weekdays = $rule['weekdays'];
				if ( ! $weekdays ) {
					$weekdays = array( (int) $start->format( 'w' ) );
				}

				// Walk week blocks from the start's own week so an interval > 1
				// counts weeks from the event, not from an arbitrary epoch.
				$week_start = $start->modify( '-' . (int) $start->format( 'w' ) . ' days' );
				for ( $w = $week_start; $w <= $stop; $w = $w->modify( '+' . ( $step * 7 ) . ' days' ) ) {
					foreach ( $weekdays as $wd ) {
						$d = $w->modify( '+' . $wd . ' days' );
						if ( $d >= $start && $d <= $stop ) {
							yield $d;
						}
					}
				}
				return;

			case 'monthly_date':
				$day = (int) $start->format( 'j' );
				$m   = $start->modify( 'first day of this month' );
				while ( $m <= $stop ) {
					$dim = (int) $m->format( 't' );
					// Skip months with no such day rather than spilling into the
					// next one, which is what a naive "+1 month" on the 31st does.
					if ( $day <= $dim ) {
						$d = $m->setDate( (int) $m->format( 'Y' ), (int) $m->format( 'n' ), $day );
						if ( $d >= $start && $d <= $stop ) {
							yield $d;
						}
					}
					$m = $m->modify( '+' . $step . ' months' );
				}
				return;

			case 'monthly_day':
				$weekday = strtolower( $start->format( 'l' ) );
				$nth     = (int) ceil( (int) $start->format( 'j' ) / 7 ); // 1..5
				$ordinal = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth' );
				$word    = $ordinal[ $nth ] ?? 'first';

				$m = $start->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				while ( $m <= $stop ) {
					$d = $m->modify( $word . ' ' . $weekday . ' of this month' );
					// "fifth <weekday>" rolls into the next month when there isn't
					// one; drop those rather than drifting.
					if ( $d && $d->format( 'n' ) === $m->format( 'n' ) && $d >= $start && $d <= $stop ) {
						yield $d;
					}
					$m = $m->modify( '+' . $step . ' months' );
				}
				return;
		}
	}

	/* --------------------------------------------------------------------- */
	/* Bulk maintenance                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Rebuild every event's occurrences. Used after an import or a manual
	 * "rebuild" from the tools screen.
	 *
	 * @return array{events:int,rows:int}
	 */
	public static function rebuild_all() {
		$ids = get_posts(
			array(
				'post_type'        => ABM_POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$rows      = 0;
		$protected = 0;
		foreach ( $ids as $id ) {
			if ( self::is_protected_explicit( $id ) ) {
				$protected++;
			}
			$rows += self::generate_for_post( $id );
		}

		return array(
			'events'    => count( $ids ),
			'rows'      => $rows,
			'protected' => $protected,
		);
	}

	/**
	 * Materialize only events that have no occurrence rows at all.
	 *
	 * This is what an upgrade should do: give rows to anything that predates the
	 * occurrence table, and touch nothing that already has them.
	 *
	 * @return array{events:int,rows:int}
	 */
	public static function generate_missing() {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$table} o ON o.post_id = p.ID
				 WHERE p.post_type = %s AND o.id IS NULL
				   AND p.post_status NOT IN ('trash','auto-draft')
				 GROUP BY p.ID",
				ABM_POST_TYPE
			)
		);

		$rows = 0;
		foreach ( $ids as $id ) {
			$rows += self::generate_for_post( (int) $id );
		}

		return array(
			'events' => count( $ids ),
			'rows'   => $rows,
		);
	}

	/**
	 * Insert an explicit list of dates for an event, bypassing rule expansion.
	 * Used by the MEC database importer to copy occurrence rows verbatim so the
	 * new calendar matches the old one exactly, rules or not.
	 *
	 * @param int      $post_id Event ID.
	 * @param array    $rows    List of ['date'=>Y-m-d,'start'=>H:i,'end'=>H:i|close].
	 * @return int Rows written.
	 */
	public static function set_explicit( $post_id, array $rows ) {
		global $wpdb;

		$post_id = (int) $post_id;
		self::delete_for_post( $post_id );
		self::flush_cache( $post_id );

		// Mark these rows as verbatim so a later rebuild cannot flatten them.
		update_post_meta( $post_id, self::EXPLICIT_META, 1 );

		$seen   = array();
		$values = array();
		$args   = array();
		foreach ( $rows as $row ) {
			$d = abm_sanitize_date( $row['date'] ?? '' );
			if ( '' === $d || isset( $seen[ $d ] ) ) {
				continue; // De-dupe: MEC can emit repeated rows for one day.
			}
			$seen[ $d ] = true;
			$values[]   = '(%d,%s,%s,%s)';
			array_push( $args, $post_id, $d, (string) ( $row['start'] ?? '' ), (string) ( $row['end'] ?? '' ) );
			if ( count( $values ) >= self::MAX_ROWS_PER_EVENT ) {
				break;
			}
		}

		if ( ! $values ) {
			return 0;
		}

		$table   = self::table();
		$written = 0;
		$total   = count( $values );
		for ( $i = 0; $i < $total; $i += 100 ) {
			$v = array_slice( $values, $i, 100 );
			$a = array_slice( $args, $i * 4, 100 * 4 );
			$sql = "INSERT INTO {$table} (post_id, occur_date, start_time, end_time) VALUES " . implode( ',', $v );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
			$written += (int) $wpdb->query( $wpdb->prepare( $sql, $a ) );
		}

		return $written;
	}

	/**
	 * Count rows, for the tools screen and for tests.
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * The next occurrence date for an event on or after today, used by the single
	 * event view and the export links when an event recurs.
	 *
	 * @param int $post_id Event ID.
	 * @return string Y-m-d or ''.
	 */
	public static function next_date( $post_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT occur_date FROM {$table} WHERE post_id = %d AND occur_date >= %s ORDER BY occur_date ASC LIMIT 1",
				(int) $post_id,
				current_time( 'Y-m-d' )
			)
		);
		return $date ? (string) $date : '';
	}

	/**
	 * Whether an event has more than one occurrence.
	 *
	 * @param int $post_id Event ID.
	 * @return bool
	 */
	public static function is_recurring( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$recurring_cache[ $post_id ] ) ) {
			return self::$recurring_cache[ $post_id ];
		}

		global $wpdb;
		$table = self::table();
		// LIMIT 2 is enough to answer "more than one"; counting all 445 rows of a
		// weekly event to learn that is wasted work.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d LIMIT 2", $post_id ) );

		self::$recurring_cache[ $post_id ] = ( count( $rows ) > 1 );
		return self::$recurring_cache[ $post_id ];
	}

	/**
	 * Drop a memoized answer after the rows behind it change.
	 *
	 * @param int|null $post_id Event ID, or null to clear everything.
	 */
	public static function flush_cache( $post_id = null ) {
		if ( null === $post_id ) {
			self::$recurring_cache = array();
			return;
		}
		unset( self::$recurring_cache[ (int) $post_id ] );
	}

	/**
	 * Whether a given date is a real occurrence of an event. Guards the ?occ=
	 * query arg so a crafted URL can't make an event claim a date it never had.
	 *
	 * @param int    $post_id Event ID.
	 * @param string $ymd     Date.
	 * @return bool
	 */
	public static function has_date( $post_id, $ymd ) {
		$ymd = abm_sanitize_date( $ymd );
		if ( '' === $ymd ) {
			return false;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND occur_date = %s", (int) $post_id, $ymd ) ) > 0;
	}
}
