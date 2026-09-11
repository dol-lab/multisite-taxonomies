<?php
/**
 * Tests for the object-to-terms cache and the relationship readers built on it.
 *
 * The cache group is derived from the scope (`<blog>_<namespace>_<taxonomy>_multisite_relationships`),
 * so the same numeric ID means a different object in each namespace. Everything here uses one ID in
 * two namespaces at once: a cache or a purge that ignores the namespace answers for the wrong object.
 *
 * @package multitaxo
 */

/**
 * Namespace isolation of the relationship cache, its purges and its readers.
 */
class Test_Relationship_Cache extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces.
	 *
	 * @var string
	 */
	private $tax = 'relcache_tax';

	/**
	 * One ID, deliberately used as a post ID and as a user ID.
	 *
	 * @var int
	 */
	private $shared_id = 4242;

	/**
	 * Register the taxonomy before each test.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
	}

	/**
	 * Create a term and return its ID.
	 *
	 * @param string $name Term name.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name ): int {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * The term IDs a cache read reports for an object.
	 *
	 * @param int    $object_id   Object ID.
	 * @param string $object_type Namespace ('', 'user' or 'blog').
	 * @return array|false Term IDs, or false when nothing is cached.
	 */
	private function cached_term_ids( int $object_id, string $object_type = '' ) {
		$cached = get_object_multisite_term_cache( $object_id, $this->tax, 0, $object_type );
		if ( false === $cached ) {
			return false;
		}
		return array_map( 'intval', wp_list_pluck( $cached, 'multisite_term_id' ) );
	}

	/**
	 * Nothing is cached until something primes it, and priming returns whole term objects.
	 */
	public function test_cache_round_trip() {
		$post_id = self::factory()->post->create();
		$term_id = $this->make_term( 'Cached' );
		set_object_multisite_terms( $post_id, array( $term_id ), $this->tax );

		clean_object_multisite_term_cache( $post_id, 'post' );
		$this->assertFalse( get_object_multisite_term_cache( $post_id, $this->tax ), 'a cold read reports a miss rather than priming itself' );

		update_object_multisite_term_cache( $post_id, 'post' );

		$cached = get_object_multisite_term_cache( $post_id, $this->tax );
		$this->assertCount( 1, $cached );
		$this->assertInstanceOf( 'Multisite_Term', $cached[0] );
		$this->assertSame( 'Cached', $cached[0]->name );
	}

	/**
	 * An object with no terms caches an empty list, which is a hit, not a miss.
	 */
	public function test_cache_records_the_absence_of_terms() {
		$post_id = self::factory()->post->create();

		update_object_multisite_term_cache( $post_id, 'post' );

		$this->assertSame( array(), get_object_multisite_term_cache( $post_id, $this->tax ) );
	}

	/**
	 * One ID in two namespaces caches two different answers.
	 */
	public function test_cache_is_keyed_by_namespace() {
		$post_term = $this->make_term( 'Post side' );
		$user_term = $this->make_term( 'User side' );

		set_object_multisite_terms( $this->shared_id, array( $post_term ), $this->tax, 0, false, '' );
		set_object_multisite_terms( $this->shared_id, array( $user_term ), $this->tax, 0, false, 'user' );

		update_object_multisite_term_cache( $this->shared_id, 'post' );
		update_object_multisite_term_cache( $this->shared_id, 'user' );

		$this->assertSame( array( $post_term ), $this->cached_term_ids( $this->shared_id, '' ) );
		$this->assertSame( array( $user_term ), $this->cached_term_ids( $this->shared_id, 'user' ) );
	}

	/**
	 * A purge empties one namespace and leaves the other primed.
	 */
	public function test_clean_object_cache_is_scoped_to_one_namespace() {
		$post_term = $this->make_term( 'Post side' );
		$user_term = $this->make_term( 'User side' );
		set_object_multisite_terms( $this->shared_id, array( $post_term ), $this->tax, 0, false, '' );
		set_object_multisite_terms( $this->shared_id, array( $user_term ), $this->tax, 0, false, 'user' );
		update_object_multisite_term_cache( $this->shared_id, 'post' );
		update_object_multisite_term_cache( $this->shared_id, 'user' );

		clean_object_multisite_term_cache( $this->shared_id, 'post' );

		$this->assertFalse( $this->cached_term_ids( $this->shared_id, '' ) );
		$this->assertSame( array( $user_term ), $this->cached_term_ids( $this->shared_id, 'user' ) );
	}

	/**
	 * Deleting an object's relationships deletes them in the named namespace only.
	 */
	public function test_delete_relationships_is_scoped_to_one_namespace() {
		$post_term = $this->make_term( 'Post side' );
		$user_term = $this->make_term( 'User side' );
		set_object_multisite_terms( $this->shared_id, array( $post_term ), $this->tax, 0, false, '' );
		set_object_multisite_terms( $this->shared_id, array( $user_term ), $this->tax, 0, false, 'user' );

		delete_object_multisite_term_relationships( $this->shared_id, $this->tax, 0, 'user' );

		$this->assertSame(
			array(),
			get_object_multisite_terms( $this->shared_id, $this->tax, 0, array( 'fields' => 'ids' ), 'user' )
		);
		$this->assertSame(
			array( $post_term ),
			array_map( 'intval', get_object_multisite_terms( $this->shared_id, $this->tax, 0, array( 'fields' => 'ids' ), '' ) ),
			'the post-namespace row survives a user-namespace purge'
		);
	}

	/**
	 * The edit-form reader returns a comma-separated name list, and false when there is nothing.
	 */
	public function test_terms_to_edit() {
		$post_id = self::factory()->post->create();
		$first   = $this->make_term( 'Alpha' );
		$second  = $this->make_term( 'Beta' );
		set_object_multisite_terms( $post_id, array( $first, $second ), $this->tax );

		$this->assertSame( 'Alpha,Beta', get_multisite_terms_to_edit( $post_id, $this->tax ) );
		$this->assertSame( 'Alpha,Beta', get_multisite_terms_to_edit( $post_id, $this->tax ), 'the second read comes from the cache it primed' );

		$this->assertFalse( get_multisite_terms_to_edit( self::factory()->post->create(), $this->tax ) );
		$this->assertFalse( get_multisite_terms_to_edit( 0, $this->tax ) );
	}

	/**
	 * The reverse reader groups a term's objects by namespace, with the post namespace
	 * surfacing under the friendlier 'post' key.
	 */
	public function test_term_objects_grouped_by_type() {
		$term_id = $this->make_term( 'Everywhere' );
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create();

		set_object_multisite_terms( $post_id, array( $term_id ), $this->tax, 0, false, '' );
		set_object_multisite_terms( $user_id, array( $term_id ), $this->tax, 0, false, 'user' );
		set_object_multisite_terms( 7, array( $term_id ), $this->tax, 0, false, 'blog' );

		$grouped = get_multisite_term_objects_by_type( $term_id, $this->tax );

		$this->assertSame( array( 'post', 'blog', 'user' ), array_keys( $grouped ), 'grouped in object_type order' );
		$this->assertSame( $post_id, $grouped['post'][0]->object_id );
		$this->assertSame( get_current_blog_id(), $grouped['post'][0]->blog_id );
		$this->assertSame( $user_id, $grouped['user'][0]->object_id );
		$this->assertSame( 0, $grouped['user'][0]->blog_id, 'user rows are network-global' );
		$this->assertSame( 7, $grouped['blog'][0]->object_id );

		$this->assertSame( array(), get_multisite_term_objects_by_type( $term_id, 'no_such_tax' ) );
	}

	/**
	 * Neither blog nor namespace is named by get_objects_in_multisite_term(), so it answers for all
	 * of them at once. Callers that need one object kind want the namespace-aware readers instead.
	 */
	public function test_objects_in_term_spans_every_namespace() {
		$term_id = $this->make_term( 'Mixed' );
		set_object_multisite_terms( $this->shared_id, array( $term_id ), $this->tax, 0, false, '' );
		set_object_multisite_terms( 99, array( $term_id ), $this->tax, 0, false, 'user' );

		$this->assertEqualSets(
			array( $this->shared_id, 99 ),
			array_map( 'intval', get_objects_in_multisite_term( $term_id, $this->tax ) )
		);

		$this->assertSame( array(), get_objects_in_multisite_term( 999999, $this->tax ) );
		$this->assertSame( 'invalid_taxonomy', get_objects_in_multisite_term( $term_id, 'no_such_tax' )->get_error_code() );
	}

	/**
	 * Whether an object type carries a taxonomy at all.
	 */
	public function test_is_object_in_multisite_taxonomy() {
		register_multisite_taxonomy( 'relcache_users_only', array( 'user' ), array() );

		$this->assertTrue( is_object_in_multisite_taxonomy( 'post', $this->tax ) );
		$this->assertTrue( is_object_in_multisite_taxonomy( 'user', 'relcache_users_only' ) );
		$this->assertFalse( is_object_in_multisite_taxonomy( 'post', 'relcache_users_only' ) );
		$this->assertFalse( is_object_in_multisite_taxonomy( 'nothing_registered_here', $this->tax ) );
	}
}
