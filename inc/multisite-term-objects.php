<?php
/**
 * The reverse direction: which objects carry a term.
 *
 * @package multitaxo
 */

/**
 * The objects carrying a multisite term, across every namespace it is used in.
 *
 * The counterpart to {@see Multisite_Object::terms()}. For a taxonomy registered for more than one
 * namespace the answer is genuinely mixed (posts on several blogs, users, sites), so it comes back
 * as a {@see Multisite_Object_Set} rather than a list of IDs, which could not tell them apart.
 *
 * Pass an array of term IDs to union a term with its descendants, matching how the posts archive
 * rolls up child terms; objects are de-duplicated across the set.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|int[] $multisite_term_id  Multisite term ID, or an array of term IDs to union over.
 * @param string    $multisite_taxonomy Multisite taxonomy name.
 * @param array     $args {
 *     Optional. Scope, order and pagination.
 *
 *     @type Multisite_Object_Scope $scope  Namespace and blog to restrict to. Default: every
 *                                          namespace on every blog, which is the point of the
 *                                          reverse direction.
 *     @type int                    $number Maximum number of objects to return. 0 returns all.
 *     @type int                    $offset Number of leading objects to skip. Default 0.
 *     @type string                 $order  'ASC' or 'DESC'. Default 'ASC'.
 * }
 * @return Multisite_Object_Set The objects carrying the term. Empty when the taxonomy is unknown.
 */
function multisite_term_objects( $multisite_term_id, $multisite_taxonomy, $args = array() ) {
	global $wpdb;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return Multisite_Object_Set::empty_set();
	}

	$args = wp_parse_args(
		$args,
		array(
			'scope'  => null,
			'number' => 0,
			'offset' => 0,
			'order'  => 'ASC',
		)
	);

	$term_ids = array_filter( array_map( 'intval', (array) $multisite_term_id ) );
	if ( empty( $term_ids ) ) {
		return Multisite_Object_Set::empty_set();
	}

	$scope = ( $args['scope'] instanceof Multisite_Object_Scope ) ? $args['scope'] : Multisite_Object_Scope::any();
	$order = ( 'desc' === strtolower( $args['order'] ) ) ? 'DESC' : 'ASC';

	$where = $wpdb->prepare(
		'tt.multisite_term_id IN (' . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ') AND tt.multisite_taxonomy = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the placeholder list is built from a count, not from input.
		array_merge( $term_ids, array( $multisite_taxonomy ) )
	);

	$scope_where = $scope->where( 'tr' );
	if ( '' !== $scope_where ) {
		$where .= ' AND ' . $scope_where;
	}

	$number = max( 0, (int) $args['number'] );
	$offset = max( 0, (int) $args['offset'] );
	$limit  = ( $number > 0 ) ? $wpdb->prepare( ' LIMIT %d OFFSET %d', $number, $offset ) : '';

	// Values are prepared above; table names come from $wpdb properties. GROUP BY collapses an
	// object assigned under more than one term of the union into a single row.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT tr.object_id, tr.blog_id, tr.object_type
		FROM {$wpdb->multisite_term_relationships} AS tr
		INNER JOIN {$wpdb->multisite_term_multisite_taxonomy} AS tt
			ON tr.multisite_term_multisite_taxonomy_id = tt.multisite_term_multisite_taxonomy_id
		WHERE $where
		GROUP BY tr.object_type, tr.blog_id, tr.object_id
		ORDER BY tr.object_type $order, tr.blog_id $order, tr.object_id $order" . $limit
	);
	// phpcs:enable

	return Multisite_Object_Set::from_rows( (array) $rows );
}

/**
 * How many objects carry each term, split by namespace.
 *
 * The `count` column on a term is a single total across every namespace and blog, which is what
 * `hide_empty` needs but not what a mixed taxonomy wants to display. This answers the per-namespace
 * question for a whole list of terms in one query, so a term list does not turn into an n+1.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|int[]                   $multisite_term_ids Term ID or IDs.
 * @param string                      $multisite_taxonomy Multisite taxonomy name.
 * @param Multisite_Object_Scope|null $scope              Optional. Restrict the count to a namespace
 *                                                        and/or blog. Default: everything.
 * @return array Term ID => array( namespace => int ). Namespaces with no objects are omitted, and
 *               so are terms with none at all.
 */
