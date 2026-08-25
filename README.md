# Multisite Taxonomies

Multisite Taxonomies provides network-wide custom taxonomies for WordPress Multisite. Terms are
stored once for the network and can be assigned to posts, users, or sites without duplicating the
taxonomy on every site.

The plugin provides the storage, administration screens, relationship APIs, and optional front-end
archives. It does not register a taxonomy by itself; applications register the taxonomies they need
with `register_multisite_taxonomy()`.

## Requirements

- WordPress Multisite
- Network activation

## Installation

1. Copy the plugin to `wp-content/plugins/multisite-taxonomies` or install it with Composer.
2. Network-activate **Multisite Taxonomies**.
3. Register at least one taxonomy, preferably from a separate plugin.

The network administration menu remains empty until a taxonomy has been registered.

## Registering a taxonomy

Register multisite taxonomies on `init`, just like native WordPress taxonomies:

```php
add_action( 'init', 'register_network_topics', 0 );

function register_network_topics() {
	$labels = array(
		'name'          => __( 'Topics', 'example-plugin' ),
		'singular_name' => __( 'Topic', 'example-plugin' ),
		'menu_name'     => __( 'Topics', 'example-plugin' ),
		'all_items'     => __( 'All Topics', 'example-plugin' ),
		'add_new_item'  => __( 'Add New Topic', 'example-plugin' ),
		'edit_item'     => __( 'Edit Topic', 'example-plugin' ),
		'update_item'   => __( 'Update Topic', 'example-plugin' ),
		'search_items'  => __( 'Search Topics', 'example-plugin' ),
	);

	register_multisite_taxonomy(
		'network_topic',
		array( 'post', 'user', 'blog' ),
		array(
			'labels'             => $labels,
			'hierarchical'       => false,
			'publicly_queryable' => true,
		)
	);
}
```

The second registration argument accepts post types such as `post` or `page`, plus the special
network object types `user` and `blog`. Relationship functions use these ID namespaces:

| `object_type` argument | Object ID refers to | Stored `blog_id` |
| --- | --- | --- |
| `''` (default) | A post on a site | The site's ID |
| `'user'` | A network user | `0` (network-global) |
| `'blog'` | A site in the network | `0` (network-global) |

All registered post types share the default `''` relationship namespace. A taxonomy may support
several object types at once; the namespace keeps identical numeric IDs for posts, users, and sites
separate.

Taxonomies used by network-admin or AJAX requests must also be registered during those requests.
`is_network_admin()` is false in `admin-ajax.php`; `Multitaxo_Plugin::is_crud_request()` can be used
when conditionally registering a taxonomy for the term-management screens.

## The "Multisite Tags" meta box

One shared meta box edits every applicable taxonomy: on the post editor, on the user profile
screens, and inside the Network Admin site-info form. It offers only the taxonomies registered for
that object type (a `blog` taxonomy never shows up on a post), and it skips a taxonomy registered
with `show_ui => false` or `meta_box_cb => false`.

For a per-screen decision, filter it:

```php
// A block-editor panel of our own edits this taxonomy on the post screens.
add_filter( 'multisite_taxonomy_show_meta_box', function ( $show, $taxonomy, $object_type ) {
	if ( 'network_topic' === $taxonomy->name && 'post' === $object_type ) {
		return ! in_array( $GLOBALS['pagenow'], array( 'post.php', 'post-new.php' ), true );
	}
	return $show;
}, 10, 3 );
```

The box's save handlers ask the same question, so returning `false` hands the taxonomy over
completely: the box neither renders nor writes it. That matters with the block editor, which posts
the meta-box form back to `post.php` *after* its REST save, so a hidden-but-still-saving box would
overwrite whatever the panel just stored.

Note that `$pagenow` is the reliable signal on those screens; the current screen's
`is_block_editor()` flag is unset at `init` (when taxonomies register) and on the meta-box submit.

## Working with terms

The relationship functions use the same basic pattern as the WordPress term APIs. Their last
argument selects the object namespace and defaults to posts:

```php
// Assign a term to a post on the current site.
set_object_multisite_terms( $post_id, 'design', 'network_topic' );

// Assign the same network term to a user and a site.
set_object_multisite_terms( $user_id, 'design', 'network_topic', 0, false, 'user' );
set_object_multisite_terms( $site_id, 'design', 'network_topic', 0, false, 'blog' );

// Read only the user's relationships.
$terms = get_object_multisite_terms( $user_id, 'network_topic', 0, array(), 'user' );
```

For a taxonomy registered exclusively for users or exclusively for sites, the namespace is inferred
when it is omitted. For a taxonomy supporting multiple namespaces, pass it explicitly for user and
site operations.

`get_multisite_terms()` accepts both the plugin's argument-array form and the familiar WordPress
forms:

```php
$terms = get_multisite_terms( array( 'taxonomy' => 'network_topic' ) );
$terms = get_multisite_terms( 'network_topic' );
$terms = get_multisite_terms( 'network_topic', array( 'hide_empty' => false ) );
```

The canonical query key is `taxonomy`. The legacy `multisite_taxonomy` key remains available as a
deprecated alias.

To query relationships for a specific namespace, combine `object_ids` and `object_type`:

