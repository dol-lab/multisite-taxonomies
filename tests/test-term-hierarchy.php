<?php
/**
 * Tests for the term hierarchy helpers.
 *
 * Parent/child relations are read through a cached map (`multisite_{$taxonomy}_children`), so these
 * tests exercise the readers after writes that must invalidate it. They also cover the loop guard:
 * a term that ends up its own ancestor makes every walker here recurse forever.
 *
 * @package multitaxo
 */

/**
 * Children, ancestors, count padding and loop breaking.
 */
class Test_Term_Hierarchy extends WP_UnitTestCase {

	/**
	 * Hierarchical taxonomy under test.
	 *
	 * @var string
	 */
	private $tax = 'hierarchy_tax';

	/**
	 * Flat taxonomy, which has no hierarchy at all.
	 *
	 * @var string
	 */
	private $flat_tax = 'hierarchy_flat_tax';

	/**
	 * Register the taxonomies and act as someone allowed to manage terms.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post' ), array( 'hierarchical' => true ) );
		register_multisite_taxonomy( $this->flat_tax, array( 'post' ), array( 'hierarchical' => false ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );
	}

	/**
	 * Create a term and return its ID.
	 *
	 * @param string $name   Term name.
	 * @param int    $parent_id Optional parent term ID.
	 * @param string $tax    Optional taxonomy, defaults to the hierarchical one.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name, int $parent_id = 0, string $tax = '' ): int {
		$res = insert_multisite_term( $name, $tax ? $tax : $this->tax, array( 'parent' => $parent_id ), false );
		$this->assertNotWPError( $res );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * A three-level tree: grandparent > parent > child.
	 *
	 * @return int[] The three term IDs, oldest first.
	 */
	private function make_tree(): array {
		$grandparent = $this->make_term( 'Grandparent' );
		$parent      = $this->make_term( 'Parent', $grandparent );
		$child       = $this->make_term( 'Child', $parent );
		return array( $grandparent, $parent, $child );
	}

	/**
	 * Children are collected recursively, so a grandparent reports the whole subtree.
	 */
	public function test_term_children_are_collected_recursively() {
		list( $grandparent, $parent, $child ) = $this->make_tree();

		$this->assertEqualSets( array( $parent, $child ), array_map( 'intval', get_multisite_term_children( $grandparent, $this->tax ) ) );
		$this->assertSame( array( $child ), array_map( 'intval', get_multisite_term_children( $parent, $this->tax ) ) );
		$this->assertSame( array(), get_multisite_term_children( $child, $this->tax ) );
	}

	/**
	 * A flat taxonomy has no hierarchy map, and an unknown one is an error.
	 */
	public function test_term_children_edge_cases() {
		$flat_id = $this->make_term( 'Flat', 0, $this->flat_tax );

		$this->assertSame( array(), get_multisite_term_children( $flat_id, $this->flat_tax ) );
		$this->assertSame( array(), get_multisite_term_children( 999999, $this->tax ) );
		$this->assertSame( 'invalid_multisite_taxonomy', get_multisite_term_children( 1, 'no_such_tax' )->get_error_code() );
	}

	/**
	 * Ancestry is transitive and one-directional.
	 */
	public function test_term_is_ancestor_of() {
		list( $grandparent, $parent, $child ) = $this->make_tree();
		$unrelated                            = $this->make_term( 'Unrelated' );

		$this->assertTrue( multisite_term_is_ancestor_of( $parent, $child, $this->tax ) );
		$this->assertTrue( multisite_term_is_ancestor_of( $grandparent, $child, $this->tax ), 'two levels up still counts' );
		$this->assertFalse( multisite_term_is_ancestor_of( $child, $parent, $this->tax ) );
		$this->assertFalse( multisite_term_is_ancestor_of( $unrelated, $child, $this->tax ) );
		$this->assertFalse( multisite_term_is_ancestor_of( $parent, $grandparent, $this->tax ), 'a root term has no parent' );
	}

