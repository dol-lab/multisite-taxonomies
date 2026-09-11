<?php
/**
 * Multisite Taxonomy API : Term CRUD
 *
 * Creating, updating and deleting terms, plus the slug uniqueness and count bookkeeping
 * that writing a term drags along.
 *
 * @package multitaxo
 */

/**
 * Removes a multisite term from the database.
 *
 * If the multisite term is a parent of other multisite terms, then the children will be updated to
 * that multisite term's parent.
 *
 * Metadata associated with the multisite term will be deleted.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int          $multisite_term     Multisite term ID.
 * @param string       $multisite_taxonomy Multisite taxonomy name.
 * @param array|string $args {
 *     Optional. Array of arguments to override the default multisite term ID. Default empty array.
 *
 *     @type int  $default       The multisite term ID to make the default multisite term. This will only override
 *                               the multisite terms found if there is only one term found. Any other and
 *                               the found multisite terms are used.
 *     @type bool $force_default Optional. Whether to force the supplied multisite term as default to be
 *                               assigned even if the object was not going to be multisite term-less.
 *                               Default false.
 * }
 * @return bool|int|WP_Error True on success, false if multisite term does not exist. Zero on attempted
 *                           deletion of default Category. WP_Error if the multisite taxonomy does not exist.
 */
function delete_multisite_term( $multisite_term, $multisite_taxonomy, $args = array() ) {
	global $wpdb;

	// If the user cannot manage multisite terms then kick back.
	if ( ! current_user_can( 'manage_multisite_terms' ) ) {
		return false;
	}

	$multisite_term = (int) $multisite_term;
	$ids            = multisite_term_exists( $multisite_term, $multisite_taxonomy );
	if ( ! $ids ) {
		return false;
	}
	if ( is_wp_error( $ids ) ) {
		return $ids;
	}
	$mtmt_id = $ids['multisite_term_multisite_taxonomy_id'];

	$defaults = array();

	$args = wp_parse_args( $args, $defaults );

	if ( isset( $args['default'] ) ) {
		$default = (int) $args['default'];
		if ( ! multisite_term_exists( $default, $multisite_taxonomy ) ) {
			unset( $default );
		}
	}

	if ( isset( $args['force_default'] ) ) {
		$force_default = $args['force_default'];
	}

	/**
	 * Fires when deleting a multisite term, before any modifications are made to posts or multisite terms.
	 *
	 * @param int    $multisite_term     Multisite term ID.
	 * @param string $multisite_taxonomy Multisite taxonomy name.
	 */
	do_action( 'pre_delete_multisite_term', $multisite_term, $multisite_taxonomy );

	// Update children to point to new parent.
	if ( is_multisite_taxonomy_hierarchical( $multisite_taxonomy ) ) {
		$multisite_term_obj = get_multisite_term( $multisite_term, $multisite_taxonomy );
		if ( is_wp_error( $multisite_term_obj ) ) {
			return $multisite_term_obj;
		}
		$parent = $multisite_term_obj->parent;

		$edit_ids      = $wpdb->get_results( $wpdb->prepare( "SELECT multisite_term_id, multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_term_multisite_taxonomy WHERE `parent` = %d", (int) $multisite_term_obj->multisite_term_id ) );
		$edit_mtmt_ids = wp_list_pluck( $edit_ids, 'multisite_term_multisite_taxonomy_id' );

		/**
		 * Fires immediately before a multisite term to delete's children are reassigned a parent.
		 *
		 * @param array $edit_mtmt_ids An array of multisite term multisite taxonomy IDs for the given multisite term.
		 */
		do_action( 'edit_multisite_term_multisite_taxonomies', $edit_mtmt_ids );

		$wpdb->update(
			$wpdb->multisite_term_multisite_taxonomy,
			compact( 'parent' ),
			array(
				'parent' => $multisite_term_obj->multisite_term_id,
			) + compact( 'multisite_taxonomy' )
		);

		// Clean the cache for all child multisite terms.
		$edit_multisite_term_ids = wp_list_pluck( $edit_ids, 'multisite_term_id' );
		clean_multisite_term_cache( $edit_multisite_term_ids, $multisite_taxonomy );

		/**
		 * Fires immediately after a multisite term to delete's children are reassigned a parent.
		 *
		 * @param array $edit_mtmt_ids An array of multisite term multisite taxonomy IDs for the given multisite term.
		 */
		do_action( 'edited_multisite_term_multisite_taxonomies', $edit_mtmt_ids );
	}

	// Get the multisite term before deleting it or its multisite term relationships so we can pass to actions below.
	$deleted_multisite_term = get_multisite_term( $multisite_term, $multisite_taxonomy );

	// Every relationship row carries the blog and namespace it belongs to; reassignment has to
	// read and write in that same scope, or it rewrites a same-numbered object on another blog.
	$relationships = (array) $wpdb->get_results( $wpdb->prepare( "SELECT object_id, blog_id, object_type FROM $wpdb->multisite_term_relationships WHERE multisite_term_multisite_taxonomy_id = %d", $mtmt_id ) );
	$object_ids    = array_unique( array_map( 'intval', wp_list_pluck( $relationships, 'object_id' ) ) );

	foreach ( $relationships as $relationship ) {
		$object_id       = (int) $relationship->object_id;
		$row_blog_id     = (int) $relationship->blog_id;
		$row_object_type = $relationship->object_type;

		$multisite_terms = get_object_multisite_terms(
			$object_id,
			$multisite_taxonomy,
			$row_blog_id,
			array(
				'fields'  => 'ids',
				'orderby' => 'none',
			),
			$row_object_type
		);
		if ( 1 === count( $multisite_terms ) && isset( $default ) ) {
			$multisite_terms = array( $default );
		} else {
			$multisite_terms = array_diff( $multisite_terms, array( $multisite_term ) );
			if ( isset( $default ) && isset( $force_default ) && $force_default ) {
				$multisite_terms = array_merge( $multisite_terms, array( $default ) );
			}
		}
		$multisite_terms = array_map( 'intval', $multisite_terms );
		set_object_multisite_terms( $object_id, $multisite_terms, $multisite_taxonomy, $row_blog_id, false, $row_object_type );
	}

	// The term itself is about to go: drop whatever rows the reassignment loop left behind.
	$wpdb->delete(
		$wpdb->multisite_term_relationships,
		array(
			'multisite_term_multisite_taxonomy_id' => $mtmt_id,
		)
	);

	// Clean the relationship caches for all object types using this multisite term. The cache is
	// scoped like the rows are, so every blog the term was used on needs its own pass.
	$blog_ids = array_unique( array_map( 'intval', wp_list_pluck( $relationships, 'blog_id' ) ) );
	if ( empty( $blog_ids ) ) {
		$blog_ids = array( get_current_blog_id() );
	}
	$multisite_tax_object = get_multisite_taxonomy( $multisite_taxonomy );
	foreach ( $multisite_tax_object->object_type as $object_type ) {
		foreach ( $blog_ids as $relationship_blog_id ) {
			clean_object_multisite_term_cache( $object_ids, $object_type, $relationship_blog_id );
		}
	}
	$multisite_term_meta_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM $wpdb->multisite_termmeta WHERE multisite_term_id = %d ", $multisite_term ) );
	foreach ( $multisite_term_meta_ids as $mid ) {
		delete_metadata_by_mid( 'multisite_term', $mid );
	}

	/**
	 * Fires immediately before a multisite term multisite taxonomy ID is deleted.
	 *
	 * @param int $mtmt_id Multisite term multisite taxonomy ID.
	 */
	do_action( 'delete_multisite_term_multisite_taxonomy', $mtmt_id );
	$wpdb->delete(
		$wpdb->multisite_term_multisite_taxonomy,
		array(
			'multisite_term_multisite_taxonomy_id' => $mtmt_id,
		)
	);

	/**
	 * Fires immediately after a multisite term multisite taxonomy ID is deleted.
	 *
	 * @param int $mtmt_id Multisite term multisite taxonomy ID.
	 */
	do_action( 'deleted_multisite_term_multisite_taxonomy', $mtmt_id );

	// Delete the multisite term if no multisite taxonomies use it.
	if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->multisite_term_multisite_taxonomy WHERE multisite_term_id = %d", $multisite_term ) ) ) {
		$wpdb->delete(
			$wpdb->multisite_terms,
			array(
				'multisite_term_id' => $multisite_term,
			)
		);
	}
	clean_multisite_term_cache( $multisite_term, $multisite_taxonomy );

	/**
	 * Fires after a multisite term is deleted from the database and the cache is cleaned.
	 *
	 * @param int     $multisite_term         Multisite term ID.
	 * @param int     $mtmt_id        Multisite term multisite taxonomy ID.
	 * @param string  $multisite_taxonomy    Multisite taxonomy slug.
	 * @param mixed   $deleted_multisite_term Copy of the already-deleted multisite term, in the form specified
	 *                              by the parent function. WP_Error otherwise.
	 * @param array   $object_ids   List of multisite term object IDs.
	 */
	do_action( 'delete_multisite_term', $multisite_term, $mtmt_id, $multisite_taxonomy, $deleted_multisite_term, $object_ids );

	/**
	 * Fires after a multisite term in a specific multisite taxonomy is deleted.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the specific
	 * multisite taxonomy the multisite term belonged to.
	 *
	 * @param int     $multisite_term         Multisite term ID.
	 * @param int     $mtmt_id        Multisite term multisite taxonomy ID.
	 * @param mixed   $deleted_multisite_term Copy of the already-deleted multisite term, in the form specified
	 *                              by the parent function. WP_Error otherwise.
	 * @param array   $object_ids   List of multisite term object IDs.
	 */
	do_action( "delete_multisite_{$multisite_taxonomy}", $multisite_term, $mtmt_id, $deleted_multisite_term, $object_ids );

	return true;
}

