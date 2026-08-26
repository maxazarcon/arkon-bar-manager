<?php
/**
 * Describe an event's series as a calendar recurrence.
 *
 * A repeating event exported one night at a time is not really exported: the
 * visitor who wants the weekly night on their phone has to save it again every
 * week. Both exports should hand over the series, which in RFC 5545 terms means
 * an RRULE.
 *
 * There are two kinds of repeating event here and only one of them carries a
 * rule. Events created in this plugin have abm_recur_* meta, which says exactly
 * what the pattern is. Events imported from another calendar hold their dates
 * verbatim and carry no rule at all, because the dates came from the source
 * system rather than from a pattern -- and in practice those are the busiest
 * events on the calendar. So the pattern has to be recovered from the stored
 * dates for them, which is what most of this file does.
 *
 * The explicit rule wins where there is one. It is authoritative, and it knows
 * things the rows cannot show: a rule with no end date is materialized only as
 * far as the rolling horizon, so reading its last row would claim the series
 * stops in two years when it does not.
 *
 * Everything is described from the occurrence being viewed, never from the
 * series start. DTSTART must be a real instance of the rule, the viewed night
 * always is one, and nobody wants eight years of past Mondays written into their
 * calendar.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Recurrence {

	/** RFC 5545 weekday tokens, indexed by date('w'). */
	const DAYS = array( 'SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA' );

	/**
	 * How many skipped dates are worth spelling out.
	 *
	 * A weekly night that takes a few holidays off is still a weekly night, and
	 * EXDATE says so precisely. A "weekly" series with fifty holes is not a
	 * pattern with exceptions, it is a list, and listing the dates outright
	 * describes it better than a rule that is wrong most of the time.
	 */
	const MAX_EXDATES = 30;

	/** Beyond this, a date list is not worth sending either. */
	const MAX_RDATES = 200;

	/**
	 * Describe the series an occurrence belongs to.
	 *
	 * Returns null for a single-date event, which is most of them -- the caller
	 * then exports exactly what it exported before.
	 *
	 * @param int    $post_id Event ID.
	 * @param string $from    The occurrence being viewed, Y-m-d.
	 * @return array{rrule:string,exdates:string[],rdates:string[]}|null
	 */
	public static function describe( $post_id, $from ) {
		$from = abm_sanitize_date( $from );
		if ( '' === $from ) {
			return null;
		}

		$rule = ABM_Occurrences::get_rule( $post_id );
		if ( '' !== $rule['type'] ) {
			$out = self::from_rule( $rule, $from );
			if ( $out ) {
				return $out;
			}
		}

		return self::from_dates( ABM_Occurrences::dates_from( $post_id, $from ), $from );
	}

	/* --------------------------------------------------------------------- */
	/* From an explicit rule                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * Translate this plugin's recurrence meta into an RRULE.
	 *
	 * @param array  $rule From ABM_Occurrences::get_rule().
	 * @param string $from The occurrence being viewed, Y-m-d.
	 * @return array{rrule:string,exdates:string[],rdates:string[]}|null
	 */
	private static function from_rule( $rule, $from ) {
		$start = date_create_immutable( $from );
		if ( ! $start ) {
			return null;
		}

		$parts = array();

		switch ( $rule['type'] ) {
			case 'daily':
				$parts[] = 'FREQ=DAILY';
				break;

			case 'weekly':
				$parts[] = 'FREQ=WEEKLY';
				$days    = array();
				foreach ( $rule['weekdays'] as $w ) {
					$days[] = self::DAYS[ $w ];
				}
				if ( ! $days ) {
					$days[] = self::DAYS[ (int) $start->format( 'w' ) ];
				}
				$parts[] = 'BYDAY=' . implode( ',', $days );
				break;

			case 'monthly_date':
				$parts[] = 'FREQ=MONTHLY';
				$parts[] = 'BYMONTHDAY=' . (int) $start->format( 'j' );
				break;

			case 'monthly_day':
				$parts[] = 'FREQ=MONTHLY';
				$parts[] = 'BYDAY=' . self::nth_of_month( $start ) . self::DAYS[ (int) $start->format( 'w' ) ];
				break;

			default:
				return null;
		}

		if ( $rule['interval'] > 1 ) {
			$parts[] = 'INTERVAL=' . (int) $rule['interval'];
		}

		// COUNT is stated from the series start, so it cannot be reused here --
		// the visitor is joining partway through. UNTIL is an absolute date and
		// carries over unchanged; a rule bounded only by COUNT is left open-ended
		// rather than given a made-up end.
		if ( '' !== $rule['until'] ) {
			$parts[] = 'UNTIL=' . self::until( $rule['until'] );
		}

		// Exceptions are stored for the whole series; only the ones still ahead
		// mean anything to a calendar being handed the rest of it.
		$exdates = array();
		foreach ( $rule['exceptions'] as $ex ) {
			if ( $ex >= $from ) {
				$exdates[] = $ex;
			}
		}
		sort( $exdates );

		return array(
			'rrule'   => implode( ';', $parts ),
			'exdates' => array_slice( $exdates, 0, self::MAX_EXDATES ),
			'rdates'  => array(),
		);
	}

	/* --------------------------------------------------------------------- */
	/* Recovered from the stored dates                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Recover a pattern from the dates an imported event actually holds.
	 *
	 * Tries the shapes a venue calendar really uses, in order of how tightly they
	 * fit, and falls back to listing the dates when none of them describes the
	 * set. A wrong rule is worse than a list: it writes nights into someone's
	 * calendar that the venue is not open for.
	 *
	 * @param string[] $dates Y-m-d, ascending, starting at the viewed occurrence.
	 * @param string   $from  The occurrence being viewed, Y-m-d.
	 * @return array{rrule:string,exdates:string[],rdates:string[]}|null
	 */
	private static function from_dates( $dates, $from ) {
		$dates = array_values( array_unique( $dates ) );
		if ( count( $dates ) < 2 ) {
			return null; // Nothing left ahead of this one: a single event.
		}

		// Monthly is tried first, and the order is load-bearing. "The second
		// Tuesday of the month" is every date on a Tuesday with gaps of four or
		// five whole weeks, so a weekly fit matches it -- as a four-weekly rule
		// with the five-week months listed as exceptions. That rule is wrong: it
		// drifts a week earlier every couple of months and then disagrees with
		// the calendar for the rest of the series. A monthly fit only matches
		// dates that really are a month apart, so trying it first costs a true
		// weekly series nothing.
		$fit = self::fit_monthly( $dates );
		if ( ! $fit ) {
			$fit = self::fit_weekly( $dates );
		}

		if ( $fit ) {
			return array(
				'rrule'   => $fit['rrule'] . ';UNTIL=' . self::until( end( $dates ) ),
				'exdates' => $fit['exdates'],
				'rdates'  => array(),
			);
		}

		// No pattern. RDATE still gives a calendar every night in one save, which
		// is the point of the export even without a rule to describe them.
		$rdates = array_slice( $dates, 1, self::MAX_RDATES );
		if ( ! $rdates ) {
			return null;
		}

		return array(
			'rrule'   => '',
			'exdates' => array(),
			'rdates'  => $rdates,
		);
	}

	/**
	 * Does this set look like "every N weeks on the same weekday"?
	 *
	 * @param string[] $dates Y-m-d ascending.
	 * @return array{rrule:string,exdates:string[]}|null
	 */
	private static function fit_weekly( $dates ) {
		$first = date_create_immutable( $dates[0] );
		if ( ! $first ) {
			return null;
		}

		$dow = $first->format( 'w' );
		foreach ( $dates as $d ) {
			$dt = date_create_immutable( $d );
			if ( ! $dt || $dt->format( 'w' ) !== $dow ) {
				return null; // Mixed weekdays: not this shape.
			}
		}

		// The interval is the smallest gap, in weeks. Taking the smallest rather
		// than the commonest matters: a fortnightly series that skips one is a
		// fortnightly series, but a weekly one read as fortnightly would drop
		// every other real night into EXDATE and then fail the density check for
		// the wrong reason.
		$weeks = self::gaps_in_weeks( $dates );
		if ( null === $weeks ) {
			return null;
		}
		$interval = min( $weeks );
		if ( $interval < 1 || $interval > 52 ) {
			return null;
		}

		$expected = self::walk( $dates[0], end( $dates ), $interval * 7 );
		$exdates  = array_values( array_diff( $expected, $dates ) );
		if ( count( $exdates ) > self::MAX_EXDATES ) {
			return null;
		}

		$rrule = 'FREQ=WEEKLY;BYDAY=' . self::DAYS[ (int) $dow ];
		if ( $interval > 1 ) {
			$rrule .= ';INTERVAL=' . $interval;
		}

		return array(
			'rrule'   => $rrule,
			'exdates' => $exdates,
		);
	}

	/**
	 * Does this set look like "the same date every month", or "the same weekday
	 * of every month"?
	 *
	 * @param string[] $dates Y-m-d ascending.
	 * @return array{rrule:string,exdates:string[]}|null
	 */
	private static function fit_monthly( $dates ) {
		$first = date_create_immutable( $dates[0] );
		if ( ! $first ) {
			return null;
		}

		$same_day   = true;
		$same_nth   = true;
		$day_of_mon = $first->format( 'j' );
		$dow        = $first->format( 'w' );
		$nth        = self::nth_of_month( $first );

		foreach ( $dates as $d ) {
			$dt = date_create_immutable( $d );
			if ( ! $dt ) {
				return null;
			}
			if ( $dt->format( 'j' ) !== $day_of_mon ) {
				$same_day = false;
			}
			if ( $dt->format( 'w' ) !== $dow || self::nth_of_month( $dt ) !== $nth ) {
				$same_nth = false;
			}
			if ( ! $same_day && ! $same_nth ) {
				return null;
			}
		}

		// Consecutive dates must sit one month apart. Anything sparser is a
		// pattern this does not try to name.
		$prev = null;
		foreach ( $dates as $d ) {
			$dt = date_create_immutable( $d );
			if ( $prev ) {
				$months = ( (int) $dt->format( 'Y' ) - (int) $prev->format( 'Y' ) ) * 12
					+ ( (int) $dt->format( 'n' ) - (int) $prev->format( 'n' ) );
				if ( $months < 1 || $months > 3 ) {
					return null;
				}
			}
			$prev = $dt;
		}

		if ( $same_day ) {
			$rrule = 'FREQ=MONTHLY;BYMONTHDAY=' . (int) $day_of_mon;
		} else {
			$rrule = 'FREQ=MONTHLY;BYDAY=' . $nth . self::DAYS[ (int) $dow ];
		}

		// Months the series skips. Cheap to work out here because the candidate
		// set is one date per month between the first and the last.
		$expected = array();
		$cursor   = $first;
		$last     = date_create_immutable( end( $dates ) );
		$guard    = 0;
		while ( $cursor && $last && $cursor <= $last && $guard++ < 600 ) {
			$expected[] = $cursor->format( 'Y-m-d' );
			$cursor     = self::next_month_match( $cursor, $same_day ? 0 : (int) $dow, $same_day ? (int) $day_of_mon : 0, $nth );
		}

		$exdates = array_values( array_diff( $expected, $dates ) );
		if ( count( $exdates ) > self::MAX_EXDATES ) {
			return null;
		}

		return array(
			'rrule'   => $rrule,
			'exdates' => $exdates,
		);
	}

	/* --------------------------------------------------------------------- */
	/* Small helpers                                                         */
	/* --------------------------------------------------------------------- */

	/**
	 * Gaps between consecutive dates, in whole weeks. Null if any gap is not a
	 * whole number of weeks, which rules the set out of a weekly shape.
	 *
	 * @param string[] $dates Y-m-d ascending.
	 * @return int[]|null
	 */
	private static function gaps_in_weeks( $dates ) {
		$weeks = array();
		$prev  = null;
		foreach ( $dates as $d ) {
			$dt = date_create_immutable( $d );
			if ( ! $dt ) {
				return null;
			}
			if ( $prev ) {
				$days = (int) round( ( $dt->getTimestamp() - $prev->getTimestamp() ) / DAY_IN_SECONDS );
				if ( $days <= 0 || 0 !== $days % 7 ) {
					return null;
				}
				$weeks[] = (int) ( $days / 7 );
			}
			$prev = $dt;
		}
		return $weeks ? $weeks : null;
	}

	/**
	 * Every date from $start to $end at a fixed spacing in days.
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @param int    $step  Days.
	 * @return string[]
	 */
	private static function walk( $start, $end, $step ) {
		$out    = array();
		$cursor = date_create_immutable( $start );
		$last   = date_create_immutable( $end );
		if ( ! $cursor || ! $last || $step < 1 ) {
			return $out;
		}
		$guard = 0;
		while ( $cursor <= $last && $guard++ < ABM_Occurrences::MAX_ROWS_PER_EVENT ) {
			$out[]  = $cursor->format( 'Y-m-d' );
			$cursor = $cursor->modify( '+' . $step . ' days' );
			if ( ! $cursor ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * The next month's matching date, either the same day number or the same
	 * nth weekday. Returns null when that month has no match -- a 31st in
	 * February, a fifth Tuesday in a month without one -- which is exactly the
	 * behaviour a calendar applies to the rule itself.
	 *
	 * @param DateTimeImmutable $from      Current match.
	 * @param int               $dow       Weekday 0-6, when matching by weekday.
	 * @param int               $day_of_mon Day number, when matching by date.
	 * @param string            $nth       '1'..'4' or '-1'.
	 * @return DateTimeImmutable|null
	 */
	private static function next_month_match( $from, $dow, $day_of_mon, $nth ) {
		$month = $from->modify( 'first day of next month' );
		if ( ! $month ) {
			return null;
		}

		if ( $day_of_mon > 0 ) {
			$days = (int) $month->format( 't' );
			if ( $day_of_mon > $days ) {
				// Skip the short month rather than rolling into the next one.
				return self::next_month_match( $month, 0, $day_of_mon, $nth );
			}
			return $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day_of_mon );
		}

		$phrase = ( '-1' === $nth ? 'last' : self::ordinal( (int) $nth ) ) . ' ' . self::day_name( $dow )
			. ' of ' . $month->format( 'F Y' );
		$hit    = date_create_immutable( $phrase );
		if ( ! $hit ) {
			return null;
		}

		// "5th Tuesday of a month with four" rolls into the following month;
		// drop it, which is what the rule does too.
		if ( $hit->format( 'n' ) !== $month->format( 'n' ) ) {
			return self::next_month_match( $month, $dow, 0, $nth );
		}

		return $hit;
	}

	/**
	 * Which occurrence of its weekday within the month a date is: '1'..'4', or
	 * '-1' for the last one. RFC 5545 counts a fifth from the end rather than
	 * the front, and so does every calendar client.
	 *
	 * @param DateTimeImmutable $dt Date.
	 * @return string
	 */
	private static function nth_of_month( $dt ) {
		$index = (int) ceil( (int) $dt->format( 'j' ) / 7 );
		if ( $index >= 5 ) {
			return '-1';
		}
		// A fourth that is also the last is better expressed as the last: it
		// keeps the series on the same night in five-week months.
		$days_left = (int) $dt->format( 't' ) - (int) $dt->format( 'j' );
		if ( 4 === $index && $days_left < 7 ) {
			return '-1';
		}
		return (string) $index;
	}

	/**
	 * UNTIL, as an inclusive UTC timestamp at the end of the given day.
	 *
	 * End of day rather than midnight: UNTIL is inclusive and compared against
	 * DTSTART's own value, so a midnight UNTIL on the final date can drop that
	 * date for an evening event. The end of the day cannot.
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function until( $ymd ) {
		return str_replace( '-', '', $ymd ) . 'T235959Z';
	}

	/**
	 * @param int $n 1-4.
	 * @return string
	 */
	private static function ordinal( $n ) {
		$words = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth' );
		return isset( $words[ $n ] ) ? $words[ $n ] : 'first';
	}

	/**
	 * @param int $dow 0-6.
	 * @return string
	 */
	private static function day_name( $dow ) {
		$names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		return isset( $names[ $dow ] ) ? $names[ $dow ] : 'Sunday';
	}
}
