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

		$utc       = new DateTimeZone( 'UTC' );
		$dtstart   = ( clone $start_dt )->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		$dtend     = ( clone $end_dt )->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		$dtstamp   = gmdate( 'Ymd\THis\Z' );

		$summary  = self::escape( get_the_title( $post_id ) );
		$location = self::escape( abm_venue_location() );

		$desc = wp_strip_all_tags( (string) $post->post_excerpt );
		if ( '' === $desc ) {
			$desc = wp_strip_all_tags( (string) $post->post_content );
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
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
		);

		// EXDATE and RDATE carry the same clock time as DTSTART -- see stamp_all().
		if ( $series ) {
			if ( '' !== $series['rrule'] ) {
				$lines[] = 'RRULE:' . $series['rrule'];
			}
			if ( $series['exdates'] ) {
				$lines[] = 'EXDATE:' . implode( ',', self::stamp_all( $series['exdates'], $start_dt ) );
			}
			if ( $series['rdates'] ) {
				$lines[] = 'RDATE:' . implode( ',', self::stamp_all( $series['rdates'], $start_dt ) );
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
	 * Render a list of dates as UTC timestamps carrying the series' own start
	 * time, for EXDATE / RDATE.
	 *
	 * The clock time is taken from DTSTART rather than from each occurrence row.
	 * An EXDATE only cancels an instance if it matches that instance exactly, so
	 * a date stamped with some other time silently cancels nothing -- the failure
	 * shows up as the skipped night still appearing in the visitor's calendar,
	 * with nothing anywhere saying why.
	 *
	 * @param string[]          $dates    Y-m-d.
	 * @param DateTimeInterface $start_dt The event's start, with its time.
	 * @return string[]
	 */
	private static function stamp_all( $dates, $start_dt ) {
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
			$out[] = $dt->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}

		return $out;
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
		$folded = '';
		while ( strlen( $line ) > 75 ) {
			$folded .= substr( $line, 0, 75 ) . "\r\n ";
			$line    = substr( $line, 75 );
		}
		return $folded . $line;
	}
}
