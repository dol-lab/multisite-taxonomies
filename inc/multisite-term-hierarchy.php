<?php
/**
 * Multisite Taxonomy API : Term hierarchy
 *
 * Parents, children, ancestors and the loop check, plus the cached hierarchy map the
 * term queries pad their counts from.
 *
 * @package multitaxo
 */

/**
 * Merge all term children into a single array of their IDs.
 *
 * This recursive function will merge all of the children of $multisite_term into the same
 * array of multisite term IDs. Only useful for multisite taxonomies which are hierarchical.
 *
 * Will return an empty array if $multisite_term does not exist in $multisite_taxonomy.
 *
 * @param string $multisite_term_id  ID of Multisite Term to get children.
 * @param string $multisite_taxonomy Multisite Taxonomy Name.
 * @return array|WP_Error List of Multisite term IDs. WP_Error returned if `$multisite_taxonomy` does not exist.
 */
function get_multisite_term_children( $multisite_term_id, $multisite_taxonomy ) {
	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid taxonomy.', 'multitaxo' ) );
	}

	$multisite_term_id = intval( $multisite_term_id );

	$multisite_terms = _get_multisite_term_hierarchy( $multisite_taxonomy );

	if ( ! isset( $multisite_terms[ $multisite_term_id ] ) ) {
		return array();
	}

	$children = $multisite_terms[ $multisite_term_id ];

	foreach ( (array) $multisite_terms[ $multisite_term_id ] as $child ) {
		if ( $multisite_term_id === $child ) {
			continue;
		}
		if ( isset( $multisite_terms[ $child ] ) ) {
			$children = array_merge( $children, get_multisite_term_children( $child, $multisite_taxonomy ) );
		}
	}

	return $children;
}

/**
 * Check if a multisite term is an ancestor of another multisite term.
 *
 * You can use either an id or the multisite term object for both parameters.
 *
 * @param int|object $multisite_term1    ID or object to check if this is the parent multisite term.
 * @param int|object $multisite_term2    The child multisite term.
 * @param string     $multisite_taxonomy Multisite taxonomy name that $multisite_term1 and `$multisite_term2` belong to.
 * @return bool Whether `$multisite_term2` is a child of `$multisite_term1`.
 */
function multisite_term_is_ancestor_of( $multisite_term1, $multisite_term2, $multisite_taxonomy ) {
	if ( ! isset( $multisite_term1->multisite_term_id ) ) {
		$multisite_term1 = get_multisite_term( $multisite_term1, $multisite_taxonomy );
	}
	if ( ! isset( $multisite_term2->parent ) ) {
		$multisite_term2 = get_multisite_term( $multisite_term2, $multisite_taxonomy );
	}
	if ( empty( $multisite_term1->multisite_term_id ) || empty( $multisite_term2->parent ) ) {
		return false;
	}
	if ( $multisite_term2->parent === $multisite_term1->multisite_term_id ) {
		return true;
	}
	return multisite_term_is_ancestor_of( $multisite_term1, get_multisite_term( $multisite_term2->parent, $multisite_taxonomy ), $multisite_taxonomy );
}

/**
 *
 * Retrieves children of multisite taxonomy as multisite term IDs.
 *
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return array Empty if $multisite_taxonomy isn't hierarchical or returns children as multisite term IDs.
 */
function _get_multisite_term_hierarchy( $multisite_taxonomy ) {
	if ( ! is_multisite_taxonomy_hierarchical( $multisite_taxonomy ) ) {
		return array();
	}
	$children = get_option( "multisite_{$multisite_taxonomy}_children" );

	if ( is_array( $children ) ) {
		return $children;
	}
	$children        = array();
	$multisite_terms = get_multisite_terms(
		array(
			'get'      => 'all',
			'orderby'  => 'id',
			'fields'   => 'id=>parent',
			'taxonomy' => $multisite_taxonomy,
		)
	);
	foreach ( $multisite_terms as $multisite_term_id => $parent ) {
		if ( $parent > 0 ) {
			$children[ $parent ][] = $multisite_term_id;
		}
	}
	update_option( "multisite_{$multisite_taxonomy}_children", $children );

	return $children;
}

/**
 * Get the subset of $multisite_terms that are descendants of $multisite_term_id.
 *
 * If `$multisite_terms` is an array of objects, then _get_multisite_term_children() returns an array of objects.
 * If `$multisite_terms` is an array of IDs, then _get_multisite_term_children() returns an array of IDs.
 *
 * @param int    $multisite_term_id   The ancestor multisite term: all returned multisite terms should be descendants of `$multisite_term_id`.
 * @param array  $multisite_terms     The set of multisite terms - either an array of multisite term objects or multisite term IDs - from which those that
 *                          are descendants of $multisite_term_id will be chosen.
 * @param string $multisite_taxonomy  The multisite taxonomy which determines the hierarchy of the multisite terms.
 * @param array  $ancestors Optional. Multisite Term ancestors that have already been identified. Passed by reference, to keep
 *                          track of found multisite terms when recursing the hierarchy. The array of located ancestors is used
 *                          to prevent infinite recursion loops. For performance, `multisite_term_ids` are used as array keys,
 *                          with 1 as value. Default empty array.
 * @return array|WP_Error The subset of $multisite_terms that are descendants of $multisite_term_id.
 */
