<?php
/**
 * Multisite Taxonomy API : Term meta and term settings
 *
 * The meta wrappers over the multisite term meta table, and the registry of boolean
 * per-taxonomy "behavior" settings stored in that meta.
 *
 * @package multitaxo
 */

/**
 * Adds metadata to a multisite term.
 *
 * @param int    $multisite_term_id    Multisite term ID.
 * @param string $meta_key   Metadata name.
 * @param mixed  $meta_value Metadata value.
 * @param bool   $unique     Optional. Whether to bail if an entry with the same key is found for the multisite term.
 *                           Default false.
 * @return int|WP_Error|bool Meta ID on success. WP_Error when multisite_term_id is ambiguous between multisite taxonomies.
 *                           False on failure.
 */
function add_multisite_term_meta( $multisite_term_id, $meta_key, $meta_value, $unique = false ) {

	$added = add_metadata( 'multisite_term', $multisite_term_id, $meta_key, $meta_value, $unique );

	// Bust term query cache.
	if ( $added ) {
		wp_cache_set( 'last_changed', microtime(), 'multisite_terms' );
	}

	return $added;
}

/**
 * Removes metadata matching criteria from a multisite term.
 *
 * @param int    $multisite_term_id    Multisite term ID.
 * @param string $meta_key   Metadata name.
 * @param mixed  $meta_value Optional. Metadata value. If provided, rows will only be removed that match the value.
 * @return bool True on success, false on failure.
 */
function delete_multisite_term_meta( $multisite_term_id, $meta_key, $meta_value = '' ) {

	$deleted = delete_metadata( 'multisite_term', $multisite_term_id, $meta_key, $meta_value );

	// Bust multisite term query cache.
	if ( $deleted ) {
		wp_cache_set( 'last_changed', microtime(), 'multisite_terms' );
	}

	return $deleted;
}

/**
 * Retrieves metadata for a multisiteterm.
 *
 * @param int    $multisite_term_id Multisite term ID.
 * @param string $key     Optional. The meta key to retrieve. If no key is provided, fetches all metadata for the multisite term.
 * @param bool   $single  Whether to return a single value. If false, an array of all values matching the
 *                        `$multisite_term_id`/`$key` pair will be returned. Default: false.
 * @return mixed If `$single` is false, an array of metadata values. If `$single` is true, a single metadata value.
 */
function get_multisite_term_meta( $multisite_term_id, $key = '', $single = false ) {
	return get_metadata( 'multisite_term', $multisite_term_id, $key, $single );
}

/**
 * Updates multisite term metadata.
 *
 * Use the `$prev_value` parameter to differentiate between meta fields with the same key and multisite term ID.
 *
 * If the meta field for the multisite term does not exist, it will be added.
 *
 * @param int    $multisite_term_id    Multisite term ID.
 * @param string $meta_key   Metadata key.
 * @param mixed  $meta_value Metadata value.
 * @param mixed  $prev_value Optional. Previous value to check before removing.
 * @return int|WP_Error|bool Meta ID if the key didn't previously exist. True on successful update.
 *                           WP_Error when multisite_term_id is ambiguous between multisite taxonomies. False on failure.
 */
function update_multisite_term_meta( $multisite_term_id, $meta_key, $meta_value, $prev_value = '' ) {

	$updated = update_metadata( 'multisite_term', $multisite_term_id, $meta_key, $meta_value, $prev_value );

	// Bust multisite term query cache.
	if ( $updated ) {
		wp_cache_set( 'last_changed', microtime(), 'multisite_terms' );
	}

	return $updated;
}

/**
 * Updates metadata cache for list of multisite term IDs.
 *
 * Performs SQL query to retrieve all metadata for the multisite terms matching `$multisite_term_ids` and stores them in the cache.
 * Subsequent calls to `get_multisite_term_meta()` will not need to query the database.
 *
 * @param array $multisite_term_ids List of multisite term IDs.
 * @return array|false Returns false if there is nothing to update. Returns an array of metadata on success.
 */
function update_multisite_termmeta_cache( $multisite_term_ids ) {
	return update_meta_cache( 'multisite_term', $multisite_term_ids );
}

/**
 * Registry of declarative per-term "behaviors" (boolean settings), keyed by taxonomy.
 *
 * A behavior is a boolean flag a term carries in its meta. It renders as a checkbox
 * in a "Behaviors" section on the term add/edit screen and is persisted to
 * `multisite_termmeta`, so any consumer can read it back with
 * {@see get_multisite_term_setting()} or join the meta table directly.
 *
 * @return array Registry passed by reference so registration can mutate it.
 */
function &multisite_term_settings_registry() {
	static $registry = array();
	return $registry;
}

/**
 * Register a boolean "behavior" setting on a multisite taxonomy's terms.
 *
 * Idempotent per (taxonomy, key): re-registering a key replaces its args. The hooks are
 * added on every call, which is free because a named callback at the same priority occupies
 * one slot however often it is added -- and a guard that fired only on the first call would
 * leave a second registration unhooked whenever something reset the hooks in between.
 *
 * @param string $taxonomy Multisite taxonomy slug.
 * @param string $key      Meta key (also the checkbox field name). Keep it globally
 *                         unique so direct meta joins are unambiguous.
 * @param array  $args     Optional. Accepts 'label' (string), 'description' (string),
 *                         'type' (string, 'boolean') and 'default' (bool).
 * @return void
 */
