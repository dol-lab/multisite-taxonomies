<?php
/**
 * Tests for update_multisite_term() and the slug uniqueness it leans on.
 *
 * The write path a term edit takes: merge the submitted args over the stored row, decide what the
 * slug must become, then update two tables (the term and its taxonomy row). The slug decision is
 * the subtle part -- an explicit duplicate is an error, while a slug the caller left to us is made
 * unique instead.
 *
 * @package multitaxo
 */

/**
 * Term update, slug collisions and the errors update_multisite_term() returns.
 */
class Test_Term_Update extends WP_UnitTestCase {

	/**
	 * Hierarchical taxonomy under test.
	 *
	 * @var string
	 */
	private $tax = 'update_tax';

	/**
	 * Flat taxonomy, for the numbered-suffix path.
	 *
	 * @var string
	 */
	private $flat_tax = 'update_flat_tax';

	/**
	 * Register the taxonomies and act as someone allowed to manage terms.
	 */
	public function set_up() {
		parent::set_up();
		register_multisite_taxonomy( $this->tax, array( 'post' ), array( 'hierarchical' => true ) );
		register_multisite_taxonomy( $this->flat_tax, array( 'post' ), array( 'hierarchical' => false ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );
	}

	/**
	 * Create a term and return its ID.
	 *
	 * @param string $name Term name.
	 * @param array  $args Optional insert args.
	 * @param string $tax  Optional taxonomy, defaults to the hierarchical one.
	 * @return int Multisite term ID.
	 */
	private function make_term( string $name, array $args = array(), string $tax = '' ): int {
		$res = insert_multisite_term( $name, $tax ? $tax : $this->tax, $args, false );
		$this->assertNotWPError( $res );
		return (int) $res['multisite_term_id'];
	}

	/**
	 * Updating only the name keeps the slug the term already had: the stored row is merged
	 * under the submitted args, so an omitted slug means "unchanged", not "regenerate".
	 */
	public function test_rename_keeps_the_existing_slug() {
		$term_id = $this->make_term( 'Alpha' );

		$res = update_multisite_term( $term_id, $this->tax, array( 'name' => 'Alpha Renamed' ) );
		$this->assertNotWPError( $res );
		$this->assertSame( $term_id, (int) $res['multisite_term_id'] );

		$term = get_multisite_term( $term_id, $this->tax );
		$this->assertSame( 'Alpha Renamed', $term->name );
		$this->assertSame( 'alpha', $term->slug );
	}

	/**
	 * An explicitly emptied slug is regenerated from the new name.
	 */
	public function test_empty_slug_is_regenerated_from_the_name() {
		$term_id = $this->make_term( 'Beta' );

		update_multisite_term(
			$term_id,
			$this->tax,
			array(
				'name' => 'Beta Two',
				'slug' => '',
			)
		);

		$this->assertSame( 'beta-two', get_multisite_term( $term_id, $this->tax )->slug );
	}

	/**
	 * Description and parent live on the taxonomy row, not the term row; both persist.
	 */
	public function test_description_and_parent_are_updated() {
		$parent_id = $this->make_term( 'Parent' );
		$child_id  = $this->make_term( 'Child' );

		update_multisite_term(
			$child_id,
			$this->tax,
			array(
				'parent'      => $parent_id,
				'description' => 'Now a child.',
			)
		);

		$child = get_multisite_term( $child_id, $this->tax );
		$this->assertSame( $parent_id, (int) $child->parent );
		$this->assertSame( 'Now a child.', $child->description );

		$this->assertSame(
			array( $child_id ),
			array_map( 'intval', get_multisite_term_children( $parent_id, $this->tax ) ),
			'the cached children map is rebuilt when a parent changes'
		);
	}

	/**
	 * Claiming another term's slug outright is an error, not a silent rename.
	 */
	public function test_explicit_duplicate_slug_is_rejected() {
		$this->make_term( 'Taken', array( 'slug' => 'taken' ) );
		$other_id = $this->make_term( 'Other', array( 'slug' => 'other' ) );

		$res = update_multisite_term( $other_id, $this->tax, array( 'slug' => 'taken' ) );

		$this->assertWPError( $res );
		$this->assertSame( 'duplicate_multisite_term_slug', $res->get_error_code() );
		$this->assertSame( 'other', get_multisite_term( $other_id, $this->tax )->slug, 'the term is left untouched' );
	}

	/**
	 * A slug we generated ourselves may collide, and then it gets a numbered suffix instead.
	 */
	public function test_generated_slug_collision_gets_a_suffix() {
		$this->make_term( 'Shared', array( 'slug' => 'shared' ), $this->flat_tax );
		$second_id = $this->make_term( 'Second', array( 'slug' => 'second' ), $this->flat_tax );

		update_multisite_term(
			$second_id,
			$this->flat_tax,
			array(
				'name' => 'Shared',
				'slug' => '',
			)
		);

		$this->assertSame( 'shared-2', get_multisite_term( $second_id, $this->flat_tax )->slug );
	}

	/**
	 * Under a hierarchical taxonomy the collision is resolved with the parent's slug first.
	 */
	public function test_generated_slug_collision_under_a_parent_uses_the_parent_slug() {
		$this->make_term( 'Duplicate', array( 'slug' => 'duplicate' ) );
		$parent_id = $this->make_term( 'Branch', array( 'slug' => 'branch' ) );
		$child_id  = $this->make_term( 'Leaf', array( 'slug' => 'leaf' ) );

		update_multisite_term(
			$child_id,
			$this->tax,
			array(
				'name'   => 'Duplicate',
				'slug'   => '',
				'parent' => $parent_id,
			)
		);

		$this->assertSame( 'duplicate-branch', get_multisite_term( $child_id, $this->tax )->slug );
	}

	/**
	 * Keeping one's own slug is not a collision.
	 */
	public function test_term_may_keep_its_own_slug() {
		$term_id = $this->make_term( 'Stable', array( 'slug' => 'stable' ) );

		$res = update_multisite_term(
			$term_id,
			$this->tax,
			array(
				'name' => 'Stable Renamed',
				'slug' => 'stable',
			)
		);

		$this->assertNotWPError( $res );
		$this->assertSame( 'stable', get_multisite_term( $term_id, $this->tax )->slug );
	}

	/**
	 * The refusals: unknown taxonomy, unknown term, empty name, absent parent.
	 */
	public function test_error_cases() {
		$term_id = $this->make_term( 'Errors' );

		$this->assertSame( 'invalid_multisite_taxonomy', update_multisite_term( $term_id, 'no_such_tax', array() )->get_error_code() );
		$this->assertSame( 'invalid_multisite_term', update_multisite_term( 999999, $this->tax, array() )->get_error_code() );
		$this->assertSame( 'empty_multisite_term_name', update_multisite_term( $term_id, $this->tax, array( 'name' => '   ' ) )->get_error_code() );
		$this->assertSame( 'missing_multisite_parent', update_multisite_term( $term_id, $this->tax, array( 'parent' => 999999 ) )->get_error_code() );
	}

	/**
	 * Without the capability nothing is written.
	 */
	public function test_requires_the_manage_capability() {
		$term_id = $this->make_term( 'Guarded' );
		wp_set_current_user( 0 );

		$res = update_multisite_term( $term_id, $this->tax, array( 'name' => 'Should not stick' ) );

		$this->assertWPError( $res );
		$this->assertSame( 'invalid_user_permissions', $res->get_error_code() );
		$this->assertSame( 'Guarded', get_multisite_term( $term_id, $this->tax )->name );
	}

	/**
	 * Find-or-insert: create_multisite_term() returns the existing term rather than a second one.
	 */
	public function test_create_term_is_find_or_insert() {
		$term_id = $this->make_term( 'Existing' );

		$this->assertSame( $term_id, (int) create_multisite_term( 'Existing', $this->tax ) );

		$created = create_multisite_term( 'Brand New', $this->tax );
		$this->assertNotWPError( $created );
		$this->assertNotEmpty( multisite_term_exists( 'Brand New', $this->tax ) );
	}
}
