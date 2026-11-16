<?php
/**
 * The operations an agent can perform on the calendar.
 *
 * Every operation follows the same path. Read the event as it is. Work out
 * what it would be once the change was made, including the exact dates it
 * would have, without writing anything. Ask ABM_Change_Classifier what should
 * happen. Then either apply it, hold it for a person, or refuse it, and say
 * which.
 *
 * Nothing here is specific to REST. The REST controller, the approval screen
 * and the tests all call run().
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Agent_Ops {

	/** Removed occurrence rows of a verbatim event, kept so a skip can be undone. */
	const SKIPPED_META = 'abm_agent_skipped_rows';

	/** Most dates compared against the rest of the calendar for one change. */
	const MAX_COMPARE_DATES = 400;

	const RECUR_TYPES = array( 'daily', 'weekly', 'monthly_date', 'monthly_day' );

	const OPS = array( 'create', 'update', 'set_status', 'trash', 'restore', 'skip', 'unskip', 'category_create', 'category_rename', 'category_delete' );

	/* --------------------------------------------------------------------- */
	/* Reading                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * One event, as an agent should see it: stored values, not display strings,
	 * and the dates the calendar will actually render.
	 *
	 * @param int $id Event ID.
	 * @return array|WP_Error
	 */
	public static function event( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ABM_POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'abm_not_found', __( 'No event with that ID.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
		}

		$id    = (int) $post->ID;
		$cost  = (string) get_post_meta( $id, 'abm_event_cost', true );
		$thumb = self::own_flyer_id( $id );
		$rule  = ABM_Occurrences::get_rule( $id );
		$stats = ABM_Occurrences::stats_for( $id );
		$terms = wp_get_object_terms( $id, ABM_TAXONOMY, array( 'fields' => 'ids' ) );

		$skipped = $rule['exceptions'];
		foreach ( self::skipped_rows( $id ) as $row ) {
			$skipped[] = $row['date'];
		}
		$skipped = array_values( array_unique( $skipped ) );
		sort( $skipped );

		if ( '' === $cost ) {
			$cover = array( 'mode' => '', 'value' => '' );
		} elseif ( abm_cost_is_none( $cost ) ) {
			$cover = array( 'mode' => 'none', 'value' => '' );
		} else {
			$cover = array( 'mode' => 'amount', 'value' => $cost );
		}

		return array(
			'id'                 => $id,
			'status'             => (string) $post->post_status,
			'title'              => (string) $post->post_title,
			'description'        => (string) $post->post_content,
			'excerpt'            => (string) $post->post_excerpt,
			'link'               => (string) get_permalink( $id ),
			'publish_at'         => 'future' === $post->post_status ? substr( (string) $post->post_date, 0, 16 ) : '',
			'date'               => (string) get_post_meta( $id, 'abm_event_date', true ),
			'start_time'         => (string) get_post_meta( $id, 'abm_event_time_start', true ),
			'end_time'           => (string) get_post_meta( $id, 'abm_event_time_end', true ),
			'cover'              => $cover,
			'categories'         => is_wp_error( $terms ) ? array() : array_map( 'intval', $terms ),
			'show_category'      => (string) get_post_meta( $id, 'abm_show_category', true ),
			'display_start_only' => (bool) get_post_meta( $id, 'abm_display_start_only', true ),
			'flyer'              => array(
				'id'  => $thumb,
				'url' => $thumb ? (string) wp_get_attachment_url( $thumb ) : '',
			),
			'rule'               => $rule,
			'skipped'            => $skipped,
			'occurrences'        => array(
				'count'  => $stats['count'],
				'next'   => $stats['next'],
				'locked' => $stats['locked'],
				'dates'  => ABM_Occurrences::upcoming_dates( $id ),
			),
			'fingerprint'        => self::fingerprint( $id ),
		);
	}

	/**
	 * A hash of everything about an event that a reviewer would have looked at.
	 *
	 * A held change records this when it is requested. If the event is edited
	 * before a person decides, the hashes differ and the change is not applied:
	 * approval was given for a different event than the one now stored.
	 *
	 * @param int $id Event ID.
	 * @return string 64 hex characters, or '' if there is no such event.
	 */
	public static function fingerprint( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ABM_POST_TYPE !== $post->post_type ) {
			return '';
		}

		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( (string) $key, 'abm_' ) || '_thumbnail_id' === $key ) {
				$meta[ $key ] = $values;
			}
		}
		ksort( $meta, SORT_STRING );

		$terms = wp_get_object_terms( $post->ID, ABM_TAXONOMY, array( 'fields' => 'ids' ) );
		$terms = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
		sort( $terms, SORT_NUMERIC );

		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'id'          => (int) $post->ID,
					'title'       => $post->post_title,
					'content'     => $post->post_content,
					'excerpt'     => $post->post_excerpt,
					'status'      => $post->post_status,
					'date'        => $post->post_date,
					'meta'        => $meta,
					'categories'  => $terms,
					'occurrences' => ABM_Occurrences::rows_for( $post->ID ),
				)
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Running an operation                                                  */
	/* --------------------------------------------------------------------- */

	/**
	 * Perform, hold or refuse one operation.
	 *
	 * @param string $op   One of OPS.
	 * @param array  $p    Parameters for that operation.
	 * @param array  $opts dry_run: describe the outcome and write nothing.
	 *                     approved: a person has approved this; apply it even though the rule would hold it.
	 * @return array|WP_Error {outcome,reasons,removed,added,duplicate_of,event,change_id,approve_url,details}
	 */
	public static function run( $op, array $p, array $opts = array() ) {
		// The duplicate check and the insert are two steps. A create that is
		// retried while the first attempt is still running would pass the check
		// twice, so creates of the same show on the same night take turns.
		$lock = '';
		if ( 'create' === $op && empty( $opts['dry_run'] ) ) {
			$lock = 'abm_agent_lock_' . md5( ABM_Change_Classifier::normalize_title( (string) ( $p['title'] ?? '' ) ) . '|' . (string) ( $p['date'] ?? '' ) );
			if ( ! self::take_lock( $lock ) ) {
				return new WP_Error( 'abm_busy', __( 'This event is already being created. Check for it before trying again.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
			}
		}

		try {
			return self::execute( $op, $p, $opts );
		} finally {
			if ( '' !== $lock ) {
				self::drop_lock( $lock );
			}
		}
	}

	/**
	 * Take a short-lived lock, or report that someone else holds it.
	 *
	 * Written straight to the options table rather than through add_option().
	 * The options API answers from the object cache first, and a persistent
	 * cache that has fallen out of step with the database (found on staging
	 * after an unclean restart) reported a lock that no longer existed, which
	 * refused every create of that show. The insert is also the test: the
	 * table's unique key lets exactly one request in.
	 *
	 * @param string $name Lock name.
	 * @return bool
	 */
	private static function take_lock( $name ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
			if ( $got ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null === $held || time() - (int) $held < 60 ) {
				return false;
			}

			// Left behind by a request that died. Remove that exact lock, so two
			// requests clearing it cannot remove each other's fresh one.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $held ) );
		}

		return false;
	}

	/**
	 * @param string $name Lock name.
	 */
	private static function drop_lock( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	/**
	 * @param string $op   Operation.
	 * @param array  $p    Parameters.
	 * @param array  $opts Options, as for run().
	 * @return array|WP_Error
	 */
	private static function execute( $op, array $p, array $opts ) {
		if ( ! in_array( $op, self::OPS, true ) ) {
			return new WP_Error( 'abm_unknown_op', __( 'Unknown operation.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}

		$dry      = ! empty( $opts['dry_run'] );
		$approved = ! empty( $opts['approved'] );

		$plan = 0 === strpos( $op, 'category_' ) ? self::plan_category( $op, $p ) : self::plan_event( $op, $p );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$verdict = isset( $plan['verdict'] ) ? $plan['verdict'] : ABM_Change_Classifier::classify( $plan['before'], $plan['after'], $plan['ctx'] );

		$result = array(
			'outcome'      => $verdict['outcome'],
			'reasons'      => $verdict['reasons'],
			'removed'      => $verdict['removed'],
			'added'        => $verdict['added'],
			'duplicate_of' => $verdict['duplicate_of'],
			'event'        => null,
			'change_id'    => 0,
			'approve_url'  => '',
			'details'      => array(),
		);

		$post_id = (int) ( $plan['post_id'] ?? 0 );
		$today   = current_time( 'Y-m-d' );
		$gone    = count(
			array_filter(
				$verdict['removed'],
				static function ( $d ) use ( $today ) {
					return $d >= $today;
				}
			)
		);

		$summary = array(
			'text'    => (string) $plan['text'],
			'reasons' => $verdict['reasons'],
			'removed' => $verdict['removed'],
			'added'   => $verdict['added'],
		);

		if ( $dry ) {
			$result['event']              = $post_id ? self::event_or_null( $post_id ) : null;
			$result['details']['dry_run'] = true;
			return $result;
		}

		if ( ABM_Change_Classifier::REFUSED === $verdict['outcome'] ) {
			$result['event'] = $post_id ? self::event_or_null( $post_id ) : null;
			self::log( $op, $post_id, 'refused', $summary );
			return $result;
		}

		if ( ABM_Change_Classifier::APPROVAL === $verdict['outcome'] && ! $approved ) {
			$change_id = ABM_Agent_Store::add_change(
				array(
					'op'          => $op,
					'post_id'     => $post_id,
					'params'      => $p,
					'fingerprint' => $post_id ? self::fingerprint( $post_id ) : '',
					'summary'     => $summary,
					'user_id'     => get_current_user_id(),
				)
			);
			if ( ! $change_id ) {
				return new WP_Error( 'abm_store_failed', __( 'The change needs approval but could not be saved for review. Nothing was changed.', 'arkon-bar-manager' ), array( 'status' => 500 ) );
			}
			$result['change_id']   = $change_id;
			$result['approve_url'] = admin_url( 'admin.php?page=abm-agent-changes' );
			$result['event']       = $post_id ? self::event_or_null( $post_id ) : null;
			self::log( $op, $post_id, 'approval_required', $summary, $change_id );
			return $result;
		}

		$applied = call_user_func( $plan['apply'] );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$post_id            = (int) ( $applied['post_id'] ?? $post_id );
		$result['outcome']  = ABM_Change_Classifier::APPLIED;
		$result['details']  = (array) ( $applied['details'] ?? array() );
		$result['event']    = $post_id ? self::event_or_null( $post_id ) : null;
		$summary['details'] = $result['details'];

		self::log( $op, $post_id, 'applied', $summary, (int) ( $opts['change_id'] ?? 0 ), $gone );

		return $result;
	}

	/**
	 * Apply a held change a person has approved.
	 *
	 * @param int $change_id Pending change ID.
	 * @param int $user_id   Approver.
	 * @return array|WP_Error {status:approved|stale, result?:array, reason?:string}
	 */
	public static function approve( $change_id, $user_id ) {
		$change = ABM_Agent_Store::get_change( $change_id );
		if ( ! $change ) {
			return new WP_Error( 'abm_not_found', __( 'No such pending change.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $change['status'] ) {
			/* translators: %s: status such as approved or expired. */
			return new WP_Error( 'abm_not_pending', sprintf( __( 'This change is already %s.', 'arkon-bar-manager' ), $change['status'] ), array( 'status' => 409 ) );
		}

		// Claim it before doing anything. Two approvals arriving together, or an
		// approval racing a rejection, must not both act: only the request whose
		// UPDATE moved the row out of "pending" carries on.
		if ( ! ABM_Agent_Store::set_change_status( $change_id, 'approving', $user_id ) ) {
			return new WP_Error( 'abm_not_pending', __( 'This change is already being decided.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
		}

		// Approval was given for the event as it was when the change was asked
		// for. If it has been edited since, that is a different event.
		if ( $change['post_id'] && '' !== $change['fingerprint'] && ! hash_equals( $change['fingerprint'], self::fingerprint( $change['post_id'] ) ) ) {
			ABM_Agent_Store::set_change_status( $change_id, 'stale', $user_id, 'approving' );
			self::log( $change['op'], $change['post_id'], 'stale', $change['summary'], $change_id );
			return array(
				'status' => 'stale',
				'reason' => __( 'The event changed after this was requested, so nothing was applied.', 'arkon-bar-manager' ),
			);
		}

		$result = self::run(
			$change['op'],
			$change['params'],
			array(
				'approved'  => true,
				'change_id' => $change_id,
			)
		);
		if ( is_wp_error( $result ) ) {
			// Nothing was applied, so hand the change back for another try.
			ABM_Agent_Store::set_change_status( $change_id, 'pending', 0, 'approving' );
			return $result;
		}

		if ( ABM_Change_Classifier::APPLIED !== $result['outcome'] ) {
			ABM_Agent_Store::set_change_status( $change_id, 'stale', $user_id, 'approving' );
			return array(
				'status' => 'stale',
				'reason' => implode( ' ', $result['reasons'] ),
				'result' => $result,
			);
		}

		ABM_Agent_Store::set_change_status( $change_id, 'approved', $user_id, 'approving' );

		return array(
			'status' => 'approved',
			'result' => $result,
		);
	}

	/**
	 * Turn a held change down. Nothing is applied.
	 *
	 * @param int $change_id Pending change ID.
	 * @param int $user_id   Who decided.
	 * @return bool Whether it was pending.
	 */
	public static function reject( $change_id, $user_id ) {
		$change = ABM_Agent_Store::get_change( $change_id );
		if ( ! $change || ! ABM_Agent_Store::set_change_status( $change_id, 'rejected', $user_id ) ) {
			return false;
		}
		self::log( $change['op'], $change['post_id'], 'rejected', $change['summary'], $change_id );
		return true;
	}

	/* --------------------------------------------------------------------- */
	/* Planning: events                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Work out before, after and how to apply, for an event operation.
	 *
	 * @param string $op Operation.
	 * @param array  $p  Parameters.
	 * @return array|WP_Error
	 */
	private static function plan_event( $op, array $p ) {
		$type      = get_post_type_object( ABM_POST_TYPE );
		$creating  = 'create' === $op;
		$post      = null;
		$id        = 0;
		$today     = current_time( 'Y-m-d' );

		// These operations act on anyone's events and read the whole calendar to
		// do it, so they need the capability to edit events that are not yours.
		if ( ! $type || ! current_user_can( $type->cap->edit_others_posts ) ) {
			return self::forbidden();
		}

		if ( $creating ) {
			if ( ! current_user_can( $type->cap->create_posts ) ) {
				return self::forbidden();
			}
		} else {
			$id   = (int) ( $p['id'] ?? 0 );
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || ABM_POST_TYPE !== $post->post_type || 'auto-draft' === $post->post_status ) {
				return new WP_Error( 'abm_not_found', __( 'No event with that ID.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
			}
			$cap = 'trash' === $op ? 'delete_post' : 'edit_post';
			if ( ! current_user_can( $cap, $id ) ) {
				return self::forbidden();
			}
		}

		// Current values.
		$cur = array(
			'title'  => $post ? (string) $post->post_title : '',
			'status' => $post ? (string) $post->post_status : '',
			'date'   => $id ? (string) get_post_meta( $id, 'abm_event_date', true ) : '',
			'start'  => $id ? (string) get_post_meta( $id, 'abm_event_time_start', true ) : '',
			'end'    => $id ? (string) get_post_meta( $id, 'abm_event_time_end', true ) : '',
			'rule'   => $id ? ABM_Occurrences::get_rule( $id ) : self::empty_rule(),
		);
		$rows   = $id ? ABM_Occurrences::rows_for( $id ) : array();
		$dates  = array_column( $rows, 'date' );
		$locked = $id ? ABM_Occurrences::is_protected_explicit( $id, count( $rows ) ) : false;

		$before = array(
			'exists' => (bool) $post,
			'status' => $cur['status'],
			'title'  => $cur['title'],
			'dates'  => $dates,
		);
		$after  = $before;
		$ctx    = array(
			'op'                     => $op,
			'today'                  => $today,
			'recent_removed'         => 0,
			'locked'                 => $locked,
			'series'                 => $locked || '' !== $cur['rule']['type'],
			'dates_meta_changed'     => false,
			'rule_changed'           => false,
			'time_changed'           => false,
			'upcoming_times_uniform' => true,
			'others'                 => array(),
		);

		$label = $cur['title'];
		$text  = '';
		$apply = null;

		switch ( $op ) {

			case 'create':
			case 'update':
				// Status is its own operation with its own rule. Ignoring it here
				// would report success for a publish that never happened.
				if ( ! $creating && array_key_exists( 'status', $p ) ) {
					return self::invalid( 'status', __( 'Change an existing event\'s status with the status operation, not an update.', 'arkon-bar-manager' ) );
				}
				$in = self::read_fields( $p, $creating );
				if ( is_wp_error( $in ) ) {
					return $in;
				}

				$status     = $cur['status'];
				$publish_at = '';
				if ( $creating ) {
					$wanted = self::read_status( $p, 'draft' );
					if ( is_wp_error( $wanted ) ) {
						return $wanted;
					}
					list( $status, $publish_at ) = $wanted;
					if ( 'draft' !== $status && ! current_user_can( $type->cap->publish_posts ) ) {
						return self::forbidden();
					}
				}

				$new_date = array_key_exists( 'date', $in ) ? $in['date'] : $cur['date'];
				$new_rule = array_key_exists( 'rule', $in ) ? $in['rule'] : $cur['rule'];

				// For an event that holds its dates verbatim, "the current time" is
				// what its upcoming nights say, not what the time fields say: the
				// admin can change the fields without the nights following, so the
				// two can disagree. Comparing against the fields would call a real
				// change no change, and filling an unsent field from them would
				// overwrite the nights with a value nobody asked for.
				$row_times = null;
				if ( $locked ) {
					foreach ( $rows as $row ) {
						if ( $row['date'] >= $today ) {
							$row_times = array( $row['start'], $row['end'] );
							break;
						}
					}
				}
				$now_start = $row_times ? $row_times[0] : $cur['start'];
				$now_end   = $row_times ? $row_times[1] : $cur['end'];
				$times     = array(
					array_key_exists( 'start', $in ) ? $in['start'] : $now_start,
					array_key_exists( 'end', $in ) ? $in['end'] : $now_end,
				);

				$ctx['dates_meta_changed'] = $new_date !== $cur['date'];
				$ctx['rule_changed']       = $new_rule != $cur['rule']; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- arrays of scalars, order-normalized.
				$ctx['time_changed']       = ( array_key_exists( 'start', $in ) || array_key_exists( 'end', $in ) ) && ( $times[0] !== $now_start || $times[1] !== $now_end || ( $locked && ! self::upcoming_uniform( $rows, $today ) ) );
				$ctx['recent_removed']     = $id ? ABM_Agent_Store::recent_removed( $id ) : 0;

				// Giving a locked event a rule replaces its held dates; clearing a
				// rule it does not have changes nothing.
				$takes_rule = $ctx['rule_changed'] && '' !== $new_rule['type'];

				if ( $locked && ! $takes_rule ) {
					$after_dates         = $dates;
					$ctx['rule_changed'] = false;
				} elseif ( $creating || $ctx['dates_meta_changed'] || $ctx['rule_changed'] ) {
					$after_dates = ABM_Occurrences::expand( $new_date, $new_rule );
				} else {
					$after_dates = $dates;
				}

				if ( $locked && $ctx['time_changed'] ) {
					$ctx['upcoming_times_uniform'] = self::upcoming_uniform( $rows, $today );
				}

				$after = array(
					'exists' => true,
					'status' => $status,
					'title'  => array_key_exists( 'title', $in ) ? $in['title'] : $cur['title'],
					'dates'  => $after_dates,
				);
				$label = $after['title'];
				$text  = $creating
					/* translators: 1: event title, 2: status. */
					? sprintf( __( 'Create "%1$s" as %2$s', 'arkon-bar-manager' ), $label, $status )
					/* translators: 1: event title, 2: comma-separated field names. */
					: sprintf( __( 'Change "%1$s": %2$s', 'arkon-bar-manager' ), $label, implode( ', ', array_keys( $in ) ) );

				$row_write = ( $locked && ! $takes_rule && $ctx['time_changed'] ) ? $times : null;
				$apply     = static function () use ( $id, $in, $status, $publish_at, $creating, $locked, $takes_rule, $row_write ) {
					return self::apply_fields( $id, $in, $status, $publish_at, $creating, $locked && ! $takes_rule, $row_write );
				};
				break;

			case 'set_status':
				$wanted = self::read_status( $p, '' );
				if ( is_wp_error( $wanted ) ) {
					return $wanted;
				}
				list( $status, $publish_at ) = $wanted;
				if ( 'trash' === $cur['status'] ) {
					return new WP_Error( 'abm_in_trash', __( 'This event is in the trash. Restore it first.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
				}
				if ( 'draft' !== $status && ! current_user_can( $type->cap->publish_posts ) ) {
					return self::forbidden();
				}
				if ( 'draft' !== $status && '' === abm_sanitize_date( $cur['date'] ) ) {
					return new WP_Error( 'abm_incomplete', __( 'This event has no date, so it cannot be published.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
				}
				$after['status'] = $status;
				/* translators: 1: event title, 2: status. */
				$text  = sprintf( __( 'Set "%1$s" to %2$s', 'arkon-bar-manager' ), $label, $status . ( $publish_at ? ' at ' . $publish_at : '' ) );
				$apply = static function () use ( $id, $status, $publish_at ) {
					return self::apply_status( $id, $status, $publish_at );
				};
				break;

			case 'trash':
				if ( 'trash' === $cur['status'] ) {
					return new WP_Error( 'abm_in_trash', __( 'This event is already in the trash.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
				}
				$after['status'] = 'trash';
				/* translators: %s: event title. */
				$text  = sprintf( __( 'Move "%s" to the trash', 'arkon-bar-manager' ), $label );
				$apply = static function () use ( $id ) {
					if ( ! wp_trash_post( $id ) ) {
						return new WP_Error( 'abm_trash_failed', __( 'The event could not be moved to the trash.', 'arkon-bar-manager' ), array( 'status' => 500 ) );
					}
					return array( 'post_id' => $id );
				};
				break;

			case 'restore':
				if ( 'trash' !== $cur['status'] ) {
					return new WP_Error( 'abm_not_in_trash', __( 'This event is not in the trash.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
				}
				$previous        = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
				$after['status'] = '' !== $previous ? $previous : 'draft';
				/* translators: %s: event title. */
				$text  = sprintf( __( 'Restore "%s" from the trash', 'arkon-bar-manager' ), $label );
				$apply = static function () use ( $id ) {
					add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
					$ok = wp_untrash_post( $id );
					remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
					if ( ! $ok ) {
						return new WP_Error( 'abm_restore_failed', __( 'The event could not be restored.', 'arkon-bar-manager' ), array( 'status' => 500 ) );
					}
					ABM_Occurrences::generate_for_post( $id );
					return array( 'post_id' => $id );
				};
				break;

			case 'skip':
			case 'unskip':
				$date = abm_sanitize_date( (string) ( $p['date'] ?? '' ) );
				if ( '' === $date ) {
					return new WP_Error( 'abm_invalid_date', __( 'date must be YYYY-MM-DD.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
				}
				$ctx['date'] = $date;
				$rule_driven = '' !== $cur['rule']['type'];

				if ( ! $rule_driven && ! $locked ) {
					return self::refusal( $id, $label, __( 'This event runs on a single date, so there is no series to skip a night of. Move it or trash it instead.', 'arkon-bar-manager' ) );
				}

				// A verbatim event is only protected from being regenerated while it
				// has more than one date. Skipped down to one, the next save would
				// rebuild it from its date field: the skipped night would come back
				// and the real one would go.
				if ( 'skip' === $op && ! $rule_driven && in_array( $date, $dates, true ) && count( $dates ) <= 2 ) {
					return self::refusal( $id, $label, __( 'This event has only two dates left, and it holds them exactly as imported. Skipping one would leave a single date that the next save could replace. Trash the event, or give it a repeat rule, instead.', 'arkon-bar-manager' ) );
				}

				if ( 'skip' === $op ) {
					$after['dates']        = array_values( array_diff( $dates, array( $date ) ) );
					$ctx['recent_removed'] = ABM_Agent_Store::recent_removed( $id );
					/* translators: 1: event title, 2: date. */
					$text  = sprintf( __( 'Skip "%1$s" on %2$s', 'arkon-bar-manager' ), $label, $date );
					$apply = static function () use ( $id, $date, $rule_driven ) {
						return self::apply_skip( $id, $date, $rule_driven );
					};
				} else {
					$was_skipped = $rule_driven
						? in_array( $date, $cur['rule']['exceptions'], true )
						: in_array( $date, array_column( self::skipped_rows( $id ), 'date' ), true );
					if ( ! $was_skipped ) {
						return self::refusal( $id, $label, __( 'That date was not skipped, so there is nothing to put back.', 'arkon-bar-manager' ) );
					}
					$after['dates'] = array_values( array_unique( array_merge( $dates, array( $date ) ) ) );
					/* translators: 1: event title, 2: date. */
					$text  = sprintf( __( 'Put "%1$s" back on %2$s', 'arkon-bar-manager' ), $label, $date );
					$apply = static function () use ( $id, $date, $rule_driven ) {
						return self::apply_unskip( $id, $date, $rule_driven );
					};
				}
				break;
		}

		// What else is on the nights this change touches.
		$compare = array_values( array_unique( array_merge( array_diff( $after['dates'], $before['dates'] ), $after['dates'] ) ) );
		usort(
			$compare,
			static function ( $a, $b ) use ( $today ) {
				// Upcoming nights first: those are the ones that can collide in public.
				return array( $a < $today, $a ) <=> array( $b < $today, $b );
			}
		);
		$ctx['others'] = self::others_on( array_slice( $compare, 0, self::MAX_COMPARE_DATES ), $id );

		return array(
			'post_id' => $id,
			'before'  => $before,
			'after'   => $after,
			'ctx'     => $ctx,
			'text'    => $text,
			'apply'   => $apply,
		);
	}

	/**
	 * Validate the editable fields of an event.
	 *
	 * Only keys that were sent come back, so an update changes exactly what it
	 * names. Anything unreadable is an error, never a silent blank: an event
	 * whose date fails to parse would otherwise lose every date it has.
	 *
	 * @param array $p        Parameters.
	 * @param bool  $creating Whether title and date are required.
	 * @return array|WP_Error Keys: title, content, excerpt, date, start, end, cost, categories, show_category, display_start_only, media_id, rule.
	 */
	private static function read_fields( array $p, $creating ) {
		$out = array();

		if ( array_key_exists( 'title', $p ) ) {
			$title = sanitize_text_field( (string) $p['title'] );
			if ( '' === $title || strlen( $title ) > 200 ) {
				return self::invalid( 'title', __( 'title must be 1 to 200 characters.', 'arkon-bar-manager' ) );
			}
			$out['title'] = $title;
		} elseif ( $creating ) {
			return self::invalid( 'title', __( 'title is required.', 'arkon-bar-manager' ) );
		}

		if ( array_key_exists( 'date', $p ) ) {
			$date = abm_sanitize_date( (string) $p['date'] );
			if ( '' === $date ) {
				return self::invalid( 'date', __( 'date must be a real date written YYYY-MM-DD.', 'arkon-bar-manager' ) );
			}
			$out['date'] = $date;
		} elseif ( $creating ) {
			return self::invalid( 'date', __( 'date is required.', 'arkon-bar-manager' ) );
		}

		if ( array_key_exists( 'start_time', $p ) ) {
			$raw   = trim( (string) $p['start_time'] );
			$start = ABM_Meta::sanitize_start_time( $raw );
			if ( '' !== $raw && '' === $start ) {
				return self::invalid( 'start_time', __( 'start_time must be 24-hour HH:MM.', 'arkon-bar-manager' ) );
			}
			$out['start'] = $start;
		}

		if ( array_key_exists( 'end_time', $p ) ) {
			$raw = trim( (string) $p['end_time'] );
			$end = abm_sanitize_time( $raw );
			if ( '' !== $raw && '' === $end ) {
				return self::invalid( 'end_time', __( 'end_time must be 24-hour HH:MM, "close", or empty.', 'arkon-bar-manager' ) );
			}
			$out['end'] = $end;
		}

		if ( array_key_exists( 'description', $p ) ) {
			$out['content'] = wp_kses_post( (string) $p['description'] );
		}
		if ( array_key_exists( 'excerpt', $p ) ) {
			$out['excerpt'] = sanitize_textarea_field( (string) $p['excerpt'] );
		}

		if ( array_key_exists( 'cover', $p ) ) {
			$cover = is_array( $p['cover'] ) ? $p['cover'] : array();
			$mode  = (string) ( $cover['mode'] ?? '' );
			if ( ! in_array( $mode, array( '', 'none', 'amount' ), true ) ) {
				return self::invalid( 'cover', __( 'cover.mode must be "" (not specified), "none" (no cover) or "amount".', 'arkon-bar-manager' ) );
			}
			if ( 'none' === $mode ) {
				$out['cost'] = ABM_COST_NONE;
			} elseif ( 'amount' === $mode ) {
				$value = abm_normalize_cost( (string) ( $cover['value'] ?? '' ) );
				if ( '' === $value ) {
					return self::invalid( 'cover', __( 'cover.value is required when cover.mode is "amount".', 'arkon-bar-manager' ) );
				}
				$out['cost'] = $value;
			} else {
				$out['cost'] = '';
			}
		}

		if ( array_key_exists( 'categories', $p ) ) {
			$ids = array_values( array_unique( array_map( 'absint', (array) $p['categories'] ) ) );
			foreach ( $ids as $term_id ) {
				if ( ! $term_id || ! term_exists( $term_id, ABM_TAXONOMY ) ) {
					return self::invalid( 'categories', __( 'categories must be IDs of existing event categories.', 'arkon-bar-manager' ) );
				}
			}
			$out['categories'] = $ids;
		}

		if ( array_key_exists( 'show_category', $p ) ) {
			$show = (string) $p['show_category'];
			if ( ! in_array( $show, array( '', 'show', 'hide' ), true ) ) {
				return self::invalid( 'show_category', __( 'show_category must be "", "show" or "hide".', 'arkon-bar-manager' ) );
			}
			$out['show_category'] = $show;
		}

		if ( array_key_exists( 'display_start_only', $p ) ) {
			$out['display_start_only'] = rest_sanitize_boolean( $p['display_start_only'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'media_id', $p ) ) {
			$media = absint( $p['media_id'] );
			if ( $media && ! wp_attachment_is_image( $media ) ) {
				return self::invalid( 'media_id', __( 'media_id must be an image in the Media Library, or 0 to remove the flyer.', 'arkon-bar-manager' ) );
			}
			$out['media_id'] = $media;
		}

		if ( array_key_exists( 'rule', $p ) ) {
			$rule = self::read_rule( $p['rule'] );
			if ( is_wp_error( $rule ) ) {
				return $rule;
			}
			$out['rule'] = $rule;
		}

		if ( ! $creating && ! $out ) {
			return new WP_Error( 'abm_no_changes', __( 'No fields to change were sent.', 'arkon-bar-manager' ), array( 'status' => 400 ) );
		}

		return $out;
	}

	/**
	 * @param mixed $raw Rule object, or null/'' for "does not repeat".
	 * @return array|WP_Error Rule in get_rule() shape.
	 */
	private static function read_rule( $raw ) {
		$rule = self::empty_rule();
		if ( null === $raw || '' === $raw || array() === $raw ) {
			return $rule;
		}
		if ( ! is_array( $raw ) ) {
			return self::invalid( 'rule', __( 'rule must be an object, or null for an event that does not repeat.', 'arkon-bar-manager' ) );
		}

		$type = (string) ( $raw['type'] ?? '' );
		if ( '' !== $type && ! in_array( $type, self::RECUR_TYPES, true ) ) {
			return self::invalid( 'rule', __( 'rule.type must be daily, weekly, monthly_date, monthly_day, or empty.', 'arkon-bar-manager' ) );
		}
		$rule['type'] = $type;
		if ( '' === $type ) {
			return $rule;
		}

		$interval = (int) ( $raw['interval'] ?? 1 );
		if ( $interval < 1 || $interval > 52 ) {
			return self::invalid( 'rule', __( 'rule.interval must be between 1 and 52.', 'arkon-bar-manager' ) );
		}
		$rule['interval'] = $interval;

		$weekdays = array();
		foreach ( (array) ( $raw['weekdays'] ?? array() ) as $day ) {
			if ( ! is_numeric( $day ) || (int) $day < 0 || (int) $day > 6 ) {
				return self::invalid( 'rule', __( 'rule.weekdays must be numbers 0 (Sunday) to 6 (Saturday).', 'arkon-bar-manager' ) );
			}
			$weekdays[] = (int) $day;
		}
		$weekdays = array_values( array_unique( $weekdays ) );
		sort( $weekdays );
		$rule['weekdays'] = 'weekly' === $type ? $weekdays : array();

		$until = (string) ( $raw['until'] ?? '' );
		if ( '' !== $until && '' === abm_sanitize_date( $until ) ) {
			return self::invalid( 'rule', __( 'rule.until must be YYYY-MM-DD or empty.', 'arkon-bar-manager' ) );
		}
		$rule['until'] = abm_sanitize_date( $until );

		$count = (int) ( $raw['count'] ?? 0 );
		if ( $count < 0 || $count > 1000 ) {
			return self::invalid( 'rule', __( 'rule.count must be between 0 and 1000.', 'arkon-bar-manager' ) );
		}
		$rule['count'] = $count;

		$exceptions = array();
		foreach ( (array) ( $raw['exceptions'] ?? array() ) as $skip ) {
			$skip = abm_sanitize_date( (string) $skip );
			if ( '' === $skip ) {
				return self::invalid( 'rule', __( 'rule.exceptions must be dates written YYYY-MM-DD.', 'arkon-bar-manager' ) );
			}
			$exceptions[] = $skip;
		}
		$exceptions = array_values( array_unique( $exceptions ) );
		sort( $exceptions );
		$rule['exceptions'] = $exceptions;

		return $rule;
	}

	/**
	 * @param array  $p       Parameters.
	 * @param string $default Status when none is sent; '' makes it required.
	 * @return array|WP_Error [ status, publish_at as site-local "Y-m-d H:i:s" or '' ].
	 */
	private static function read_status( array $p, $default ) {
		$status = (string) ( $p['status'] ?? $default );
		if ( ! in_array( $status, array( 'draft', 'publish', 'future' ), true ) ) {
			return self::invalid( 'status', __( 'status must be draft, publish or future.', 'arkon-bar-manager' ) );
		}

		$publish_at = '';
		if ( 'future' === $status ) {
			$raw  = trim( (string) ( $p['publish_at'] ?? '' ) );
			$when = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', substr( str_replace( 'T', ' ', $raw ), 0, 16 ), wp_timezone() );
			if ( ! $when ) {
				return self::invalid( 'publish_at', __( 'publish_at is required for a scheduled event, as site-local "YYYY-MM-DD HH:MM".', 'arkon-bar-manager' ) );
			}
			if ( $when->getTimestamp() <= time() ) {
				return self::invalid( 'publish_at', __( 'publish_at must be in the future.', 'arkon-bar-manager' ) );
			}
			$publish_at = $when->format( 'Y-m-d H:i:s' );
		}

		return array( $status, $publish_at );
	}

	/* --------------------------------------------------------------------- */
	/* Applying: events                                                      */
	/* --------------------------------------------------------------------- */

	/**
	 * Write fields to a new or existing event and re-derive everything that
	 * depends on them.
	 *
	 * @param int    $id         Event ID, 0 when creating.
	 * @param array  $in         Validated fields from read_fields().
	 * @param string $status     Status for a new event.
	 * @param string $publish_at Local publish time for a scheduled new event.
	 * @param bool   $creating   Whether to insert.
	 * @param bool       $verbatim   The event holds its dates verbatim and keeps doing so.
	 * @param array|null $row_write  [ start, end ] to give its upcoming nights, or null to leave them.
	 * @return array|WP_Error {post_id,details}
	 */
	private static function apply_fields( $id, array $in, $status, $publish_at, $creating, $verbatim, $row_write = null ) {
		$details = array();

		if ( $creating ) {
			$postarr = array(
				'post_type'    => ABM_POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $in['title'],
				'post_content' => $in['content'] ?? '',
				'post_excerpt' => $in['excerpt'] ?? '',
			);
			if ( 'future' === $status ) {
				$postarr['post_date']     = $publish_at;
				$postarr['post_date_gmt'] = get_gmt_from_date( $publish_at );
			}
			$id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$id = (int) $id;
		} else {
			$postarr = array();
			foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $from => $to ) {
				if ( array_key_exists( $from, $in ) ) {
					$postarr[ $to ] = $in[ $from ];
				}
			}
			if ( $postarr ) {
				$postarr['ID'] = $id;
				$saved         = wp_update_post( wp_slash( $postarr ), true );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
			}
		}

		$meta = array(
			'date'               => 'abm_event_date',
			'start'              => 'abm_event_time_start',
			'end'                => 'abm_event_time_end',
			'cost'               => 'abm_event_cost',
			'show_category'      => 'abm_show_category',
			'display_start_only' => 'abm_display_start_only',
		);
		foreach ( $meta as $from => $key ) {
			if ( array_key_exists( $from, $in ) ) {
				update_post_meta( $id, $key, $in[ $from ] );
			}
		}

		// Same default Bulk Add uses: with no end time there is nothing real to
		// export, so clamp the export unless the caller said otherwise.
		if ( $creating && ! array_key_exists( 'display_start_only', $in ) ) {
			update_post_meta( $id, 'abm_display_start_only', '' === ( $in['end'] ?? '' ) ? 1 : 0 );
		}

		if ( array_key_exists( 'rule', $in ) && ! $verbatim ) {
			$rule = $in['rule'];
			update_post_meta( $id, 'abm_recur_type', $rule['type'] );
			update_post_meta( $id, 'abm_recur_interval', $rule['interval'] );
			update_post_meta( $id, 'abm_recur_weekdays', implode( ',', $rule['weekdays'] ) );
			update_post_meta( $id, 'abm_recur_until', $rule['until'] );
			update_post_meta( $id, 'abm_recur_count', $rule['count'] );
			update_post_meta( $id, 'abm_recur_exceptions', implode( ',', $rule['exceptions'] ) );
			// A rule replaces any dates held verbatim, so the nights set aside
			// from that set no longer mean anything.
			if ( '' !== $rule['type'] ) {
				delete_post_meta( $id, self::SKIPPED_META );
			}
		}

		if ( array_key_exists( 'categories', $in ) ) {
			wp_set_object_terms( $id, $in['categories'], ABM_TAXONOMY, false );
		}

		if ( array_key_exists( 'media_id', $in ) ) {
			if ( $in['media_id'] ) {
				set_post_thumbnail( $id, $in['media_id'] );
			} else {
				delete_post_thumbnail( $id );
			}
		}

		// A verbatim event's rows are not regenerated, so a time change has to be
		// written to the rows themselves or the calendar would not move.
		// The times come from the plan, which read them from the nights: a field
		// the caller did not send keeps the value the nights already had.
		if ( $verbatim && is_array( $row_write ) ) {
			$details['rows_changed'] = ABM_Occurrences::set_upcoming_times( $id, (string) $row_write[0], (string) $row_write[1] );
			// Bring the fields into line with the nights, so the two agree again.
			update_post_meta( $id, 'abm_event_time_start', (string) $row_write[0] );
			update_post_meta( $id, 'abm_event_time_end', (string) $row_write[1] );
		}

		self::rederive( $id );

		return array(
			'post_id' => $id,
			'details' => $details,
		);
	}

	/**
	 * @param int    $id         Event ID.
	 * @param string $status     draft, publish or future.
	 * @param string $publish_at Local time for future.
	 * @return array|WP_Error
	 */
	private static function apply_status( $id, $status, $publish_at ) {
		$postarr = array(
			'ID'          => $id,
			'post_status' => $status,
		);

		if ( 'future' === $status ) {
			$postarr['post_date']     = $publish_at;
			$postarr['post_date_gmt'] = get_gmt_from_date( $publish_at );
			$postarr['edit_date']     = true;
		} elseif ( 'publish' === $status ) {
			// Publishing now. A date still in the future would make WordPress
			// schedule it instead.
			$post = get_post( $id );
			if ( $post && strtotime( $post->post_date_gmt . ' +0000' ) > time() ) {
				$postarr['post_date']     = current_time( 'mysql' );
				$postarr['post_date_gmt'] = current_time( 'mysql', true );
				$postarr['edit_date']     = true;
			}
		}

		$saved = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		self::rederive( $id );

		return array( 'post_id' => $id );
	}

	/**
	 * @param int    $id          Event ID.
	 * @param string $date        Date to skip.
	 * @param bool   $rule_driven Whether the event repeats by rule.
	 * @return array|WP_Error
	 */
	private static function apply_skip( $id, $date, $rule_driven ) {
		if ( $rule_driven ) {
			$rule         = ABM_Occurrences::get_rule( $id );
			$exceptions   = $rule['exceptions'];
			$exceptions[] = $date;
			$exceptions   = array_values( array_unique( $exceptions ) );
			sort( $exceptions );
			update_post_meta( $id, 'abm_recur_exceptions', implode( ',', $exceptions ) );
			self::rederive( $id );
			return array( 'post_id' => $id );
		}

		$row = ABM_Occurrences::remove_date( $id, $date );
		if ( ! $row ) {
			return new WP_Error( 'abm_skip_failed', __( 'The event no longer runs on that date.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
		}
		$kept   = self::skipped_rows( $id );
		$kept[] = $row;
		update_post_meta( $id, self::SKIPPED_META, wp_slash( (string) wp_json_encode( $kept ) ) );

		return array( 'post_id' => $id );
	}

	/**
	 * @param int    $id          Event ID.
	 * @param string $date        Date to put back.
	 * @param bool   $rule_driven Whether the event repeats by rule.
	 * @return array|WP_Error
	 */
	private static function apply_unskip( $id, $date, $rule_driven ) {
		if ( $rule_driven ) {
			$rule       = ABM_Occurrences::get_rule( $id );
			$exceptions = array_values( array_diff( $rule['exceptions'], array( $date ) ) );
			update_post_meta( $id, 'abm_recur_exceptions', implode( ',', $exceptions ) );
			self::rederive( $id );
			return array( 'post_id' => $id );
		}

		$kept     = self::skipped_rows( $id );
		$restored = false;
		foreach ( $kept as $i => $row ) {
			if ( $row['date'] === $date ) {
				ABM_Occurrences::add_row( $id, $row['date'], $row['start'], $row['end'] );
				unset( $kept[ $i ] );
				$restored = true;
				break;
			}
		}
		if ( ! $restored ) {
			return new WP_Error( 'abm_unskip_failed', __( 'That date is no longer set aside.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
		}
		if ( $kept ) {
			update_post_meta( $id, self::SKIPPED_META, wp_slash( (string) wp_json_encode( array_values( $kept ) ) ) );
		} else {
			delete_post_meta( $id, self::SKIPPED_META );
		}

		return array( 'post_id' => $id );
	}

	/**
	 * Display strings first, then occurrences, the same order and for the same
	 * reason as a REST save: both are derived from meta that was just written.
	 *
	 * @param int $id Event ID.
	 */
	private static function rederive( $id ) {
		clean_post_cache( $id );
		$post = get_post( $id );
		if ( $post && class_exists( 'ABM_Meta' ) ) {
			ABM_Meta::instance()->sync_derived( $id, $post );
		}
		ABM_Occurrences::generate_for_post( $id );
	}

	/* --------------------------------------------------------------------- */
	/* Categories                                                            */
	/* --------------------------------------------------------------------- */

	/**
	 * @param string $op Operation.
	 * @param array  $p  Parameters.
	 * @return array|WP_Error
	 */
	private static function plan_category( $op, array $p ) {
		$type = get_post_type_object( ABM_POST_TYPE );
		if ( ! $type || ! current_user_can( $type->cap->edit_others_posts ) ) {
			return self::forbidden();
		}

		$none = array(
			'exists' => false,
			'status' => '',
			'title'  => '',
			'dates'  => array(),
		);
		$ctx  = array( 'op' => $op );
		$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
		$term = null;

		if ( 'category_create' !== $op ) {
			$term = get_term( (int) ( $p['id'] ?? 0 ), ABM_TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'abm_not_found', __( 'No event category with that ID.', 'arkon-bar-manager' ), array( 'status' => 404 ) );
			}
		}
		if ( 'category_delete' !== $op && ( '' === $name || strlen( $name ) > 100 ) ) {
			return self::invalid( 'name', __( 'name must be 1 to 100 characters.', 'arkon-bar-manager' ) );
		}

		if ( 'category_create' === $op ) {
			if ( term_exists( $name, ABM_TAXONOMY ) ) {
				return new WP_Error( 'abm_category_exists', __( 'A category with that name already exists.', 'arkon-bar-manager' ), array( 'status' => 409 ) );
			}
			/* translators: %s: category name. */
			$text  = sprintf( __( 'Create category "%s"', 'arkon-bar-manager' ), $name );
			$apply = static function () use ( $name ) {
				$made = wp_insert_term( $name, ABM_TAXONOMY );
				if ( is_wp_error( $made ) ) {
					return $made;
				}
				return array( 'details' => array( 'category_id' => (int) $made['term_id'] ) );
			};
		} elseif ( 'category_rename' === $op ) {
			/* translators: 1: old name, 2: new name, 3: number of events. */
			$text  = sprintf( __( 'Rename category "%1$s" to "%2$s" (%3$d events carry it)', 'arkon-bar-manager' ), $term->name, $name, (int) $term->count );
			$apply = static function () use ( $term, $name ) {
				$saved = wp_update_term( $term->term_id, ABM_TAXONOMY, array( 'name' => $name ) );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				return array( 'details' => array( 'category_id' => (int) $term->term_id ) );
			};
		} else {
			/* translators: 1: name, 2: number of events. */
			$text  = sprintf( __( 'Delete category "%1$s" (%2$d events carry it; the events are kept)', 'arkon-bar-manager' ), $term->name, (int) $term->count );
			$apply = static function () use ( $term ) {
				$gone = wp_delete_term( $term->term_id, ABM_TAXONOMY );
				if ( is_wp_error( $gone ) || ! $gone ) {
					return is_wp_error( $gone ) ? $gone : new WP_Error( 'abm_delete_failed', __( 'The category could not be deleted.', 'arkon-bar-manager' ), array( 'status' => 500 ) );
				}
				return array( 'details' => array( 'category_id' => (int) $term->term_id ) );
			};
		}

		return array(
			'post_id' => 0,
			'before'  => $none,
			'after'   => $none,
			'ctx'     => $ctx,
			'text'    => $text,
			'apply'   => $apply,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Helpers                                                               */
	/* --------------------------------------------------------------------- */

	/**
	 * Other events on the given dates, for the duplicate checks.
	 *
	 * @param string[] $dates   Dates.
	 * @param int      $exclude Event to leave out.
	 * @return array<string,array<int,string>> date => [ id => title ]
	 */
	private static function others_on( array $dates, $exclude ) {
		global $wpdb;

		$dates = array_values( array_filter( array_map( 'abm_sanitize_date', $dates ), 'strlen' ) );
		if ( ! $dates ) {
			return array();
		}

		$table = ABM_Occurrences::table();
		$marks = implode( ',', array_fill( 0, count( $dates ), '%s' ) );
		$args  = array_merge( array( ABM_POST_TYPE, (int) $exclude ), $dates );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.occur_date, p.ID, p.post_title
				 FROM {$table} o JOIN {$wpdb->posts} p ON p.ID = o.post_id
				 WHERE p.post_type = %s AND p.ID <> %d
				   AND p.post_status IN ('publish','draft','pending','future','private')
				   AND o.occur_date IN ({$marks})",
				$args
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->occur_date ][ (int) $row->ID ] = (string) $row->post_title;
		}
		return $out;
	}

	/**
	 * Whether every upcoming row carries the same times.
	 *
	 * @param array  $rows  From rows_for().
	 * @param string $today Y-m-d.
	 * @return bool
	 */
	private static function upcoming_uniform( array $rows, $today ) {
		$seen = array();
		foreach ( $rows as $row ) {
			if ( $row['date'] >= $today ) {
				$seen[ $row['start'] . '|' . $row['end'] ] = true;
			}
		}
		return count( $seen ) <= 1;
	}

	/**
	 * @param int $id Event ID.
	 * @return array<int,array{date:string,start:string,end:string}>
	 */
	private static function skipped_rows( $id ) {
		$raw  = json_decode( (string) get_post_meta( $id, self::SKIPPED_META, true ), true );
		$rows = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( is_array( $row ) && '' !== abm_sanitize_date( (string) ( $row['date'] ?? '' ) ) ) {
				$rows[] = array(
					'date'  => (string) $row['date'],
					'start' => (string) ( $row['start'] ?? '' ),
					'end'   => (string) ( $row['end'] ?? '' ),
				);
			}
		}
		return $rows;
	}

	/**
	 * The flyer this event was actually given, or 0.
	 *
	 * Read from the table on purpose. A fallback-image plugin can answer
	 * get_post_thumbnail_id() and get_post_meta() for an event that has no
	 * image, and can write its answer into the meta cache, so neither says
	 * whether the event has a flyer of its own. An agent deciding whether a
	 * show still needs artwork has to know that.
	 *
	 * @param int $id Event ID.
	 * @return int Attachment ID.
	 */
	private static function own_flyer_id( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_thumbnail_id' ORDER BY meta_id DESC LIMIT 1", (int) $id ) );
	}

	/** @return array */
	private static function empty_rule() {
		return array(
			'type'       => '',
			'interval'   => 1,
			'weekdays'   => array(),
			'until'      => '',
			'count'      => 0,
			'exceptions' => array(),
		);
	}

	/**
	 * A plan that is refused without consulting the classifier, for a request
	 * that makes no sense for this event.
	 *
	 * @param int    $id     Event ID.
	 * @param string $label  Event title.
	 * @param string $reason Why.
	 * @return array
	 */
	private static function refusal( $id, $label, $reason ) {
		return array(
			'post_id' => $id,
			'text'    => $label,
			'verdict' => array(
				'outcome'      => ABM_Change_Classifier::REFUSED,
				'reasons'      => array( $reason ),
				'removed'      => array(),
				'added'        => array(),
				'duplicate_of' => 0,
			),
			'apply'   => null,
		);
	}

	/**
	 * @param int $id Event ID.
	 * @return array|null
	 */
	private static function event_or_null( $id ) {
		$event = self::event( $id );
		return is_wp_error( $event ) ? null : $event;
	}

	/**
	 * @param string $op        Operation.
	 * @param int    $post_id   Event ID.
	 * @param string $outcome   What happened.
	 * @param array  $summary   Plain description.
	 * @param int    $change_id Pending change this relates to.
	 * @param int    $gone      Upcoming dates removed.
	 */
	private static function log( $op, $post_id, $outcome, array $summary, $change_id = 0, $gone = 0 ) {
		ABM_Agent_Store::log(
			array(
				'user_id'          => get_current_user_id(),
				'op'               => $op,
				'post_id'          => $post_id,
				'outcome'          => $outcome,
				'removed_upcoming' => $gone,
				'summary'          => $summary,
				'change_id'        => $change_id,
			)
		);
	}

	/** @return WP_Error */
	private static function forbidden() {
		return new WP_Error( 'abm_forbidden', __( 'You are not allowed to do that.', 'arkon-bar-manager' ), array( 'status' => 403 ) );
	}

	/**
	 * @param string $field   Field name.
	 * @param string $message What is wrong with it.
	 * @return WP_Error
	 */
	private static function invalid( $field, $message ) {
		return new WP_Error(
			'abm_invalid_' . $field,
			$message,
			array(
				'status' => 400,
				'field'  => $field,
			)
		);
	}
}
