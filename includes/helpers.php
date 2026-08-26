<?php
/**
 * Shared helpers: settings access, formatting, sanitization and calendar links.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a single plugin setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback when unset/empty.
 * @return mixed
 */
function abm_get_setting( $key, $default = '' ) {
	$settings = get_option( ABM_SETTINGS, array() );
	if ( is_array( $settings ) && isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
		return $settings[ $key ];
	}
	return $default;
}

/**
 * Whether the category tag should show for this event in the [abm_calendar]
 * list. Per-event meta abm_show_category ('show'|'hide') overrides the global
 * calendar_show_categories setting; '' (default) inherits the global value.
 *
 * @param int $post_id Event ID.
 * @return bool
 */
function abm_show_category_for( $post_id ) {
	$override = get_post_meta( $post_id, 'abm_show_category', true );
	if ( 'show' === $override ) {
		return true;
	}
	if ( 'hide' === $override ) {
		return false;
	}
	return (bool) abm_get_setting( 'calendar_show_categories', 1 );
}

/* -------------------------------------------------------------------------
 * Sanitization
 * ---------------------------------------------------------------------- */

/**
 * Validate a Y-m-d date string. Returns '' if invalid.
 *
 * @param mixed $val Raw value.
 * @return string
 */
function abm_sanitize_date( $val ) {
	$val = trim( (string) $val );
	if ( '' === $val ) {
		return '';
	}
	$d = DateTime::createFromFormat( 'Y-m-d', $val );
	if ( $d && $d->format( 'Y-m-d' ) === $val ) {
		return $val;
	}
	return '';
}

/**
 * Validate an H:i (24h) time string, or the literal 'close'. Returns '' if invalid.
 *
 * @param mixed $val Raw value.
 * @return string
 */
function abm_sanitize_time( $val ) {
	$val = trim( (string) $val );
	if ( '' === $val ) {
		return '';
	}
	if ( 'close' === strtolower( $val ) ) {
		return 'close';
	}
	if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $val ) ) {
		return $val;
	}
	$d = DateTime::createFromFormat( 'H:i', $val );
	if ( $d ) {
		return $d->format( 'H:i' );
	}
	return '';
}

/* -------------------------------------------------------------------------
 * Formatting (display strings stored as meta for Dynamic Content)
 * ---------------------------------------------------------------------- */

/**
 * Convert a Y-m-d string to a timestamp in the site timezone.
 *
 * @param string $ymd Date.
 * @return int Timestamp or 0.
 */
function abm_date_to_timestamp( $ymd ) {
	if ( empty( $ymd ) ) {
		return 0;
	}
	$d = DateTime::createFromFormat( 'Y-m-d', $ymd, wp_timezone() );
	if ( ! $d ) {
		return 0;
	}
	$d->setTime( 0, 0, 0 );
	return $d->getTimestamp();
}

/**
 * Format Y-m-d using a PHP date() format. Defaults to the global date_format
 * setting (falling back to "j M", e.g. "26 Jun").
 *
 * @param string $ymd    Date.
 * @param string $format Optional explicit PHP date format; overrides the setting.
 * @return string
 */
function abm_format_date( $ymd, $format = '' ) {
	$ts = abm_date_to_timestamp( $ymd );
	if ( ! $ts ) {
		return '';
	}
	if ( '' === $format ) {
		$format = abm_get_setting( 'date_format', 'j M' );
		if ( '' === $format ) {
			$format = 'j M';
		}
	}
	return date_i18n( $format, $ts );
}

/**
 * Format an H:i time as e.g. "5:00 PM".
 *
 * @param string $his Time.
 * @return string
 */
function abm_format_time( $his ) {
	if ( empty( $his ) || 'close' === $his ) {
		return '';
	}
	$d = DateTime::createFromFormat( 'H:i', $his, wp_timezone() );
	if ( ! $d ) {
		return '';
	}
	return $d->format( 'g:i A' );
}

/**
 * Build the human time range, e.g. "5:00 PM - 11:30 PM" or "8:00 PM - Close".
 *
 * @param string $start H:i start.
 * @param string $end   H:i end or 'close'.
 * @return string
 */
