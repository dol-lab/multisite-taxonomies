<?php
/**
 * Multisite Taxonomies Plugin init class
 *
 * @package multitaxo
 */

/**
 * Plugin Init class.
 */
class Multitaxo_Plugin {

	/**
	 * Current database schema version. Bump when the table structure changes and add a
	 * matching migration branch in maybe_upgrade_database().
	 *
	 * @var int
	 */
	const DB_VERSION = 2;

	/**
	 * List table class.
	 *
	 * @access private
	 * @var Multisite_Terms_List_Table
	 */
	private $list_table;

	/**
	 * Front-end term archive controller.
	 *
	 * @access private
	 * @var Multisite_Taxonomy_Archive
	 */
	private static $archive_controller;

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		// Front-end controller that renders per-blog archives for multisite taxonomy terms.
		self::$archive_controller = new Multisite_Taxonomy_Archive();

		// We enqueue both the frontend and admin styles and scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles_and_scripts' ) );

		// Register an activation/deactivation hooks.
		add_action( 'activate_multisite-taxonomies/multisite-taxonomies.php', array( $this, 'activation_hook' ) );
		add_action( 'deactivate_multisite-taxonomies/multisite-taxonomies.php', array( $this, 'deactivation_hook' ) );

		// Add the editing tags screen.
		add_action( 'network_admin_menu', array( $this, 'add_network_menu_terms' ) );
		add_filter( 'set-screen-option', array( $this, 'multisite_set_screen_option' ), 10, 3 );

		// Add link to the network-admin admin-bar dropdown.
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_menu' ), 21 );

		// Hide menu items we dont want to make visible to the world but want to leave behind.
		add_action( 'admin_head', array( $this, 'hide_network_menu_terms' ), 1 );

		// register our tables to WPDB.
		add_action( 'init', array( __CLASS__, 'register_database_tables' ), 1 );
		add_action( 'switch_blog', array( __CLASS__, 'register_database_tables' ) );

		// Run schema migrations for installs predating the current DB version.
		add_action( 'init', array( __CLASS__, 'maybe_upgrade_database' ), 2 );

		// Signal consumers to register their taxonomies in exactly the CRUD contexts
		// (network admin, WP-CLI, term-CRUD ajax), so none has to re-derive the gate itself.
		add_action( 'init', array( $this, 'fire_register_signal' ), 5 );

		// register the ajax response for creating new tags.
		add_action( 'wp_ajax_add-multisite-tag', array( $this, 'ajax_add_multisite_tag' ) );
		add_action( 'wp_ajax_inline-save-multisite-tax', array( $this, 'ajax_inline_save_multisite_tag' ) );

		// register action hooks for specific actions.
		add_action( 'before_delete_post', array( $this, 'before_delete_post_action_hook' ) );

		// A deleted site drops its wp_<id>_posts table directly, without firing
		// before_delete_post per post, so purge that blog's relationship rows here.
		add_action( 'wp_delete_site', array( $this, 'delete_site_action_hook' ) );

