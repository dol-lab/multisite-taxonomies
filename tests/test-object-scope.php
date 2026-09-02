<?php
/**
 * The relationship scope value object.
 *
 * A relationship row is keyed by namespace and blog together. These tests cover
 * Multisite_Object_Scope itself and the way Multisite_Term_Query resolves one, in particular
 * that a relationship read which names no scope is pinned to the current blog rather than
 * spanning the network.
 *
 * @package multitaxo
 */

/**
 * Multisite_Object_Scope tests.
 */
class Test_Object_Scope extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces.
	 *
	 * @var string
	 */
	private $tax = 'object_scope_tax';

	/**
	 * Taxonomy registered for users only, so the namespace can be inferred.
	 *
	 * @var string
	 */
	private $user_tax = 'object_scope_user_tax';

	/**
	 * Register fresh taxonomies before each test.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
		register_multisite_taxonomy( $this->user_tax, array( 'user' ), array() );
	}

	/**
	 * Create a term and return its multisite_term_multisite_taxonomy_id.
	 *
	 * @param string $name Term name.
	 * @param string $taxonomy Taxonomy to create it in.
	 * @return int The multisite_term_multisite_taxonomy_id of the created term.
	 */
	private function make_term( string $name, string $taxonomy = '' ): int {
		$res = insert_multisite_term( $name, $taxonomy ? $taxonomy : $this->tax, array(), false );
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
	 * A post scope keeps the blog it was given; 0 means the current one.
	 */
	public function test_create_pins_the_post_namespace_to_a_blog() {
		$explicit = Multisite_Object_Scope::create( '', 7 );
		$this->assertSame( '', $explicit->object_type() );
		$this->assertSame( 7, $explicit->blog_id() );

		$implicit = Multisite_Object_Scope::create( '', 0 );
		$this->assertSame( get_current_blog_id(), $implicit->blog_id() );
	}

	/**
	 * User and blog relationships are network-global, so their blog is always 0.
	 */
	public function test_create_forces_blog_zero_for_network_global_namespaces() {
		$this->assertSame( 0, Multisite_Object_Scope::create( 'user', 7 )->blog_id() );
		$this->assertSame( 0, Multisite_Object_Scope::create( 'blog', 7 )->blog_id() );
	}

	/**
	 * An unknown object type is the post namespace: post types map to it.
	 */
	public function test_create_normalizes_unknown_object_types_to_the_post_namespace() {
		$this->assertSame( '', Multisite_Object_Scope::create( 'page', 3 )->object_type() );
	}

	/**
	 * A single-namespace taxonomy names the namespace when the caller does not.
	 */
	public function test_for_taxonomy_infers_a_single_namespace() {
		$inferred = Multisite_Object_Scope::for_taxonomy( '', $this->user_tax, 7 );
		$this->assertSame( 'user', $inferred->object_type() );
		$this->assertSame( 0, $inferred->blog_id() );

		// The multi-namespace taxonomy cannot be inferred, so it stays in the post namespace.
		$this->assertSame( '', Multisite_Object_Scope::for_taxonomy( '', $this->tax, 7 )->object_type() );
	}

	/**
	 * An open scope constrains nothing and says so.
	 */
	public function test_open_scopes_report_that_they_do_not_narrow() {
		$this->assertFalse( Multisite_Object_Scope::any()->is_narrowing() );
		$this->assertSame( '', Multisite_Object_Scope::any()->where( 'tr' ) );

		$namespace_only = Multisite_Object_Scope::across_blogs( 'user' );
		$this->assertTrue( $namespace_only->is_narrowing() );
		$this->assertNull( $namespace_only->blog_id() );
		$this->assertSame( "tr.object_type = 'user'", $namespace_only->where( 'tr' ) );
	}

	/**
	 * A pinned scope writes both predicates, never one.
	 */
	public function test_where_writes_both_halves_of_the_key() {
		$scope = Multisite_Object_Scope::create( '', 4 );
		$this->assertSame( "tr.object_type = '' AND tr.blog_id = 4", $scope->where( 'tr' ) );
		$this->assertSame( "rel.object_type = '' AND rel.blog_id = 4", $scope->where( 'rel' ) );
	}

	/**
	 * Cache groups differ per blog and per namespace, so entries cannot collide.
	 */
	public function test_cache_group_carries_the_scope() {
		$here      = Multisite_Object_Scope::create( '', 4 )->cache_group( $this->tax );
		$elsewhere = Multisite_Object_Scope::create( '', 5 )->cache_group( $this->tax );
		$users     = Multisite_Object_Scope::create( 'user', 4 )->cache_group( $this->tax );

		$this->assertNotSame( $here, $elsewhere );
		$this->assertNotSame( $here, $users );
		$this->assertStringContainsString( $this->tax, $here );
	}

	/**
	 * A relationship read that names no scope stays on the current blog.
	 */
	public function test_query_without_a_scope_fails_closed() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 811;

		set_object_multisite_terms( $object_id, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( $object_id, array( $elsewhere ), $this->tax, get_current_blog_id() + 100, false, '' );

		$this->assertSame( array( $here ), $this->query_mtmt_ids( array( 'object_ids' => $object_id ) ) );
	}

	/**
	 * `blog_id => null` is the opt-out: an explicit read across every blog.
	 */
	public function test_null_blog_id_opts_out_of_the_pin() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 812;

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
	 * Encoded args keep scopes apart, so a caller memoizing on a hash of its args stays correct.
	 */
	public function test_scopes_survive_json_encoding_distinctly() {
		$this->assertSame( '"0:user"', wp_json_encode( Multisite_Object_Scope::users() ) );

		$encoded = array_map(
			'wp_json_encode',
			array(
				Multisite_Object_Scope::users(),
				Multisite_Object_Scope::blogs(),
				Multisite_Object_Scope::posts_on( 1 ),
				Multisite_Object_Scope::posts_on( 2 ),
				Multisite_Object_Scope::posts(),
				Multisite_Object_Scope::any(),
			)
		);
		$this->assertSame( $encoded, array_unique( $encoded ), 'distinct scopes must not encode alike' );
	}

	/**
	 * An explicit scope overrides the object_type and blog_id vars.
	 */
	public function test_object_scope_var_wins_over_the_loose_vars() {
		$here      = $this->make_term( 'Here' );
		$elsewhere = $this->make_term( 'Elsewhere' );
		$object_id = 813;
		$other     = get_current_blog_id() + 100;

		set_object_multisite_terms( $object_id, array( $here ), $this->tax, get_current_blog_id(), false, '' );
		set_object_multisite_terms( $object_id, array( $elsewhere ), $this->tax, $other, false, '' );

		$read = $this->query_mtmt_ids(
			array(
				'object_ids'   => $object_id,
				'blog_id'      => get_current_blog_id(),
				'object_scope' => Multisite_Object_Scope::create( '', $other ),
			)
		);
		$this->assertSame( array( $elsewhere ), $read );
	}

	/**
	 * A term listing that names no relationship at all is not scoped into one.
	 */
	public function test_plain_term_listing_is_not_turned_into_a_relationship_read() {
		$unused = $this->make_term( 'Unused' );

		$read = $this->query_mtmt_ids( array( 'hide_empty' => false ) );
		$this->assertContains( $unused, $read, 'a term with no relationships should still be listed' );
	}
}
