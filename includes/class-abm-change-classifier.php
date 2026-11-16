<?php
/**
 * Decides what happens to a change an agent asks for.
 *
 * Every write that arrives through the agent API is described here as a
 * before state and an after state, and comes back as one of three outcomes:
 * applied, approval_required, or refused. This class is the only place that
 * rule lives.
 *
 * It makes no WordPress calls, the same way ABM_Bulk_Parser does not, so the
 * rule can be exercised offline with nothing but PHP. The caller supplies
 * everything it needs to know: the dates the event has and would have, what
 * else is on those nights, and how much an agent has already removed lately.
 *
 * The rule protects what the public can see. A draft has no dates on the
 * calendar, so it can be reshaped or trashed freely; a published or scheduled
 * event cannot lose more than one upcoming date without a person seeing
 * exactly which dates would go.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Change_Classifier {

	const APPLIED  = 'applied';
	const APPROVAL = 'approval_required';
	const REFUSED  = 'refused';

	/** One change may remove this many upcoming public dates on its own. */
	const MAX_REMOVED_PER_CHANGE = 1;

	/** Upcoming dates agents may skip from one event inside the log window. */
	const MAX_SKIPS_IN_WINDOW = 2;

	/** Statuses whose dates the public can see, now or once scheduled. */
	const PUBLIC_STATUSES = array( 'publish', 'future' );

	/**
	 * Classify one change.
	 *
	 * @param array $before {exists:bool,status:string,title:string,dates:string[]} as stored.
	 * @param array $after  Same shape, as it would be once applied.
	 * @param array $ctx    {op:string,today:string,recent_removed:int,locked:bool,
	 *                      dates_meta_changed:bool,rule_changed:bool,time_changed:bool,
	 *                      upcoming_times_uniform:bool,date?:string,
	 *                      others:array<string,array<int,string>>} where others maps a
	 *                      date to the other events on it, id => title.
	 * @return array{outcome:string,reasons:string[],removed:string[],added:string[],duplicate_of:int}
	 */
	public static function classify( array $before, array $after, array $ctx ) {
		$op    = (string) ( $ctx['op'] ?? '' );
		$today = (string) ( $ctx['today'] ?? '' );

		$result = array(
			'outcome'      => self::APPLIED,
			'reasons'      => array(),
			'removed'      => array(),
			'added'        => array(),
			'duplicate_of' => 0,
		);

		// Categories have no dates. Creating one adds a choice; renaming or
		// deleting one changes every event that carries it.
		if ( 'category_create' === $op ) {
			return $result;
		}
		if ( 'category_rename' === $op || 'category_delete' === $op ) {
			return self::hold( $result, 'This changes a category that existing events may carry.' );
		}

		$before_dates = self::dates( $before );
		$after_dates  = self::dates( $after );
		$was_public   = self::is_public( $before );
		$is_public    = self::is_public( $after );

		$public_before     = $was_public ? $before_dates : array();
		$public_after      = $is_public ? $after_dates : array();
		$result['removed'] = array_values( array_diff( $public_before, $public_after ) );
		$result['added']   = array_values( array_diff( $public_after, $public_before ) );

		$title_changed = self::normalize_title( (string) ( $before['title'] ?? '' ) ) !== self::normalize_title( (string) ( $after['title'] ?? '' ) );
		$others        = is_array( $ctx['others'] ?? null ) ? $ctx['others'] : array();

		/*
		 * Refusals: things that are wrong whoever approves them.
		 */

		// The same show on the same night, whatever its status. Only nights this
		// change newly puts the event on are compared, unless the title itself
		// changed, in which case every night is a new claim.
		$claimed = ( $title_changed || empty( $before['exists'] ) ) ? $after_dates : array_values( array_diff( $after_dates, $before_dates ) );
		$twin    = self::find( $claimed, (string) ( $after['title'] ?? '' ), $others, false );
		if ( $twin ) {
			$result['outcome']      = self::REFUSED;
			$result['duplicate_of'] = $twin['id'];
			$result['reasons'][]    = sprintf( 'An event with this title is already on %s (ID %d). Edit that one instead.', $twin['date'], $twin['id'] );
			return $result;
		}

		if ( 'skip' === $op && ! in_array( (string) ( $ctx['date'] ?? '' ), $before_dates, true ) ) {
			$result['outcome']   = self::REFUSED;
			$result['reasons'][] = 'The event does not run on that date, so there is nothing to skip.';
			return $result;
		}

		// A locked event keeps its dates verbatim. Writing its date field would
		// report success and move nothing on the calendar.
		if ( ! empty( $ctx['locked'] ) && ! empty( $ctx['dates_meta_changed'] ) && empty( $ctx['rule_changed'] ) ) {
			$result['outcome']   = self::REFUSED;
			$result['reasons'][] = 'This event holds its dates exactly as imported, so changing its date field would not move it. Skip or restore single dates, or give it a repeat rule to replace its dates.';
			return $result;
		}

		/*
		 * Holds: things a person should see first.
		 */

		// A look-alike title on the same night is probably the same show spelled
		// two ways. It only matters once the public would see both.
		if ( $is_public ) {
			$near_dates = ( $title_changed || ! $was_public ) ? $after_dates : $result['added'];
			$near       = self::find( $near_dates, (string) ( $after['title'] ?? '' ), $others, true );
			if ( $near ) {
				$result = self::hold( $result, sprintf( 'An event with a very similar title, "%s" (ID %d), is already on %s.', $near['title'], $near['id'], $near['date'] ) );
			}
		}

		// Nothing below applies to an event the public cannot see.
		if ( ! $was_public ) {
			return $result;
		}

		if ( 'trash' === $op ) {
			$result = self::hold( $result, 'This removes a live event from the calendar.' );
		} elseif ( ! $is_public ) {
			$result = self::hold( $result, 'This takes a live event off the calendar.' );
		}

		$upcoming = array_values(
			array_filter(
				$result['removed'],
				static function ( $d ) use ( $today ) {
					return $d >= $today;
				}
			)
		);

		if ( $is_public && count( $upcoming ) > self::MAX_REMOVED_PER_CHANGE ) {
			$result = self::hold( $result, sprintf( 'This removes %d upcoming dates from the calendar.', count( $upcoming ) ) );
		}

		if ( 'skip' === $op && (int) ( $ctx['recent_removed'] ?? 0 ) + count( $upcoming ) > self::MAX_SKIPS_IN_WINDOW ) {
			$result = self::hold( $result, 'Several dates have already been skipped from this event recently.' );
		}

		if ( ! empty( $ctx['locked'] ) && ! empty( $ctx['time_changed'] ) && empty( $ctx['upcoming_times_uniform'] ) ) {
			$result = self::hold( $result, 'This event\'s upcoming nights do not all share one time, and this would give them all the same one.' );
		}

		return $result;
	}

	/**
	 * A title reduced to what a person would compare: lowercase words.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function normalize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );
		return trim( (string) $title );
	}

	/**
	 * Whether two titles are probably the same show spelled two ways.
	 *
	 * Identical titles are not "near"; that is a duplicate and is handled
	 * separately. Short titles are compared more strictly, because one letter
	 * is most of the difference between "DJ A" and "DJ B".
	 *
	 * @param string $a Title.
	 * @param string $b Title.
	 * @return bool
	 */
	public static function near( $a, $b ) {
		$a = self::normalize_title( $a );
		$b = self::normalize_title( $b );
		if ( '' === $a || '' === $b || $a === $b ) {
			return false;
		}

		$shorter = min( strlen( $a ), strlen( $b ) );
		if ( $shorter >= 6 && strlen( $a ) <= 255 && strlen( $b ) <= 255 && levenshtein( $a, $b ) <= 2 ) {
			return true;
		}

		similar_text( $a, $b, $percent );
		return $percent >= 85.0;
	}

	/**
	 * Find another event on one of these dates whose title matches.
	 *
	 * @param string[] $dates  Dates to look on.
	 * @param string   $title  Title to compare.
	 * @param array    $others date => [ id => title ].
	 * @param bool     $near   True for look-alikes, false for identical titles.
	 * @return array{id:int,title:string,date:string}|null
	 */
	private static function find( array $dates, $title, array $others, $near ) {
		$wanted = self::normalize_title( $title );
		if ( '' === $wanted ) {
			return null;
		}
		foreach ( $dates as $date ) {
			if ( empty( $others[ $date ] ) || ! is_array( $others[ $date ] ) ) {
				continue;
			}
			foreach ( $others[ $date ] as $id => $other ) {
				$hit = $near ? self::near( $title, (string) $other ) : ( self::normalize_title( (string) $other ) === $wanted );
				if ( $hit ) {
					return array(
						'id'    => (int) $id,
						'title' => (string) $other,
						'date'  => (string) $date,
					);
				}
			}
		}
		return null;
	}

	/**
	 * @param array $state State.
	 * @return string[] Sorted, unique.
	 */
	private static function dates( array $state ) {
		$dates = array_values( array_unique( array_map( 'strval', (array) ( $state['dates'] ?? array() ) ) ) );
		sort( $dates );
		return $dates;
	}

	/**
	 * @param array $state State.
	 * @return bool
	 */
	private static function is_public( array $state ) {
		return ! empty( $state['exists'] ) && in_array( (string) ( $state['status'] ?? '' ), self::PUBLIC_STATUSES, true );
	}

	/**
	 * @param array  $result Result so far.
	 * @param string $reason Why a person should look.
	 * @return array
	 */
	private static function hold( array $result, $reason ) {
		$result['outcome']   = self::APPROVAL;
		$result['reasons'][] = $reason;
		return $result;
	}
}
