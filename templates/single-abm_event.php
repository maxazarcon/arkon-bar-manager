<?php
/**
 * Single event template.
 *
 * Themes do not know about this post type, so without a template of their own
 * they fall back to a generic layout. On some themes that fallback renders the
 * page shell and nothing else: no title, no date, no flyer, no content. Since
 * /event-archive/<slug>/ 301s here and inbound social links point at these URLs,
 * the highest-value inbound link on a site can land on an empty page.
 *
 * This template is therefore a floor, not a design. It renders what the old
 * calendar's detail page rendered, using the plugin's own data, and it steps
 * aside the moment the theme provides single-abm_event.php or the setting is
 * switched off.
 *
 * The date shown is the occurrence being viewed, resolved from ?occ= and
 * validated against the occurrence table, so a link from a repeating event's row
 * shows the night that was actually clicked rather than the series start.
 *
 * @package ArkonBarManager
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$abm_id    = get_the_ID();
	$abm_date  = ABM_Frontend::occurrence_date( $abm_id );
	$abm_start = (string) get_post_meta( $abm_id, 'abm_event_time_start', true );
	$abm_end   = (string) get_post_meta( $abm_id, 'abm_event_time_end', true );

	$abm_flyer_id  = absint( get_post_meta( $abm_id, 'abm_flyer_id', true ) );
	$abm_flyer_url = abm_resolve_flyer_url( $abm_id, $abm_flyer_id, 'large' );

	$abm_cost  = (string) get_post_meta( $abm_id, 'abm_cost_display', true );
	$abm_terms = get_the_terms( $abm_id, ABM_TAXONOMY );
	$abm_cats  = ( $abm_terms && ! is_wp_error( $abm_terms ) ) ? wp_list_pluck( $abm_terms, 'name' ) : array();

	$abm_time_display = abm_format_time_range( $abm_start, $abm_end );
	$abm_date_display = abm_format_date( $abm_date, 'l, F jS, Y' );

	// Other nights this event runs. Empty for a single-date event, which is most
	// of them, so the block simply does not render.
	$abm_upcoming = ABM_Occurrences::upcoming_for( $abm_id, 12, $abm_date );

	// The hero sets the title in the theme's script face over a darkened flyer,
	// which is handsome and not especially easy to read. A plain restatement in
	// the body carries the legibility. Where it sits depends on whether there is
	// a description for it to head -- see the CSS.
	$abm_has_desc = '' !== trim( (string) get_the_content() );
	?>
	<div class="abm-single<?php echo $abm_has_desc ? ' has-description' : ''; ?>">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'abm-single-event' ); ?>>

			<?php
			/*
			 * Full-bleed title band, matching how every other page on this site
			 * opens. The theme's header is an overlay -- header.x-masthead
			 * collapses to zero height and the visible nav bar inside it is
			 * positioned absolutely -- so content laid out from the top of the
			 * document renders underneath the navigation. Every Cornerstone page
			 * here answers that with a tall hero band the nav sits over, rather
			 * than by padding the content down, so this does the same.
			 *
			 * The flyer doubles as the band's background, darkened, which keeps
			 * the page recognisably about this event before anything is read.
			 *
			 * The plain `hero` class on it is LOAD-BEARING and is not decoration.
			 * The site runs a scroll listener that measures the first `.hero`
			 * element and, once the visitor scrolls past it, adds `navbar-scrolled`
			 * to `.x-bar-absolute` -- which is what gives the transparent navbar a
			 * dark background so the white logo and menu stay readable over light
			 * content. Without this class the listener finds nothing, the class is
			 * never added, and the navigation turns invisible the moment the
			 * visitor scrolls off this band. Renaming it breaks that silently.
			 */
			$abm_hero_style = $abm_flyer_url
				? ' style="background-image:url(' . esc_url( $abm_flyer_url ) . ')"'
				: '';
			?>
			<header class="hero abm-single-hero<?php echo $abm_flyer_url ? ' has-bg' : ''; ?>"<?php echo $abm_hero_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above. ?>>
				<div class="abm-single-hero-inner">
					<h1 class="abm-single-title"><?php the_title(); ?></h1>
					<?php if ( $abm_date_display ) : ?>
						<p class="abm-single-hero-date">
							<?php echo esc_html( $abm_date_display ); ?>
							<?php if ( $abm_time_display ) : ?>
								<span class="abm-single-hero-sep">&middot;</span> <?php echo esc_html( $abm_time_display ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</header>

			<div class="abm-single-inner">
			<div class="abm-single-body">

				<?php if ( $abm_flyer_url ) : ?>
					<div class="abm-single-flyer">
						<img src="<?php echo esc_url( $abm_flyer_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" />
					</div>
				<?php endif; ?>

				<div class="abm-single-detail">

					<ul class="abm-single-meta">
						<?php if ( $abm_date_display ) : ?>
							<li class="abm-single-meta-date">
								<span class="abm-single-label"><?php esc_html_e( 'Date', 'arkon-bar-manager' ); ?></span>
								<span class="abm-single-value"><?php echo esc_html( $abm_date_display ); ?></span>
							</li>
						<?php endif; ?>

						<?php if ( $abm_time_display ) : ?>
							<li class="abm-single-meta-time">
								<span class="abm-single-label"><?php esc_html_e( 'Time', 'arkon-bar-manager' ); ?></span>
								<span class="abm-single-value"><?php echo esc_html( $abm_time_display ); ?></span>
							</li>
						<?php endif; ?>

						<?php if ( $abm_cats ) : ?>
							<li class="abm-single-meta-cat">
								<span class="abm-single-label"><?php esc_html_e( 'Category', 'arkon-bar-manager' ); ?></span>
								<span class="abm-single-value"><?php echo esc_html( implode( ', ', $abm_cats ) ); ?></span>
							</li>
						<?php endif; ?>

						<li class="abm-single-meta-cost">
							<span class="abm-single-label"><?php esc_html_e( 'Cover', 'arkon-bar-manager' ); ?></span>
							<span class="abm-single-value">
								<?php echo esc_html( '' !== $abm_cost ? $abm_cost : __( 'No cover', 'arkon-bar-manager' ) ); ?>
							</span>
						</li>
					</ul>

					<?php
					// Export buttons come from the plugin's own shortcode so this
					// template and a Cornerstone-built one behave identically.
					echo do_shortcode( '[abm_event_export]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode escapes its own output.
					?>

					<?php if ( $abm_upcoming ) : ?>
						<div class="abm-single-upcoming">
							<h2><?php esc_html_e( 'Also coming up', 'arkon-bar-manager' ); ?></h2>
							<ul>
								<?php foreach ( $abm_upcoming as $abm_row ) : ?>
									<li>
										<a href="<?php echo esc_url( add_query_arg( 'occ', $abm_row->occur_date, get_permalink( $abm_id ) ) ); ?>">
											<?php echo esc_html( abm_format_date( $abm_row->occur_date, 'D, j M Y' ) ); ?>
										</a>
										<?php
										$abm_row_time = abm_format_time_range( $abm_row->start_time, $abm_row->end_time );
										if ( $abm_row_time ) :
											?>
											<span class="abm-single-upcoming-time"><?php echo esc_html( $abm_row_time ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<?php
				/*
				 * Heading and description live inside .abm-single-body so all four blocks
				 * are siblings in one flex container and CSS `order` alone can place them:
				 * on a wide screen the flyer and details share a row and the heading
				 * introduces the description beneath; on a phone everything stacks and the
				 * heading rises above the details. One element, no duplication, nothing
				 * hidden from assistive technology.
				 */
				?>
				<h2 class="abm-single-heading"><?php the_title(); ?></h2>

				<?php if ( $abm_has_desc ) : ?>
					<div class="abm-single-content entry-content">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>
			</div>

			<p class="abm-single-back">
				<?php
				/*
				 * Shaped after the site's own "Learn More" button: uppercase,
				 * letter-spaced, with a chevron that carries the motion. The
				 * chevron is a pseudo-element in CSS rather than markup, so it
				 * cannot be read out as text by a screen reader.
				 */
				?>
				<a href="<?php echo esc_url( ABM_Legacy_URLs::calendar_url() ); ?>">
					<?php esc_html_e( 'All events', 'arkon-bar-manager' ); ?>
				</a>
			</p>
			</div><!-- .abm-single-inner -->
		</article>
	</div>
	<?php
endwhile;

get_footer();
