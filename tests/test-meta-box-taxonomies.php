<?php
/**
 * Which taxonomies the shared "Multisite Tags" meta box offers, and saves.
 *
 * @package multitaxo
 */

/**
 * Tests for Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box().
 */
class Test_Meta_Box_Taxonomies extends WP_UnitTestCase {

	/**
	 * Taxonomies registered by a test, unregistered again in tear_down.
	 *
	 * @var string[]
	 */
	private $registered = array();

	/**
	 * Register a taxonomy and remember it for cleanup.
	 *
	 * @param string $name         Taxonomy name.
	 * @param array  $object_types Object types it applies to.
	 * @param array  $args         Registration args.
	 * @return void
	 */
	private function register( $name, $object_types, $args = array() ) {
		register_multisite_taxonomy( $name, $object_types, $args );
		$this->registered[] = $name;
	}

	/**
	 * Remove the taxonomies a test registered; the registry is a global that outlives the test.
	 */
	public function tear_down() {
		foreach ( $this->registered as $name ) {
			unregister_multisite_taxonomy( $name );
		}
		$this->registered = array();

		parent::tear_down();
	}

	/**
	 * The box is per object type: a blog- or user-only taxonomy has no business on a post screen.
	 */
	public function test_offers_only_taxonomies_of_the_requested_object_type() {
		$this->register( 'mbox_post_tax', array( 'post' ) );
		$this->register( 'mbox_blog_tax', array( 'blog' ) );

		$for_posts = Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box( 'post' );
		$this->assertArrayHasKey( 'mbox_post_tax', $for_posts );
		$this->assertArrayNotHasKey( 'mbox_blog_tax', $for_posts );

		$for_blogs = Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box( 'blog' );
		$this->assertArrayHasKey( 'mbox_blog_tax', $for_blogs );
		$this->assertArrayNotHasKey( 'mbox_post_tax', $for_blogs );
	}

	/**
	 * The registration-time opt-outs core uses for post taxonomies work here too.
	 */
	public function test_show_ui_and_meta_box_cb_false_opt_out() {
		$this->register( 'mbox_hidden_ui', array( 'post' ), array( 'show_ui' => false ) );
		$this->register( 'mbox_no_box', array( 'post' ), array( 'meta_box_cb' => false ) );
		$this->register( 'mbox_normal', array( 'post' ), array( 'show_ui' => true ) );

		$offered = Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box( 'post' );

		$this->assertArrayHasKey( 'mbox_normal', $offered );
		$this->assertArrayNotHasKey( 'mbox_hidden_ui', $offered );
		$this->assertArrayNotHasKey( 'mbox_no_box', $offered );
	}

	/**
	 * A plugin whose own UI owns the taxonomy on one screen can drop it there while it stays
	 * registered everywhere else.
	 */
	public function test_filter_can_hand_a_taxonomy_to_another_ui() {
		$this->register( 'mbox_owned_elsewhere', array( 'post', 'user' ), array( 'show_ui' => true ) );

		$filter = function ( $show, $taxonomy, $object_type ) {
			return 'mbox_owned_elsewhere' === $taxonomy->name && 'post' === $object_type ? false : $show;
		};
		add_filter( 'multisite_taxonomy_show_meta_box', $filter, 10, 3 );

		$this->assertArrayNotHasKey(
			'mbox_owned_elsewhere',
			Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box( 'post' )
		);
		$this->assertArrayHasKey(
			'mbox_owned_elsewhere',
			Multisite_Taxonomy_Meta_Box::taxonomies_for_meta_box( 'user' ),
			'the filter only spoke about posts'
		);

		remove_filter( 'multisite_taxonomy_show_meta_box', $filter, 10 );
	}

	/**
	 * The save handler asks the same question, so a stale form post cannot overwrite terms the
	 * box does not offer (the block-editor case: Gutenberg submits the meta-box form after the
	 * REST save).
	 */
	public function test_save_skips_a_taxonomy_the_box_does_not_offer() {
		$this->register( 'mbox_saved', array( 'post' ), array( 'show_ui' => true ) );
		$this->register( 'mbox_not_saved', array( 'post' ), array( 'show_ui' => true ) );

		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		grant_super_admin( $user_id );
		set_current_screen( 'post' );

		$filter = function ( $show, $taxonomy ) {
			return 'mbox_not_saved' === $taxonomy->name ? false : $show;
		};
		add_filter( 'multisite_taxonomy_show_meta_box', $filter, 10, 2 );

		$_POST['multisite_taxonomy_meta_box_nonce'] = wp_create_nonce( 'multisite_taxonomy_meta_box' );
		$_POST['multi_tax_input']                   = array(
			'mbox_saved'     => 'kept',
			'mbox_not_saved' => 'clobbered',
		);

		( new Multisite_Taxonomy_Meta_Box() )->save_multisite_taxonomy( $post_id );

		$blog_id = get_current_blog_id();
		$this->assertSame(
			array( 'kept' ),
			wp_list_pluck( get_object_multisite_terms( $post_id, 'mbox_saved', $blog_id ), 'name' )
		);
		$this->assertSame(
			array(),
			wp_list_pluck( get_object_multisite_terms( $post_id, 'mbox_not_saved', $blog_id ), 'name' )
		);

		remove_filter( 'multisite_taxonomy_show_meta_box', $filter, 10 );
		unset( $_POST['multisite_taxonomy_meta_box_nonce'], $_POST['multi_tax_input'] );
		revoke_super_admin( $user_id );
	}
}
