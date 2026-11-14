<?php
/**
 * RFC 5545 .ics generation for a single event.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_ICal {

	/**
	 * Output an .ics file for the given event and exit. Caller must verify the
	 * post exists / is the right type.
	 *
	 * @param int $post_id Event ID.
	 */
	public static function output( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ABM_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			status_header( 404 );
			exit;
		}

		// A recurring event exports the occurrence being viewed (?occ=), not the
		// series start, so "Download iCal" on a given row adds that night.
		$date  = ABM_Frontend::occurrence_date( $post_id );
		$start = get_post_meta( $post_id, 'abm_event_time_start', true );
		$end   = get_post_meta( $post_id, 'abm_event_time_end', true );

		$dts = abm_event_datetimes( $date, $start, $end, abm_event_clamp_enabled( $post_id ) );
		if ( ! $dts ) {
			status_header( 404 );
			exit;
		}
		list( $start_dt, $end_dt ) = $dts;

		$dtstamp = gmdate( 'Ymd\THis\Z' );

		$summary  = self::escape( get_the_title( $post_id ) );
		$location = self::escape( abm_venue_location() );

		// Nothing from behind a password: the file still says what and when.
		$desc = '';
		if ( ! post_password_required( $post ) ) {
			$desc = wp_strip_all_tags( (string) $post->post_excerpt );
			if ( '' === $desc ) {
				$desc = wp_strip_all_tags( (string) $post->post_content );
			}
		}
		$cost = abm_format_cost( get_post_meta( $post_id, 'abm_event_cost', true ) );
		if ( '' !== $cost ) {
			/* translators: %s: cover charge. */
			$desc .= ( $desc ? "\n\n" : '' ) . sprintf( __( 'Cover: %s', 'arkon-bar-manager' ), $cost );
		}
		$desc = self::escape( $desc . ( $desc ? "\n\n" : '' ) . get_permalink( $post_id ) );

		/*
		 * A repeating event exports as a repeating event.
		 *
		 * The series is described from this night forward, so DTSTART below is a
		 * genuine instance of the rule -- which RFC 5545 requires -- and no past
		 * dates are written into the visitor's calendar. The times come from the
		 * occurrence being viewed; a rule cannot say "this one night runs late",
		 * and neither can any calendar client reading it.
		 */
		$series = ABM_Recurrence::describe( $post_id, $date );

		/*
		 * A series is written in the venue's own time zone, not in UTC.
		 *
		 * A calendar expands a rule in the zone DTSTART is given in. Friday 9 PM in
		 * Chicago is Saturday 02:00 in UTC, so a UTC DTSTART beside BYDAY=FR asks
		 * for Fridays at 02:00 UTC -- which is Thursday night at the venue. The same
		 * UTC instant is also an hour off on the far side of every clock change,
		 * and an EXDATE stamped for that side then matches nothing.
		 *
		 * A single night has no rule to expand, so it stays an absolute UTC instant,
		 * which is exact. So does a series on a site whose zone is UTC itself.
		 */
		$tz    = $start_dt->getTimezone();
		$local = $series && ! in_array( $tz->getName(), array( 'UTC', '+00:00' ), true );
		$utc   = new DateTimeZone( 'UTC' );

		if ( $local ) {
			$tzid    = self::tzid( $tz );
			$dtstart = 'DTSTART;TZID=' . $tzid . ':' . $start_dt->format( 'Ymd\THis' );
			$dtend   = 'DTEND;TZID=' . $tzid . ':' . $end_dt->format( 'Ymd\THis' );
		} else {
			$tzid    = '';
			$dtstart = 'DTSTART:' . ( clone $start_dt )->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$dtend   = 'DTEND:' . ( clone $end_dt )->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		/*
		 * The UID identifies the thing being saved, and what that is depends on
		 * whether this export is a series.
		 *
		 * A single night is identified by its date. Without it, saving two nights
		 * of a weekly event hands the calendar app the same UID twice and the
		 * second silently replaces the first instead of landing beside it.
		 *
		 * A series is identified by the event alone. Saving one from two different
		 * rows is the same series twice, and dating the UID would file it as two
		 * -- leaving the visitor with every night duplicated from whichever row
		 * they clicked second onwards. One UID means the second save updates the
		 * first, which is what re-saving a series should do.
		 */
		$uid = 'abm-event-' . $post_id
			. ( $series ? '' : '-' . str_replace( '-', '', (string) $date ) )
			. '@' . ( $host ? $host : 'localhost' );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Arkon Event Manager//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		);

		// The zone a TZID names has to be defined in the same file. Most calendars
		// know the IANA names on their own; the ones that do not read this.
		if ( $local ) {
			$lines = array_merge( $lines, self::vtimezone( $tz, $tzid, $start_dt, self::series_end( $series, $start_dt ) ) );
		}

		$lines = array_merge(
			$lines,
			array(
				'BEGIN:VEVENT',
				'UID:' . $uid,
				'DTSTAMP:' . $dtstamp,
				$dtstart,
				$dtend,
			)
		);

		// EXDATE and RDATE carry the same clock time, in the same form, as DTSTART
		// -- see stamp_all().
		if ( $series ) {
			$param = $local ? ';TZID=' . $tzid : '';
			if ( '' !== $series['rrule'] ) {
				$lines[] = 'RRULE:' . $series['rrule'];
			}
			if ( $series['exdates'] ) {
				$lines[] = 'EXDATE' . $param . ':' . implode( ',', self::stamp_all( $series['exdates'], $start_dt, $local ) );
			}
			if ( $series['rdates'] ) {
				$lines[] = 'RDATE' . $param . ':' . implode( ',', self::stamp_all( $series['rdates'], $start_dt, $local ) );
			}
		}

		$lines = array_merge(
			$lines,
			array(
				'SUMMARY:' . $summary,
				'DESCRIPTION:' . $desc,
				'LOCATION:' . $location,
				'URL:' . self::escape( get_permalink( $post_id ) ),
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);

		// Fold lines to 75 octets per RFC 5545.
		$output = '';
		foreach ( $lines as $line ) {
			$output .= self::fold( $line ) . "\r\n";
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ?: 'event' ) . '.ics"' );

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal payload, values escaped via self::escape().
		exit;
	}

	/**
	 * Render a list of dates carrying the series' own start time, for EXDATE /
	 * RDATE: local wall-clock values when DTSTART is local, UTC otherwise.
	 *
	 * The clock time is taken from DTSTART rather than from each occurrence row.
	 * An EXDATE only cancels an instance if it matches that instance exactly, so
	 * a date stamped with some other time silently cancels nothing -- the failure
	 * shows up as the skipped night still appearing in the visitor's calendar,
	 * with nothing anywhere saying why.
	 *
	 * @param string[]          $dates    Y-m-d.
	 * @param DateTimeInterface $start_dt The event's start, with its time.
	 * @param bool              $local    Whether DTSTART is written with a TZID.
	 * @return string[]
	 */
	private static function stamp_all( $dates, $start_dt, $local = false ) {
		$utc  = new DateTimeZone( 'UTC' );
		$out  = array();
		$hour = (int) $start_dt->format( 'H' );
		$min  = (int) $start_dt->format( 'i' );

		foreach ( $dates as $ymd ) {
			$dt = date_create( $ymd, $start_dt->getTimezone() );
			if ( ! $dt ) {
				continue;
			}
			$dt->setTime( $hour, $min, 0 );
			$out[] = $local
				? $dt->format( 'Ymd\THis' )
				: $dt->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}

		return $out;
	}

	/**
	 * A TZID for the site's zone.
	 *
	 * An IANA name is used as it is. A site set to a manual offset has a zone
	 * named like "+05:30", and a colon cannot appear in an unquoted parameter
	 * value, so that becomes "UTC+0530" -- which means nothing to a calendar on
	 * its own, and does not have to, because vtimezone() defines it.
	 *
	 * @param DateTimeZone $tz Site zone.
	 * @return string
	 */
	private static function tzid( DateTimeZone $tz ) {
		$name = $tz->getName();
		if ( preg_match( '#^[A-Za-z][A-Za-z0-9_+\-]*(/[A-Za-z0-9_+\-]+)*$#', $name ) ) {
			return $name;
		}
		return 'UTC' . preg_replace( '/[^0-9+\-]/', '', $name );
	}

	/**
	 * How far the zone definition has to reach: the series' own end where it has
	 * one, five years where it does not, and never more than ten, so an
	 * eight-year weekly does not ship a page of clock changes it will never use.
	 *
	 * @param array             $series   From ABM_Recurrence::describe().
	 * @param DateTimeInterface $start_dt The event's start.
	 * @return int Unix timestamp.
	 */
	private static function series_end( $series, $start_dt ) {
		$start = $start_dt->getTimestamp();
		$cap   = $start + 10 * 366 * DAY_IN_SECONDS;
		$end   = 0;

		if ( preg_match( '/UNTIL=(\d{4})(\d{2})(\d{2})/', (string) $series['rrule'], $m ) ) {
			$end = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		} elseif ( $series['rdates'] ) {
			$last = date_create( end( $series['rdates'] ), new DateTimeZone( 'UTC' ) );
			$end  = $last ? $last->getTimestamp() : 0;
		}

		if ( ! $end ) {
			return $start + 5 * 366 * DAY_IN_SECONDS;
		}

		// A few days past the last night, so its own offset is always covered.
		return min( $cap, $end + 3 * DAY_IN_SECONDS );
	}

	/**
	 * A VTIMEZONE describing the site's zone across the series.
	 *
	 * Written from the zone database's own transitions, one observance per clock
	 * change, rather than as a yearly rule inferred from them. Inferring "second
	 * Sunday in March" from a sample year is right until a zone changes its law,
	 * and then wrong without any sign of it; the transitions are simply what
	 * happened and what is scheduled.
	 *
	 * @param DateTimeZone      $tz       Site zone.
	 * @param string            $tzid     From tzid().
	 * @param DateTimeInterface $start_dt The event's start.
	 * @param int               $until    Unix timestamp the definition must reach.
	 * @return string[] Content lines.
	 */
	private static function vtimezone( DateTimeZone $tz, $tzid, $start_dt, $until ) {
		// From two days before the first night, so the first observance listed is
		// the one in force on that night and starts before it in any zone. Nothing
		// earlier is needed: the export never reaches back past the night viewed.
		$begin = $start_dt->getTimestamp() - 2 * DAY_IN_SECONDS;
		$steps = $tz->getTransitions( $begin, max( $begin, (int) $until ) );

		$lines = array(
			'BEGIN:VTIMEZONE',
			'TZID:' . $tzid,
		);

		// A fixed offset has no transitions to list: one observance, for all time.
		if ( ! is_array( $steps ) || ! $steps ) {
			$offset = self::utc_offset( $tz->getOffset( $start_dt ) );
			return array_merge(
				$lines,
				array(
					'BEGIN:STANDARD',
					'DTSTART:19700101T000000',
					'TZOFFSETFROM:' . $offset,
					'TZOFFSETTO:' . $offset,
					'END:STANDARD',
					'END:VTIMEZONE',
				)
			);
		}

		foreach ( $steps as $i => $step ) {
			// The first entry is the state at $begin, not a change, so it has no
			// earlier offset to come from; it comes from its own.
			$from = $i > 0 ? (int) $steps[ $i - 1 ]['offset'] : (int) $step['offset'];
			$kind = ! empty( $step['isdst'] ) ? 'DAYLIGHT' : 'STANDARD';

			$lines[] = 'BEGIN:' . $kind;
			// An observance starts at the local time shown just before the change.
			$lines[] = 'DTSTART:' . gmdate( 'Ymd\THis', (int) $step['ts'] + $from );
			$lines[] = 'TZOFFSETFROM:' . self::utc_offset( $from );
			$lines[] = 'TZOFFSETTO:' . self::utc_offset( (int) $step['offset'] );
			if ( ! empty( $step['abbr'] ) && preg_match( '/^[A-Za-z]+$/', (string) $step['abbr'] ) ) {
				$lines[] = 'TZNAME:' . $step['abbr'];
			}
			$lines[] = 'END:' . $kind;
		}

		$lines[] = 'END:VTIMEZONE';

		return $lines;
	}

	/**
	 * Seconds east of UTC as an RFC 5545 utc-offset: -18000 becomes "-0500".
	 *
	 * @param int $seconds Offset in seconds.
	 * @return string
	 */
	private static function utc_offset( $seconds ) {
		$seconds = (int) $seconds;
		$abs     = abs( $seconds );
		return sprintf( '%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv( $abs, 3600 ), intdiv( $abs % 3600, 60 ) );
	}

	/**
	 * Escape text per RFC 5545 (backslash, comma, semicolon, newlines).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function escape( $text ) {
		$text = (string) $text;
		$text = str_replace( array( '\\', ',', ';' ), array( '\\\\', '\\,', '\\;' ), $text );
		$text = str_replace( array( "\r\n", "\n", "\r" ), '\\n', $text );
		return $text;
	}

	/**
	 * Fold a content line at 75 octets with CRLF + space continuation.
	 *
	 * @param string $line Content line.
	 * @return string
	 */
	private static function fold( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		// Cut on a character boundary. The limit is in octets, but a line broken
		// in the middle of a multi-byte character is two invalid halves to a
		// reader that decodes each physical line before unfolding.
		$folded = '';
		$limit  = 75;
		while ( strlen( $line ) > $limit ) {
			$piece = function_exists( 'mb_strcut' ) ? mb_strcut( $line, 0, $limit, 'UTF-8' ) : substr( $line, 0, $limit );
			if ( '' === $piece ) {
				$piece = substr( $line, 0, $limit ); // Not valid UTF-8; cut it anyway.
			}
			$folded .= $piece . "\r\n ";
			$line    = substr( $line, strlen( $piece ) );
			$limit   = 74; // A continuation line spends one octet on its leading space.
		}
		return $folded . $line;
	}
}
