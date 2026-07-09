<?php
/**
 * Multisite Taxonomies Settings init class
 *
 * @todo: User_Taxonomy_Admin
 * @package multitaxo
 */

/**
 * Settings screens init class.
 */
class Multisite_Taxonomy_Meta_Box {
	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		// We enqueue both the frontend and admin styles and scripts.
		add_action( 'add_meta_boxes', array( $this, 'add_multisite_taxonomy_meta_box_post' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'add_multisite_taxonomy_meta_box_user' ), 10, 1 );
		add_action( 'edit_user_profile', array( $this, 'add_multisite_taxonomy_meta_box_user' ), 10, 1 );

		// Network Admin -> Sites -> Edit Site: render the picker inside the site-info form.
		add_action( 'network_site_info_form', array( $this, 'add_multisite_taxonomy_meta_box_site' ), 10, 1 );

		// Add the admin scripts to the posts pages.
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles_and_scripts' ) );

		// register the ajax response for creating new terms.
		add_action( 'wp_ajax_ajax-multisite-tag-search', array( $this, 'wp_ajax_ajax_multisite_terms_search' ) );
		add_action( 'wp_ajax_ajax-get-multisite-term-cloud', array( $this, 'wp_ajax_get_multisite_term_cloud' ) );

		// Save the post Box.
		add_action( 'save_post', array( $this, 'save_multisite_taxonomy' ), 10, 1 ); // param ist post_id.
		add_action( 'personal_options_update', array( $this, 'save_multisite_taxonomy' ), 10, 1 ); // param is user_id.
		add_action( 'edit_user_profile_update', array( $this, 'save_multisite_taxonomy' ), 10, 1 ); // param is user_id.

