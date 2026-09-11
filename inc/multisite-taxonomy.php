<?php
/**
 * Multisite Taxonomy API
 *
 * @package multitaxo
 */

/**
 * Retrieves a list of registered multisite taxonomy names or objects.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param array  $args     Optional. An array of `key => value` arguments to match against the multisite taxonomy objects.
 *                         Default empty array.
 * @param string $output   Optional. The type of output to return in the array. Accepts either multisite taxonomy 'names'
 *                         or 'objects'. Default 'names'.
 * @param string $operator Optional. The logical operation to perform. Accepts 'and' or 'or'. 'or' means only
 *                         one element from the array needs to match; 'and' means all elements must match.
 *                         Default 'and'.
 * @return array A list of multisite taxonomy names or objects.
 */
function get_multisite_taxonomies( $args = array(), $output = 'names', $operator = 'and' ) {
	global $multisite_taxonomies;

	$field = ( 'names' === $output ) ? 'name' : false;

	return wp_filter_object_list( $multisite_taxonomies, $args, $operator, $field );
}

/**
 * Return the names or objects of the multisite taxonomies which are registered for the requested object or object type, such as
 * a post object or post type name.
 *
 * Example:
 *
 *     $multisite_taxonomies= get_object_multisite_taxonomies( 'post' );
 *
 * This results in:
 *
 *     Array( 'category', 'post_tag' )
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param array|string|WP_Post $object_type Name of the type of multisite taxonomy object, or an object (row from posts).
 * @param string               $output      Optional. The type of output to return in the array. Accepts either
 *                                          multisite taxonomy 'names' or 'objects'. Default 'names'.
 * @return array The names of all multisite taxonomy of $object_type.
 */
function get_object_multisite_taxonomies( $object_type, $output = 'names' ) {
	global $multisite_taxonomies;

	if ( is_object( $object_type ) ) {
		if ( 'attachment' === $object_type->post_type ) {
			return ''; // Currently don't support attachments.
		}
		$object_type = $object_type->post_type;
	}

	$object_type = (array) $object_type;

	$taxonomies = array();
	foreach ( (array) $multisite_taxonomies as $multi_tax_name => $multi_tax_obj ) {
		if ( array_intersect( $object_type, (array) $multi_tax_obj->object_type ) ) {
			if ( 'names' === $output ) {
				$taxonomies[] = $multi_tax_name;
			} else {
				$taxonomies[ $multi_tax_name ] = $multi_tax_obj;
			}
		}
	}

	return $taxonomies;
}

/**
 * Retrieves the multisite taxonomy object of $multisite_taxonomy.
 *
 * The get_multisite_taxonomy function will first check that the parameter string given
 * is a multisite taxonomy object and if it is, it will return it.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param string $multisite_taxonomy Name of multisite taxonomy object to return.
 * @return Multisite_Taxonomy|false The Multisite Taxonomy Object or false if $multisite_taxonomy doesn't exist.
 */
function get_multisite_taxonomy( $multisite_taxonomy ) {
	global $multisite_taxonomies;

	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return false;
	}

	return $multisite_taxonomies[ $multisite_taxonomy ];
}

/**
 * Resolve the capability required to assign a taxonomy's terms, per object namespace.
 *
 * A single multisite taxonomy can span several object types (e.g. one taxonomy applied to both
 * users and posts). The registered `assign_multisite_terms` capability is taxonomy-wide, but the
 * right gate can legitimately differ per namespace: assigning a term to a user record may be a
 * super-admin task while assigning the same taxonomy to a post is a normal author task. This
 * filterable resolver lets a taxonomy vary its assign capability by object type without forking
 * the taxonomy in two.
 *
 * @param string|Multisite_Taxonomy $taxonomy    Taxonomy name or object.
 * @param string                    $object_type Object namespace ('' = post, 'user', 'blog').
 * @return string The capability required to assign this taxonomy's terms for that object type.
 */
function get_multisite_taxonomy_assign_cap( $taxonomy, $object_type = '' ) {
	if ( ! is_object( $taxonomy ) ) {
		$taxonomy = get_multisite_taxonomy( $taxonomy );
	}

	$cap = ( $taxonomy && isset( $taxonomy->cap->assign_multisite_terms ) )
		? $taxonomy->cap->assign_multisite_terms
		: 'assign_multisite_terms';

	/**
	 * Filters the capability required to assign a taxonomy's terms, per object namespace.
	 *
	 * @param string                    $cap         The assign capability (taxonomy default).
	 * @param Multisite_Taxonomy|false  $taxonomy    The taxonomy object (false if unknown).
	 * @param string                    $object_type Object namespace ('' = post, 'user', 'blog').
	 */
	return apply_filters( 'multisite_taxonomy_assign_cap', $cap, $taxonomy, $object_type );
}

/**
 * Whether the current user may assign a taxonomy's terms for a given object namespace.
 *
 * Thin wrapper over {@see get_multisite_taxonomy_assign_cap()} so callers read declaratively.
 *
 * @param string|Multisite_Taxonomy $taxonomy    Taxonomy name or object.
 * @param string                    $object_type Object namespace ('' = post, 'user', 'blog').
 * @return bool Whether the current user may assign the taxonomy's terms for that object type.
 */
function current_user_can_assign_multisite_terms( $taxonomy, $object_type = '' ) {
	return current_user_can( get_multisite_taxonomy_assign_cap( $taxonomy, $object_type ) );
}

/**
 * Checks that the multisite taxonomy name exists.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param string $multisite_taxonomy Name of multisite taxonomy object.
 * @return bool Whether the multisite taxonomy exists.
 */
function multisite_taxonomy_exists( $multisite_taxonomy ) {
	global $multisite_taxonomies;

	return isset( $multisite_taxonomies[ $multisite_taxonomy ] );
}

/**
 * Whether the multisite taxonomy object is hierarchical.
 *
 * Checks to make sure that the multisite taxonomy is an object first. Then Gets the
 * object, and finally returns the hierarchical value in the object.
 *
 * A false return value might also mean that the multisite taxonomy does not exist.
 *
 * @param string $multisite_taxonomy Name of multisite taxonomy object.
 * @return bool Whether the multisite taxonomy is hierarchical.
 */
function is_multisite_taxonomy_hierarchical( $multisite_taxonomy ) {
	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return false;
	}
	$multisite_taxonomy = get_multisite_taxonomy( $multisite_taxonomy );
	return $multisite_taxonomy->hierarchical;
}

