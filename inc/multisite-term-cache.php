<?php
/**
 * Multisite Taxonomy API : Term and relationship caches
 *
 * @package multitaxo
 */

/**
 * Removes the multisite taxonomy relationship to multisite terms from the cache.
 *
 * Will remove the entire multisite taxonomy relationship containing multisite term `$object_id`. The
 * multisite term IDs have to exist within the multisite taxonomy `$object_type` for the deletion to
 * take place.
 *
 * @global bool $_wp_suspend_cache_invalidation
 *
 * @see get_object_multisite_taxonomies() for more on $object_type.
 *
 * @param int|array    $object_ids  Single or list of multisite term object ID(s).
 * @param array|string $object_type The multisite taxonomy object type.
 * @param int          $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 */
function clean_object_multisite_term_cache( $object_ids, $object_type, $blog_id = 0 ) {
	global $_wp_suspend_cache_invalidation;

	if ( ! empty( $_wp_suspend_cache_invalidation ) ) {
		return;
	}

	$scope = Multisite_Object_Scope::create( $object_type, $blog_id );

	if ( ! is_array( $object_ids ) ) {
		$object_ids = array( $object_ids );
	}
	$multisite_taxonomies = get_object_multisite_taxonomies( $object_type );

	foreach ( $object_ids as $id ) {
		foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
			wp_cache_delete( $id, $scope->cache_group( $multisite_taxonomy ) );
		}
	}

	/**
	 * Fires after the object multisite term cache has been cleaned.
	 *
	 * @param array  $object_ids An array of object IDs.
	 * @param string $objet_type Object type.
	 */
	do_action( 'clean_object_multisite_term_cache', $object_ids, $object_type );
}

/**
 * Will remove all of the multisite term ids from the cache.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @global bool $_wp_suspend_cache_invalidation
 *
 * @param int|array $ids            Single or list of multisite term IDs.
 * @param string    $multisite_taxonomy       Optional. Can be empty and will assume `mtmt_ids`, else will use for context.
 *                                  Default empty.
 * @param bool      $clean_taxonomy Optional. Whether to clean multisite taxonomy wide caches (true), or just individual
 *                                  multisite term object caches (false). Default true.
 */
function clean_multisite_term_cache( $ids, $multisite_taxonomy = '', $clean_taxonomy = true ) {
	global $wpdb, $_wp_suspend_cache_invalidation;

	if ( ! empty( $_wp_suspend_cache_invalidation ) ) {
		return;
	}

	if ( ! is_array( $ids ) ) {
		$ids = array( $ids );
	}

	$multisite_taxonomies = array();
	// If no multisite taxonomy, assume mtmt_ids.
	if ( empty( $multisite_taxonomy ) ) {
		$mtmt_ids        = array_map( 'intval', $ids );
		$multisite_terms = $wpdb->get_results( 'SELECT multisite_term_id, multisite_taxonomy FROM ' . $wpdb->multisite_term_multisite_taxonomy . " WHERE multisite_term_multisite_taxonomy_id IN ( '" . implode( "', '", esc_sql( $mtmt_ids ) ) . "' )" );
		$ids             = array();
		foreach ( (array) $multisite_terms as $multisite_term ) {
			$multisite_taxonomies[] = $multisite_term->multisite_taxonomy;
			$ids[]                  = $multisite_term->multisite_term_id;
			wp_cache_delete( $multisite_term->multisite_term_id, 'multisite_terms' );
		}
		$multisite_taxonomies = array_unique( $multisite_taxonomies );
	} else {
		$multisite_taxonomies = array( $multisite_taxonomy );
		foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'multisite_terms' );
			}
		}
	}

	foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
		if ( $clean_taxonomy ) {
			wp_cache_delete( 'all_ids', $multisite_taxonomy );
			wp_cache_delete( 'get', $multisite_taxonomy );
			delete_option( "multisite_{$multisite_taxonomy}_children" );
			// Regenerate multisite_{$multisite_taxonomy}_children.
			_get_multisite_term_hierarchy( $multisite_taxonomy );
		}

		/**
		 * Fires once after each taxonomy's term cache has been cleaned.
		 *
		 * @param array  $ids            An array of multisite term IDs.
		 * @param string $multisite_taxonomy       Multisite taxonomy slug.
		 * @param bool   $clean_taxonomy Whether or not to clean taxonomy-wide caches.
		 */
		do_action( 'clean_multisite_term_cache', $ids, $multisite_taxonomy, $clean_taxonomy );
	}

	wp_cache_set( 'last_changed', microtime(), 'multisite_terms' );
}