/**
 * Add a new multisite term to the database.
 *
 * A non-existent multisite term is inserted in the following sequence:
 * 1. The multisite term is added to the multisite term table, then related to the multisite taxonomy.
 * 2. If everything is correct, several actions are fired.
 * 3. The 'multisite_term_id_filter' is evaluated.
 * 4. The multisite term cache is cleaned.
 * 5. Several more actions are fired.
 * 6. An array is returned containing the multisite term_id and multisite_term_multisite_taxonomy_id.
 *
 * If the 'slug' argument is not empty, then it is checked to see if the multisite term
 * is invalid. If it is not a valid, existing multisite term, it is added and the multisite term_id
 * is given.
 *
 * If the multisite taxonomy is hierarchical, and the 'parent' argument is not empty,
 * the multisite term is inserted and the multisite term_id will be given.
 *
 * Error handling:
 * If $multisite_taxonomy does not exist or $multisite_term is empty,
 * a WP_Error object will be returned.
 *
 * If the multisite term already exists on the same hierarchical level,
 * or the multisite term slug and name are not unique, a WP_Error object will be returned.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string       $multisite_term     The multisite term to add or update.
 * @param string       $multisite_taxonomy The multisite taxonomy to which to add the multisite term.
 * @param array|string $args {
 *     Optional. Array or string of arguments for inserting a multisite term.
 *
 *     @type string $alias_of    Slug of the multisite term to make this multisite term an alias of.
 *                               Default empty string. Accepts a multisite term slug.
 *     @type string $description The multisite term description. Default empty string.
 *     @type int    $parent      The id of the parent multisite term. Default 0.
 *     @type string $slug        The multisite term slug to use. Default empty string.
 * }
 * @param bool         $cap_check wether to check for the capability 'manage_multisite_terms'.
 * @return array|WP_Error An array containing the `multisite_term_id` and `multisite_term_multisite_taxonomy_id`,
 *                        WP_Error otherwise.
 */
