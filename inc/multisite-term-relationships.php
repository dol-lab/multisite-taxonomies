<?php
/**
 * Multisite Taxonomy API : Object relationships
 *
 * Which objects carry which terms, and the (blog_id, object_type) namespace a relationship row
 * lives in. The reverse direction, term to objects, is in multisite-term-objects.php.
 *
 * @package multitaxo
 */

/**
 * Retrieve object_ids of valid multisite taxonomy and multisite term.
 *
 * The strings of $multisite_taxonomies must exist before this function will continue. On
 * failure of finding a valid multisite taxonomy, it will return an WP_Error class, kind
 * of like Exceptions in PHP 5, except you can't catch them. Even so, you can
 * still test for the WP_Error class and get the error message.
 *
 * The $multisite_terms aren't checked the same as $multisite_taxonomies, but still need to exist
 * for $object_ids to be returned.
 *
 * It is possible to change the order that object_ids is returned by either
 * using PHP sort family functions or using the database by using $args with
 * either ASC or DESC array. The value should be in the key named 'order'.
 *
 * @deprecated 0.2.0 Use multisite_term_objects(), whose result keeps each object's namespace and
 *                  blog instead of flattening them into colliding IDs. Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|array    $multisite_term_ids   Multisite Term id or array of multisite term ids of multisite terms that will be used.
 * @param string|array $multisite_taxonomies String of multisite taxonomy name or Array of string values of multisite taxonomy names.
 * @param array|string $args       Change the order of the object_ids, either ASC or DESC.
 * @return WP_Error|array If the multisite taxonomy does not exist, then WP_Error will be returned. On success.
 *  the array can be empty meaning that there are no $object_ids found or it will return the $object_ids found.
 */
function get_objects_in_multisite_term( $multisite_term_ids, $multisite_taxonomies, $args = array() ) {
	global $wpdb;

	if ( ! is_array( $multisite_term_ids ) ) {
		$multisite_term_ids = array( $multisite_term_ids );
	}
	if ( ! is_array( $multisite_taxonomies ) ) {
		$multisite_taxonomies = array( $multisite_taxonomies );
	}
	foreach ( (array) $multisite_taxonomies as $multisite_taxonomy ) {
		if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'multitaxo' ) );
		}
	}

	$defaults = array(
		'order' => 'ASC',
	);
	$args     = wp_parse_args( $args, $defaults );

	$order = ( 'desc' === strtolower( $args['order'] ) ) ? 'DESC' : 'ASC';

	$multisite_term_ids = array_map( 'intval', $multisite_term_ids );

	$multisite_taxonomies = "'" . implode( "', '", array_map( 'esc_sql', $multisite_taxonomies ) ) . "'";
	$multisite_term_ids   = "'" . implode( "', '", $multisite_term_ids ) . "'";

	$object_ids = $wpdb->get_col( "SELECT tr.object_id FROM $wpdb->multisite_term_relationships AS tr INNER JOIN $wpdb->multisite_term_multisite_taxonomy AS tt ON tr.multisite_term_multisite_taxonomy_id = tt.multisite_term_multisite_taxonomy_id WHERE tt.multisite_taxonomy IN ($multisite_taxonomies) AND tt.multisite_term_id IN ($multisite_term_ids) ORDER BY tr.object_id $order" );

	if ( ! $object_ids ) {
		return array();
	}
	return $object_ids;
}

/**
 * Get the relationship rows assigned to a multisite term, grouped by object-type namespace.
 *
 * Unlike get_objects_in_multisite_term(), which only returns a flat list of object IDs, this
 * returns the full namespace context (object_type + blog_id) needed to resolve each row back
 * to the right object (a post on a given blog, a network user, or a site). Used by the
 * network-admin count drill-down.
 *
 * @deprecated 0.2.0 Use multisite_term_objects()->grouped(), which returns identities rather than
 *                  raw rows. Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int    $multisite_term_id  Multisite term ID.
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return array {
 *     Relationship rows keyed by namespace. Empty namespaces are omitted.
 *
 *     @type array $post Rows in the post namespace (object_type ''). Each entry is an object with
 *                       object_id (int) and blog_id (int).
 *     @type array $user Rows in the user namespace. Each entry has object_id (int); blog_id is 0.
 *     @type array $blog Rows in the blog namespace. Each entry has object_id (int); blog_id is 0.
 * }
 */
function get_multisite_term_objects_by_type( $multisite_term_id, $multisite_taxonomy ) {
	global $wpdb;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return array();
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT tr.object_id, tr.object_type, tr.blog_id
			FROM {$wpdb->multisite_term_relationships} AS tr
			INNER JOIN {$wpdb->multisite_term_multisite_taxonomy} AS tt
				ON tr.multisite_term_multisite_taxonomy_id = tt.multisite_term_multisite_taxonomy_id
			WHERE tt.multisite_term_id = %d AND tt.multisite_taxonomy = %s
			ORDER BY tr.object_type, tr.blog_id, tr.object_id",
			$multisite_term_id,
			$multisite_taxonomy
		)
	);

	$grouped = array();

	foreach ( (array) $rows as $row ) {
		// '' is the post namespace; map it to the 'post' key for callers.
		$namespace = '' === $row->object_type ? 'post' : $row->object_type;

		$grouped[ $namespace ][] = (object) array(
			'object_id' => (int) $row->object_id,
			'blog_id'   => (int) $row->blog_id,
		);
	}

	return $grouped;
}

