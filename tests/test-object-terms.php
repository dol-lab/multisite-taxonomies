<?php
/**
 * DB round-trip tests for object_type-aware relationships.
 *
 * Verifies that set_/add_/remove_/get_object_multisite_terms() carry the namespace through every
 * write and read: user/blog rows land at blog_id 0 with the right object_type, post rows keep an
 * explicit blog_id with object_type '', and reads never cross namespaces. These tests guard
 * relationship creation and retrieval against regressions.
 *
 * @package multitaxo
 */

/**
 * Namespace-aware object term relationship tests.
 */
class Test_Object_Terms extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'aff_terms_tax';

	/**
	 * Relationships table name.
	 *
	 * @var string
	 */
	private $rel;

	/**
	 * Register a fresh taxonomy spanning all three namespaces before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->rel = $wpdb->base_prefix . 'multisite_term_relationships';
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
	 * Read the stored (blog_id, object_type) for a relationship row, or null if absent.
	 *
	 * @param int    $object_id   Object id the term is assigned to.
	 * @param int    $mtmt_id     multisite_term_multisite_taxonomy_id of the term.
	 * @param string $object_type Namespace of the relationship ('user', 'blog' or '').
	 * @return object|null Row with blog_id and object_type, or null when absent.
	 */
	private function stored_row( int $object_id, int $mtmt_id, string $object_type ) {
		global $wpdb;
		// Reading our own relationship table directly to assert on raw stored values; the table
		// name is a trusted constant, not user input.
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT blog_id, object_type FROM {$this->rel} WHERE object_id = %d AND multisite_term_multisite_taxonomy_id = %d AND object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$object_id,
				$mtmt_id,
				$object_type
			)
		);
	}

	/**
	 * User assignments are stored network-globally at blog_id 0 with object_type 'user'.
	 */
	public function test_user_assignment_is_network_global() {
		$mtmt    = $this->make_term( 'User Term' );
		$user_id = $this->factory()->user->create();

		$result = set_object_multisite_terms( $user_id, array( $mtmt ), $this->tax, 0, false, 'user' );
		$this->assertNotWPError( $result );

		$row = $this->stored_row( $user_id, $mtmt, 'user' );
		$this->assertNotNull( $row, 'a user row should exist' );
		$this->assertSame( 0, (int) $row->blog_id, 'user rows are stored at blog_id 0' );
		$this->assertSame( 'user', $row->object_type );
	}

	/**
	 * Blog assignments are stored network-globally at blog_id 0 with object_type 'blog'.
	 */
	public function test_blog_assignment_is_network_global() {
		$mtmt    = $this->make_term( 'Blog Term' );
		$blog_id = 4242; // arbitrary blog id; the relationship layer does not require it to exist.

		$result = set_object_multisite_terms( $blog_id, array( $mtmt ), $this->tax, 0, false, 'blog' );
		$this->assertNotWPError( $result );

		$row = $this->stored_row( $blog_id, $mtmt, 'blog' );
		$this->assertNotNull( $row, 'a blog row should exist' );
		$this->assertSame( 0, (int) $row->blog_id, 'blog rows are stored at blog_id 0' );
		$this->assertSame( 'blog', $row->object_type );
	}

	/**
	 * Post assignments keep an explicit blog_id and an empty object_type.
	 */
	public function test_post_assignment_keeps_blog_and_empty_type() {
		$mtmt    = $this->make_term( 'Post Term' );
		$post_id = 555;

		$result = set_object_multisite_terms( $post_id, array( $mtmt ), $this->tax, 9, false, '' );
		$this->assertNotWPError( $result );

		$row = $this->stored_row( $post_id, $mtmt, '' );
		$this->assertNotNull( $row, 'a post row should exist' );
		$this->assertSame( 9, (int) $row->blog_id, 'post rows keep the explicit blog_id' );
		$this->assertSame( '', $row->object_type );
	}

	/**
	 * The same object_id assigned under different namespaces must not bleed across reads.
	 */
	public function test_reads_do_not_cross_namespaces() {
		$user_term = $this->make_term( 'Only User' );
		$blog_term = $this->make_term( 'Only Blog' );
		$shared_id = 7; // deliberately reuse one id across the user and blog namespaces.

		set_object_multisite_terms( $shared_id, array( $user_term ), $this->tax, 0, false, 'user' );
		set_object_multisite_terms( $shared_id, array( $blog_term ), $this->tax, 0, false, 'blog' );

		$user_read = array_map( 'intval', get_object_multisite_terms( $shared_id, $this->tax, 0, array( 'fields' => 'mtmt_ids' ), 'user' ) );
		$blog_read = array_map( 'intval', get_object_multisite_terms( $shared_id, $this->tax, 0, array( 'fields' => 'mtmt_ids' ), 'blog' ) );

		$this->assertContains( $user_term, $user_read );
		$this->assertNotContains( $blog_term, $user_read, 'blog assignment must not show up in the user namespace' );

		$this->assertContains( $blog_term, $blog_read );
		$this->assertNotContains( $user_term, $blog_read, 'user assignment must not show up in the blog namespace' );
	}

	/**
	 * Add/remove operate within the named namespace.
	 */
	public function test_add_and_remove_within_namespace() {
		$term_a  = $this->make_term( 'Add A' );
		$term_b  = $this->make_term( 'Add B' );
		$user_id = $this->factory()->user->create();

		add_object_multisite_terms( $user_id, array( $term_a ), $this->tax, 0, 'user' );
		add_object_multisite_terms( $user_id, array( $term_b ), $this->tax, 0, 'user' );

		$after_add = array_map( 'intval', get_object_multisite_terms( $user_id, $this->tax, 0, array( 'fields' => 'mtmt_ids' ), 'user' ) );
		$this->assertContains( $term_a, $after_add );
		$this->assertContains( $term_b, $after_add );

		remove_object_multisite_terms( $user_id, array( $term_a ), $this->tax, 0, 'user' );

		$after_remove = array_map( 'intval', get_object_multisite_terms( $user_id, $this->tax, 0, array( 'fields' => 'mtmt_ids' ), 'user' ) );
		$this->assertNotContains( $term_a, $after_remove );
		$this->assertContains( $term_b, $after_remove, 'removing one term leaves the rest' );
	}

	/**
	 * Assigning to a term in an unregistered taxonomy is rejected.
	 */
	public function test_unknown_taxonomy_is_rejected() {
		$result = set_object_multisite_terms( 1, array( 1 ), 'no_such_tax_xyz', 0, false, 'user' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_multisite_taxonomy', $result->get_error_code() );
	}
}