function _get_multisite_term_children( $multisite_term_id, $multisite_terms, $multisite_taxonomy, &$ancestors = array() ) {
	$empty_array = array();
	if ( empty( $multisite_terms ) ) {
		return $empty_array;
	}
	$multisite_term_list = array();
	$has_children        = _get_multisite_term_hierarchy( $multisite_taxonomy );

	if ( ( 0 !== $multisite_term_id ) && ! isset( $has_children[ $multisite_term_id ] ) ) {
		return $empty_array;
	}
	// Include the multisite term itself in the ancestors array, so we can properly detect when a loop has occurred.
	if ( empty( $ancestors ) ) {
		$ancestors[ $multisite_term_id ] = 1;
	}

	foreach ( (array) $multisite_terms as $multisite_term ) {
		$use_id = false;
		if ( ! is_object( $multisite_term ) ) {
			$multisite_term = get_multisite_term( $multisite_term, $multisite_taxonomy );
			if ( is_wp_error( $multisite_term ) ) {
				return $multisite_term;
			}
			$use_id = true;
		}

		// Don't recurse if we've already identified the multisite term as a child - this indicates a loop.
		if ( isset( $ancestors[ $multisite_term->multisite_term_id ] ) ) {
			continue;
		}
		if ( intval( $multisite_term->parent ) === intval( $multisite_term_id ) ) {
			if ( $use_id ) {
				$multisite_term_list[] = $multisite_term->multisite_term_id;
			} else {
				$multisite_term_list[] = $multisite_term;
			}
			if ( ! isset( $has_children[ $multisite_term->multisite_term_id ] ) ) {
				continue;
			}
			$ancestors[ $multisite_term->multisite_term_id ] = 1;

			$children = _get_multisite_term_children( $multisite_term->multisite_term_id, $multisite_terms, $multisite_taxonomy, $ancestors );
			if ( $children ) {
				$multisite_term_list = array_merge( $multisite_term_list, $children );
			}
		}
	}

	return $multisite_term_list;
}

/**
 * Add count of children to parent count.
 *
 * Recalculates multisite term counts by including items from child multisite terms. Assumes all
 * relevant children are already in the $multisite_terms argument.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array  $multisite_terms    List of multisite term objects, passed by reference.
 * @param string $multisite_taxonomy Multisite term context.
 */
function _pad_multisite_term_counts( &$multisite_terms, $multisite_taxonomy ) {
	global $wpdb;

	// This function only works for hierarchical multisite taxonomies.
	if ( ! is_multisite_taxonomy_hierarchical( $multisite_taxonomy ) ) {
		return;
	}
	$multisite_term_hier = _get_multisite_term_hierarchy( $multisite_taxonomy );

	if ( empty( $multisite_term_hier ) ) {
		return;
	}

	$multisite_term_items  = array();
	$multisite_terms_by_id = array();
	$multisite_term_ids    = array();

	foreach ( (array) $multisite_terms as $key => $multisite_term ) {
		$multisite_terms_by_id[ $multisite_term->multisite_term_id ]                 = & $multisite_terms[ $key ];
		$multisite_term_ids[ $multisite_term->multisite_term_multisite_taxonomy_id ] = $multisite_term->multisite_term_id;
	}

	// Get the object and multisite term ids and stick them in a lookup table.
	$multi_tax_obj = get_multisite_taxonomy( $multisite_taxonomy );
	$object_type   = esc_sql( $multi_tax_obj->object_type );
	$results       = $wpdb->get_results( "SELECT object_id, multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_term_relationships INNER JOIN $wpdb->posts ON object_id = ID WHERE multisite_term_multisite_taxonomy_id IN (" . implode( ',', array_keys( $multisite_term_ids ) ) . ") AND post_type IN ( '" . implode( "', '", $object_type ) . "' ) AND post_status = 'publish'" );
	foreach ( $results as $row ) {
		$id = $multisite_term_ids[ $row->multisite_term_multisite_taxonomy_id ];
		$multisite_term_items[ $id ][ $row->object_id ] = isset( $multisite_term_items[ $id ][ $row->object_id ] ) ? ++$multisite_term_items[ $id ][ $row->object_id ] : 1;
	}

	// Touch every ancestor's lookup row for each post in each term.
	foreach ( $multisite_term_ids as $multisite_term_id ) {
		$child     = $multisite_term_id;
		$ancestors = array();
		while ( ! empty( $multisite_terms_by_id[ $child ] ) && $multisite_terms_by_id[ $child ]->parent ) {
			$parent      = $multisite_terms_by_id[ $child ]->parent;
			$ancestors[] = $child;
			if ( ! empty( $multisite_term_items[ $multisite_term_id ] ) ) {
				foreach ( $multisite_term_items[ $multisite_term_id ] as $item_id => $touches ) {
					$multisite_term_items[ $parent ][ $item_id ] = isset( $multisite_term_items[ $parent ][ $item_id ] ) ? ++$multisite_term_items[ $parent ][ $item_id ] : 1;
				}
			}
			$child = $parent;

			if ( in_array( $parent, $ancestors, true ) ) {
				break;
			}
		}
	}

	// Transfer the touched cells.
	foreach ( (array) $multisite_term_items as $id => $items ) {
		if ( isset( $multisite_terms_by_id[ $id ] ) ) {
			$multisite_terms_by_id[ $id ]->count = count( $items );
		}
	}
}