/**
 * Get the object IDs assigned to a multisite term within a single object-type namespace,
 * paginated and with a total count, for rendering a front-end users/sites archive.
 *
 * User and blog relationships are network-global (blog_id 0); the post namespace is scoped to a
 * specific blog. Unlike get_objects_in_multisite_term(), this filters by object_type — so a single
 * multi-namespace taxonomy can drive separate per-namespace archives — and returns the total
 * alongside the page slice so a caller can paginate without a second full read.
 *
 * Pass an array of term IDs to span a term and its descendants (hierarchical roll-up, matching how
 * the posts archive includes child terms); object IDs are de-duplicated across the set.
 *
 * @deprecated 0.2.0 Use multisite_term_objects(), which keeps each object's namespace and blog.
 *                  Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|int[] $multisite_term_id  Multisite term ID, or an array of term IDs to union over.
 * @param string    $multisite_taxonomy Multisite taxonomy name.
 * @param string    $object_type        ID namespace: '' (posts), 'user', or 'blog'.
 * @param array     $args {
 *        Optional. Pagination / order arguments.
 *
 *     @type int    $number  Maximum number of IDs to return. 0 returns all. Default 0.
 *     @type int    $offset  Number of leading IDs to skip. Default 0.
 *     @type string $order   'ASC' or 'DESC', by object_id. Default 'ASC'.
 *     @type int    $blog_id Blog scope for the post namespace. Defaults to the current blog;
 *                           ignored for the user/blog namespaces (always network-global, blog_id 0).
 * }
 * @return array {
 *     @type int[] $ids   Object IDs for the requested page.
 *     @type int   $total Total matching object IDs across all pages.
 * }
 */
function get_multisite_term_object_ids( $multisite_term_id, $multisite_taxonomy, $object_type = '', $args = array() ) {
	global $wpdb;

	$empty = array(
		'ids'   => array(),
		'total' => 0,
	);

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return $empty;
	}

	$defaults = array(
		'number'  => 0,
		'offset'  => 0,
		'order'   => 'ASC',
		'blog_id' => 0,
	);
	$args     = wp_parse_args( $args, $defaults );

	$term_ids = array_filter( array_map( 'intval', (array) $multisite_term_id ) );
	if ( empty( $term_ids ) ) {
		return $empty;
	}
	$term_in = implode( ',', $term_ids );

	$scope = Multisite_Object_Scope::create( $object_type, (int) $args['blog_id'] );
	$order = ( 'desc' === strtolower( $args['order'] ) ) ? 'DESC' : 'ASC';

	// The WHERE values are escaped here ($term_in is a sanitized int list, the scope condition is
	// prepared on its own); table names come from $wpdb properties. DISTINCT de-duplicates objects
	// assigned under more than one term in the set.
	$from_where = $wpdb->prepare(
		"FROM {$wpdb->multisite_term_relationships} AS tr
		INNER JOIN {$wpdb->multisite_term_multisite_taxonomy} AS tt
			ON tr.multisite_term_multisite_taxonomy_id = tt.multisite_term_multisite_taxonomy_id
		WHERE tt.multisite_term_id IN ($term_in) AND tt.multisite_taxonomy = %s",
		$multisite_taxonomy
	) . ' AND ' . $scope->where( 'tr' );

	$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT tr.object_id) $from_where" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	if ( 0 === $total ) {
		return $empty;
	}

	$number = max( 0, (int) $args['number'] );
	$offset = max( 0, (int) $args['offset'] );
	$limit  = ( $number > 0 ) ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $number, $offset ) : '';

	$ids = $wpdb->get_col( "SELECT DISTINCT tr.object_id $from_where ORDER BY tr.object_id $order" . $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return array(
		'ids'   => array_map( 'intval', (array) $ids ),
		'total' => $total,
	);
}

/**
 * Will unlink the object from the multisite taxonomy or multisite taxonomies.
 *
 * Will remove all relationships between the object and any multisite terms in
 * a particular multisite taxonomy or multisite taxonomies. Does not remove the multisite term or
 * multisite taxonomy itself.
 *
 * @param int          $object_id  The multisite term Object Id that refers to the multisite term.
 * @param string|array $multisite_taxonomies List of multisite taxonomy names or single multisite taxonomy name.
 * @param int          $blog_id  The blog_id that the Object Id belongs to. Default is current blog id.
 * @param string       $object_type Optional. ID namespace of `$object_id`: '' (post, default), 'user', or 'blog'.
 */