/**
 * Creates or modifies a multisite taxonomy object.
 *
 * Note: Do not use before the {@see 'init'} hook.
 *
 * A simple function for creating or modifying a multisite taxonomy object based on the
 * parameters given. The function will accept an array (third optional
 * parameter), along with strings for the multisite taxonomy name and another string for
 * the object type.
 *
 * @global array $multisite_taxonomies Registered multisite taxonomies.
 *
 * @param string       $multisite_taxonomy    Multisite Taxonomy key, must not exceed 32 characters.
 * @param array|string $object_type Object type or array of object types with which the multisite taxonomy
 *                                  should be associated. A single taxonomy may span several object types
 *                                  at once (e.g. array( 'post', 'user', 'blog' )).
 *
 *                                  These names map to an ID *namespace* in the relationship table, not to a
 *                                  WP post type. Recognized namespaces:
 *                                  - any post type (post, page, CPT) -> the post namespace, stored as ''.
 *                                    The literal string 'post' is never stored; posts are always ''.
 *                                  - 'user' -> wp_users.
 *                                  - 'blog' -> wp_blogs.
 *                                  Post-vs-page is not a namespace split (both are wp_posts rows); JOIN
 *                                  wp_posts and read post_type if you need to tell them apart. Defaults to
 *                                  array( 'post' ) when empty.
 * @param array|string $args        {
 *     Optional. Array or query string of arguments for registering a multisite taxonomy.
 *
 *     @type array         $labels                An array of labels for this multisite taxonomy. By default, Tag labels are
 *                                                used for non-hierarchical multisite taxonomies, and Category labels are used
 *                                                for hierarchical multisite taxonomies. See accepted values in
 *                                                get_multisite_taxonomy_labels(). Default empty array.
 *     @type string        $description           A short descriptive summary of what the multisite taxonomy is for. Default empty.
 *     @type bool          $public                Whether a multisite taxonomy is intended for use publicly either via
 *                                                the admin interface or by front-end users. The default settings
 *                                                of `$publicly_queryable`, `$show_ui`, and `$show_in_nav_menus`
 *                                                are inherited from `$public`.
 *     @type bool          $publicly_queryable    Whether the multisite taxonomy is publicly queryable.
 *                                                If not set, the default is inherited from `$public`
 *     @type bool          $hierarchical          Whether the multisite taxonomy is hierarchical. Default false.
 *     @type bool          $show_ui               Whether to generate and allow a UI for managing terms in this multisite taxonomy in
 *                                                the admin. If not set, the default is inherited from `$public`
 *                                                (default true).
 *     @type bool          $show_in_menu          Whether to show the multisite taxonomy in the admin menu. If true, the multisite taxonomy is
 *                                                shown as a submenu of the object type menu. If false, no menu is shown.
 *                                                `$show_ui` must be true. If not set, default is inherited from `$show_ui`
 *                                                (default true).
 *     @type bool          $show_in_nav_menus     Makes this multisite taxonomy available for selection in navigation menus. If not
 *                                                set, the default is inherited from `$public` (default true).
 *     @type bool          $show_in_rest          Whether to include the multisite taxonomy in the REST API.
 *     @type string        $rest_base             To change the base url of REST API route. Default is $multisite_taxonomy.
 *     @type string        $rest_controller_class REST API Controller class name. Default is 'WP_REST_Terms_Controller'.
 *     @type bool          $show_multisite_terms_cloud         Whether to list the multisite taxonomy in the Tag Cloud Widget controls. If not set,
 *                                                the default is inherited from `$show_ui` (default true).
 *     @type bool          $show_in_quick_edit    Whether to show the multisite taxonomy in the quick/bulk edit panel. It not set,
 *                                                the default is inherited from `$show_ui` (default true).
 *     @type bool          $show_admin_column     Whether to display a column for the multisite taxonomy on its post type listing
 *                                                screens. Default false.
 *     @type bool|callable $meta_box_cb           Provide a callback function for the meta box display. If not set,
 *                                                post_categories_meta_box() is used for hierarchical taxonomies, and
 *                                                post_tags_meta_box() is used for non-hierarchical. If false, no meta
 *                                                box is shown.
 *     @type array         $capabilities {
 *         Array of capabilities for this multisite taxonomy.
 *
 *         @type string $manage_terms Default 'manage_categories'.
 *         @type string $edit_multisite_terms   Default 'manage_categories'.
 *         @type string $delete_multisite_terms Default 'manage_categories'.
 *         @type string $assign_terms Default 'edit_posts'.
 *     }
 *     @type bool|array    $rewrite {
 *         Triggers the handling of rewrites for this multisite taxonomy. Default true, using $multisite_taxonomy as slug. To prevent
 *         rewrite, set to false. To specify rewrite rules, an array can be passed with any of these keys:
 *
 *         @type string $slug         Customize the permastruct slug. Default `$multisite_taxonomy` key.
 *         @type bool   $with_front   Should the permastruct be prepended with WP_Rewrite::$front. Default true.
 *         @type bool   $hierarchical Either hierarchical rewrite tag or not. Default false.
 *         @type int    $ep_mask      Assign an endpoint mask. Default `EP_NONE`.
 *     }
 *     @type string        $query_var             Sets the query var key for this multisite taxonomy. Default `$multisite_taxonomy` key. If
 *                                                false, a multisite taxonomy cannot be loaded at `?{query_var}={term_slug}`. If a
 *                                                string, the query `?{query_var}={term_slug}` will be valid.
 *     @type callable      $update_count_callback Works much like a hook, in that it will be called when the count is
 *                                                updated. Default update_multisite_term_count().
 * }
 * @return Multisite_Taxonomy|WP_Error The registered taxonomy object on success, WP_Error object on failure.
 */
function register_multisite_taxonomy( $multisite_taxonomy, $object_type, $args = array() ) {
	global $multisite_taxonomies;

	if ( ! is_array( $multisite_taxonomies ) ) {
		$multisite_taxonomies = array();
	}

	$args = wp_parse_args( $args );

	if ( empty( $multisite_taxonomy ) || strlen( $multisite_taxonomy ) > 32 ) {
		return new WP_Error( 'multisite_taxonomy_length_invalid', __( 'Multisite taxonomy names must be between 1 and 32 characters in length.', 'multitaxo' ) );
	}

	$multisite_taxonomy_object = new Multisite_Taxonomy( $multisite_taxonomy, $object_type, $args );
	$multisite_taxonomy_object->add_rewrite_rules();

	$multisite_taxonomies[ $multisite_taxonomy ] = $multisite_taxonomy_object;

	$multisite_taxonomy_object->add_hooks();

	/**
	 * Fires after a multisite taxonomy is registered.
	 *
	 * @param string       $multisite_taxonomy    Multisite taxonomy slug.
	 * @param array|string $object_type Object type or array of object types.
	 * @param array        $args        Array of multisite taxonomy registration arguments.
	 */
	do_action( 'registered_multisite_taxonomy', $multisite_taxonomy, $object_type, (array) $multisite_taxonomy_object );

	return $multisite_taxonomy_object;
}

/**
 * Unregisters a multisite taxonomy.
 *
 * @global WP    $wp            Current WordPress environment instance.
 * @global array $multisite_taxonomies List of multisite taxonomies.
 *
 * @param string $multisite_taxonomy Multisite taxonomy name.
 * @return bool|WP_Error True on success, WP_Error on failure or if the multisite taxonomy doesn't exist.
 */
function unregister_multisite_taxonomy( $multisite_taxonomy ) {
	if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
	}

	$multisite_taxonomy_object = get_multisite_taxonomy( $multisite_taxonomy );

	global $multisite_taxonomies;

	$multisite_taxonomy_object->remove_rewrite_rules();
	$multisite_taxonomy_object->remove_hooks();

	// Remove the taxonomy.
	unset( $multisite_taxonomies[ $multisite_taxonomy ] );

	/**
	 * Fires after a multisite taxonomy is unregistered.
	 *
	 * @param string $multisite_taxonomy Multisite taxonomy name.
	 */
	do_action( 'unregistered_multisite_taxonomy', $multisite_taxonomy );

	return true;
}

/**
 * Builds an object with all multisite taxonomy labels out of a multisite taxonomy object
 *
 * Accepted keys of the label array in the multisite taxonomy object:
 *
 * - name - general name for the multisite taxonomy, usually plural. The same as and overridden by $multisite_taxonomy->label. Default is Tags/Categories
 * - singular_name - name for one object of this taxonomy. Default is Tag/Category
 * - search_items - Default is Search Tags/Search Categories
 * - popular_items - This string isn't used on hierarchical taxonomies. Default is Popular Tags
 * - all_items - Default is All Tags/All Categories
 * - parent_item - This string isn't used on non-hierarchical taxonomies. In hierarchical ones the default is Parent Category
 * - parent_item_colon - The same as `parent_item`, but with colon `:` in the end
 * - edit_item - Default is Edit Tag/Edit Category
 * - view_item - Default is View Tag/View Category
 * - update_item - Default is Update Tag/Update Category
 * - add_new_item - Default is Add New Tag/Add New Category
 * - new_item_name - Default is New Tag Name/New Category Name
 * - separate_items_with_commas - This string isn't used on hierarchical taxonomies. Default is "Separate tags with commas", used in the meta box.
 * - add_or_remove_items - This string isn't used on hierarchical taxonomies. Default is "Add or remove tags", used in the meta box when JavaScript is disabled.
 * - choose_from_most_used - This string isn't used on hierarchical taxonomies. Default is "Choose from the most used tags", used in the meta box.
 * - not_found - Default is "No tags found"/"No categories found", used in the meta box and multisite taxonomy list table.
 * - no_terms - Default is "No tags"/"No categories", used in the posts and media list tables.
 * - items_list_navigation - String for the table pagination hidden heading.
 * - items_list - String for the table hidden heading.
 *
 * Above, the first default value is for non-hierarchical multisite taxonomies (like tags) and the second one is for hierarchical multisite taxonomies (like categories).
 *
 * @param Multisite_Taxonomy $multisite_taxonomy Multisite taxonomy object.
 * @return object object with all the labels as member variables.
 */
