<?php
/**
 * Front-end archive controller for multisite taxonomy terms.
 *
 * The rewrite/permastruct plumbing (Multisite_Taxonomy::add_rewrite_rules()) already
 * registers a per-taxonomy permastruct (`multitaxo/<slug>/%name%`) and a query var, and
 * get_multisite_term_link() builds matching URLs — but nothing consumed the matched query
 * vars to actually render an archive, so the route dead-ended. This controller turns a
 * permastruct hit into a normal WordPress posts archive for the current blog.
 *
 * Two archive flavours share one route:
 *  - Posts (object_type ''): a per-blog posts archive that reuses the main loop, theme markup,
 *    and pagination — `posts_clauses` scopes the main query to the term on the current blog.
 *  - Users / sites (object_type 'user' | 'blog'): there is no native posts loop for these, so the
 *    main posts query is neutralized and the controller loads the network-global WP_User / WP_Site
 *    objects itself (paginated), exposing them to the template via template tags. A bundled template
 *    renders them on any theme; a theme may override it (see filter_template_include()).
 *
 * The object type rides on the `multisite_object_type` query var, so the clean URL stays a posts
 * archive for backwards compatibility. Multisite_WP_Query is a cross-blog aggregator and is
 * intentionally NOT used here.
 *
 * @package multitaxo
 */

/**
 * Class Multisite_Taxonomy_Archive
 */
class Multisite_Taxonomy_Archive {

	/**
	 * The term whose archive is being rendered, or null when the current request is not a
	 * multisite taxonomy archive.
	 *
	 * @var Multisite_Term|null
	 */
	private $queried_term = null;

	/**
	 * Taxonomy slug of the queried archive.
	 *
	 * @var string
	 */
	private $queried_taxonomy = '';

	/**
	 * ID namespace of the archive: '' (posts, default), 'user', or 'blog'.
	 *
	 * @var string
	 */
	private $object_type = '';

	/**
	 * Objects (WP_User or WP_Site) on the current page of a user/blog archive. Empty for posts archives.
	 *
	 * @var array
	 */
	private $objects = array();

	/**
	 * Total objects assigned to the term across all pages of a user/blog archive.
	 *
	 * @var int
	 */
	private $total_objects = 0;

	/**
	 * Objects per page on a user/blog archive.
	 *
	 * @var int
	 */
	private $per_page = 0;

	/**
	 * Current page number of a user/blog archive (1-based).
	 *
	 * @var int
	 */
	private $paged = 1;

	/**
	 * Total number of pages on a user/blog archive.
	 *
	 * @var int
	 */
	private $max_num_pages = 0;