function delete_object_multisite_term_relationships( $object_id, $multisite_taxonomies, $blog_id, $object_type = '' ) {
	$object_id = (int) $object_id;

	if ( ! is_array( $multisite_taxonomies ) ) {
		$multisite_taxonomies = array( $multisite_taxonomies );
	}

	foreach ( (array) $multisite_taxonomies as $multisite_taxonomy ) {
		$multisite_term_ids = get_object_multisite_terms(
			$object_id,
			$multisite_taxonomy,
			$blog_id,
			array(
				'fields' => 'ids',
			),
			$object_type
		);
		$multisite_term_ids = array_map( 'intval', $multisite_term_ids );
		remove_object_multisite_terms( $object_id, $multisite_term_ids, $multisite_taxonomy, $blog_id, $object_type );
	}
}

/**
 * Retrieves the multisite terms associated with the given object(s), in the supplied multisite taxonomies.
 *
 * @deprecated 0.2.0 Use Multisite_Object::terms(). Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|array    $object_ids The ID(s) of the object(s) to retrieve.
 * @param string|array $multisite_taxonomies The multisite taxonomies to retrieve multisite terms from.
 * @param int          $blog_id The blog the object IDs belong to. Defaults to the current blog. User and blog
 *                              relationships are network-global, so the argument is ignored for them.
 * @param array|string $args See Multisite_Term_Query::__construct() for supported arguments.
 * @param string       $object_type Optional. ID namespace to restrict to: '' (post, default), 'user', or 'blog'.
 *                                  For a single-namespace taxonomy it is inferred when omitted.
 * @return array|WP_Error The requested multisite term data or empty array if no multisite terms found.
 *                        WP_Error if any of the $multisite_taxonomies don't exist.
 */
function get_object_multisite_terms( $object_ids, $multisite_taxonomies, $blog_id = 0, $args = array(), $object_type = '' ) {
	global $wpdb;

	if ( empty( $object_ids ) || empty( $multisite_taxonomies ) ) {
		return array();
	}

	if ( ! is_array( $multisite_taxonomies ) ) {
		$multisite_taxonomies = array( $multisite_taxonomies );
	}

	foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
		if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
			return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
		}
	}

	// The namespace (inferred for a single-namespace taxonomy) and the blog, as one scope.
	$scope = 1 === count( $multisite_taxonomies )
		? Multisite_Object_Scope::for_taxonomy( $object_type, reset( $multisite_taxonomies ), $blog_id )
		: Multisite_Object_Scope::create( $object_type, $blog_id );

	$blog_id = $scope->blog_id();

	if ( ! is_array( $object_ids ) ) {
		$object_ids = array( $object_ids );
	}

	$object_ids = array_map( 'intval', $object_ids );

	$args = wp_parse_args( $args );

	$args['taxonomy']     = $multisite_taxonomies;
	$args['object_ids']   = $object_ids;
	$args['object_scope'] = $scope;

	$multisite_terms = get_multisite_terms( $args );

	/**
	 * Filters the multisite terms for a given object or objects.
	 *
	 * @param array $multisite_terms      An array of multisite terms for the given object or objects.
	 * @param array $object_ids Array of object IDs for which `$multisite_terms` were retrieved.
	 * @param array $multisite_taxonomies Array of multisite taxonomies from which `$multisite_terms` were retrieved.
	 * @param array $args       An array of arguments for retrieving multisite terms for the given
	 *                          object(s). See get_object_multisite_terms() for details.
	 */
	$multisite_terms = apply_filters( 'get_object_multisite_terms', $multisite_terms, $object_ids, $multisite_taxonomies, $blog_id, $args );

	$object_ids           = implode( ',', $object_ids );
	$multisite_taxonomies = "'" . implode( "', '", array_map( 'esc_sql', $multisite_taxonomies ) ) . "'";

	/**
	 * Filters the multisite terms for a given object or objects.
	 *
	 * The `$multisite_taxonomies` parameter passed to this filter is formatted as a SQL fragment. The
	 * {@see 'get_object_multisite_terms'} filter is recommended as an alternative.
	 *
	 * @param array     $multisite_terms      An array of multisite terms for the given object or objects.
	 * @param int|array $object_ids Object ID or array of IDs.
	 * @param string    $multisite_taxonomies SQL-formatted (comma-separated and quoted) list of multisite taxonomy names.
	 * @param array     $args       An array of arguments for retrieving multisite terms for the given object(s).
	 *                              See get_object_multisite_terms() for details.
	 */
	return apply_filters( 'get_object_multisite_terms', $multisite_terms, $object_ids, $multisite_taxonomies, $blog_id, $args );
}

