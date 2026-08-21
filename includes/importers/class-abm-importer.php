<?php
/**
 * Importer framework. Each source calendar plugin registers a subclass; the
 * controller auto-detects the right one from the CSV header or uses the one the
 * user picked. A normalized event array (see map_row) decouples each source
 * format from how events are written.
 *
 * Normalized event array:
 *   title        string  (required)
 *   content      string  post content / description
 *   date         string  Y-m-d (required)
 *   time_start   string  H:i or '' (blank = all-day / unknown)
 *   time_end     string  H:i, 'close', or ''
 *   cost         string  raw door cost, e.g. "10", "Free" (optional)
 *   categories   array   category names
 *   image_url    string  remote flyer URL, or '' to use the global placeholder
 *   slug         string  desired post slug, or '' for the default
 *   source_id    string  source plugin's record id (dedupe key)
 *   all_day      bool
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

abstract class ABM_Importer {

	/** @var array<string,ABM_Importer> */
	protected static $registry = array();

	/** Machine key, e.g. "mec". */
	abstract public function get_key();

	/** Human label for the source dropdown. */
	abstract public function get_label();

	/**
	 * Does this importer recognize the given CSV header row?
	 *
	 * @param array $header Header columns.
	 * @return bool
	 */
	abstract public function matches( array $header );

	/**
	 * Map one CSV row to the normalized event array, or null to skip.
	 *
	 * @param array $row    Row values.
	 * @param array $header Header columns.
	 * @return array|null
	 */
	abstract public function map_row( array $row, array $header );

	/* ----------------------------------------------------------------- */

	public static function register( ABM_Importer $importer ) {
		self::$registry[ $importer->get_key() ] = $importer;
	}

	/** @return array<string,ABM_Importer> */
	public static function all() {
		return self::$registry;
	}

	/**
	 * @param string $key Importer key.
	 * @return ABM_Importer|null
	 */
	public static function get( $key ) {
		return self::$registry[ $key ] ?? null;
	}

	/**
	 * Find the first registered importer that recognizes this header.
	 *
	 * @param array $header Header columns.
	 * @return ABM_Importer|null
	 */
	public static function detect( array $header ) {
		foreach ( self::all() as $importer ) {
			if ( $importer->matches( $header ) ) {
				return $importer;
			}
		}
		return null;
	}

	/**
	 * Read a named column from a row (case-insensitive header match).
	 *
	 * @param array  $row    Row values.
	 * @param array  $header Header columns.
	 * @param string $name   Column name.
	 * @return string
	 */
	protected function col( array $row, array $header, $name ) {
		$idx = array_search( strtolower( $name ), array_map( 'strtolower', $header ), true );
		if ( false === $idx ) {
			return '';
		}
		return isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
	}
}
