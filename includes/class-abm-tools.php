<?php
/**
 * Tools screen: MEC database migration, occurrence rebuild, and a schema
 * diagnostic.
 *
 * The diagnostic exists because MEC's table layout has changed between versions
 * and this plugin has to run against whatever is actually installed. Rather than
 * guessing and failing quietly, the importer reports what it resolved and the
 * operator can see whether the mapping is right before writing anything.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Tools {

	/** @var ABM_Tools|null */
	private static $instance = null;

	const NONCE = 'abm_tools';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 12 );
		add_action( 'wp_ajax_abm_mec_import_batch', array( $this, 'ajax_import_batch' ) );
	}

	/**
	 * Import one slice of events.
	 *
	 * The whole migration does not fit in a single request: 337 events, each
	 * inserting a post, terms, a thumbnail, a dozen meta rows and its occurrence
	 * rows, ran past the request timeout on a real site. The writes had all landed
	 * but the response never came back, which is the worst of both worlds because
	 * the operator cannot tell a slow import from a dead one. Slicing it keeps
	 * every request short and lets the page show honest progress.
	 */
	public function ajax_import_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'arkon-bar-manager' ) ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$report = ABM_MEC_DB::import(
			array(
				'dry_run'                  => false,
				'update_existing'          => ! empty( $_POST['update_existing'] ),
				'allow_without_recurrence' => ! empty( $_POST['allow_no_recurrence'] ),
				'skip_before'              => sanitize_text_field( wp_unslash( $_POST['skip_before'] ?? '' ) ),
				'offset'                   => absint( wp_unslash( $_POST['offset'] ?? 0 ) ),
				'limit'                    => max( 1, min( 50, absint( wp_unslash( $_POST['batch'] ?? 20 ) ) ) ),
			)
		);

		wp_send_json_success( $report );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Migrate & Tools', 'arkon-bar-manager' ),
			__( 'Migrate & Tools', 'arkon-bar-manager' ),
			'manage_options',
			'abm-tools',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle a posted action and render the screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'arkon-bar-manager' ) );
		}

		$report  = null;
		$rebuilt = null;
		$action  = '';

		if ( isset( $_POST['abm_tools_action'] ) && check_admin_referer( self::NONCE, self::NONCE ) ) {
			$action = sanitize_key( wp_unslash( $_POST['abm_tools_action'] ) );

			if ( 'mec_dry' === $action || 'mec_run' === $action ) {
				$report = ABM_MEC_DB::import(
					array(
						'dry_run'         => ( 'mec_dry' === $action ),
						'update_existing'          => ! empty( $_POST['abm_update_existing'] ),
						'limit'                    => absint( wp_unslash( $_POST['abm_limit'] ?? 0 ) ),
						'allow_without_recurrence' => ! empty( $_POST['abm_allow_no_recurrence'] ),
						'skip_before'              => sanitize_text_field( wp_unslash( $_POST['abm_skip_before'] ?? '' ) ),
					)
				);
			} elseif ( 'rebuild' === $action ) {
				$rebuilt = ABM_Occurrences::rebuild_all();
			}
		}

		$map = ABM_MEC_DB::detect();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Migrate & Tools', 'arkon-bar-manager' ); ?></h1>

			<?php if ( null !== $rebuilt ) : ?>
				<div class="notice notice-success"><p>
					<?php
					printf(
						/* translators: 1: event count, 2: occurrence row count, 3: protected count. */
						esc_html__( 'Rebuilt occurrences for %1$s events, producing %2$s dates. %3$s imported events kept their original dates.', 'arkon-bar-manager' ),
						'<strong>' . esc_html( number_format_i18n( $rebuilt['events'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $rebuilt['rows'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $rebuilt['protected'] ?? 0 ) ) . '</strong>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<div class="notice notice-<?php echo $report['aborted'] ? 'error' : ( $report['usable'] ? 'success' : 'warning' ); ?>">
					<p>
						<strong>
						<?php
						if ( $report['aborted'] ) {
							esc_html_e( 'Import stopped. Nothing was written.', 'arkon-bar-manager' );
						} elseif ( $report['dry_run'] ) {
							esc_html_e( 'Preview only — nothing was written.', 'arkon-bar-manager' );
						} else {
							esc_html_e( 'Import complete.', 'arkon-bar-manager' );
						}
						?>
						</strong>
					</p>
					<p>
						<?php
						printf(
							/* translators: 1: events, 2: created, 3: updated, 4: skipped, 5: occurrences. */
							esc_html__( 'MEC events read: %1$s. Created: %2$s, updated: %3$s, skipped: %4$s. Occurrence dates: %5$s.', 'arkon-bar-manager' ),
							'<strong>' . esc_html( number_format_i18n( $report['events'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $report['created'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $report['updated'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $report['skipped'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $report['occurrences'] ) ) . '</strong>'
						);
						?>
					</p>
					<?php foreach ( $report['notes'] as $note ) : ?>
						<p><em><?php echo esc_html( $note ); ?></em></p>
					<?php endforeach; ?>

					<?php if ( ! empty( $report['top'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Events contributing the most dates:', 'arkon-bar-manager' ); ?></strong></p>
						<ol style="margin-left:20px">
						<?php foreach ( $report['top'] as $title => $n ) : ?>
							<li><?php echo esc_html( $title ); ?> — <?php echo esc_html( number_format_i18n( $n ) ); ?></li>
						<?php endforeach; ?>
						</ol>
						<p class="description"><?php esc_html_e( 'If a weekly event shows only one date here, its recurrence did not come through and the column mapping below needs a second look.', 'arkon-bar-manager' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Import from Modern Events Calendar', 'arkon-bar-manager' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Reads MEC\'s own tables in this WordPress install and copies every occurrence date exactly as MEC generated it, so a weekly event arrives with all of its dates rather than just the first. Featured images are reused in place, not re-downloaded. Each event keeps its old slug so existing links keep resolving.', 'arkon-bar-manager' ); ?>
			</p>

			<table class="widefat striped" style="max-width:46em;margin-bottom:16px">
				<tbody>
					<tr>
						<th style="width:16em"><?php esc_html_e( 'MEC events found', 'arkon-bar-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $map['post_count'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Occurrence rows found', 'arkon-bar-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $map['dates_rows'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schema recognized', 'arkon-bar-manager' ); ?></th>
						<td>
							<?php if ( $map['usable'] ) : ?>
								<span style="color:#007017">&#10003; <?php esc_html_e( 'Yes', 'arkon-bar-manager' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e">&#10007; <?php esc_html_e( 'No — see the diagnostic below', 'arkon-bar-manager' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Of those, upcoming', 'arkon-bar-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $map['future_rows'] ) ); ?>
							<span class="description"><?php esc_html_e( '(rows on published events dated today or later)', 'arkon-bar-manager' ); ?></span></td>
					</tr>
					<?php if ( ! empty( $map['status_published'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Row status values', 'arkon-bar-manager' ); ?></th>
						<td>
							<?php
							$parts = array();
							foreach ( $map['status_published'] as $st => $n ) {
								$parts[] = ( '' === $st ? '(empty)' : $st ) . ': ' . number_format_i18n( $n );
							}
							echo esc_html( implode( ' · ', $parts ) );
							?>
							<br /><span class="description"><?php esc_html_e( 'On published events only. Rows are imported unless explicitly trashed or cancelled.', 'arkon-bar-manager' ); ?></span>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Occurrences currently stored', 'arkon-bar-manager' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( ABM_Occurrences::count_all() ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<p>
					<label><input type="checkbox" name="abm_update_existing" value="1" checked /> <?php esc_html_e( 'Update events already imported from MEC', 'arkon-bar-manager' ); ?></label>
				</p>
				<?php if ( ! $map['usable'] ) : ?>
				<p style="padding:8px 12px;border-left:4px solid #dba617;background:#fcf9e8">
					<label>
						<input type="checkbox" name="abm_allow_no_recurrence" value="1" />
						<strong><?php esc_html_e( 'Import anyway, without recurrence', 'arkon-bar-manager' ); ?></strong>
					</label><br />
					<span class="description"><?php esc_html_e( 'The occurrence table was not recognized, so every repeating event would arrive with a single date. Leave this unticked and fix the mapping first unless you specifically want that.', 'arkon-bar-manager' ); ?></span>
				</p>
				<?php endif; ?>
				<p>
					<label><?php esc_html_e( 'Limit to first N events (0 = all, useful for a trial run)', 'arkon-bar-manager' ); ?>
						<input type="number" name="abm_limit" value="0" min="0" step="1" style="width:6em" />
					</label>
				</p>
				<p>
					<label><?php esc_html_e( 'Skip dates before', 'arkon-bar-manager' ); ?>
						<input type="date" name="abm_skip_before" value="<?php echo esc_attr( (string) wp_unslash( $_POST['abm_skip_before'] ?? '' ) ); ?>" />
					</label>
					<br /><span class="description"><?php esc_html_e( 'Leave empty to bring the full history across. A long-running weekly night can carry years of past dates that the calendar never shows; setting this keeps the occurrence table small without changing what visitors see.', 'arkon-bar-manager' ); ?></span>
				</p>
				<p>
					<button type="submit" name="abm_tools_action" value="mec_dry" class="button button-secondary"><?php esc_html_e( 'Preview (no changes)', 'arkon-bar-manager' ); ?></button>
					<button type="button" id="abm-run-import" class="button button-primary"><?php esc_html_e( 'Run import', 'arkon-bar-manager' ); ?></button>
				</p>

				<div id="abm-progress" style="display:none;max-width:46em">
					<p><progress id="abm-progress-bar" value="0" max="100" style="width:100%;height:22px"></progress></p>
					<p id="abm-progress-text" class="description"></p>
				</div>
			</form>

			<script>
			( function () {
				var btn = document.getElementById( 'abm-run-import' );
				if ( ! btn ) { return; }

				var wrap = document.getElementById( 'abm-progress' );
				var bar  = document.getElementById( 'abm-progress-bar' );
				var text = document.getElementById( 'abm-progress-text' );
				var form = btn.closest( 'form' );
				var BATCH = 20;

				function field( name ) {
					var el = form.querySelector( '[name="' + name + '"]' );
					if ( ! el ) { return ''; }
					return el.type === 'checkbox' ? ( el.checked ? '1' : '' ) : el.value;
				}

				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( <?php echo wp_json_encode( __( 'This creates events in this site from Modern Events Calendar. Run it now?', 'arkon-bar-manager' ) ); ?> ) ) { return; }

					btn.disabled = true;
					wrap.style.display = 'block';

					var offset = 0, created = 0, updated = 0, skipped = 0, dates = 0, total = 0;

					function step() {
						var body = new URLSearchParams();
						body.set( 'action', 'abm_mec_import_batch' );
						body.set( 'nonce', <?php echo wp_json_encode( wp_create_nonce( self::NONCE ) ); ?> );
						body.set( 'offset', offset );
						body.set( 'batch', BATCH );
						body.set( 'update_existing', field( 'abm_update_existing' ) );
						body.set( 'allow_no_recurrence', field( 'abm_allow_no_recurrence' ) );
						body.set( 'skip_before', field( 'abm_skip_before' ) );

						fetch( ajaxurl, {
							method: 'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body: body.toString(),
							credentials: 'same-origin'
						} )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) { throw new Error( 'bad response' ); }
							var d = res.data;

							if ( d.aborted ) {
								text.textContent = ( d.notes || [] ).join( ' ' );
								bar.removeAttribute( 'value' );
								btn.disabled = false;
								return;
							}

							created += d.created; updated += d.updated; skipped += d.skipped; dates += d.occurrences;
							total = d.total_events || total;
							offset = d.next_offset;

							bar.max = total || 1;
							bar.value = Math.min( offset, bar.max );
							text.textContent = offset + ' / ' + total + <?php echo wp_json_encode( ' ' . __( 'events', 'arkon-bar-manager' ) ); ?> +
								'  ·  ' + created + <?php echo wp_json_encode( ' ' . __( 'created', 'arkon-bar-manager' ) ); ?> +
								', ' + updated + <?php echo wp_json_encode( ' ' . __( 'updated', 'arkon-bar-manager' ) ); ?> +
								', ' + skipped + <?php echo wp_json_encode( ' ' . __( 'skipped', 'arkon-bar-manager' ) ); ?> +
								'  ·  ' + dates + <?php echo wp_json_encode( ' ' . __( 'dates', 'arkon-bar-manager' ) ); ?>;

							if ( d.done ) {
								text.textContent += <?php echo wp_json_encode( '  —  ' . __( 'finished. Reloading…', 'arkon-bar-manager' ) ); ?>;
								window.setTimeout( function () { window.location.reload(); }, 900 );
							} else {
								step();
							}
						} )
						.catch( function () {
							text.textContent = <?php echo wp_json_encode( __( 'A batch failed. Nothing after this point was imported; press Run import again to resume from where it stopped.', 'arkon-bar-manager' ) ); ?>;
							btn.disabled = false;
						} );
					}

					step();
				} );
			} )();
			</script>

			<hr />

			<h2><?php esc_html_e( 'Rebuild occurrences', 'arkon-bar-manager' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Regenerates the date rows for every event from its own recurrence rule. Imported events keep the dates copied from MEC unless you have given them a rule. Safe to run at any time.', 'arkon-bar-manager' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE, self::NONCE ); ?>
				<p><button type="submit" name="abm_tools_action" value="rebuild" class="button"><?php esc_html_e( 'Rebuild all occurrences', 'arkon-bar-manager' ); ?></button></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Schema diagnostic', 'arkon-bar-manager' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'What the importer detected in this install. If the import came back with no recurrence, copy this whole block when reporting it — it says exactly which columns were found and which ones were used.', 'arkon-bar-manager' ); ?>
			</p>
			<textarea class="widefat code" rows="18" readonly onclick="this.select()"><?php
				$diag = $map;
				$diag['sample_rows'] = ABM_MEC_DB::sample_rows( 3 );
				$diag['wp_version']  = get_bloginfo( 'version' );
				$diag['php_version'] = PHP_VERSION;
				echo esc_textarea( wp_json_encode( $diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			?></textarea>
		</div>
		<?php
	}
}
