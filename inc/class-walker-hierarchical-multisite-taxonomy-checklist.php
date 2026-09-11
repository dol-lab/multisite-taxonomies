<?php
/**
 * Multisite Taxonomies API: Walker_Hierarchical_Multisite_Taxonomy_Checklist class
 *
 * @package multitaxo
 */

/**
 * Class to output an unordered list of hierarchical multisite taxonomies checkbox input elements.
 *
 * Every row is the term's own control (a checkbox, or the row itself in list-only mode) followed
 * by the sub-terms wrapped in a `<details>` element, so a branch collapses and expands natively:
 * keyboard operable, announced as a disclosure by screen readers, and working with scripting
 * switched off. Branches holding a selected term are rendered open.
 *
 * The disclosure contains nothing but the sub-terms, so the rows of a level stay identical whether
 * or not a term has children; CSS puts the arrow in the gutter every row leaves free on the left.
 *
 * @see Walker
 */
class Walker_Hierarchical_Multisite_Taxonomy_Checklist extends Walker {
	/**
	 * Type of tree we are waling over.
	 *
	 * @access public
	 * @var string
	 */
	public $tree_type = 'category';

	/**
	 * DB fields to look in.
	 *
	 * @access public
	 * @var array
	 */
	public $db_fields = array(
		'parent' => 'parent',
		'id'     => 'multisite_term_id',
	); // TODO: decouple this.

	/**
	 * Multisite term ids whose branch has to be rendered expanded.
	 *
	 * Every ancestor of a selected term, so a checked box is never hidden inside a collapsed
	 * branch. Rebuilt on each walk().
	 *
	 * @access protected
	 * @var array Term id => true.
	 */
	protected $expanded_branches = array();

	/**
	 * Walks the term tree, working out first which branches have to start expanded.
	 *
	 * @param array $elements  The terms to walk.
	 * @param int   $max_depth Maximum depth to descend to, 0 for all levels.
	 * @param mixed ...$args   Additional arguments; the checklist arguments are the first one.
	 * @return string The term list markup.
	 */
	public function walk( $elements, $max_depth, ...$args ) {
		$this->expanded_branches = array();

		$walk_args      = ( isset( $args[0] ) && is_array( $args[0] ) ) ? $args[0] : array();
		$selected_terms = isset( $walk_args['selected_terms'] ) ? array_map( 'intval', (array) $walk_args['selected_terms'] ) : array();

		if ( ! empty( $selected_terms ) ) {
			$parent_of = array();

			foreach ( (array) $elements as $element ) {
				if ( isset( $element->multisite_term_id ) ) {
					$parent_of[ (int) $element->multisite_term_id ] = isset( $element->parent ) ? (int) $element->parent : 0;
				}
			}

			foreach ( $selected_terms as $term_id ) {
				$parent = isset( $parent_of[ $term_id ] ) ? $parent_of[ $term_id ] : 0;

				// Climb to the root. A parent already marked ends the climb, which also keeps a
				// corrupted hierarchy from looping here.
				while ( $parent && ! isset( $this->expanded_branches[ $parent ] ) ) {
					$this->expanded_branches[ $parent ] = true;
					$parent                             = isset( $parent_of[ $parent ] ) ? $parent_of[ $parent ] : 0;
				}
			}
		}

		return parent::walk( $elements, $max_depth, ...$args );
	}

	/**
	 * Traverses a single term, telling start_el() whether it is going to get a child list.
	 *
	 * The disclosure has to be opened in start_el(), before the children are walked, so the
	 * element's own output is the only place that can know about them.
	 *
	 * @param object $element           The term to display.
	 * @param array  $children_elements All terms keyed by parent id. Passed by reference.
	 * @param int    $max_depth         Maximum depth to descend to, 0 for all levels.
	 * @param int    $depth             Depth of the current term.
	 * @param array  $args              An array of arguments. @see wp_terms_checklist().
	 * @param string $output            Passed by reference. Used to append additional content.
	 */
	public function display_element( $element, &$children_elements, $max_depth, $depth, $args, &$output ) {
		if ( ! $element ) {
			return;
		}

		$id_field = $this->db_fields['id'];
		$id       = (int) $element->$id_field;

		// Only a term whose children are actually rendered gets a disclosure; below $max_depth
		// the children are dropped, and an empty <details> is a control that does nothing.
		$has_children = ! empty( $children_elements[ $id ] ) && ( 0 === (int) $max_depth || $max_depth > $depth + 1 );

		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args[0]['has_children'] = $has_children;
			$args[0]['is_expanded']  = $has_children && isset( $this->expanded_branches[ $id ] );
		}

