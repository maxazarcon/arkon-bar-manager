<?php
/**
 * Changelog screen.
 *
 * Renders the changelog from readme.txt rather than keeping a second copy in
 * PHP. readme.txt is what ships with the plugin and what the versioning
 * convention already requires be updated on every build, so parsing it keeps one
 * source of truth. A hand-maintained duplicate would drift, and a changelog that
 * disagrees with itself is worse than none.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

class ABM_Changelog {

	/** @var ABM_Changelog|null */
	private static $instance = null;

	/** Admin page slug. */
	const SLUG = 'abm-changelog';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 13: after Settings (Admin, 10) and Migrate & Tools (Tools, 12),
		// so this lands at the bottom of the menu where a reference screen belongs.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 13 );
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . ABM_POST_TYPE,
			__( 'Changelog', 'arkon-bar-manager' ),
			__( 'Changelog', 'arkon-bar-manager' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The plugin's readme.txt path.
	 *
	 * @return string
	 */
	private static function readme_path() {
		return ABM_DIR . 'readme.txt';
	}

	/**
	 * The raw body of a "== Name ==" section of readme.txt, or ''.
	 *
	 * @param string $name Section name, e.g. 'Description'.
	 * @return string
	 */
	public static function section( $name ) {
		$raw = self::readme();
		if ( '' === $raw ) {
			return '';
		}

		$pattern = '/^==\s*' . preg_quote( (string) $name, '/' ) . '\s*==\s*$(.*?)(?=^==\s|\z)/ms';
		if ( ! preg_match( $pattern, $raw, $m ) ) {
			return '';
		}

		return trim( $m[1] );
	}

	/**
	 * A "Key: value" line from readme.txt's header block, or ''.
	 *
	 * readme.txt carries a few values the plugin header does not -- "Tested up
	 * to" in particular, which is not a header WordPress recognises, so reading
	 * it from $plugin_data yields nothing.
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public static function header( $name ) {
		$raw = self::readme();
		if ( '' === $raw ) {
			return '';
		}

		// Only the block above the first section heading.
		$head = preg_split( '/^==\s/m', $raw );
		$head = isset( $head[0] ) ? $head[0] : '';

		if ( ! preg_match( '/^' . preg_quote( (string) $name, '/' ) . '\s*:\s*(.+)$/mi', $head, $m ) ) {
			return '';
		}

		return trim( $m[1] );
	}

	/**
	 * readme.txt in full, or '' if it cannot be read.
	 *
	 * @return string
	 */
	private static function readme() {
		$path = self::readme_path();
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a file shipped inside this plugin.
		return false === $raw ? '' : $raw;
	}

	/**
	 * Render readme.txt markup as the small subset of HTML the plugin-details
	 * modal displays: headings, paragraphs, lists, bold and code.
	 *
	 * Everything is escaped before any markup is added, so readme.txt cannot
	 * inject HTML into the modal.
	 *
	 * @param string $text Raw section body.
	 * @return string
	 */
	public static function to_html( $text ) {
		$lines = preg_split( '/\R/', trim( (string) $text ) );
		$out   = '';
		$para  = array();
		$items = array();
		$tag   = 'ul';

		$flush = static function () use ( &$para, &$items, &$out, &$tag ) {
			if ( $para ) {
				$out .= '<p>' . self::inline( implode( ' ', $para ) ) . '</p>';
				$para = array();
			}
			if ( $items ) {
				$out .= '<' . $tag . '>';
				foreach ( $items as $item ) {
					$out .= '<li>' . self::inline( $item ) . '</li>';
				}
				$out  .= '</' . $tag . '>';
				$items = array();
			}
		};

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$flush();
				continue;
			}

			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $m ) ) {
				$flush();
				$out .= '<h4>' . self::inline( $m[1] ) . '</h4>';
				continue;
			}

			// "* item" is a bullet, "1. item" a numbered step. Switching between
			// the two closes the open list, so a numbered procedure with bullets
			// under its steps renders as a list followed by a list rather than
			// one list of mixed markers.
			$bullet = null;
			$kind   = '';
			// The space after the asterisk is required, and is what separates a
			// bullet from a paragraph that opens in bold. Without it, a line
			// beginning "**Note**" is read as a list item, loses its first
			// asterisk and renders with the rest of its markup showing.
			if ( preg_match( '/^\*\s+(.*)$/', $trimmed, $m ) ) {
				$bullet = $m[1];
				$kind   = 'ul';
			} elseif ( preg_match( '/^\d+\.\s+(.*)$/', $trimmed, $m ) ) {
				$bullet = $m[1];
				$kind   = 'ol';
			}

			if ( null !== $bullet ) {
				if ( $para || ( $items && $kind !== $tag ) ) {
					$flush();
				}
				$tag     = $kind;
				$items[] = $bullet;
				continue;
			}

			// A line under an open list item continues it; readme.txt wraps them.
			if ( $items ) {
				$items[ count( $items ) - 1 ] .= ' ' . $trimmed;
				continue;
			}

			$para[] = $trimmed;
		}

		$flush();

		return $out;
	}

	/**
	 * Escape, then apply the inline markup readme.txt uses.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function inline( $text ) {
		$text = esc_html( (string) $text );
		// Bold before italic: **x** would otherwise match the italic pattern
		// twice and produce an empty emphasis around the middle.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\*(?!\s)([^*]+?)(?<!\s)\*/', '<em>$1</em>', $text );
		$text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
		return $text;
	}

	/**
	 * Parse the "== Changelog ==" section of readme.txt.
	 *
	 * Returns entries in file order, which the convention keeps newest first.
	 * Each entry has a version, any leading prose lines, and its bullets. Bullets
	 * wrap across lines in readme.txt, so a line that does not start a new bullet
	 * is folded into the previous one.
	 *
	 * @return array{intro:string[],entries:array<int,array{version:string,notes:string[],items:string[]}>}
	 */
	public static function parse() {
		$out = array(
			'intro'   => array(),
			'entries' => array(),
		);

		$body = self::section( 'Changelog' );
		if ( '' === $body ) {
			return $out;
		}

		$lines   = preg_split( '/\R/', $body );
		$current = null;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// "= 2.10.3 =" starts a new entry.
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $trimmed, $vm ) ) {
				if ( $current ) {
					$out['entries'][] = $current;
				}
				$current = array(
					'version' => $vm[1],
					'notes'   => array(),
					'items'   => array(),
				);
				continue;
			}

			if ( '' === $trimmed ) {
				continue;
			}

			// Prose before the first version heading explains the scheme itself.
			if ( ! $current ) {
				$out['intro'][] = $trimmed;
				continue;
			}

			if ( '*' === substr( $trimmed, 0, 1 ) ) {
				$current['items'][] = trim( substr( $trimmed, 1 ) );
				continue;
			}

			// A continuation of the previous bullet, or a note under the heading.
			if ( $current['items'] ) {
				$last                      = count( $current['items'] ) - 1;
				$current['items'][ $last ] .= ' ' . $trimmed;
			} else {
				$current['notes'][] = $trimmed;
			}
		}

		if ( $current ) {
			$out['entries'][] = $current;
		}

		return $out;
	}

	/**
	 * Classify a version against the one before it, so the list can say at a
	 * glance whether a release broke something, added something or fixed
	 * something. Reads the numbers rather than trusting the prose.
	 *
	 * @param string $version  This entry's version.
	 * @param string $previous The next-oldest version, or ''.
	 * @return array{label:string,class:string}
	 */
	private static function classify( $version, $previous ) {
		$a = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', $version ) . '.0.0' ) );
		$b = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $previous ) . '.0.0' ) );

		if ( '' === (string) $previous ) {
			return array(
				'label' => __( 'Release', 'arkon-bar-manager' ),
				'class' => 'abm-cl-minor',
			);
		}
		if ( $a[0] !== $b[0] ) {
			return array(
				'label' => __( 'Breaking', 'arkon-bar-manager' ),
				'class' => 'abm-cl-major',
			);
		}
		if ( $a[1] !== $b[1] ) {
			return array(
				'label' => __( 'New', 'arkon-bar-manager' ),
				'class' => 'abm-cl-minor',
			);
		}
		return array(
			'label' => __( 'Fix', 'arkon-bar-manager' ),
			'class' => 'abm-cl-patch',
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'arkon-bar-manager' ) );
		}

		$data    = self::parse();
		$entries = $data['entries'];
		$running = ABM_VERSION;
		?>
		<div class="wrap abm-changelog">
			<h1><?php esc_html_e( 'Changelog', 'arkon-bar-manager' ); ?></h1>

			<p class="description" style="max-width:46em">
				<?php
				printf(
					/* translators: %s: version number. */
					esc_html__( 'Running version %s. This is read from the plugin\'s own readme.txt, so it cannot drift from what shipped.', 'arkon-bar-manager' ),
					'<strong>' . esc_html( $running ) . '</strong>'
				);
				?>
			</p>

			<?php if ( ! $entries ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'No changelog found. readme.txt is missing or has no "== Changelog ==" section.', 'arkon-bar-manager' ); ?>
				</p></div>
			<?php else : ?>

				<?php if ( $data['intro'] ) : ?>
					<p class="abm-cl-intro"><?php echo esc_html( implode( ' ', $data['intro'] ) ); ?></p>
				<?php endif; ?>

				<?php
				$total = count( $entries );
				foreach ( $entries as $i => $entry ) :
					$previous = ( $i + 1 < $total ) ? $entries[ $i + 1 ]['version'] : '';
					$kind     = self::classify( $entry['version'], $previous );
					$is_this  = ( $entry['version'] === $running );
					?>
					<div class="abm-cl-entry<?php echo $is_this ? ' is-current' : ''; ?>">
						<h2>
							<?php echo esc_html( $entry['version'] ); ?>
							<span class="abm-cl-tag <?php echo esc_attr( $kind['class'] ); ?>"><?php echo esc_html( $kind['label'] ); ?></span>
							<?php if ( $is_this ) : ?>
								<span class="abm-cl-tag abm-cl-current"><?php esc_html_e( 'Installed', 'arkon-bar-manager' ); ?></span>
							<?php endif; ?>
						</h2>

						<?php foreach ( $entry['notes'] as $note ) : ?>
							<p class="abm-cl-note"><?php echo esc_html( $note ); ?></p>
						<?php endforeach; ?>

						<?php if ( $entry['items'] ) : ?>
							<ul>
								<?php foreach ( $entry['items'] as $item ) : ?>
									<li><?php echo esc_html( $item ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