		// Save the site/blog box. site-info.php processes the request after admin_init, so hook there.
		add_action( 'admin_init', array( $this, 'save_multisite_taxonomy_site' ) );
	}

	/**
	 * Display the metabox container if we should use it.
	 *
	 * @param WP_User $profile_user The WP Post type.
	 *
	 * @return void
	 */
	public function add_multisite_taxonomy_meta_box_user( WP_User $profile_user ) {
		// Only render if there is at least one taxonomy registered for the user object type.
		if ( count( (array) get_object_multisite_taxonomies( 'user' ) ) <= 0 ) {
			return;
		}

		$this->admin_enqueue_styles_and_scripts( 'post-new.php' );

		// The profile screens have no metabox toggle behaviour of their own, so wire up a
		// self-contained one that also collapses the box by default.
		wp_enqueue_script( 'multisite-taxonomy-profile-box', MULTITAXO_ASSETS_URL . '/js/multisite-taxonomy-profile-box.js', array( 'jquery' ), MULTITAXO_VERSION, 1 );

		add_meta_box(
			'multsite_taxonomy_meta_box',
			esc_html__( 'Multisite Tags', 'multitaxo' ),
			array( $this, 'multisite_taxonomy_meta_box_callback' ),
			null,
			'advanced',
			'default',
			array( $profile_user )
		);

		do_action( 'before_add_multisite_taxonomy_meta_box_user', $profile_user );

		// not sure, if surrounding things with the meta-box makes a lot of sense here...
		do_meta_boxes( get_current_screen(), 'advanced', $profile_user );

		do_action( 'after_add_multisite_taxonomy_meta_box_user', $profile_user );
	}

	/**
	 * Render the assignment picker on the Network Admin site-edit screen (Sites -> Edit Site).
	 *
	 * Fired by the core `network_site_info_form` action from inside the site-info form, so the
	 * fields submit with the form (verified against the `edit-site` nonce in the save handler).
	 *
	 * @param int $blog_id The site/blog ID being edited.
	 *
	 * @return void
	 */
	public function add_multisite_taxonomy_meta_box_site( $blog_id ) {
		$blog_id = (int) $blog_id;

		// Only render if there is at least one taxonomy registered for the blog object type.
		if ( count( (array) get_object_multisite_taxonomies( 'blog' ) ) <= 0 ) {
			return;
		}

		$site = get_site( $blog_id );
		if ( ! $site ) {
			return;
		}

		// The site-info form does not run through admin_enqueue_scripts with our hooks, so enqueue directly.
		$this->admin_enqueue_styles_and_scripts( 'site-info.php' );

		// The tag picker only edits form state; nothing persists until the site-info form is
		// submitted. Flag that unsaved state so the picker does not read as an instant save.
		wp_enqueue_script(
			'multisite-tags-site-info',
			MULTITAXO_ASSETS_URL . '/js/multisite-tags-site-info.js',
			array( 'jquery', 'multisite-taxonomy-box' ),
			MULTITAXO_VERSION,
			true
		);

		?>
		<h2 id="multisite-tags"><?php esc_html_e( 'Multisite Tags', 'multitaxo' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><span class="screen-reader-text"><?php esc_html_e( 'Multisite Tags', 'multitaxo' ); ?></span></th>
				<td>
					<?php $this->multisite_taxonomy_meta_box_callback( $site ); ?>
					<div class="notice notice-warning inline multitax-unsaved-notice" style="display:none;">
						<p><?php esc_html_e( 'You have unsaved tag changes. Click “Save Changes” at the bottom of this page to apply them.', 'multitaxo' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Display the metabox container if we should use it.
	 *
	 * @param string  $post_type The WP Post type.
	 * @param WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function add_multisite_taxonomy_meta_box_post( $post_type, $post ) {
		if ( count( (array) get_object_multisite_taxonomies( $post_type ) ) > 0 && ( current_user_can( 'assign_multisite_terms' ) ) ) {
			add_meta_box( 'multsite_taxonomy_meta_box', esc_html__( 'Multisite Tags', 'multitaxo' ), array( $this, 'multisite_taxonomy_meta_box_callback' ), null, 'advanced', 'default', array( $post, $post_type ) );
		}
	}

	/**
	 * Enqueue scripts and styles for our metabox.
	 *
	 * @todo: add this to the footer, if the metabox is added?
	 *
	 * @param string $hook page hook.
	 *
	 * @return void
	 */
	public function admin_enqueue_styles_and_scripts( $hook ) {

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php', 'user-edit.php', 'profile.php', 'site-info.php' ), true ) ) {
			// We only need the scripts and styles on the post/user/site edit screens.
			return;
		}

		wp_enqueue_script( 'multisite-taxonomy-suggest', MULTITAXO_ASSETS_URL . '/js/multisite-taxonomy-suggest.js', array( 'jquery', 'jquery-ui-core', 'jquery-ui-autocomplete', 'wp-a11y', 'tags-suggest' ), MULTITAXO_VERSION, 1 );
		wp_localize_script(
			'multisite-taxonomy-suggest',
			'multiTaxL10n',
			array(
				'tagDelimiter' => _x( ',', 'tag delimiter', 'multitaxo' ),
				'removeTerm'   => __( 'Remove term:', 'multitaxo' ),
				'termSelected' => __( 'Term selected.', 'multitaxo' ),
				'termAdded'    => __( 'Term added.', 'multitaxo' ),
				'termRemoved'  => __( 'Term removed.', 'multitaxo' ),
			)
		);

		wp_enqueue_script( 'multisite-taxonomy-box', MULTITAXO_ASSETS_URL . '/js/multisite-taxonomy-box.js', array( 'multisite-taxonomy-suggest', 'jquery-ui-tabs' ), MULTITAXO_VERSION, 1 );
		wp_localize_script(
			'multisite-taxonomy-box',
			'mtaxsecurity',
			array(
				'noncesearch' => wp_create_nonce( 'nonce-multisite-terms-search' ),
				'noncecloud'  => wp_create_nonce( 'nonce-multisite-term-cloud' ),
			)
		);

		wp_enqueue_script( 'hierarchical-multisite-taxonomy-box', MULTITAXO_ASSETS_URL . '/js/multisite-hierarchical-term-box.js', array( 'jquery-ui-tabs', 'wp-lists' ), MULTITAXO_VERSION, 1 );

		wp_enqueue_style( 'multisite-taxonomy-meta-box', MULTITAXO_ASSETS_URL . '/css/admin.css', array(), MULTITAXO_VERSION );
	}

	/**
	 * Display the meta box content.
	 *
	 * @param int|WP_Post|WP_User|WP_Site $obj     The object (or its id) whose terms to edit.
	 * @param string                      $metabox Unused; kept for the add_meta_box callback signature.
	 *
	 * @return void
	 */
	public function multisite_taxonomy_meta_box_callback( $obj, $metabox = '' ) {
		// Resolve the object id and its ID namespace ('' = post, 'user', 'blog'). See plan.md.
		if ( is_a( $obj, 'WP_User' ) ) {
			$obj_id      = (int) $obj->ID;
			$object_type = 'user';
		} elseif ( is_a( $obj, 'WP_Site' ) ) {
			$obj_id      = (int) $obj->id;
			$object_type = 'blog';
		} elseif ( is_a( $obj, 'WP_Post' ) ) {
			$obj_id      = (int) $obj->ID;
			$object_type = '';
		} else {
			$obj_id      = (int) $obj;
			$object_type = '';
		}

		// Show only the taxonomies registered for this object type; posts keep the full list.
		if ( 'user' === $object_type || 'blog' === $object_type ) {
			$taxonomies = get_object_multisite_taxonomies( $object_type, 'objects' );
		} else {
			$taxonomies = get_multisite_taxonomies( array(), 'objects' );
		}

		$tabs         = array();
		$tab_contents = array();

		?>
		<div id="multisite-tax-picker">
			<ul>
		<?php

		foreach ( $taxonomies as $tax ) {
			// Are we hierarchical or not?
			$hierarchical = ( true === $tax->hierarchical ) ? 'hierarchical-' : 'flat-';

			// Set up the tab itself.
			?>
			<li><a href="#tabs-<?php echo esc_attr( $hierarchical ) . esc_attr( $tax->name ); ?>"><?php echo esc_html( $tax->labels->name ); ?></a></li>
			<?php
		}

		?>
		</ul>
		<?php

		// and lets do this again for the boxes.
		reset( $taxonomies );

		// loop and loop.
		foreach ( $taxonomies as $tax ) {
			// Are we hierarchical or not?
			$hierarchical = ( true === $tax->hierarchical ) ? 'hierarchical-' : 'flat-';

			?>
			<div id="tabs-<?php echo esc_attr( $hierarchical ) . esc_attr( $tax->name ); ?>" class="multi-taxonomy-tab">
				<h2><?php echo esc_html( $tax->labels->name ); ?></h2>
			<?php

			$args = array(
				'title'       => $tax->labels->name,
				'taxonomy'    => $tax->name,
				'object_type' => $object_type,
				'args'        => array(),
			);

			// Are we hierarchical-term or not?
			if ( true === $tax->hierarchical ) {
				$this->hierarchical_multisite_taxonomy_meta_box( $obj_id, $args );
			} else {
				$this->multisite_taxonomy_meta_box( $obj_id, $args );
			}

			?>
			</div>
			<?php
		}

		?>
		</div>
		<?php
	}

	/**
	 * Display post tags form fields.
	 *
	 * @since 2.6.0
	 *
	 * @todo Create taxonomy-agnostic wrapper for this.
	 *
	 * @param int   $obj_id
	 * @param array $args {
	 *   Tags meta box arguments.
	 *
	 *     @type string   $taxonomy Taxonomy corresponding.
	 *     @type string   $title    Meta box title.
	 *     @type array    $args {
	 *         Extra meta box arguments.
	 *     }
	 * }
	 */
	public function multisite_taxonomy_meta_box( int $obj_id, $args ) {
		if ( ! isset( $args['taxonomy'] ) ) {
			return false;
		}

		$defaults              = array( 'object_type' => '' );
		$r                     = wp_parse_args( $args, $defaults );
		$tax_name              = esc_attr( $r['taxonomy'] );
		$taxonomy              = get_multisite_taxonomy( $r['taxonomy'] );
		$user_can_assign_terms = current_user_can( $taxonomy->cap->assign_multisite_terms );
		$comma                 = _x( ',', 'tag delimiter', 'multitaxo' );
		$terms_to_edit         = get_multisite_terms_to_edit( $obj_id, $tax_name, 0, $r['object_type'] );

		if ( ! is_string( $terms_to_edit ) ) {
			$terms_to_edit = '';
		}

		// Add an nonce field so we can check for it later.
		wp_nonce_field( 'multisite_taxonomy_meta_box', 'multisite_taxonomy_meta_box_nonce' );
		?>
	<div class="multitaxonomydiv" id="multi-taxonomy-<?php echo esc_attr( $tax_name ); ?>">
		<div class="ajaxtaxonomy">
		<div class="nojs-taxonomy hide-if-js">
			<label for="multi-tax-input-<?php echo esc_attr( $tax_name ); ?>"><?php echo esc_html( $taxonomy->labels->add_or_remove_items ); ?></label>
			<p><textarea name="<?php echo esc_attr( "multi_tax_input[$tax_name]" ); ?>" rows="3" cols="20" class="the-multi-taxonomy" id="multi-tax-input-<?php echo esc_attr( $tax_name ); ?>" <?php disabled( ! $user_can_assign_terms ); ?> aria-describedby="new-taxonomy-<?php echo esc_attr( $tax_name ); ?>-desc"><?php echo esc_textarea( str_replace( ',', $comma . ' ', $terms_to_edit ) ); ?></textarea></p>
		</div>
		<?php if ( $user_can_assign_terms ) : ?>
		<div class="ajaxmultitaxonomy hide-if-no-js">
			<label class="screen-reader-text" for="new-multi-taxonomy-<?php echo esc_attr( $tax_name ); ?>"><?php echo esc_html( $taxonomy->labels->add_new_item ); ?></label>
			<p><input data-multi-taxonomy="<?php echo esc_attr( $tax_name ); ?>" type="text" id="new-multi-taxonomy-<?php echo esc_attr( $tax_name ); ?>" name="new_multi_taxonomy[<?php echo esc_attr( $tax_name ); ?>]" class="newmultiterm form-input-tip" size="16" autocomplete="off" aria-describedby="new-multi-taxonomy-<?php echo esc_attr( $tax_name ); ?>-desc" value="" />
			<input type="button" class="button multitermadd" value="<?php esc_attr_e( 'Add', 'multitaxo' ); ?>" /></p>
		</div>
		<p class="howto" id="new-multi-taxonomy-<?php echo esc_attr( $tax_name ); ?>-desc"><?php echo esc_html( $taxonomy->labels->separate_items_with_commas ); ?></p>
		<?php elseif ( empty( $terms_to_edit ) ) : ?>
			<p><?php echo esc_html( $taxonomy->labels->no_terms ); ?></p>
		<?php endif; ?>
		</div>
		<ul class="multitaxonomychecklist" role="list"></ul>
	</div>
		<?php if ( $user_can_assign_terms ) : ?>
	<p class="hide-if-no-js"><button type="button" class="button-link multitaxonomycloud-link" id="link-<?php echo esc_attr( $tax_name ); ?>" aria-expanded="false"><?php echo esc_html( $taxonomy->labels->choose_from_most_used ); ?></button></p>
	<?php endif; ?>
		<?php
	}

	/**
	 * Display post hierarchical-term form fields.
	 *
	 * @since 2.6.0
	 *
	 * @todo Create taxonomy-agnostic wrapper for this.
	 *
	 * @param int   $obj_id
	 * @param array $args {
	 *   hierarchical-term meta box arguments.
	 *
	 *     @type string   $id       Meta box 'id' attribute.
	 *     @type string   $title    Meta box title.
	 *     @type callable $callback Meta box display callback.
	 *     @type array    $args {
	 *         Extra meta box arguments.
	 *
	 *         @type string $taxonomy Taxonomy. Default 'hierarchical-term'.
	 *     }
	 * }
	 */
	public function hierarchical_multisite_taxonomy_meta_box( $obj_id, $args ) {
		if ( ! isset( $args['taxonomy'] ) ) {
			return false;
		}

		$defaults = array( 'object_type' => '' );
		$r        = wp_parse_args( $args, $defaults );
		$tax_name = esc_attr( $r['taxonomy'] );
		$taxonomy = get_multisite_taxonomy( $r['taxonomy'] );

		wp_nonce_field( 'multisite_taxonomy_meta_box', 'multisite_taxonomy_meta_box_nonce' );
		?>
		<div id="taxonomy-<?php echo esc_attr( $tax_name ); ?>" class="multisite-hierarchical-taxonomy-div">
			<ul id="<?php echo esc_attr( $tax_name ); ?>-tabs" class="hierarchical-term-tabs">
				<li class="tabs"><a href="#<?php echo esc_attr( $tax_name ); ?>-all"><?php echo esc_html( $taxonomy->labels->all_items ); ?></a></li>
				<li class="hide-if-no-js"><a href="#<?php echo esc_attr( $tax_name ); ?>-pop"><?php echo esc_html( $taxonomy->labels->most_used ); ?></a></li>
			</ul>

			<div id="<?php echo esc_attr( $tax_name ); ?>-pop" class="tabs-panel" style="display: none;">
				<ul id="<?php echo esc_attr( $tax_name ); ?>checklist-pop" class="hierarchical-term-checklist form-no-clear" >
					<?php $popular_ids = popular_multisite_terms_checklist( $tax_name ); ?>
				</ul>
			</div>

			<div id="<?php echo esc_attr( $tax_name ); ?>-all" class="tabs-panel">
				<?php
				echo '<input type="hidden" name="multi_tax_input[' . esc_attr( $tax_name ) . '][]" value="0" />'; // Allows for an empty term set to be sent. 0 is an invalid Term ID and will be ignored by empty() checks.
				?>
				<ul id="<?php echo esc_attr( $tax_name ); ?>checklist" data-wp-lists="list:<?php echo esc_attr( $tax_name ); ?>" class="hierarchical-term-checklist form-no-clear">
					<?php
					multisite_terms_checklist(
						$obj_id,
						array(
							'taxonomy'      => $tax_name,
							'popular_terms' => $popular_ids,
							'object_type'   => $r['object_type'],
						)
					);
					?>
				</ul>
			</div>
		<?php if ( current_user_can( $taxonomy->cap->edit_multisite_terms ) ) : ?>
				<div id="<?php echo esc_attr( $tax_name ); ?>-adder" class="wp-hidden-children">
					<a id="<?php echo esc_attr( $tax_name ); ?>-add-toggle" href="#<?php echo esc_attr( $tax_name ); ?>-add" class="hide-if-no-js taxonomy-add-new">
						<?php
							/* translators: %s: add new taxonomy label */
							printf( esc_html__( '+ %s', 'multitaxo' ), esc_html( $taxonomy->labels->add_new_item ) );
						?>
					</a>
					<p id="<?php echo esc_attr( $tax_name ); ?>-add" class="multisite-hierarchical-term-add wp-hidden-child">
						<label class="screen-reader-text" for="new_multisite_<?php echo esc_attr( $tax_name ); ?>"><?php echo esc_html( $taxonomy->labels->add_new_item ); ?></label>
						<input type="text" name="new_multisite_<?php echo esc_attr( $tax_name ); ?>" id="new_multisite_<?php echo esc_attr( $tax_name ); ?>" class="form-required form-input-tip" value="<?php echo esc_attr( $taxonomy->labels->new_item_name ); ?>" aria-required="true"/>
						<label class="screen-reader-text" for="new_multisite_<?php echo esc_attr( $tax_name ); ?>_parent">
							<?php echo esc_html( $taxonomy->labels->parent_item_colon ); ?>
						</label>
						<?php
						$parent_dropdown_args = array(
							'taxonomy'         => $tax_name,
							'hide_empty'       => 0,
							'name'             => 'new_multisite_' . $tax_name . '_parent',
							'orderby'          => 'name',
							'hierarchical'     => 1,
							'show_option_none' => '&mdash; ' . $taxonomy->labels->parent_item . ' &mdash;',
						);

						/**
						 * Filters the arguments for the taxonomy parent dropdown on the Post Edit page.
						 *
						 * @since 4.4.0
						 *
						 * @param array $parent_dropdown_args {
						 *     Optional. Array of arguments to generate parent dropdown.
						 *
						 *     @type string   $taxonomy         Name of the taxonomy to retrieve.
						 *     @type bool     $hide_if_empty    True to skip generating markup if no
						 *                                      tags are found. Default 0.
						 *     @type string   $name             Value for the 'name' attribute
						 *                                      of the select element.
						 *                                      Default "new_multisite_{$tax_name}_parent".
						 *     @type string   $orderby          Which column to use for ordering
						 *                                      terms. Default 'name'.
						 *     @type bool|int $hierarchical     Whether to traverse the taxonomy
						 *                                      hierarchy. Default 1.
						 *     @type string   $show_option_none Text to display for the "none" option.
						 *                                      Default "&mdash; {$parent} &mdash;",
						 *                                      where `$parent` is 'parent_item'
						 *                                      taxonomy label.
						 * }
						 */
						$parent_dropdown_args = apply_filters( 'edit_multisite_hierarchical_term_parent_dropdown_args', $parent_dropdown_args );

						dropdown_multisite_taxonomy( $parent_dropdown_args );
						?>
						<input type="button" id="<?php echo esc_attr( $tax_name ); ?>-add-submit" data-wp-lists="add:<?php echo esc_attr( $tax_name ); ?>checklist:<?php echo esc_attr( $tax_name ); ?>-add" class="button multisite-hierarchical-term-add-submit" value="<?php echo esc_attr( $taxonomy->labels->add_new_item ); ?>" />
						<?php wp_nonce_field( 'add-multisite-' . $tax_name, '_ajax_nonce-add-' . $tax_name, false ); ?>
						<span id="<?php echo esc_attr( $tax_name ); ?>-ajax-response"></span>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Search through the multisite tags.
	 *
	 * @return void
	 */
	public function wp_ajax_ajax_multisite_terms_search() {
		check_ajax_referer( 'nonce-multisite-terms-search', 'security' );

		if ( ! isset( $_GET['tax'] ) ) { // WPCS: input var ok.
			wp_die( 0 );
		}

		$taxonomy = sanitize_key( wp_unslash( $_GET['tax'] ) ); // WPCS: input var ok.
		$tax      = get_multisite_taxonomy( $taxonomy );
		if ( ! $tax ) {
			wp_die( 0 );
		}

		if ( ! current_user_can( $tax->cap->assign_multisite_terms ) ) {
			wp_die( -1 );
		}

		if ( isset( $_GET['q'] ) ) { // WPCS: input var ok.
			$s = sanitize_text_field( wp_unslash( $_GET['q'] ) ); // WPCS: input var ok.
		} else {
			$s = '';
		}

		$comma = _x( ',', 'tag delimiter', 'multitaxo' );
		if ( ',' !== $comma ) {
			$s = str_replace( $comma, ',', $s );
		}
		if ( false !== strpos( $s, ',' ) ) {
			$s = explode( ',', $s );
			$s = $s[ count( $s ) - 1 ];
		}
		$s = trim( $s );

		$args = array(
			'taxonomy'   => $taxonomy,
			'fields'     => 'names',
			'hide_empty' => false,
		);

		if ( '' === $s ) {
			/*
			 * No query yet (the field was just focused): offer a short list of terms so the user
			 * can pick without typing. Alphabetical for a stable, predictable order.
			 */

			/**
			 * Filters how many suggestions the field offers when focused with no query entered.
			 *
			 * @param int                $number The maximum number of suggestions. Default 10.
			 * @param Multisite_Taxonomy $tax    The taxonomy object.
			 */
			$args['number']  = (int) apply_filters( 'multisite_term_search_empty_number', 10, $tax );
			$args['orderby'] = 'name';
			$args['order']   = 'ASC';
		} else {
			/**
			 * Filters the minimum number of characters required to fire a tag search via Ajax.
			 *
			 * @since 4.0.0
			 *
			 * @param int                $characters The minimum number of characters required. Default 2.
			 * @param Multisite_Taxonomy $tax        The taxonomy object.
			 * @param string             $s          The search term.
			 */
			$term_search_min_chars = (int) apply_filters( 'term_search_min_chars', 2, $tax, $s );

			/*
			* Require $term_search_min_chars chars for matching (default: 2)
			* ensure it's a non-negative, non-zero integer.
			*/
			if ( ( 0 === $term_search_min_chars ) || ( strlen( $s ) < $term_search_min_chars ) ) {
				wp_die();
			}

			$args['name__like'] = $s;
		}

		$results = get_multisite_terms( $args );

		echo implode( "\n", $results ); // phpcs:ignore WordPress.Security.EscapeOutput
		wp_die();
	}

	/**
	 * Ajax Get the tag cloud.
	 *
	 * @return void
	 */
	public function wp_ajax_get_multisite_term_cloud() {
		check_ajax_referer( 'nonce-multisite-term-cloud', 'security' );

		if ( ! isset( $_POST['tax'] ) ) { // WPCS: input var ok.
			wp_die( 0 );
		}

		$taxonomy = sanitize_key( wp_unslash( $_POST['tax'] ) ); // WPCS: input var ok.
		$tax      = get_multisite_taxonomy( $taxonomy );
		if ( ! $tax ) {
			wp_die( 0 );
		}

		if ( ! current_user_can( $tax->cap->assign_multisite_terms ) ) {
			wp_die( -1 );
		}

		$term_args = array(
			'taxonomy'   => $taxonomy,
			'number'     => 45,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'hide_empty' => false,
		);

		// Make the multisite term cloud defaults editable.
		$term_args = apply_filters( 'multisite_taxonomy_term_cloud_args', $term_args );

		// Get the terms for the clould.
		$terms = get_multisite_terms( $term_args );

		if ( empty( $terms ) ) {
			wp_die( esc_html( $tax->labels->not_found ) );
		}

		if ( is_wp_error( $terms ) ) {
			wp_die( esc_html( $terms->get_error_message() ) );
		}

		foreach ( $terms as $key => $term ) {
			$terms[ $key ]->link = '#';
			$terms[ $key ]->id   = $term->multisite_term_id;
		}

		// We need raw tag names here, so don't filter the output.
		$return = generate_multisite_term_cloud(
			$terms,
			array(
				'filter' => 0,
				'format' => 'list',
			)
		);

		if ( empty( $return ) ) {
			wp_die( 0 );
		}

		echo $return; // phpcs:ignore WordPress.Security.EscapeOutput

		wp_die();
	}

	/**
	 * Save the custom Taxonomy box.
	 *
	 * @todo: use for users, too.
	 * @todo: better error-handling. - store in a transient, then show error after save?
	 * @todo: is it right to use set_post_multisite_terms instead of set_object_multisite_terms here?
	 *
	 * @access public
	 * @param int $obj_id The object id being edited (like post_id, blog_id, user_id).
	 * @return mixed Void if successful or post_id if not.
	 */
	public function save_multisite_taxonomy( int $obj_id ) {
		/*
		* We need to verify this came from the our screen and with proper authorization,
		* because save_post can be triggered at other times.
		*/

		// Check if our nonce is set.
		if ( ! isset( $_POST['multisite_taxonomy_meta_box_nonce'] ) ) { // WPCS: input var okay.
			return $obj_id;
		}

		// Verify that the nonce is valid.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['multisite_taxonomy_meta_box_nonce'] ) ), 'multisite_taxonomy_meta_box' ) ) { // WPCS: input var okay.
			return $obj_id;
		}

		// If this is an autosave, our form has not been submitted, so we don't want to do anything.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $obj_id;
		}

		if ( ! current_user_can( 'assign_multisite_terms', $obj_id ) ) {
			return $obj_id;
		}

		// @todo: maybe it's more elegant to wrap this function, add another parameter for object-type + add error-handling?!
		$screen = get_current_screen();

		// we are on a profile-page (network-wide or in a blog)
		if ( in_array( $screen->base, array( 'user-edit-network', 'profile-network', 'profile' ), true ) ) {
			$object_type = 'user';
		} else { // we are on a post-page.
			$post        = get_post( $obj_id );
			$object_type = $post->post_type;
		}

		// check if there is a taxonomy registered for this object-type.
		if ( count( (array) get_object_multisite_taxonomies( $object_type ) ) <= 0 ) {
			error_log( 'No obj found for multisite taxonomy...' . __FILE__ . ' on line ' . __LINE__ );
			return $obj_id;
		}

		if ( isset( $_POST['multi_tax_input'] ) ) { // WPCS: Input var OK.
			$multi_tax_input = sanitize_multisite_taxonomy_save_data( wp_unslash( $_POST['multi_tax_input'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		if ( empty( $multi_tax_input ) || ! is_array( $multi_tax_input ) ) {
			return $obj_id;
		}

		// it might make sense, to always set blog_id to 1 for global objects like users or blogs.
		$blog_id = apply_filters( 'multisite_taxonomy_blog_id_before_save', get_current_blog_id(), $object_type, $multi_tax_input );

		// New-style support for all custom taxonomies.
		foreach ( $multi_tax_input as $taxonomy => $terms ) {
			$taxonomy_obj = get_multisite_taxonomy( $taxonomy );

			if ( ! $taxonomy_obj ) {
				/* translators: %s: taxonomy name */
				_doing_it_wrong( __FUNCTION__, esc_html( sprintf( __( 'Invalid multisite-taxonomy: %s.', 'multitaxo' ), $taxonomy ) ), '4.4.0' );
				continue;
			}

			// array = hierarchical, string = non-hierarchical.
			if ( is_array( $terms ) ) {
				$terms = array_filter( $terms );
			}

			if ( current_user_can( $taxonomy_obj->cap->assign_multisite_terms ) ) {
				if ( 'user' === $object_type ) {
					// User relationships live in the 'user' namespace at blog_id = 0 (forced by set_object_multisite_terms).
					set_object_multisite_terms( $obj_id, $this->prepare_terms_for_save( $terms, $taxonomy ), $taxonomy, 0, false, 'user' );
				} else {
					set_post_multisite_terms( $obj_id, $terms, $taxonomy, $blog_id );
				}
			}
		}
	}

	/**
	 * Normalize submitted term input the way set_post_multisite_terms() does, so it can be passed
	 * straight to set_object_multisite_terms() for the user/blog namespaces.
	 *
	 * Flat taxonomies submit a comma-separated string (which must be exploded into individual
	 * names); hierarchical taxonomies submit an array of term IDs.
	 *
	 * @param array|string $terms    The submitted terms.
	 * @param string       $taxonomy The taxonomy name.
	 * @return array Normalized list of term names or IDs.
	 */
	private function prepare_terms_for_save( $terms, $taxonomy ) {
		if ( empty( $terms ) ) {
			return array();
		}

		if ( ! is_array( $terms ) ) {
			$comma = _x( ',', 'tag delimiter', 'multitaxo' );

			if ( ',' !== $comma ) {
				$terms = str_replace( $comma, ',', $terms );
			}

			$terms = explode( ',', trim( $terms, " \n\t\r\0\x0B," ) );
		}

		// Hierarchical taxonomies must pass IDs so same-named children under different parents aren't confused.
		if ( is_multisite_taxonomy_hierarchical( $taxonomy ) ) {
			$terms = array_unique( array_map( 'intval', $terms ) );
		}

		return $terms;
	}

	/**
	 * Save term assignments from the Network Admin site-edit screen (Sites -> Edit Site).
	 *
	 * Hooked on admin_init because wp-admin/network/site-info.php processes the `update-site`
	 * request inline (and then redirects) after admin_init has fired. The submitted fields are
	 * part of the core site-info form, so they are verified against its `edit-site` nonce.
	 *
	 * @return void
	 */
	public function save_multisite_taxonomy_site() {
		// Only act on the network site-info update request.
		if ( ! is_network_admin() ) {
			return;
		}

		if ( ! isset( $_REQUEST['action'] ) || 'update-site' !== $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! isset( $_POST['multi_tax_input'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// Verify the core site-info form nonce (our fields ride along inside that form).
		check_admin_referer( 'edit-site' );

		$blog_id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;
		if ( ! $blog_id || ! current_user_can( 'manage_sites' ) ) {
			return;
		}

		$multi_tax_input = sanitize_multisite_taxonomy_save_data( wp_unslash( $_POST['multi_tax_input'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( empty( $multi_tax_input ) || ! is_array( $multi_tax_input ) ) {
			return;
		}

		foreach ( $multi_tax_input as $taxonomy => $terms ) {
			$taxonomy_obj = get_multisite_taxonomy( $taxonomy );

			if ( ! $taxonomy_obj ) {
				continue;
			}

			if ( current_user_can( $taxonomy_obj->cap->assign_multisite_terms ) ) {
				// Blog relationships live in the 'blog' namespace at blog_id = 0 (forced by set_object_multisite_terms).
				set_object_multisite_terms( $blog_id, $this->prepare_terms_for_save( $terms, $taxonomy ), $taxonomy, 0, false, 'blog' );
			}
		}
	}
}
