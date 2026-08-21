<?php
/**
 * Import controller: the "Import" admin screen, secure CSV upload handling, and
 * writing normalized events (from any registered ABM_Importer) into abm_event
 * posts, meta, terms and flyers.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Import {

	/** @var ABM_Import|null */
	private static $instance = null;

	const NONCE       = 'abm_import_nonce';
	const MAX_BYTES   = 5242880; // 5 MB upload cap.
	const SOURCE_KEY  = 'abm_import_source';
	const SOURCE_ID   = 'abm_import_source_id';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		require_once ABM_DIR . 'includes/importers/class-abm-importer.php';
		require_once ABM_DIR . 'includes/importers/class-abm-importer-mec.php';
		ABM_Importer::register( new ABM_Importer_MEC() );

		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Import Events', 'arkon-bar-manager' ),
			__( 'Import', 'arkon-bar-manager' ),
			'manage_options',
			'abm-import',
			array( $this, 'render_page' )
		);
	}

	/* --------------------------------------------------------------------- */
	/* Admin page                                                            */
	/* --------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = null;
		if ( isset( $_POST['abm_import_submit'] ) ) {
			check_admin_referer( self::NONCE, self::NONCE );
			$report = $this->handle_upload();
		}

		// A completed (non-dry-run) import gets its own success screen.
		if ( is_array( $report ) && empty( $report['dry_run'] ) ) {
			$this->render_success( $report );
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Events', 'arkon-bar-manager' ); ?></h1>

			<?php if ( is_wp_error( $report ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $report ) ) : ?>
				<?php $this->render_report( $report ); ?>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abm_import_file"><?php esc_html_e( 'CSV File', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<input type="file" id="abm_import_file" name="abm_import_file" accept=".csv,text/csv" required />
							<p class="description"><?php esc_html_e( 'Export from your current calendar plugin and upload the .csv here.', 'arkon-bar-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abm_import_source"><?php esc_html_e( 'Source', 'arkon-bar-manager' ); ?></label></th>
						<td>
							<select id="abm_import_source" name="abm_import_source">
								<option value="auto"><?php esc_html_e( 'Auto-detect', 'arkon-bar-manager' ); ?></option>
								<?php foreach ( ABM_Importer::all() as $importer ) : ?>
									<option value="<?php echo esc_attr( $importer->get_key() ); ?>"><?php echo esc_html( $importer->get_label() ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'arkon-bar-manager' ); ?></th>
						<td>
							<label><input type="radio" name="abm_status" value="publish" checked /> <?php esc_html_e( 'Publish', 'arkon-bar-manager' ); ?></label>
							&nbsp;&nbsp;
							<label><input type="radio" name="abm_status" value="draft" /> <?php esc_html_e( 'Draft', 'arkon-bar-manager' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'arkon-bar-manager' ); ?></th>
						<td>
							<label><input type="checkbox" name="abm_reuse_existing" value="1" checked /> <?php esc_html_e( 'Reuse images already in this site’s Media Library (match by URL / filename instead of duplicating) — best when moving platforms on the same site', 'arkon-bar-manager' ); ?></label><br />
							<label><input type="checkbox" name="abm_import_images" value="1" checked /> <?php esc_html_e( 'Otherwise download the flyer image into the Media Library', 'arkon-bar-manager' ); ?></label><br />
							<label><input type="checkbox" name="abm_update_existing" value="1" checked /> <?php esc_html_e( 'Update events already imported from the same source when their details changed (identical events are skipped)', 'arkon-bar-manager' ); ?></label><br />
							<label><input type="checkbox" name="abm_dry_run" value="1" /> <?php esc_html_e( 'Dry run (preview counts only, import nothing)', 'arkon-bar-manager' ); ?></label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import', 'arkon-bar-manager' ), 'primary', 'abm_import_submit' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array $report Result counters + messages.
	 */
	private function render_report( array $report ) {
		$class = empty( $report['errors'] ) ? 'notice-success' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
			<p>
				<strong><?php echo $report['dry_run'] ? esc_html__( 'Dry run complete.', 'arkon-bar-manager' ) : esc_html__( 'Import complete.', 'arkon-bar-manager' ); ?></strong>
				<?php
				printf(
					/* translators: source label, created, updated, unchanged, skipped, rows */
					esc_html__( 'Source: %1$s. Created: %2$d, Updated: %3$d, Unchanged: %4$d, Skipped: %5$d, of %6$d rows.', 'arkon-bar-manager' ),
					esc_html( $report['source'] ),
					(int) $report['created'],
					(int) $report['updated'],
					(int) $report['unchanged'],
					(int) $report['skipped'],
					(int) $report['rows']
				);
				?>
			</p>
			<p>
				<?php
				if ( $report['dry_run'] ) {
					printf(
						/* translators: 1: images to reuse, 2: images to download */
						esc_html__( 'Flyers: %1$d to reuse, %2$d to download.', 'arkon-bar-manager' ),
						(int) $report['images_reused'],
						(int) $report['images_downloaded']
					);
				} else {
					printf(
						/* translators: 1: images reused, 2: images downloaded */
						esc_html__( 'Flyers: %1$d reused, %2$d downloaded.', 'arkon-bar-manager' ),
						(int) $report['images_reused'],
						(int) $report['images_downloaded']
					);
				}
				?>
			</p>
			<?php if ( ! empty( $report['errors'] ) ) : ?>
				<ul style="list-style:disc;margin-left:20px;">
					<?php foreach ( array_slice( $report['errors'], 0, 25 ) as $msg ) : ?>
						<li><?php echo esc_html( $msg ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Dedicated success screen shown after a completed (non-dry-run) import.
	 *
	 * @param array $report Result counters.
	 */
	private function render_success( array $report ) {
		$import_url = admin_url( 'edit.php?post_type=' . ABM_POST_TYPE . '&page=abm-import' );
		$events_url = admin_url( 'edit.php?post_type=' . ABM_POST_TYPE );
		$processed  = (int) $report['created'] + (int) $report['updated'];

		$stats = array(
			__( 'Created', 'arkon-bar-manager' )           => (int) $report['created'],
			__( 'Updated', 'arkon-bar-manager' )           => (int) $report['updated'],
			__( 'Unchanged', 'arkon-bar-manager' )         => (int) $report['unchanged'],
			__( 'Skipped', 'arkon-bar-manager' )           => (int) $report['skipped'],
			__( 'Flyers reused', 'arkon-bar-manager' )     => (int) $report['images_reused'],
			__( 'Flyers downloaded', 'arkon-bar-manager' ) => (int) $report['images_downloaded'],
			__( 'Rows read', 'arkon-bar-manager' )         => (int) $report['rows'],
		);
		?>
		<div class="wrap abm-import-success">
			<style>
				.abm-import-success .abm-success-head { display:flex; align-items:center; gap:10px; }
				.abm-import-success .abm-success-head .dashicons { color:#46b450; font-size:34px; width:34px; height:34px; }
				.abm-import-success .abm-stats { display:flex; flex-wrap:wrap; gap:14px; margin:22px 0; }
				.abm-import-success .abm-stat { flex:1 1 140px; min-width:140px; background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:16px; text-align:center; }
				.abm-import-success .abm-stat .abm-num { display:block; font-size:30px; font-weight:600; line-height:1.1; }
				.abm-import-success .abm-stat .abm-label { display:block; margin-top:6px; color:#646970; }
				.abm-import-success .abm-actions .button { margin-right:8px; }
			</style>

			<h1 class="abm-success-head">
				<span class="dashicons dashicons-yes-alt"></span>
				<?php esc_html_e( 'Import Complete', 'arkon-bar-manager' ); ?>
			</h1>

			<p>
				<?php
				printf(
					/* translators: 1: number of events, 2: source label */
					esc_html__( '%1$d events imported from %2$s.', 'arkon-bar-manager' ),
					$processed,
					esc_html( $report['source'] )
				);
				?>
			</p>

			<div class="abm-stats">
				<?php foreach ( $stats as $label => $value ) : ?>
					<div class="abm-stat">
						<span class="abm-num"><?php echo (int) $value; ?></span>
						<span class="abm-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $report['errors'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Some rows were skipped:', 'arkon-bar-manager' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( array_slice( $report['errors'], 0, 25 ) as $msg ) : ?>
							<li><?php echo esc_html( $msg ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p class="abm-actions">
				<a class="button button-primary" href="<?php echo esc_url( $events_url ); ?>"><?php esc_html_e( 'View Events', 'arkon-bar-manager' ); ?></a>
				<a class="button" href="<?php echo esc_url( $import_url ); ?>"><?php esc_html_e( 'Import Another File', 'arkon-bar-manager' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Upload handling                                                       */
	/* --------------------------------------------------------------------- */

	/**
	 * Validate + parse the upload, then import each row.
	 *
	 * @return array|WP_Error
	 */
	private function handle_upload() {
		if ( empty( $_FILES['abm_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['abm_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'abm_no_file', __( 'No file was uploaded.', 'arkon-bar-manager' ) );
		}
		$file = $_FILES['abm_import_file'];

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'abm_upload_error', __( 'The file failed to upload. Try again.', 'arkon-bar-manager' ) );
		}
		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'abm_too_big', __( 'That file is larger than the 5 MB limit.', 'arkon-bar-manager' ) );
		}

		// Require a .csv extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			return new WP_Error( 'abm_not_csv', __( 'Please upload a .csv file.', 'arkon-bar-manager' ) );
		}

		$handle = fopen( $file['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading an uploaded temp file.
		if ( ! $handle ) {
			return new WP_Error( 'abm_unreadable', __( 'The file could not be read.', 'arkon-bar-manager' ) );
		}

		// MEC / Excel exports may be tab- or semicolon-delimited and carry a BOM.
		$delimiter = $this->detect_delimiter( $handle );

		$header = fgetcsv( $handle, 0, $delimiter );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'abm_empty', __( 'The file appears to be empty.', 'arkon-bar-manager' ) );
		}
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] ); // Strip UTF-8 BOM.
		}
		$header = array_map( 'trim', $header );

		// Resolve importer.
		$source = isset( $_POST['abm_import_source'] ) ? sanitize_key( wp_unslash( $_POST['abm_import_source'] ) ) : 'auto';
		$importer = ( 'auto' === $source ) ? ABM_Importer::detect( $header ) : ABM_Importer::get( $source );
		if ( ! $importer || ( 'auto' !== $source && ! $importer->matches( $header ) ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'abm_no_importer', __( 'Could not match this file to a known calendar format.', 'arkon-bar-manager' ) );
		}

		$opts = array(
			'status'          => ( isset( $_POST['abm_status'] ) && 'draft' === $_POST['abm_status'] ) ? 'draft' : 'publish',
			'reuse_existing'  => ! empty( $_POST['abm_reuse_existing'] ),
			'import_images'   => ! empty( $_POST['abm_import_images'] ),
			'update_existing' => ! empty( $_POST['abm_update_existing'] ),
			'dry_run'         => ! empty( $_POST['abm_dry_run'] ),
		);

		if ( $opts['import_images'] && ! $opts['dry_run'] ) {
			@set_time_limit( 0 ); // phpcs:ignore -- long image sideloads.
		}

		$report = array(
			'source'             => $importer->get_label(),
			'rows'               => 0,
			'created'            => 0,
			'updated'            => 0,
			'unchanged'          => 0,
			'skipped'            => 0,
			'images_reused'      => 0,
			'images_downloaded'  => 0,
			'errors'             => array(),
			'dry_run'            => $opts['dry_run'],
		);

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter ) ) ) {
			if ( array( null ) === $row ) {
				continue; // Blank line.
			}
			$report['rows']++;

			$event = $importer->map_row( $row, $header );
			if ( null === $event ) {
				$report['skipped']++;
				continue;
			}

			$result = $this->import_event( $event, $importer->get_key(), $opts );
			if ( is_wp_error( $result ) ) {
				$report['skipped']++;
				$report['errors'][] = sprintf( '%s: %s', $event['title'], $result->get_error_message() );
			} else {
				$report[ $result['outcome'] ]++; // 'created' | 'updated' | 'skipped'
				if ( 'reuse' === $result['image'] ) {
					$report['images_reused']++;
				} elseif ( 'download' === $result['image'] ) {
					$report['images_downloaded']++;
				}
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $report;
	}

	/**
	 * Sniff the field delimiter from the first line (comma, tab, semicolon or
	 * pipe), then rewind. Defaults to comma.
	 *
	 * @param resource $handle Open file handle.
	 * @return string
	 */
	private function detect_delimiter( $handle ) {
		$line = fgets( $handle );
		rewind( $handle );
		if ( false === $line ) {
			return ',';
		}
		$line = preg_replace( '/^\xEF\xBB\xBF/', '', $line ); // Ignore BOM when counting.

		$counts = array();
		foreach ( array( ',', "\t", ';', '|' ) as $d ) {
			$counts[ $d ] = substr_count( $line, $d );
		}
		arsort( $counts );
		$best = key( $counts );
		return $counts[ $best ] > 0 ? $best : ',';
	}

	/**
	 * Create or update a single event from a normalized array.
	 *
	 * @param array  $event Normalized event.
	 * @param string $key   Importer key (source).
	 * @param array  $opts  Import options.
	 * @return array|WP_Error { outcome: created|updated|skipped, image: reuse|download|none }, or error.
	 */
	private function import_event( array $event, $key, array $opts ) {
		$existing = $this->find_existing( $key, $event['source_id'] );

		if ( $existing && ! $opts['update_existing'] ) {
			return array(
				'outcome' => 'skipped',
				'image'   => 'none',
			);
		}

		// Decide image disposition up front. This is read-only (a Media Library
		// lookup, no download), so it is accurate during a dry run too.
		$has_flyer = $existing ? has_post_thumbnail( $existing ) : false;
		$match_id  = 0;
		$image     = 'none';
		if ( ! $has_flyer && ! empty( $event['image_url'] ) ) {
			if ( $opts['reuse_existing'] ) {
				$match_id = $this->find_existing_attachment( $event['image_url'] );
			}
			if ( $match_id ) {
				$image = 'reuse';
			} elseif ( $opts['import_images'] ) {
				$image = 'download';
			}
		}

		// An existing event whose details are identical is left untouched.
		$unchanged = $existing && $this->is_unchanged( $existing, $event, $image );

		if ( $opts['dry_run'] ) {
			if ( $unchanged ) {
				return array(
					'outcome' => 'unchanged',
					'image'   => 'none',
				);
			}
			return array(
				'outcome' => $existing ? 'updated' : 'created',
				'image'   => $image,
			);
		}

		if ( $unchanged ) {
			return array(
				'outcome' => 'unchanged',
				'image'   => 'none',
			);
		}

		$postarr = array(
			'post_type'    => ABM_POST_TYPE,
			'post_status'  => $opts['status'],
			'post_title'   => $event['title'],
			'post_content' => $event['content'],
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
			$created       = false;
		} else {
			if ( ! empty( $event['slug'] ) ) {
				$postarr['post_name'] = $event['slug'];
			}
			$post_id = wp_insert_post( $postarr, true );
			$created = true;
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Categories (auto-creates missing terms).
		if ( ! empty( $event['categories'] ) ) {
			wp_set_object_terms( $post_id, $event['categories'], ABM_TAXONOMY, false );
		}

		// Flyer: apply the disposition decided above. The flyer is the post's
		// Featured Image, so set the thumbnail; abm_flyer_id mirrors it.
		$flyer_id = $has_flyer ? (int) get_post_thumbnail_id( $post_id ) : 0;
		if ( ! $flyer_id ) {
			if ( 'reuse' === $image && $match_id ) {
				$flyer_id = $match_id;
			} elseif ( 'download' === $image ) {
				$sideloaded = $this->sideload_image( $event['image_url'], $post_id, $event['title'] );
				if ( is_wp_error( $sideloaded ) ) {
					$image = 'none'; // Download failed; don't count it.
				} else {
					$flyer_id = $sideloaded;
				}
			}
			if ( $flyer_id ) {
				set_post_thumbnail( $post_id, $flyer_id );
			}
		}

		$this->write_meta( $post_id, $event, $flyer_id, $key );

		return array(
			'outcome' => $created ? 'created' : 'updated',
			'image'   => $image,
		);
	}

	/**
	 * Whether an existing event already matches the incoming data exactly, so the
	 * import can skip it instead of re-saving. Compares title, content, date,
	 * times, cost and categories; a flyer that would be added (image !== 'none')
	 * counts as a change. Flyers already set are never overwritten, so they do
	 * not affect the comparison.
	 *
	 * @param int    $existing_id Existing event ID.
	 * @param array  $event       Normalized incoming event.
	 * @param string $image       Image disposition: reuse|download|none.
	 * @return bool
	 */
	private function is_unchanged( $existing_id, array $event, $image ) {
		if ( 'none' !== $image ) {
			return false; // A flyer would be added.
		}

		if ( (string) get_post_field( 'post_title', $existing_id ) !== (string) $event['title'] ) {
			return false;
		}
		if ( (string) get_post_field( 'post_content', $existing_id ) !== (string) $event['content'] ) {
			return false;
		}

		$meta = array(
			'abm_event_date'       => (string) $event['date'],
			'abm_event_time_start' => (string) $event['time_start'],
			'abm_event_time_end'   => (string) $event['time_end'],
			'abm_event_cost'       => isset( $event['cost'] ) ? sanitize_text_field( $event['cost'] ) : '',
		);
		foreach ( $meta as $key => $value ) {
			if ( (string) get_post_meta( $existing_id, $key, true ) !== $value ) {
				return false;
			}
		}

		// Categories compared as sets.
		$current = wp_get_object_terms( $existing_id, ABM_TAXONOMY, array( 'fields' => 'names' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'strval', $current );
		$incoming = isset( $event['categories'] ) ? array_map( 'strval', $event['categories'] ) : array();
		sort( $current, SORT_STRING );
		sort( $incoming, SORT_STRING );
		if ( $current !== $incoming ) {
			return false;
		}

		return true;
	}

	/**
	 * Write all abm_ meta (raw + derived display / calendar values), mirroring
	 * the editor save path so imported events behave identically on the frontend.
	 *
	 * @param int    $post_id  Event ID.
	 * @param array  $event    Normalized event.
	 * @param int    $flyer_id Flyer attachment ID (0 if none).
	 * @param string $key      Importer key.
	 */
	private function write_meta( $post_id, array $event, $flyer_id, $key ) {
		$date  = $event['date'];
		$start = $event['time_start'];
		$end   = $event['time_end'];
		$cost  = isset( $event['cost'] ) ? sanitize_text_field( $event['cost'] ) : '';

		update_post_meta( $post_id, 'abm_event_date', $date );
		update_post_meta( $post_id, 'abm_event_time_start', $start );
		update_post_meta( $post_id, 'abm_event_time_end', $end );
		update_post_meta( $post_id, 'abm_event_cost', $cost );
		update_post_meta( $post_id, 'abm_flyer_id', $flyer_id );
		// Clamp exports only where there is no real end time to honour, matching
		// the database importer. Forcing this on truncated a genuine 8 PM - 1 AM
		// show at 11:59 PM in every export it produced.
		update_post_meta( $post_id, 'abm_display_start_only', ( '' === $end ) ? 1 : 0 );

		update_post_meta( $post_id, 'abm_date_display', abm_format_date( $date ) );
		update_post_meta( $post_id, 'abm_time_display', abm_format_time_range( $start, $end ) );
		update_post_meta( $post_id, 'abm_cost_display', abm_format_cost( $cost ) );
		update_post_meta( $post_id, 'abm_flyer_url', abm_resolve_flyer_url( $post_id, $flyer_id ) );
		update_post_meta( $post_id, 'abm_ical', abm_ical_url( $post_id ) );
		update_post_meta( $post_id, 'abm_gcal', abm_build_gcal_url( $post_id, $date, $start, $end ) );

		// Provenance for dedupe on re-import.
		update_post_meta( $post_id, self::SOURCE_KEY, $key );
		update_post_meta( $post_id, self::SOURCE_ID, (string) $event['source_id'] );

		// Record the source slug so /event-archive/<slug>/ keeps resolving even if
		// this event is renamed later. The redirect map is driven by this meta;
		// without it the lookup only works for as long as the post slug happens to
		// still match the old one.
		if ( ! empty( $event['slug'] ) ) {
			update_post_meta( $post_id, ABM_Legacy_URLs::SLUG_META, $event['slug'] );
		}
	}

	/**
	 * Find an already-imported event by source + source id.
	 *
	 * @param string $key       Importer key.
	 * @param string $source_id Source record id.
	 * @return int Post ID or 0.
	 */
	private function find_existing( $key, $source_id ) {
		if ( '' === (string) $source_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => ABM_POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => self::SOURCE_KEY,
						'value' => $key,
					),
					array(
						'key'   => self::SOURCE_ID,
						'value' => (string) $source_id,
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Resolve an image URL to an attachment already in this site's Media Library,
	 * so a same-site migration reuses existing flyers instead of duplicating them.
	 * Tries, in order: exact URL, the URL normalized onto the local uploads base,
	 * then the filename (which tolerates differing CDN hosts / paths).
	 *
	 * @param string $url Source image URL.
	 * @return int Attachment ID, or 0 if no match.
	 */
	private function find_existing_attachment( $url ) {
		// 1. Exact URL within this site's uploads.
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			return (int) $id;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// 2. Rewrite onto the local uploads base URL, then resolve.
		if ( $path && preg_match( '#/uploads/(.+)$#', $path, $m ) ) {
			$uploads   = wp_upload_dir();
			$local_url = trailingslashit( $uploads['baseurl'] ) . ltrim( $m[1], '/' );
			$id        = attachment_url_to_postid( $local_url );
			if ( $id ) {
				return (int) $id;
			}
		}

		// 3. Match by filename against _wp_attached_file (host/path agnostic).
		return $this->attachment_id_by_filename( wp_basename( $path ? $path : $url ) );
	}

	/**
	 * Find an attachment by its file basename, tolerating a resized-size suffix
	 * (e.g. name-1024x768.jpg matches the original name.jpg).
	 *
	 * @param string $basename File name.
	 * @return int Attachment ID, or 0.
	 */
	private function attachment_id_by_filename( $basename ) {
		global $wpdb;
		$basename = trim( $basename );
		if ( '' === $basename ) {
			return 0;
		}

		$candidates = array( $basename );
		// Strip a WordPress size suffix to also match the source image.
		$stripped = preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $basename );
		if ( $stripped !== $basename ) {
			$candidates[] = $stripped;
		}

		foreach ( $candidates as $name ) {
			$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted attachment lookup; no caching needed for a one-off import.
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = '_wp_attached_file'
					   AND ( meta_value = %s OR meta_value LIKE %s )
					 ORDER BY post_id DESC LIMIT 1",
					$name,
					'%/' . $wpdb->esc_like( $name )
				)
			);
			if ( $id ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Download a remote image into the Media Library and attach it.
	 *
	 * @param string $url     Image URL (http/https only).
	 * @param int    $post_id Parent event.
	 * @param string $title   Alt/title.
	 * @return int|WP_Error Attachment ID.
	 */
	private function sideload_image( $url, $post_id, $title ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'abm_bad_url', __( 'Skipped non-HTTP image URL.', 'arkon-bar-manager' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = array(
			'name'     => wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore -- clean up temp download on failure.
			return $attachment_id;
		}
		return (int) $attachment_id;
	}
}