function insert_multisite_term( $multisite_term, $multisite_taxonomy, $args = array(), $cap_check = true ) {
	global $wpdb;

	// If the user cannot create multisite terms then kick back.
	if ( ! current_user_can( 'manage_multisite_terms' ) && $cap_check ) {
		return new WP_Error( 'invalid_multisite_create_permissions', __( 'You are not authorized to create multisite terms.', 'multitaxo' ) );
	}

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
	}
	/**
	 * Filters a multisite term before it is sanitized and inserted into the database.
	 *
	 * @param string $multisite_term     The multisite term to add or update.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	$multisite_term = apply_filters( 'pre_insert_multisite_term', $multisite_term, $multisite_taxonomy );
	if ( is_wp_error( $multisite_term ) ) {
		return $multisite_term;
	}
	if ( is_int( $multisite_term ) && 0 === $multisite_term ) {
		return new WP_Error( 'invalid_multisite_term_id', __( 'Invalid multisite term ID.', 'multitaxo' ) );
	}
	if ( '' === trim( $multisite_term ) ) {
		return new WP_Error( 'empty_multisite_term_name', __( 'A name is required for this multisite term.', 'multitaxo' ) );
	}
	$defaults = array(
		'alias_of'    => '',
		'description' => '',
		'parent'      => 0,
		'slug'        => '',
	);
	$args     = wp_parse_args( $args, $defaults );

	if ( $args['parent'] > 0 && ! multisite_term_exists( (int) $args['parent'] ) ) {
		return new WP_Error( 'missing_parent', __( 'Parent multisite term does not exist.', 'multitaxo' ) );
	}

	$args['name']               = $multisite_term;
	$args['multisite_taxonomy'] = $multisite_taxonomy;

	// Coerce null description to strings, to avoid database errors.
	$args['description'] = (string) $args['description'];

	$args = sanitize_multisite_term( $args, $multisite_taxonomy, 'db' );

	$name        = wp_unslash( $args['name'] );
	$description = wp_unslash( $args['description'] );
	$parent      = (int) $args['parent'];

	$slug_provided = ! empty( $args['slug'] );
	if ( ! $slug_provided ) {
		$slug = sanitize_title( $name );
	} else {
		$slug = $args['slug'];
	}

	$multisite_term_group = 0;
	if ( $args['alias_of'] ) {
		$alias = get_multisite_term_by( 'slug', $args['alias_of'], $multisite_taxonomy );
		if ( ! empty( $alias->multisite_term_group ) ) {
			// The alias we want is already in a group, so let's use that one.
			$multisite_term_group = $alias->multisite_term_group;
		} elseif ( ! empty( $alias->multisite_term_id ) ) {
			/*
			 * The alias is not in a group, so we create a new one
			 * and add the alias to it.
			 */
			$multisite_term_group = $wpdb->get_var( "SELECT MAX(multisite_term_group) FROM $wpdb->multisite_terms" ) + 1;

			update_multisite_term(
				$alias->multisite_term_id,
				$multisite_taxonomy,
				array(
					'multisite_term_group' => $multisite_term_group,
				)
			);
		}
	}

	/*
	 * Prevent the creation of multisite terms with duplicate names at the same level of a multisite taxonomy hierarchy,
	 * unless a unique slug has been explicitly provided.
	 */
	$name_matches = get_multisite_terms(
		array(
			'name'       => $name,
			'hide_empty' => false,
			'taxonomy'   => $multisite_taxonomy,
		)
	);

	/*
	 * The `name` match in `get_multisite_terms()` doesn't differentiate accented characters,
	 * so we do a stricter comparison here.
	 */
	$name_match = null;
	if ( $name_matches ) {
		foreach ( $name_matches as $_match ) {
			if ( strtolower( $name ) === strtolower( $_match->name ) ) {
				$name_match = $_match;
				break;
			}
		}
	}

	if ( $name_match ) {
		$slug_match = get_multisite_term_by( 'slug', $slug, $multisite_taxonomy );
		if ( ! $slug_provided || $name_match->slug === $slug || $slug_match ) {
			if ( is_multisite_taxonomy_hierarchical( $multisite_taxonomy ) ) {
				$siblings = get_multisite_terms(
					array(
						'get'      => 'all',
						'parent'   => $parent,
						'taxonomy' => $multisite_taxonomy,
					)
				);

				$existing_multisite_term = null;
				if ( $name_match->slug === $slug && in_array( $name, wp_list_pluck( $siblings, 'name' ), true ) ) {
					$existing_multisite_term = $name_match;
				} elseif ( $slug_match && in_array( $slug, wp_list_pluck( $siblings, 'slug' ), true ) ) {
					$existing_multisite_term = $slug_match;
				}

				if ( $existing_multisite_term ) {
					return new WP_Error( 'multisite_term_exists', __( 'A multisite term with the name provided already exists with this parent.', 'multitaxo' ), $existing_multisite_term->multisite_term_id );
				}
			} else {
				return new WP_Error( 'multisite_term_exists', __( 'A multisite term with the name provided already exists in this taxonomy.', 'multitaxo' ), $name_match->multisite_term_id );
			}
		}
	}

	$slug = unique_multisite_term_slug( $slug, (object) $args );

	$data = compact( 'name', 'slug', 'multisite_term_group' );

	/**
	 * Filters multisite term data before it is inserted into the database.
	 *
	 * @param array  $data     Multisite term data to be inserted.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 * @param array  $args     Arguments passed to insert_multisite_term().
	 */
	$data = apply_filters( 'insert_multisite_term_data', $data, $multisite_taxonomy, $args );

	if ( false === $wpdb->insert( $wpdb->multisite_terms, $data ) ) {
		return new WP_Error( 'db_insert_error', __( 'Could not insert multisite term into the database', 'multitaxo' ), $wpdb->last_error );
	}

	$multisite_term_id = (int) $wpdb->insert_id;

	// Seems unreachable, However, Is used in the case that a multisite term name is provided, which sanitizes to an empty string.
	if ( empty( $slug ) ) {
		$slug = sanitize_title( $slug, $multisite_term_id );

		/** This action is documented in wp-includes/taxonomy.php */
		do_action( 'edit_multisite_terms', $multisite_term_id, $multisite_taxonomy );
		$wpdb->update( $wpdb->multisite_terms, compact( 'slug' ), compact( 'multisite_term_id' ) );

		/** This action is documented in wp-includes/taxonomy.php */
		do_action( 'edited_multisite_terms', $multisite_term_id, $multisite_taxonomy );
	}

	$mtmt_id = $wpdb->get_var( $wpdb->prepare( "SELECT tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_term_multisite_taxonomy AS tt INNER JOIN $wpdb->multisite_terms AS t ON tt.multisite_term_id = t.multisite_term_id WHERE tt.multisite_taxonomy = %s AND t.multisite_term_id = %d", $multisite_taxonomy, $multisite_term_id ) );

	if ( ! empty( $mtmt_id ) ) {
		return array(
			'multisite_term_id'                    => $multisite_term_id,
			'multisite_term_multisite_taxonomy_id' => $mtmt_id,
		);
	}
	$wpdb->insert(
		$wpdb->multisite_term_multisite_taxonomy,
		compact( 'multisite_term_id', 'multisite_taxonomy', 'description', 'parent' ) + array(
			'count' => 0,
		)
	);
	$mtmt_id = (int) $wpdb->insert_id;

	/*
	 * Sanity check: if we just created a multisite term with the same parent + multisite taxonomy + slug but a higher multisite_term_id than
	 * an existing multisite term, then we have unwittingly created a duplicate multisite term. Delete the dupe, and use the multisite_term_id
	 * and multisite_term_multisite_taxonomy_id of the older multisite term instead. Then return out of the function so that the "create" hooks
	 * are not fired.
	 */
	$duplicate_multisite_term = $wpdb->get_row( $wpdb->prepare( "SELECT t.multisite_term_id, tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_terms t INNER JOIN $wpdb->multisite_term_multisite_taxonomy tt ON ( tt.multisite_term_id = t.multisite_term_id ) WHERE t.slug = %s AND tt.parent = %d AND tt.multisite_taxonomy = %s AND t.multisite_term_id < %d AND tt.multisite_term_multisite_taxonomy_id != %d", $slug, $parent, $multisite_taxonomy, $multisite_term_id, $mtmt_id ) );
	if ( $duplicate_multisite_term ) {
		$wpdb->delete(
			$wpdb->multisite_terms,
			array(
				'multisite_term_id' => $multisite_term_id,
			)
		);
		$wpdb->delete(
			$wpdb->multisite_term_multisite_taxonomy,
			array(
				'multisite_term_multisite_taxonomy_id' => $mtmt_id,
			)
		);

		$multisite_term_id = (int) $duplicate_multisite_term->multisite_term_id;
		$mtmt_id           = (int) $duplicate_multisite_term->multisite_term_multisite_taxonomy_id;

		clean_multisite_term_cache( $multisite_term_id, $multisite_taxonomy );
		return array(
			'multisite_term_id'                    => $multisite_term_id,
			'multisite_term_multisite_taxonomy_id' => $mtmt_id,
		);
	}

	/**
	 * Fires immediately after a new multisite term is created, before the multisite term cache is cleaned.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'create_multisite_term', $multisite_term_id, $mtmt_id, $multisite_taxonomy );

	/**
	 * Fires after a new multisite term is created for a specific taxonomy.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers
	 * to the slug of the multisite taxonomy the multisite term was created for.
	 *
	 * @param int $multisite_term_id Multisite term ID.
	 * @param int $mtmt_id   Multisite term multisite taxonomy ID.
	 */
	do_action( "create_multisite_{$multisite_taxonomy}", $multisite_term_id, $mtmt_id );

	/**
	 * Filters the multisite term ID after a new multisite term is created.
	 *
	 * @param int $multisite_term_id Multisite term ID.
	 * @param int $mtmt_id   Taxonomy term ID.
	 */
	$multisite_term_id = apply_filters( 'multisite_term_id_filter', $multisite_term_id, $mtmt_id );

	clean_multisite_term_cache( $multisite_term_id, $multisite_taxonomy );

	/**
	 * Fires after a new multisite term is created, and after the multisite term cache has been cleaned.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'created_multisite_term', $multisite_term_id, $mtmt_id, $multisite_taxonomy );

	/**
	 * Fires after a new multisite term in a specific multisite taxonomy is created, and after the multisite term
	 * cache has been cleaned.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the multisite taxonomy slug.
	 *
	 * @param int $multisite_term_id Multisite term ID.
	 * @param int $mtmt_id   Multisite term multisite taxonomy ID.
	 */
	do_action( "created_multisite_{$multisite_taxonomy}", $multisite_term_id, $mtmt_id );

	return array(
		'multisite_term_id'                    => $multisite_term_id,
		'multisite_term_multisite_taxonomy_id' => $mtmt_id,
	);
}