function get_multisite_taxonomy_labels( $multisite_taxonomy ) {
	$multisite_taxonomy->labels = (array) $multisite_taxonomy->labels;

	if ( isset( $multisite_taxonomy->helps ) && empty( $multisite_taxonomy->labels['separate_items_with_commas'] ) ) {
		$multisite_taxonomy->labels['separate_items_with_commas'] = $multisite_taxonomy->helps;
	}

	if ( isset( $multisite_taxonomy->no_multisite_terms_cloud ) && empty( $multisite_taxonomy->labels['not_found'] ) ) {
		$multisite_taxonomy->labels['not_found'] = $multisite_taxonomy->no_multisite_terms_cloud;
	}

	$nohier_vs_hier_defaults              = array(
		'name'                       => array( _x( 'Tags', 'taxonomy general name', 'multitaxo' ), _x( 'Categories', 'taxonomy general name', 'multitaxo' ) ),
		'singular_name'              => array( _x( 'Tag', 'taxonomy singular name', 'multitaxo' ), _x( 'Category', 'taxonomy singular name', 'multitaxo' ) ),
		'search_items'               => array( __( 'Search Tags', 'multitaxo' ), __( 'Search Categories', 'multitaxo' ) ),
		'popular_items'              => array( __( 'Popular Tags', 'multitaxo' ), null ),
		'all_items'                  => array( __( 'All Tags', 'multitaxo' ), __( 'All Categories', 'multitaxo' ) ),
		'parent_item'                => array( null, __( 'Parent Category', 'multitaxo' ) ),
		'parent_item_colon'          => array( null, __( 'Parent Category:', 'multitaxo' ) ),
		'edit_item'                  => array( __( 'Edit Tag', 'multitaxo' ), __( 'Edit Category', 'multitaxo' ) ),
		'view_item'                  => array( __( 'View Tag', 'multitaxo' ), __( 'View Category', 'multitaxo' ) ),
		'update_item'                => array( __( 'Update Tag', 'multitaxo' ), __( 'Update Category', 'multitaxo' ) ),
		'add_new_item'               => array( __( 'Add New Tag', 'multitaxo' ), __( 'Add New Category', 'multitaxo' ) ),
		'new_item_name'              => array( __( 'New Tag Name', 'multitaxo' ), __( 'New Category Name', 'multitaxo' ) ),
		'separate_items_with_commas' => array( __( 'Separate tags with commas', 'multitaxo' ), null ),
		'add_or_remove_items'        => array( __( 'Add or remove tags', 'multitaxo' ), null ),
		'choose_from_most_used'      => array( __( 'Choose from the most used tags', 'multitaxo' ), null ),
		'not_found'                  => array( __( 'No tags found.', 'multitaxo' ), __( 'No categories found.', 'multitaxo' ) ),
		'no_terms'                   => array( __( 'No tags', 'multitaxo' ), __( 'No categories', 'multitaxo' ) ),
		'items_list_navigation'      => array( __( 'Tags list navigation', 'multitaxo' ), __( 'Categories list navigation', 'multitaxo' ) ),
		'items_list'                 => array( __( 'Tags list', 'multitaxo' ), __( 'Categories list', 'multitaxo' ) ),
	);
	$nohier_vs_hier_defaults['menu_name'] = $nohier_vs_hier_defaults['name'];

	$labels = _get_custom_object_labels( $multisite_taxonomy, $nohier_vs_hier_defaults );

	$multisite_taxonomy = $multisite_taxonomy->name;

	$default_labels = clone $labels;

	/**
	 * Filters the labels of a specific multisite taxonomy.
	 *
	 * The dynamic portion of the hook name, `$multisite_taxonomy`, refers to the multisite taxonomy slug.
	 *
	 * @see get_multisite_taxonomy_labels() for the full list of multisite taxonomy labels.
	 *
	 * @param object $labels Object with labels for the multisite taxonomy as member variables.
	 */
	$labels = apply_filters( "multisite_taxonomy_labels_{$multisite_taxonomy}", $labels );

	// Ensure that the filtered labels contain all required default values.
	$labels = (object) array_merge( (array) $default_labels, (array) $labels );

	return $labels;
}

/**
 * Add an already registered multisite taxonomy to an object type.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param string $multisite_taxonomy    Name of multisite taxonomy object.
 * @param string $object_type Name of the object type.
 * @return bool True if successful, false if not.
 */
function register_multisite_taxonomy_for_object_type( $multisite_taxonomy, $object_type ) {
	global $multisite_taxonomies;

	if ( ! isset( $multisite_taxonomies[ $multisite_taxonomy ] ) ) {
		return false;
	}

	if ( ! in_array( $object_type, array( 'user', 'blog' ), true ) && ! get_post_type_object( $object_type ) ) {
		return false;
	}

	if ( ! in_array( $object_type, $multisite_taxonomies[ $multisite_taxonomy ]->object_type, true ) ) {
		$multisite_taxonomies[ $multisite_taxonomy ]->object_type[] = $object_type;
	}

	// Filter out empties.
	$multisite_taxonomies[ $multisite_taxonomy ]->object_type = array_filter( $multisite_taxonomies[ $multisite_taxonomy ]->object_type );

	return true;
}

/**
 * Remove an already registered multisite taxonomy from an object type.
 *
 * @global array $multisite_taxonomies The registered multisite taxonomies.
 *
 * @param string $multisite_taxonomy    Name of multisite taxonomy object.
 * @param string $object_type Name of the object type.
 * @return bool True if successful, false if not.
 */
function unregister_multisite_taxonomy_for_object_type( $multisite_taxonomy, $object_type ) {
	global $multisite_taxonomies;

	if ( ! isset( $multisite_taxonomies[ $multisite_taxonomy ] ) ) {
		return false;
	}

	if ( ! in_array( $object_type, array( 'user', 'blog' ), true ) && ! get_post_type_object( $object_type ) ) {
		return false;
	}

	$key = array_search( $object_type, $multisite_taxonomies[ $multisite_taxonomy ]->object_type, true );
	if ( false === $key ) {
		return false;
	}

	unset( $multisite_taxonomies[ $multisite_taxonomy ]->object_type[ $key ] );
	return true;
}

/**
 * Multisite Term API
 */

/**
 * Given a multisite taxonomy query, generates SQL to be appended to a main query.
 *
 * @see Multisite_Taxonomy_Query
 *
 * @param array  $multisite_taxonomy_query A compact multisite tax query.
 * @param string $primary_table The primary table.
 * @param string $primary_id_column The primary id column.
 * @return array
 */
function get_multisite_tax_sql( $multisite_taxonomy_query, $primary_table, $primary_id_column ) {
	$multisite_taxonomy_query_obj = new Multisite_Taxonomy_Query( $multisite_taxonomy_query );
	return $multisite_taxonomy_query_obj->get_sql( $primary_table, $primary_id_column );
}

/**
 * Get all Multisite Term data from database by Multisite term ID.
 *
 * The usage of the get_multisite_term function is to apply filters to a multisite term object. It
 * is possible to get a multisite term object from the database before applying the
 * filters.
 *
 * $multisite_term ID must be part of $multisite_taxonomy, to get from the database. Failure, might
 * be able to be captured by the hooks. Failure would be the same value as $wpdb
 * returns for the get_row method.
 *
 * There are two hooks, one is specifically for each term, named 'get_multisite_term', and
 * the second is for the multisite taxonomy name, 'term_$multisite_taxonomy'. Both hooks gets the
 * multisite term object, and the multisite taxonomy name as parameters. Both hooks are expected to
 * return a Multisite Term object.
 *
 * {@see 'get_multisite_term'} hook - Takes two parameters the multisite term Object and the multisite taxonomy name.
 * Must return multisite term object. Used in get_multisite_term() as a catch-all filter for every
 * $multisite_term.
 *
 * {@see 'get_$multisite_taxonomy'} hook - Takes two parameters the multisite term Object and the multisite taxonomy
 * name. Must return multisite term object. $multisite_taxonomy will be the multisite taxonomy name, so for
 * example, if 'category', it would be 'get_multisite_category' as the filter name. Useful
 * for custom multisite taxonomies or plugging into default multisite taxonomies.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @see sanitize_multisite_term_field() The $context param lists the available values for get_multisite_term_by() $filter param.
 *
 * @param int|Multisite_Term|object $multisite_term If integer, multisite term data will be fetched from the database, or from the cache if
 *                                 available. If stdClass object (as in the results of a database query), will apply
 *                                 filters and return a `Multisite_Term` object corresponding to the `$multisite_term` data. If `Multisite_Term`,
 *                                 will return `$multisite_term`.
 * @param string                    $multisite_taxonomy Optional. Multisite taxonomy name that $multisite_term is part of.
 * @param string                    $output   Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N, which correspond to
 *                             a Multisite_Term object, an associative array, or a numeric array, respectively. Default OBJECT.
 * @param string                    $filter   Optional, default is raw or no WordPress defined filter will applied.
 * @return array|Multisite_Term|WP_Error|null Object of the type specified by `$output` on success. When `$output` is 'OBJECT',
 *                                     a Multisite_Term instance is returned. If multisite taxonomy does not exist, a WP_Error is
 *                                     returned. Returns null for miscellaneous failure.
 */
