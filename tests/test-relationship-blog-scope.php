<?php
/**
 * Blog scoping of relationship reads.
 *
 * Object IDs are only unique per blog, so a relationship read has to name the blog it means.
 * These tests cover the `blog_id` query var of Multisite_Term_Query and the `$blog_id` argument
 * get_object_multisite_terms() hands it. The scope object itself is covered by
 * {@see Test_Object_Scope}.
 *
 * @package multitaxo
 */

/**
 * Blog-scoped relationship read tests.
 */
class Test_Relationship_Blog_Scope extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'blog_scope_tax';

	/**
	 * Register a fresh taxonomy before each test.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
	}

	/**
	 * Create a term and return its multisite_term_multisite_taxonomy_id.
	 *
	 * @param string $name Term name.
	 * @return int The multisite_term_multisite_taxonomy_id of the created term.
	 */
	private function make_term( string $name ): int {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		return (int) $res['multisite_term_multisite_taxonomy_id'];
	}

	/**
	 * Read relationship mtmt_ids through the query class.
	 *
	 * @param array $args Extra query vars.
	 * @return int[] multisite_term_multisite_taxonomy_ids.
	 */
	private function query_mtmt_ids( array $args ): array {
		$query = new Multisite_Term_Query();
		$terms = $query->query(
			array_merge(
				array(
					'taxonomy' => $this->tax,
					'fields'   => 'mtmt_ids',
					'orderby'  => 'none',
				),
				$args
			)
		);
		return array_map( 'intval', (array) $terms );
	}

	/**
	 * The same object id on two blogs keeps two independent term sets.
	 */
	public function test_post_read_is_limited_to_its_blog() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 555;
		$other     = get_current_blog_id() + 100;

		set_object_multisite_terms( $object_id, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( $object_id, array( $elsewhere ), $this->tax, $other, false, '' );

		$mine = array_map( 'intval', get_object_multisite_terms( $object_id, $this->tax, get_current_blog_id(), array( 'fields' => 'mtmt_ids' ), '' ) );
		$this->assertSame( array( $here ), $mine );

		$theirs = array_map( 'intval', get_object_multisite_terms( $object_id, $this->tax, $other, array( 'fields' => 'mtmt_ids' ), '' ) );
		$this->assertSame( array( $elsewhere ), $theirs );
	}

	/**
	 * Omitting the blog id reads the current blog, not the whole network.
	 */
	public function test_post_read_defaults_to_the_current_blog() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 556;

		set_object_multisite_terms( $object_id, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( $object_id, array( $elsewhere ), $this->tax, get_current_blog_id() + 100, false, '' );

		$read = array_map( 'intval', get_object_multisite_terms( $object_id, $this->tax, 0, array( 'fields' => 'mtmt_ids' ), '' ) );
		$this->assertSame( array( $here ), $read );
	}

	/**
	 * Reading across every blog is possible, but only by asking for it.
	 */
	public function test_null_blog_id_spans_every_blog() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 557;

		set_object_multisite_terms( $object_id, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( $object_id, array( $elsewhere ), $this->tax, get_current_blog_id() + 100, false, '' );

		$read = $this->query_mtmt_ids(
			array(
				'object_ids'  => $object_id,
				'object_type' => '',
				'blog_id'     => null,
			)
		);
		sort( $read );
		$expected = array( $here, $elsewhere );
		sort( $expected );
		$this->assertSame( $expected, $read );
	}

	/**
	 * User and blog relationships are network-global, so a blog id must not hide them.
	 */
	public function test_network_global_namespaces_ignore_the_blog_id() {
		$user_term = $this->make_term( 'User Term' );
		$user_id   = $this->factory()->user->create();

		set_object_multisite_terms( $user_id, array( $user_term ), $this->tax, 0, false, 'user' );

		$read = array_map( 'intval', get_object_multisite_terms( $user_id, $this->tax, get_current_blog_id() + 100, array( 'fields' => 'mtmt_ids' ), 'user' ) );
		$this->assertSame( array( $user_term ), $read );
	}

	/**
	 * Without object ids, blog_id alone lists the terms in use on that blog.
	 */
	public function test_blog_id_alone_lists_the_terms_used_on_a_blog() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$other     = get_current_blog_id() + 100;

		set_object_multisite_terms( 601, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( 602, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( 603, array( $elsewhere ), $this->tax, $other, false, '' );

		$this->assertSame( array( $here ), $this->query_mtmt_ids( array( 'blog_id' => get_current_blog_id() ) ) );
		$this->assertSame( array( $elsewhere ), $this->query_mtmt_ids( array( 'blog_id' => $other ) ) );
	}

	/**
	 * Deleting a term reassigns relationships on every blog it was used on.
	 */
	public function test_deleting_a_term_clears_relationships_on_every_blog() {
		$admin = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );

		$doomed = insert_multisite_term( 'Doomed', $this->tax, array(), false );
		$this->assertNotWPError( $doomed );
		$term_id = (int) $doomed['multisite_term_id'];
		$mtmt_id = (int) $doomed['multisite_term_multisite_taxonomy_id'];
		$other   = get_current_blog_id() + 100;

		set_object_multisite_terms( 701, array( $mtmt_id ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( 701, array( $mtmt_id ), $this->tax, $other, false, '' );

		$this->assertTrue( delete_multisite_term( $term_id, $this->tax ) );

		$this->assertSame( array(), array_map( 'intval', get_object_multisite_terms( 701, $this->tax, get_current_blog_id(), array( 'fields' => 'mtmt_ids' ), '' ) ) );
		$this->assertSame( array(), array_map( 'intval', get_object_multisite_terms( 701, $this->tax, $other, array( 'fields' => 'mtmt_ids' ), '' ) ) );
	}
}
