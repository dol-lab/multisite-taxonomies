<?php
/**
 * Tests for the taxonomy-level API: object types, term field readers and links.
 *
 * These are the functions an application calls around a registration rather than around a term.
 * The link builders are the fiddly ones: with no permastruct they fall back to a query string,
 * and which query string depends on whether the taxonomy got a query var at registration.
 *
 * @package multitaxo
 */

/**
 * Object type (de)registration, field readers, counts and links.
 */
class Test_Taxonomy_Api extends WP_UnitTestCase {

	/**
	 * Queryable taxonomy, registered for posts.
	 *
	 * @var string
	 */
	private $tax = 'api_tax';

	/**
	 * Taxonomy without a query var.
	 *
	 * @var string
	 */
	private $private_tax = 'api_private_tax';

	/**
	 * Register the taxonomies and act as someone allowed to manage terms.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy(
			$this->tax,
			array( 'post' ),
			array(
				'hierarchical'       => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
			)
		);
		register_multisite_taxonomy( $this->private_tax, array( 'post' ), array( 'publicly_queryable' => false ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );
	}

	/**
	 * Create a term and return its ID.
	 *
	 * @param string $name Term name.
	 * @param array  $args Optional insert args.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name, array $args = array() ): int {
		$res = insert_multisite_term( $name, $this->tax, $args, false );
		$this->assertNotWPError( $res );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * Object types can be added to and removed from a live registration.
	 */
	public function test_object_type_registration_round_trip() {
		$this->assertFalse( is_object_in_multisite_taxonomy( 'user', $this->tax ) );

		$this->assertTrue( register_multisite_taxonomy_for_object_type( $this->tax, 'user' ) );
		$this->assertTrue( is_object_in_multisite_taxonomy( 'user', $this->tax ) );
		$this->assertTrue( register_multisite_taxonomy_for_object_type( $this->tax, 'user' ), 'adding twice is harmless' );

		$this->assertTrue( unregister_multisite_taxonomy_for_object_type( $this->tax, 'user' ) );
		$this->assertFalse( is_object_in_multisite_taxonomy( 'user', $this->tax ) );
		$this->assertFalse( unregister_multisite_taxonomy_for_object_type( $this->tax, 'user' ), 'removing what is not there fails' );
	}

	/**
	 * Only real post types and the two network namespaces are accepted, on a known taxonomy.
	 */
	public function test_object_type_registration_refuses_nonsense() {
		$this->assertFalse( register_multisite_taxonomy_for_object_type( 'no_such_tax', 'post' ) );
		$this->assertFalse( register_multisite_taxonomy_for_object_type( $this->tax, 'no_such_post_type' ) );
		$this->assertFalse( unregister_multisite_taxonomy_for_object_type( 'no_such_tax', 'post' ) );
		$this->assertFalse( unregister_multisite_taxonomy_for_object_type( $this->tax, 'no_such_post_type' ) );
	}

	/**
	 * A single field can be read straight off a term, sanitized for its context.
	 */
	public function test_term_field_reader() {
		$term_id = $this->make_term( 'Fields', array( 'description' => 'Plain <em>description</em>.' ) );

		$this->assertSame( 'Fields', get_multisite_term_field( 'name', $term_id, $this->tax ) );
		$this->assertSame( $term_id, get_multisite_term_field( 'multisite_term_id', $term_id, $this->tax ) );
		$this->assertSame( '', get_multisite_term_field( 'no_such_field', $term_id, $this->tax ) );
		$this->assertSame( '', get_multisite_term_field( 'name', 999999, $this->tax ), 'an unknown term has no fields' );

		$edit = get_multisite_term_to_edit( $term_id, $this->tax );
		$this->assertSame( 'Fields', $edit->name );
		$this->assertStringContainsString( '&lt;em&gt;', $edit->description, 'the edit context escapes markup' );
		$this->assertSame( '', get_multisite_term_to_edit( 999999, $this->tax ) );
	}