	/**
	 * Register the front-end hooks.
	 */
	public function __construct() {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'pre_get_posts', array( $this, 'maybe_setup_archive' ) );
		add_filter( 'posts_clauses', array( $this, 'filter_posts_clauses' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'filter_the_posts' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'prevent_object_archive_404' ), 10, 2 );
		add_filter( 'template_include', array( $this, 'filter_template_include' ) );
		add_filter( 'get_the_archive_title', array( $this, 'filter_archive_title' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ) );
	}

	/**
	 * Register the generic archive query vars. The per-taxonomy query var is already
	 * registered by Multisite_Taxonomy::add_rewrite_rules(); this only adds the namespace
	 * selector used to carry object_type through the same URL.
	 *
	 * @param array $query_vars Registered public query vars.
	 * @return array
	 */
	public function register_query_vars( $query_vars ) {
		$query_vars[] = 'multisite_object_type';
		return $query_vars;
	}

	/**
	 * Detect a publicly queryable multisite taxonomy's query var on the main front-end
	 * query and, when present, turn the query into that term's archive.
	 *
	 * @param WP_Query $query The query being parsed.
	 */
	public function maybe_setup_archive( $query ) {
		// Reset per-request state so a previous archive does not leak into secondary queries.
		if ( $query->is_main_query() ) {
			$this->queried_term     = null;
			$this->queried_taxonomy = '';
			$this->object_type      = '';
			$this->objects          = array();
			$this->total_objects    = 0;
			$this->per_page         = 0;
			$this->paged            = 1;
			$this->max_num_pages    = 0;
		}

		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Leave the site root untouched. Only a matched permastruct with a non-empty request
		// path belongs to this archive controller.
		if ( empty( $GLOBALS['wp']->request ) ) {
			return;
		}

		$taxonomy = '';
		$slug     = '';
		foreach ( get_multisite_taxonomies( array( 'publicly_queryable' => true ), 'objects' ) as $tax ) {
			if ( false === $tax->query_var ) {
				continue;
			}
			$value = $query->get( $tax->query_var );
			if ( '' !== $value && null !== $value ) {
				$taxonomy = $tax->name;
				$slug     = $value;
				break;
			}
		}

		if ( '' === $taxonomy ) {
			return;
		}

		/**
		 * Filters whether the multisite taxonomy archive controller should handle this request.
		 *
		 * @param bool   $enabled  Whether to render the archive. Default true.
		 * @param string $taxonomy Taxonomy slug detected on the request.
		 * @param string $slug     Requested term slug.
		 */
		if ( ! apply_filters( 'multisite_taxonomy_archive_enabled', true, $taxonomy, $slug ) ) {
			return;
		}

		$object_type = normalize_multisite_object_type( (string) $query->get( 'multisite_object_type' ) );
		if ( '' !== $object_type && ! multisite_taxonomy_supports_object_type( $object_type, $taxonomy ) ) {
			wp_die(
				sprintf(
					/* translators: 1: object archive label ("user" or "site"), 2: taxonomy label. */
					esc_html__( 'The requested %1$s archive is not registered for the "%2$s" taxonomy.', 'multitaxo' ),
					esc_html( $this->get_archive_object_label( $object_type ) ),
					esc_html( $this->get_archive_taxonomy_label( $taxonomy ) )
				),
				esc_html__( 'Multisite Taxonomies', 'multitaxo' ),
				array(
					'response' => 404,
				)
			);
		}

		// User/site archives expose network-global objects and are restricted to super admins.
		if ( '' !== $object_type && ! is_super_admin() ) {
			wp_die(
				esc_html__( 'User and blog lists are currently only accessible by super-admins.', 'multitaxo' ),
				esc_html__( 'Multisite Taxonomies', 'multitaxo' ),
				array(
					'response' => 403,
				)
			);
		}

		$term = get_multisite_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			$query->set_404();
			status_header( 404 );
			return;
		}

		$this->queried_term     = $term;
		$this->queried_taxonomy = $taxonomy;
		$this->object_type      = $object_type;

		// Present the request as an archive rather than the (defaulted) home query.
		$query->is_home       = false;
		$query->is_front_page = false;
		$query->is_archive    = true;
		$query->is_404        = false;

		// User/blog archives have no posts loop: load the objects and neutralize the main query.
		if ( '' !== $this->object_type ) {
			$this->setup_object_archive( $query );
		}
	}

	/**
	 * Load the paginated user/site objects for the queried term and neutralize the posts loop.
	 *
	 * Users and sites are network-global, so the term's relationship rows live at blog_id 0. The
	 * IDs are paginated in SQL, then hydrated into WP_User / WP_Site objects for the current page.
	 * The main posts query is short-circuited to return nothing (a real posts query against
	 * user/site IDs would match unrelated posts), and its found_posts/max_num_pages are corrected
	 * in filter_the_posts() so the theme's native pagination tags work against the object total.
	 *
	 * @param WP_Query $query The main query being parsed.
	 */
	private function setup_object_archive( $query ) {
		$paged = max( 1, (int) $query->get( 'paged' ) );

		/**
		 * Filters the number of objects shown per page on a users/sites term archive.
		 *
		 * @param int    $per_page    Objects per page. Defaults to the "Blog pages show at most" setting.
		 * @param string $object_type Namespace being rendered: 'user' or 'blog'.
		 * @param string $taxonomy    Taxonomy slug of the archive.
		 */
		$per_page = (int) apply_filters( 'multisite_taxonomy_archive_objects_per_page', (int) get_option( 'posts_per_page', 10 ), $this->object_type, $this->queried_taxonomy );
		if ( $per_page < 1 ) {
			$per_page = 10;
		}

		$result = get_multisite_term_object_ids(
			$this->get_archive_term_ids(),
			$this->queried_taxonomy,
			$this->object_type,
			array(
				'number' => $per_page,
				'offset' => ( $paged - 1 ) * $per_page,
				'order'  => 'ASC',
			)
		);

		$this->paged         = $paged;
		$this->per_page      = $per_page;
		$this->total_objects = (int) $result['total'];
		$this->max_num_pages = ( $per_page > 0 ) ? (int) ceil( $this->total_objects / $per_page ) : 0;

		// A page request past the last page of a non-empty archive is a 404, as it would be for posts.
		if ( $this->total_objects > 0 && $paged > $this->max_num_pages ) {
			$query->set_404();
			status_header( 404 );
			$this->queried_term = null;
			return;
		}

		$this->objects = $this->hydrate_objects( $result['ids'] );

		// Render objects, not posts: stop the main query touching the posts table.
		$query->set( 'post__in', array( 0 ) );
		$query->set( 'posts_per_page', $per_page );
		$query->set( 'ignore_sticky_posts', true );
		$query->set( 'no_found_rows', true );
	}

	/**
	 * The term IDs whose objects the archive lists: the queried term plus, on a hierarchical
	 * taxonomy, its descendants. This mirrors the posts archive, where Multisite_Taxonomy_Query
	 * defaults include_children to true, so a parent term's archive rolls up its whole subtree.
	 *
	 * @return int[]
	 */
	private function get_archive_term_ids() {
		$term_id  = (int) $this->queried_term->multisite_term_id;
		$term_ids = array( $term_id );

		if ( is_multisite_taxonomy_hierarchical( $this->queried_taxonomy ) ) {
			$children = get_multisite_term_children( $term_id, $this->queried_taxonomy );
			if ( is_array( $children ) ) {
				$term_ids = array_merge( $term_ids, array_map( 'intval', $children ) );
			}
		}

		/**
		 * Filters the set of term IDs a users/sites archive draws objects from.
		 *
		 * Defaults to the queried term and its descendants (hierarchical roll-up). Return just the
		 * queried term ID to restrict the archive to direct assignments.
		 *
		 * @param int[]          $term_ids    Term IDs to include.
		 * @param Multisite_Term $queried_term The queried term.
		 * @param string         $object_type Namespace: 'user' or 'blog'.
		 */
		$term_ids = apply_filters( 'multisite_taxonomy_archive_term_ids', $term_ids, $this->queried_term, $this->object_type );

		return array_values( array_unique( array_map( 'intval', (array) $term_ids ) ) );
	}

	/**
	 * Turn a page of object IDs into WP_User / WP_Site objects, dropping any that no longer exist.
	 *
	 * @param int[] $ids Object IDs for the current page.
	 * @return array WP_User[] or WP_Site[].
	 */
	private function hydrate_objects( $ids ) {
		$objects = array();
		foreach ( $ids as $id ) {
			if ( 'user' === $this->object_type ) {
				$object = get_userdata( $id );
			} elseif ( 'blog' === $this->object_type ) {
				$object = get_site( $id );
			} else {
				$object = null;
			}
			if ( $object ) {
				$objects[] = $object;
			}
		}
		return $objects;
	}

	/**
	 * Human label for an object archive namespace.
	 *
	 * @param string $object_type Namespace: 'user' or 'blog'.
	 * @return string
	 */
	private function get_archive_object_label( $object_type ) {
		if ( 'user' === $object_type ) {
			return __( 'user', 'multitaxo' );
		}

		if ( 'blog' === $object_type ) {
			return __( 'site', 'multitaxo' );
		}

		return __( 'post', 'multitaxo' );
	}

	/**
	 * Best-effort label for an archive taxonomy.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string
	 */
	private function get_archive_taxonomy_label( $taxonomy ) {
		$tax = get_multisite_taxonomy( $taxonomy );

		if ( $tax && ! empty( $tax->labels->singular_name ) ) {
			return $tax->labels->singular_name;
		}

		if ( $tax && ! empty( $tax->label ) ) {
			return $tax->label;
		}

		return $taxonomy;
	}

	/**
	 * Inject the term relationship join/where for the controller's own archive query, scoped
	 * to the requested ID namespace and this blog.
	 *
	 * @param array    $clauses The list of clauses for the query.
	 * @param WP_Query $query   The current query.
	 * @return array
	 */
	public function filter_posts_clauses( $clauses, $query ) {
		global $wpdb;

		// Only the posts namespace runs a real posts query; user/blog archives are handled in
		// setup_object_archive()/filter_the_posts() and must not have term clauses bolted on.
		if ( null === $this->queried_term || '' !== $this->object_type || ! $query->is_main_query() || is_admin() ) {
			return $clauses;
		}

		// Multisite_Taxonomy_Query uses its own clause keys (multisite_taxonomy / multisite_terms),
		// not WP core's taxonomy / terms — the wrong keys silently sanitize to an empty clause
		// (0 = 1) and the archive shows nothing.
		$tax_query = array(
			array(
				'multisite_taxonomy' => $this->queried_taxonomy,
				'field'              => 'slug',
				'multisite_terms'    => $this->queried_term->slug,
				'operator'           => 'IN',
			),
		);

		$sql = get_multisite_tax_sql( $tax_query, $wpdb->posts, 'ID' );

		// An empty join means the term matched nothing: get_multisite_tax_sql() returned its
		// "no results" shape (empty join + `0 = 1`). The namespace scoping below references the
		// relationships table by name, so without a join it would be an unknown-column error.
		// Fail closed instead of emitting broken SQL.
		if ( empty( $sql['join'] ) ) {
			$clauses['where'] .= ' AND 1=0 ';
			return $clauses;
		}

		$clauses['join'] .= $sql['join'];
		if ( ! empty( $sql['where'] ) ) {
			$clauses['where'] .= $sql['where'];
		}

		// A single tax_query clause aliases the relationship table to its full name. Scope the
		// match to the requested namespace: posts ('') live on this blog, user/blog rows are
		// network-global (blog_id 0).
		$scope             = Multisite_Object_Scope::create( $this->object_type, get_current_blog_id() );
		$clauses['where'] .= ' AND ' . $scope->where( $wpdb->multisite_term_relationships );

		return $clauses;
	}

	/**
	 * Keep a user/blog archive from 404-ing on its (intentionally) empty posts result.
	 *
	 * The posts query is neutralized to return nothing, so WP::handle_404() — which runs after the
	 * query and would otherwise override the is_404 = false set in maybe_setup_archive() — sees zero
	 * posts and flags a 404. We render objects, not posts, so short-circuit that handling and force
	 * a 200. An over-the-last-page request is handled earlier (queried_term is cleared there), so it
	 * still 404s correctly.
	 *
	 * @param bool     $preempt Whether to short-circuit default 404 handling.
	 * @param WP_Query $query   The query being checked.
	 * @return bool
	 */
	public function prevent_object_archive_404( $preempt, $query ) {
		if ( null !== $this->queried_term && '' !== $this->object_type && $query->is_main_query() ) {
			$query->is_404 = false;
			status_header( 200 );
			return true;
		}
		return $preempt;
	}

	/**
	 * Correct the main query's totals on a user/blog archive so the theme's native pagination
	 * tags (the_posts_pagination(), paginate_links() against $wp_query) reflect the object count.
	 *
	 * The posts query was neutralized to return nothing, so without this its max_num_pages would
	 * be 0 and pagination would never render. Runs after the (empty) posts are fetched.
	 *
	 * @param array    $posts The posts (empty for a user/blog archive).
	 * @param WP_Query $query The current query.
	 * @return array Unmodified posts.
	 */
	public function filter_the_posts( $posts, $query ) {
		if ( null === $this->queried_term || '' === $this->object_type || ! $query->is_main_query() || is_admin() ) {
			return $posts;
		}

		$query->found_posts   = $this->total_objects;
		$query->max_num_pages = $this->max_num_pages;

		return $posts;
	}

	/**
	 * Pick the template for the archive.
	 *
	 * Posts archives fall through to the theme's normal archive hierarchy when no dedicated
	 * template exists. User/blog archives have no posts loop, so a theme template that just runs
	 * The Loop would render nothing; for them we fall back to the plugin's bundled object template
	 * (which uses the template tags below) when the theme provides no override.
	 *
	 * Lookup order (most specific first):
	 *   user/blog: multisite-taxonomy-{type}-{tax}.php, multisite-taxonomy-{type}.php,
	 *   then (all): multisite-taxonomy-{tax}.php, multisite-taxonomy.php.
	 *
	 * @param string $template The path of the template to include.
	 * @return string
	 */
	public function filter_template_include( $template ) {
		if ( null === $this->queried_term ) {
			return $template;
		}

		$candidates = array();
		if ( '' !== $this->object_type ) {
			$candidates[] = 'multisite-taxonomy-' . $this->object_type . '-' . $this->queried_taxonomy . '.php';
			$candidates[] = 'multisite-taxonomy-' . $this->object_type . '.php';
		}
		$candidates[] = 'multisite-taxonomy-' . $this->queried_taxonomy . '.php';
		$candidates[] = 'multisite-taxonomy.php';

		$found = locate_template( $candidates );
		if ( $found ) {
			return $found;
		}

		// No theme template: posts archives keep the theme's default archive; user/blog archives
		// need our object-aware fallback so the route renders on any theme.
		if ( '' !== $this->object_type ) {
			return MULTITAXO_PLUGIN_DIR . 'templates/multisite-taxonomy-objects.php';
		}

		return $template;
	}

	/**
	 * Use the term name as the archive title.
	 *
	 * @param string $title Archive title.
	 * @return string
	 */
	public function filter_archive_title( $title ) {
		if ( null === $this->queried_term ) {
			return $title;
		}
		return $this->queried_term->name;
	}

	/**
	 * Use the term name (plus taxonomy label) in the document <title>.
	 *
	 * @param array $parts The document title parts.
	 * @return array
	 */
	public function filter_document_title_parts( $parts ) {
		if ( null === $this->queried_term ) {
			return $parts;
		}
		$parts['title'] = $this->queried_term->name;
		$tax            = get_multisite_taxonomy( $this->queried_taxonomy );
		if ( $tax && ! empty( $tax->label ) ) {
			$parts['title'] .= ' &ndash; ' . $tax->label;
		}
		return $parts;
	}

	/**
	 * Whether the current request is a multisite taxonomy archive handled by this controller.
	 *
	 * @return bool
	 */
	public function is_archive() {
		return null !== $this->queried_term;
	}

	/**
	 * The term whose archive is currently being rendered.
	 *
	 * @return Multisite_Term|null
	 */
	public function get_queried_term() {
		return $this->queried_term;
	}

	/**
	 * ID namespace of the current archive: '' (posts), 'user', or 'blog'.
	 *
	 * @return string
	 */
	public function get_object_type() {
		return $this->object_type;
	}

	/**
	 * The WP_User / WP_Site objects on the current page of a user/blog archive.
	 *
	 * @return array WP_User[] or WP_Site[]; empty on a posts archive or outside an archive.
	 */
	public function get_objects() {
		return $this->objects;
	}

	/**
	 * Total objects assigned to the queried term across all pages of a user/blog archive.
	 *
	 * @return int
	 */
	public function get_total_objects() {
		return $this->total_objects;
	}

	/**
	 * Total number of pages on a user/blog archive.
	 *
	 * @return int
	 */
	public function get_max_num_pages() {
		return $this->max_num_pages;
	}

	/**
	 * Current page number (1-based) on a user/blog archive.
	 *
	 * @return int
	 */
	public function get_paged() {
		return $this->paged;
	}
}
