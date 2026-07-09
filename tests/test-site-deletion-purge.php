<?php
/**
 * Tests for purging a deleted site's relationship rows (Multitaxo_Plugin::delete_site_action_hook).
 *
 * Deleting a whole site drops its `wp_<id>_posts` table without firing `before_delete_post` per
 * post, so the plugin purges that blog's post-namespace relationship rows on `wp_delete_site` and
 * recounts the affected terms. User- and blog-namespace rows are network-global (blog_id 0) and
 * must survive. This guards the data-integrity behaviour added alongside the object_type column.
 *
 * @package multitaxo
 */

/**
 * Deleted-site relationship purge tests.
 */
class Test_Site_Deletion_Purge extends WP_UnitTestCase {

	/**
	 * Taxonomy spanning all three namespaces, registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'purge_tax';

	/**
	 * Relationships table name.
	 *
	 * @var string
	 */
	private $rel;

	/**
	 * Register a fresh taxonomy spanning all three namespaces before each test.
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->rel = $wpdb->base_prefix . 'multisite_term_relationships';
		register_multisite_taxonomy( $this->tax, array( 'post', 'user', 'blog' ), array( 'hierarchical' => true ) );
	}

	/**
	 * Create a term and return its ids.
	 *
	 * @param string $name Term name.
	 * @return array{term_id:int,mtmt_id:int} The created term's ids.
	 */
	private function make_term( string $name ): array {
		$res = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $res, 'term insert should succeed' );
		return array(
			'term_id' => (int) $res['multisite_term_id'],
			'mtmt_id' => (int) $res['multisite_term_multisite_taxonomy_id'],
		);
	}

	/**
	 * Count the raw relationship rows stored for a given blog_id.
	 *
	 * @param int $blog_id Blog id to count rows for.
	 * @return int Number of relationship rows at that blog_id.
	 */
	private function rows_for_blog( int $blog_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->rel} WHERE blog_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$blog_id
			)
		);
	}

	/**
	 * Deleting a site purges only that blog's post rows and leaves other blogs and the
	 * network-global user/blog rows untouched, then recounts the affected term.
	 */
	public function test_delete_site_purges_only_that_blogs_post_rows() {
		$term    = $this->make_term( 'Shared Across Sites' );
		$mtmt    = $term['mtmt_id'];
		$deleted = 77; // The site being deleted.
		$kept    = 88; // An unrelated site whose rows must survive.

		// Post rows on two different sites, plus network-global user and blog rows.
		set_object_multisite_terms( 101, array( $mtmt ), $this->tax, $deleted, false, '' );
		set_object_multisite_terms( 102, array( $mtmt ), $this->tax, $kept, false, '' );
		set_object_multisite_terms( 5, array( $mtmt ), $this->tax, 0, false, 'user' );
		set_object_multisite_terms( 9, array( $mtmt ), $this->tax, 0, false, 'blog' );

		$this->assertSame( 1, $this->rows_for_blog( $deleted ), 'the doomed site starts with one post row' );
		$this->assertSame( 1, $this->rows_for_blog( $kept ) );
		$this->assertSame( 4, (int) get_multisite_term( $term['term_id'], $this->tax )->count, 'count starts at all four rows' );

		$plugin = new Multitaxo_Plugin();
		$plugin->delete_site_action_hook( new WP_Site( (object) array( 'blog_id' => (string) $deleted ) ) );

		$this->assertSame( 0, $this->rows_for_blog( $deleted ), 'the deleted site post rows are gone' );
		$this->assertSame( 1, $this->rows_for_blog( $kept ), 'an unrelated site keeps its rows' );
		$this->assertSame( 2, $this->rows_for_blog( 0 ), 'network-global user and blog rows survive' );

		// The recount reflects the three surviving relationship rows.
		$this->assertSame( 3, (int) get_multisite_term( $term['term_id'], $this->tax )->count, 'the affected term is recounted after the purge' );
	}

	/**
	 * Deleting a site that has no relationship rows is a harmless no-op that leaves other data intact.
	 */
	public function test_delete_site_with_no_rows_is_a_noop() {
		$term = $this->make_term( 'Untouched' );
		set_object_multisite_terms( 5, array( $term['mtmt_id'] ), $this->tax, 0, false, 'user' );

		$this->assertSame( 1, (int) get_multisite_term( $term['term_id'], $this->tax )->count );

		$plugin = new Multitaxo_Plugin();
		$plugin->delete_site_action_hook( new WP_Site( (object) array( 'blog_id' => '999' ) ) );

		$this->assertSame( 1, $this->rows_for_blog( 0 ), 'the unrelated network-global user row is untouched' );
		$this->assertSame( 1, (int) get_multisite_term( $term['term_id'], $this->tax )->count, 'the term count is unchanged' );
	}
}
