<?php
/**
 * Tests for the markup Multisite_Terms_List_Table prints for each row.
 *
 * The row classes are an interface, not decoration: collapsible-multisite-terms.js folds the tree
 * by reading `level-N` off the rows, the stylesheet indents by the same class, and the rows below
 * the top level arrive collapsed. Everything the folding needs is decided here, so the script has
 * nothing left to work out — which is what these tests hold in place.
 *
 * @package multitaxo
 */

/**
 * Row classes, the collapsed initial state and the disclosure buttons.
 */
class Test_Terms_List_Table extends WP_UnitTestCase {

	/**
	 * Hierarchical taxonomy under test.
	 *
	 * @var string
	 */
	private $tax = 'list_table_tax';

	/**
	 * Term ids, keyed by the name they were created under.
	 *
	 * @var array
	 */
	private $terms = array();

	/**
	 * Register the taxonomy, act as a super admin and build a three-deep tree.
	 */
	public function set_up() {
		parent::set_up();

		register_multisite_taxonomy( $this->tax, array( 'post' ), array( 'hierarchical' => true ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );

		set_current_screen( 'edit-tags' );

		$root  = insert_multisite_term( 'Root', $this->tax, array(), false );
		$child = insert_multisite_term( 'Child', $this->tax, array( 'parent' => $root['multisite_term_id'] ), false );
		$leaf  = insert_multisite_term( 'Leaf', $this->tax, array( 'parent' => $child['multisite_term_id'] ), false );

		$this->terms = array(
			'root'  => $root['multisite_term_id'],
			'child' => $child['multisite_term_id'],
			'leaf'  => $leaf['multisite_term_id'],
		);
	}

	/**
	 * Put back everything the listing needed: an admin screen makes is_admin() true for whatever
	 * runs next, and a taxonomy left registered turns up in another test's listing.
	 */
	public function tear_down() {
		set_current_screen( 'front' );
		unregister_multisite_taxonomy( $this->tax );
		unset( $_REQUEST['s'] );
		parent::tear_down();
	}

	/**
	 * Render the listing.
	 *
	 * @return string The rows, as printed into the table body.
	 */
	private function render_rows() {
		$table = new Multisite_Terms_List_Table( array( 'taxonomy' => $this->tax ) );
		$table->prepare_items();

		ob_start();
		$table->display_rows_or_placeholder();

		return ob_get_clean();
	}

	/**
	 * The opening tag of one term's row.
	 *
	 * @param string $rows The rendered rows.
	 * @param string $key  Key into $this->terms.
	 * @return string The `<tr ...>` tag, or '' when the term has no row.
	 */
	private function row_tag( $rows, $key ) {
		$found = array();
		preg_match( '/<tr id="tag-' . $this->terms[ $key ] . '"[^>]*>/', $rows, $found );

		return isset( $found[0] ) ? $found[0] : '';
	}

	/**
	 * Each row is tagged with its depth.
	 */
	public function test_rows_carry_their_level() {
		$rows = $this->render_rows();

		$this->assertStringContainsString( 'class="level-0', $this->row_tag( $rows, 'root' ) );
		$this->assertStringContainsString( 'class="level-1', $this->row_tag( $rows, 'child' ) );
		$this->assertStringContainsString( 'class="level-2', $this->row_tag( $rows, 'leaf' ) );
	}

	/**
	 * Only the terms that have children are marked as such, and only those get a disclosure button.
	 */
	public function test_only_parents_are_marked_and_get_a_toggle() {
		$rows = $this->render_rows();

		$this->assertStringContainsString( 'has-children', $this->row_tag( $rows, 'root' ) );
		$this->assertStringContainsString( 'has-children', $this->row_tag( $rows, 'child' ) );
		$this->assertStringNotContainsString( 'has-children', $this->row_tag( $rows, 'leaf' ) );

		$this->assertSame( 2, substr_count( $rows, 'mtax-term-toggle' ) );
		$this->assertStringContainsString( 'Show 1 sub-term of Root', $rows );
	}

	/**
	 * Depth is on the row once: the script and the stylesheet read the same class.
	 */
	public function test_depth_is_not_spelled_out_twice() {
		$rows = $this->render_rows();

		$this->assertStringNotContainsString( 'style=', $this->row_tag( $rows, 'child' ) );
		$this->assertStringNotContainsString( '--mtax-level', $rows );
	}

	/**
	 * The last row of a group is marked, so the guide line ends there instead of running past it.
	 */
	public function test_the_end_of_a_group_is_marked() {
		$rows = $this->render_rows();

		$this->assertStringContainsString( 'mtax-last', $this->row_tag( $rows, 'root' ) );
		$this->assertStringContainsString( 'mtax-last', $this->row_tag( $rows, 'child' ) );
		$this->assertStringContainsString( 'mtax-last', $this->row_tag( $rows, 'leaf' ) );
	}

	/**
	 * A term whose sub-terms all landed on another page has nothing here to unfold, so it gets no
	 * button and is not marked as a parent.
	 */
	public function test_a_parent_whose_children_are_elsewhere_gets_no_toggle() {
		update_user_option( get_current_user_id(), 'edit_multisite_tax_per_page', 1 );

		$rows = $this->render_rows();
		$tag  = $this->row_tag( $rows, 'root' );

		$this->assertNotSame( '', $tag );
		$this->assertStringNotContainsString( 'has-children', $tag );
		$this->assertStringNotContainsString( 'mtax-term-toggle', $rows );

		delete_user_option( get_current_user_id(), 'edit_multisite_tax_per_page' );
	}

	/**
	 * The name is the name: the depth is no longer spelled into it as em-dashes.
	 */
	public function test_names_are_not_padded() {
		$rows = $this->render_rows();

		$this->assertStringNotContainsString( '&#8212;', $rows );
		$this->assertStringContainsString( '>Leaf</a>', $rows );
	}

	/**
	 * The tree arrives collapsed, so nothing flashes open before the script runs.
	 */
	public function test_sub_terms_start_hidden() {
		$rows = $this->render_rows();

		$this->assertStringNotContainsString( 'hidden', $this->row_tag( $rows, 'root' ) );
		$this->assertStringContainsString( 'hidden', $this->row_tag( $rows, 'child' ) );
		$this->assertStringContainsString( 'hidden', $this->row_tag( $rows, 'leaf' ) );
	}

	/**
	 * A row rendered on its own goes into a page that is already open — the ajax handlers do that
	 * for a term just added and for one that came back from Quick Edit — so it must arrive visible.
	 */
	public function test_a_standalone_row_is_not_hidden() {
		$table = new Multisite_Terms_List_Table( array( 'taxonomy' => $this->tax ) );
		$table->prepare_items();

		ob_start();
		$table->single_row( get_multisite_term( $this->terms['leaf'], $this->tax ) );
		$row = ob_get_clean();

		$tag = $this->row_tag( $row, 'leaf' );

		$this->assertStringContainsString( 'class="level-2"', $tag );
		$this->assertStringNotContainsString( 'hidden', $tag );
	}

	/**
	 * A page that starts inside a subtree prints that subtree's parents for context, and those —
	 * plus the term the page is actually for — have to be visible, or the page arrives blank.
	 */
	public function test_a_page_inside_a_subtree_is_not_blank() {
		update_user_option( get_current_user_id(), 'edit_multisite_tax_per_page', 1 );
		$_REQUEST['paged'] = 3;

		$rows = $this->render_rows();

		foreach ( array( 'root', 'child', 'leaf' ) as $key ) {
			$tag = $this->row_tag( $rows, $key );

			$this->assertNotSame( '', $tag, $key . ' should be on the page' );
			$this->assertStringNotContainsString( 'hidden', $tag, $key . ' should be visible' );
		}

		// The two ancestors are showing what is under them, so their arrows say so.
		$this->assertSame( 2, substr_count( $rows, 'aria-expanded="true"' ) );

		unset( $_REQUEST['paged'] );
		delete_user_option( get_current_user_id(), 'edit_multisite_tax_per_page' );
	}

	/**
	 * A search lists every match in its own right, so there is no tree to fold.
	 */
	public function test_search_results_are_flat() {
		$_REQUEST['s'] = 'Child';

		$rows = $this->render_rows();
		$tag  = $this->row_tag( $rows, 'child' );

		$this->assertStringContainsString( 'class="level-0"', $tag );
		$this->assertStringNotContainsString( 'has-children', $tag );
		$this->assertStringNotContainsString( 'hidden', $tag );
		$this->assertStringNotContainsString( 'mtax-term-toggle', $rows );
	}

	/**
	 * The table announces itself to the script, and drops the two defaults a tree cannot use.
	 */
	public function test_table_classes_switch_to_the_tree_layout() {
		$table = new Multisite_Terms_List_Table( array( 'taxonomy' => $this->tax ) );
		$table->prepare_items();

		$method = new ReflectionMethod( $table, 'get_table_classes' );
		$method->setAccessible( true );
		$classes = $method->invoke( $table );

		$this->assertContains( 'mtax-collapsible', $classes );
		$this->assertNotContains( 'fixed', $classes );
		$this->assertNotContains( 'striped', $classes );
	}
}