/**
 * Retrieves the multisite taxonomy relationship to the multisite term object id.
 *
 * Upstream functions (like get_the_multisite_terms() and is_object_in_multisite_term()) are
 * responsible for populating the object-term relationship cache. The current
 * function only fetches relationship data that is already in the cache.
 *
 * @param int    $id       Multisite term object ID.
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @param int    $blog_id The blog the object ID belongs to. Defaults to the current blog.
 * @param string $object_type Optional. ID namespace of `$id`: '' (post, default), 'user' or 'blog'.
 *                            Inferred from a single-namespace taxonomy when omitted.
 *
 * @return bool|array|WP_Error Array of `Multisite_Term` objects, if cached.
 *                             False if cache is empty for `$multisite_taxonomy` and `$id`.
 *                             WP_Error if get_multisite_term() returns an error object for any multisite term.
 */
function get_object_multisite_term_cache( $id, $multisite_taxonomy, $blog_id = 0, $object_type = '' ) {
	$scope = Multisite_Object_Scope::for_taxonomy( $object_type, $multisite_taxonomy, $blog_id );

	$_multisite_term_ids = wp_cache_get( $id, $scope->cache_group( $multisite_taxonomy ) );

	// We leave the priming of relationship caches to upstream functions.
	if ( false === $_multisite_term_ids ) {
		return false;
	}

	// Backward compatibility for if a plugin is putting objects into the cache, rather than IDs.
	$multisite_term_ids = array();
	foreach ( $_multisite_term_ids as $multisite_term_id ) {
		if ( is_numeric( $multisite_term_id ) ) {
			$multisite_term_ids[] = intval( $multisite_term_id );
		} elseif ( isset( $multisite_term_id->multisite_term_id ) ) {
			$multisite_term_ids[] = intval( $multisite_term_id->multisite_term_id );
		}
	}

	// Fill the multisite term objects.
	_prime_multisite_term_caches( $multisite_term_ids );

	$multisite_terms = array();
	foreach ( $multisite_term_ids as $multisite_term_id ) {
		$multisite_term = get_multisite_term( $multisite_term_id, $multisite_taxonomy );
		if ( is_wp_error( $multisite_term ) ) {
			return $multisite_term;
		}

		$multisite_terms[] = $multisite_term;
	}

	return $multisite_terms;
}

/**
 * Updates the cache for the given multisite term object ID(s).
 *
 * Note: Due to performance concerns, great care should be taken to only update
 * multisite term caches when necessary. Processing time can increase exponentially depending
 * on both the number of passed multisite term IDs and the number of multisite taxonomies those multisite terms
 * belong to.
 *
 * Caches will only be updated for multisite terms not already cached.
 *
 * @param string|array $object_ids  Comma-separated list or array of multisite term object IDs.
 * @param array|string $object_type The multisite taxonomy object type.
 * @param int          $blog_id     The blog the object IDs belong to. Defaults to the current blog.
 * @return void|false False if all of the multisite terms in `$object_ids` are already cached.
 */