function register_multisite_term_setting( $taxonomy, $key, $args = array() ) {
	$registry = &multisite_term_settings_registry();

	$args = wp_parse_args(
		$args,
		array(
			'label'       => $key,
			'description' => '',
			'type'        => 'boolean',
			'default'     => false,
		)
	);

	$registry[ $taxonomy ][ $key ] = $args;

	add_action( "{$taxonomy}_add_form_fields", 'render_multisite_term_settings_add_fields' );
	add_action( "{$taxonomy}_multisite_edit_form_fields", 'render_multisite_term_settings_edit_fields', 10, 2 );
	add_action( 'created_multisite_term', 'save_multisite_term_settings', 10, 3 );
	add_action( 'edited_multisite_term', 'save_multisite_term_settings', 10, 3 );
}

/**
 * The behaviors registered for a taxonomy.
 *
 * @param string $taxonomy Multisite taxonomy slug.
 * @return array Map of key => args; empty if none registered.
 */
function get_multisite_term_settings( $taxonomy ) {
	$registry = &multisite_term_settings_registry();
	return isset( $registry[ $taxonomy ] ) ? $registry[ $taxonomy ] : array();
}

/**
 * Read a term's behavior flag as a boolean.
 *
 * @param int    $term_id Multisite term ID.
 * @param string $key     The behavior meta key.
 * @param bool   $default_value Value when the meta is unset. Default false.
 * @return bool
 */
function get_multisite_term_setting( $term_id, $key, $default_value = false ) {
	$value = get_multisite_term_meta( (int) $term_id, $key, true );
	if ( '' === $value || null === $value ) {
		return (bool) $default_value;
	}
	return '1' === (string) $value;
}

/**
 * Render the "Behaviors" section on the Add Term form (non-table markup).
 *
 * @param string $taxonomy Taxonomy slug (passed by the `{$taxonomy}_add_form_fields` action).
 * @return void
 */
function render_multisite_term_settings_add_fields( $taxonomy ) {
	$settings = get_multisite_term_settings( $taxonomy );
	if ( empty( $settings ) ) {
		return;
	}
	echo '<div class="form-field term-behaviors-wrap">';
	echo '<h2>' . esc_html__( 'Behaviors', 'multitaxo' ) . '</h2>';
	echo '<input type="hidden" name="multisite_term_settings_present" value="1" />';
	foreach ( $settings as $key => $args ) {
		multisite_term_setting_checkbox( $key, $args, (bool) $args['default'] );
	}
	echo '</div>';
}

/**
 * Render the "Behaviors" section on the Edit Term form (table-row markup).
 *
 * @param object $term The current term object (has `multisite_term_id`).
 * @param object $tax  The taxonomy object.
 * @return void
 */
function render_multisite_term_settings_edit_fields( $term, $tax ) {
	$taxonomy = is_object( $tax ) ? $tax->name : (string) $tax;
	$settings = get_multisite_term_settings( $taxonomy );
	if ( empty( $settings ) ) {
		return;
	}
	$term_id = is_object( $term ) ? (int) $term->multisite_term_id : (int) $term;
	echo '<tr class="form-field term-behaviors-wrap"><th scope="row">' . esc_html__( 'Behaviors', 'multitaxo' ) . '</th><td>';
	echo '<input type="hidden" name="multisite_term_settings_present" value="1" />';
	foreach ( $settings as $key => $args ) {
		multisite_term_setting_checkbox( $key, $args, get_multisite_term_setting( $term_id, $key, (bool) $args['default'] ) );
	}
	echo '</td></tr>';
}

/**
 * Print one behavior checkbox (shared by the add and edit renderers).
 *
 * @param string $key     Behavior meta key / field name.
 * @param array  $args    Behavior args ( label, description ).
 * @param bool   $checked Whether the box is checked.
 * @return void
 */
function multisite_term_setting_checkbox( $key, $args, $checked ) {
	printf(
		'<p><label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>%4$s</p>',
		esc_attr( $key ),
		checked( (bool) $checked, true, false ),
		esc_html( $args['label'] ),
		'' !== $args['description'] ? '<br /><span class="description">' . esc_html( $args['description'] ) . '</span>' : ''
	);
}

/**
 * Persist behavior checkboxes when a term is created or edited through the admin form.
 *
 * Hooked on `created_multisite_term` and `edited_multisite_term`. The term add/edit
 * handler verifies its nonce before firing these actions, and the hidden
 * `multisite_term_settings_present` marker guarantees we only act on our own form
 * submission (programmatic term updates never carry it), so an unchecked box
 * deterministically clears the flag instead of being mistaken for "not submitted".
 *
 * @param int    $term_id  Multisite term ID.
 * @param int    $tt_id    Multisite term taxonomy ID (unused).
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function save_multisite_term_settings( $term_id, $tt_id, $taxonomy ) {
	$settings = get_multisite_term_settings( $taxonomy );
	if ( empty( $settings ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the term add/edit handler before this action fires.
	if ( empty( $_POST['multisite_term_settings_present'] ) ) {
		return;
	}
	foreach ( array_keys( $settings ) as $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		if ( ! empty( $_POST[ $key ] ) ) {
			update_multisite_term_meta( (int) $term_id, $key, '1' );
		} else {
			delete_multisite_term_meta( (int) $term_id, $key );
		}
	}
}
