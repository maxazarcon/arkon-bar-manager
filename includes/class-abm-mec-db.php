<?php
/**
 * Modern Events Calendar database importer.
 *
 * The CSV importer cannot carry recurrence: a MEC export gives one row per
 * event, keyed on the MEC post ID, so every occurrence of a weekly event
 * collapses onto a single record. On the live site two weekly events account for
 * roughly three quarters of every row the calendar renders, so that collapse is
 * not an edge case, it is most of the calendar.
 *
 * This importer reads MEC's own tables instead and copies the occurrence rows
 * verbatim. Because it runs inside the same WordPress install it can also reuse
 * the existing attachments rather than re-downloading any images.
 *
 * MEC's schema has shifted across versions, so every table and column is
 * resolved at runtime and the importer reports exactly what it found. Nothing is
 * assumed; if a column is missing the importer degrades instead of fatalling.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_MEC_DB {

	/** MEC's event post type. */
	const MEC_POST_TYPE = 'mec-events';

	/** MEC's category taxonomy. */
	const MEC_TAXONOMY = 'mec_category';

	/** Meta recording which MEC post an event came from. */
	const SOURCE_ID = 'abm_import_source_id';
	const SOURCE_KEY = 'abm_import_source';

	/* --------------------------------------------------------------------- */
	/* Schema discovery                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Whether a table exists.
	 *
	 * Probes with a real SELECT rather than SHOW TABLES: SHOW is MySQL-specific
	 * and returns nothing under alternative database layers, which would make a
	 * present table look absent and silently drop every recurrence.
	 *
	 * @param string $table Fully-prefixed name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_results( "SELECT * FROM {$table} LIMIT 1" );
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( $prev );

		return ( '' === (string) $error );
	}

	/**
	 * Column names of a table, lowercased.
	 *
	 * Three strategies, because no single one is reliable everywhere:
	 * SHOW COLUMNS is authoritative on MySQL; reading the keys of a real row
	 * works anywhere but needs at least one row; get_col_info() covers an empty
	 * table on drivers that populate it.
	 *
	 * @param string $table Fully-prefixed name.
	 * @return string[]
	 */
	private static function columns( $table ) {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$prev = $wpdb->suppress_errors( true );

		// 1. MySQL.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		$cols = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r['Field'] ) ) {
					$cols[] = strtolower( $r['Field'] );
				}
			}
		}

		// 2. Keys of an actual row.
		if ( ! $cols ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( "SELECT * FROM {$table} LIMIT 1", ARRAY_A );
			if ( is_array( $row ) && $row ) {
				$cols = array_map( 'strtolower', array_keys( $row ) );
			}
		}

		// 3. Result metadata, for an empty table.
		if ( ! $cols ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_results( "SELECT * FROM {$table} LIMIT 1" );
			$info = $wpdb->get_col_info( 'name' );
			if ( is_array( $info ) && $info ) {
				$cols = array_map( 'strtolower', $info );
			}
		}

		$wpdb->suppress_errors( $prev );

		return array_values( array_unique( $cols ) );
	}

	/**
	 * Pick the first candidate column that actually exists.
	 *
	 * @param string[] $available  Columns present.
	 * @param string[] $candidates Preference order.
	 * @return string Column name or ''.
	 */
	private static function pick( array $available, array $candidates ) {
		foreach ( $candidates as $c ) {
			if ( in_array( $c, $available, true ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Resolve everything the importer needs, in one place.
	 *
	 * @return array
	 */
	public static function detect() {
		global $wpdb;

		$dates_table  = $wpdb->prefix . 'mec_dates';
		$events_table = $wpdb->prefix . 'mec_events';

		$dates_cols  = self::columns( $dates_table );
		$events_cols = self::columns( $events_table );

		$post_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				self::MEC_POST_TYPE
			)
		);

		$map = array(
			'dates_table'   => $dates_table,
			'dates_exists'  => ! empty( $dates_cols ),
			'dates_cols'    => $dates_cols,
			'events_table'  => $events_table,
			'events_exists' => ! empty( $events_cols ),
			'events_cols'   => $events_cols,
			'post_count'    => $post_count,
			// Column resolution, in preference order across known MEC versions.
			'col_post'      => self::pick( $dates_cols, array( 'post_id', 'event_id', 'postid' ) ),
			'col_dstart'    => self::pick( $dates_cols, array( 'dstart', 'start', 'date_start', 'startdate' ) ),
			'col_dend'      => self::pick( $dates_cols, array( 'dend', 'end', 'date_end', 'enddate' ) ),
			'col_status'    => self::pick( $dates_cols, array( 'status' ) ),
			'col_public'    => self::pick( $dates_cols, array( 'public' ) ),
		);

		$map['dates_rows']       = 0;
		$map['status_histogram'] = array();
		$map['status_published'] = array();
		$map['future_rows']      = 0;

		if ( $map['dates_exists'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$map['dates_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dates_table}" );

			// The row-level status column is the thing most likely to silently
			// drop real dates, so show its distribution rather than trusting an
			// assumption about what the values mean.
			if ( $map['col_status'] ) {
				$c = $map['col_status'];
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = (array) $wpdb->get_results( "SELECT {$c} AS s, COUNT(*) AS n FROM {$dates_table} GROUP BY {$c}", ARRAY_A );
				foreach ( $rows as $r ) {
					$map['status_histogram'][ (string) $r['s'] ] = (int) $r['n'];
				}

				// The distribution that actually matters: rows belonging to MEC
				// posts we would import.
				if ( $map['col_post'] ) {
					$pc = $map['col_post'];
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = (array) $wpdb->get_results(
						$wpdb->prepare(
							"SELECT d.{$c} AS s, COUNT(*) AS n
							 FROM {$dates_table} d
							 INNER JOIN {$wpdb->posts} p ON p.ID = d.{$pc}
							 WHERE p.post_type = %s AND p.post_status = 'publish'
							 GROUP BY d.{$c}",
							self::MEC_POST_TYPE
						),
						ARRAY_A
					);
					foreach ( $rows as $r ) {
						$map['status_published'][ (string) $r['s'] ] = (int) $r['n'];
					}
				}
			}

			if ( $map['col_dstart'] && $map['col_post'] ) {
				$ds = $map['col_dstart'];
				$pc = $map['col_post'];
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$map['future_rows'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$dates_table} d
						 INNER JOIN {$wpdb->posts} p ON p.ID = d.{$pc}
						 WHERE p.post_type = %s AND p.post_status = 'publish' AND d.{$ds} >= %s",
						self::MEC_POST_TYPE,
						current_time( 'Y-m-d' )
					)
				);
			}
		}

		$map['usable'] = ( $map['dates_exists'] && $map['col_post'] && $map['col_dstart'] );

		return $map;
	}

	/**
	 * A few raw rows, so the diagnostic screen can show what the data really
	 * looks like rather than making the operator trust a mapping they can't see.
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function sample_rows( $limit = 5 ) {
		global $wpdb;
		$map = self::detect();
		if ( ! $map['dates_exists'] ) {
			return array();
		}
		$table = $map['dates_table'];
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} LIMIT %d", absint( $limit ) ), ARRAY_A );
	}

	/* --------------------------------------------------------------------- */
	/* Reading MEC                                                           */
	/* --------------------------------------------------------------------- */

	/**
	 * Occurrence dates for one MEC event, ascending and de-duplicated.
	 *
	 * @param int   $mec_post_id MEC post ID.
	 * @param array $map         detect() output.
	 * @return string[] Y-m-d
	 */
	public static function dates_for( $mec_post_id, array $map ) {
		global $wpdb;

		if ( ! $map['usable'] ) {
			return array();
		}

		$table  = $map['dates_table'];
		$c_post = $map['col_post'];
		$c_st   = $map['col_dstart'];

		$where = array( "{$c_post} = %d" );
		$args  = array( (int) $mec_post_id );

		// Only published MEC posts are read in the first place, so the post's own
		// status is the authority. The row-level status column is treated as a
		// denylist of explicitly dead rows rather than an allowlist: matching only
		// 'publish' here silently drops every row whose status MEC left as
		// something else, and that loss looks exactly like a successful import.
		if ( $map['col_status'] ) {
			$where[] = "( {$map['col_status']} IS NULL OR {$map['col_status']} NOT IN ('trash','cancelled','canceled') )";
		}
		if ( $map['col_public'] ) {
			$where[] = "( {$map['col_public']} = 1 OR {$map['col_public']} IS NULL )";
		}

		if ( ! empty( $map['skip_before'] ) ) {
			$where[] = "{$c_st} >= %s";
			$args[]  = $map['skip_before'];
		}

		$sql = "SELECT {$c_st} AS dstart FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$c_st} ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		$out = array();
		foreach ( $rows as $raw ) {
			$d = self::normalize_date( $raw );
			if ( '' !== $d ) {
				$out[ $d ] = true;
			}
		}

		return array_keys( $out );
	}

	/**
	 * Coerce whatever MEC stored into Y-m-d. Handles DATE, DATETIME and integer
	 * timestamps, all of which have appeared in the wild.
	 *
	 * @param mixed $raw Raw column value.
	 * @return string
	 */
	public static function normalize_date( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw || '0000-00-00' === substr( $raw, 0, 10 ) ) {
			return '';
		}

		// A bare integer is a unix timestamp.
		if ( ctype_digit( $raw ) && strlen( $raw ) >= 9 ) {
			return gmdate( 'Y-m-d', (int) $raw );
		}

		$candidate = substr( $raw, 0, 10 );
		return abm_sanitize_date( $candidate );
	}

	/**
	 * Start/end times for a MEC event, from its post meta.
	 *
	 * MEC stores these as separate hour / minute / am-pm keys. When the event is
	 * flagged to hide its end time (a common convention for anything running past
	 * midnight) the end is deliberately returned empty so the
	 * calendar renders a start time only, exactly as the live site does.
	 *
	 * @param int $mec_post_id MEC post ID.
	 * @return array{start:string,end:string}
	 */
	public static function times_for( $mec_post_id ) {
		$start = self::assemble_time(
			get_post_meta( $mec_post_id, 'mec_start_time_hour', true ),
			get_post_meta( $mec_post_id, 'mec_start_time_minutes', true ),
			get_post_meta( $mec_post_id, 'mec_start_time_ampm', true )
		);

		$hide_end = (string) get_post_meta( $mec_post_id, 'mec_hide_end_time', true );
		if ( '1' === $hide_end || 'on' === $hide_end || 'yes' === $hide_end ) {
			return array(
				'start' => $start,
				'end'   => '',
			);
		}

		$end = self::assemble_time(
			get_post_meta( $mec_post_id, 'mec_end_time_hour', true ),
			get_post_meta( $mec_post_id, 'mec_end_time_minutes', true ),
			get_post_meta( $mec_post_id, 'mec_end_time_ampm', true )
		);

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Build H:i from MEC's split hour / minute / meridiem values.
	 *
	 * @param mixed $hour   1-12.
	 * @param mixed $minute 0-59.
	 * @param mixed $ampm   AM|PM.
	 * @return string H:i or ''.
	 */
	private static function assemble_time( $hour, $minute, $ampm ) {
		$hour = (string) $hour;
		if ( '' === trim( $hour ) ) {
			return '';
		}

		$h = (int) $hour;
		$m = (int) $minute;
		$a = strtoupper( trim( (string) $ampm ) );

		if ( $h < 0 || $h > 23 || $m < 0 || $m > 59 ) {
			return '';
		}

		if ( 'PM' === $a && $h < 12 ) {
			$h += 12;
		} elseif ( 'AM' === $a && 12 === $h ) {
			$h = 0;
		}

		return sprintf( '%02d:%02d', $h, $m );
	}

	/* --------------------------------------------------------------------- */
	/* Import                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Run the import.
	 *
	 * @param array $opts {
	 *     @type bool $dry_run         Report without writing.
	 *     @type bool $update_existing Update events previously imported.
	 *     @type int  $limit           0 for all.
	 * }
	 * @return array Report.
	 */
	public static function import( array $opts = array() ) {
		global $wpdb;

		$opts = wp_parse_args(
			$opts,
			array(
				'dry_run'                  => true,
				'update_existing'          => true,
				'limit'                    => 0,
				'allow_without_recurrence' => false,
				'skip_before'              => '',
				'offset'                   => 0,
			)
		);

		$map                = self::detect();
		$map['skip_before'] = abm_sanitize_date( $opts['skip_before'] );
		$report = array(
			'usable'       => $map['usable'],
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'events'       => 0,
			'occurrences'  => 0,
			'no_dates'     => 0,
			'notes'        => array(),
			'top'          => array(),
			'aborted'      => false,
			'dry_run'      => (bool) $opts['dry_run'],
			'offset'       => (int) $opts['offset'],
			'next_offset'  => (int) $opts['offset'],
			'done'         => true,
			'total_events' => 0,
		);

		if ( ! $map['usable'] ) {
			$report['notes'][] = __( 'MEC occurrence table not found or unrecognized. Without it every repeating event imports as a single date, which is how a calendar quietly loses most of its listings.', 'arkon-bar-manager' );
			$report['notes'][] = __( 'Copy the schema diagnostic below when reporting this so the column mapping can be corrected.', 'arkon-bar-manager' );

			// Refuse to write. Degrading to one-date-per-event looks like a
			// successful import in the numbers and is only discovered later, on
			// the live calendar, by which point the old data may be gone.
			if ( ! $opts['dry_run'] && ! $opts['allow_without_recurrence'] ) {
				$report['aborted'] = true;
				$report['notes'][] = __( 'Import stopped before writing anything. Tick "import anyway without recurrence" only if you accept that repeating events will arrive with one date each.', 'arkon-bar-manager' );
				return $report;
			}
		}

		// Total is reported separately so a batched run can show real progress.
		$report['total_events'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				self::MEC_POST_TYPE
			)
		);

		$args = array(
			'post_type'        => self::MEC_POST_TYPE,
			'post_status'      => 'publish',
			'numberposts'      => $opts['limit'] > 0 ? (int) $opts['limit'] : -1,
			'offset'           => max( 0, (int) $opts['offset'] ),
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
		);

		$mec_posts = get_posts( $args );
		$counts    = array();

		foreach ( $mec_posts as $mec ) {
			$report['events']++;

			$dates = $map['usable'] ? self::dates_for( $mec->ID, $map ) : array();
			$times = self::times_for( $mec->ID );

			if ( ! $dates ) {
				// Fall back to the event's own start date so the event still
				// lands, just without its recurrence.
				$fallback = self::normalize_date( get_post_meta( $mec->ID, 'mec_start_date', true ) );
				if ( '' !== $fallback ) {
					$dates = array( $fallback );
				}
			}

			if ( ! $dates ) {
				$report['no_dates']++;
				$report['skipped']++;
				continue;
			}

			$counts[ $mec->post_title ] = count( $dates );
			$report['occurrences']     += count( $dates );

			if ( $opts['dry_run'] ) {
				$existing = self::find_existing( $mec->ID );
				if ( $existing ) {
					$report['updated']++;
				} else {
					$report['created']++;
				}
				continue;
			}

			$result = self::upsert_event( $mec, $dates, $times, $opts );
			if ( is_wp_error( $result ) ) {
				$report['skipped']++;
				$report['notes'][] = $mec->post_title . ': ' . $result->get_error_message();
				continue;
			}
			$report[ $result ]++;
		}

		arsort( $counts );
		$report['top'] = array_slice( $counts, 0, 10, true );

		$report['next_offset'] = (int) $opts['offset'] + count( $mec_posts );
		$report['done']        = ( $report['next_offset'] >= $report['total_events'] ) || ! $mec_posts;

		if ( ! $opts['dry_run'] ) {
			// Occurrences were written explicitly; make sure nothing stale
			// survives from an earlier run of the rule-based generator.
			$report['occurrence_rows'] = ABM_Occurrences::count_all();
		}

		return $report;
	}

	/**
	 * Find an event already imported from a given MEC post.
	 *
	 * @param int $mec_post_id MEC post ID.
	 * @return int Post ID or 0.
	 */
	public static function find_existing( $mec_post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s
				 LIMIT 1",
				self::SOURCE_ID,
				(string) $mec_post_id,
				ABM_POST_TYPE
			)
		);
	}

	/**
	 * Create or update one event and write its occurrences.
	 *
	 * @param WP_Post $mec   Source MEC post.
	 * @param array   $dates Y-m-d list.
	 * @param array   $times start/end.
	 * @param array   $opts  Import options.
	 * @return string|WP_Error 'created'|'updated'|'skipped'
	 */
	private static function upsert_event( WP_Post $mec, array $dates, array $times, array $opts ) {
		$existing = self::find_existing( $mec->ID );

		if ( $existing && ! $opts['update_existing'] ) {
			return 'skipped';
		}

		$postarr = array(
			'post_type'    => ABM_POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => $mec->post_title,
			'post_content' => $mec->post_content,
			'post_excerpt' => $mec->post_excerpt,
			'post_date'    => $mec->post_date,
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			// Reuse the MEC slug so the legacy redirect is an exact match and the
			// new permalink stays recognisable to anyone who knew the old one.
			$postarr['post_name'] = $mec->post_name;
			$post_id              = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Featured image: same install, so point at the same attachment rather
		// than downloading a second copy.
		$thumb = (int) get_post_thumbnail_id( $mec->ID );
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
		}

		// Categories, matched by name so "Music" and "Event" land on the
		// equivalents seeded by this plugin.
		$terms = get_the_terms( $mec->ID, self::MEC_TAXONOMY );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$target = array();
			foreach ( $terms as $t ) {
				$found = term_exists( $t->name, ABM_TAXONOMY );
				if ( ! $found ) {
					$found = wp_insert_term( $t->name, ABM_TAXONOMY );
				}
				if ( ! is_wp_error( $found ) && isset( $found['term_id'] ) ) {
					$target[] = (int) $found['term_id'];
				}
			}
			if ( $target ) {
				wp_set_object_terms( $post_id, $target, ABM_TAXONOMY, false );
			}
		}

		$first = $dates[0];
		$cost  = (string) get_post_meta( $mec->ID, 'mec_cost', true );

		update_post_meta( $post_id, 'abm_event_date', $first );
		update_post_meta( $post_id, 'abm_event_time_start', $times['start'] );
		update_post_meta( $post_id, 'abm_event_time_end', $times['end'] );
		update_post_meta( $post_id, 'abm_event_cost', $cost );

		// Clamp exports only where MEC had no real end time to give (hidden or
		// blank). Where a genuine end was set, honour it, so an 8 PM to 1 AM show
		// exports as running to 1 AM instead of being cut off at midnight.
		update_post_meta( $post_id, 'abm_display_start_only', ( '' === $times['end'] ) ? 1 : 0 );

		// Occurrences are copied verbatim from MEC, so no recurrence rule is
		// stored: the imported dates are the source of truth. Editing the event
		// later and choosing a rule replaces them.
		update_post_meta( $post_id, 'abm_recur_type', '' );

		update_post_meta( $post_id, self::SOURCE_KEY, 'mec-db' );
		update_post_meta( $post_id, self::SOURCE_ID, (string) $mec->ID );
		update_post_meta( $post_id, ABM_Legacy_URLs::SLUG_META, $mec->post_name );

		// Derived display strings + calendar links.
		if ( class_exists( 'ABM_Meta' ) ) {
			ABM_Meta::instance()->sync_derived( $post_id, get_post( $post_id ) );
		}

		$rows = array();
		foreach ( $dates as $d ) {
			$rows[] = array(
				'date'  => $d,
				'start' => $times['start'],
				'end'   => $times['end'],
			);
		}
		ABM_Occurrences::set_explicit( $post_id, $rows );

		return $existing ? 'updated' : 'created';
	}
}