```php
$terms = get_multisite_terms(
	array(
		'taxonomy'    => 'network_topic',
		'object_ids'  => array( $user_id ),
		'object_type' => 'user',
	)
);
```

## Front-end archives

A taxonomy registered with `publicly_queryable => true` receives a term URL on each site:

```text
<home_url>/multitaxo/<taxonomy-rewrite-slug>/<term-slug>/
```

The base segment defaults to `multitaxo` and can be changed with the
`multisite_taxonomy_base_url_slug` filter. Build links with `get_multisite_term_link()`:

```php
$link = get_multisite_term_link( $term );

if ( ! is_wp_error( $link ) ) {
	printf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $term->name ) );
}
```

The default archive lists posts belonging to the current site. User and site archives select their
namespace with a query argument:

| Archive | URL |
| --- | --- |
| Posts | `.../multitaxo/network_topic/design/` |
| Users | `.../multitaxo/network_topic/design/?multisite_object_type=user` |
| Sites | `.../multitaxo/network_topic/design/?multisite_object_type=blog` |

User and site archives expose network-wide objects and are therefore restricted to super admins.
Requests for a namespace the taxonomy does not support return a 404.

Themes may provide archive templates in this order:

```text
multisite-taxonomy-user-<taxonomy>.php
multisite-taxonomy-user.php
multisite-taxonomy-blog-<taxonomy>.php
multisite-taxonomy-blog.php
multisite-taxonomy-<taxonomy>.php
multisite-taxonomy.php
```

If no theme template matches a user or site archive, the plugin uses
`templates/multisite-taxonomy-objects.php`. Relevant template helpers include:

```php
is_multisite_taxonomy_archive();
get_queried_multisite_term();
is_multisite_taxonomy_object_archive();
get_queried_multisite_object_type();
get_multisite_taxonomy_archive_objects();
```

The `multisite_taxonomy_archive_object_item` filter customizes a row in the bundled template, and
`multisite_taxonomy_archive_objects_per_page` controls its page size.

## Term settings

Applications can register boolean settings stored on individual terms:

```php
register_multisite_term_setting(
	'network_topic',
	'featured',
	array(
		'label'       => __( 'Featured', 'example-plugin' ),
		'description' => __( 'Highlight objects assigned to this topic.', 'example-plugin' ),
		'default'     => false,
	)
);

$featured = get_multisite_term_setting( $term_id, 'featured', false );
```

Registered settings appear on the network term add and edit screens and are stored in the network
term-meta table. Programmatic term updates do not overwrite them unless the settings fields are
submitted.

## Capabilities

Taxonomy capabilities are configured through the `capabilities` registration argument. Assignment
checks are resolved by:

```php
get_multisite_taxonomy_assign_cap( $taxonomy, $object_type );
current_user_can_assign_multisite_terms( $taxonomy, $object_type );
```

Use the `multisite_taxonomy_assign_cap` filter to vary assignment permission by namespace. For
example, a taxonomy can require network-management permission for user relationships while allowing
editors to assign the same terms to posts. The filter receives the registered taxonomy object:

```php
add_filter(
	'multisite_taxonomy_assign_cap',
	function ( $capability, $taxonomy, $object_type ) {
		if ( $taxonomy instanceof Multisite_Taxonomy
			&& 'network_topic' === $taxonomy->name
			&& 'user' === $object_type
		) {
			return 'manage_network';
		}

		return $capability;
	},
	10,
	3
);
```

When a user can view but cannot assign a taxonomy, the editor displays its current terms as a
read-only list. The explanatory text can be changed with `multisite_taxonomy_read_only_note`.

## Cross-site post queries

`Multisite_WP_Query` queries related posts across the network. By default it includes published,
password-free posts from publicly queryable post types; user and site relationships are excluded.

- `multisite_wp_query_access` may return a `WP_Error` to reject a query before it or its cache runs.
- `multisite_wp_query_post_types` changes the public post types included for each source site.

Installations with additional site-privacy rules should use the access filter to verify every source
site before allowing an aggregated query.

## Database tables

The plugin stores terms, term metadata, taxonomy records, and relationships in four
`<network-prefix>multisite_*` tables. Network activation creates the schema. On administration, cron,
and WP-CLI requests, the plugin periodically verifies that the tables still exist and recreates a
missing schema. A repair is logged because recreating a table cannot restore its former data.

Deleting a site removes that site's post relationships. User and site relationships are
network-global and use `blog_id = 0`.

## Logging

The plugin logs the few things a developer must see (a repaired schema, a failed write) through the
`multitaxo_log` action, and writes to `error_log()` only while nothing is hooked:

```php
add_action( 'multitaxo_log', function ( $level, $message, $source ) {
	my_logger( $level, $message, $source ); // $level is a PSR-3 level, $source a caller id.
}, 10, 3 );
```

The plugin has no knowledge of the host's logger, so an install with its own logging keeps these
lines out of `error_log()` by hooking the action.

## Development

Install development dependencies and run the coding-standard checks:

```sh
composer install
vendor/bin/phpcs --standard=phpcs.xml .
```

The PHPUnit suite requires the WordPress test library and runs in multisite mode:

```sh
composer install-test
vendor/bin/phpunit -c multisite.xml
```