function abm_format_time_range( $start, $end ) {
	$start_f = abm_format_time( $start );
	if ( 'close' === $end ) {
		$end_f = __( 'Close', 'arkon-bar-manager' );
	} else {
		$end_f = abm_format_time( $end );
	}
	if ( $start_f && $end_f ) {
		return $start_f . ' - ' . $end_f;
	}
	return $start_f ? $start_f : $end_f;
}

/**
 * Format an event cost for display. A plain number gets the currency symbol
 * (e.g. "10" -> "$10", "7.5" -> "$7.50"); any other text passes through as
 * entered (e.g. "Free", "$5 / $10 door"). Empty returns ''.
 *
 * @param string $raw Raw cost value.
 * @return string
 */
function abm_format_cost( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	$symbol = abm_get_setting( 'currency_symbol', '$' );
	if ( is_numeric( $raw ) ) {
		$num = (float) $raw;
		$out = ( floor( $num ) === $num ) ? number_format( $num, 0 ) : number_format( $num, 2 );
		return $symbol . $out;
	}
	return $raw;
}

/* -------------------------------------------------------------------------
 * Calendar helpers
 * ---------------------------------------------------------------------- */

/**
 * Resolve concrete start/end DateTime objects (site timezone) for an event.
 * Handles "Close" (uses the default close time setting) and past-midnight rollover.
 *
 * @param string $ymd   Event date Y-m-d.
 * @param string $start H:i start.
 * @param string $end   H:i end or 'close'.
 * @return array{0:DateTime,1:DateTime}|false
 */
function abm_event_datetimes( $ymd, $start, $end, $clamp_to_start = false ) {
	$tz = wp_timezone();
	if ( empty( $ymd ) || empty( $start ) ) {
		return false;
	}

	$start_dt = DateTime::createFromFormat( 'Y-m-d H:i', $ymd . ' ' . $start, $tz );
	if ( ! $start_dt ) {
		return false;
	}

	if ( 'close' === $end || '' === $end ) {
		$close   = abm_sanitize_time( abm_get_setting( 'close_time', '02:00' ) );
		$close   = ( $close && 'close' !== $close ) ? $close : '02:00';
		$end_dt  = DateTime::createFromFormat( 'Y-m-d H:i', $ymd . ' ' . $close, $tz );
	} else {
		$end_dt = DateTime::createFromFormat( 'Y-m-d H:i', $ymd . ' ' . $end, $tz );
	}

	if ( ! $end_dt ) {
		$end_dt = clone $start_dt;
		$end_dt->modify( '+3 hours' );
	}

	// End at/before start means it rolls into the next day (e.g. 8 PM – 1 AM, or Close).
	if ( $end_dt <= $start_dt ) {
		$end_dt->modify( '+1 day' );
	}

	// "Only display start date": keep a past-midnight event on its start day in
	// calendar exports by clamping the end to 23:59 of the start date.
	if ( $clamp_to_start && $end_dt->format( 'Y-m-d' ) !== $start_dt->format( 'Y-m-d' ) ) {
		$end_dt = clone $start_dt;
		$end_dt->setTime( 23, 59, 0 );
	}

	return array( $start_dt, $end_dt );
}

/**
 * Whether calendar exports should clamp a past-midnight event to its start day.
 *
 * This only affects the iCal / Google Calendar span. The calendar listing always
 * shows an event on its start date, because an occurrence carries a date and a
 * time range rather than a start date and an end date, so a show running 8 PM to
 * 1 AM is one row on the 21st and can never split across two days.
 *
 * Defaults to OFF: if someone typed a real end time, the export should honour it.
 * Turn it on for an event whose end time is a placeholder or open-ended, where
 * claiming a specific finish in someone's calendar would be worse than stopping
 * at the end of the night.
 *
 * @param int $post_id Event ID.
 * @return bool
 */
function abm_event_clamp_enabled( $post_id ) {
	$val = get_post_meta( $post_id, 'abm_display_start_only', true );
	return ( '' === $val ) ? false : (bool) (int) $val;
}

/**
 * The venue location for calendar exports, or '' when unset.
 *
 * Name and address are separate settings but one field to a calendar app, so
 * they are joined with a comma rather than a space: "The Venue, 1 Example St"
 * geocodes, "The Venue 1 Example St" is a guess. Either part may be empty.
 *
 * @return string
 */