function get_multisite_term( $multisite_term, $multisite_taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
	if ( empty( $multisite_term ) ) {
		return new WP_Error( 'invalid_term', __( 'Empty Term', 'multitaxo' ) );
	}

	if ( $multisite_taxonomy && ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return new WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'multitaxo' ) );
	}

	if ( $multisite_term instanceof Multisite_Term ) {
		$_multisite_term = $multisite_term;
	} elseif ( is_object( $multisite_term ) ) {
		if ( empty( $multisite_term->filter ) || 'raw' === $multisite_term->filter ) {
			$_multisite_term = sanitize_multisite_term( $multisite_term, $multisite_taxonomy, 'raw' );
			$_multisite_term = new Multisite_Term( $_multisite_term );
		} else {
			$_multisite_term = Multisite_Term::get_instance( $multisite_term->multisite_term_id );
		}
	} else {
		$_multisite_term = Multisite_Term::get_instance( $multisite_term, $multisite_taxonomy );
	}

	if ( is_wp_error( $_multisite_term ) ) {
		return $_multisite_term;
	} elseif ( ! $_multisite_term ) {
		return null;
	}

	/**
	 * Filters a multisite term.
	 *
	 * @param int|Multisite_Term $_multisite_term    Multisite Term object or ID.
	 * @param string      $multisite_taxonomy The multisite taxonomy slug.
	 */
	$_multisite_term = apply_filters( 'get_multisite_term', $_multisite_term, $multisite_taxonomy );

	/**
	 * Filters a multisite taxonomy.
	 *
	 * The dynamic portion of the filter name, `$multisite_taxonomy`, refers
	 * to the multisite taxonomy slug.
	 *
	 * @param int|Multisite_Term $_multisite_term    Multisite Term object or ID.
	 * @param string      $multisite_taxonomy The multisite taxonomy slug.
	 */
	$_multisite_term = apply_filters( "get_multisite_{$multisite_taxonomy}", $_multisite_term, $multisite_taxonomy );

	// Bail if a filter callback has changed the type of the `$_multisite_term` object.
	if ( ! ( $_multisite_term instanceof Multisite_Term ) ) {
		return $_multisite_term;
	}

	// Sanitize term, according to the specified filter.
	$_multisite_term->filter( $filter );

	if ( ARRAY_A === $output ) {
		return $_multisite_term->to_array();
	} elseif ( ARRAY_N === $output ) {
		return array_values( $_multisite_term->to_array() );
	}

	return $_multisite_term;
}

/**
 * Get all Multisite Term data from database by Multisite Term field and data.
 *
 * Warning: $value is not escaped for 'name' $field. You must do it yourself, if
 * required.
 *
 * The default $field is 'id', therefore it is possible to also use null for
 * field, but not recommended that you do so.
 *
 * If $value does not exist, the return value will be false. If $multisite_taxonomy exists
 * and $field and $value combinations exist, the Multisite Term will be returned.
 *
 * This function will always return the first term that matches the `$field`-
 * `$value`-`$multisite_taxonomy` combination specified in the parameters. If your query
 * is likely to match more than one term (as is likely to be the case when
 * `$field` is 'name', for example), consider using get_multisite_terms() instead; that
 * way, you will get all matching multisite terms, and can provide your own logic for
 * deciding which one was intended.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @see sanitize_multisite_term_field() The $context param lists the available values for get_multisite_term_by() $filter param.
 *
 * @param string     $field    Either 'slug', 'name', 'id' (multisite_term_id), or 'multisite_term_multisite_taxonomy_id'.
 * @param string|int $value    Search for this multisite term value.
 * @param string     $multisite_taxonomy Multisite Taxonomy name. Optional, if `$field` is 'multisite_term_multisite_taxonomy_id'.
 * @param string     $output   Optional. The required return type. One of OBJECT, ARRAY_A, or ARRAY_N, which correspond to
 *                             a Multisite_Term object, an associative array, or a numeric array, respectively. Default OBJECT.
 * @param string     $filter   Optional, default is raw or no WordPress defined filter will applied.
 * @return Multisite_Term|array|false Multisite_Term instance (or array) on success. Will return false if `$multisite_taxonomy` does not exist
 *                             or `$multisite_term` was not found.
 */
function get_multisite_term_by( $field, $value, $multisite_taxonomy = '', $output = OBJECT, $filter = 'raw' ) {
	global $wpdb;

	// 'multisite_term_multisite_taxonomy_id' lookups don't require multisite taxonomy checks.
	if ( 'multisite_term_multisite_taxonomy_id' !== $field && ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
		return false;
	}

	$multisite_tax_clause = $wpdb->prepare( 'AND tt.multisite_taxonomy = %s', $multisite_taxonomy );

	if ( 'slug' === $field ) {
		$_field = 't.slug';
		$value  = sanitize_title( $value );
		if ( empty( $value ) ) {
			return false;
		}
	} elseif ( 'name' === $field ) {
		// Assume already escaped.
		$value  = wp_unslash( $value );
		$_field = 't.name';
	} elseif ( 'multisite_term_multisite_taxonomy_id' === $field ) {
		$value  = (int) $value;
		$_field = 'tt.multisite_term_multisite_taxonomy_id';
		// No `multisite_taxonomy` clause when searching by 'multisite_term_multisite_taxonomy_id'.
		$multisite_tax_clause = '';
	} else {
		$multisite_term = get_multisite_term( (int) $value, $multisite_taxonomy, $output, $filter );
		if ( is_wp_error( $multisite_term ) || is_null( $multisite_term ) ) {
			$multisite_term = false;
		}
		return $multisite_term;
	}

	$multisite_term = $wpdb->get_row( $wpdb->prepare( "SELECT t.*, tt.* FROM $wpdb->multisite_terms AS t INNER JOIN $wpdb->multisite_term_multisite_taxonomy AS tt ON t.multisite_term_id = tt.multisite_term_id WHERE $_field = %s", $value ) . " $multisite_tax_clause LIMIT 1" );
	if ( ! $multisite_term ) {
		return false;
	}

	// In the case of 'multisite_term_multisite_taxonomy_id', override the provided `$multisite_taxonomy` with whatever we find in the db.
	if ( 'multisite_term_multisite_taxonomy_id' === $field ) {
		$multisite_taxonomy = $multisite_term->multisite_taxonomy;
	}

	wp_cache_add( $multisite_term->multisite_term_id, $multisite_term, 'multisite_terms' );

	return get_multisite_term( $multisite_term, $multisite_taxonomy, $output, $filter );
}

/**
 * Get sanitized Multisite Term field.
 *
 * The function is for contextual reasons and for simplicity of usage.
 *
 * @see sanitize_multisite_term_field()
 *
 * @param string             $field    Multisite Term field to fetch.
 * @param int|Multisite_Term $multisite_term     Multisite term ID or object.
 * @param string             $multisite_taxonomy Optional. Multisite Taxonomy Name. Default empty.
 * @param string             $context  Optional, default is display. Look at sanitize_multisite_term_field() for available options.
 * @return string|int|null|WP_Error Will return an empty string if $multisite_term is not an object or if $field is not set in $multisite_term.
 */
function get_multisite_term_field( $field, $multisite_term, $multisite_taxonomy = '', $context = 'display' ) {
	$multisite_term = get_multisite_term( $multisite_term, $multisite_taxonomy );
	if ( is_wp_error( $multisite_term ) ) {
		return $multisite_term;
	}
	if ( ! is_object( $multisite_term ) ) {
		return '';
	}
	if ( ! isset( $multisite_term->$field ) ) {
		return '';
	}
	return sanitize_multisite_term_field( $field, $multisite_term->$field, $multisite_term->multisite_term_id, $multisite_term->multisite_taxonomy, $context );
}

/**
 * Sanitizes Multisite Term for editing.
 *
 * Return value is sanitize_multisite_term() and usage is for sanitizing the multisite term for
 * editing. Function is for contextual and simplicity.
 *
 * @param int|object $id       Multisite term ID or object.
 * @param string     $multisite_taxonomy Multisite Taxonomy name.
 * @return string|int|null|WP_Error Will return empty string if $multisite_term is not an object.
 */
function get_multisite_term_to_edit( $id, $multisite_taxonomy ) {
	$multisite_term = get_multisite_term( $id, $multisite_taxonomy );

	if ( is_wp_error( $multisite_term ) ) {
		return $multisite_term;
	}
	if ( ! is_object( $multisite_term ) ) {
		return '';
	}
	return sanitize_multisite_term( $multisite_term, $multisite_taxonomy, 'edit' );
}

