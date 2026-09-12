<?php
/**
 * List Table API: Multisite_Terms_List_Table class
 *
 * @package multitaxo
 */

/**
 * Core class used to implement displaying multisite terms in a list table.
 *
 * @access private
 *
 * @see WP_List_Table
 */
class Multisite_Terms_List_Table extends WP_List_Table {
	/**
	 * $callback_args
	 *
	 * @var mixed
	 */
	public $callback_args;

	/**
	 * Parent multisite term id => ids of its children, for the whole taxonomy.
	 *
	 * Empty whenever the listing is flat — a non-hierarchical taxonomy, a sorted column or a
	 * search, all of which print every match at the top level. Null until first looked up.
	 *
	 * @var array|null
	 */
	private $children = null;

	/**
	 * Multisite term id => id of its parent, the inverse of $children. Null until first built.
	 *
	 * @var array|null
	 */
	private $parents = null;

	/**
	 * The row being printed: the term, its depth, and the three facts that depend on the rows
	 * around it on this page. Set by print_row(), read by the column callbacks under it.
	 *
	 * @var array
	 */
	private $row = array();

	/**
	 * The multisite taxonomy name
	 *
	 * @var string
	 */
	public $taxonomy;

	/**
	 * Constructor.
	 *
	 * @access public
	 *
	 * @see WP_List_Table::__construct() for more information on default arguments.
	 *
	 * @global string $post_type
	 * @global string $multisite_taxonomy
	 * @global string $action
	 * @global object $mu_tax
	 *
	 * @param array $args An associative array of arguments. Pass `taxonomy` (a registered
	 *                    multisite taxonomy slug); `screen` only decides the column set.
	 *
	 * @throws InvalidArgumentException When neither the `taxonomy` argument nor the screen names a
	 *                                  registered multisite taxonomy.
	 */
	public function __construct( $args = array() ) {
		global $post_type, $multisite_taxonomy, $action, $mu_tax;

		parent::__construct(
			array(
				'plural'   => 'tags',
				'singular' => 'tag',
				'screen'   => isset( $args['screen'] ) ? $args['screen'] : get_current_screen(),
			)
		);

		$action    = $this->screen->action; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$post_type = $this->screen->post_type; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		// The taxonomy is an argument, not something smuggled through WP_Screen: over ajax the
		// screen is rebuilt from a posted id and may carry nothing.
		$multisite_taxonomy = ! empty( $args['taxonomy'] ) ? $args['taxonomy'] : $this->screen->taxonomy;

		if ( empty( $multisite_taxonomy ) || ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
			// Throw rather than wp_die(): this constructor runs inside ajax handlers too, where a
			// raw die corrupts the XML response.
			throw new InvalidArgumentException( esc_html( Multitaxo_Plugin::invalid_taxonomy_message( $multisite_taxonomy ) ) );
		}

		// Everything below reads the screen, so keep it in sync with the resolved taxonomy.
		$this->taxonomy         = $multisite_taxonomy;
		$this->screen->taxonomy = $multisite_taxonomy;

		$mu_tax = get_multisite_taxonomy( $multisite_taxonomy ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	/**
	 * Check if ajax user can manage multiste terms
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return current_user_can( get_multisite_taxonomy( $this->screen->taxonomy )->cap->manage_multisite_terms );
	}

	/**
	 * Function that prepare the items.
	 *
	 * @access public
	 */
	public function prepare_items() {
		$tags_per_page = $this->get_items_per_page( 'edit_multisite_tax_per_page' );

		if ( ! empty( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$search = trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$search = '';
		}

		$args = array(
			'taxonomy'   => $this->screen->taxonomy,
			'search'     => $search,
			'page'       => $this->get_pagenum(),
			'number'     => $tags_per_page,
			'hide_empty' => 0,
		);

		if ( ! empty( $_REQUEST['orderby'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['orderby'] = trim( sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( ! empty( $_REQUEST['order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['order'] = trim( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		// is_flat() reads these, and the query below is the first thing to ask.
		$this->callback_args = $args;

		$query = $args;

		if ( $this->is_flat() ) {
			$query['offset'] = ( $args['page'] - 1 ) * $args['number'];
		} else {
			// A tree is cut into pages after it has been walked, so the query has to bring all of it.
			$query['number'] = 0;
			$query['offset'] = 0;
		}

		$this->items = get_multisite_terms( $query );

		$this->set_pagination_args(
			array(
				'total_items' => count_multisite_terms(
					$this->screen->taxonomy,
					array(
						'search' => $search,
					)
				),
				'per_page'    => $tags_per_page,
			)
		);
	}

	/**
	 * Text to display when no items
	 *
	 * @return void
	 */
	public function no_items() {
		echo esc_html( get_multisite_taxonomy( $this->screen->taxonomy )->labels->not_found );
	}

	/**
	 * Getting all actions
	 *
	 * @return array $actions An array of actions.
	 */
	protected function get_bulk_actions() {
		$actions = array();

		if ( current_user_can( get_multisite_taxonomy( $this->screen->taxonomy )->cap->delete_multisite_terms ) ) {
			$actions['delete'] = __( 'Delete', 'multitaxo' );
		}

		return $actions;
	}

	/**
	 * The current action.
	 *
	 * @return string
	 */
	public function current_action() {
		if ( isset( $_REQUEST['action'] ) && isset( $_REQUEST['delete_multisite_terms'] ) && ( 'delete' === $_REQUEST['action'] || ( isset( $_REQUEST['action2'] ) && 'delete' === $_REQUEST['action2'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'bulk-delete';
		}
		return parent::current_action();
	}

	/**
	 * Get the columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'          => '<input type="checkbox" />',
			'name'        => _x( 'Name', 'multisite term name', 'multitaxo' ),
			'description' => __( 'Description', 'multitaxo' ),
			'slug'        => __( 'Slug', 'multitaxo' ),
		);

		if ( 'link_category' === $this->screen->taxonomy ) {
			$columns['links'] = __( 'Links', 'multitaxo' );
		} else {
			$columns['posts'] = _x( 'Count', 'Number/count of items', 'multitaxo' );
		}

		return $columns;
	}

	/**
	 * Get the sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'        => 'name',
			'description' => 'description',
			'slug'        => 'slug',
			'posts'       => 'count',
			'links'       => 'count',
		);
	}

	/**
	 * Display either the rows or the empty placeholder.
	 *
	 * @access public
	 */
	public function display_rows_or_placeholder() {
		$rows = $this->page_rows();

		if ( empty( $rows ) ) {
			echo '<tr class="no-items"><td class="colspanchange" colspan="' . esc_attr( $this->get_column_count() ) . '">';
			$this->no_items();
			echo '</td></tr>';
			return;
		}

		foreach ( $rows as $row ) {
			echo "\t";
			$this->print_row( $row );
		}
	}

	/**
	 * The rows of the current page, in the order they are printed.
	 *
	 * The tree is walked whole and cut to the page afterwards, which is what lets a row be told
	 * what follows it: whether it has a sub-term on this page to unfold, and whether it is the
	 * last of its group. A page that starts inside a subtree is given that subtree's parents for
	 * context, showing their children, or the page would arrive blank.
	 *
	 * A flat listing has none of that: the query paged it, and every term stands on its own.
	 *
	 * @return array List of rows, each an array as print_row() takes one.
	 */
	private function page_rows() {
		$terms = is_array( $this->items ) ? $this->items : array();

		if ( empty( $terms ) ) {
			return array();
		}

		if ( $this->is_flat() ) {
			$rows = array();

			foreach ( $terms as $term ) {
				$rows[] = array( 'term' => $term );
			}

			return $rows;
		}

		$args = wp_parse_args(
			(array) $this->callback_args,
			array(
				'page'   => 1,
				'number' => 20,
			)
		);

		$per_page = max( 1, (int) $args['number'] );
		$start    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$tree = $this->walk_tree( $terms );
		$rows = array_slice( $tree, $start, $per_page );

		if ( empty( $rows ) ) {
			return array();
		}

		return $this->mark_rows( array_merge( $this->context_rows( $tree, $start ), $rows ) );
	}

	/**
	 * The terms in tree order, each with the depth it is printed at.
	 *
	 * @param array $terms The terms of the taxonomy, as the query returned them.
	 * @return array List of array( 'term' => Multisite_Term, 'depth' => int ).
	 */
	private function walk_tree( $terms ) {
		$by_parent = array();

		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$rows = array();
		$this->walk_branch( $by_parent, 0, 0, $rows );

		return $rows;
	}

	/**
	 * Append one parent's children, and their own, to the rows being built.
	 *
	 * Each parent's list is taken out of $by_parent as it is walked, so a term is printed once
	 * however the parent column reads — a cycle in it would otherwise recurse forever.
	 *
	 * @param array $by_parent Parent term id => its terms, consumed as they are walked.
	 * @param int   $parent_id Parent term id to descend from.
	 * @param int   $depth     Depth the children sit at.
	 * @param array $rows      Rows built so far.
	 * @return void
	 */
	private function walk_branch( &$by_parent, $parent_id, $depth, &$rows ) {
		if ( ! isset( $by_parent[ $parent_id ] ) ) {
			return;
		}

		$terms = $by_parent[ $parent_id ];
		unset( $by_parent[ $parent_id ] );

		foreach ( $terms as $term ) {
			$rows[] = array(
				'term'  => $term,
				'depth' => $depth,
			);

			$this->walk_branch( $by_parent, (int) $term->multisite_term_id, $depth + 1, $rows );
		}
	}

	/**
	 * The ancestors of the row a page starts on, as rows showing their children.
	 *
	 * They are the rows before it, each one shallower than the last: in tree order the nearest
	 * row at depth N - 1 is the parent of the row at depth N.
	 *
	 * @param array $tree  The whole tree, as walk_tree() returned it.
	 * @param int   $start Index the page starts at.
	 * @return array Rows, outermost first.
	 */
	private function context_rows( $tree, $start ) {
		$rows  = array();
		$depth = $tree[ $start ]['depth'] - 1;

		for ( $i = $start - 1; $i >= 0 && $depth >= 0; $i-- ) {
			if ( $tree[ $i ]['depth'] === $depth ) {
				$row             = $tree[ $i ];
				$row['expanded'] = true;

				array_unshift( $rows, $row );
				--$depth;
			}
		}

		return $rows;
	}

	/**
	 * Fill in what a row can only be told by the rows around it on the page.
	 *
	 * A row has sub-terms to unfold when the row below it is deeper — a subtree split across
	 * pages leaves a parent with nothing here to open. A row is hidden when the row that would
	 * unfold it is above it and folded, which is what "collapsed" means. And a row is last in its
	 * group when nothing at its own depth follows it before the tree comes back up.
	 *
	 * @param array $rows The page's rows, in print order.
	 * @return array The same rows, marked.
	 */
	private function mark_rows( $rows ) {
		$total   = count( $rows );
		$printed = array();

		for ( $i = 0; $i < $total; $i++ ) {
			$term   = $rows[ $i ]['term'];
			$depth  = $rows[ $i ]['depth'];
			$below  = isset( $rows[ $i + 1 ] ) ? $rows[ $i + 1 ]['depth'] : $depth;
			$parent = (int) $term->parent;

			$rows[ $i ]['has_children'] = $below > $depth;
			$rows[ $i ]['hidden']       = isset( $printed[ $parent ] ) && ! $printed[ $parent ];

			$printed[ (int) $term->multisite_term_id ] = ! empty( $rows[ $i ]['expanded'] );
		}

		$seen = array();

		for ( $i = $total - 1; $i >= 0; $i-- ) {
			$depth = $rows[ $i ]['depth'];

			$rows[ $i ]['last'] = empty( $seen[ $depth ] );

			foreach ( array_keys( $seen ) as $level ) {
				if ( $level > $depth ) {
					unset( $seen[ $level ] );
				}
			}

			$seen[ $depth ] = true;
		}

		return $rows;
	}

	/**
	 * The term hierarchy behind the current listing.
	 *
	 * @return array Parent term id => child term ids; empty when the listing is flat.
	 */
	private function get_children() {
		if ( null === $this->children ) {
			$this->children = $this->is_flat() ? array() : _get_multisite_term_hierarchy( $this->screen->taxonomy );
		}

		return $this->children;
	}

	/**
	 * Whether the listing is a plain list rather than a tree.
	 *
	 * A sorted column or a search prints every match in its own right, children included, and a
	 * non-hierarchical taxonomy has nothing to nest. Those listings are paged by the query; a tree
	 * is walked whole and paged here.
	 *
	 * @return bool
	 */
	private function is_flat() {
		$args = wp_parse_args( (array) $this->callback_args, array( 'search' => '' ) );

		return ! is_multisite_taxonomy_hierarchical( $this->screen->taxonomy )
			|| isset( $args['orderby'] )
			|| ! empty( $args['search'] );
	}

	/**
	 * The inverse of get_children(), for walking a term back up to its root.
	 *
	 * @return array Multisite term id => parent term id.
	 */
	private function get_parents() {
		if ( null === $this->parents ) {
			$this->parents = array();
			foreach ( $this->get_children() as $parent => $children ) {
				foreach ( (array) $children as $child ) {
					$this->parents[ (int) $child ] = (int) $parent;
				}
			}
		}

		return $this->parents;
	}

	/**
	 * How deep a term sits in the tree that is being listed.
	 *
	 * This is for a row rendered on its own, where there is no tree around it to count: the term
	 * is walked up to its root instead. Only the edges get_parents() knows about count, which is
	 * what keeps a flat listing flat.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return int Depth, 0 for a top-level term.
	 */
	private function term_level( $multisite_term ) {
		$parents = $this->get_parents();
		$level   = 0;
		$id      = (int) $multisite_term->multisite_term_id;
		$seen    = array();

		while ( isset( $parents[ $id ] ) && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			++$level;
			$id = $parents[ $id ];
		}

		return $level;
	}

	/**
	 * Whether the listing has a tree in it to fold.
	 *
	 * @return bool
	 */
	private function is_collapsible() {
		return ! empty( $this->get_children() );
	}

	/**
	 * Add the class the collapsing script and its styles hang off.
	 *
	 * @return array Table CSS classes.
	 */
	protected function get_table_classes() {
		$classes = parent::get_table_classes();

		if ( $this->is_collapsible() ) {
			/*
			 * A tree wants neither of the defaults: `fixed` splits the width evenly, which squeezes
			 * the one column that carries the indentation, and `striped` shades every second row
			 * with :nth-child, which counts the folded-away rows and so shades at random.
			 */
			$classes   = array_values( array_diff( $classes, array( 'fixed', 'striped' ) ) );
			$classes[] = 'mtax-collapsible';
		}

		return $classes;
	}

	/**
	 * Add the expand/collapse-everything button to the top table nav.
	 *
	 * Its two labels ride along as data attributes; the script has no other string to localize.
	 *
	 * @param string $which Which nav is being printed, 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which || ! $this->is_collapsible() ) {
			return;
		}

		printf(
			'<button type="button" class="button mtax-toggle-all" aria-expanded="false" data-expand-label="%1$s" data-collapse-label="%2$s">%1$s</button>',
			esc_attr__( 'Expand all', 'multitaxo' ),
			esc_attr__( 'Collapse all', 'multitaxo' )
		);
	}

	/**
	 * Display one row, on its own.
	 *
	 * The ajax handlers render a term this way — one just added, one back from Quick Edit — into
	 * a page that is already open, so the row arrives visible and its depth comes from the term's
	 * own ancestry rather than from the rows around it.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @param bool           $expanded Whether this row's own children are to be shown.
	 * @return void
	 */
	public function single_row( $multisite_term, $expanded = false ) {
		$children = $this->get_children();

		$this->print_row(
			array(
				'term'         => $multisite_term,
				'depth'        => $this->term_level( $multisite_term ),
				'expanded'     => (bool) $expanded,
				'has_children' => isset( $children[ (int) $multisite_term->multisite_term_id ] ),
			)
		);
	}

	/**
	 * Print one row of the table.
	 *
	 * Depth appears once, as the `level-N` class: the script folds the tree by comparing one
	 * row's depth to the next, and the stylesheet turns the same class into the indent. The name
	 * column used to carry it as em-dash padding instead, which put it in the term's own name.
	 *
	 * @param array $row The row: its term, its depth, and what the rest of the page makes of it.
	 * @return void
	 */
	private function print_row( $row ) {
		$row = wp_parse_args(
			$row,
			array(
				'depth'        => 0,
				'expanded'     => false,
				'hidden'       => false,
				'has_children' => false,
				'last'         => false,
			)
		);

		$row['term'] = sanitize_multisite_term( $row['term'], $this->taxonomy );
		$this->row   = $row;

		$classes = array( 'level-' . (int) $row['depth'] );

		if ( $row['has_children'] ) {
			$classes[] = 'has-children';
		}

		if ( $row['last'] ) {
			$classes[] = 'mtax-last';
		}

		printf(
			'<tr id="tag-%1$s" class="%2$s"%3$s>',
			esc_attr( $row['term']->multisite_term_id ),
			esc_attr( implode( ' ', $classes ) ),
			$row['hidden'] ? ' hidden' : '' // phpcs:ignore WordPress.Security.EscapeOutput -- Literal attribute.
		);
		$this->single_row_columns( $row['term'] );
		echo '</tr>';
	}

	/**
	 * Column check boxes content.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_cb( $multisite_term ) {
		if ( current_user_can( 'delete_multisite_term', $multisite_term->multisite_term_id ) ) {
			/* translators: %s: multisite term name */
			return '<label class="screen-reader-text" for="cb-select-' . esc_attr( $multisite_term->multisite_term_id ) . '">' . sprintf( __( 'Select %s', 'multitaxo' ), $multisite_term->name ) . '</label>'
				. '<input type="checkbox" name="delete_multisite_terms[]" value="' . esc_attr( $multisite_term->multisite_term_id ) . '" id="cb-select-' . esc_attr( $multisite_term->multisite_term_id ) . '" />';
		}

		return '&nbsp;';
	}

	/**
	 * The disclosure button that folds a term's sub-terms away.
	 *
	 * It leads the name column rather than sitting with the bulk checkbox, so that it is indented
	 * along with the term it belongs to and the arrows themselves draw the shape of the tree. The
	 * label counts what is behind it, which is the one thing a folded row cannot show.
	 *
	 * No aria-controls: a subtree can be split across pages, so the rows it would name are not
	 * always on this one. A row whose sub-terms all landed on another page gets no button at all.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string Button markup, empty for a term with nothing to unfold here.
	 */
	private function term_toggle( $multisite_term ) {
		$id       = (int) $multisite_term->multisite_term_id;
		$children = $this->get_children();

		if ( empty( $this->row['has_children'] ) || ! isset( $children[ $id ] ) ) {
			return '';
		}

		// The label counts the term's sub-terms, not the rows this page happens to hold.
		$count = count( (array) $children[ $id ] );

		$label = sprintf(
			/* translators: 1: number of sub-terms, 2: multisite term name */
			_n( 'Show %1$s sub-term of %2$s', 'Show %1$s sub-terms of %2$s', $count, 'multitaxo' ),
			number_format_i18n( $count ),
			$multisite_term->name
		);

		$expanded = empty( $this->row['expanded'] ) ? 'false' : 'true';

		return sprintf(
			'<button type="button" class="mtax-term-toggle" aria-expanded="%1$s" title="%2$s"><span class="screen-reader-text">%2$s</span></button>',
			esc_attr( $expanded ),
			esc_attr( $label )
		);
	}

	/**
	 * Return column's name content
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_name( $multisite_term ) {
		$multisite_taxonomy = $this->screen->taxonomy;

		/**
		 * Filters display of the multisite term name in the multiste terms list table.
		 *
		 * Depth used to be spelled into this string as a run of em-dashes. It is an indent on the
		 * cell now, so what arrives here is the term's name and nothing else.
		 *
		 * @see Multisite_Terms_List_Table::column_name()
		 *
		 * @param string $pad_tag_name The multisite term name.
		 * @param Multisite_Term $multisite_term         Multisite term object.
		 */
		$name = apply_filters( 'multisite_term_name', $multisite_term->name, $multisite_term );

		$qe_data = get_multisite_term( $multisite_term->multisite_term_id, $multisite_taxonomy, OBJECT, 'edit' );

		if ( wp_doing_ajax() ) {
			$uri = wp_get_referer();
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification
				$uri = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		}

		$edit_link = add_query_arg(
			'wp_http_referer',
			rawurlencode( wp_unslash( $uri ) ),
			get_edit_multisite_term_link( $multisite_term->multisite_term_id, $multisite_taxonomy, $this->screen->post_type )
		);

		$out = $this->term_toggle( $multisite_term ) . sprintf(
			'<strong><a class="row-title" href="%s" aria-label="%s">%s</a></strong>%s<br />',
			esc_url( $edit_link ),
			/* translators: %s: multisite term name */
			esc_attr( sprintf( __( '&#8220;%s&#8221; (Edit)', 'multitaxo' ), $multisite_term->name ) ),
			$name,
			// Sanitized by multisite_term_display_suffix(). No object here: this lists terms as
			// terms, so only what is true of the term itself can be shown.
			multisite_term_display_suffix( $multisite_term, 'list-table', array( 'taxonomy' => $multisite_taxonomy ) )
		);

		$out .= '<div class="hidden" id="inline_' . esc_attr( $qe_data->multisite_term_id ) . '">';
		$out .= '<div class="name">' . esc_html( $qe_data->name ) . '</div>';

		/** This filter is documented in wp-admin/edit-tag-form.php */
		$out .= '<div class="slug">' . apply_filters( 'editable_slug', $qe_data->slug, $qe_data ) . '</div>';
		$out .= '<div class="parent">' . $qe_data->parent . '</div></div>';

		return $out;
	}

	/**
	 * Gets the name of the default primary column.
	 *
	 * @access protected
	 *
	 * @return string Name of the default primary column, in this case, 'name'.
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Generates and displays row action links.
	 *
	 * @access protected
	 *
	 * @param Multisite_Term $multisite_term  Multisite term being acted upon.
	 * @param string         $column_name     Current column name.
	 * @param string         $primary         Primary column name.
	 * @return string Row actions output for multiste terms.
	 */
	protected function handle_row_actions( $multisite_term, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$multisite_taxonomy = $this->screen->taxonomy;
		$mu_tax             = get_multisite_taxonomy( $multisite_taxonomy );
		if ( wp_doing_ajax() ) {
			$uri = wp_get_referer();
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification
				$uri = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		}

		$edit_link = add_query_arg(
			'wp_http_referer',
			rawurlencode( wp_unslash( $uri ) ),
			get_edit_multisite_term_link( $multisite_term->multisite_term_id, $multisite_taxonomy )
		);

		$actions = array();
		if ( current_user_can( 'edit_multisite_term', $multisite_term->multisite_term_id ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( $edit_link ),
				/* translators: %s: multisite term name */
				esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;', 'multitaxo' ), $multisite_term->name ) ),
				__( 'Edit', 'multitaxo' )
			);
			$actions['inline hide-if-no-js'] = sprintf(
				'<a href="#" class="editinline aria-button-if-js" aria-label="%s">%s</a>',
				/* translators: %s: multisite term name */
				esc_attr( sprintf( __( 'Quick edit &#8220;%s&#8221; inline', 'multitaxo' ), $multisite_term->name ) ),
				__( 'Quick&nbsp;Edit', 'multitaxo' )
			);
		}
		if ( current_user_can( 'delete_multisite_term', $multisite_term->multisite_term_id ) ) {
			$actions['delete'] = sprintf(
				'<a href="%s" class="delete-multisite-term aria-button-if-js" aria-label="%s">%s</a>',
				wp_nonce_url(
					add_query_arg(
						array(
							'page'              => 'multisite_term_list_' . $multisite_taxonomy,
							'action'            => 'delete',
							'multisite_term_id' => $multisite_term->multisite_term_id,
						)
					),
					'delete-multisite_term_' . $multisite_term->multisite_term_id
				),
				/* translators: %s: multisite term name */
				esc_attr( sprintf( __( 'Delete &#8220;%s&#8221;', 'multitaxo' ), $multisite_term->name ) ),
				__( 'Delete', 'multitaxo' )
			);
		}

		/**
		 * Filters the action links displayed for each multisite term in the multiste terms list table.
		 *
		 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the taxonomy slug.
		 *
		 * @param array  $actions An array of action links to be displayed. Default
		 *                        'Edit', 'Quick Edit', 'Delete', and 'View'.
		 * @param Multisite_Term $multisite_term    Term object.
		 */
		$actions = apply_filters( "multitiste_taxonomy_{$multisite_taxonomy}_row_actions", $actions, $multisite_term );

		return $this->row_actions( $actions );
	}

	/**
	 * Get column description content.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_description( $multisite_term ) {
		return $multisite_term->description;
	}

	/**
	 * Get the slug column content.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_slug( $multisite_term ) {
		return apply_filters( 'editable_slug', $multisite_term->slug, $multisite_term );
	}

	/**
	 * Get post count column content.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_posts( $multisite_term ) {
		$count  = absint( $multisite_term->count );
		$mu_tax = get_multisite_taxonomy( $this->screen->taxonomy );

		if ( 0 === $count ) {
			return number_format_i18n( $count );
		}

		// Link the count to the network-admin drill-down listing the assigned objects.
		// The drill-down page registers on network_admin_menu, so the link must target
		// the network admin — admin_url() would 403 on a network-only page.
		$drilldown = add_query_arg(
			array(
				'page'              => 'multisite_term_objects',
				'taxonomy'          => $mu_tax->name,
				'multisite_term_id' => $multisite_term->multisite_term_id,
			),
			network_admin_url( 'admin.php' )
		);

		// Users and sites get their own destination: the native Network → Users / Sites screens,
		// filtered to this term, so admins land in the familiar list (search, bulk actions). Posts
		// have no equivalent network list, so they keep the generic drill-down.
		$term_filter_args = array(
			'multisite_taxonomy' => $mu_tax->name,
			'multisite_term_id'  => $multisite_term->multisite_term_id,
		);
		$users_filter     = add_query_arg( $term_filter_args, network_admin_url( 'users.php' ) );
		$sites_filter     = add_query_arg( $term_filter_args, network_admin_url( 'sites.php' ) );

		// $multisite_term->count sums every object-type namespace (posts, users, blogs).
		// Split it so a mixed taxonomy reads e.g. "👤 5 · 📄 12" instead of one ambiguous total.
		$by_type = get_multisite_term_objects_by_type( $multisite_term->multisite_term_id, $mu_tax->name );
		$icons   = array(
			'user' => array( '👤', __( 'Users', 'multitaxo' ), $users_filter ),
			'post' => array( '📄', __( 'Posts', 'multitaxo' ), $drilldown ),
			'blog' => array( '🌐', __( 'Sites', 'multitaxo' ), $sites_filter ),
		);
		$parts   = array();
		foreach ( $icons as $namespace => $meta ) {
			if ( ! empty( $by_type[ $namespace ] ) ) {
				$inner   = $meta[0] . ' ' . number_format_i18n( count( $by_type[ $namespace ] ) );
				$parts[] = '<a href="' . esc_url( $meta[2] ) . '" title="' . esc_attr( $meta[1] ) . '">' . $inner . '</a>';
			}
		}

		if ( ! $parts ) {
			return '<a href="' . esc_url( $drilldown ) . '">' . number_format_i18n( $count ) . '</a>';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Get links column.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @return string
	 */
	public function column_links( $multisite_term ) {
		$count = number_format_i18n( $multisite_term->count );
		if ( $count ) {
			$count = "<a href='link-manager.php?cat_id=$multisite_term->multisite_term_id'>$count</a>";
		}
		return $count;
	}

	/**
	 * Default template for costum column added by plugins.
	 *
	 * @param Multisite_Term $multisite_term Multisite term object.
	 * @param string         $column_name The column name.
	 * @return string
	 */
	public function column_default( $multisite_term, $column_name ) {
		/**
		 * Filters the displayed columns in the multiste terms list table.
		 *
		 * The dynamic portion of the hook name, `$this->screen->taxonomy`,
		 * refers to the slug of the current taxonomy.
		 *
		 * @param string $string      Blank string.
		 * @param string $column_name Name of the column.
		 * @param int    $multisite_term_id     Term ID.
		 */
		return apply_filters( "manage_{$this->screen->taxonomy}_custom_column", '', $column_name, $multisite_term->multisite_term_id );
	}

	/**
	 * Outputs the hidden row displayed when inline editing.
	 */
	public function inline_edit() {
		$mu_tax = get_multisite_taxonomy( $this->screen->taxonomy );

		if ( ! current_user_can( $mu_tax->cap->edit_multisite_terms ) ) {
			return;
		}
		?>

		<form method="get"><table style="display: none"><tbody id="inlineedit">
			<tr id="inline-edit" class="inline-edit-row" style="display: none"><td colspan="<?php echo esc_attr( $this->get_column_count() ); ?>" class="colspanchange">

				<fieldset>
					<legend class="inline-edit-legend"><?php esc_html_e( 'Quick Edit', 'multitaxo' ); ?></legend>
					<div class="inline-edit-col">
						<label>
							<span class="title"><?php echo esc_html_x( 'Name', 'term name', 'multitaxo' ); ?></span>
							<span class="input-text-wrap"><input type="text" name="name" class="ptitle" value="" /></span>
						</label>
						<label>
							<span class="title"><?php esc_html_e( 'Slug', 'multitaxo' ); ?></span>
							<span class="input-text-wrap"><input type="text" name="slug" class="ptitle" value="" /></span>
						</label>
					</div>
				</fieldset>
		<?php

		$core_columns = array(
			'cb'          => true,
			'description' => true,
			'name'        => true,
			'slug'        => true,
			'posts'       => true,
		);

		list( $columns ) = $this->get_column_info();
		foreach ( $columns as $column_name => $column_display_name ) {
			if ( isset( $core_columns[ $column_name ] ) ) {
				continue;
			}
			do_action( 'quick_edit_custom_box', $column_name, 'edit-multisite-terms', $this->screen->taxonomy );
		}

		?>

		<p class="inline-edit-save submit">
			<button type="button" class="cancel button alignleft"><?php esc_html_e( 'Cancel', 'multitaxo' ); ?></button>
			<button type="button" class="save button button-primary alignright"><?php echo esc_html( $mu_tax->labels->update_item ); ?></button>
			<span class="spinner"></span>
			<span class="error" style="display:none;"></span>
			<?php wp_nonce_field( 'ajax_edit_multisite_tax', 'nonce_multisite_inline_edit', false ); ?>
			<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $this->screen->taxonomy ); ?>" />
			<input type="hidden" name="screen" value="<?php echo esc_attr( $this->screen->id ); ?>" />
			<br class="clear" />
		</p>
		</td></tr>
		</tbody></table></form>
		<?php
	}
}