function update_object_multisite_term_cache( $object_ids, $object_type, $blog_id = 0 ) {
	if ( empty( $object_ids ) ) {
		return;
	}
	if ( ! is_array( $object_ids ) ) {
		$object_ids = explode( ',', $object_ids );
	}
	$object_ids = array_map( 'intval', $object_ids );

	$multisite_taxonomies = get_object_multisite_taxonomies( $object_type );
	$scope                = Multisite_Object_Scope::create( $object_type, $blog_id );

	$ids = array();
	foreach ( (array) $object_ids as $id ) {
		foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
			if ( false === wp_cache_get( $id, $scope->cache_group( $multisite_taxonomy ) ) ) {
				$ids[] = $id;
				break;
			}
		}
	}

	if ( empty( $ids ) ) {
		return false;
	}
	// Read in the same scope the entries are cached under, or a user/blog cache is primed with posts.
	$multisite_terms = get_object_multisite_terms(
		$ids,
		$multisite_taxonomies,
		$scope->blog_id(),
		array(
			'fields'                           => 'all_with_object_id',
			'orderby'                          => 'name',
			'update_multisite_term_meta_cache' => false,
		),
		$scope->object_type()
	);

	$object_multisite_terms = array();
	foreach ( (array) $multisite_terms as $multisite_term ) {
		$object_multisite_terms[ $multisite_term->object_id ][ $multisite_term->multisite_taxonomy ][] = $multisite_term->multisite_term_id;
	}

	foreach ( $ids as $id ) {
		foreach ( $multisite_taxonomies as $multisite_taxonomy ) {
			if ( ! isset( $object_multisite_terms[ $id ][ $multisite_taxonomy ] ) ) {
				if ( ! isset( $object_multisite_terms[ $id ] ) ) {
					$object_multisite_terms[ $id ] = array();
				}
				$object_multisite_terms[ $id ][ $multisite_taxonomy ] = array();
			}
		}
	}

	foreach ( $object_multisite_terms as $id => $value ) {
		foreach ( $value as $multisite_taxonomy => $multisite_terms ) {
			wp_cache_add( $id, $multisite_terms, $scope->cache_group( $multisite_taxonomy ) );
		}
	}
}

/**
 * Updates multisite terms to multisite taxonomy in cache.
 *
 * @param array  $multisite_terms    List of multisite term objects to change.
 * @param string $multisite_taxonomy Optional. Update multisite term to this multisite taxonomy in cache. Default empty.
 */
function update_multisite_term_cache( $multisite_terms, $multisite_taxonomy = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Kept for signature parity with WordPress core update_term_cache().
	foreach ( (array) $multisite_terms as $multisite_term ) {
		// Create a copy in case the array was passed by reference.
		$_multisite_term = clone $multisite_term;

		// Object ID should not be cached.
		unset( $_multisite_term->object_id );

		wp_cache_add( $multisite_term->multisite_term_id, $_multisite_term, 'multisite_terms' );
	}
}

/**
 * Adds any multisite terms from the given IDs to the cache that do not already exist in cache.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array $multisite_term_ids          Array of multisite term IDs.
 * @param bool  $update_meta_cache Optional. Whether to update the meta cache. Default true.
 */
function _prime_multisite_term_caches( $multisite_term_ids, $update_meta_cache = true ) {
	global $wpdb;

	$non_cached_ids = _get_non_cached_ids( $multisite_term_ids, 'multisite_terms' );
	if ( ! empty( $non_cached_ids ) ) {
		$fresh_multisite_terms = $wpdb->get_results( 'SELECT t.*, tt.* FROM ' . $wpdb->multisite_terms . ' AS t INNER JOIN ' . $wpdb->multisite_term_multisite_taxonomy . " AS tt ON t.multisite_term_id = tt.multisite_term_id WHERE t.multisite_term_id IN ( '" . implode( "', '", esc_sql( $non_cached_ids ) ) . "' )" );

		update_multisite_term_cache( $fresh_multisite_terms, $update_meta_cache );

		if ( $update_meta_cache ) {
			update_multisite_termmeta_cache( $non_cached_ids );
		}
	}
}