/**
 * Retrieve the multisite terms in a given multisite taxonomy or list of multisite taxonomies.
 *
 * You can fully inject any customizations to the query before it is sent, as
 * well as control the output with a filter.
 *
 * The {@see 'get_multisite_terms'} filter will be called when the cache has the multisite term and will
 * pass the found multisite term along with the array of $multisite_taxonomies and array of $args.
 * This filter is also called before the array of multisite terms is passed and will pass
 * the array of multisite terms, along with the $multisite_taxonomies and $args.
 *
 * The {@see 'list_multisite_terms_exclusions'} filter passes the compiled exclusions along with
 * the $args.
 *
 * The {@see 'get_multisite_terms_orderby'} filter passes the `ORDER BY` clause for the query
 * along with the $args array.
 *
 * @global wpdb  $wpdb WordPress database abstraction object.
 * @global array $wp_filter
 *
 * @param array|string $args {
 *     Optional. Array or string of arguments to get multisite terms. A bare taxonomy slug (or array
 *     of slugs) is also accepted, as in core's get_terms().
 *
 *     @type string|array $multisite_taxonomy     Multisite taxonomy name, or array of multisite taxonomies, to which results should
 *                                                be limited.
 *     @type string       $orderby                Field(s) to order terms by. Accepts multisite term fields ('name', 'slug',
 *                                                'multisite_term_group', 'multisite_term_id', 'id', 'description'), 'count' for multisite term
 *                                                multisite taxonomy count, 'include' to match the 'order' of the $include param,
 *                                                'meta_value', 'meta_value_num', the value of `$meta_key`, the array
 *                                                keys of `$meta_query`, or 'none' to omit the ORDER BY clause.
 *                                                Defaults to 'name'.
 *     @type string       $order                  Whether to order terms in ascending or descending order.
 *                                                Accepts 'ASC' (ascending) or 'DESC' (descending).
 *                                                Default 'ASC'.
 *     @type bool|int     $hide_empty             Whether to hide terms not assigned to any posts. Accepts
 *                                                1|true or 0|false. Default 1|true.
 *     @type array|string $include                Array or comma/space-separated string of multisite term ids to include.
 *                                                Default empty array.
 *     @type array|string $exclude                Array or comma/space-separated string of multisite term ids to exclude.
 *                                                If $include is non-empty, $exclude is ignored.
 *                                                Default empty array.
 *     @type array|string $exclude_tree           Array or comma/space-separated string of multisite term ids to exclude
 *                                                along with all of their descendant multisite terms. If $include is
 *                                                non-empty, $exclude_tree is ignored. Default empty array.
 *     @type int|string   $number                 Maximum number of multisite terms to return. Accepts ''|0 (all) or any
 *                                                positive number. Default ''|0 (all).
 *     @type int          $offset                 The number by which to offset the multisite terms query. Default empty.
 *     @type string       $fields                 Multisite Term fields to query for. Accepts 'all' (returns an array of complete
 *                                                multisite term objects), 'ids' (returns an array of ids), 'id=>parent' (returns
 *                                                an associative array with ids as keys, parent multisite term IDs as values),
 *                                                'names' (returns an array of multisite term names), 'count' (returns the number
 *                                                of matching multisite terms), 'id=>name' (returns an associative array with ids
 *                                                as keys, multisite term names as values), or 'id=>slug' (returns an associative
 *                                                array with ids as keys, multisite term slugs as values). Default 'all'.
 *     @type string|array $name                   Optional. Name or array of names to return multisite term(s) for. Default empty.
 *     @type string|array $slug                   Optional. Slug or array of slugs to return multisite term(s) for. Default empty.
 *     @type bool         $hierarchical           Whether to include multisite terms that have non-empty descendants (even
 *                                                if $hide_empty is set to true). Default true.
 *     @type string       $search                 Search criteria to match multisite terms. Will be SQL-formatted with
 *                                                wildcards before and after. Default empty.
 *     @type string       $name__like             Retrieve multisite terms with criteria by which a multisite term is LIKE $name__like.
 *                                                Default empty.
 *     @type string       $description__like      Retrieve multisite terms where the description is LIKE $description__like.
 *                                                Default empty.
 *     @type bool         $pad_counts             Whether to pad the quantity of a multisite term's children in the quantity
 *                                                of each multisite term's "count" object variable. Default false.
 *     @type string       $get                    Whether to return multisite terms regardless of ancestry or whether the multisite terms
 *                                                are empty. Accepts 'all' or empty (disabled). Default empty.
 *     @type int          $child_of               Multisite term ID to retrieve child multisite terms of. If multiple multisite taxonomies
 *                                                are passed, $child_of is ignored. Default 0.
 *     @type int|string   $parent                 Parent multisite term ID to retrieve direct-child multisite terms of. Default empty.
 *     @type bool         $childless              True to limit results to multisite terms that have no children. This parameter
 *                                                has no effect on non-hierarchical multisite taxonomies. Default false.
 *     @type string       $cache_domain           Unique cache key to be produced when this query is stored in an
 *                                                object cache. Default is 'core'.
 *     @type bool         $update_multisite_term_meta_cache Whether to prime meta caches for matched multisite terms. Default true.
 *     @type array        $meta_query             Meta query clauses to limit retrieved multisite terms by.
 *                                                See `WP_Meta_Query`. Default empty.
 *     @type string       $meta_key               Limit multisite terms to those matching a specific metadata key. Can be used in
 *                                                conjunction with `$meta_value`.
 *     @type string       $meta_value             Limit multisite terms to those matching a specific metadata value. Usually used
 *                                                in conjunction with `$meta_key`.
 * }
 * @param array|string $deprecated Optional. Argument array, when using the legacy
 *                                 `( $taxonomy, $args )` parameter format. Default empty.
 * @return array|int|WP_Error List of Multisite_Term instances and their children. Will return WP_Error, if any of $multisite_taxonomies
 *                            do not exist.
 */
function get_multisite_terms( $args = array(), $deprecated = '' ) {
	global $wpdb;

	$multisite_term_query = new Multisite_Term_Query();

	$defaults = array(
		'suppress_filter' => false,
		'taxonomy'        => array(),
	);

	$args = Multisite_Term_Query::normalize_query_args( $args );

	// Legacy signature, as in core get_terms(): ( $taxonomy ) or ( $taxonomy, $args ). Recognized by
	// the absence of any known query var, so a plain slug does not end up querying every taxonomy.
	$_args = wp_parse_args( $args );

	if ( $deprecated || ! array_intersect_key( $multisite_term_query->query_var_defaults, (array) $_args ) ) {
		$taxonomies       = (array) $args;
		$args             = Multisite_Term_Query::normalize_query_args( wp_parse_args( $deprecated ) );
		$args['taxonomy'] = $taxonomies;
	} else {
		$args = $_args;
	}

	$args = wp_parse_args( $args, $defaults );

	if ( isset( $args['taxonomy'] ) && null !== $args['taxonomy'] ) {
		$args['taxonomy'] = (array) $args['taxonomy'];
	}

	if ( ! empty( $args['taxonomy'] ) ) {
		foreach ( $args['taxonomy'] as $taxonomy ) {
			if ( ! multisite_taxonomy_exists( $taxonomy ) ) {
				return new WP_Error( 'invalid_taxonomy', __( 'Invalid taxonomy.', 'multitaxo' ) );
			}
		}
	}

	// Don't pass suppress_filter to WP_Term_Query.
	$suppress_filter = $args['suppress_filter'];
	unset( $args['suppress_filter'] );

	$multisite_terms = $multisite_term_query->query( $args );

	// Count queries are not filtered, for legacy reasons.
	if ( ! is_array( $multisite_terms ) ) {
		return $multisite_terms;
	}

	if ( $suppress_filter ) {
		return $multisite_terms;
	}

	/**
	 * Filters the found terms.
	 *
	 * @since 2.3.0
	 * @since 4.6.0 Added the `$term_query` parameter.
	 *
	 * @param array         $terms      Array of found terms.
	 * @param array         $taxonomies An array of taxonomies.
	 * @param array         $args       An array of get_multisite_terms() arguments.
	 * @param WP_Term_Query $term_query The WP_Term_Query object.
	 */
	return apply_filters( 'get_multisite_terms', $multisite_terms, $multisite_term_query->query_vars['taxonomy'], $multisite_term_query->query_vars, $multisite_term_query );
}