/**
 * Will make slug unique, if it isn't already.
 *
 * The `$slug` has to be unique global to every multisite taxonomy, meaning that one
 * multisite taxonomy multisite term can't have a matching slug with another multisite taxonomy multisite term. Each
 * slug has to be globally unique for every multisite taxonomy.
 *
 * The way this works is that if the multisite taxonomy that the multisite term belongs to is
 * hierarchical and has a parent, it will append that parent to the $slug.
 *
 * If that still doesn't return an unique slug, then it try to append a number
 * until it finds a number that is truly unique.
 *
 * The only purpose for `$multisite_term` is for appending a parent, if one exists.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param string $slug The string that will be tried for a unique slug.
 * @param object $multisite_term The multisite term object that the `$slug` will belong to.
 * @return string Will return a true unique slug.
 */
function unique_multisite_term_slug( $slug, $multisite_term ) {
	global $wpdb;

	$needs_suffix  = true;
	$original_slug = $slug;

	// As of 4.1, duplicate slugs are allowed as long as they're in different taxonomies.
	if ( ! multisite_term_exists( $slug ) || ( get_option( 'db_version' ) >= 30133 && ! get_multisite_term_by( 'slug', $slug, $multisite_term->multisite_taxonomy ) ) ) {
		$needs_suffix = false;
	}

	/*
	 * If the multisite taxonomy supports hierarchy and the multisite term has a parent, make the slug unique
	 * by incorporating parent slugs.
	 */
	$parent_suffix = '';
	if ( $needs_suffix && is_multisite_taxonomy_hierarchical( $multisite_term->multisite_taxonomy ) && ! empty( $multisite_term->parent ) ) {
		$the_parent = $multisite_term->parent;
		while ( ! empty( $the_parent ) ) {
			$parent_multisite_term = get_multisite_term( $the_parent, $multisite_term->multisite_taxonomy );
			if ( is_wp_error( $parent_multisite_term ) || empty( $parent_multisite_term ) ) {
				break;
			}
			$parent_suffix .= '-' . $parent_multisite_term->slug;
			if ( ! multisite_term_exists( $slug . $parent_suffix ) ) {
				break;
			}
			if ( empty( $parent_multisite_term->parent ) ) {
				break;
			}
			$the_parent = $parent_multisite_term->parent;
		}
	}

	// If we didn't get a unique slug, try appending a number to make it unique.
	/**
	 * Filters whether the proposed unique multisite term slug is bad.
	 *
	 * @param bool   $needs_suffix Whether the slug needs to be made unique with a suffix.
	 * @param string $slug         The slug.
	 * @param object $multisite_term         Multisite term object.
	 */
	if ( apply_filters( 'unique_multisite_term_slug_is_bad_slug', $needs_suffix, $slug, $multisite_term ) ) {
		if ( $parent_suffix ) {
			$slug .= $parent_suffix;
		} else {
			if ( ! empty( $multisite_term->multisite_term_id ) ) {
				$query = $wpdb->prepare( "SELECT slug FROM $wpdb->multisite_terms WHERE slug = %s AND multisite_term_id != %d", $slug, $multisite_term->multisite_term_id );
			} else {
				$query = $wpdb->prepare( "SELECT slug FROM $wpdb->multisite_terms WHERE slug = %s", $slug );
			}
			if ( $wpdb->get_var( $query ) ) {
				$num = 2;
				do {
					$alt_slug = $slug . "-$num";
					++$num;
					$slug_check = $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM $wpdb->multisite_terms WHERE slug = %s", $alt_slug ) );
				} while ( $slug_check );
				$slug = $alt_slug;
			}
		}
	}

	/**
	 * Filters the unique multisite term slug.
	 *
	 * @param string $slug          Unique multisite term slug.
	 * @param object $multisite_term          Multisite term object.
	 * @param string $original_slug Slug originally passed to the function for testing.
	 */
	return apply_filters( 'unique_multisite_term_slug', $slug, $multisite_term, $original_slug );
}