/**
 * Normalize an object type to the value stored in the relationships `object_type` column.
 *
 * The column records the ID namespace of `object_id`, not the WP post type:
 * '' = post namespace (post, page, any CPT), 'user' = wp_users, 'blog' = wp_blogs.
 * Posts are always stored as '' (never the literal 'post'), so anything that is not
 * explicitly 'user' or 'blog' normalizes to ''.
 *
 * @param string $object_type Raw object type (e.g. '', 'post', 'page', a CPT, 'user', 'blog').
 * @return string Normalized object type: '' , 'user', or 'blog'.
 */
function normalize_multisite_object_type( $object_type ) {
	if ( 'user' === $object_type || 'blog' === $object_type ) {
		return $object_type;
	}
	return '';
}

/**
 * Resolve the object type for a relationship, inferring it from the taxonomy when possible.
 *
 * If `$object_type` is empty and the taxonomy is registered for exactly one object type that
 * is 'user' or 'blog', that value is used. This lets callers omit `$object_type` for
 * single-namespace user/blog taxonomies while post-namespace assignments stay ''.
 *
 * @param string $object_type        Raw object type, may be empty.
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return string Normalized object type: '' , 'user', or 'blog'.
 */
function resolve_multisite_object_type( $object_type, $multisite_taxonomy ) {
	$object_type = normalize_multisite_object_type( $object_type );
	if ( '' !== $object_type ) {
		return $object_type;
	}

	$tax = get_multisite_taxonomy( $multisite_taxonomy );
	if ( $tax && is_array( $tax->object_type ) && 1 === count( $tax->object_type ) ) {
		$only = normalize_multisite_object_type( reset( $tax->object_type ) );
		if ( 'user' === $only || 'blog' === $only ) {
			return $only;
		}
	}
	return '';
}

/**
 * Whether a taxonomy is registered for the namespace represented by `$object_type`.
 *
 * @param string $object_type        Normalized object type: '' , 'user', or 'blog'.
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return bool True if the taxonomy accepts this object type.
 */
function multisite_taxonomy_supports_object_type( $object_type, $multisite_taxonomy ) {
	$tax = get_multisite_taxonomy( $multisite_taxonomy );
	if ( ! $tax || ! is_array( $tax->object_type ) ) {
		return false;
	}
	foreach ( $tax->object_type as $registered ) {
		if ( normalize_multisite_object_type( $registered ) === $object_type ) {
			return true;
		}
	}
	return false;
}

/**
 * Normalize a blog ID, falling back to the current blog.
 *
 * Accepts the numeric strings that arrive from request data and treats anything
 * non-numeric or non-positive as "not supplied".
 *
 * @param mixed $blog_id Requested blog ID.
 * @return int Blog ID to use.
 */
function multisite_blog_id_or_current( $blog_id = 0 ) {
	$blog_id = is_numeric( $blog_id ) ? (int) $blog_id : 0;
	return $blog_id > 0 ? $blog_id : get_current_blog_id();
}

/**
 * Resolve the blog_id to store for a relationship row.
 *
 * User and blog relationships are network-global: their `object_id` is a user/blog ID and
 * the blog context is meaningless, so they are always stored with `blog_id = 0`. Post-namespace
 * relationships fall back to the current blog when none is supplied (legacy behavior).
 *
 * @param string $object_type Normalized object type: '' , 'user', or 'blog'.
 * @param int    $blog_id     Requested blog ID.
 * @return int Blog ID to store.
 */
function multisite_relationship_blog_id( $object_type, $blog_id = 0 ) {
	if ( 'user' === $object_type || 'blog' === $object_type ) {
		return 0;
	}
	return multisite_blog_id_or_current( $blog_id );
}

/**
 * Create multisite term and multisite taxonomy relationships.
 *
 * Relates an object (post) to a multisite term and multisite taxonomy type. Creates the
 * multisite term and multisite taxonomy relationship if it doesn't already exist. Creates a multisite term if
 * it doesn't exist (using the slug).
 *
 * A relationship means that the multisite term is grouped in or belongs to the multisite taxonomy.
 * A multisite term has no meaning until it is given context by defining which multisite taxonomy it
 * exists under.
 *
 * @deprecated 0.2.0 Use Multisite_Object::set_terms(). Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb The WordPress database abstraction object.
 *
 * @param int              $object_id The object to relate to.
 * @param array|int|string $multisite_terms     A single multisite term slug, single multisite term id, or array
 *                                              of either multisite term slugs or ids.
 *                                    Will replace all existing related multisite terms in this multisite taxonomy.
 * @param string           $multisite_taxonomy  The context in which to relate the multisite term to the object.
 * @param int              $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 * @param bool             $append    Optional. If false will delete difference of multisite terms. Default false.
 * @param string           $object_type Optional. ID namespace of `$object_id`: '' (post, default), 'user', or 'blog'.
 *                                      May be omitted for single-namespace taxonomies; it is then inferred.
 * @return array|WP_Error Multisite term multisite taxonomy IDs of the affected multisite terms.
 */
