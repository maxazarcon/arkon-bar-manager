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

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		// The date belongs in the UID: without it, saving two nights of a weekly
		// event hands the calendar app the same UID twice and the second silently
		// replaces the first instead of adding alongside it.
		$uid = 'abm-event-' . $post_id . '-' . str_replace( '-', '', (string) $date ) . '@' . ( $host ? $host : 'localhost' );

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
			'SUMMARY:' . $summary,
			'DESCRIPTION:' . $desc,
			'LOCATION:' . $location,
			'URL:' . self::escape( get_permalink( $post_id ) ),
			'END:VEVENT',
			'END:VCALENDAR',
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
