<?php
/**
 * Tests for get_multisite_term_object_ids(), the paginated per-namespace reader that backs the
 * front-end users/sites archive (Multisite_Taxonomy_Archive).
 *
 * Verifies the total/page split, that pagination (number/offset) walks the IDs, and that a query
 * for one namespace never returns rows stored under another.
 *
 * @package multitaxo
 */

class Test_Archive_Object_Ids extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning posts, users, and sites, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'aff_archive_tax';

	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
	}

	/**
	 * Create a term and return its multisite_term_id.
	 */
	private function make_term( string $name ): int {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		return (int) $res['multisite_term_id'];
	}

	public function test_total_and_page_slice_for_users() {
		$term_id = $this->make_term( 'Users Archive' );

		// Assign five users to the term in the user namespace.
		$user_ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$uid        = $this->factory()->user->create();
			$user_ids[] = $uid;
			set_object_multisite_terms( $uid, array( $term_id ), $this->tax, 0, false, 'user' );
		}
		sort( $user_ids );

		// First page of two.
		$page1 = get_multisite_term_object_ids(
			$term_id,
			$this->tax,
			'user',
			array(
				'number' => 2,
				'offset' => 0,
			)
		);
		$this->assertSame( 5, $page1['total'], 'total should count every assigned user' );
		$this->assertCount( 2, $page1['ids'], 'a page of two should return two ids' );
		$this->assertSame( array_slice( $user_ids, 0, 2 ), $page1['ids'], 'ids come back ordered by object_id ASC' );

		// Last (partial) page.
		$page3 = get_multisite_term_object_ids(
			$term_id,
			$this->tax,
			'user',
			array(
				'number' => 2,
				'offset' => 4,
			)
		);
		$this->assertSame( 5, $page3['total'] );
		$this->assertSame( array_slice( $user_ids, 4, 2 ), $page3['ids'], 'the final page holds the remainder' );
	}

	public function test_number_zero_returns_all() {
		$term_id = $this->make_term( 'All Users' );
		for ( $i = 0; $i < 3; $i++ ) {
			set_object_multisite_terms( $this->factory()->user->create(), array( $term_id ), $this->tax, 0, false, 'user' );
		}

		$all = get_multisite_term_object_ids( $term_id, $this->tax, 'user' );
		$this->assertSame( 3, $all['total'] );
		$this->assertCount( 3, $all['ids'], 'number 0 (default) returns every id' );
	}

	public function test_namespaces_do_not_bleed() {
		$term_id = $this->make_term( 'Mixed Namespaces' );

		$user_id = $this->factory()->user->create();
		set_object_multisite_terms( $user_id, array( $term_id ), $this->tax, 0, false, 'user' );
		set_object_multisite_terms( 4242, array( $term_id ), $this->tax, 0, false, 'blog' );
		set_object_multisite_terms( 555, array( $term_id ), $this->tax, 7, false, '' );

		$users = get_multisite_term_object_ids( $term_id, $this->tax, 'user' );
		$blogs = get_multisite_term_object_ids( $term_id, $this->tax, 'blog' );

		$this->assertSame( array( $user_id ), $users['ids'], 'user query returns only the user row' );
		$this->assertSame( array( 4242 ), $blogs['ids'], 'blog query returns only the blog row' );
		$this->assertSame( 1, $users['total'] );
		$this->assertSame( 1, $blogs['total'] );
	}

	public function test_array_of_term_ids_unions_and_dedupes() {
		$parent = $this->make_term( 'Parent' );
		$child  = $this->make_term( 'Child' );

		$shared = $this->factory()->user->create(); // assigned to both terms.
		$only   = $this->factory()->user->create(); // assigned to the child only.
		set_object_multisite_terms( $shared, array( $parent ), $this->tax, 0, false, 'user' );
		set_object_multisite_terms( $shared, array( $child ), $this->tax, 0, true, 'user' );
		set_object_multisite_terms( $only, array( $child ), $this->tax, 0, false, 'user' );

		$rolled = get_multisite_term_object_ids( array( $parent, $child ), $this->tax, 'user' );
		$this->assertSame( 2, $rolled['total'], 'a user under two terms in the set is counted once' );
		$this->assertSame( array( $shared, $only ), $rolled['ids'] );
	}

	public function test_empty_term_and_unknown_taxonomy() {
		$term_id = $this->make_term( 'Empty' );

		$empty = get_multisite_term_object_ids( $term_id, $this->tax, 'user' );
		$this->assertSame( 0, $empty['total'] );
		$this->assertSame( array(), $empty['ids'] );

		$unknown = get_multisite_term_object_ids( 1, 'no_such_tax_xyz', 'user' );
		$this->assertSame( 0, $unknown['total'] );
		$this->assertSame( array(), $unknown['ids'] );
	}
}