function set_object_multisite_terms( $object_id, $multisite_terms, $multisite_taxonomy, $blog_id = 0, $append = false, $object_type = '' ) {
	global $wpdb;

	$object_id = (int) $object_id;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
	}

	// The namespace and the blog the rows belong to (user/blog rows are network-global, blog 0).
	$scope       = Multisite_Object_Scope::for_taxonomy( $object_type, $multisite_taxonomy, $blog_id );
	$object_type = $scope->object_type();
	$blog_id     = $scope->blog_id();

	if ( ! is_array( $multisite_terms ) ) {
		$multisite_terms = array( $multisite_terms );
	}

	if ( ! $append ) {
		$old_mtmt_ids = get_object_multisite_terms(
			$object_id,
			$multisite_taxonomy,
			$blog_id,
			array(
				'fields'  => 'mtmt_ids',
				'orderby' => 'none',
			),
			$object_type
		);
	} else {
		$old_mtmt_ids = array();
	}

	$mtmt_ids           = array();
	$multisite_term_ids = array();
	$new_mtmt_ids       = array();

	foreach ( (array) $multisite_terms as $multisite_term ) {
		if ( ! strlen( trim( $multisite_term ) ) ) {
			continue;
		}
		$multisite_term_info = multisite_term_exists( $multisite_term, $multisite_taxonomy );
		if ( ! $multisite_term_info ) {
			// Skip if a non-existent term ID is passed.
			if ( is_int( $multisite_term ) ) {
				continue;
			}

			$multisite_term_info = insert_multisite_term( $multisite_term, $multisite_taxonomy );
		}
		if ( is_wp_error( $multisite_term_info ) ) {
			return $multisite_term_info;
		}
		$multisite_term_ids[] = $multisite_term_info['multisite_term_id'];
		$mtmt_id              = $multisite_term_info['multisite_term_multisite_taxonomy_id'];
		$mtmt_ids[]           = $mtmt_id;

		if ( $wpdb->get_var( $wpdb->prepare( "SELECT multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_term_relationships WHERE blog_id = %d AND object_id = %d AND multisite_term_multisite_taxonomy_id = %d AND object_type = %s", $blog_id, $object_id, $mtmt_id, $object_type ) ) ) {
			continue;
		}

		/**
		 * Fires immediately before an object-multisite_term relationship is added.
		 *
		 * @param int    $object_id Object ID.
		 * @param int    $mtmt_id     Multisite term multisite taxonomy ID.
		 * @param string $multisite_taxonomy Multisite taxonomy slug.
		 */
		do_action( 'add_multisite_term_relationship', $object_id, $mtmt_id, $multisite_taxonomy );
		if ( false === $wpdb->insert(
			$wpdb->multisite_term_relationships,
			array(
				'object_id'                            => $object_id,
				'multisite_term_multisite_taxonomy_id' => $mtmt_id,
				'blog_id'                              => $blog_id,
				'object_type'                          => $object_type,
			)
		) ) {
			return new WP_Error( 'db_insert_error', __( 'Could not insert multisite term relationship into the database', 'multitaxo' ), $wpdb->last_error );
		}

		/**
		 * Fires immediately after an object-multisite_term relationship is added.
		 *
		 * @param int    $object_id Object ID.
		 * @param int    $mtmt_id     Multisite term multisite taxonomy ID.
		 * @param string $multisite_taxonomy  Multisite taxonomy slug.
		 */
		do_action( 'added_multisite_term_relationship', $object_id, $mtmt_id, $multisite_taxonomy );
		$new_mtmt_ids[] = $mtmt_id;
	}

	if ( $new_mtmt_ids ) {
		update_multisite_term_count( $new_mtmt_ids, $multisite_taxonomy );
	}
	if ( ! $append ) {
		$delete_mtmt_ids = array_diff( $old_mtmt_ids, $mtmt_ids );

		if ( $delete_mtmt_ids ) {
			$in_delete_mtmt_ids        = "'" . implode( "', '", $delete_mtmt_ids ) . "'";
			$delete_multisite_term_ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.multisite_term_id FROM $wpdb->multisite_term_multisite_taxonomy AS tt WHERE tt.multisite_taxonomy = %s AND tt.multisite_term_multisite_taxonomy_id IN ($in_delete_mtmt_ids)", $multisite_taxonomy ) );
			$delete_multisite_term_ids = array_map( 'intval', $delete_multisite_term_ids );

			$remove = remove_object_multisite_terms( $object_id, $delete_multisite_term_ids, $multisite_taxonomy, $blog_id, $object_type );
			if ( is_wp_error( $remove ) ) {
				return $remove;
			}
		}
	}

	$t = get_multisite_taxonomy( $multisite_taxonomy );
	if ( ! $append && isset( $t->sort ) && $t->sort ) {
		$values               = array();
		$multisite_term_order = 0;
		$final_mtmt_ids       = get_object_multisite_terms(
			$object_id,
			$multisite_taxonomy,
			$blog_id,
			array(
				'fields' => 'mtmt_ids',
			),
			$object_type
		);
		foreach ( $mtmt_ids as $mtmt_id ) {
			if ( in_array( $mtmt_id, $final_mtmt_ids, true ) ) {
				$values[] = $wpdb->prepare( '(%d, %d, %d, %d, %s)', $blog_id, $object_id, $mtmt_id, ++$multisite_term_order, $object_type );
			}
		}
		if ( $values ) {
			if ( false === $wpdb->query( "INSERT INTO $wpdb->multisite_term_relationships (blog_id, object_id, multisite_term_multisite_taxonomy_id, multisite_term_order, object_type) VALUES " . join( ',', $values ) . ' ON DUPLICATE KEY UPDATE multisite_term_order = VALUES(multisite_term_order)' ) ) {
				return new WP_Error( 'db_insert_error', __( 'Could not insert multisite term relationship into the database', 'multitaxo' ), $wpdb->last_error );
			}
		}
	}

	wp_cache_delete( $object_id, $scope->cache_group( $multisite_taxonomy ) );
	wp_cache_delete( 'last_changed', 'multisite_terms' );

	/**
	 * Fires after an multisite object's terms have been set.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $multisite_terms      An array of object multisite terms.
	 * @param array  $mtmt_ids     An array of multisite term multisite taxonomy IDs.
	 * @param string $multisite_taxonomy   Multisite taxonomy slug.
	 * @param bool   $append     Whether to append new multisite terms to the old multisite terms.
	 * @param array  $old_mtmt_ids Old array of multisite term multisite taxonomy IDs.
	 */
	do_action( 'set_object_multisite_terms', $object_id, $multisite_terms, $mtmt_ids, $multisite_taxonomy, $append, $old_mtmt_ids );
	return $mtmt_ids;
}