function multisite_term_object_counts( $multisite_term_ids, $multisite_taxonomy, $scope = null ) {
	global $wpdb;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return array();
	}

	$term_ids = array_filter( array_map( 'intval', (array) $multisite_term_ids ) );
	if ( empty( $term_ids ) ) {
		return array();
	}

	$where = $wpdb->prepare(
		'tt.multisite_term_id IN (' . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ') AND tt.multisite_taxonomy = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see above.
		array_merge( $term_ids, array( $multisite_taxonomy ) )
	);

	if ( $scope instanceof Multisite_Object_Scope ) {
		$scope_where = $scope->where( 'tr' );
		if ( '' !== $scope_where ) {
			$where .= ' AND ' . $scope_where;
		}
	}

	// Values are prepared above; table names come from $wpdb properties. The DISTINCT pair is the
	// object identity, so the same post ID on two blogs counts twice and one object assigned under
	// two terms of the same taxonomy counts once per term.
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT tt.multisite_term_id AS term_id, tr.object_type, COUNT( DISTINCT tr.blog_id, tr.object_id ) AS total
		FROM {$wpdb->multisite_term_relationships} AS tr
		INNER JOIN {$wpdb->multisite_term_multisite_taxonomy} AS tt
			ON tr.multisite_term_multisite_taxonomy_id = tt.multisite_term_multisite_taxonomy_id
		WHERE $where
		GROUP BY tt.multisite_term_id, tr.object_type"
	);
	// phpcs:enable

	$counts = array();
	foreach ( (array) $rows as $row ) {
		$counts[ (int) $row->term_id ][ normalize_multisite_object_type( $row->object_type ) ] = (int) $row->total;
	}

	return $counts;
}

/**
 * How many objects carry one term in one namespace.
 *
 * @param int                         $multisite_term_id  Term ID.
 * @param string                      $multisite_taxonomy Multisite taxonomy name.
 * @param string                      $object_type        Namespace: '', 'user' or 'blog'.
 * @param Multisite_Object_Scope|null $scope              Optional. Further restriction, e.g. one blog.
 * @return int
 */
function multisite_term_object_count( $multisite_term_id, $multisite_taxonomy, $object_type = '', $scope = null ) {
	$object_type = normalize_multisite_object_type( $object_type );
	$counts      = multisite_term_object_counts( $multisite_term_id, $multisite_taxonomy, $scope );
	$term_counts = isset( $counts[ (int) $multisite_term_id ] ) ? $counts[ (int) $multisite_term_id ] : array();

	return isset( $term_counts[ $object_type ] ) ? $term_counts[ $object_type ] : 0;
}

/**
 * The multisite taxonomies registered for an ID namespace.
 *
 * {@see get_object_multisite_taxonomies()} matches the names a taxonomy was registered with
 * ('post', a CPT, 'user', 'blog'), which is the right question in the admin but the wrong one for
 * relationships: those store normalized namespaces, where every post type is ''. This function
 * asks the relationship question.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param string $object_type Normalized namespace: '', 'user' or 'blog'.
 * @param string $output      Optional. 'names' or 'objects'. Default 'names'.
 * @return array Taxonomy names, or name => Multisite_Taxonomy.
 */
function get_multisite_taxonomies_for_namespace( $object_type, $output = 'names' ) {
	global $multisite_taxonomies;

	$object_type = normalize_multisite_object_type( $object_type );
	$found       = array();

	foreach ( (array) $multisite_taxonomies as $name => $taxonomy ) {
		if ( ! $taxonomy instanceof Multisite_Taxonomy || ! $taxonomy->supports_namespace( $object_type ) ) {
			continue;
		}

		if ( 'names' === $output ) {
			$found[] = $name;
		} else {
			$found[ $name ] = $taxonomy;
		}
	}

	return $found;
}