	/**
	 * Ancestors come back lowest first, and the parent id reader agrees with them.
	 */
	public function test_ancestors_and_parent_id() {
		list( $grandparent, $parent, $child ) = $this->make_tree();

		$this->assertSame( array( $parent, $grandparent ), get_multisite_ancestors( $child, $this->tax ) );
		$this->assertSame( array(), get_multisite_ancestors( $grandparent, $this->tax ) );
		$this->assertSame( array(), get_multisite_ancestors( 0, $this->tax ) );

		$this->assertSame( $parent, get_multisite_term_multisite_taxonomy_parent_id( $child, $this->tax ) );
		$this->assertSame( 0, get_multisite_term_multisite_taxonomy_parent_id( $grandparent, $this->tax ) );
		$this->assertFalse( get_multisite_term_multisite_taxonomy_parent_id( 999999, $this->tax ) );
	}

	/**
	 * _get_multisite_term_children() filters a set it is given, returning it in the shape it got.
	 */
	public function test_get_term_children_filters_a_given_set() {
		list( $grandparent, $parent, $child ) = $this->make_tree();
		$unrelated                            = $this->make_term( 'Unrelated' );

		$ids = array( $grandparent, $parent, $child, $unrelated );
		$this->assertEqualSets( array( $parent, $child ), array_map( 'intval', _get_multisite_term_children( $grandparent, $ids, $this->tax ) ) );

		$objects     = array_map(
			function ( $id ) {
				return get_multisite_term( $id, $this->tax );
			},
			$ids
		);
		$descendants = _get_multisite_term_children( $parent, $objects, $this->tax );
		$this->assertCount( 1, $descendants );
		$this->assertSame( $child, (int) $descendants[0]->multisite_term_id, 'objects in, objects out' );

		$this->assertSame( array(), _get_multisite_term_children( $child, $ids, $this->tax ), 'a leaf has no children' );
		$this->assertSame( array(), _get_multisite_term_children( $grandparent, array(), $this->tax ) );
	}

	/**
	 * Padded counts roll a child's published posts up into every ancestor.
	 */
	public function test_pad_counts_rolls_children_up() {
		list( $grandparent, $parent, $child ) = $this->make_tree();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		set_object_multisite_terms( $post_id, array( $child ), $this->tax );

		$terms = get_multisite_terms(
			array(
				'taxonomy'   => $this->tax,
				'hide_empty' => false,
				'pad_counts' => true,
			)
		);

		$counts = array();
		foreach ( $terms as $term ) {
			$counts[ (int) $term->multisite_term_id ] = (int) $term->count;
		}

		$this->assertSame( 1, $counts[ $child ] );
		$this->assertSame( 1, $counts[ $parent ], "a parent counts its child's posts" );
		$this->assertSame( 1, $counts[ $grandparent ] );
	}

	/**
	 * The loop guard refuses the parents that would close a cycle.
	 */
	public function test_loop_check_refuses_self_and_cycles() {
		list( $grandparent, $parent, $child ) = $this->make_tree();

		$this->assertSame( 0, check_multisite_term_hierarchy_for_loops( 0, $child, $this->tax ) );
		$this->assertSame( 0, check_multisite_term_hierarchy_for_loops( $child, $child, $this->tax ), 'a term cannot be its own parent' );
		$this->assertSame( $parent, check_multisite_term_hierarchy_for_loops( $parent, $child, $this->tax ), 'an honest parent is returned unchanged' );
		$this->assertSame( 0, check_multisite_term_hierarchy_for_loops( $child, $grandparent, $this->tax ), 'adopting a descendant would close a cycle' );
	}

	/**
	 * A cycle that does not involve the term being checked is broken instead of refused:
	 * its members are reparented to the root, and the requested parent is allowed.
	 */
	public function test_loop_check_breaks_a_foreign_cycle() {
		$first  = $this->make_term( 'First' );
		$second = $this->make_term( 'Second', $first );
		$outer  = $this->make_term( 'Outer' );

		// Close the cycle behind update_multisite_term()'s back: it has no loop guard of its own.
		update_multisite_term( $first, $this->tax, array( 'parent' => $second ) );

		$this->assertSame( $first, check_multisite_term_hierarchy_for_loops( $first, $outer, $this->tax ) );
		$this->assertSame( 0, (int) get_multisite_term( $first, $this->tax )->parent );
		$this->assertSame( 0, (int) get_multisite_term( $second, $this->tax )->parent );
	}
}
