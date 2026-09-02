<?php
/**
 * Tests for the reverse direction: which objects carry a term.
 *
 * A term shared by posts, users and sites must answer with identities, not integers, and must be
 * filterable and countable per namespace. These tests pin that down, including the case the whole
 * design exists for: the same numeric ID used in two namespaces at once.
 *
 * @package multitaxo
 */

/**
 * multisite_term_objects(), the object set, and the per-namespace counts.
 */
class Test_Term_Objects extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'to_mixed_tax';

	/**
	 * Term ID shared by every namespace in most tests.
	 *
	 * @var int
	 */
	private $term;

	/**
	 * Register the taxonomy and create the shared term.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );

		$res = insert_multisite_term( 'Design Research', $this->tax, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		$this->term = (int) $res['multisite_term_id'];
	}

	/**
	 * Tag one post on this blog, one post on another blog, a user and a site with the shared term.
	 *
	 * @return array The four objects, keyed by a short label.
	 */
	private function tag_one_of_everything(): array {
		$here  = get_current_blog_id();
		$there = $here + 100;

		$objects = array(
			'post_here'  => Multisite_Object::post( 11, $here ),
			'post_there' => Multisite_Object::post( 11, $there ),
			'user'       => Multisite_Object::user( 11 ),
			'site'       => Multisite_Object::blog( 11 ),
		);

		foreach ( $objects as $object ) {
			$object->set_terms( $this->tax, array( $this->term ) );
		}

		return $objects;
	}

	/**
	 * The reverse read returns every namespace, keeping each object distinct.
	 */
	public function test_returns_objects_from_every_namespace() {
		$expected = $this->tag_one_of_everything();

		$set = multisite_term_objects( $this->term, $this->tax );

		$this->assertCount( 4, $set, 'one row per object, though all four share ID 11' );

		$keys = array_keys( $set->keyed() );
		foreach ( $expected as $label => $object ) {
			$this->assertContains( $object->key(), $keys, "the $label object should be in the set" );
		}
	}

	/**
	 * The set filters down to one namespace, one blog, or an explicit scope.
	 */
	public function test_set_filters_by_namespace_blog_and_scope() {
		$this->tag_one_of_everything();
		$here = get_current_blog_id();

		$set = multisite_term_objects( $this->term, $this->tax );

		$this->assertCount( 2, $set->of( '' ), 'two posts, on two blogs' );
		$this->assertCount( 1, $set->of( 'user' ) );
		$this->assertCount( 1, $set->of( 'blog' ) );
		$this->assertCount( 1, $set->on( $here ), 'only the post on this blog' );
		$this->assertCount( 2, $set->on( 0 ), 'the user and the site are network-global' );
		$this->assertCount( 1, $set->in( Multisite_Object_Scope::posts_on( $here ) ) );

		$user = $set->of( 'user' )->first();
		$this->assertSame( 11, $user->id() );
		$this->assertSame( 'user', $user->object_type() );
	}

	/**
	 * grouped() and counts() split the set by namespace.
	 */
	public function test_grouped_and_counts() {
		$this->tag_one_of_everything();

		$set = multisite_term_objects( $this->term, $this->tax );

		$counts = $set->counts();
		ksort( $counts );
		$this->assertSame(
			array(
				''     => 2,
				'blog' => 1,
				'user' => 1,
			),
			$counts
		);

		$grouped = $set->grouped();
		$this->assertInstanceOf( 'Multisite_Object_Set', $grouped['user'] );
		$this->assertCount( 2, $grouped[''] );

		$ids = $set->ids();
		$this->assertSame( array( 11, 11 ), $ids[''], 'IDs alone cannot tell the two posts apart' );

		$this->assertSame( array( 11 ), $set->ids_of( 'user' ), 'one namespace comes back flat' );
		$this->assertSame( array( 11, 11 ), $set->ids_of( 'page' ), 'any post type is the post namespace' );
		$this->assertSame( array(), $set->of( 'user' )->ids_of( 'blog' ), 'a namespace the set does not hold' );
	}

	/**
	 * A scope passed into the query restricts the rows the database returns.
	 */
	public function test_scope_argument_restricts_the_query() {
		$this->tag_one_of_everything();

		$users = multisite_term_objects( $this->term, $this->tax, array( 'scope' => Multisite_Object_Scope::users() ) );
		$this->assertCount( 1, $users );
		$this->assertSame( 'user', $users->first()->object_type() );

		$posts = multisite_term_objects( $this->term, $this->tax, array( 'scope' => Multisite_Object_Scope::posts() ) );
		$this->assertCount( 2, $posts, 'the post namespace on every blog' );

		$here = multisite_term_objects( $this->term, $this->tax, array( 'scope' => Multisite_Object_Scope::posts_on( get_current_blog_id() ) ) );
		$this->assertCount( 1, $here );
	}

	/**
	 * An object tagged under two terms of one union appears once.
	 */
	public function test_union_over_terms_deduplicates_objects() {
		$second = insert_multisite_term( 'Child Term', $this->tax, array(), false );
		$second = (int) $second['multisite_term_id'];

		$user = Multisite_Object::user( 77 );
		$user->set_terms( $this->tax, array( $this->term, $second ) );

		$set = multisite_term_objects( array( $this->term, $second ), $this->tax );

		$this->assertCount( 1, $set );
		$this->assertSame( $user->key(), $set->first()->key() );
	}

	/**
	 * Pagination slices the set without changing what it holds.
	 */
	public function test_number_and_offset() {
		$this->tag_one_of_everything();

		$first_two = multisite_term_objects( $this->term, $this->tax, array( 'number' => 2 ) );
		$next_two  = multisite_term_objects(
			$this->term,
			$this->tax,
			array(
				'number' => 2,
				'offset' => 2,
			)
		);

		$this->assertCount( 2, $first_two );
		$this->assertCount( 2, $next_two );
		$this->assertSame( array(), array_intersect( array_keys( $first_two->keyed() ), array_keys( $next_two->keyed() ) ) );
	}

	/**
	 * Counts come back per term and per namespace, in one query for many terms.
	 */
	public function test_object_counts_per_namespace() {
		$this->tag_one_of_everything();

		$other = insert_multisite_term( 'Other', $this->tax, array(), false );
		$other = (int) $other['multisite_term_id'];
		Multisite_Object::user( 12 )->set_terms( $this->tax, array( $other ) );

		$counts = multisite_term_object_counts( array( $this->term, $other ), $this->tax );

		$this->assertSame( 2, $counts[ $this->term ][''], 'the same post ID on two blogs counts twice' );
		$this->assertSame( 1, $counts[ $this->term ]['user'] );
		$this->assertSame( 1, $counts[ $this->term ]['blog'] );
		$this->assertSame( array( 'user' => 1 ), $counts[ $other ] );

		$this->assertSame( 1, multisite_term_object_count( $this->term, $this->tax, 'user' ) );
		$this->assertSame( 0, multisite_term_object_count( $other, $this->tax, 'blog' ) );
		$this->assertSame(
			1,
			multisite_term_object_count( $this->term, $this->tax, '', Multisite_Object_Scope::posts_on( get_current_blog_id() ) ),
			'a scope narrows the count to one blog'
		);
	}

	/**
	 * Hydration returns real objects, grouped by scope rather than one query per row.
	 */
	public function test_hydrate_returns_wordpress_objects() {
		$user_id = self::factory()->user->create();
		$post_id = self::factory()->post->create();

		Multisite_Object::user( $user_id )->set_terms( $this->tax, array( $this->term ) );
		Multisite_Object::post( $post_id )->set_terms( $this->tax, array( $this->term ) );

		$resolved = multisite_term_objects( $this->term, $this->tax )->hydrate();

		$this->assertCount( 2, $resolved );
		$this->assertInstanceOf( 'WP_User', $resolved[ Multisite_Object::user( $user_id )->key() ] );
		$this->assertInstanceOf( 'WP_Post', $resolved[ Multisite_Object::post( $post_id )->key() ] );
	}

	/**
	 * Objects that no longer exist drop out of the hydrated result.
	 */
	public function test_hydrate_skips_missing_objects() {
		Multisite_Object::user( 987654 )->set_terms( $this->tax, array( $this->term ) );

		$set = multisite_term_objects( $this->term, $this->tax );

		$this->assertCount( 1, $set, 'the relationship row is there' );
		$this->assertSame( array(), $set->hydrate(), 'but the user is not' );
	}

	/**
	 * An unknown taxonomy or an empty term list yields an empty set, not an error.
	 */
	public function test_empty_inputs() {
		$this->assertTrue( multisite_term_objects( $this->term, 'no_such_tax' )->is_empty() );
		$this->assertTrue( multisite_term_objects( array(), $this->tax )->is_empty() );
		$this->assertSame( array(), multisite_term_object_counts( array(), $this->tax ) );
	}
}