/**
 * Add multisite term(s) associated with a given object.
 *
 * @deprecated 0.2.0 Use Multisite_Object::add_terms(). Runtime notices arrive in 0.3.0.
 *
 * @param int              $object_id The ID of the object to which the multisite terms will be added.
 * @param array|int|string $multisite_terms     The slug(s) or ID(s) of the multisite term(s) to add.
 * @param array|string     $multisite_taxonomy  Multisite taxonomy name.
 * @param int              $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 * @param string           $object_type Optional. ID namespace of `$object_id`: '' (post, default), 'user', or 'blog'.
 *
 * @return array|WP_Error Multisite term multisite taxonomy IDs of the affected multisite terms.
 */
function add_object_multisite_terms( $object_id, $multisite_terms, $multisite_taxonomy, $blog_id = 0, $object_type = '' ) {
	return set_object_multisite_terms( $object_id, $multisite_terms, $multisite_taxonomy, $blog_id, true, $object_type );
}

/**
 * Remove multisite term(s) associated with a given object.
 *
 * @deprecated 0.2.0 Use Multisite_Object::remove_terms(). Runtime notices arrive in 0.3.0.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int              $object_id The ID of the object from which the multisite terms will be removed.
 * @param array|int|string $multisite_terms     The slug(s) or ID(s) of the multisite term(s) to remove.
 * @param array|string     $multisite_taxonomy  Multisite taxonomy name.
 * @param int              $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 * @param string           $object_type Optional. ID namespace of `$object_id`: '' (post, default), 'user', or 'blog'.
 *
 * @return bool|WP_Error True on success, false or WP_Error on failure.
 */
function remove_object_multisite_terms( $object_id, $multisite_terms, $multisite_taxonomy, $blog_id = 0, $object_type = '' ) {
	global $wpdb;

	$object_id = (int) $object_id;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid taxonomy.', 'multitaxo' ) );
	}

	// The namespace and the blog the rows belong to (user/blog rows are network-global, blog 0).
	$scope       = Multisite_Object_Scope::for_taxonomy( $object_type, $multisite_taxonomy, $blog_id );
	$object_type = $scope->object_type();
	$blog_id     = $scope->blog_id();

	if ( ! is_array( $multisite_terms ) ) {
		$multisite_terms = array( $multisite_terms );
	}

	$mtmt_ids = array();

	foreach ( (array) $multisite_terms as $multisite_term ) {
		if ( ! strlen( trim( $multisite_term ) ) ) {
			continue;
		}

		$multisite_term_info = multisite_term_exists( $multisite_term, $multisite_taxonomy );
		if ( ! $multisite_term_info ) {
			// Skip if a non-existent multisite term ID is passed.
			if ( is_int( $multisite_term ) ) {
				continue;
			}
		}

		if ( is_wp_error( $multisite_term_info ) ) {
			return $multisite_term_info;
		}

		$mtmt_ids[] = $multisite_term_info['multisite_term_multisite_taxonomy_id'];
	}

	if ( $mtmt_ids ) {
		$in_mtmt_ids = "'" . implode( "', '", $mtmt_ids ) . "'";

		/**
		 * Fires immediately before an object-multisite_term relationship is deleted.
		 *
		 * @param int   $object_id            Object ID.
		 * @param int   $blog_id              Blog ID.
		 * @param array $mtmt_ids             An array of multisite term multisite taxonomy IDs.
		 * @param string $multisite_taxonomy  Multisite taxonomy slug.
		 */
		do_action( 'delete_multisite_term_relationships', $object_id, $blog_id, $mtmt_ids, $multisite_taxonomy );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->multisite_term_relationships WHERE object_id = %d AND blog_id = %d AND object_type = %s AND multisite_term_multisite_taxonomy_id IN ($in_mtmt_ids)", $object_id, $blog_id, $object_type ) );

		// `false` is a query error; `0` means the rows were already gone (still success).
		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_error', __( 'Could not delete multisite term relationship from the database', 'multitaxo' ), $wpdb->last_error );
		}

		wp_cache_delete( $object_id, $scope->cache_group( $multisite_taxonomy ) );
		wp_cache_delete( 'last_changed', 'multisite_terms' );

		/**
		 * Fires immediately after an object-multisite_term relationship is deleted.
		 *
		 * @param int    $object_id           Object ID.
		 * @param int   $blog_id              Blog ID.
		 * @param array  $mtmt_ids            An array of multisite term multisite taxonomy IDs.
		 * @param string $multisite_taxonomy  Multisite taxonomy slug.
		 */
		do_action( 'deleted_multisite_term_relationships', $object_id, $blog_id, $mtmt_ids, $multisite_taxonomy );

		update_multisite_term_count( $mtmt_ids, $multisite_taxonomy );

		// Reaching here means the DELETE ran without error (0 rows simply means already gone).
		return true;
	}

	return false;
}