/**
 * Update multisite term based on arguments provided.
 *
 * The $args will indiscriminately override all values with the same field name.
 * Care must be taken to not override important information need to update or
 * update will fail (or perhaps create a new multisite term, neither would be acceptable).
 *
 * Defaults will set 'alias_of', 'description', 'parent', and 'slug' if not
 * defined in $args already.
 *
 * 'alias_of' will create a multisite term group, if it doesn't already exist, and update
 * it for the $multisite_term.
 *
 * If the 'slug' argument in $args is missing, then the 'name' in $args will be
 * used. It should also be noted that if you set 'slug' and it isn't unique then
 * a WP_Error will be passed back. If you don't pass any slug, then a unique one
 * will be created for you.
 *
 * For what can be overrode in `$args`, check the multisite term scheme can contain and stay
 * away from the multisite term keys.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int          $multisite_term_id  The ID of the multisite term.
 * @param string       $multisite_taxonomy The context in which to relate the multisite term to the object.
 * @param array|string $args     Optional. Array of get_multisite_terms() arguments. Default empty array.
 * @return array|WP_Error Returns Multisite term ID and multisite taxonomy multisite term ID
 */
function update_multisite_term( $multisite_term_id, $multisite_taxonomy, $args = array() ) {
	global $wpdb;

	// If the user cannot manage multisite terms then kick back.
	if ( ! current_user_can( 'manage_multisite_terms' ) ) {
		return new WP_Error( 'invalid_user_permissions', __( 'You do not have permissions to manage multisite terms.', 'multitaxo' ) );
	}

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
	}

	$multisite_term_id = (int) $multisite_term_id;

	// First, get all of the original args.
	$multisite_term = get_multisite_term( $multisite_term_id, $multisite_taxonomy );

	if ( is_wp_error( $multisite_term ) ) {
		return $multisite_term;
	}

	if ( ! $multisite_term ) {
		return new WP_Error( 'invalid_multisite_term', __( 'Empty multisite term', 'multitaxo' ) );
	}

	$multisite_term = (array) $multisite_term->data;

	// Escape data pulled from DB.
	$multisite_term = wp_slash( $multisite_term );

	// Merge old and new args with new args overwriting old ones.
	$args = array_merge( $multisite_term, $args );

	$defaults    = array(
		'alias_of'    => '',
		'description' => '',
		'parent'      => 0,
		'slug'        => '',
	);
	$args        = wp_parse_args( $args, $defaults );
	$args        = sanitize_multisite_term( $args, $multisite_taxonomy, 'db' );
	$parsed_args = $args;

	$name        = wp_unslash( $args['name'] );
	$description = wp_unslash( $args['description'] );

	$parsed_args['name']        = $name;
	$parsed_args['description'] = $description;

	if ( '' === trim( $name ) ) {
		return new WP_Error( 'empty_multisite_term_name', __( 'A name is required for this multisite term.', 'multitaxo' ) );
	}

	if ( $parsed_args['parent'] > 0 && ! multisite_term_exists( (int) $parsed_args['parent'] ) ) {
		return new WP_Error( 'missing_multisite_parent', __( 'Parent missing multisite term does not exist.', 'multitaxo' ) );
	}

	$empty_slug = false;
	if ( empty( $args['slug'] ) ) {
		$empty_slug = true;
		$slug       = sanitize_title( $name );
	} else {
		$slug = $args['slug'];
	}

	$parsed_args['slug'] = $slug;

	$multisite_term_group = isset( $parsed_args['multisite_term_group'] ) ? $parsed_args['multisite_term_group'] : 0;
	if ( $args['alias_of'] ) {
		$alias = get_multisite_term_by( 'slug', $args['alias_of'], $multisite_taxonomy );
		if ( ! empty( $alias->multisite_term_group ) ) {
			// The alias we want is already in a group, so let's use that one.
			$multisite_term_group = $alias->multisite_term_group;
		} elseif ( ! empty( $alias->multisite_term_id ) ) {
			/*
			 * The alias is not in a group, so we create a new one
			 * and add the alias to it.
			 */
			$multisite_term_group = $wpdb->get_var( "SELECT MAX(multisite_term_group) FROM $wpdb->multisite_terms" ) + 1;

			update_multisite_term(
				$alias->multisite_term_id,
				$multisite_taxonomy,
				array(
					'multisite_term_group' => $multisite_term_group,
				)
			);
		}

		$parsed_args['multisite_term_group'] = $multisite_term_group;
	}

	/**
	 * Filters the multisite term parent.
	 *
	 * Hook to this filter to see if it will cause a hierarchy loop.
	 *
	 * @param int    $parent      ID of the parent multisite term.
	 * @param int    $multisite_term_id     Multisite term ID.
	 * @param string $multisite_taxonomy    Multisite taxonomy slug.
	 * @param array  $parsed_args An array of potentially altered update arguments for the given multisite term.
	 * @param array  $args        An array of update arguments for the given multisite term.
	 */
	$parent = apply_filters( 'update_multisite_term_parent', $args['parent'], $multisite_term_id, $multisite_taxonomy, $parsed_args, $args );

	// Check for duplicate slug.
	$duplicate = get_multisite_term_by( 'slug', $slug, $multisite_taxonomy );
	if ( $duplicate && $duplicate->multisite_term_id !== $multisite_term_id ) {
		// If an empty slug was passed or the parent changed, reset the slug to something unique.
		// Otherwise, bail.
		if ( $empty_slug || ( $parent !== $multisite_term['parent'] ) ) {
			$slug = unique_multisite_term_slug( $slug, (object) $args );
		} else {
			/* translators: 1: Multisite taxonomy multisite term slug */
			return new WP_Error( 'duplicate_multisite_term_slug', sprintf( __( 'The slug &#8220;%s&#8221; is already in use by another multisite term', 'multitaxo' ), $slug ) );
		}
	}

	$mtmt_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_term_multisite_taxonomy AS tt INNER JOIN $wpdb->multisite_terms AS t ON tt.multisite_term_id = t.multisite_term_id WHERE tt.multisite_taxonomy = %s AND t.multisite_term_id = %d", $multisite_taxonomy, $multisite_term_id ) );

	/**
	 * Fires immediately before the given terms are edited.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edit_multisite_terms', $multisite_term_id, $multisite_taxonomy );

	$data = compact( 'name', 'slug', 'multisite_term_group' );

	/**
	 * Filters multisite term data before it is updated in the database.
	 *
	 * @param array  $data     Multisite term data to be updated.
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 * @param array  $args     Arguments passed to update_multisite_term().
	 */
	$data = apply_filters( 'update_multisite_term_data', $data, $multisite_term_id, $multisite_taxonomy, $args );

	$wpdb->update( $wpdb->multisite_terms, $data, compact( 'multisite_term_id' ) );
	if ( empty( $slug ) ) {
		$slug = sanitize_title( $name, $multisite_term_id );
		$wpdb->update( $wpdb->multisite_terms, compact( 'slug' ), compact( 'multisite_term_id' ) );
	}

	/**
	 * Fires immediately after the given terms are edited.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edited_multisite_terms', $multisite_term_id, $multisite_taxonomy );

	/**
	 * Fires immediate before a multisite term-taxonomy relationship is updated.
	 *
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edit_multisite_term_multisite_taxonomy', $mtmt_id, $multisite_taxonomy );

	$wpdb->update(
		$wpdb->multisite_term_multisite_taxonomy,
		compact( 'multisite_term_id', 'multisite_taxonomy', 'description', 'parent' ),
		array(
			'multisite_term_multisite_taxonomy_id' => $mtmt_id,
		)
	);

	/**
	 * Fires immediately after a multisite term-taxonomy relationship is updated.
	 *
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edited_multisite_term_multisite_taxonomy', $mtmt_id, $multisite_taxonomy );

	/**
	 * Fires after a multisite term has been updated, but before the multisite term cache has been cleaned.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edit_multisite_term', $multisite_term_id, $mtmt_id, $multisite_taxonomy );

	/**
	 * Fires after a multisite term in a specific multisite taxonomy has been updated, but before the multisite term
	 * cache has been cleaned.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the multisite taxonomy slug.
	 *
	 * @param int $multisite_term_id Multisite term ID.
	 * @param int $mtmt_id   Multisite term multisite taxonomy ID.
	 */
	do_action( "edit_multisite_{$multisite_taxonomy}", $multisite_term_id, $mtmt_id );

	$multisite_term_id = apply_filters( 'multisite_term_id_filter', $multisite_term_id, $mtmt_id );

	clean_multisite_term_cache( $multisite_term_id, $multisite_taxonomy );

	/**
	 * Fires after a multisite term has been updated, and the multisite term cache has been cleaned.
	 *
	 * @param int    $multisite_term_id  Multisite term ID.
	 * @param int    $mtmt_id    Multisite term multisite taxonomy ID.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	do_action( 'edited_multisite_term', $multisite_term_id, $mtmt_id, $multisite_taxonomy );

	/**
	 * Fires after a multisite term for a specific multisite taxonomy has been updated, and the multisite term
	 * cache has been cleaned.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the multisite taxonomy slug.
	 *
	 * @param int $multisite_term_id Multisite term ID.
	 * @param int $mtmt_id   Multisite term multisite taxonomy ID.
	 */
	do_action( "edited_multisite_{$multisite_taxonomy}", $multisite_term_id, $mtmt_id );

	return array(
		'multisite_term_id'                    => $multisite_term_id,
		'multisite_term_multisite_taxonomy_id' => $mtmt_id,
	);
}

