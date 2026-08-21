<?php
/**
 * Event category archive.
 *
 * Same problem as the single event template, and the same answer. A theme has no
 * layout for this taxonomy, so it falls through to a generic archive that prints
 * excerpt fragments and pagination and nothing that identifies an event -- no
 * title, no date, no flyer.
 *
 * That matters more here than it looks. The previous calendar linked every row's
 * category to this URL, so those links are already out in the world and indexed,
 * and this post type claims the same /event-category/ base. After a migration
 * they resolve here.
 *
 * Rather than reimplement the listing, this renders the calendar shortcode
 * filtered to the current term. One renderer, so the archive cannot drift from
 * the calendar, and it inherits load-more, month dividers and collapse for free.
 * It shows upcoming dates only, which is what a venue archive should do -- the
 * same reasoning behind ordering this archive by next occurrence rather than by
 * the series start.
 *
 * The wrapper reuses the .abm-single classes deliberately: the hero band, its
 * type and the body measure are identical, and duplicating them under archive
 * names would be two copies to keep in step. .abm-archive is the hook for
 * anything that genuinely differs.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

get_header();

$abm_term = get_queried_object();
$abm_name = ( $abm_term && ! is_wp_error( $abm_term ) ) ? $abm_term->name : '';
$abm_slug = ( $abm_term && ! is_wp_error( $abm_term ) ) ? $abm_term->slug : '';
$abm_desc = ( $abm_term && ! is_wp_error( $abm_term ) ) ? trim( (string) $abm_term->description ) : '';
?>
<div class="abm-single abm-archive">
	<?php
	/*
	 * The plain `hero` class is load-bearing, exactly as on the single event
	 * template: the site's scroll listener measures the first `.hero` element and
	 * then darkens the navbar, without which the menu disappears against light
	 * content the moment a visitor scrolls.
	 */
	?>
	<header class="hero abm-single-hero">
		<div class="abm-single-hero-inner">
			<h1 class="abm-single-title"><?php echo esc_html( $abm_name ); ?></h1>
			<?php if ( $abm_desc ) : ?>
				<p class="abm-single-hero-date"><?php echo esc_html( $abm_desc ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<div class="abm-single-inner">
		<?php
		if ( '' !== $abm_slug ) {
			echo do_shortcode( '[abm_calendar category="' . esc_attr( $abm_slug ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode escapes its own output.
		}
		?>

		<p class="abm-single-back">
			<a href="<?php echo esc_url( ABM_Legacy_URLs::calendar_url() ); ?>">
				<?php esc_html_e( 'All events', 'arkon-bar-manager' ); ?>
			</a>
		</p>
	</div>
</div>
<?php
get_footer();