/**
 * Get an array of ancestor IDs for a given object.
 *
 * @param int    $object_id     Optional. The ID of the object. Default 0.
 * @param string $object_type   Optional. The type of object for which we'll be retrieving
 *                              ancestors. Accepts a post type or a multisite taxonomy name. Default empty.
 * @param string $resource_type Optional. Type of resource $object_type is. Accepts 'post_type'
 *                              or 'multisite_taxonomy'. Default empty.
 * @return array An array of ancestors from lowest to highest in the hierarchy.
 */
function get_multisite_ancestors( $object_id = 0, $object_type = '', $resource_type = '' ) {
	$object_id = (int) $object_id;

	$ancestors = array();

	if ( empty( $object_id ) ) {
		return apply_filters( 'get_multisite_ancestors', $ancestors, $object_id, $object_type, $resource_type );
	}

	if ( ! $resource_type ) {
		if ( is_multisite_taxonomy_hierarchical( $object_type ) ) {
			$resource_type = 'multisite_taxonomy';
		} elseif ( post_type_exists( $object_type ) ) {
			$resource_type = 'post_type';
		}
	}

	if ( 'multisite_taxonomy' === $resource_type ) {
		$multisite_term = get_multisite_term( $object_id, $object_type );
		while ( ! is_wp_error( $multisite_term ) && ! empty( $multisite_term->parent ) && ! in_array( $multisite_term->parent, $ancestors, true ) ) {
			$ancestors[]    = (int) $multisite_term->parent;
			$multisite_term = get_multisite_term( $multisite_term->parent, $object_type );
		}
	} elseif ( 'post_type' === $resource_type ) {
		$ancestors = get_post_ancestors( $object_id );
	}

	/**
	 * Filters a given object's ancestors.
	 *
	 * @param array  $ancestors     An array of object ancestors.
	 * @param int    $object_id     Object ID.
	 * @param string $object_type   Type of object.
	 * @param string $resource_type Type of resource $object_type is.
	 */
	return apply_filters( 'get_multisite_ancestors', $ancestors, $object_id, $object_type, $resource_type );
}

/**
 * Returns the multisite term's parent's multisite_term_id.
 *
 * @param int    $multisite_term_id  Multisite term ID.
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return int|false False on error.
 */
function get_multisite_term_multisite_taxonomy_parent_id( $multisite_term_id, $multisite_taxonomy ) {
	$multisite_term = get_multisite_term( $multisite_term_id, $multisite_taxonomy );
	if ( ! $multisite_term || is_wp_error( $multisite_term ) ) {
		return false;
	}
	return (int) $multisite_term->parent;
}

/**
 * Checks the given subset of the multisite term hierarchy for hierarchy loops.
 * Prevents loops from forming and breaks those that it finds.
 *
 * @param int    $parent_term        `multisite_term_id` of the parent for the multisite term we're checking.
 * @param int    $multisite_term_id  The multisite term we're checking.
 * @param string $multisite_taxonomy The multisite taxonomy of the multisite term we're checking.
 *
 * @return int The new parent for the multisite term.
 */
function check_multisite_term_hierarchy_for_loops( $parent_term, $multisite_term_id, $multisite_taxonomy ) {
	// Nothing fancy here - bail.
	if ( ! $parent_term ) {
		return 0;
	}

	// Can't be its own parent.
	if ( (int) $parent_term === (int) $multisite_term_id ) {
		return 0;
	}
	// Now look for larger loops.
	$loop = wp_find_hierarchy_loop( 'get_multisite_term_multisite_taxonomy_parent_id', $multisite_term_id, $parent_term, array( $multisite_taxonomy ) );
	if ( ! $loop ) {
		return $parent_term; // No loop.
	}
	// Setting $parent to the given value causes a loop.
	if ( isset( $loop[ $multisite_term_id ] ) ) {
		return 0;
	}
	// There's a loop, but it doesn't contain $multisite_term_id. Break the loop.
	foreach ( array_keys( $loop ) as $loop_member ) {
		update_multisite_term(
			$loop_member,
			$multisite_taxonomy,
			array(
				'parent' => 0,
			)
		);
	}
	return $parent_term;
}