function abm_venue_location() {
	$parts = array_filter(
		array_map(
			'trim',
			array(
				(string) abm_get_setting( 'venue_name', '' ),
				(string) abm_get_setting( 'venue_address', '' ),
			)
		),
		'strlen'
	);

	return implode( ', ', $parts );
}

/**
 * The per-event iCal download URL (uses the /ical/ permalink endpoint).
 *
 * @param int $post_id Event ID.
 * @return string
 */
function abm_ical_url( $post_id ) {
	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return '';
	}
	return user_trailingslashit( trailingslashit( $permalink ) . ABM_ICAL_ENDPOINT );
}

/**
 * Build a Google Calendar "add event" link from the event's values.
 *
 * @param int    $post_id Event ID.
 * @param string $ymd     Date Y-m-d.
 * @param string $start   H:i start.
 * @param string $end     H:i end or 'close'.
 * @return string
 */
function abm_build_gcal_url( $post_id, $ymd, $start, $end ) {
	$dts = abm_event_datetimes( $ymd, $start, $end, abm_event_clamp_enabled( $post_id ) );
	if ( ! $dts ) {
		return '';
	}
	list( $s, $e ) = $dts;

	$utc = new DateTimeZone( 'UTC' );
	$s_utc = ( clone $s )->setTimezone( $utc );
	$e_utc = ( clone $e )->setTimezone( $utc );
	$dates = $s_utc->format( 'Ymd\THis\Z' ) . '/' . $e_utc->format( 'Ymd\THis\Z' );

	$details = wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $post_id ) );
	if ( '' === $details ) {
		$details = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 40, '' );
	}

	$cost = abm_format_cost( get_post_meta( $post_id, 'abm_event_cost', true ) );
	if ( '' !== $cost ) {
		/* translators: %s: cover charge. */
		$line    = sprintf( __( 'Cover: %s', 'arkon-bar-manager' ), $cost );
		// Joined with a space, not a blank line. A newline cannot survive the trip:
		// esc_url() strips %0d and %0a outright as a header-injection guard, so an
		// encoded line break is removed from any URL WordPress sanitizes and the two
		// sentences arrive run together as "Hop).Cover: $5". The .ics export keeps a
		// real blank line, because that payload never passes through a URL escaper.
		$details = '' !== $details ? $details . ' ' . $line : $line;
	}

	$location = abm_venue_location();

	$params = array(
		'action'   => 'TEMPLATE',
		'text'     => get_the_title( $post_id ),
		'details'  => $details,
		'location' => $location,
	);

	$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

	$url = 'https://calendar.google.com/calendar/render?' . $query . '&dates=' . $dates;

	/*
	 * Add the series as a recurring event where there is one, so a weekly night
	 * is saved once rather than every week.
	 *
	 * Google's TEMPLATE link takes a single "recur" line and nothing else: the
	 * RRULE only. Skipped nights cannot come with it, because EXDATE would have
	 * to be a second line and a URL cannot carry a line break at all -- esc_url()
	 * strips %0d and %0a outright as a header-injection guard, so the encoded
	 * break is removed and the two lines arrive spliced into one unreadable
	 * value. The .ics export is the faithful one and does carry the exceptions;
	 * this is the convenience link, and a recurring entry a few nights too
	 * generous is worth more than a single night. RDATE-only series -- dates with
	 * no pattern behind them -- get no recur at all rather than a wrong rule.
	 */
	$series = ABM_Recurrence::describe( $post_id, $ymd );
	if ( $series && '' !== $series['rrule'] ) {
		$url .= '&recur=' . rawurlencode( 'RRULE:' . $series['rrule'] );
	}

	return $url;
}

/**
 * Resolve the flyer URL for an event, falling back to the global placeholder.
 *
 * @param int $post_id      Event ID.
 * @param int $flyer_id     Flyer attachment ID (0 if none).
 * @param int $thumb_size   Optional registered image size for the URL.
 * @return string
 */
function abm_resolve_flyer_url( $post_id, $flyer_id, $size = 'large' ) {
	if ( $flyer_id ) {
		$url = wp_get_attachment_image_url( $flyer_id, $size );
		if ( $url ) {
			return $url;
		}
	}
	$placeholder_id = absint( abm_get_setting( 'placeholder_id', 0 ) );
	if ( $placeholder_id ) {
		$url = wp_get_attachment_image_url( $placeholder_id, $size );
		if ( $url ) {
			return $url;
		}
	}
	return '';
}
