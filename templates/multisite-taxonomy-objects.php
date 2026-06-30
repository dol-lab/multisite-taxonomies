<?php
/**
 * Default front-end template for a multisite taxonomy users/sites archive.
 *
 * Used when the active theme provides no multisite-taxonomy-{user|blog}[-<tax>].php override
 * (see Multisite_Taxonomy_Archive::filter_template_include()). Renders the term title and
 * description plus a paginated list of the assigned users or sites, wrapped in the active theme's
 * header/footer so it inherits the site chrome.
 *
 * Themes that want full control can copy this file into the theme as multisite-taxonomy-user.php
 * or multisite-taxonomy-blog.php and use the same template tags.
 *
 * @package multitaxo
 */

get_header();

$multitaxo_object_type = get_queried_multisite_object_type();
$multitaxo_objects     = get_multisite_taxonomy_archive_objects();
$multitaxo_term        = get_queried_multisite_term();
?>
<main id="primary" class="site-main multisite-taxonomy-archive multisite-taxonomy-archive--<?php echo esc_attr( $multitaxo_object_type ); ?>">
	<header class="page-header">
		<h1 class="page-title"><?php the_archive_title(); ?></h1>
		<?php
		if ( $multitaxo_term && '' !== $multitaxo_term->description ) {
			echo '<div class="archive-description">' . wp_kses_post( wpautop( $multitaxo_term->description ) ) . '</div>';
		}
		?>
	</header>

	<?php if ( ! empty( $multitaxo_objects ) ) : ?>
		<ul class="multisite-taxonomy-archive__list">
			<?php
			foreach ( $multitaxo_objects as $multitaxo_object ) {
				if ( $multitaxo_object instanceof WP_User ) {
					$multitaxo_name = $multitaxo_object->display_name;
					$multitaxo_url  = get_author_posts_url( $multitaxo_object->ID );
				} elseif ( $multitaxo_object instanceof WP_Site ) {
					$multitaxo_name = $multitaxo_object->blogname;
					$multitaxo_url  = $multitaxo_object->home;
				} else {
					continue;
				}

				$multitaxo_item = sprintf(
					'<li class="multisite-taxonomy-archive__item"><a href="%1$s">%2$s</a></li>',
					esc_url( $multitaxo_url ),
					esc_html( $multitaxo_name )
				);

				/**
				 * Filters the markup for a single row of the bundled users/sites archive.
				 *
				 * @param string         $multitaxo_item   The default `<li>` markup (already escaped).
				 * @param WP_User|WP_Site $multitaxo_object The object being rendered.
				 * @param string         $multitaxo_object_type Namespace: 'user' or 'blog'.
				 */
				echo apply_filters( 'multisite_taxonomy_archive_object_item', $multitaxo_item, $multitaxo_object, $multitaxo_object_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</ul>

		<?php
		the_posts_pagination(
			array(
				'mid_size'  => 2,
				'prev_text' => esc_html__( 'Previous', 'multitaxo' ),
				'next_text' => esc_html__( 'Next', 'multitaxo' ),
			)
		);
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'No entries found.', 'multitaxo' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
