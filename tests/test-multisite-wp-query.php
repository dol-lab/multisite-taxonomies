<?php
/**
 * Tests for the legacy cross-blog post query.
 *
 * @package multitaxo
 */

/**
 * Cross-blog query access and visibility tests.
 */
class Test_Multisite_WP_Query extends WP_UnitTestCase {

	/**
	 * Taxonomy registered for each test.
	 *
	 * @var string
	 */
	private $tax = 'multisite_wp_query_tax';

	/**
	 * Register the test taxonomy.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ) );
		register_post_type( 'private_query_item', array( 'publicly_queryable' => false ) );
	}

	/**
	 * Remove query filters that a failed assertion could otherwise leak.
	 */
	public function tear_down() {
		remove_all_filters( 'multisite_wp_query_access' );
		unregister_post_type( 'private_query_item' );
		parent::tear_down();
	}

	/**
	 * Create a term and return its relationship-table taxonomy ID.
	 *
	 * @return int Term taxonomy ID.
	 */
	private function make_term() {
		$result = insert_multisite_term( 'Query Term', $this->tax, array(), false );
		$this->assertNotWPError( $result );

		return (int) $result['multisite_term_multisite_taxonomy_id'];
	}

	/**
	 * Query without using or updating the persistent cache.
	 *
	 * @param int $term_id Term taxonomy ID.
	 * @return Multisite_WP_Query Query object.
	 */
	private function run_query( $term_id ) {
		$query  = new Multisite_WP_Query();
		$result = $query->query(
			array(
				'multisite_term_ids' => array( $term_id ),
				'nopaging'           => true,
				'cache'              => false,
				'update_cache'       => false,
			)
		);
		$this->assertNull( $result );

		return $query;
	}

	/**
	 * Password-protected posts and non-public post types must not expose their content.
	 */
	public function test_password_protected_posts_are_excluded() {
		$term_id        = $this->make_term();
		$public_post    = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Public content',
			)
		);
		$protected_post = $this->factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'Protected content',
			)
		);
		$private_post   = $this->factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_type'    => 'private_query_item',
				'post_content' => 'Private post type content',
			)
		);

		set_object_multisite_terms( $public_post, array( $term_id ), $this->tax, get_current_blog_id() );
		set_object_multisite_terms( $protected_post, array( $term_id ), $this->tax, get_current_blog_id() );
		set_object_multisite_terms( $private_post, array( $term_id ), $this->tax, get_current_blog_id() );

		$query = $this->run_query( $term_id );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$this->assertContains( $public_post, $ids );
		$this->assertNotContains( $protected_post, $ids );
		$this->assertNotContains( $private_post, $ids );
	}

	/**
	 * Relationships from another object namespace must not collide with a post ID.
	 */
	public function test_non_post_relationships_are_excluded() {
		global $wpdb;

		$term_id  = $this->make_term();
		$post_id  = $this->factory()->post->create( array( 'post_status' => 'publish' ) );
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->multisite_term_relationships,
			array(
				'blog_id'                              => get_current_blog_id(),
				'object_id'                            => $post_id,
				'multisite_term_multisite_taxonomy_id' => $term_id,
				'multisite_term_order'                 => 0,
				'object_type'                          => 'user',
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);
		$this->assertSame( 1, $inserted );

		$query = $this->run_query( $term_id );

		$this->assertNotContains( $post_id, wp_list_pluck( $query->posts, 'ID' ) );
	}

	/**
	 * An integration can reject the query before cached or database results are read.
	 */
	public function test_access_filter_can_return_wp_error() {
		$error = new WP_Error( 'query_blocked_by_policy', 'Blocked by an access policy.' );
		add_filter(
			'multisite_wp_query_access',
			static function () use ( $error ) {
				return $error;
			}
		);

		$query  = new Multisite_WP_Query();
		$result = $query->query( array( 'multisite_term_ids' => array( 1 ) ) );

		$this->assertSame( $error, $result );
		$this->assertSame( array(), $query->posts );
		$this->assertSame( array(), $query->blogs_data );
	}

	/**
	 * Boolean denials receive the query's generic policy error.
	 */
	public function test_access_filter_can_return_false() {
		add_filter( 'multisite_wp_query_access', '__return_false' );

		$query  = new Multisite_WP_Query();
		$result = $query->query( array( 'multisite_term_ids' => array( 1 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'multisite_wp_query_forbidden', $result->get_error_code() );
	}
}
