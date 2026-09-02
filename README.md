# Multisite Taxonomies

> **Fork notice: not drop-in compatible with the original.** This forks
> [HarvardChanSchool/multisite-taxonomies](https://github.com/HarvardChanSchool/multisite-taxonomies)
> and breaks with it twice. The relationships table gained an `object_type` column inside its
> primary key, and activating this version migrates the table one way (tracked in the
> `multitaxo_db_version` site option). And a relationship is always read for one site and one
> namespace, so a read naming neither used to span the network and now means "posts on the current
> site". [docs/migrating-0.1-to-0.2.md](docs/migrating-0.1-to-0.2.md) covers the API side.

Network-wide custom taxonomies for WordPress Multisite: a term is stored once for the whole network
and assigned to posts, users and sites, instead of being duplicated on every site.

The plugin ships the storage, the network admin screens, the relationship API and optional
front-end archives. It registers no taxonomy itself; applications register what they need with
`register_multisite_taxonomy()`.

## Installation

Requires WordPress Multisite.

1. Install with Composer, or copy the plugin to `wp-content/plugins/multisite-taxonomies`.
2. Network-activate **Multisite Taxonomies**.
3. Register at least one taxonomy, preferably from a plugin of your own. The network admin menu
   stays empty until something does.

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

Every post type shares the default `''` namespace. A taxonomy may support several object types at
once, and the namespace is what keeps post 42, user 42 and site 42 apart.

A taxonomy registered only in the network admin goes missing on its own term screens, whose add and
inline-edit forms submit over `admin-ajax.php`, where `is_network_admin()` is false. Register on the
`multisite_taxonomies_register` action instead: it fires in exactly the requests where term CRUD
happens (network admin, WP-CLI, this plugin's ajax actions). To gate registration by hand, ask
`Multitaxo_Plugin::is_crud_request()`.

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

`$pagenow` is the reliable signal on those screens: the current screen's `is_block_editor()` flag
is unset at `init` (when taxonomies register) and on the meta-box submit.

## Working with terms

An object is a namespace, a site and an ID together: post 42, user 42 and site 42 are three
different things, and post 42 exists on every site. `Multisite_Object` is that identity, and it is
how relationships are read and written.

```php
Multisite_Object::post( $post_id )->set_terms( 'network_topic', 'design' );
Multisite_Object::post( $post_id, $blog_id )->set_terms( 'network_topic', 'design' );
Multisite_Object::user( $user_id )->set_terms( 'network_topic', 'design' );
Multisite_Object::blog( $site_id )->set_terms( 'network_topic', 'design' );

$terms = Multisite_Object::user( $user_id )->terms( 'network_topic' );
$ids   = Multisite_Object::user( $user_id )->term_ids( 'network_topic' );
```

The named constructors are the only way in, so a namespace is never left unstated. `post()` takes
the site the ID belongs to and defaults to the current one; `user()` and `blog()` take no site at
all, because those relationships are network-global and always stored at `blog_id = 0`.

`post()` resolves that default **when the object is built**, not when it is used, so an object
constructed before a `switch_to_blog()` still points at the old site. Build it inside the switch,
or name the site.

The rest of the surface:

```php
$object->add_terms( 'network_topic', array( $term_id ) );     // keep what is already there
$object->remove_terms( 'network_topic', array( $term_id ) );
$object->has_term( 'network_topic', $term_id );               // or no term: "carries anything?"
$object->resolve();                                           // WP_Post | WP_User | WP_Site | null
$object->scope();                                             // the (namespace, site) pair
$object->key();                                               // identity string, safe as an array key
```

Use `$object->key()` wherever objects from different namespaces share an array. Plain IDs collide;
these do not.

### Terms shared across namespaces

A taxonomy registered for more than one namespace can tag posts, users and sites with the same
term. The reverse question then has a mixed answer, so it comes back as a `Multisite_Object_Set`
rather than a list of IDs, which could not tell a user from a post:

```php
$objects = multisite_term_objects( $term_id, 'network_topic' );

count( $objects );          // everything carrying the term
$objects->of( 'user' );     // just the users
$objects->on( $blog_id );   // just one site's posts
$objects->counts();         // array( '' => 12, 'user' => 3, 'blog' => 1 )
$objects->ids_of( 'user' ); // a flat list, for a single-namespace read
$objects->grouped();        // namespace => Multisite_Object_Set

foreach ( $objects->of( 'user' )->hydrate() as $user ) {
	echo esc_html( $user->display_name );
}
```

`hydrate()` groups by site and namespace first, so it costs one query per group and at most one
`switch_to_blog()` per site, however the set is ordered. Objects that no longer exist are dropped.

Restrict the query itself with a scope, rather than reading everything and filtering:

```php
$users = multisite_term_objects( $term_id, 'network_topic', array( 'scope' => Multisite_Object_Scope::users() ) );
```

Pass an array of term IDs to union a term with its descendants. Objects are de-duplicated across
the union, so an object tagged under both a parent and a child appears once.

A term's `count` column is a single total across every namespace and site, which is what
`hide_empty` needs and not what a mixed taxonomy wants to display. Per-namespace numbers come from
one grouped query, for a whole list of terms at once:

```php
$counts = multisite_term_object_counts( $term_ids, 'network_topic' );
// array( 17 => array( '' => 12, 'user' => 3 ), 18 => array( 'user' => 1 ) )

multisite_term_object_count( $term_id, 'network_topic', 'user' );
```

Registration mixes two vocabularies in one array: post types and ID namespaces. Relationships only
ever store the namespace, where every post type is `''`, so the taxonomy answers both questions
separately:

```php
$tax = get_multisite_taxonomy( 'network_topic' );

$tax->namespaces();               // array( '', 'user' )
$tax->post_types();               // array( 'post', 'project' )
$tax->supports_namespace( 'user' );
$tax->is_mixed();                 // more than one namespace, so nothing can be inferred

get_multisite_taxonomies_for_namespace( 'user' );  // taxonomy names, by namespace
```

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

`blog_id` names the site the object IDs belong to and defaults to `0`, the current site. It works
with or without `object_ids`: on its own it lists the terms in use on that site.

```php
$terms = get_multisite_terms(
	array(
		'taxonomy' => 'network_topic',
		'blog_id'  => $blog_id,
	)
);
```

A relationship query that names neither `object_type` nor `blog_id` is read as the current site's
post namespace. Reading across every site is possible, but has to be asked for with
`'blog_id' => null`, because the rows it returns may belong to different objects on different
sites.

The namespace and the site are one value, `Multisite_Object_Scope`, and `object_scope` passes it
directly. Prefer it in code that carries the pair around, so that neither half can be applied
without the other:

```php
$scope = Multisite_Object_Scope::for_taxonomy( 'user', 'network_topic' );

$terms = get_multisite_terms(
	array(
		'taxonomy'     => 'network_topic',
		'object_ids'   => array( $user_id ),
		'object_scope' => $scope,
	)
);

// The same value builds the SQL condition for a hand-written relationship query.
$where = $scope->where( 'tr' ); // "tr.object_type = 'user' AND tr.blog_id = 0"
```

`Multisite_Object_Scope::across_blogs( $object_type )` and `Multisite_Object_Scope::any()` are the
deliberately unpinned variants.

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

User and site relationships are network-global and use `blog_id = 0`. Deleting a site removes both
that site's post relationships and the row that tags the site itself, and deleting a user removes
that user's rows. Affected term counts are recalculated afterwards.

## Migrating from 0.1 to 0.2

0.2 replaced the loose relationship arguments with `Multisite_Object`. The old functions still work
and still behave the same way, now carrying `@deprecated` tags. The call-by-call mapping, the three
cases that are not one-to-one, and the two behavioural changes are in
[docs/migrating-0.1-to-0.2.md](docs/migrating-0.1-to-0.2.md).

## Notes

A relationship row is keyed by `(blog_id, object_id, multisite_term_multisite_taxonomy_id,
object_type)`. Two of those columns describe the object: `object_type` is the ID namespace and
`blog_id` the site a post ID belongs to. Neither identifies a row on its own, and applying one
without the other fails open: the read silently spans the network and returns rows for a
same-numbered object somewhere else.

`Multisite_Object_Scope` exists so the pair cannot come apart: it is the only place the two SQL
predicates and the relationship cache group are written. `Multisite_Object` is that scope plus an
ID, and it is what call sites should hold, because its named constructors make an unstated
namespace impossible to express. `Multisite_Term_Query` still accepts the two loose query vars and
builds a scope from them, pinning an unstated relationship read to the current site's post
namespace.

The two directions are independent axes and stay that way: an object has terms in many taxonomies,
and a term has objects in many namespaces. Neither the scope nor the object carries a taxonomy;
the taxonomy belongs to the operation.

Both classes carry their whole identity in private properties, so a plain `json_encode()` would
render every value as `{}`. They are `JsonSerializable` and encode to their `key()` instead, which
keeps them usable inside a structure a caller hashes. A memoization key built from query args
(`md5( wp_json_encode( $args ) )`) is the usual case, and it would otherwise return one scope's
result for another's.

Cleanup of network-global rows hangs off deletion hooks, and `deleted_user` is the trap: on
multisite `wp_delete_user()` only removes the user from the current site, yet fires the action
anyway. Only `wpmu_delete_user()` really deletes them. The handler therefore reads `$wpdb->users`
directly (the caches were just cleared) and purges only when the row is gone; trusting the action
would strip a user's terms network-wide the first time an admin removed them from one site.

The object-to-terms cache group is derived from the scope
(`<blog>_<namespace>_<taxonomy>_multisite_relationships`), because a cached list of term IDs keyed
by object ID means nothing without one. Anything writing that cache by hand has to use
`Multisite_Object_Scope::cache_group()`, or its entries will not be found or invalidated.

## Logging

The plugin logs the few things a developer must see (a repaired schema, a failed write) through the
`multitaxo_log` action, and writes to `error_log()` only while nothing is hooked:

```php
add_action( 'multitaxo_log', function ( $level, $message, $source ) {
	my_logger( $level, $message, $source ); // $level is a PSR-3 level, $source a caller id.
}, 10, 3 );
```

The plugin knows nothing about the host's logger, so an install with its own logging hooks the
action to keep these lines out of `error_log()`.

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