/**
 * Check if Multisite Term exists.
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 *
 * @param int|string $multisite_term     The multisite term to check. Accepts multisite term ID, slug, or name.
 * @param string     $multisite_taxonomy The multisite taxonomy name to use.
 * @param int        $parent_term Optional. ID of parent multisite term under which to confine the exists search.
 * @return mixed Returns null if the multisite term does not exist. Returns the multisite term ID
 *               if no multisite taxonomy is specified and the multisite term ID exists. Returns
 *               an array of the multisite term ID and the multisite term multisite taxonomy ID the multisite taxonomy
 *               is specified and the pairing exists.
 */
function multisite_term_exists( $multisite_term, $multisite_taxonomy = '', $parent_term = null ) {
	global $wpdb;

	$select     = "SELECT multisite_term_id FROM $wpdb->multisite_terms as t WHERE ";
	$tax_select = "SELECT tt.multisite_term_id, tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_terms AS t INNER JOIN $wpdb->multisite_term_multisite_taxonomy as tt ON tt.multisite_term_id = t.multisite_term_id WHERE ";

	if ( is_int( $multisite_term ) ) {
		if ( 0 === $multisite_term ) {
			return 0;
		}

		if ( ! empty( $multisite_taxonomy ) ) {
			return $wpdb->get_row( $wpdb->prepare( $tax_select . 't.multisite_term_id = %d AND tt.multisite_taxonomy = %s', $multisite_term, $multisite_taxonomy ), ARRAY_A );
		} else {
			return $wpdb->get_var( $wpdb->prepare( $select . 't.multisite_term_id = %d', $multisite_term ) );
		}
	}

	$multisite_term = trim( wp_unslash( $multisite_term ) );
	$slug           = sanitize_title( $multisite_term );

	$where             = 't.slug = %s';
	$else_where        = 't.name = %s';
	$where_fields      = array( $slug );
	$else_where_fields = array( $multisite_term );
	$orderby           = 'ORDER BY t.multisite_term_id ASC';
	$limit             = 'LIMIT 1';
	if ( ! empty( $multisite_taxonomy ) ) {
		if ( is_numeric( $parent_term ) ) {
			$parent_term         = (int) $parent_term;
			$where_fields[]      = $parent_term;
			$else_where_fields[] = $parent_term;
			$where              .= ' AND tt.parent = %d';
			$else_where         .= ' AND tt.parent = %d';
		}

		$where_fields[]      = $multisite_taxonomy;
		$else_where_fields[] = $multisite_taxonomy;

		$result = $wpdb->get_row( $wpdb->prepare( "SELECT tt.multisite_term_id, tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_terms AS t INNER JOIN $wpdb->multisite_term_multisite_taxonomy as tt ON tt.multisite_term_id = t.multisite_term_id WHERE $where AND tt.multisite_taxonomy = %s $orderby $limit", $where_fields ), ARRAY_A );
		if ( $result ) {
			return $result;
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT tt.multisite_term_id, tt.multisite_term_multisite_taxonomy_id FROM $wpdb->multisite_terms AS t INNER JOIN $wpdb->multisite_term_multisite_taxonomy as tt ON tt.multisite_term_id = t.multisite_term_id WHERE $else_where AND tt.multisite_taxonomy = %s $orderby $limit", $else_where_fields ), ARRAY_A );
	}

	// @codingStandardsIgnoreLine
	$result = $wpdb->get_var( $wpdb->prepare( "SELECT multisite_term_id FROM $wpdb->multisite_terms as t WHERE $where $orderby $limit", $where_fields ) );

	if ( $result ) {
		return $result;
	}

	// @codingStandardsIgnoreLine
	return $wpdb->get_var( $wpdb->prepare( "SELECT multisite_term_id FROM $wpdb->multisite_terms as t WHERE $else_where $orderby $limit", $else_where_fields ) );
}

/**
 * Sanitize Multisite Term all fields.
 *
 * Relies on sanitize_multisite_term_field() to sanitize the multisite term. The difference is that
 * this function will sanitize <strong>all</strong> fields. The context is based
 * on sanitize_multisite_term_field().
 *
 * The $multisite_term is expected to be either an array or an object.
 *
 * @param array|object $multisite_term     The multisite term to check.
 * @param string       $multisite_taxonomy The multisite taxonomy name to use.
 * @param string       $context  Optional. Context in which to sanitize the multisite term. Accepts 'edit', 'db',
 *                               'display', 'attribute', or 'js'. Default 'display'.
 * @return array|object Multisite Term with all fields sanitized.
 */
function sanitize_multisite_term( $multisite_term, $multisite_taxonomy, $context = 'display' ) {
	$fields = array( 'multisite_term_id', 'name', 'description', 'slug', 'count', 'parent', 'multisite_term_group', 'multisite_term_multisite_taxonomy_id', 'object_id' );

	$do_object = is_object( $multisite_term );

	$multisite_term_id = $do_object ? $multisite_term->multisite_term_id : ( isset( $multisite_term['multisite_term_id'] ) ? $multisite_term['multisite_term_id'] : 0 );

	foreach ( (array) $fields as $field ) {
		if ( $do_object ) {
			if ( isset( $multisite_term->$field ) ) {
				$multisite_term->$field = sanitize_multisite_term_field( $field, $multisite_term->$field, $multisite_term_id, $multisite_taxonomy, $context );
			}
		} elseif ( isset( $multisite_term[ $field ] ) ) {
				$multisite_term[ $field ] = sanitize_multisite_term_field( $field, $multisite_term[ $field ], $multisite_term_id, $multisite_taxonomy, $context );
		}
	}

	if ( $do_object ) {
		$multisite_term->filter = $context;
	} else {
		$multisite_term['filter'] = $context;
	}

	return $multisite_term;
}

/**
 * Cleanse the field value in the multisite term based on the context.
 *
 * Passing a multisite term field value through the function should be assumed to have
 * cleansed the value for whatever context the multisite term field is going to be used.
 *
 * If no context or an unsupported context is given, then default filters will
 * be applied.
 *
 * There are enough filters for each context to support a custom filtering
 * without creating your own filter function. Simply create a function that
 * hooks into the filter you need.
 *
 * @param string $field    Multisite Term field to sanitize.
 * @param string $value    Search for this multisite term value.
 * @param int    $multisite_term_id  Multisite term ID.
 * @param string $multisite_taxonomy Multisite Taxonomy Name.
 * @param string $context  Context in which to sanitize the multisite term field. Accepts 'edit', 'db', 'display',
 *                         'attribute', or 'js'.
 * @return mixed Sanitized field.
 */
function sanitize_multisite_term_field( $field, $value, $multisite_term_id, $multisite_taxonomy, $context ) {
	$int_fields = array( 'parent', 'multisite_term_id', 'count', 'multisite_term_group', 'multisite_term_multisite_taxonomy_id', 'object_id' );
	if ( in_array( $field, $int_fields, true ) ) {
		$value = (int) $value;
		if ( $value < 0 ) {
			$value = 0;
		}
	}

	if ( 'raw' === $context ) {
		return $value;
	}

	if ( 'edit' === $context ) {

		/**
		 * Filters a multisite term field to edit before it is sanitized.
		 *
		 * The dynamic portion of the filter name, `$field`, refers to the multisite term field.
		 *
		 * @param mixed $value     Value of the multisite term field.
		 * @param int   $multisite_term_id   Multisite term ID.
		 * @param string $multisite_taxonomy Multisite Taxonomy slug.
		 */
		$value = apply_filters( "edit_multisite_term_{$field}", $value, $multisite_term_id, $multisite_taxonomy );

		/**
		 * Filters the multisite taxonomy field to edit before it is sanitized.
		 *
		 * The dynamic portions of the filter name, `$multisite_taxonomy` and `$field`, refer
		 * to the multisite taxonomy slug and multisite taxonomy field, respectively.
		 *
		 * @param mixed $value   Value of the multisite taxonomy field to edit.
		 * @param int   $multisite_term_id Multisite term ID.
		 */
		$value = apply_filters( "edit_multisite_{$multisite_taxonomy}_{$field}", $value, $multisite_term_id );

		if ( 'description' === $field ) {
			$value = esc_html( $value );
		} else {
			$value = esc_attr( $value );
		}
	} elseif ( 'db' === $context ) {

		/**
		 * Filters a multisite term field value before it is sanitized.
		 *
		 * The dynamic portion of the filter name, `$field`, refers to the multisite term field.
		 *
		 * @param mixed  $value    Value of the multisite term field.
		 * @param string $multisite_taxonomy Multisite taxonomy slug.
		 */
		$value = apply_filters( "pre_multisite_term_{$field}", $value, $multisite_taxonomy );

		/**
		 * Filters a multisite taxonomy field before it is sanitized.
		 *
		 * The dynamic portions of the filter name, `$multisite_taxonomy` and `$field`, refer
		 * to the multisite taxonomy slug and field name, respectively.
		 *
		 * @param mixed $value Value of the multisite taxonomy field.
		 */
		$value = apply_filters( "pre_multisite_{$multisite_taxonomy}_{$field}", $value );

	} elseif ( 'rss' === $context ) {

		/**
		 * Filters the multisite term field for use in RSS.
		 *
		 * The dynamic portion of the filter name, `$field`, refers to the multisite term field.
		 *
		 * @param mixed  $value    Value of the multisite term field.
		 * @param string $multisite_taxonomy Multisite taxonomy slug.
		 */
		$value = apply_filters( "multisite_term_{$field}_rss", $value, $multisite_taxonomy );

		/**
		 * Filters the multisite taxonomy field for use in RSS.
		 *
		 * The dynamic portions of the hook name, `$multisite_taxonomy`, and `$field`, refer
		 * to the multisite taxonomy slug and field name, respectively.
		 *
		 * @param mixed $value Value of the multisite taxonomy field.
		 */
		$value = apply_filters( "multisite_{$multisite_taxonomy}_{$field}_rss", $value );
	} else {
		// Use display filters by default.
		/**
		 * Filters the multisite term field sanitized for display.
		 *
		 * The dynamic portion of the filter name, `$field`, refers to the multisite term field name.
		 *
		 * @param mixed  $value    Value of the multisite term field.
		 * @param int    $multisite_term_id  Multisite term ID.
		 * @param string $multisite_taxonomy Multisite taxonomy slug.
		 * @param string $context  Context to retrieve the multisite term field value.
		 */
		$value = apply_filters( "multisite_term_{$field}", $value, $multisite_term_id, $multisite_taxonomy, $context );

		/**
		 * Filters the multisite taxonomy field sanitized for display.
		 *
		 * The dynamic portions of the filter name, `$multisite_taxonomy`, and `$field`, refer
		 * to the multisite taxonomy slug and multisite taxonomy field, respectively.
		 *
		 * @param mixed  $value   Value of the multisite taxonomy field.
		 * @param int    $multisite_term_id Multisite term ID.
		 * @param string $context Context to retrieve the multisite taxonomy field value.
		 */
		$value = apply_filters( "multisite_{$multisite_taxonomy}_{$field}", $value, $multisite_term_id, $context );
	}

	if ( 'attribute' === $context ) {
		$value = esc_attr( $value );
	} elseif ( 'js' === $context ) {
		$value = esc_js( $value );
	}
	return $value;
}

/**
 * Count how many multisite terms are in multisite taxonomy.
 *
 * Default $args is 'hide_empty' which can be 'hide_empty=true' or array('hide_empty' => true).
 *
 * @param string       $multisite_taxonomy Multisite taxonomy name.
 * @param array|string $args     Optional. Array of arguments that get passed to get_multisite_terms().
 *                               Default empty array.
 * @return array|int|WP_Error Number of multisite terms in that multisite taxonomy or WP_Error if the multisite taxonomy does not exist.
 */
function count_multisite_terms( $multisite_taxonomy, $args = array() ) {
	$defaults = array(
		'hide_empty' => false,
	);

	$args = wp_parse_args( $args, $defaults );

	$args['fields']   = 'count';
	$args['taxonomy'] = $multisite_taxonomy;

	return get_multisite_terms( $args );
}

/**
 * Generate a permalink for a multisite taxonomy multisite term archive.
 *
 * @global WP_Rewrite $wp_rewrite
 *
 * @param object|int|string $multisite_term     The multisite term object, ID, or slug whose link will be retrieved.
 * @param string            $multisite_taxonomy Optional. Multisite taxonomy. Default empty.
 * @return string|WP_Error HTML link to multisite taxonomy multisite term archive on success, WP_Error if multisite term does not exist.
 */
function get_multisite_term_link( $multisite_term, $multisite_taxonomy = '' ) {
	global $wp_rewrite;

	if ( ! is_object( $multisite_term ) ) {
		if ( is_int( $multisite_term ) ) {
			$multisite_term = get_multisite_term( $multisite_term, $multisite_taxonomy );
		} else {
			$multisite_term = get_multisite_term_by( 'slug', $multisite_term, $multisite_taxonomy );
		}
	}

	if ( ! is_object( $multisite_term ) ) {
		$multisite_term = new WP_Error( 'invalid_multisite_term', __( 'Empty Multisite Term', 'multitaxo' ) );
	}
	if ( is_wp_error( $multisite_term ) ) {
		return $multisite_term;
	}
	$multisite_taxonomy = $multisite_term->multisite_taxonomy;

	$multisite_termlink = $wp_rewrite->get_extra_permastruct( $multisite_taxonomy );

	$slug = $multisite_term->slug;
	$mt   = get_multisite_taxonomy( $multisite_taxonomy );

	if ( empty( $multisite_termlink ) ) {
		if ( 'category' === $multisite_taxonomy ) {
			$multisite_termlink = '?cat=' . $multisite_term->multisite_term_id;
		} elseif ( $mt->query_var ) {
			$multisite_termlink = "?$mt->query_var=$slug";
		} else {
			$multisite_termlink = "?multisite_taxonomy=$multisite_taxonomy&multisite_term=$slug";
		}
		$multisite_termlink = home_url( $multisite_termlink );
	} else {
		if ( $mt->rewrite['hierarchical'] ) {
			$hierarchical_slugs = array();
			$ancestors          = get_multisite_ancestors( $multisite_term->multisite_term_id, $multisite_taxonomy, 'multisite_taxonomy' );
			foreach ( (array) $ancestors as $ancestor ) {
				$ancestor_multisite_term = get_multisite_term( $ancestor, $multisite_taxonomy );
				$hierarchical_slugs[]    = $ancestor_multisite_term->slug;
			}
			$hierarchical_slugs   = array_reverse( $hierarchical_slugs );
			$hierarchical_slugs[] = $slug;
			$multisite_termlink   = str_replace( "%$multisite_taxonomy%", implode( '/', $hierarchical_slugs ), $multisite_termlink );
		} else {
			$multisite_termlink = str_replace( "%$multisite_taxonomy%", $slug, $multisite_termlink );
		}
		$multisite_termlink = home_url( user_trailingslashit( $multisite_termlink, 'category' ) );
	}

	/**
	 * Filters the multisite term link.
	 *
	 * @param string $multisite_termlink Multisite term link URL.
	 * @param object $multisite_term     Multisite term object.
	 * @param string $multisite_taxonomy Multisite taxonomy slug.
	 */
	return apply_filters( 'multisite_term_link', $multisite_termlink, $multisite_term, $multisite_taxonomy );
}

/**
 * Displays or retrieves the edit term link with formatting.
 *
 * @since 3.1.0
 *
 * @param integer $multisite_term_id Term ID for display.
 * @param string  $taxonomy Term taxonomy for display.
 * @return string|void HTML content.
 */
function get_edit_multisite_term_link( $multisite_term_id, $taxonomy ) {
	$tax = get_multisite_taxonomy( $taxonomy );
	if ( ! $tax || ! current_user_can( 'edit_multisite_term', $multisite_term_id ) ) {
		return;
	}

	$term = get_multisite_term( $multisite_term_id, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return;
	}

	$args = array(
		'page'               => 'multisite_term_edit',
		'multisite_taxonomy' => $taxonomy,
		'multisite_term_id'  => $multisite_term_id,
	);

	if ( $tax->show_ui ) {
		$location = add_query_arg( $args, get_admin_url( null, 'network/admin.php' ) );
	} else {
		$location = '';
	}

	/**
	 * Filters the edit link for a term.
	 *
	 * @since 3.1.0
	 *
	 * @param string $location    The edit link.
	 * @param int    $multisite_term_id     Term ID.
	 * @param string $taxonomy    Taxonomy name.
	 * @param string $object_type The object type (eg. the post type).
	 */
	return apply_filters( 'get_edit_multisite_term_link', $location, $multisite_term_id, $taxonomy );
}

/**
 * Display the multisite taxonomies of a post with available options.
 *
 * This function can be used within the loop to display the multisite taxonomies for a
 * post without specifying the Post ID. You can also use it outside the Loop to
 * display the multisite taxonomies for a specific post.
 *
 * @param array $args {
 *     Arguments about which post to use and how to format the output. Shares all of the arguments
 *     supported by get_the_multisite_taxonomies(), in addition to the following.
 *
 *     @type  int|WP_Post $post   Post ID or object to get multisite taxonomies of. Default current post.
 *     @type  string      $before Displays before the multisite taxonomies. Default empty string.
 *     @type  string      $sep    Separates each multisite taxonomy. Default is a space.
 *     @type  string      $after  Displays after the multisite taxonomies. Default empty string.
 * }
 */
function the_multisite_taxonomies( $args = array() ) {
	$defaults = array(
		'post'   => 0,
		'before' => '',
		'sep'    => ' ',
		'after'  => '',
	);

	$r = wp_parse_args( $args, $defaults );

	echo $r['before'] . join( $r['sep'], get_the_multisite_taxonomies( $r['post'], $r ) ) . $r['after']; // phpcs:ignore WordPress.Security.EscapeOutput
}

/**
 * Retrieve all multisite taxonomies associated with a post.
 *
 * This function can be used within the loop. It will also return an array of
 * the multisite taxonomies with links to the multisite taxonomy and name.
 *
 * @param int|WP_Post $post Optional. Post ID or WP_Post object. Default is global $post.
 * @param int         $blog_id The blog ID to retrieve from. Defaults to the current blog ID if not specified.
 * @param array       $args {
 *     Optional. Arguments about how to format the list of multisite taxonomies. Default empty array.
 *
 *     @type string $template      Template for displaying a multisite taxonomy label and list of multisite terms.
 *                                 Default is "Label: Multisite Terms."
 *     @type string $multisite_term_template Template for displaying a single multisite term in the list. Default is the multisite term name
 *                                 linked to its archive.
 * }
 *
 * @return array List of multisite taxonomies.
 */
function get_the_multisite_taxonomies( $post = 0, $blog_id = 0, $args = array() ) {
	$post = get_post( $post );

	$blog_id = multisite_blog_id_or_current( $blog_id );

	$args = wp_parse_args(
		$args,
		array(
			/* translators: %s: multisite taxonomy label, %l: list of multisite terms formatted as per $multisite_term_template */
			'template'                => __( '%s: %l.', 'multitaxo' ),
			'multisite_term_template' => '<a href="%1$s">%2$s</a>',
		)
	);

	$multisite_taxonomies = array();

	if ( ! $post ) {
		return $multisite_taxonomies;
	}

	foreach ( get_object_multisite_taxonomies( $post ) as $multisite_taxonomy ) {
		$t = (array) get_multisite_taxonomy( $multisite_taxonomy );
		if ( empty( $t['label'] ) ) {
			$t['label'] = $multisite_taxonomy;
		}
		if ( empty( $t['args'] ) ) {
			$t['args'] = array();
		}
		if ( empty( $t['template'] ) ) {
			$t['template'] = $args['template'];
		}
		if ( empty( $t['multisite_term_template'] ) ) {
			$t['multisite_term_template'] = $args['multisite_term_template'];
		}

		$multisite_terms = get_object_multisite_term_cache( $post->ID, $multisite_taxonomy, $blog_id );
		if ( false === $multisite_terms ) {
			$multisite_terms = get_object_multisite_terms( $post->ID, $multisite_taxonomy, $blog_id, $t['args'] );
		}
		$links = array();

		foreach ( $multisite_terms as $multisite_term ) {
			$links[] = wp_sprintf( $t['multisite_term_template'], esc_attr( get_multisite_term_link( $multisite_term ) ), $multisite_term->name );
		}
		if ( $links ) {
			$multisite_taxonomies[ $multisite_taxonomy ] = wp_sprintf( $t['template'], $t['label'], $links, $multisite_terms );
		}
	}
	return $multisite_taxonomies;
}

/**
 * Retrieve all multisite taxonomies of a post with just the names.
 *
 * @param int|WP_Post $post Optional. Post ID or WP_Post object. Default is global $post.
 * @return array
 */
function get_post_multisite_taxonomies( $post = 0 ) {
	$post = get_post( $post );

	return get_object_multisite_taxonomies( $post );
}

/**
 * Ajax handler for adding a hierarchical term.
 *
 * @access private
 * @since 3.1.0
 */
function ajax_add_multisite_hierarchical_term() {
	if ( isset( $_POST['action'] ) ) {
		$action = sanitize_key( wp_unslash( $_POST['action'] ) );
	}

	$tax      = str_replace( 'add-multisite-hierarchical-term-', '', $action );
	$taxonomy = get_multisite_taxonomy( $tax );

	check_ajax_referer( 'add-multisite-' . $taxonomy->name, '_ajax_nonce-add-' . $taxonomy->name );

	if ( ! current_user_can( $taxonomy->cap->edit_multisite_terms ) ) {
		wp_die( -1 );
	}

	if ( isset( $_POST[ 'new_multisite_' . $taxonomy->name ] ) ) {
		$names = explode( ',', sanitize_text_field( wp_unslash( $_POST[ 'new_multisite_' . $taxonomy->name ] ) ) );
	} else {
		$names = array();
	}

	if ( isset( $_POST[ 'new_multisite_' . $taxonomy->name . '_parent' ] ) ) {
		$parent = absint( wp_unslash( $_POST[ 'new_multisite_' . $taxonomy->name . '_parent' ] ) );
	} else {
		$parent = 0;
	}

	if ( 0 > $parent ) {
		$parent = 0;
	}

	if ( isset( $_POST['multi_tax_input'] ) && isset( $_POST['multi_tax_input'][ $taxonomy->name ] ) ) {
		$checked_categories = array_map( 'absint', (array) wp_unslash( $_POST['multi_tax_input'][ $taxonomy->name ] ) );
	} else {
		$checked_categories = array();
	}

	$popular_ids = popular_multisite_terms_checklist( $taxonomy->name, 0, 10, false );

	foreach ( $names as $cat_name ) {
		$cat_name          = trim( $cat_name );
		$category_nicename = sanitize_title( $cat_name );

		if ( '' === $category_nicename ) {
			continue;
		}

		$cat_id = insert_multisite_term( $cat_name, $taxonomy->name, array( 'parent' => $parent ) );

		if ( ! $cat_id || is_wp_error( $cat_id ) ) {
			continue;
		} else {
			$cat_id = $cat_id['multisite_term_id'];
		}

		$checked_categories[] = $cat_id;

		if ( $parent ) { // Do these all at once in a second.
			continue;
		}

		ob_start();

		multisite_terms_checklist(
			0,
			array(
				'taxonomy'             => $taxonomy->name,
				'descendants_and_self' => $cat_id,
				'selected_terms'       => $checked_categories,
				'popular_terms'        => $popular_ids,
			)
		);

		$data = ob_get_clean();

		$add = array(
			'what'     => $taxonomy->name,
			'id'       => $cat_id,
			'data'     => str_replace( array( "\n", "\t" ), '', $data ),
			'position' => -1,
		);
	}

	if ( $parent ) { // Foncy - replace the parent and all its children.
		$parent  = get_multisite_term( $parent, $taxonomy->name );
		$term_id = $parent->multisite_term_id;

		while ( $parent->parent ) { // get the top parent.
			$parent = get_multisite_term( $parent->parent, $taxonomy->name );

			if ( is_wp_error( $parent ) ) {
				break;
			}

			$term_id = $parent->multisite_term_id;
		}

		ob_start();
		$checklist_args = array(
			'taxonomy'             => $taxonomy->name,
			'descendants_and_self' => $term_id,
			'selected_terms'       => $checked_categories,
			'popular_terms'        => $popular_ids,
		);

		multisite_terms_checklist( 0, $checklist_args );
		$data = ob_get_clean();

		$add = array(
			'what'     => $taxonomy->name,
			'id'       => $term_id,
			'data'     => str_replace( array( "\n", "\t" ), '', $data ),
			'position' => -1,
		);
	}

	ob_start();

	dropdown_multisite_taxonomy(
		array(
			'taxonomy'         => $taxonomy->name,
			'hide_empty'       => 0,
			'name'             => 'new_multisite_' . $taxonomy->name . '_parent',
			'orderby'          => 'name',
			'hierarchical'     => 1,
			'show_option_none' => '&mdash; ' . $taxonomy->labels->parent_item . ' &mdash;',
		)
	);

	$sup = ob_get_clean();

	$add['supplemental'] = array( 'new_multisite_term_parent' => $sup );

	$x = new WP_Ajax_Response( $add );
	$x->send();
}

/**
 * Set the terms for a post.
 *
 * @since 2.8.0
 *
 * @see wp_set_object_terms()
 *
 * @param int $data  Save data to be sanitized.
 * @return array|false|WP_Error Array of term taxonomy IDs of affected terms. WP_Error or false on failure.
 */
function sanitize_multisite_taxonomy_save_data( $data = array() ) {
	array_walk_recursive( $data, 'sanitize_text_field' );

	return $data;
}
