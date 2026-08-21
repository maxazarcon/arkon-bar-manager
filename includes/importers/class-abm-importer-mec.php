<?php
/**
 * Modern Events Calendar (MEC) CSV importer.
 *
 * Expected columns: ID, Title, Description, Start Date, Start Time, End Date,
 * End Time, Link, Location, Address, Organizer, Organizer Tel, Organizer Email,
 * Event Cost, Featured Image, Labels, Categories, Tags.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Importer_MEC extends ABM_Importer {

	public function get_key() {
		return 'mec';
	}

	public function get_label() {
		return __( 'Modern Events Calendar (CSV)', 'arkon-bar-manager' );
	}

	/**
	 * Recognize a MEC export by its signature columns.
	 *
	 * @param array $header Header columns.
	 * @return bool
	 */
	public function matches( array $header ) {
		$h = array_map( 'strtolower', array_map( 'trim', $header ) );
		return in_array( 'start date', $h, true )
			&& in_array( 'start time', $h, true )
			&& in_array( 'featured image', $h, true );
	}

	/**
	 * @param array $row    Row values.
	 * @param array $header Header columns.
	 * @return array|null
	 */
	public function map_row( array $row, array $header ) {
		$title = $this->col( $row, $header, 'Title' );
		$date  = abm_sanitize_date( $this->col( $row, $header, 'Start Date' ) );
		if ( '' === $title || '' === $date ) {
			return null; // No title or unparseable date -> not importable.
		}

		$start_raw = $this->col( $row, $header, 'Start Time' );
		$all_day   = ( false !== stripos( $start_raw, 'all day' ) );
		$start     = $all_day ? '' : $this->parse_time( $start_raw );
		$end       = $this->parse_time( $this->col( $row, $header, 'End Time' ) );

		$categories = array_values(
			array_filter( array_map( 'trim', explode( ',', $this->col( $row, $header, 'Categories' ) ) ) )
		);

		$image_url = $this->col( $row, $header, 'Featured Image' );
		// Skip MEC's own placeholder so our global placeholder is used instead.
		if ( $image_url ) {
			$basename = strtolower( (string) wp_basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) ) );
			if ( false !== strpos( $basename, 'flyer-placeholder' ) ) {
				$image_url = '';
			}
		}

		return array(
			'title'      => $title,
			'content'    => $this->col( $row, $header, 'Description' ),
			'date'       => $date,
			'time_start' => $start,
			'time_end'   => $end,
			'cost'       => $this->col( $row, $header, 'Event Cost' ),
			'categories' => $categories,
			'image_url'  => $image_url,
			'slug'       => $this->slug_from_link( $this->col( $row, $header, 'Link' ) ),
			'source_id'  => $this->col( $row, $header, 'ID' ),
			'all_day'    => $all_day,
		);
	}

	/**
	 * Parse a MEC time string ("8:00 pm", "9:00 am") to H:i. Empty / "All Day"
	 * returns ''.
	 *
	 * @param string $raw Raw time.
	 * @return string
	 */
	private function parse_time( $raw ) {
		$raw = trim( $raw );
		if ( '' === $raw || false !== stripos( $raw, 'all day' ) ) {
			return '';
		}
		$norm = strtoupper( preg_replace( '/\s+/', ' ', $raw ) );
		foreach ( array( 'g:i A', 'g:iA', 'g A', 'H:i' ) as $fmt ) {
			$d = DateTime::createFromFormat( $fmt, $norm );
			if ( $d ) {
				return $d->format( 'H:i' );
			}
		}
		return '';
	}

	/**
	 * Reuse the event slug from a MEC permalink so existing URLs carry over
	 * (e.g. .../event-archive/jack-botts/ -> jack-botts). Only on-site archive
	 * links are used; external links (Eventbrite, etc.) are ignored.
	 *
	 * @param string $link Link URL.
	 * @return string
	 */
	private function slug_from_link( $link ) {
		if ( '' === $link || false === strpos( $link, '/event-archive/' ) ) {
			return '';
		}
		$path  = (string) wp_parse_url( $link, PHP_URL_PATH );
		$parts = array_values( array_filter( explode( '/', $path ) ) );
		$last  = end( $parts );
		return $last ? sanitize_title( $last ) : '';
	}
}
