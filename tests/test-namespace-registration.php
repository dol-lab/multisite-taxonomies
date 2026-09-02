<?php
/**
 * Tests for namespace-aware registration and relationship cleanup.
 *
 * `$object_type` mixes post types with ID namespaces, which is why the two questions ("which post
 * types?" and "which namespaces?") need separate accessors. The cleanup tests cover the rows that
 * belong to no blog and were therefore outliving the thing they describe.
 *
 * @package multitaxo
 */

/**
 * Taxonomy namespace accessors and per-namespace orphan cleanup.
 */
class Test_Namespace_Registration extends WP_UnitTestCase {

	/**
	 * Relationships table name.
	 *
	 * @var string
	 */
	private $rel;

	/**
	 * Resolve the relationships table before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->rel = $wpdb->base_prefix . 'multisite_term_relationships';
	}

	/**
	 * Count relationship rows for one object.
	 *
	 * @param Multisite_Object $target Object to count rows for.
	 * @return int Number of rows.
	 */
	private function row_count( Multisite_Object $target ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->rel} WHERE object_id = %d AND blog_id = %d AND object_type = %s",
				$target->id(),
				$target->blog_id(),
				$target->object_type()
			)
		);
		// phpcs:enable
	}

	/**
	 * Create a term in a taxonomy and return its term ID.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy name.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name, string $taxonomy ): int {
		$res = insert_multisite_term( $name, $taxonomy, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * Post types and namespaces are two different questions about one registration.
	 */
	public function test_namespaces_and_post_types_are_separate() {
		$tax = register_multisite_taxonomy( 'nsr_mixed', array( 'post', 'page', 'user' ) );

		$namespaces = $tax->namespaces();
		sort( $namespaces );
		$this->assertSame( array( '', 'user' ), $namespaces, 'both post types collapse into one namespace' );
		$this->assertSame( array( 'post', 'page' ), $tax->post_types() );
		$this->assertTrue( $tax->is_mixed() );
		$this->assertTrue( $tax->supports_namespace( 'page' ), 'a post type is the post namespace' );
		$this->assertTrue( $tax->supports_namespace( 'user' ) );
		$this->assertFalse( $tax->supports_namespace( 'blog' ) );
	}

	/**
	 * A single-namespace taxonomy is not mixed and has no post types.
	 */
	public function test_user_only_taxonomy() {
		$tax = register_multisite_taxonomy( 'nsr_users', array( 'user' ) );

		$this->assertSame( array( 'user' ), $tax->namespaces() );
		$this->assertSame( array(), $tax->post_types() );
		$this->assertFalse( $tax->is_mixed() );
	}

	/**
	 * The namespace lookup answers the relationship question, unlike the registered-name one.
	 */
	public function test_taxonomies_for_namespace() {
		register_multisite_taxonomy( 'nsr_cpt_only', array( 'nsr_cpt' ) );
		register_multisite_taxonomy( 'nsr_user_only', array( 'user' ) );

		$this->assertContains( 'nsr_cpt_only', get_multisite_taxonomies_for_namespace( '' ) );
		$this->assertNotContains( 'nsr_user_only', get_multisite_taxonomies_for_namespace( '' ) );
		$this->assertContains( 'nsr_user_only', get_multisite_taxonomies_for_namespace( 'user' ) );

		$this->assertSame(
			array(),
			get_object_multisite_taxonomies( '' ),
			'the registered-name lookup cannot answer for a normalized namespace'
		);
	}

	/**
	 * Deleting a user removes the relationship rows nothing else would reach.
	 */
	public function test_deleted_user_purges_its_relationships() {
		register_multisite_taxonomy( 'nsr_purge_user', array( 'user' ) );
		$term = $this->make_term( 'Purge User', 'nsr_purge_user' );

		$user_id = self::factory()->user->create();
		$object  = Multisite_Object::user( $user_id );
		$object->set_terms( 'nsr_purge_user', array( $term ) );
		$this->assertSame( 1, $this->row_count( $object ) );

		wpmu_delete_user( $user_id );

		$this->assertSame( 0, $this->row_count( $object ), 'the user rows should go with the user' );
	}

	/**
	 * Deleting a user from one site leaves the network-global rows alone.
	 *
	 * `wp_delete_user()` fires `deleted_user` on multisite even though it only removes the user
	 * from the current site, so the purge must not run for it.
	 */
	public function test_user_removed_from_one_site_keeps_its_relationships() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		register_multisite_taxonomy( 'nsr_keep_user', array( 'user' ) );
		$term = $this->make_term( 'Keep User', 'nsr_keep_user' );

		$user_id = self::factory()->user->create();
		$object  = Multisite_Object::user( $user_id );
		$object->set_terms( 'nsr_keep_user', array( $term ) );

		add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
		wp_delete_user( $user_id );

		$this->assertNotFalse( get_userdata( $user_id ), 'the user should still exist on the network' );
		$this->assertSame( 1, $this->row_count( $object ), 'a per-site removal is not a deletion' );
	}

	/**
	 * Deleting a site removes the row that tags the site itself, not just its posts.
	 */
	public function test_deleted_site_purges_its_own_blog_row() {
		register_multisite_taxonomy( 'nsr_purge_site', array( 'blog' ) );
		$term = $this->make_term( 'Purge Site', 'nsr_purge_site' );

		$site_id = self::factory()->blog->create();
		$object  = Multisite_Object::blog( $site_id );
		$object->set_terms( 'nsr_purge_site', array( $term ) );
		$this->assertSame( 1, $this->row_count( $object ) );

		wp_delete_site( $site_id );

		$this->assertSame( 0, $this->row_count( $object ), 'the row naming the site is network-global, and still has to go' );
	}
}