		// Filter the native network Users / Sites lists to a single multisite term when linked from
		// the term list table's count column.
		add_action( 'pre_get_users', array( $this, 'filter_network_users_by_multisite_term' ) );
		add_action( 'pre_get_sites', array( $this, 'filter_network_sites_by_multisite_term' ) );
		add_action( 'network_admin_notices', array( $this, 'multisite_term_filter_notice' ) );
	}

	/**
	 * Fire the `multisite_taxonomies_register` action in CRUD contexts.
	 *
	 * Taxonomies whose only UI is the network term screens do not need to register on every
	 * front-end request. The trap is that "register in the network admin" is not enough: the
	 * add/inline-edit screens submit over admin-ajax.php, where is_network_admin() is false.
	 * Hooking this action (instead of re-deriving the context) registers a taxonomy in exactly
	 * the requests where its screens and ajax handlers run, and nowhere else.
	 *
	 * @return void
	 */
	public function fire_register_signal() {
		if ( self::is_crud_request() ) {
			/**
			 * Fires on init in every request where multisite-taxonomy CRUD operates:
			 * the network admin, WP-CLI, or one of this plugin's term-CRUD ajax actions.
			 * Register multisite taxonomies here to have them available for every screen and
			 * ajax handler without gating on is_network_admin() yourself.
			 */
			do_action( 'multisite_taxonomies_register' );
		}
	}

	/**
	 * Whether the current request is one where multisite-taxonomy CRUD screens operate:
	 * the network admin, WP-CLI, or one of this plugin's own term-CRUD ajax actions.
	 *
	 * Exposed so consumers that prefer to register on their own `init` hook can share this
	 * single definition of the CRUD context instead of duplicating the is_network_admin()
	 * / wp_doing_ajax() checks (which is exactly where the "invalid taxonomy" bug came from).
	 *
	 * @return bool
	 */
	public static function is_crud_request() {
		if ( is_network_admin() ) {
			return true;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( wp_doing_ajax() ) {
			// The ajax action is the router key, not user data; each handler verifies its own nonce.
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return in_array( $action, self::ajax_crud_actions(), true );
		}
		return false;
	}

	/**
	 * The plugin's own ajax action names that operate on terms and therefore need the taxonomy
	 * registered. Covers both the term-CRUD writes and the term-box reads (autocomplete search
	 * and the "most used" cloud), which resolve the taxonomy via get_multisite_taxonomy() and
	 * otherwise bail with a bare `0`. Kept next to the wp_ajax_* registrations in the constructor.
	 *
	 * @return string[]
	 */
	private static function ajax_crud_actions() {
		return array(
			'add-multisite-tag',
			'inline-save-multisite-tax',
			'ajax-multisite-tag-search',
			'ajax-get-multisite-term-cloud',
		);
	}

	/**
	 * A human- and developer-readable "invalid taxonomy" message.
	 *
	 * Names the slug, says whether it is registered at all, and reports the request context,
	 * so the usual cause (a taxonomy that registers only in the network admin but not during
	 * its CRUD ajax) is self-evident instead of a bare "Invalid multisite taxonomy."
	 *
	 * @param string|null $taxonomy The requested taxonomy slug.
	 * @return string
	 */
	public static function invalid_taxonomy_message( $taxonomy ) {
		$taxonomy = (string) $taxonomy;

		if ( '' === $taxonomy ) {
			return __( 'No multisite taxonomy was specified.', 'multitaxo' );
		}

		if ( multisite_taxonomy_exists( $taxonomy ) ) {
			/* translators: %s: taxonomy slug. */
			return sprintf( __( 'The multisite taxonomy "%s" exists but is not available in this context.', 'multitaxo' ), $taxonomy );
		}

		$registered = array_keys( (array) $GLOBALS['multisite_taxonomies'] );

		return sprintf(
			/* translators: 1: requested taxonomy slug, 2: request-context flags, 3: comma-separated registered slugs. */
			__( 'The multisite taxonomy "%1$s" is not registered for this request (%2$s). Registered here: %3$s. A taxonomy that registers only in the network admin must also register during its CRUD ajax actions — hook the multisite_taxonomies_register action, or gate on Multitaxo_Plugin::is_crud_request().', 'multitaxo' ),
			$taxonomy,
			sprintf( 'doing_ajax=%d, network_admin=%d', (int) wp_doing_ajax(), (int) is_network_admin() ),
			$registered ? implode( ', ', $registered ) : __( '(none)', 'multitaxo' )
		);
	}

	/**
	 * Send a descriptive invalid-taxonomy error as a WP_Ajax_Response and stop.
	 *
	 * Ajax endpoints must never wp_die() raw text: the term screens' JS expects the XML
	 * wp_ajax envelope, so a bare die produces an unparseable body that the UI swallows.
	 * This surfaces the reason in #ajax-response instead.
	 *
	 * @param string|null $taxonomy The requested taxonomy slug.
	 * @return void Never returns; WP_Ajax_Response::send() exits.
	 */
	private function send_invalid_taxonomy_ajax_error( $taxonomy ) {
		$response = new WP_Ajax_Response();
		$response->add(
			array(
				'what' => 'multisite_taxonomy',
				'data' => new WP_Error( 'invalid_multisite_taxonomy', self::invalid_taxonomy_message( $taxonomy ) ),
			)
		);
		$response->send();
	}

	/**
	 * Read and validate the multisite-term filter carried on a network-admin list screen.
	 *
	 * Returns the requested taxonomy/term only in the network admin, and only when the current user
	 * may manage the taxonomy's terms. This is read-only GET navigation (linked from the term list
	 * table), so no nonce is required, but the capability is enforced. Per-screen gating (users.php
	 * vs sites.php) is done by the individual callers.
	 *
	 * @access private
	 * @return array|null { @type string $taxonomy, @type int $term_id } or null when not filtering.
	 */
	private function read_term_filter_args() {
		if ( ! is_network_admin() ) {
			return null;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation link, capability-checked below.
		$tax_slug = isset( $_GET['multisite_taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['multisite_taxonomy'] ) ) : '';
		$term_id  = isset( $_GET['multisite_term_id'] ) ? absint( wp_unslash( $_GET['multisite_term_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $tax_slug || 0 === $term_id ) {
			return null;
		}

		$tax = get_multisite_taxonomy( $tax_slug );
		if ( ! is_a( $tax, 'Multisite_Taxonomy' ) || ! current_user_can( $tax->cap->manage_multisite_terms ) ) {
			return null;
		}

		return array(
			'taxonomy' => $tax_slug,
			'term_id'  => $term_id,
		);
	}

	/**
	 * The object IDs (users or sites) assigned to the filtered term, rolled up over descendants.
	 *
	 * @access private
	 * @param array  $filter      The validated filter from read_term_filter_args().
	 * @param string $object_type 'user' or 'blog'.
	 * @return int[] Object IDs; may be empty.
	 */
	private function filtered_term_object_ids( $filter, $object_type ) {
		$term_ids = $this->multisite_term_ids_with_children( $filter['term_id'], $filter['taxonomy'] );
		$result   = get_multisite_term_object_ids( $term_ids, $filter['taxonomy'], $object_type, array( 'number' => 0 ) );
		return $result['ids'];
	}

	/**
	 * The term plus its descendants (hierarchical roll-up), so a parent term's filter also covers
	 * users assigned to child terms — matching the front-end archive and the posts archive.
	 *
	 * @access private
	 * @param int    $term_id  Multisite term ID.
	 * @param string $taxonomy Multisite taxonomy name.
	 * @return int[]
	 */
	private function multisite_term_ids_with_children( $term_id, $taxonomy ) {
		$ids = array( (int) $term_id );

		if ( is_multisite_taxonomy_hierarchical( $taxonomy ) ) {
			$children = get_multisite_term_children( $term_id, $taxonomy );
			if ( is_array( $children ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $children ) );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Restrict the network Users list to the users assigned to the filtered multisite term.
	 *
	 * @access public
	 * @param WP_User_Query $query The user query for the list table.
	 * @return void
	 */
	public function filter_network_users_by_multisite_term( $query ) {
		global $pagenow;
		if ( 'users.php' !== $pagenow ) {
			return;
		}

		$filter = $this->read_term_filter_args();
		if ( null === $filter ) {
			return;
		}

		$ids = $this->filtered_term_object_ids( $filter, 'user' );

		// 'include' with a non-matching id (0) forces an empty list when the term has no users;
		// an empty 'include' would otherwise be ignored and show every user.
		$query->set( 'include', ! empty( $ids ) ? $ids : array( 0 ) );
	}

	/**
	 * Restrict the network Sites list to the sites assigned to the filtered multisite term.
	 *
	 * @access public
	 * @param WP_Site_Query $query The site query for the list table.
	 * @return void
	 */
	public function filter_network_sites_by_multisite_term( $query ) {
		global $pagenow;
		if ( 'sites.php' !== $pagenow ) {
			return;
		}

		$filter = $this->read_term_filter_args();
		if ( null === $filter ) {
			return;
		}

		$ids = $this->filtered_term_object_ids( $filter, 'blog' );

		// 'site__in' with a non-matching id (0) forces an empty list when the term has no sites.
		$query->query_vars['site__in'] = ! empty( $ids ) ? $ids : array( 0 );
	}

	/**
	 * Show which multisite term the network Users / Sites list is filtered by, with a clear link.
	 *
	 * @access public
	 * @return void
	 */
	public function multisite_term_filter_notice() {
		global $pagenow;
		if ( 'users.php' !== $pagenow && 'sites.php' !== $pagenow ) {
			return;
		}

		$filter = $this->read_term_filter_args();
		if ( null === $filter ) {
			return;
		}

		$tax  = get_multisite_taxonomy( $filter['taxonomy'] );
		$term = get_multisite_term( $filter['term_id'], $filter['taxonomy'] );
		if ( ! is_a( $term, 'Multisite_Term' ) ) {
			return;
		}

		$clear = remove_query_arg( array( 'multisite_taxonomy', 'multisite_term_id' ) );

		$message = ( 'sites.php' === $pagenow )
			/* translators: 1: taxonomy singular label, 2: term name. */
			? sprintf( __( 'Showing sites assigned to %1$s: %2$s.', 'multitaxo' ), $tax->labels->singular_name, $term->name )
			/* translators: 1: taxonomy singular label, 2: term name. */
			: sprintf( __( 'Showing users assigned to %1$s: %2$s.', 'multitaxo' ), $tax->labels->singular_name, $term->name );

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( $clear ),
			esc_html__( 'Clear filter', 'multitaxo' )
		);
	}

	/**
	 * Get the shared front-end archive controller instance.
	 *
	 * @access public
	 * @return Multisite_Taxonomy_Archive|null
	 */
	public static function get_archive_controller() {
		return self::$archive_controller;
	}

	/**
	 * Register the Multisite Taxonomies database tables for use with $wpdb.
	 *
	 * @global wpdb $wpdb The WordPress database abstraction object.
	 *
	 * @access public
	 * @return void
	 */
	public static function register_database_tables() {
		global $wpdb;

		$wpdb->multisite_termmeta                = $wpdb->base_prefix . 'multisite_termmeta';
		$wpdb->multisite_terms                   = $wpdb->base_prefix . 'multisite_terms';
		$wpdb->multisite_term_relationships      = $wpdb->base_prefix . 'multisite_term_relationships';
		$wpdb->multisite_term_multisite_taxonomy = $wpdb->base_prefix . 'multisite_term_multisite_taxonomy';

		if ( false === get_site_option( 'multitaxo_tables_created' ) ) {
			self::create_database_tables();
		}
	}

	/**
	 * Enqueue the frontend styles and scripts.
	 *
	 * @access public
	 * @return void
	 */
	public function enqueue_styles_and_scripts() {
	}

	/**
	 * Enqueue the admin styles and scripts.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_enqueue_styles_and_scripts() {
		wp_register_script( 'admin-multisite-tags', MULTITAXO_PLUGIN_URL . '/assets/js/admin-multisite-tags.js', array( 'jquery', 'wp-ajax-response' ), MULTITAXO_VERSION, true );
		wp_localize_script(
			'admin-multisite-tags',
			'tagsl10n',
			array(
				'noPerm' => esc_html__( 'Sorry, you are not allowed to do that.', 'multitaxo' ),
				'broken' => esc_html__( 'An unidentified error has occurred.', 'multitaxo' ),
			)
		);

		wp_register_script( 'inline-edit-multisite-tax', MULTITAXO_PLUGIN_URL . '/assets/js/inline-edit-multisite-tax.js', array( 'jquery', 'wp-a11y' ), MULTITAXO_VERSION, true );
		wp_localize_script(
			'inline-edit-multisite-tax',
			'inlineEditL10n',
			array(
				'error' => esc_html__( 'Error while saving the changes.', 'multitaxo' ),
				'saved' => esc_html__( 'Changes saved.', 'multitaxo' ),
			)
		);
	}

	/**
	 * Plugin activation hook callback.
	 *
	 * @access public
	 * @return void
	 */
	public function activation_hook() {
		self::register_database_tables();
	}

	/**
	 * Plugin deactivation hook callback.
	 *
	 * @access public
	 * @return void
	 */
	public function deactivation_hook() {
		$this->delete_database_tables();
	}

	/**
	 * Create our custom database tables.
	 *
	 * @global wpdb $wpdb The WordPress database abstraction object.
	 *
	 * @access public
	 * @return void
	 */
	public static function create_database_tables() {
		global $wpdb;
		// Load the db delta scripts.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Get characterset of the server.
		$charset_collate = $wpdb->get_charset_collate();

		/*
		 * Indexes have a maximum size of 767 bytes. Historically, we haven't need to be concerned about that.
		 * As of 4.2, however, we moved to utf8mb4, which uses 4 bytes per character. This means that an index which
		 * used to have room for floor(767/3) = 255 characters, now only has room for floor(767/4) = 191 characters.
		 */

		$max_index_length = 191;

		// Table structure for table `wp_multisite_termmeta`.
		$multisite_termmeta_sql = 'CREATE TABLE IF NOT EXISTS `' . $wpdb->multisite_termmeta . '` (
			meta_id bigint(20) unsigned NOT NULL auto_increment,
			multisite_term_id bigint(20) unsigned NOT NULL default "0",
			meta_key varchar(255) default NULL,
			meta_value longtext,
			PRIMARY KEY  (meta_id),
			KEY multisite_term_id (multisite_term_id),
			KEY meta_key (meta_key(' . $max_index_length . '))
		) ' . $charset_collate . ';';

		$wpdb->query( $multisite_termmeta_sql );

		// Table structure for table `wp_multisite_terms`.
		$multisite_terms_sql = 'CREATE TABLE IF NOT EXISTS `' . $wpdb->multisite_terms . '` (
			multisite_term_id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(200) NOT NULL default "",
			slug varchar(200) NOT NULL default "",
			multisite_term_group bigint(10) NOT NULL default 0,
			PRIMARY KEY  (multisite_term_id),
			KEY slug (slug(' . $max_index_length . ')),
			KEY name (name(' . $max_index_length . '))
		) ' . $charset_collate . ';';

		$wpdb->query( $multisite_terms_sql );

		// Table structure for table `wp_multisite_term_relationships`.
		//
		// `object_type` records the ID namespace of `object_id`:
		// - '' (empty) => post namespace (post, page, any CPT), the default and legacy value.
		// - 'user' => wp_users.
		// - 'blog' => wp_blogs.
		// Posts are always stored as '' (never the literal 'post'), so the three values
		// stay distinct and a single taxonomy can safely span object types. See plan.md.
		$multisite_term_relationships_sql = 'CREATE TABLE IF NOT EXISTS `' . $wpdb->multisite_term_relationships . '` (
			blog_id bigint(20) unsigned NOT NULL default 0,
			object_id bigint(20) unsigned NOT NULL default 0,
			multisite_term_multisite_taxonomy_id bigint(20) unsigned NOT NULL default 0,
			multisite_term_order int(11) NOT NULL default 0,
			object_type varchar(20) NOT NULL default "",
			PRIMARY KEY  (blog_id,object_id,multisite_term_multisite_taxonomy_id,object_type),
			KEY multisite_term_multisite_taxonomy_id (multisite_term_multisite_taxonomy_id)
		) ' . $charset_collate . ';';

		$wpdb->query( $multisite_term_relationships_sql );

		// Table structure for table `wp_multisite_term_multisite_taxonomy`.
		$multisite_term_multisite_taxonomy_sql = 'CREATE TABLE IF NOT EXISTS `' . $wpdb->multisite_term_multisite_taxonomy . '` (
			multisite_term_multisite_taxonomy_id bigint(20) unsigned NOT NULL auto_increment,
			multisite_term_id bigint(20) unsigned NOT NULL default 0,
			multisite_taxonomy varchar(32) NOT NULL default "",
			description longtext NOT NULL,
			parent bigint(20) unsigned NOT NULL default 0,
			count bigint(20) NOT NULL default 0,
			PRIMARY KEY  (multisite_term_multisite_taxonomy_id),
			UNIQUE KEY multisite_term_id_multisite_taxonomy (multisite_term_id,multisite_taxonomy),
			KEY multisite_taxonomy (multisite_taxonomy)
		) ' . $charset_collate . ';';

		$wpdb->query( $multisite_term_multisite_taxonomy_sql );

		update_site_option( 'multitaxo_tables_created', 1 );

		// Fresh installs already have the latest schema, so record the current DB version.
		update_site_option( 'multitaxo_db_version', self::DB_VERSION );
	}

	/**
	 * Run schema migrations for installs created before the current DB version.
	 *
	 * Gated by the `multitaxo_db_version` site option so the ALTER statements only run
	 * once per upgrade. Each migration must be safe to run against live data.
	 *
	 * @global wpdb $wpdb The WordPress database abstraction object.
	 *
	 * @access public
	 * @return void
	 */
	public static function maybe_upgrade_database() {
		global $wpdb;

		// Tables not created yet: register_database_tables() handles fresh installs.
		if ( false === get_site_option( 'multitaxo_tables_created' ) ) {
			return;
		}

		if ( (int) get_site_option( 'multitaxo_db_version', 0 ) >= self::DB_VERSION ) {
			return;
		}

		// The migration runs on `init` for every request, so concurrent PHP workers can race:
		// each passes the version/column check before any commits its ALTER, and the losers hit
		// "Duplicate column name". Serialize with a MySQL advisory lock so exactly one worker
		// migrates; the others bail immediately and pick up the bumped version on a later request.
		if ( '1' !== (string) $wpdb->get_var( "SELECT GET_LOCK('multitaxo_upgrade', 0)" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			return;
		}

		try {
			// Re-read inside the lock: a prior holder may have finished the upgrade already.
			$installed = (int) get_site_option( 'multitaxo_db_version', 0 );
			if ( $installed >= self::DB_VERSION ) {
				return;
			}

			// Each step must fully succeed before we record the new version; a failed step
			// leaves multitaxo_db_version untouched so a later request retries it, rather than
			// marking a broken schema as "done".
			if ( $installed < 2 && ! self::upgrade_relationships_object_type( $wpdb ) ) {
				return;
			}

			update_site_option( 'multitaxo_db_version', self::DB_VERSION );
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('multitaxo_upgrade')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Migration to schema v2: give the relationships table an `object_type` column and fold it into the PK.
	 *
	 * Idempotent and fail-safe. The column and the primary-key rebuild are checked and applied
	 * independently, so a partial upgrade (column added, PK not yet rebuilt, e.g. after a crash
	 * or a failed DDL statement) is completed on the next run instead of being skipped. Any DB
	 * error aborts with false so the caller does not record the upgrade as complete.
	 *
	 * @param wpdb $wpdb The WordPress database abstraction object.
	 * @return bool True when the table matches the v2 schema, false if a step failed.
	 */
	private static function upgrade_relationships_object_type( $wpdb ) {
		$table = $wpdb->multisite_term_relationships;

		// Add the column; all existing rows default to '' (the post namespace).
		$has_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'object_type' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $has_column )
			&& false === $wpdb->query( 'ALTER TABLE `' . $table . '` ADD COLUMN object_type varchar(20) NOT NULL DEFAULT "" AFTER multisite_term_order' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		) {
			return false;
		}

		// Rebuild the PK with object_type appended, independently of the column step so a
		// half-applied upgrade still completes on retry. Safe: every existing row has object_type
		// '', so the new key stays unique and its leftmost prefix matches the old PK.
		$pk_has_object_type = $wpdb->get_var( $wpdb->prepare( 'SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY' AND Column_name = %s", 'object_type' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $pk_has_object_type )
			&& false === $wpdb->query( 'ALTER TABLE `' . $table . '` DROP PRIMARY KEY, ADD PRIMARY KEY (blog_id,object_id,multisite_term_multisite_taxonomy_id,object_type)' ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		) {
			return false;
		}

		return true;
	}

	/**
	 * Remove our custom database tables on plugin deactivation.
	 *
	 * @access public
	 * @return void
	 */
	public function delete_database_tables() {
		// Silence.
	}

	/**
	 * Add the metowrk admin menu to the terms page.
	 *
	 * @access public
	 * @return void
	 */
	/**
	 * Add a link to the network-admin dropdown in the admin bar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar object.
	 * @return void
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! is_multisite() || ! current_user_can( 'manage_multisite_terms' ) ) {
			return;
		}
		$wp_admin_bar->add_node(
			array(
				'id'     => 'multisite-taxonomies',
				'title'  => esc_html__( 'Multisite Taxonomies', 'multitaxo' ),
				'href'   => network_admin_url( 'admin.php?page=multisite_term_list' ),
				'parent' => 'network-admin',
			)
		);
	}

	/**
	 * Register the network-admin "Taxonomies" menu and its per-taxonomy submenus.
	 *
	 * @return void
	 */
	public function add_network_menu_terms() {
		$screen = add_menu_page( esc_html__( 'Multisite Taxonomies', 'multitaxo' ), esc_html__( 'Taxonomies', 'multitaxo' ), 'manage_multisite_terms', 'multisite_term_list', array( $this, 'display_multisite_taxonomy_list' ), 'dashicons-tag', 22 );

		add_submenu_page( 'multisite_term_list', esc_html__( 'Edit Tag', 'multitaxo' ), esc_html__( 'Edit Tag', 'multitaxo' ), 'manage_multisite_terms', 'multisite_term_edit', array( $this, 'display_multisite_taxonomy_edit_screen' ) );

		add_submenu_page( 'multisite_term_list', esc_html__( 'Assigned Objects', 'multitaxo' ), esc_html__( 'Assigned Objects', 'multitaxo' ), 'manage_multisite_terms', 'multisite_term_objects', array( $this, 'display_multisite_term_objects' ) );

		$taxonomies = get_multisite_taxonomies( array(), 'objects' );

		foreach ( $taxonomies as $tax_slug => $tax ) {
			$screen_hook = add_submenu_page( 'multisite_term_list', $tax->label, $tax->label, 'manage_multisite_terms', 'multisite_term_list_' . $tax_slug, array( $this, 'display_multisite_taxonomy' ) );
			add_action( 'load-' . $screen_hook, array( $this, 'load_multisite_taxonomy' ) );
		}
	}

	/**
	 * Hide netowrk Menu Items we dont want to be seen.
	 *
	 * @access public
	 * @return void
	 */
	public function hide_network_menu_terms() {
		remove_submenu_page( 'multisite_term_list', 'multisite_term_edit' );
		remove_submenu_page( 'multisite_term_list', 'multisite_term_objects' );
	}

	/**
	 * Save the screen options hook.
	 *
	 * @access public
	 *
	 * @param string $status Set the screen option value.
	 * @param string $option The option to check.
	 * @param mixed  $value The value of hte option to use.
	 *
	 * @return mixed option value.
	 */
	public function multisite_set_screen_option( $status, $option, $value ) {
		if ( 'edit_multisite_tax_per_page' === $option ) {
			return $value;
		}
	}

	/**
	 * Display the list table screen in the network.
	 *
	 * @access public
	 * @return void
	 */
	public function load_multisite_taxonomy() {
		$page = ( isset( $_GET['page'] ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		$tax_slug = str_replace( 'multisite_term_list_', '', $page );

		// Check that we have something.
		if ( empty( $tax_slug ) ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'multitaxo' ) );
		}

		$mulsite_taxonomy = get_multisite_taxonomy( $tax_slug );

		if ( ! is_a( $mulsite_taxonomy, 'Multisite_Taxonomy' ) ) {
			wp_die( esc_html__( 'Invalid multisite taxonomy.', 'multitaxo' ) );
		}

		if ( ! in_array( $mulsite_taxonomy->name, get_multisite_taxonomies( array( 'show_ui' => true ) ), true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit multisite terms in this multisite taxonomy.', 'multitaxo' ) );
		}

		if ( ! current_user_can( $mulsite_taxonomy->cap->manage_multisite_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage multisite terms in this multisite taxonomy.', 'multitaxo' ) . '</p>',
				403
			);
		}

		$screen = get_current_screen();

		// well this is dumb we are setting the multisite tex only to get it again.
		$screen->taxonomy = $tax_slug;

		/**
		 * $post_type is set when the WP_Terms_List_Table instance is created
		 *
		 * @global string $post_type
		 */
		$this->list_table = new Multisite_Terms_List_Table();

		$pagenum = $this->list_table->get_pagenum();
		$title   = $mulsite_taxonomy->labels->name;

		add_screen_option(
			'per_page',
			array(
				'default' => 20,
				'option'  => 'edit_multisite_tax_per_page',
			)
		);

		get_current_screen()->set_screen_reader_content(
			array(
				'heading_pagination' => $mulsite_taxonomy->labels->items_list_navigation,
				'heading_list'       => $mulsite_taxonomy->labels->items_list,
			)
		);

		$location = false;

		$referer = wp_get_referer();

		if ( ! $referer ) { // For POST requests.
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$referer = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
			} else {
				$referer = '/';
			}
		}

		$referer = remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'error', 'message', 'paged' ), $referer );

		switch ( $this->list_table->current_action() ) {

			case 'add-multisite-tag':
				check_admin_referer( 'add-multisite-tag', 'nonce-add-multisite-tag' );

				if ( ! current_user_can( $mulsite_taxonomy->cap->edit_multisite_terms ) ) {
					wp_die(
						'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
						'<p>' . esc_html__( 'Sorry, you are not allowed to create terms in this taxonomy.', 'multitaxo' ) . '</p>',
						403
					);
				}

				$tag = null;
				if ( isset( $_POST['tag-name'] ) ) {
					$tag = insert_multisite_term( sanitize_text_field( wp_unslash( $_POST['tag-name'] ) ), $mulsite_taxonomy->name, $_POST );
				}

				if ( $tag && ! is_wp_error( $tag ) ) {
					$location = add_query_arg( 'message', 1, $referer );
				} else {
					$location = add_query_arg(
						array(
							'error'   => true,
							'message' => 4,
						),
						$referer
					);
				}

				break;
			case 'delete':
				if ( ! isset( $_REQUEST['multisite_term_id'] ) ) {
					break;
				}

				$tag_id = (int) absint( wp_unslash( $_REQUEST['multisite_term_id'] ) );

				check_admin_referer( 'delete-multisite_term_' . $tag_id );

				if ( ! current_user_can( 'delete_multisite_term', $tag_id ) ) {
					wp_die(
						'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
						'<p>' . esc_html__( 'Sorry, you are not allowed to delete this item.', 'multitaxo' ) . '</p>',
						403
					);
				}

				delete_multisite_term( $tag_id, $mulsite_taxonomy->name );

				$location = add_query_arg( 'message', 2, $referer );

				// When deleting a term, prevent the action from redirecting back to a term that no longer exists.
				$location = remove_query_arg( array( 'multisite_term_id', 'action', 'page' ), $location );

				$location = add_query_arg( 'page', 'multisite_term_list_' . $mulsite_taxonomy->name, $location );

				break;
			case 'bulk-delete':
				check_admin_referer( 'bulk-tags' );

				if ( ! current_user_can( $mulsite_taxonomy->cap->delete_multisite_terms ) ) {
					wp_die(
						'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
						'<p>' . esc_html__( 'Sorry, you are not allowed to delete these items.', 'multitaxo' ) . '</p>',
						403
					);
				}

				if ( isset( $_REQUEST['delete_multisite_terms'] ) && is_array( wp_unslash( $_REQUEST['delete_multisite_terms'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$multisite_terms = array_map( 'absint', wp_unslash( $_REQUEST['delete_multisite_terms'] ) );
					foreach ( $multisite_terms as $multisite_terms_id ) {
						delete_multisite_term( $multisite_terms_id, $mulsite_taxonomy->name );
					}
				}

				$location = add_query_arg( 'message', 6, $referer );

				break;
			case 'edit':
				if ( ! isset( $_REQUEST['multisite_term_id'] ) ) {
					break;
				}

				$multisite_term_id = (int) absint( wp_unslash( $_POST['multisite_term_id'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$term              = get_multisite_term( $multisite_term_id );

				if ( ! $term instanceof WP_Term ) {
					wp_die( esc_html__( 'You attempted to edit an item that doesn&#8217;t exist. Perhaps it was deleted?', 'multitaxo' ) );
				}

				wp_safe_redirect( esc_url_raw( get_multisite_edit_term_link( $multisite_term_id, $mulsite_taxonomy->name ) ) );

				exit;
			case 'editedtag':
				if ( ! isset( $_REQUEST['multisite_term_id'] ) ) {
					break;
				}

				$tag_id = (int) absint( wp_unslash( $_POST['multisite_term_id'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				check_admin_referer( 'update-multisite-term_' . $tag_id );

				if ( ! current_user_can( 'edit_multisite_terms', $tag_id ) ) {
					wp_die(
						'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
						'<p>' . esc_html__( 'Sorry, you are not allowed to edit this item.', 'multitaxo' ) . '</p>',
						403
					);
				}

				$tag = get_multisite_term( $tag_id, $mulsite_taxonomy->name );

				if ( ! $tag ) {
					wp_die( esc_html__( 'You attempted to edit an item that doesn&#8217;t exist. Perhaps it was deleted?', 'multitaxo' ) );
				}

				$ret = update_multisite_term( $tag_id, $mulsite_taxonomy->name, $_POST );

				if ( $ret && ! is_wp_error( $ret ) ) {
					$location = add_query_arg( 'message', 3, $referer );
				} else {
					$location = add_query_arg(
						array(
							'error'   => true,
							'message' => 5,
						),
						$referer
					);
				}

				break;
			default:
				if ( ! $this->list_table->current_action() || ! isset( $_REQUEST['delete_tags'] ) ) {
					break;
				}

				check_admin_referer( 'bulk-tags' );

				// Good idea to make sure things are set before using them.
				$tags = isset( $_REQUEST['delete_tags'] ) ? (array) wp_unslash( $_REQUEST['delete_tags'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

				// Sanitize the tags.
				$tags = array_map( 'sanitize_text_field', $tags );

				/** This action is documented in wp-admin/edit-comments.php */
				$location = apply_filters( 'handle_bulk_actions_' . get_current_screen()->id, $location, $this->list_table->current_action(), $tags );

				break;
		}

		if ( ! $location && ! empty( $_REQUEST['_wp_http_referer'] ) ) {
			$location = remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}

		if ( $location ) {
			if ( $pagenum > 1 ) {
				$location = add_query_arg( 'paged', $pagenum, $location ); // $pagenum takes care of $total_pages.
			}
			/**
			 * Filters the taxonomy redirect destination URL.
			 *
			 * @since 4.6.0
			 *
			 * @param string $location The destination URL.
			 * @param object $mulsite_taxonomy The taxonomy object.
			 */
			wp_safe_redirect( apply_filters( 'redirect_term_location', $location, $mulsite_taxonomy ) );
			exit;
		}

		$this->list_table->prepare_items();

		$total_pages = $this->list_table->get_pagination_arg( 'total_pages' );

		if ( $pagenum > $total_pages && $total_pages > 0 ) {
			wp_safe_redirect( add_query_arg( 'paged', $total_pages ) );
			exit;
		}
	}

	/**
	 * Add a new multsite term to the database via ajax if it does not already exist.
	 *
	 * @return void
	 */
	public function ajax_add_multisite_tag() {
		check_ajax_referer( 'add-multisite-tag', 'nonce-add-multisite-tag' );

		$taxonomy = ( ! empty( sanitize_key( wp_unslash( $_POST['multisite_taxonomy'] ) ) ) ) ? sanitize_key( wp_unslash( $_POST['multisite_taxonomy'] ) ) : null;

		$tax = get_multisite_taxonomy( $taxonomy );

		if ( ! $tax instanceof Multisite_Taxonomy ) {
			$this->send_invalid_taxonomy_ajax_error( $taxonomy );
		}

		if ( ! current_user_can( $tax->cap->manage_multisite_terms ) ) {
			wp_die( -1 );
		}

		$x = new WP_Ajax_Response();

		if ( isset( $_POST['tag-name'] ) ) {
			$tag = insert_multisite_term( sanitize_text_field( wp_unslash( $_POST['tag-name'] ) ), $taxonomy, $_POST );
		}

		if ( ! $tag || is_wp_error( $tag ) ) {
			$message = esc_html__( 'An error has occurred. Please reload the page and try again.', 'multitaxo' );
			if ( is_wp_error( $tag ) && $tag->get_error_message() ) {
				$message = $tag->get_error_message();
			}

			$x->add(
				array(
					'what' => 'multisite_taxonomy',
					'data' => new WP_Error( 'error', $message ),
				)
			);
			$x->send();
		}

		$tag = get_multisite_term( $tag['multisite_term_id'], $taxonomy );

		if ( ! $tag || is_wp_error( $tag ) ) {
			$message = esc_html__( 'An error has occurred. Please reload the page and try again.', 'multitaxo' );
			if ( is_wp_error( $tag ) && $tag->get_error_message() ) {
				$message = $tag->get_error_message();
			}

			$x->add(
				array(
					'what' => 'multisite_taxonomy',
					'data' => new WP_Error( 'error', $message ),
				)
			);
			$x->send();
		}

		$args = array();

		if ( isset( $_POST['screen'] ) ) {
			$args['screen'] = convert_to_screen( sanitize_key( wp_unslash( $_POST['screen'] ) ) );
		} elseif ( isset( $GLOBALS['hook_suffix'] ) ) {
			$args['screen'] = get_current_screen();
		} else {
			$args['screen'] = null;
		}

		if ( null !== $args['screen'] ) {
			$args['screen']->taxonomy = $taxonomy;
		}

		try {
			$tax_list_table = new Multisite_Terms_List_Table( $args );
		} catch ( InvalidArgumentException $e ) {
			// The term was created above; only the response-row rendering could not resolve
			// its screen. Report it structurally rather than dumping a raw wp_die into the XML.
			$this->send_invalid_taxonomy_ajax_error( $taxonomy );
		}

		$level     = 0;
		$noparents = ''; // Only hierarchical taxonomies render a "no parents" row; keep compact() below defined.

		if ( is_multisite_taxonomy_hierarchical( $taxonomy ) ) {
			$level = count( get_ancestors( $tag->term_id, $taxonomy, 'taxonomy' ) );
			ob_start();
			$tax_list_table->single_row( $tag, $level );
			$noparents = ob_get_clean();
		}

		ob_start();
		$tax_list_table->single_row( $tag );
		$parents = ob_get_clean();

		$x->add(
			array(
				'what'         => 'taxonomy',
				'supplemental' => compact( 'parents', 'noparents' ),
			)
		);
		$x->add(
			array(
				'what'         => 'term',
				'position'     => $level,
				'supplemental' => (array) $tag,
			)
		);
		$x->send();
	}

	/**
	 * Add a Update Multisite term in database.
	 *
	 * @return void
	 */
	public function ajax_inline_save_multisite_tag() {
		check_ajax_referer( 'ajax_edit_multisite_tax', 'nonce_multisite_inline_edit' );

		if ( isset( $_POST['taxonomy'] ) ) {
			$taxonomy = sanitize_key( wp_unslash( $_POST['taxonomy'] ) );
		} else {
			$taxonomy = null;
		}

		$tax = get_multisite_taxonomy( $taxonomy );

		if ( ! $tax instanceof Multisite_Taxonomy ) {
			// Inline edit expects a bare status body, but log the reason for the developer.
			$reason = self::invalid_taxonomy_message( $taxonomy );
			if ( function_exists( 'spaces_log' ) ) {
				spaces_log( 'error', $reason, array( '_source' => __METHOD__ ) );
			} else {
				error_log( 'multisite_taxonomies: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			wp_die( 0 );
		}

		if ( ! isset( $_POST['tax_id'] ) ) {
			wp_die( -1 );
		}

		$id = absint( wp_unslash( $_POST['tax_id'] ) );

		if ( ! current_user_can( 'edit_multisite_term', $id ) ) {
			wp_die( -1 );
		}

		$args = array();

		if ( isset( $_POST['screen'] ) ) {
			$args['screen'] = convert_to_screen( sanitize_key( wp_unslash( $_POST['screen'] ) ) );
		} elseif ( isset( $GLOBALS['hook_suffix'] ) ) {
			$args['screen'] = get_current_screen();
		} else {
			$args['screen'] = null;
		}

		if ( null !== $args['screen'] ) {
			$args['screen']->taxonomy = $taxonomy;
		}

		try {
			$tax_list_table = new Multisite_Terms_List_Table( $args );
		} catch ( InvalidArgumentException $e ) {
			wp_die( 0 );
		}

		$tag                  = get_multisite_term( $id, $taxonomy );
		$_POST['description'] = $tag->description;

		$updated = update_multisite_term( $id, $taxonomy, $_POST );

		if ( $updated && ! is_wp_error( $updated ) ) {
			$tag = get_multisite_term( $updated['multisite_term_id'], $taxonomy );
			if ( ! $tag || is_wp_error( $tag ) ) {
				if ( is_wp_error( $tag ) && $tag->get_error_message() ) {
					wp_die( esc_html( $tag->get_error_message() ) );
				}
				wp_die( esc_html__( 'Item not updated.', 'multitaxo' ) );
			}
		} else {
			if ( is_wp_error( $updated ) && $updated->get_error_message() ) {
				wp_die( esc_html( $updated->get_error_message() ) );
			}
			wp_die( esc_html__( 'Item not updated.', 'multitaxo' ) );
		}
		$level  = 0;
		$parent = $tag->parent;
		while ( $parent > 0 ) {
			$parent_tag = get_multisite_term( $parent, $taxonomy );
			$parent     = $parent_tag->parent;
			++$level;
		}
		$tax_list_table->single_row( $tag, $level );
		wp_die();
	}

	/**
	 * Display the list table screen in the network.
	 *
	 * @access public
	 * @return void
	 */
	public function display_multisite_taxonomy_list() {
		?>
		<div class="wrap">
		<h1><?php echo esc_html__( 'Multisite Taxonomies', 'multitaxo' ); ?></h1>
		<ul>
		<?php

		$taxonomies = get_multisite_taxonomies( array(), 'objects' );

		if ( count( $taxonomies ) === 0 ) {
			esc_html_e( 'No Multisite Taxonomies exist.', 'multitaxo' );
		}

		foreach ( $taxonomies as $tax_slug => $tax ) {
			echo '<li><a href="' . esc_url( 'admin.php?page=multisite_term_list_' . $tax_slug ) . '">' . esc_html( $tax->label ) . '</a> <span class="description">(' . esc_html( $this->format_object_types( $tax ) ) . ')</span></li>';
		}

		echo '</ul>
		</div>';
	}

	/**
	 * Build a human-readable list of the object types (namespaces) a taxonomy targets.
	 *
	 * Any post type collapses to the single label "Posts"; "user" and "blog" map to "Users"
	 * and "Sites". Used as an at-a-glance indicator in the taxonomy list.
	 *
	 * @access private
	 * @param Multisite_Taxonomy $tax Multisite taxonomy object.
	 * @return string Comma-separated labels, e.g. "Posts, Users".
	 */
	private function format_object_types( $tax ) {
		$labels = array();

		foreach ( (array) $tax->object_type as $object_type ) {
			if ( 'user' === $object_type ) {
				$labels['user'] = __( 'Users', 'multitaxo' );
			} elseif ( 'blog' === $object_type ) {
				$labels['blog'] = __( 'Sites', 'multitaxo' );
			} else {
				// Any post type (post, page, CPT) lives in the post namespace.
				$labels['post'] = __( 'Posts', 'multitaxo' );
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * Display the objects (posts, users, sites) assigned to a single multisite term.
	 *
	 * Reached from the "Count" column of the term list table. Groups the term's relationship
	 * rows by object-type namespace and renders each namespace with object titles and edit links.
	 *
	 * @access public
	 * @return void
	 */
	public function display_multisite_term_objects() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation link, gated by capability below.
		$tax_slug = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$term_id  = isset( $_GET['multisite_term_id'] ) ? absint( wp_unslash( $_GET['multisite_term_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$tax = get_multisite_taxonomy( $tax_slug );

		if ( ! is_a( $tax, 'Multisite_Taxonomy' ) ) {
			wp_die( esc_html__( 'Invalid multisite taxonomy.', 'multitaxo' ) );
		}

		if ( ! current_user_can( $tax->cap->manage_multisite_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage multisite terms in this multisite taxonomy.', 'multitaxo' ) . '</p>',
				403
			);
		}

		$term = get_multisite_term( $term_id, $tax_slug );

		if ( ! is_a( $term, 'Multisite_Term' ) ) {
			wp_die( esc_html__( 'Invalid multisite term.', 'multitaxo' ) );
		}

		$grouped   = get_multisite_term_objects_by_type( $term_id, $tax_slug );
		$back_link = esc_url( network_admin_url( 'admin.php?page=multisite_term_list_' . $tax_slug ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php
				/* translators: 1: taxonomy label, 2: term name. */
				echo esc_html( sprintf( __( 'Objects assigned to %1$s: %2$s', 'multitaxo' ), $tax->labels->singular_name, $term->name ) );
				?>
			</h1>
			<a href="<?php echo $back_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>" class="page-title-action"><?php esc_html_e( '&larr; Back to terms', 'multitaxo' ); ?></a>
			<hr class="wp-header-end">
			<?php
			if ( empty( $grouped ) ) {
				echo '<p>' . esc_html__( 'No objects are assigned to this term.', 'multitaxo' ) . '</p>';
			} else {
				$this->render_assigned_posts( isset( $grouped['post'] ) ? $grouped['post'] : array() );
				$this->render_assigned_users( isset( $grouped['user'] ) ? $grouped['user'] : array() );
				$this->render_assigned_sites( isset( $grouped['blog'] ) ? $grouped['blog'] : array() );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render the post-namespace section of the assigned-objects screen.
	 *
	 * Posts are blog-scoped, so each row is resolved inside its own blog via switch_to_blog().
	 *
	 * @access private
	 * @param array $rows Relationship rows, each with object_id and blog_id.
	 * @return void
	 */
	private function render_assigned_posts( $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Posts', 'multitaxo' ) . '</h2>';

		// Group rows by blog so the blog name is shown once as a sub-heading instead of after every post.
		$by_blog = array();
		foreach ( $rows as $row ) {
			$by_blog[ $row->blog_id ][] = $row;
		}

		foreach ( $by_blog as $blog_id => $blog_rows ) {
			switch_to_blog( $blog_id );
			printf(
				/* translators: %s: site (blog) name. */
				'<h3>' . esc_html__( 'Site: %s', 'multitaxo' ) . '</h3>',
				'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>'
			);
			echo '<ul class="ul-disc">';
			foreach ( $blog_rows as $row ) {
				$post      = get_post( $row->object_id );
				$edit_link = get_admin_url( $blog_id, 'post.php?post=' . $row->object_id . '&action=edit' );
				if ( $post ) {
					$title     = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'multitaxo' );
					$permalink = get_permalink( $post );
					printf(
						'<li><a href="%1$s">%2$s</a> <a href="%3$s" class="edit">(%4$s)</a></li>',
						esc_url( $permalink ),
						esc_html( $title ),
						esc_url( $edit_link ),
						esc_html__( 'edit', 'multitaxo' )
					);
				} else {
					/* translators: %d: object ID. */
					printf( '<li>%s</li>', esc_html( sprintf( __( 'Missing post #%d', 'multitaxo' ), $row->object_id ) ) );
				}
			}
			echo '</ul>';
			restore_current_blog();
		}
	}

	/**
	 * Render the user-namespace section of the assigned-objects screen.
	 *
	 * @access private
	 * @param array $rows Relationship rows, each with object_id.
	 * @return void
	 */
	private function render_assigned_users( $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Users', 'multitaxo' ) . '</h2>';
		echo '<ul class="ul-disc">';
		foreach ( $rows as $row ) {
			$user = get_userdata( $row->object_id );
			if ( $user ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="description">(%3$s)</span></li>',
					esc_url( network_admin_url( 'user-edit.php?user_id=' . $row->object_id ) ),
					esc_html( $user->display_name ),
					esc_html( $user->user_email )
				);
			} else {
				/* translators: %d: object ID. */
				printf( '<li>%s</li>', esc_html( sprintf( __( 'Missing user #%d', 'multitaxo' ), $row->object_id ) ) );
			}
		}
		echo '</ul>';
	}

	/**
	 * Render the blog-namespace section of the assigned-objects screen.
	 *
	 * @access private
	 * @param array $rows Relationship rows, each with object_id.
	 * @return void
	 */
	private function render_assigned_sites( $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Sites', 'multitaxo' ) . '</h2>';
		echo '<ul class="ul-disc">';
		foreach ( $rows as $row ) {
			$blog = get_blog_details( $row->object_id );
			if ( $blog ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="description">(%3$s)</span></li>',
					esc_url( network_admin_url( 'site-info.php?id=' . $row->object_id ) ),
					esc_html( $blog->blogname ),
					esc_html( $blog->siteurl )
				);
			} else {
				/* translators: %d: object ID. */
				printf( '<li>%s</li>', esc_html( sprintf( __( 'Missing site #%d', 'multitaxo' ), $row->object_id ) ) );
			}
		}
		echo '</ul>';
	}

	/**
	 * Display the list table screen in the network.
	 *
	 * @access public
	 * @return void
	 */
	public function display_multisite_taxonomy() {
		$current_screen = get_current_screen();

		if ( empty( $current_screen->taxonomy ) ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'multitaxo' ) );
		}

		$tax = get_multisite_taxonomy( $current_screen->taxonomy );

		if ( ! $tax ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'multitaxo' ) );
		}

		if ( ! in_array( $tax->name, get_multisite_taxonomies( array( 'show_ui' => true ) ), true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit terms in this taxonomy.', 'multitaxo' ) );
		}

		if ( ! current_user_can( $tax->cap->manage_multisite_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'multitaxo' ) . '</p>',
				403
			);
		}

		$pagenum = $this->list_table->get_pagenum();
		$title   = $tax->labels->name;
		$message = $this->get_update_message();
		$class   = ( isset( $_REQUEST['error'] ) ) ? 'error' : 'updated'; // phpcs:ignore WordPress.Security.NonceVerification

		wp_enqueue_script( 'admin-multisite-tags' );
		if ( current_user_can( $tax->cap->edit_multisite_terms ) ) {
			wp_enqueue_script( 'inline-edit-multisite-tax' );
		}
		?>

		<div class="wrap nosubsub">
		<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>

		<?php
		if ( isset( $_REQUEST['s'] ) && strlen( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			/* translators: %s: search keywords */
			echo '<span class="subtitle">' . esc_html( sprintf( __( 'Search results for &#8220;%s&#8221;', 'multitaxo' ), sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) ) . '</span>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		?>

		<hr class="wp-header-end">

		<?php if ( $message ) : ?>
		<div id="message" class="<?php echo esc_attr( $class ); ?> notice is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$_SERVER['REQUEST_URI'] = remove_query_arg( array( 'message', 'error' ), sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			} else {
				$_SERVER['REQUEST_URI'] = '/';
			}
		endif;
		?>
		<div id="ajax-response"></div>

		<form class="search-form wp-clearfix" method="get">
		<input type="hidden" name="page" value="multisite_term_list_<?php echo esc_attr( $tax->name ); ?>" />
		<input type="hidden" name="multisite_taxonomy" value="<?php echo esc_attr( $tax->name ); ?>" />

		<?php $this->list_table->search_box( $tax->labels->search_items, 'tag' ); ?>

		</form>

		<div id="col-container" class="wp-clearfix">

		<div id="col-left">
		<div class="col-wrap">

		<?php
		if ( current_user_can( $tax->cap->edit_multisite_terms ) ) {
			/**
			 * Fires before the Add Term form for all taxonomies.
			 *
			 * The dynamic portion of the hook name, `$tax->name`, refers to the taxonomy slug.
			 *
			 * @since 3.0.0
			 *
			 * @param string $tax->name The taxonomy slug.
			 */
			do_action( "{$tax->name}_pre_add_form", $tax->name );
			?>

		<div class="form-wrap">
		<h2><?php echo esc_html( $tax->labels->add_new_item ); ?></h2>
		<form id="addtag" method="post" action="admin.php?page=<?php echo esc_attr( 'multisite_term_list_' . $tax->name ); ?>" class="validate"
			<?php
			/**
			 * Fires inside the Add Tag form tag.
			 *
			 * The dynamic portion of the hook name, `$tax->name`, refers to the taxonomy slug.
			 *
			 * @since 3.7.0
			 */
			do_action( "{$tax->name}_term_new_form_tag" );
			?>
		>
		<input type="hidden" name="action" value="add-multisite-tag" />
		<input type="hidden" name="page" value="multisite_term_list_<?php echo esc_attr( $tax->name ); ?>" />
		<input type="hidden" name="screen" value="<?php echo esc_attr( $current_screen->id ); ?>" />
		<input type="hidden" name="multisite_taxonomy" value="<?php echo esc_attr( $tax->name ); ?>" />
			<?php wp_nonce_field( 'add-multisite-tag', 'nonce-add-multisite-tag' ); ?>

		<div class="form-field form-required term-name-wrap">
			<label for="tag-name"><?php esc_html_x( 'Name', 'term name', 'multitaxo' ); ?></label>
			<input name="tag-name" id="tag-name" type="text" value="" size="40" aria-required="true" />
			<p><?php esc_html_e( 'The name is how it appears on your site.', 'multitaxo' ); ?></p>
		</div>
		<div class="form-field term-slug-wrap">
			<label for="tag-slug"><?php esc_html_e( 'Slug', 'multitaxo' ); ?></label>
			<input name="slug" id="tag-slug" type="text" value="" size="40" />
			<p><?php esc_html_e( 'The &#8220;slug&#8221; is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'multitaxo' ); ?></p>
		</div>
			<?php if ( is_multisite_taxonomy_hierarchical( $tax->name ) ) : ?>
		<div class="form-field term-parent-wrap">
			<label for="parent"><?php echo esc_html( $tax->labels->parent_item ); ?></label>
				<?php
				$dropdown_args = array(
					'hide_empty'       => 0,
					'hide_if_empty'    => false,
					'taxonomy'         => $tax->name,
					'name'             => 'parent',
					'orderby'          => 'name',
					'hierarchical'     => true,
					'show_option_none' => esc_html__( 'None', 'multitaxo' ),
				);
				/**
				 * Filters the taxonomy parent drop-down on the Edit Term page.
				 *
				 * @since 3.7.0
				 * @since 4.2.0 Added `$context` parameter.
				 *
				 * @param array  $dropdown_args {
				 *     An array of taxonomy parent drop-down arguments.
				 *
				 *     @type int|bool $hide_empty       Whether to hide terms not attached to any posts. Default 0|false.
				 *     @type bool     $hide_if_empty    Whether to hide the drop-down if no terms exist. Default false.
				 *     @type string   $tax->name         The taxonomy slug.
				 *     @type string   $name             Value of the name attribute to use for the drop-down select element.
				 *                                      Default 'parent'.
				 *     @type string   $orderby          The field to order by. Default 'name'.
				 *     @type bool     $hierarchical     Whether the taxonomy is hierarchical. Default true.
				 *     @type string   $show_option_none Label to display if there are no terms. Default 'None'.
				 * }
				 * @param string $tax->name The taxonomy slug.
				 * @param string $context  Filter context. Accepts 'new' or 'edit'.
				 */
				$dropdown_args = apply_filters( 'taxonomy_parent_dropdown_args', $dropdown_args, $tax->name, 'new' );
				dropdown_multisite_taxonomy( $dropdown_args );
				?>
				<?php if ( 'category' === $tax->name ) : ?>
				<p><?php esc_html_e( 'Categories, unlike tags, can have a hierarchy. You might have a Jazz category, and under that have children categories for Bebop and Big Band. Totally optional.', 'multitaxo' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'multitaxo' ); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; // End if: is multisite taxonomy hierarchical. ?>
		<div class="form-field term-description-wrap">
			<label for="tag-description"><?php esc_html_e( 'Description', 'multitaxo' ); ?></label>
			<textarea name="description" id="tag-description" rows="5" cols="40"></textarea>
			<p><?php esc_html_e( 'The description is not prominent by default; however, some themes may show it.', 'multitaxo' ); ?></p>
		</div>

			<?php
			if ( ! is_multisite_taxonomy_hierarchical( $tax->name ) ) {
				/**
				 * Fires after the Add Tag form fields for non-hierarchical taxonomies.
				 *
				 * @since 3.0.0
				 *
				 * @param string $tax->name The taxonomy slug.
				 */
				do_action( 'add_tag_form_fields', $tax->name );
			}
			/**
			 * Fires after the Add Term form fields.
			 *
			 * The dynamic portion of the hook name, `$tax->name`, refers to the taxonomy slug.
			 *
			 * @since 3.0.0
			 *
			 * @param string $tax->name The taxonomy slug.
			 */
			do_action( "{$tax->name}_add_form_fields", $tax->name );
			submit_button( $tax->labels->add_new_item );

			/**
			 * Fires at the end of the Add Term form for all taxonomies.
			 *
			 * The dynamic portion of the hook name, `$tax->name`, refers to the taxonomy slug.
			 *
			 * @since 3.0.0
			 *
			 * @param string $tax->name The taxonomy slug.
			 */
			do_action( "{$tax->name}_add_form", $tax->name );
			?>
		</form></div>
		<?php } ?>

		</div>
		</div><!-- /col-left -->

		<div id="col-right">
		<div class="col-wrap">

		<?php $this->list_table->views(); ?>

		<form id="posts-filter" method="post">
		<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $tax->name ); ?>" />

		<?php $this->list_table->display(); ?>

		</form>

		<?php
		/**
		 * Fires after the taxonomy list table.
		 *
		 * The dynamic portion of the hook name, `$tax->name`, refers to the taxonomy slug.
		 *
		 * @since 3.0.0
		 *
		 * @param string $tax->name The taxonomy name.
		 */
		do_action( "after_multisite_{$tax->name}_table", $tax->name );
		?>

		</div>
		</div><!-- /col-right -->

		</div><!-- /col-container -->
		</div><!-- /wrap -->

		<?php if ( ! wp_is_mobile() ) : ?>
		<script type="text/javascript">
		try{document.forms.addtag['tag-name'].focus();}catch(e){}
		</script>
			<?php
		endif;

		$this->list_table->inline_edit();
	}

	/**
	 * Messages for tag updates.
	 *
	 * @access public
	 * @return string Message to return.
	 */
	public function get_update_message() {
		// 0 = unused. Messages start at index 1.
		$messages = array(
			0 => '',
			1 => esc_html__( 'Multisite term added.', 'multitaxo' ),
			2 => esc_html__( 'Multisite term deleted.', 'multitaxo' ),
			3 => esc_html__( 'Multisite term updated.', 'multitaxo' ),
			4 => esc_html__( 'Multisite term not added.', 'multitaxo' ),
			5 => esc_html__( 'Multisite term not updated.', 'multitaxo' ),
			6 => esc_html__( 'Multisite term deleted.', 'multitaxo' ),
		);

		// Filters the messages displayed when a tag is updated.
		$messages = apply_filters( 'multisite_term_updated_messages', $messages );

		$message = false;
		if ( isset( $_REQUEST['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = (int) absint( wp_unslash( $_REQUEST['message'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( isset( $messages[ $msg ] ) ) {
				$message = $messages[ $msg ];
			}
		}

		return $message;
	}

	/**
	 * Display the edit screen for a tag.
	 *
	 * @access public
	 * @return void
	 */
	public function display_multisite_taxonomy_edit_screen() {
		$taxonomy = ( isset( $_GET['multisite_taxonomy'] ) ) ? sanitize_key( wp_unslash( $_GET['multisite_taxonomy'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		// Check that we have something.
		if ( empty( $taxonomy ) ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'multitaxo' ) );
		}

		$tax = get_multisite_taxonomy( $taxonomy );

		if ( ! $tax ) {
			wp_die( esc_html__( 'Invalid taxonomy.', 'multitaxo' ) );
		}

		if ( ! in_array( $tax->name, get_multisite_taxonomies( array( 'show_ui' => true ) ), true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit terms in this taxonomy.', 'multitaxo' ) );
		}

		if ( ! current_user_can( $tax->cap->manage_multisite_terms ) ) {
			wp_die(
				'<h1>' . esc_html__( 'Cheatin&#8217; uh?', 'multitaxo' ) . '</h1>' .
				'<p>' . esc_html__( 'Sorry, you are not allowed to manage terms in this taxonomy.', 'multitaxo' ) . '</p>',
				403
			);
		}

		$multisite_term_id = ( isset( $_GET['multisite_term_id'] ) ) ? sanitize_key( wp_unslash( $_GET['multisite_term_id'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		$term = get_multisite_term( $multisite_term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Invalid term.', 'multitaxo' ) );
		}

		$title   = $tax->labels->name;
		$message = $this->get_update_message();
		$class   = ( isset( $_REQUEST['error'] ) ) ? 'error' : 'updated'; // phpcs:ignore WordPress.Security.NonceVerification

		$args = array(
			'page' => 'multisite_term_list_' . $taxonomy,
		);

		$return_url = add_query_arg( $args, get_admin_url( null, 'network/admin.php' ) );

		/**
		 * Fires before the Edit Term form for all taxonomies.
		 *
		 * The dynamic portion of the hook name, `$taxonomy`, refers to
		 * the taxonomy slug.
		 *
		 * @since 3.0.0
		 *
		 * @param object $tag      Current taxonomy term object.
		 * @param string $taxonomy Current $taxonomy slug.
		 */
		do_action( "{$taxonomy}_multisite_pre_edit_form", $term, $tax );
		?>

		<div class="wrap">
		<h1><?php echo esc_html( $tax->labels->edit_item ); ?></h1>

		<?php if ( $message ) : ?>
		<div id="message" class="updated">
			<p><strong><?php echo esc_html( $message ); ?></strong></p>
			<p><a href="<?php echo esc_url( $return_url ); ?>">
			<?php
			/* translators: %s: taxonomy name */
			echo esc_html( sprintf( _x( '&larr; Back to %s', 'admin screen', 'multitaxo' ), $tax->labels->name ) );
			?>
			</a></p>
		</div>
		<?php endif; ?>

		<div id="ajax-response"></div>

		<form name="edittag" id="edittag" method="post" action="<?php echo esc_url( 'admin.php?page=multisite_term_list_' . $taxonomy ); ?>" class="validate"
		<?php
		/**
		 * Fires inside the Edit Term form tag.
		 *
		 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
		 *
		 * @since 3.7.0
		 */
		do_action( "{$taxonomy}_multisite_term_edit_form_tag" );
		?>
		>
		<input type="hidden" name="action" value="editedtag"/>
		<input type="hidden" name="page" value="multisite_term_list_<?php echo esc_attr( $taxonomy ); ?>"/>
		<input type="hidden" name="multisite_term_id" value="<?php echo esc_attr( $term->multisite_term_id ); ?>"/>
		<input type="hidden" name="multisite_taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>"/>
		<?php
		wp_original_referer_field( true, 'previous' );
		wp_nonce_field( 'update-multisite-term_' . $term->multisite_term_id );

		/**
		 * Fires at the beginning of the Edit Term form.
		 *
		 * At this point, the required hidden fields and nonces have already been output.
		 *
		 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
		 *
		 * @since 4.5.0
		 *
		 * @param object $tag      Current taxonomy term object.
		 * @param string $taxonomy Current $taxonomy slug.
		 */
		do_action( "{$taxonomy}_multisite_term_edit_form_top", $term, $tax );
		?>
			<table class="form-table">
				<tr class="form-field form-required term-name-wrap">
					<th scope="row"><label for="name"><?php echo esc_html_x( 'Name', 'term name', 'multitaxo' ); ?></label></th>
					<?php
					if ( isset( $term->name ) ) {
						$term_name = $term->name;
					} else {
						$term_name = '';
					}
					?>
					<td><input name="name" id="name" type="text" value="<?php echo esc_attr( $term_name ); ?>" size="40" aria-required="true" />
					<p class="description"><?php esc_html_e( 'The name is how it appears on your site.', 'multitaxo' ); ?></p></td>
				</tr>
				<tr class="form-field term-slug-wrap">
					<th scope="row"><label for="slug"><?php esc_html_e( 'Slug', 'multitaxo' ); ?></label></th>
					<?php
					/**
					 * Filters the editable slug.
					 *
					 * Note: This is a multi-use hook in that it is leveraged both for editable
					 * post URIs and term slugs.
					 *
					 * @since 2.6.0
					 * @since 4.4.0 The `$tag` parameter was added.
					 *
					 * @param string         $slug The editable slug. Will be either a term slug or post URI depending
					 *                             upon the context in which it is evaluated.
					 * @param object|WP_Post $tag  Term or WP_Post object.
					 */
					$slug = isset( $term->slug ) ? apply_filters( 'editable_slug', $term->slug, $term ) : '';
					?>
					<td><input name="slug" id="slug" type="text" value="<?php echo esc_attr( $slug ); ?>" size="40" />
					<p class="description"><?php esc_html_e( 'The &#8220;slug&#8221; is the URL-friendly version of the name. It is usually all lowercase and contains only letters, numbers, and hyphens.', 'multitaxo' ); ?></p></td>
				</tr>
		<?php if ( is_multisite_taxonomy_hierarchical( $taxonomy ) ) : ?>
				<tr class="form-field term-parent-wrap">
					<th scope="row"><label for="parent"><?php echo esc_html( $tax->labels->parent_item ); ?></label></th>
					<td>
						<?php
						$dropdown_args = array(
							'hide_empty'       => 0,
							'hide_if_empty'    => false,
							'taxonomy'         => $taxonomy,
							'name'             => 'parent',
							'orderby'          => 'name',
							'selected'         => $term->parent,
							'exclude_tree'     => $term->multisite_term_id,
							'hierarchical'     => true,
							'show_option_none' => esc_html__( 'None', 'multitaxo' ),
						);

						/** This filter is documented in wp-admin/edit-tags.php */
						$dropdown_args = apply_filters( 'taxonomy_parent_dropdown_args', $dropdown_args, $taxonomy, 'edit' );
						dropdown_multisite_taxonomy( $dropdown_args );
						?>
						<?php if ( 'category' === $taxonomy ) : ?>
							<p class="description"><?php esc_html_e( 'Categories, unlike tags, can have a hierarchy. You might have a Jazz category, and under that have children categories for Bebop and Big Band. Totally optional.', 'multitaxo' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Assign a parent term to create a hierarchy. The term Jazz, for example, would be the parent of Bebop and Big Band.', 'multitaxo' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
		<?php endif; // End if : is taxonomy hierarchical. ?>
				<tr class="form-field term-description-wrap">
					<th scope="row"><label for="description"><?php esc_html_e( 'Description', 'multitaxo' ); ?></label></th>
					<td><textarea name="description" id="description" rows="5" cols="50" class="large-text"><?php echo esc_textarea( $term->description ); // textarea_escaped. ?></textarea>
					<p class="description"><?php esc_html_e( 'The description is not prominent by default; however, some themes may show it.', 'multitaxo' ); ?></p></td>
				</tr>
				<?php
				/**
				 * Fires after the Edit Term form fields are displayed.
				 *
				 * The dynamic portion of the hook name, `$taxonomy`, refers to
				 * the taxonomy slug.
				 *
				 * @since 3.0.0
				 *
				 * @param object $tag      Current taxonomy term object.
				 * @param string $taxonomy Current taxonomy slug.
				 */
				do_action( "{$taxonomy}_multisite_edit_form_fields", $term, $tax );
				?>
			</table>
		<?php

		/**
		 * Fires at the end of the Edit Term form for all taxonomies.
		 *
		 * The dynamic portion of the hook name, `$taxonomy`, refers to the taxonomy slug.
		 *
		 * @since 3.0.0
		 *
		 * @param object $tag      Current taxonomy term object.
		 * @param string $taxonomy Current taxonomy slug.
		 */
		do_action( "{$taxonomy}_multisite_edit_form", $term, $taxonomy );
		?>

		<div class="edit-tag-actions">

			<?php submit_button( esc_html__( 'Update', 'multitaxo' ), 'primary', null, false ); ?>

			<?php if ( current_user_can( 'delete_multisite_term', $term->multisite_term_id ) ) : ?>
				<span id="delete-link">
					<a class="delete" href="
					<?php
					echo esc_url(
						wp_nonce_url(
							add_query_arg(
								array(
									'page'               => 'multisite_term_list_' . $taxonomy,
									'action'             => 'delete',
									'multisite_taxonomy' => $taxonomy,
									'multisite_term_id'  => $term->multisite_term_id,
								),
								'admin.php'
							),
							'delete-multisite_term_' . $term->multisite_term_id
						)
					);
					?>
					"><?php esc_html_e( 'Delete', 'multitaxo' ); ?></a>
				</span>
			<?php endif; ?>

		</div>

		</form>
		</div>

		<?php if ( ! wp_is_mobile() ) : ?>
		<script type="text/javascript">
		try{document.forms.edittag.name.focus();}catch(e){}
		</script>
			<?php
		endif;
	}

	/**
	 * Allow us to perform multisite taxonomy or multisite term related actions when the before_delete_post action hook is triggered.
	 *
	 * @param int $post_id The deleted post ID.
	 * @return void
	 */
	public function before_delete_post_action_hook( $post_id ) {
		$post_id = absint( $post_id );

		$post = get_post( $post_id, OBJECT );

		if ( is_a( $post, 'WP_Post' ) ) {
			// When a post is deleted we want tp delete the multisite term relationships to avoid orphans records.
			delete_object_multisite_term_relationships( $post_id, get_object_multisite_taxonomies( $post ), get_current_blog_id() );
		}
	}

	/**
	 * Purge a deleted site's rows from the network-global relationships table.
	 *
	 * Post relationships are keyed by `blog_id`, but deleting a whole site drops its
	 * `wp_<id>_posts` table directly without firing `before_delete_post` per post, so
	 * those rows would otherwise be orphaned and the affected term counts left inflated.
	 * User- and blog-namespace rows are network-global (blog_id 0) and unaffected.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param WP_Site $old_site The site being deleted.
	 * @return void
	 */
	public function delete_site_action_hook( $old_site ) {
		global $wpdb;

		$blog_id = is_a( $old_site, 'WP_Site' ) ? (int) $old_site->blog_id : 0;

		if ( $blog_id <= 0 ) {
			return;
		}

		// Capture the affected term/taxonomy rows before the delete so counts can be
		// recalculated afterwards, grouped by taxonomy to honour any update_count_callback.
		$affected = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT tt.multisite_term_multisite_taxonomy_id AS mtmt_id, tt.multisite_taxonomy AS taxonomy
				FROM {$wpdb->multisite_term_relationships} AS tr
				INNER JOIN {$wpdb->multisite_term_multisite_taxonomy} AS tt
					ON tt.multisite_term_multisite_taxonomy_id = tr.multisite_term_multisite_taxonomy_id
				WHERE tr.blog_id = %d",
				$blog_id
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$deleted = $wpdb->delete( $wpdb->multisite_term_relationships, array( 'blog_id' => $blog_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $deleted ) {
			$reason = sprintf( 'failed to delete term relationships for deleted blog %d: %s', $blog_id, $wpdb->last_error );
			if ( function_exists( 'spaces_log' ) ) {
				spaces_log( 'error', $reason, array( '_source' => __METHOD__ ) );
			} else {
				error_log( 'multisite_taxonomies: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}

		if ( empty( $affected ) ) {
			return;
		}

		wp_cache_delete( 'last_changed', 'multisite_terms' );

		// Group the orphaned term IDs by taxonomy, then recount each.
		$by_taxonomy = array();
		foreach ( $affected as $row ) {
			$by_taxonomy[ $row->taxonomy ][] = (int) $row->mtmt_id;
		}

		foreach ( $by_taxonomy as $taxonomy => $mtmt_ids ) {
			// A taxonomy that is not registered in this request cannot be recounted; the
			// rows are already gone, so leave the (now stale) count for a later resync.
			if ( ! multisite_taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			update_multisite_term_count( $mtmt_ids, $taxonomy );
		}
	}
}