/**
 * Determine if the given object is associated with any of the given multisite terms.
 *
 * The given multisite terms are checked against the object's multisite_term_ids, names and slugs.
 * Multisite terms given as integers will only be checked against the object's multisite_term_ids.
 * If no multisite terms are given, determines if object is associated with any multisite terms in the given multisite taxonomy.
 *
 * @deprecated 0.2.0 Use Multisite_Object::has_term(). Runtime notices arrive in 0.3.0.
 *
 * @param int              $object_id ID of the object (post ID, link ID, ...).
 * @param string           $multisite_taxonomy  Single multisite taxonomy name.
 * @param int|string|array $multisite_terms     Optional. Multisite term multisite_term_id, name, slug or array of said. Default null.
 * @param int              $blog_id The blog the object ID belongs to. Defaults to the current blog.
 * @param string           $object_type Optional. ID namespace of `$object_id`: '' (post, default), 'user' or
 *                                      'blog'. Inferred from a single-namespace taxonomy when omitted.
 *
 * @return bool|WP_Error WP_Error on input error.
 */
function is_object_in_multsite_term( $object_id, $multisite_taxonomy, $multisite_terms = null, $blog_id = 0, $object_type = '' ) {
	$object_id = (int) $object_id;

	if ( ! $object_id ) {
		return new WP_Error( 'invalid_object', __( 'Invalid object ID', 'multitaxo' ) );
	}

	$scope   = Multisite_Object_Scope::for_taxonomy( $object_type, $multisite_taxonomy, $blog_id );
	$blog_id = $scope->blog_id();

	$object_multisite_terms = get_object_multisite_term_cache( $object_id, $multisite_taxonomy, $blog_id, $object_type );
	if ( false === $object_multisite_terms ) {
		$object_multisite_terms = get_object_multisite_terms(
			$object_id,
			$multisite_taxonomy,
			$blog_id,
			array(
				'update_multisite_term_meta_cache' => false,
			),
			$object_type
		);
		if ( is_wp_error( $object_multisite_terms ) ) {
			return $object_multisite_terms;
		}

		wp_cache_set( $object_id, wp_list_pluck( $object_multisite_terms, 'multisite_term_id' ), $scope->cache_group( $multisite_taxonomy ) );
	}

	if ( is_wp_error( $object_multisite_terms ) ) {
		return $object_multisite_terms;
	}
	if ( empty( $object_multisite_terms ) ) {
		return false;
	}
	if ( empty( $multisite_terms ) ) {
		return ( ! empty( $object_multisite_terms ) );
	}

	$multisite_terms = (array) $multisite_terms;

	$ints = array_filter( $multisite_terms, 'is_int' );
	if ( $ints ) {
		$strs = array_diff( $multisite_terms, $ints );
	} else {
		$strs =& $multisite_terms;
	}

	foreach ( $object_multisite_terms as $object_multisite_term ) {
		// If multisite term is an int, check against multisite_term_ids only.
		if ( $ints && in_array( $object_multisite_term->multisite_term_id, $ints, true ) ) {
			return true;
		}

		if ( $strs ) {
			// Only check numeric strings against multisite_term_id, to avoid false matches due to type juggling.
			$numeric_strs = array_map( 'intval', array_filter( $strs, 'is_numeric' ) );
			if ( in_array( $object_multisite_term->multisite_term_id, $numeric_strs, true ) ) {
				return true;
			}

			if ( in_array( $object_multisite_term->name, $strs, true ) ) {
				return true;
			}
			if ( in_array( $object_multisite_term->slug, $strs, true ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Determine if the given object type is associated with the given multisite taxonomy.
 *
 * @param string $object_type Object type string.
 * @param string $multisite_taxonomy Single multisite taxonomy name.
 * @return bool True if object is associated with the multisite taxonomy, otherwise false.
 */
function is_object_in_multisite_taxonomy( $object_type, $multisite_taxonomy ) {
	$multisite_taxonomies = get_object_multisite_taxonomies( $object_type );
	if ( empty( $multisite_taxonomies ) ) {
		return false;
	}
	return in_array( $multisite_taxonomy, $multisite_taxonomies, true );
}

/**
 * Get comma-separated list of multisite terms available to edit for the given post ID.
 *
 * @param int    $post_id The post ID.
 * @param string $multisite_taxonomy Optional. The taxonomy for which to retrieve terms. Default 'post_tag'.
 * @param int    $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 * @param string $object_type Optional. ID namespace to restrict to: '' (post, default), 'user', or 'blog'.
 *                            For a single-namespace taxonomy it is inferred when omitted.
 *
 * @return string|bool|WP_Error
 */
function get_multisite_terms_to_edit( $post_id, $multisite_taxonomy, $blog_id = 0, $object_type = '' ) {
	$post_id = (int) $post_id;
	if ( ! $post_id ) {
		return false;
	}

	$scope   = Multisite_Object_Scope::for_taxonomy( $object_type, $multisite_taxonomy, $blog_id );
	$blog_id = $scope->blog_id();

	$multisite_terms = get_object_multisite_term_cache( $post_id, $multisite_taxonomy, $blog_id, $object_type );
	if ( false === $multisite_terms ) {
		$multisite_terms = get_object_multisite_terms( $post_id, $multisite_taxonomy, $blog_id, array(), $object_type );
		wp_cache_add( $post_id, wp_list_pluck( $multisite_terms, 'multisite_term_id' ), $scope->cache_group( $multisite_taxonomy ) );
	}

	if ( ! $multisite_terms ) {
		return false;
	}
	if ( is_wp_error( $multisite_terms ) ) {
		return $multisite_terms;
	}
	$multisite_term_names = array();
	foreach ( $multisite_terms as $multisite_term ) {
		$multisite_term_names[] = $multisite_term->name;
	}

	$multisite_terms_to_edit = esc_attr( join( ',', $multisite_term_names ) );

	/**
	 * Filters the comma-separated list of multisite terms available to edit.
	 *
	 * @see get_multisite_terms_to_edit()
	 *
	 * @param array  $multisite_terms_to_edit An array of multisite terms.
	 * @param string $multisite_taxonomy     The multisite taxonomy for which to retrieve multisite terms.
	 */
	$multisite_terms_to_edit = apply_filters( 'multisite_terms_to_edit', $multisite_terms_to_edit, $multisite_taxonomy, $blog_id );

	return $multisite_terms_to_edit;
}

/**
 * Set the terms for a post.
 *
 * @since 2.8.0
 *
 * @see wp_set_object_terms()
 *
 * @param int          $post_id  Optional. The Post ID. Does not default to the ID of the global $post.
 * @param string|array $tags     Optional. An array of terms to set for the post, or a string of terms
 *                               separated by commas. Default empty.
 * @param string       $taxonomy Optional. Taxonomy name. Default 'post_tag'.
 * @param integer      $blog_id  Blog ID to be used on the blog.
 * @param bool         $append   Optional. If true, don't delete existing terms, just add on. If false,
 *                               replace the terms with the new terms. Default false.
 * @return array|false|WP_Error Array of term taxonomy IDs of affected terms. WP_Error or false on failure.
 */
function set_post_multisite_terms( $post_id = 0, $tags = '', $taxonomy = 'post_tag', $blog_id = 0, $append = false ) {
	$post_id = (int) $post_id;

	if ( ! $post_id ) {
		return false;
	}

	$blog_id = multisite_blog_id_or_current( $blog_id );

	if ( empty( $tags ) ) {
		$tags = array();
	}

	if ( ! is_array( $tags ) ) {
		$comma = _x( ',', 'tag delimiter', 'multitaxo' );

		if ( ',' !== $comma ) {
			$tags = str_replace( $comma, ',', $tags );
		}

		$tags = explode( ',', trim( $tags, " \n\t\r\0\x0B," ) );
	}

	/*
	 * Hierarchical taxonomies must always pass IDs rather than names so that
	 * children with the same names but different parents aren't confused.
	 */
	if ( is_multisite_taxonomy_hierarchical( $taxonomy ) ) {
		$tags = array_unique( array_map( 'intval', $tags ) );
	}

	return set_object_multisite_terms( $post_id, $tags, $taxonomy, $blog_id, $append );
}
