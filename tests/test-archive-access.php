<?php
/**
 * Access control tests for front-end multisite taxonomy archives.
 *
 * User/site archives list network-global objects, so only super admins may load them. Posts
 * archives remain public.
 *
 * @package multitaxo
 */

class Test_Archive_Access extends WP_UnitTestCase {

	/**
	 * Archive taxonomy registered fresh per test.
	 *
	 * @var string
	 */
	private $tax = 'archive_access_tax';

	/**
	 * Previous global WP request object.
	 *
	 * @var WP|null
	 */
	private $original_wp;

	/**
	 * Previous main query global.
	 *
	 * @var WP_Query|null
	 */
	private $original_wp_the_query;

	/**
	 * Previous query global.
	 *
	 * @var WP_Query|null
	 */
	private $original_wp_query;

	/**
	 * Users granted super admin during a test.
	 *
	 * @var int[]
	 */
	private $granted_super_admins = array();

	/**
	 * Last wp_die payload captured by the test handler.
	 *
	 * @var array|null
	 */
	private $last_wp_die = null;

	public function set_up() {
		parent::set_up();

		$this->original_wp           = isset( $GLOBALS['wp'] ) ? $GLOBALS['wp'] : null;
		$this->original_wp_the_query = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
		$this->original_wp_query     = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;

		register_multisite_taxonomy(
			$this->tax,
			array( 'post', 'user', 'blog' ),
			array(
				'hierarchical'      => true,
				'public'            => true,
				'publicly_queryable'=> true,
				'query_var'         => $this->tax,
			)
		);
	}

	public function tear_down() {
		foreach ( $this->granted_super_admins as $user_id ) {
			revoke_super_admin( $user_id );
		}

		wp_set_current_user( 0 );
		$GLOBALS['wp']           = $this->original_wp;
		$GLOBALS['wp_the_query'] = $this->original_wp_the_query;
		$GLOBALS['wp_query']     = $this->original_wp_query;

		parent::tear_down();
	}

	/**
	 * Create a term and return its slug.
	 */
	private function make_term_slug( $name ) {
		$result = insert_multisite_term( $name, $this->tax, array(), false );
		$this->assertNotWPError( $result );

		$term = get_multisite_term( (int) $result['multisite_term_id'], $this->tax );
		$this->assertNotFalse( $term );

		return $term->slug;
	}

	/**
	 * Build a main query object that targets a multisite taxonomy archive.
	 */
	private function make_archive_query( $slug, $object_type, $taxonomy = null ) {
		if ( null === $taxonomy ) {
			$taxonomy = $this->tax;
		}

		$GLOBALS['wp']          = new WP();
		$GLOBALS['wp']->request = 'multitaxo/' . $taxonomy . '/' . $slug;

		$query = new WP_Query();
		$query->set( $taxonomy, $slug );
		$query->set( 'multisite_object_type', $object_type );

		$GLOBALS['wp_the_query'] = $query;
		$GLOBALS['wp_query']     = $query;

		return $query;
	}

	/**
	 * Swap wp_die() for an exception so access denials can be asserted.
	 */
	public function provide_wp_die_handler() {
		return array( $this, 'handle_wp_die' );
	}

	/**
	 * Capture wp_die() calls during access-control tests.
	 */
	public function handle_wp_die( $message, $title = '', $args = array() ) {
		$this->last_wp_die = array(
			// Decode entities so assertions compare the human-readable message (esc_html() in the
			// controller turns the quotes around the taxonomy name into &quot;).
			'message' => html_entity_decode( wp_strip_all_tags( (string) $message ), ENT_QUOTES ),
			'title'   => $title,
			'args'    => $args,
		);

		throw new RuntimeException( 'wp_die called' );
	}

	public function test_non_super_admin_cannot_access_user_or_blog_archives() {
		$slug = $this->make_term_slug( 'Restricted Archive' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = Multitaxo_Plugin::get_archive_controller();
		add_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );

		foreach ( array( 'user', 'blog' ) as $object_type ) {
			$query = $this->make_archive_query( $slug, $object_type );
			$this->last_wp_die = null;

			try {
				$controller->maybe_setup_archive( $query );
				$this->fail( $object_type . ' archive should deny non-super-admin access' );
			} catch ( RuntimeException $exception ) {
				$this->assertSame( 'wp_die called', $exception->getMessage() );
			}

			$this->assertSame( 'User and blog lists are currently only accessible by super-admins.', $this->last_wp_die['message'] );
			$this->assertSame( 'Multisite Taxonomies', $this->last_wp_die['title'] );
			$this->assertSame( 403, $this->last_wp_die['args']['response'] );
			$this->assertFalse( $controller->is_archive(), 'denied archive should not stay active on the controller' );
			$this->assertSame( '', $controller->get_object_type(), 'denied archive should not expose an object type' );
		}

		remove_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );
	}

	public function test_super_admin_can_access_user_archive() {
		$slug = $this->make_term_slug( 'Allowed Archive' );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $user_id );
		$this->granted_super_admins[] = $user_id;
		wp_set_current_user( $user_id );

		$query      = $this->make_archive_query( $slug, 'user' );
		$controller = Multitaxo_Plugin::get_archive_controller();

		$controller->maybe_setup_archive( $query );

		$this->assertFalse( $query->is_404(), 'super admins should be able to load user archives' );
		$this->assertTrue( $controller->is_archive() );
		$this->assertSame( 'user', $controller->get_object_type() );
	}

	public function test_unsupported_blog_archive_gets_explicit_message() {
		$taxonomy = 'archive_access_user_only_tax';

		register_multisite_taxonomy(
			$taxonomy,
			array( 'post', 'user' ),
			array(
				'hierarchical'       => true,
				'public'             => true,
				'publicly_queryable' => true,
				'query_var'          => $taxonomy,
				'labels'             => array(
					'singular_name' => 'Affiliation',
				),
			)
		);

		$result = insert_multisite_term( 'Restricted Unsupported Blog Archive', $taxonomy, array(), false );
		$this->assertNotWPError( $result );
		$term = get_multisite_term( (int) $result['multisite_term_id'], $taxonomy );
		$this->assertNotFalse( $term );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = Multitaxo_Plugin::get_archive_controller();
		add_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );

		$query             = $this->make_archive_query( $term->slug, 'blog', $taxonomy );
		$this->last_wp_die = null;

		try {
			$controller->maybe_setup_archive( $query );
			$this->fail( 'unsupported blog archive should show a dedicated registration error' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'wp_die called', $exception->getMessage() );
		}

		$this->assertSame( 'The requested site archive is not registered for the "Affiliation" taxonomy.', $this->last_wp_die['message'] );
		$this->assertSame( 'Multisite Taxonomies', $this->last_wp_die['title'] );
		$this->assertSame( 404, $this->last_wp_die['args']['response'] );
		$this->assertFalse( $controller->is_archive() );
		$this->assertSame( '', $controller->get_object_type() );

		remove_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );
	}
}
