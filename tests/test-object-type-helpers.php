<?php
/**
 * Unit tests for the object_type namespace helpers (the fork's core contract).
 *
 * These are pure functions — no DB rows are touched. They encode the rule that a relationship's
 * ID namespace is '' (post, default/legacy), 'user', or 'blog', and that user/blog rows are
 * network-global (blog_id 0). See plan.md / todo.md.
 *
 * @package multitaxo
 */

/**
 * Unit tests for the object_type namespace helper functions.
 */
class Test_Object_Type_Helpers extends WP_UnitTestCase {

	/**
	 * Anything that is not exactly 'user' or 'blog' collapses to '' (the post namespace).
	 */
	public function test_normalize_collapses_post_like_types_to_empty() {
		$this->assertSame( 'user', normalize_multisite_object_type( 'user' ) );
		$this->assertSame( 'blog', normalize_multisite_object_type( 'blog' ) );
		$this->assertSame( '', normalize_multisite_object_type( '' ) );
		$this->assertSame( '', normalize_multisite_object_type( 'post' ), "literal 'post' is never stored" );
		$this->assertSame( '', normalize_multisite_object_type( 'page' ) );
		$this->assertSame( '', normalize_multisite_object_type( 'some_cpt' ) );
	}

	/**
	 * User and blog relationships are network-global, so they always store blog_id 0,
	 * regardless of the blog_id the caller passed.
	 */
	public function test_relationship_blog_id_forces_zero_for_user_and_blog() {
		$this->assertSame( 0, multisite_relationship_blog_id( 'user', 5 ) );
		$this->assertSame( 0, multisite_relationship_blog_id( 'blog', 99 ) );
		$this->assertSame( 0, multisite_relationship_blog_id( 'user', 0 ) );
	}

	/**
	 * Post-namespace relationships keep an explicit blog_id and fall back to the current blog
	 * when none (or an invalid one) is supplied — the legacy behavior.
	 */
	public function test_relationship_blog_id_for_posts_uses_explicit_or_current() {
		$this->assertSame( 7, multisite_relationship_blog_id( '', 7 ) );
		$this->assertSame( get_current_blog_id(), multisite_relationship_blog_id( '', 0 ) );
		$this->assertSame( get_current_blog_id(), multisite_relationship_blog_id( '', -3 ) );
	}

	/**
	 * The supports_object_type() helper reflects exactly the namespaces a taxonomy was registered for.
	 */
	public function test_supports_object_type_matches_registration() {
		register_multisite_taxonomy( 'helper_multi_tax', array( 'post', 'user', 'blog' ), array() );

		$this->assertTrue( multisite_taxonomy_supports_object_type( '', 'helper_multi_tax' ) );
		$this->assertTrue( multisite_taxonomy_supports_object_type( 'user', 'helper_multi_tax' ) );
		$this->assertTrue( multisite_taxonomy_supports_object_type( 'blog', 'helper_multi_tax' ) );

		register_multisite_taxonomy( 'helper_user_tax', array( 'user' ), array() );
		$this->assertTrue( multisite_taxonomy_supports_object_type( 'user', 'helper_user_tax' ) );
		$this->assertFalse( multisite_taxonomy_supports_object_type( 'blog', 'helper_user_tax' ) );
		$this->assertFalse( multisite_taxonomy_supports_object_type( '', 'helper_user_tax' ) );
	}

	/**
	 * An unknown taxonomy supports nothing.
	 */
	public function test_supports_object_type_unknown_taxonomy_is_false() {
		$this->assertFalse( multisite_taxonomy_supports_object_type( 'user', 'no_such_tax_xyz' ) );
	}

	/**
	 * A single-namespace user/blog taxonomy infers its namespace when the caller omits it,
	 * so consumers can call the relationship API without repeating $object_type.
	 */
	public function test_resolve_infers_single_namespace() {
		register_multisite_taxonomy( 'resolve_user_tax', array( 'user' ), array() );
		register_multisite_taxonomy( 'resolve_blog_tax', array( 'blog' ), array() );

		$this->assertSame( 'user', resolve_multisite_object_type( '', 'resolve_user_tax' ) );
		$this->assertSame( 'blog', resolve_multisite_object_type( '', 'resolve_blog_tax' ) );
	}

	/**
	 * A multi-namespace taxonomy cannot be inferred: an empty object_type stays '' (post),
	 * while an explicit user/blog is honored.
	 */
	public function test_resolve_does_not_infer_for_multi_namespace() {
		register_multisite_taxonomy( 'resolve_multi_tax', array( 'post', 'user', 'blog' ), array() );

		$this->assertSame( '', resolve_multisite_object_type( '', 'resolve_multi_tax' ) );
		$this->assertSame( 'user', resolve_multisite_object_type( 'user', 'resolve_multi_tax' ) );
		$this->assertSame( 'blog', resolve_multisite_object_type( 'blog', 'resolve_multi_tax' ) );
	}
}