	/**
	 * Counting is a term query that returns a number.
	 */
	public function test_count_terms() {
		$this->assertSame( 0, (int) count_multisite_terms( $this->tax ) );

		$this->make_term( 'One' );
		$this->make_term( 'Two' );

		$this->assertSame( 2, (int) count_multisite_terms( $this->tax ) );
		$this->assertSame( 0, (int) count_multisite_terms( $this->tax, array( 'hide_empty' => true ) ), 'no term has objects yet' );
	}

	/**
	 * Without a permastruct a term link is a query string, and the query var decides its shape.
	 */
	public function test_term_link_falls_back_to_a_query_string() {
		$term_id = $this->make_term( 'Linked', array( 'slug' => 'linked' ) );

		$expected = home_url( '?' . $this->tax . '=linked' );
		$this->assertSame( $expected, get_multisite_term_link( $term_id, $this->tax ) );
		$this->assertSame( $expected, get_multisite_term_link( 'linked', $this->tax ), 'a slug resolves to the same link' );
		$this->assertSame( $expected, get_multisite_term_link( get_multisite_term( $term_id, $this->tax ) ), 'so does a term object' );

		$private = insert_multisite_term( 'Private', $this->private_tax, array( 'slug' => 'private' ), false );
		$this->assertSame(
			home_url( '?multisite_taxonomy=' . $this->private_tax . '&multisite_term=private' ),
			get_multisite_term_link( (int) $private['multisite_term_id'], $this->private_tax ),
			'a taxonomy without a query var names itself in the query string'
		);
	}

	/**
	 * An unknown term has no link.
	 */
	public function test_term_link_errors() {
		$this->assertSame( 'invalid_multisite_term', get_multisite_term_link( 'no-such-slug', $this->tax )->get_error_code() );
		$this->assertSame( 'invalid_multisite_term', get_multisite_term_link( 999999, $this->tax )->get_error_code() );
	}

	/**
	 * The edit link points at the network term screen, and is withheld where there is no UI.
	 */
	public function test_edit_term_link() {
		$term_id = $this->make_term( 'Editable' );

		$link = get_edit_multisite_term_link( $term_id, $this->tax );
		$this->assertStringContainsString( 'network/admin.php', $link );
		$this->assertStringContainsString( 'page=multisite_term_edit', $link );
		$this->assertStringContainsString( 'multisite_term_id=' . $term_id, $link );

		$this->assertNull( get_edit_multisite_term_link( $term_id, 'no_such_tax' ) );
		$this->assertNull( get_edit_multisite_term_link( 999999, $this->tax ) );

		wp_set_current_user( 0 );
		$this->assertNull( get_edit_multisite_term_link( $term_id, $this->tax ), 'a link nobody may follow is not offered' );
	}

	/**
	 * A post reports the taxonomies its post type carries, and its terms render as links.
	 */
	public function test_post_taxonomies_and_rendered_list() {
		$post_id = self::factory()->post->create();
		$term_id = $this->make_term( 'Rendered', array( 'slug' => 'rendered' ) );
		set_object_multisite_terms( $post_id, array( $term_id ), $this->tax );

		$this->assertContains( $this->tax, get_post_multisite_taxonomies( $post_id ) );

		$rendered = get_the_multisite_taxonomies( $post_id );
		$this->assertArrayHasKey( $this->tax, $rendered );
		$this->assertStringContainsString( 'Rendered</a>', $rendered[ $this->tax ] );
		$this->assertStringContainsString( home_url( '?' . $this->tax . '=rendered' ), html_entity_decode( $rendered[ $this->tax ] ) );

		$this->assertSame( array(), get_the_multisite_taxonomies( 999999 ), 'no post, no taxonomies' );
		$this->assertSame(
			array(),
			get_the_multisite_taxonomies( self::factory()->post->create() ),
			'a post without terms renders nothing'
		);
	}
}
