<?php
/**
 * Bulk Add screen.
 *
 * Three ways to get a month of shows in, converging on one editable table.
 *
 *   Paste   a booking list as prose, parsed by ABM_Bulk_Parser
 *   CSV     a spreadsheet export with plain column names
 *   Rows    typed straight in, a row at a time
 *
 * The table is the same in all three cases, and nothing is written until it is
 * submitted. That is what makes the paste parser worth having: it is allowed to
 * guess, because every guess is shown for correction before it becomes an event.
 * The table is also the third input mode on its own, so there is no separate
 * "manual" screen to build or maintain.
 *
 * Deliberately not the Import screen. That one exists to migrate a calendar from
 * another plugin: it matches on a source ID, decides whether to update or skip
 * what it finds, and is answerable to data it did not create. This one authors
 * new events and has no source to reconcile with. Folding the two together would
 * mean one screen with two sets of rules.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Bulk {

	/** @var ABM_Bulk|null */
	private static $instance = null;

	const SLUG = 'abm-bulk';

	/** Blank rows offered when the screen is opened cold. */
	const BLANK_ROWS = 3;

	/** Most rows one submission may create. */
	const MAX_ROWS = 200;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 11: after Settings (10), before Migrate & Tools (12), so the
		// authoring screens sit together above the migration ones.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Bulk Add Events', 'arkon-bar-manager' ),
			__( 'Bulk Add', 'arkon-bar-manager' ),
			'edit_posts',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook Current admin page.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		// wp.media powers the per-row flyer picker.
		wp_enqueue_media();
		wp_enqueue_style( 'abm-admin', ABM_URL . 'assets/admin.css', array(), ABM_VERSION );
		wp_enqueue_script( 'abm-bulk', ABM_URL . 'assets/bulk.js', array( 'jquery' ), ABM_VERSION, true );
		wp_localize_script(
			'abm-bulk',
			'ABM_BULK',
			array(
				'chooseFlyer' => __( 'Choose flyer', 'arkon-bar-manager' ),
				'useFlyer'    => __( 'Use as flyer', 'arkon-bar-manager' ),
				'remove'      => __( 'Remove', 'arkon-bar-manager' ),
			)
		);
	}

	/* --------------------------------------------------------------------- */
	/* Screen                                                                */
	/* --------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'arkon-bar-manager' ) );
		}

		$rows    = array();
		$skipped = array();
		$actions  = array();
		$report   = null;
		$matching = null;
		$action   = isset( $_POST['abm_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['abm_bulk_action'] ) ) : '';

		if ( '' !== $action ) {
			check_admin_referer( 'abm_bulk' );
		}

		// The table posts as "create"; its Match flyers button carries its own
		// field, so pressing it re-matches instead of writing anything.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( 'create' === $action && ! empty( $_POST['abm_match_flyers'] ) ) {
			$action = 'match_flyers';
		}

		switch ( $action ) {
			case 'parse_paste':
				$text    = isset( $_POST['abm_paste'] ) ? (string) wp_unslash( $_POST['abm_paste'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed field by field below.
				$parsed  = ABM_Bulk_Parser::parse( $text, current_time( 'Y-m-d' ) );
				$rows    = $parsed['rows'];
				$skipped = $parsed['skipped'];
				$actions = $parsed['actions'];
				break;

			case 'parse_csv':
				$parsed  = $this->read_csv();
				$rows    = $parsed['rows'];
				$skipped = $parsed['skipped'];
				break;

			case 'match_flyers':
				$rows = $this->posted_rows();
				break;

			case 'create':
				$rows   = $this->posted_rows();
				$report = $this->create( $rows );
				$rows   = $report['remaining'];
				break;
		}

		// Pair rows with artwork already in the Media Library. Runs after a parse
		// and on demand, but never after a create -- the rows left over there are
		// failures being handed back, and re-matching them would bury the reason
		// under a fresh set of notes.
		if ( in_array( $action, array( 'parse_paste', 'parse_csv', 'match_flyers' ), true ) && $rows ) {
			$flyers   = $this->match_flyers( $rows );
			$rows     = $flyers['rows'];
			$matching = $flyers;
		}

		if ( ! $rows && ! $report ) {
			$rows = array_fill( 0, self::BLANK_ROWS, $this->blank_row() );
		}
		?>
		<div class="wrap abm-bulk">
			<h1><?php esc_html_e( 'Bulk Add Events', 'arkon-bar-manager' ); ?></h1>

			<?php
			if ( $report ) {
				$this->render_report( $report );
			}
			if ( $matching && ( $matching['matched'] || $matching['ambiguous'] ) ) {
				?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: 1: flyers attached, 2: dates with more than one flyer. */
							esc_html__( 'Matched %1$d flyers from the Media Library by date. %2$d dates have more than one flyer and were left for you to choose.', 'arkon-bar-manager' ),
							(int) $matching['matched'],
							(int) $matching['ambiguous']
						);
						?>
					</p>
				</div>
				<?php
			}
			if ( $actions ) {
				$this->render_actions( $actions );
			}
			if ( $skipped ) {
				$this->render_skipped( $skipped );
			}
			?>

			<p class="description" style="max-width:52em">
				<?php esc_html_e( 'Paste a booking list, upload a spreadsheet, or type rows straight in. Nothing is saved until you press Create, and every row can be corrected first.', 'arkon-bar-manager' ); ?>
			</p>

			<?php $this->render_inputs(); ?>

			<form method="post">
				<?php wp_nonce_field( 'abm_bulk' ); ?>
				<input type="hidden" name="abm_bulk_action" value="create" />
				<?php $this->render_table( $rows ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The paste box and the CSV upload. Separate forms from the table, so that
	 * parsing a list never carries a half-filled table along with it.
	 */
	private function render_inputs() {
		?>
		<div class="abm-bulk-inputs">
			<form method="post" class="abm-bulk-input">
				<?php wp_nonce_field( 'abm_bulk' ); ?>
				<input type="hidden" name="abm_bulk_action" value="parse_paste" />
				<h2><?php esc_html_e( 'Paste a list', 'arkon-bar-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'One event per line, in whatever order. The date and the time are picked out and the rest becomes the title.', 'arkon-bar-manager' ); ?>
				</p>
				<textarea name="abm_paste" rows="8" class="large-text code" placeholder="<?php echo esc_attr( "Sep 4 - Old Codger, Pickled, Scraps - 8pm\nSep 5 | The Blusterfields | 9pm-close | \$5\n9/11  Trivia Night  8pm  free" ); ?>"></textarea>
				<p><button type="submit" class="button"><?php esc_html_e( 'Read the list', 'arkon-bar-manager' ); ?></button></p>
			</form>

			<form method="post" class="abm-bulk-input" enctype="multipart/form-data">
				<?php wp_nonce_field( 'abm_bulk' ); ?>
				<input type="hidden" name="abm_bulk_action" value="parse_csv" />
				<h2><?php esc_html_e( 'Or upload a spreadsheet', 'arkon-bar-manager' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: list of accepted CSV column names. */
						esc_html__( 'A CSV with a header row. Recognised columns: %s. Anything missing is simply left blank.', 'arkon-bar-manager' ),
						'<code>title, date, start, end, cost, category, description</code>'
					);
					?>
				</p>
				<input type="file" name="abm_csv" accept=".csv,text/csv" />
				<p><button type="submit" class="button"><?php esc_html_e( 'Read the file', 'arkon-bar-manager' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * The editable table. This is the preview, the manual entry mode and the
	 * thing that gets submitted, all at once.
	 *
	 * @param array $rows Rows.
	 */
	private function render_table( array $rows ) {
		$terms = get_terms(
			array(
				'taxonomy'   => ABM_TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		?>
		<h2><?php esc_html_e( 'Events to create', 'arkon-bar-manager' ); ?></h2>

		<table class="widefat striped abm-bulk-table">
			<thead>
				<tr>
					<th class="abm-col-date"><?php esc_html_e( 'Date', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-time"><?php esc_html_e( 'Start', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-time"><?php esc_html_e( 'End', 'arkon-bar-manager' ); ?></th>
					<th><?php esc_html_e( 'Title', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-cost"><?php esc_html_e( 'Cover', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-cat"><?php esc_html_e( 'Category', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-flyer"><?php esc_html_e( 'Flyer', 'arkon-bar-manager' ); ?></th>
					<th class="abm-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'arkon-bar-manager' ); ?></span></th>
				</tr>
			</thead>
			<tbody id="abm-bulk-rows">
				<?php
				$i = 0;
				foreach ( $rows as $row ) {
					$this->render_row( $i, $row, $terms );
					++$i;
				}
				?>
			</tbody>
		</table>

		<?php // The template JS clones for a new row. Kept out of the form so its
			// inputs are never submitted; the script renames __i__ on insert. ?>
		<script type="text/html" id="tmpl-abm-bulk-row">
			<?php $this->render_row( '__i__', $this->blank_row(), $terms ); ?>
		</script>

		<p class="abm-bulk-actions">
			<button type="button" class="button" id="abm-bulk-add"><?php esc_html_e( 'Add row', 'arkon-bar-manager' ); ?></button>
			<?php
			/*
			 * Re-pairs rows with artwork after the dates have been corrected, or
			 * once the folder has been uploaded.
			 *
			 * Its own field name rather than a second abm_bulk_action: two inputs
			 * of one name would leave the outcome resting on which appears last in
			 * the markup, and the hidden "create" has to stay first so that Enter
			 * in a table cell still submits the table.
			 */
			?>
			<button type="submit" class="button" name="abm_match_flyers" value="1">
				<?php esc_html_e( 'Match flyers by date', 'arkon-bar-manager' ); ?>
			</button>
			<span class="description">
				<?php esc_html_e( 'Upload the flyer folder to the Media Library first. Filenames beginning with the date, like 8-29.jpg, are paired with the matching row.', 'arkon-bar-manager' ); ?>
			</span>
			<span class="abm-bulk-status" aria-live="polite"></span>
		</p>

		<p>
			<label for="abm-bulk-status-select"><?php esc_html_e( 'Create as', 'arkon-bar-manager' ); ?></label>
			<select name="abm_status" id="abm-bulk-status-select">
				<option value="publish"><?php esc_html_e( 'Published', 'arkon-bar-manager' ); ?></option>
				<option value="draft"><?php esc_html_e( 'Draft', 'arkon-bar-manager' ); ?></option>
			</select>
		</p>

		<p class="submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Create events', 'arkon-bar-manager' ); ?></button>
		</p>
		<?php
	}

	/**
	 * One row of the table.
	 *
	 * @param int|string $i     Row index, or the literal __i__ for the template.
	 * @param array      $row   Row values.
	 * @param array      $terms Available categories.
	 */
	private function render_row( $i, array $row, array $terms ) {
		$name  = 'abm_rows[' . $i . ']';
		$notes = isset( $row['notes'] ) ? (array) $row['notes'] : array();
		?>
		<tr class="abm-bulk-row<?php echo $notes ? ' has-note' : ''; ?>">
			<td>
				<input type="date" name="<?php echo esc_attr( $name ); ?>[date]" value="<?php echo esc_attr( $row['date'] ); ?>" />
			</td>
			<td>
				<input type="time" name="<?php echo esc_attr( $name ); ?>[start]" value="<?php echo esc_attr( $row['start'] ); ?>" />
			</td>
			<td>
				<?php // "close" is a real value here, so this cannot be an <input type="time">. ?>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[end]" value="<?php echo esc_attr( $row['end'] ); ?>" placeholder="<?php esc_attr_e( 'HH:MM or close', 'arkon-bar-manager' ); ?>" />
			</td>
			<td>
				<input type="text" class="abm-bulk-title" name="<?php echo esc_attr( $name ); ?>[title]" value="<?php echo esc_attr( $row['title'] ); ?>" />
				<?php // A lineup or a door note picked up from the lines beneath an entry. ?>
				<textarea class="abm-bulk-desc" name="<?php echo esc_attr( $name ); ?>[description]" rows="1"
					placeholder="<?php esc_attr_e( 'Description (optional)', 'arkon-bar-manager' ); ?>"><?php echo esc_textarea( $row['description'] ); ?></textarea>
				<?php if ( $notes ) : ?>
					<span class="abm-bulk-note"><?php echo esc_html( implode( ', ', $notes ) ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $name ); ?>[cost]" value="<?php echo esc_attr( $row['cost'] ); ?>" />
			</td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[category]">
					<option value=""><?php esc_html_e( '— none —', 'arkon-bar-manager' ); ?></option>
					<?php foreach ( $terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( (int) $row['category'], (int) $term->term_id ); ?>>
							<?php echo esc_html( $term->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<div class="abm-bulk-flyer">
					<input type="hidden" class="abm-flyer-id" name="<?php echo esc_attr( $name ); ?>[flyer]" value="<?php echo esc_attr( $row['flyer'] ); ?>" />
					<button type="button" class="button-link abm-flyer-pick">
						<?php
						$thumb = $row['flyer'] ? wp_get_attachment_image( (int) $row['flyer'], array( 40, 40 ) ) : '';
						echo $thumb ? wp_kses_post( $thumb ) : esc_html__( 'Choose', 'arkon-bar-manager' );
						?>
					</button>
				</div>
			</td>
			<td>
				<button type="button" class="button-link abm-bulk-remove" aria-label="<?php esc_attr_e( 'Remove row', 'arkon-bar-manager' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array $report From create().
	 */
	private function render_report( array $report ) {
		$class = $report['failed'] ? 'notice-warning' : 'notice-success';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
			<p>
				<?php
				printf(
					/* translators: 1: created count, 2: skipped count, 3: failed count. */
					esc_html__( 'Created %1$d. Skipped %2$d as duplicates. %3$d could not be created.', 'arkon-bar-manager' ),
					(int) $report['created'],
					(int) $report['skipped'],
					(int) $report['failed']
				);
				?>
				<?php if ( $report['created'] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ABM_POST_TYPE ) ); ?>"><?php esc_html_e( 'View events', 'arkon-bar-manager' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( $report['messages'] ) : ?>
				<ul style="margin-left:1.5em;list-style:disc">
					<?php foreach ( $report['messages'] as $message ) : ?>
						<li><?php echo esc_html( $message ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $report['remaining'] ) : ?>
				<p><?php esc_html_e( 'The rows below were not created. Correct them and press Create again.', 'arkon-bar-manager' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Lines that ask for something other than an addition.
	 *
	 * Shown, never acted on. A booking list mixes new nights in with corrections
	 * to nights already on the calendar, and a delete read as an add would create
	 * the very event being asked about.
	 *
	 * @param string[] $actions Lines.
	 */
	private function render_actions( array $actions ) {
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'These ask for changes to events that already exist, so nothing was created for them. Handle them yourself:', 'arkon-bar-manager' ); ?></strong></p>
			<ul style="margin-left:1.5em;list-style:disc">
				<?php foreach ( array_slice( $actions, 0, 30 ) as $line ) : ?>
					<li><code><?php echo esc_html( $line ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $actions ) > 30 ) : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of further lines. */
						esc_html__( '&hellip; and %d more.', 'arkon-bar-manager' ),
						count( $actions ) - 30
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string[] $skipped Lines the parser could not read.
	 */
	private function render_skipped( array $skipped ) {
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %d: number of lines. */
					esc_html( _n( '%d line was not read as an event. Check whether it should have been:', '%d lines were not read as events. Check whether any should have been:', count( $skipped ), 'arkon-bar-manager' ) ),
					count( $skipped )
				);
				?>
			</p>
			<ul style="margin-left:1.5em;list-style:disc">
				<?php foreach ( array_slice( $skipped, 0, 20 ) as $line ) : ?>
					<li><code><?php echo esc_html( $line ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Input                                                                 */
	/* --------------------------------------------------------------------- */

	/**
	 * @return array<string,mixed>
	 */
	private function blank_row() {
		return array(
			'date'        => '',
			'start'       => '',
			'end'         => '',
			'title'       => '',
			'cost'        => '',
			'description' => '',
			'category'    => 0,
			'flyer'       => 0,
			'notes'       => array(),
		);
	}

	/**
	 * Normalize one row from any source into the shape the table renders.
	 *
	 * @param array $raw Partial row.
	 * @return array<string,mixed>
	 */
	private function normalize( array $raw ) {
		$row = array_merge( $this->blank_row(), array_intersect_key( $raw, $this->blank_row() ) );

		$row['date']        = abm_sanitize_date( $row['date'] );
		$row['title']       = sanitize_text_field( (string) $row['title'] );
		$row['cost']        = sanitize_text_field( (string) $row['cost'] );
		$row['description'] = sanitize_textarea_field( (string) $row['description'] );
		$row['start']       = $this->clean_time( $row['start'] );
		$row['end']         = $this->clean_time( $row['end'], true );

		$row['category'] = absint( $row['category'] );
		$row['flyer']    = absint( $row['flyer'] );
		$row['notes']    = array_map( 'sanitize_text_field', (array) $row['notes'] );

		return $row;
	}

	/**
	 * @param string $value      Time.
	 * @param bool   $allow_close Whether the literal "close" is permitted.
	 * @return string
	 */
	private function clean_time( $value, $allow_close = false ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( $allow_close && 'close' === $value ) {
			return 'close';
		}
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}
		return '';
	}

	/**
	 * Rows as submitted by the table.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function posted_rows() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized in normalize().
		$raw  = isset( $_POST['abm_rows'] ) ? (array) wp_unslash( $_POST['abm_rows'] ) : array();
		$rows = array();

		foreach ( $raw as $item ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = $this->normalize( $item );
			// A row with nothing in it is the operator leaving a spare blank, not
			// an event they forgot to fill in.
			if ( '' === $row['date'] && '' === $row['title'] ) {
				continue;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Read an uploaded CSV into rows.
	 *
	 * @return array{rows:array,skipped:string[]}
	 */
	private function read_csv() {
		$empty = array(
			'rows'    => array(),
			'skipped' => array(),
		);

		if ( empty( $_FILES['abm_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['abm_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return $empty;
		}

		$tmp = sanitize_text_field( (string) $_FILES['abm_csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$handle = fopen( $tmp, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return $empty;
		}

		// Strip a BOM, which otherwise becomes part of the first column name and
		// makes "title" fail to match.
		$first = fgets( $handle );
		if ( false === $first ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return $empty;
		}
		$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
		$delim = $this->detect_delimiter( $first );

		$header = array_map(
			static function ( $h ) {
				return strtolower( trim( (string) $h ) );
			},
			(array) str_getcsv( rtrim( $first, "\r\n" ), $delim )
		);

		$rows    = array();
		$skipped = array();

		while ( ( $data = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			if ( ! array_filter( $data, 'strlen' ) ) {
				continue;
			}

			$get = static function ( $names ) use ( $data, $header ) {
				foreach ( (array) $names as $name ) {
					$idx = array_search( $name, $header, true );
					if ( false !== $idx && isset( $data[ $idx ] ) ) {
						return trim( (string) $data[ $idx ] );
					}
				}
				return '';
			};

			$date = abm_sanitize_date( $get( array( 'date', 'start date', 'event date' ) ) );
			if ( '' === $date ) {
				// Fall back to the prose parser, so a spreadsheet holding "Sep 4"
				// rather than an ISO date still works.
				$guess = ABM_Bulk_Parser::parse_line( $get( array( 'date', 'start date', 'event date' ) ), current_time( 'Y-m-d' ) );
				$date  = $guess ? $guess['date'] : '';
			}

			$title = $get( array( 'title', 'name', 'event', 'artist', 'artists' ) );
			if ( '' === $date && '' === $title ) {
				$skipped[] = implode( ', ', array_slice( $data, 0, 4 ) );
				continue;
			}

			$rows[] = $this->normalize(
				array(
					'date'     => $date,
					'start'    => $this->csv_time( $get( array( 'start', 'start time', 'time' ) ) ),
					'end'      => $this->csv_time( $get( array( 'end', 'end time' ) ), true ),
					'title'    => $title,
					'cost'     => $get( array( 'cost', 'cover', 'price' ) ),
					'category' => $this->term_id( $get( array( 'category', 'categories' ) ) ),
					'notes'    => '' === $date ? array( 'date not understood' ) : array(),
				)
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'rows'    => $rows,
			'skipped' => $skipped,
		);
	}

	/**
	 * Accept both "20:00" and "8pm" in a spreadsheet cell.
	 *
	 * @param string $value       Cell value.
	 * @param bool   $allow_close Whether "close" is meaningful.
	 * @return string
	 */
	private function csv_time( $value, $allow_close = false ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( $allow_close && preg_match( '/^clos(e|ing)$/i', $value ) ) {
			return 'close';
		}
		$clean = $this->clean_time( $value, $allow_close );
		if ( '' !== $clean ) {
			return $clean;
		}
		// Let the prose parser have a go at "8pm", "8:00 PM".
		$guess = ABM_Bulk_Parser::parse_line( '2000-01-01 x ' . $value, '2000-01-01' );
		return $guess ? $guess['start'] : '';
	}

	/**
	 * Resolve a category name to a term ID, without creating anything. Bulk Add
	 * authors events; inventing taxonomy terms from a typo is not its job.
	 *
	 * @param string $name Category name.
	 * @return int
	 */
	private function term_id( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, ABM_TAXONOMY );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), ABM_TAXONOMY );
		}
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * @param string $line First line of the file.
	 * @return string
	 */
	private function detect_delimiter( $line ) {
		$best  = ',';
		$count = 0;
		foreach ( array( ',', "\t", ';', '|' ) as $candidate ) {
			$n = substr_count( $line, $candidate );
			if ( $n > $count ) {
				$count = $n;
				$best  = $candidate;
			}
		}
		return $best;
	}

	/* --------------------------------------------------------------------- */
	/* Flyers                                                                */
	/* --------------------------------------------------------------------- */

	/**
	 * Fill in each row's flyer from the Media Library, by date.
	 *
	 * Flyers arrive as a folder named by the night they belong to -- "8-19.jpg",
	 * "8-29 Ascension.jpg", "9-9 hopscotch kickoff.jpeg" -- so once the folder is
	 * in the Media Library the date in the filename is enough to pair most of them
	 * with a row automatically.
	 *
	 * The date alone is not a key, though. A venue can run two things on one
	 * night, and then the folder holds "8-29 Ascension.jpg" and "8-29.jpeg" for
	 * two different shows. Where a date has more than one flyer the words after
	 * the date decide it, and where they decide nothing the row is left empty and
	 * told why. Attaching the wrong artwork to a show is worse than attaching
	 * none: nobody checks a thumbnail that is already filled in.
	 *
	 * @param array $rows Rows.
	 * @return array{rows:array,matched:int,ambiguous:int}
	 */
	private function match_flyers( array $rows ) {
		$matched   = 0;
		$ambiguous = 0;

		// Work a date at a time. A night with two shows has two flyers, and they
		// can only be told apart by looking at all of them together.
		$by_date = array();
		foreach ( $rows as $i => $row ) {
			if ( $row['flyer'] || '' === $row['date'] ) {
				continue;
			}
			$by_date[ $row['date'] ][] = $i;
		}

		foreach ( $by_date as $date => $indexes ) {
			$candidates = $this->flyers_for_date( $date );
			if ( ! $candidates ) {
				continue;
			}

			$claimed = array();
			$unsure  = array();

			foreach ( $indexes as $i ) {
				$free = array_values(
					array_filter(
						$candidates,
						static function ( $c ) use ( $claimed ) {
							return ! in_array( $c['id'], $claimed, true );
						}
					)
				);

				if ( ! $free ) {
					break;
				}

				// One flyer and one row for the date needs no deciding.
				if ( 1 === count( $free ) && 1 === count( $indexes ) ) {
					$rows[ $i ]['flyer']   = (int) $free[0]['id'];
					$rows[ $i ]['notes'][] = 'flyer matched';
					$claimed[]             = (int) $free[0]['id'];
					++$matched;
					continue;
				}

				$best = $this->best_flyer( $free, $rows[ $i ]['title'] );
				if ( $best ) {
					$rows[ $i ]['flyer']   = (int) $best;
					$rows[ $i ]['notes'][] = 'flyer matched';
					$claimed[]             = $best;
					++$matched;
					continue;
				}

				$unsure[] = $i;
			}

			/*
			 * What is left over, by elimination.
			 *
			 * The common shape is a night with two shows where only one flyer was
			 * given a descriptive name -- "8-29 Ascension.jpg" and "8-29.jpeg". The
			 * named one is claimed above, and the plain one then has exactly one
			 * row left that could want it. That is a deduction rather than a guess,
			 * so it is worth making; anything less certain is left alone.
			 */
			$free = array_values(
				array_filter(
					$candidates,
					static function ( $c ) use ( $claimed ) {
						return ! in_array( $c['id'], $claimed, true );
					}
				)
			);

			if ( 1 === count( $unsure ) && 1 === count( $free ) ) {
				$rows[ $unsure[0] ]['flyer']   = (int) $free[0]['id'];
				$rows[ $unsure[0] ]['notes'][] = 'flyer matched (only one left for this date)';
				++$matched;
				continue;
			}

			foreach ( $unsure as $i ) {
				$rows[ $i ]['notes'][] = sprintf(
					/* translators: %d: number of flyers sharing the date. */
					__( '%d flyers on this date — pick one', 'arkon-bar-manager' ),
					count( $candidates )
				);
				++$ambiguous;
			}
		}

		return array(
			'rows'      => $rows,
			'matched'   => $matched,
			'ambiguous' => $ambiguous,
		);
	}

	/**
	 * Image attachments whose filename begins with this date.
	 *
	 * Matches on post_name rather than the stored path: WordPress slugifies the
	 * filename on upload, so "8-29 Ascension.jpg" becomes "8-29-ascension" and the
	 * date is a clean prefix. Both "8-9" and "08-09" are tried, since either may
	 * be typed.
	 *
	 * @param string $ymd Y-m-d.
	 * @return array<int,array{id:int,slug:string}>
	 */
	private function flyers_for_date( $ymd ) {
		global $wpdb;

		$month = (int) substr( $ymd, 5, 2 );
		$day   = (int) substr( $ymd, 8, 2 );

		$prefixes = array_unique(
			array(
				$month . '-' . $day,
				sprintf( '%02d-%02d', $month, $day ),
			)
		);

		$where = array();
		$args  = array();
		foreach ( $prefixes as $prefix ) {
			// Exactly the date, or the date followed by a separator. Without the
			// separator "8-1" would also claim every "8-19" in the library.
			$where[] = '(p.post_name = %s OR p.post_name LIKE %s)';
			$args[]  = $prefix;
			$args[]  = $wpdb->esc_like( $prefix . '-' ) . '%';
		}

		$sql = "SELECT p.ID, p.post_name
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				  AND p.post_mime_type LIKE 'image/%'
				  AND (" . implode( ' OR ', $where ) . ')
				LIMIT 20';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		$out = array();
		foreach ( (array) $found as $row ) {
			$out[] = array(
				'id'   => (int) $row->ID,
				'slug' => (string) $row->post_name,
			);
		}

		return $out;
	}

	/**
	 * Choose between several flyers sharing a date, using the words after the
	 * date in the filename. Returns 0 when nothing wins outright, which leaves
	 * the row for a person rather than guessing.
	 *
	 * @param array  $candidates From flyers_for_date().
	 * @param string $title      Row title.
	 * @return int Attachment ID, or 0.
	 */
	private function best_flyer( array $candidates, $title ) {
		$wanted = $this->words( $title );
		if ( ! $wanted ) {
			return 0;
		}

		$scores = array();
		foreach ( $candidates as $candidate ) {
			// Drop the leading date, keep what describes the night.
			$descriptor = preg_replace( '/^\d{1,2}-\d{1,2}-?/', ' ', $candidate['slug'] );
			$scores[]   = array(
				'id'    => $candidate['id'],
				'score' => count( array_intersect( $wanted, $this->words( $descriptor ) ) ),
			);
		}

		usort(
			$scores,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// A winner has to actually win. Nothing matched, or a tie at the top,
		// means the filename does not distinguish them.
		if ( $scores[0]['score'] < 1 ) {
			return 0;
		}
		if ( isset( $scores[1] ) && $scores[1]['score'] === $scores[0]['score'] ) {
			return 0;
		}

		return (int) $scores[0]['id'];
	}

	/**
	 * Comparable words from a title or filename: lowercase, punctuation gone, and
	 * without the short joining words that match everything.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function words( $text ) {
		$text  = strtolower( (string) $text );
		$text  = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		$stop  = array( 'the', 'and', 'with', 'a', 'an', 'of', 'at', 'for', 'to', 'featuring', 'feat', 'flyer', 'night', 'party' );
		$words = array_filter(
			preg_split( '/\s+/', trim( $text ) ),
			static function ( $w ) use ( $stop ) {
				return strlen( $w ) > 2 && ! in_array( $w, $stop, true );
			}
		);

		return array_values( array_unique( $words ) );
	}

	/* --------------------------------------------------------------------- */
	/* Creation                                                              */
	/* --------------------------------------------------------------------- */

	/**
	 * Create the submitted rows.
	 *
	 * @param array $rows Normalized rows.
	 * @return array{created:int,skipped:int,failed:int,messages:string[],remaining:array}
	 */
	private function create( array $rows ) {
		$status = isset( $_POST['abm_status'] ) ? sanitize_key( wp_unslash( $_POST['abm_status'] ) ) : 'publish'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in render_page().
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			$status = 'publish';
		}

		$report = array(
			'created'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'messages'  => array(),
			'remaining' => array(),
		);

		foreach ( $rows as $row ) {
			$problem = $this->why_not( $row );
			if ( $problem ) {
				++$report['failed'];
				$report['messages'][]  = $problem;
				$row['notes']          = array( $problem );
				$report['remaining'][] = $row;
				continue;
			}

			if ( $this->already_exists( $row ) ) {
				++$report['skipped'];
				continue;
			}

			$post_id = $this->insert( $row, $status );
			if ( is_wp_error( $post_id ) ) {
				++$report['failed'];
				/* translators: 1: event title, 2: error message. */
				$message               = sprintf( __( '%1$s: %2$s', 'arkon-bar-manager' ), $row['title'], $post_id->get_error_message() );
				$report['messages'][]  = $message;
				$row['notes']          = array( $post_id->get_error_message() );
				$report['remaining'][] = $row;
				continue;
			}

			++$report['created'];
		}

		return $report;
	}

	/**
	 * Why a row cannot become an event, or '' if it can.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private function why_not( array $row ) {
		if ( '' === $row['title'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'Row for %s has no title.', 'arkon-bar-manager' ), $row['date'] ? $row['date'] : __( '(no date)', 'arkon-bar-manager' ) );
		}
		if ( '' === $row['date'] ) {
			/* translators: %s: event title. */
			return sprintf( __( '%s has no date.', 'arkon-bar-manager' ), $row['title'] );
		}
		return '';
	}

	/**
	 * Whether an event with this title already sits on this date.
	 *
	 * Pressing Create twice, or re-reading the same list, should not double the
	 * calendar. There is no source ID to match on the way the Import screen has,
	 * so title plus date is the identity -- which is also how a person would tell
	 * whether they had already added a night.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private function already_exists( array $row ) {
		$found = get_posts(
			array(
				'post_type'        => ABM_POST_TYPE,
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'title'            => $row['title'],
				'posts_per_page'   => 20,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					array(
						'key'   => 'abm_event_date',
						'value' => $row['date'],
					),
				),
			)
		);

		return ! empty( $found );
	}

	/**
	 * Write one event.
	 *
	 * @param array  $row    Row.
	 * @param string $status Post status.
	 * @return int|WP_Error
	 */
	private function insert( array $row, $status ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => ABM_POST_TYPE,
				'post_status'  => $status,
				'post_title'   => $row['title'],
				'post_content' => $row['description'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, 'abm_event_date', $row['date'] );
		update_post_meta( $post_id, 'abm_event_time_start', $row['start'] );
		update_post_meta( $post_id, 'abm_event_time_end', $row['end'] );
		update_post_meta( $post_id, 'abm_event_cost', $row['cost'] );
		// Clamp the export only when there is no real end time to honour. Forcing
		// this on truncates a genuine 8 PM - 1 AM show at 11:59 PM.
		update_post_meta( $post_id, 'abm_display_start_only', ( '' === $row['end'] ) ? 1 : 0 );

		if ( $row['category'] ) {
			wp_set_object_terms( $post_id, array( (int) $row['category'] ), ABM_TAXONOMY, false );
		}

		if ( $row['flyer'] && wp_attachment_is_image( $row['flyer'] ) ) {
			set_post_thumbnail( $post_id, (int) $row['flyer'] );
			update_post_meta( $post_id, 'abm_flyer_id', (int) $row['flyer'] );
		}

		/*
		 * Derived meta and occurrences, in that order and explicitly.
		 *
		 * ABM_Occurrences::on_save_post() already ran, at priority 25 inside the
		 * wp_insert_post() above -- before any of the meta beneath it existed. It
		 * therefore generated from an empty date and produced nothing. This is the
		 * same ordering problem REST solves with rest_after_insert, and the same
		 * answer: re-derive once the meta is actually there.
		 */
		$post = get_post( $post_id );
		if ( $post && class_exists( 'ABM_Meta' ) ) {
			ABM_Meta::instance()->sync_derived( $post_id, $post );
		}
		ABM_Occurrences::generate_for_post( $post_id );

		return (int) $post_id;
	}
}
