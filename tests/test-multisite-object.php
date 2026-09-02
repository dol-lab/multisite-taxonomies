<?php
/**
 * Tests for Multisite_Object, the identity of a taggable object.
 *
 * Covers what the named constructors guarantee (a namespace is always pinned, user/blog rows are
 * network-global), that the term methods round-trip through the same scope they were built with,
 * and that two namespaces sharing an ID never see each other's terms.
 *
 * @package multitaxo
 */

/**
 * Object identity and its term methods.
 */
class Test_Multisite_Object extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning posts and users, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'mo_mixed_tax';

	/**
	 * Register a mixed taxonomy before each test.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
	}

	/**
	 * Create a term and return its term ID.
	 *
	 * @param string $name Term name.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name ): int {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * The named constructors pin the namespace they are named after.
	 */
	public function test_constructors_pin_their_namespace() {
		$this->assertSame( '', Multisite_Object::post( 7 )->object_type() );
		$this->assertSame( 'user', Multisite_Object::user( 7 )->object_type() );
		$this->assertSame( 'blog', Multisite_Object::blog( 7 )->object_type() );
	}

	/**
	 * A post defaults to the current blog; user and blog objects are network-global.
	 */
	public function test_blog_is_pinned_per_namespace() {
		$this->assertSame( get_current_blog_id(), Multisite_Object::post( 7 )->blog_id() );
		$this->assertSame( 42, Multisite_Object::post( 7, 42 )->blog_id() );
		$this->assertSame( 0, Multisite_Object::user( 7 )->blog_id() );
		$this->assertSame( 0, Multisite_Object::blog( 7 )->blog_id() );
	}

	/**
	 * The same ID in three namespaces yields three distinct identities.
	 */
	public function test_key_separates_namespaces_and_blogs() {
		$keys = array(
			Multisite_Object::post( 7, 1 )->key(),
			Multisite_Object::post( 7, 2 )->key(),
			Multisite_Object::user( 7 )->key(),
			Multisite_Object::blog( 7 )->key(),
		);

		$this->assertSame( $keys, array_unique( $keys ), 'each identity should be distinct' );

		// Private properties would otherwise encode to `{}`, collapsing all four into one.
		$encoded = array_map(
			'wp_json_encode',
			array(
				Multisite_Object::post( 7, 1 ),
				Multisite_Object::post( 7, 2 ),
				Multisite_Object::user( 7 ),
				Multisite_Object::blog( 7 ),
			)
		);
		$this->assertSame( $keys, array_map( 'json_decode', $encoded ), 'encoding must not flatten identities' );
		$this->assertTrue( Multisite_Object::user( 7 )->equals( Multisite_Object::user( 7 ) ) );
		$this->assertFalse( Multisite_Object::user( 7 )->equals( Multisite_Object::post( 7 ) ) );
	}

	/**
	 * Terms written through an object come back through the same object, and nowhere else.
	 */
	public function test_terms_round_trip_within_their_namespace() {
		$term      = $this->make_term( 'Round Trip' );
		$shared_id = 4242;

		$user = Multisite_Object::user( $shared_id );
		$post = Multisite_Object::post( $shared_id );

		$user->set_terms( $this->tax, array( $term ) );

		$this->assertSame( array( $term ), $user->term_ids( $this->tax ), 'the user carries the term' );
		$this->assertSame( array(), $post->term_ids( $this->tax ), 'the same ID as a post carries nothing' );
		$this->assertTrue( $user->has_term( $this->tax, $term ) );
		$this->assertFalse( $post->has_term( $this->tax, $term ) );
	}

	/**
	 * A post ID is scoped to its blog, so the same ID on another blog is a different object.
	 */
	public function test_post_terms_do_not_leak_across_blogs() {
		$term    = $this->make_term( 'Blog Scoped' );
		$post_id = 515;
		$here    = get_current_blog_id();
		$there   = $here + 100;

		Multisite_Object::post( $post_id, $here )->set_terms( $this->tax, array( $term ) );

		$this->assertSame( array( $term ), Multisite_Object::post( $post_id, $here )->term_ids( $this->tax ) );
		$this->assertSame( array(), Multisite_Object::post( $post_id, $there )->term_ids( $this->tax ) );
	}

	/**
	 * add_terms keeps what is there, remove_terms takes one away.
	 */
	public function test_add_and_remove_terms() {
		$first  = $this->make_term( 'First' );
		$second = $this->make_term( 'Second' );
		$user   = Multisite_Object::user( 606 );

		$user->set_terms( $this->tax, array( $first ) );
		$user->add_terms( $this->tax, array( $second ) );

		$after_add = $user->term_ids( $this->tax );
		sort( $after_add );
		$expected = array( $first, $second );
		sort( $expected );
		$this->assertSame( $expected, $after_add );

		$user->remove_terms( $this->tax, array( $first ) );
		$this->assertSame( array( $second ), $user->term_ids( $this->tax ) );
	}

	/**
	 * An unknown taxonomy is an error, not a silent empty result.
	 */
	public function test_unknown_taxonomy_is_an_error() {
		$this->assertWPError( Multisite_Object::user( 1 )->terms( 'no_such_tax' ) );
	}

	/**
	 * resolve() returns the real WordPress object, and null once it is gone.
	 */
	public function test_resolve_returns_the_wordpress_object() {
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create();

		$user = Multisite_Object::user( $user_id )->resolve();
		$this->assertInstanceOf( 'WP_User', $user );
		$this->assertSame( $user_id, (int) $user->ID );

		$post = Multisite_Object::post( $post_id )->resolve();
		$this->assertInstanceOf( 'WP_Post', $post );
		$this->assertSame( $post_id, (int) $post->ID );

		$this->assertNull( Multisite_Object::user( 99999 )->resolve() );
		$this->assertFalse( Multisite_Object::post( 99999 )->exists() );
	}

	/**
	 * A row read back from the database rebuilds the identity it was written with.
	 */
	public function test_from_row_rebuilds_identity() {
		$object = Multisite_Object::from_row(
			array(
				'object_id'   => '9',
				'blog_id'     => '0',
				'object_type' => 'user',
			)
		);

		$this->assertSame( 9, $object->id() );
		$this->assertSame( 'user', $object->object_type() );
		$this->assertSame( 0, $object->blog_id() );
	}
}