/**
 * Updates the amount of multisite terms in multisite taxonomy.
 *
 * If there is a multisite taxonomy callback applied, then it will be called for updating
 * the count.
 *
 * The default action is to count what the amount of multisite terms have the relationship
 * of multisite term ID. Once that is done, then update the database.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param array  $multisite_terms       An array of multisite_term_multisite_taxonomy_ids.
 * @param string $multisite_taxonomy    The context of the multisite term.
 * @return bool If no terms will return false, and if successful will return true.
 */
function update_multisite_term_count( $multisite_terms, $multisite_taxonomy ) {

	if ( ! is_array( $multisite_terms ) && empty( $multisite_terms ) ) {
		return new WP_Error( 'invalid_multisite_terms_update_multisite_term_count', __( 'Function update_multisite_term_count() should be passed an array of multisite terms.', 'multitaxo' ) );
	}

	$multisite_terms = array_map( 'absint', $multisite_terms );

	$multisite_taxonomy = get_multisite_taxonomy( $multisite_taxonomy );
	// We allow the taxonomy to overide the way the count is calculated.
	if ( ! is_a( $multisite_taxonomy, 'Multisite_Taxonomy' ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
	} elseif ( ! empty( $multisite_taxonomy->update_count_callback ) ) {
		call_user_func( $multisite_taxonomy->update_count_callback, $multisite_terms, $multisite_taxonomy );
	} else {
		global $wpdb;
		foreach ( (array) $multisite_terms as $multisite_term ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->multisite_term_relationships WHERE multisite_term_multisite_taxonomy_id = %d", $multisite_term ) );

			do_action( 'edit_multisite_term_multisite_taxonomy', $multisite_term, $multisite_taxonomy->name );
			$wpdb->update(
				$wpdb->multisite_term_multisite_taxonomy,
				compact( 'count' ),
				array(
					'multisite_term_multisite_taxonomy_id' => $multisite_term,
				)
			);

			do_action( 'edited_multisite_term_multisite_taxonomy', $multisite_term, $multisite_taxonomy->name );
		}
	}

	clean_multisite_term_cache( $multisite_terms, '', false );

	return true;
}
/**
 * Add a new multsite term to the database if it does not already exist.
 *
 * @param int|string $multisite_term_name Multisite term name.
 * @param string     $multisite_taxonomy Optional. The multisite taxonomy for which to retrieve multisite terms.
 * @return int|array|WP_Error A term id if the term already exists within the taxonomy.
							If term does not exist, returns results of insert_multisite_term:
							an array containing the `multisite_term_id` and `multisite_term_multisite_taxonomy_id`,
 *                          WP_Error otherwise.
 */
function create_multisite_term( $multisite_term_name, $multisite_taxonomy ) {
	$id = multisite_term_exists( $multisite_term_name, $multisite_taxonomy );
	if ( is_numeric( $id ) ) {
		return $id;
	} elseif ( is_array( $id ) && is_numeric( $id['multisite_term_id'] ) ) {
		return $id['multisite_term_id'];
	}
	return insert_multisite_term( $multisite_term_name, $multisite_taxonomy );
}