		parent::display_element( $element, $children_elements, $max_depth, $depth, $args, $output );
	}

	/**
	 * Starts the list before the elements are added.
	 *
	 * @see Walker:start_lvl()
	 *
	 * @since 2.5.1
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int    $depth  Depth of category. Used for tab indentation.
	 * @param array  $args   An array of arguments. @see wp_terms_checklist().
	 */
	public function start_lvl( &$output, $depth = 0, $args = array() ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "$indent<ul class='children'>\n";
	}

	/**
	 * Ends the list of after the elements are added.
	 *
	 * @see Walker::end_lvl()
	 *
	 * @since 2.5.1
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int    $depth  Depth of category. Used for tab indentation.
	 * @param array  $args   An array of arguments. @see wp_terms_checklist().
	 */
	public function end_lvl( &$output, $depth = 0, $args = array() ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "$indent</ul>\n";
	}

	/**
	 * Start the element output.
	 *
	 * @see Walker::start_el()
	 *
	 * @since 2.5.1
	 *
	 * @param string $output   Passed by reference. Used to append additional content.
	 * @param object $category The current term object.
	 * @param int    $depth    Depth of the term in reference to parents. Default 0.
	 * @param array  $args     An array of arguments. @see wp_terms_checklist().
	 * @param int    $id       ID of the current term.
	 */
	public function start_el( &$output, $category, $depth = 0, $args = array(), $id = 0 ) {
		if ( empty( $args['taxonomy'] ) ) {
			$taxonomy = 'category';
		} else {
			$taxonomy = $args['taxonomy'];
		}

		if ( 'category' === $taxonomy ) {
			$name = 'post_category';
		} else {
			$name = 'multi_tax_input[' . $taxonomy . ']';
		}

		$term_id      = (int) $category->multisite_term_id;
		$has_children = ! empty( $args['has_children'] );
		$list_only    = ! empty( $args['list_only'] );
		$term_name    = esc_html( apply_filters( 'the_category', $category->name ) ); // This filter is documented in wp-includes/category-template.php.

		$args['popular_terms']  = empty( $args['popular_terms'] ) ? array() : $args['popular_terms'];
		$args['selected_terms'] = empty( $args['selected_terms'] ) ? array() : $args['selected_terms'];

		$is_selected = in_array( $term_id, $args['selected_terms'], true );

		$li_classes = array();

		if ( in_array( $term_id, $args['popular_terms'], true ) ) {
			$li_classes[] = 'popular-category';
		}

		if ( $has_children ) {
			$li_classes[] = 'has-children';
		}

		$class = empty( $li_classes ) ? '' : ' class="' . esc_attr( implode( ' ', $li_classes ) ) . '"';

		// Sanitized markup another plugin wants shown after the name (a lock, a badge, ...).
		$suffix = multisite_term_display_suffix(
			$category,
			'checklist',
			array(
				'taxonomy'    => $taxonomy,
				'object_type' => isset( $args['object_type'] ) ? $args['object_type'] : '',
				'object_id'   => isset( $args['object_id'] ) ? (int) $args['object_id'] : 0,
			)
		);

		if ( $list_only ) {
			// The row itself is the checkbox here; it carries no form field and no row id.
			$row    = '<div class="category' . ( $is_selected ? ' selected' : '' ) . '" data-term-id="' . $term_id .
				'" tabindex="0" role="checkbox" aria-checked="' . ( $is_selected ? 'true' : 'false' ) . '">' .
				$term_name . '</div>';
			$row_id = '';
		} else {
			$input  = '<input value="' . $term_id . '" type="checkbox" name="' . $name . '[]" id="' . esc_attr( 'in-' . $taxonomy . '-' . $term_id ) . '"';
			$input .= checked( $is_selected, true, false );
			$input .= disabled( empty( $args['disabled'] ), false, false );

			$row    = '<label class="selectit">' . $input . ' /> ' . $term_name . '</label>';
			$row_id = ' id="' . esc_attr( "multisite-hierarchical-term-{$taxonomy}-{$term_id}" ) . '"';
		}

		$output .= "\n" . '<li' . $row_id . $class . '>' . $row . $suffix;

		// The branch. Its summary is the arrow in the row's gutter and nothing else: the term name
		// belongs to the row's own control, so the disclosure is named for screen readers instead.
		if ( $has_children ) {
			$output .= '<details class="mtax-branch"' . ( ! empty( $args['is_expanded'] ) ? ' open' : '' ) .
				'><summary class="mtax-branch-summary"><span class="screen-reader-text">' .
				/* translators: %s: multisite term name. */
				esc_html( sprintf( __( 'Sub-terms of %s', 'multitaxo' ), $category->name ) ) .
				'</span></summary>';
		}
	}

	/**
	 * Ends the element output, if needed.
	 *
	 * @see Walker::end_el()
	 *
	 * @since 2.5.1
	 *
	 * @param string $output   Passed by reference. Used to append additional content.
	 * @param object $category The current term object.
	 * @param int    $depth    Depth of the term in reference to parents. Default 0.
	 * @param array  $args     An array of arguments. @see wp_terms_checklist().
	 */
	public function end_el( &$output, $category, $depth = 0, $args = array() ) {
		if ( ! empty( $args['has_children'] ) ) {
			$output .= '</details>';
		}

		$output .= "</li>\n";
	}
}
