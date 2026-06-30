# Multisite Taxonomies
## A WordPress plugin
Multisite Taxonomies brings the ability to register custom taxonomies, accessible on an entire multisite network, to WordPress.

Master branch: [![CircleCI](https://circleci.com/gh/HarvardChanSchool/multisite-taxonomies.svg?style=svg)](https://circleci.com/gh/HarvardChanSchool/multisite-taxonomies)

## Coding standards
We follow [WordPress Coding Standards](https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards) and enforce them using PHP Code Sniffer.

To test localy simply run:
- `$ composer install` (if you haven't already)
- `$ ./vendor/bin/phpcs ./`

### Dependencies:
- [Composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-osx) (globally installed)

### How to get started?
- Start by copying the plugin to your website's WordPress plugin directory.
- Activate the plugin. 
- A Multisite Taxonomy menu will appear in the admin but it will be blank. 
- Add taxonomies to the website by using register_multisite_taxonomy called on the `init` hook. We would recommend doing this in a separate plugin of your creation. 
- Multisite tags can then be added to posts through the post edit screen on any site on the network.

### Register Taxonomy Example:

```php
add_action( 'init', 'register_multisite_taxonomies', 0 );

/**
 * Load in all taxonomies.
 *
 * @return void
 */
function register_multisite_taxonomies() {
    /**
     * Load taxonomy for Tags
     */
    $labels     = array(
        'name'                       => __( 'Tags', 'hsph-plugin-tagging' ),
        'singular_name'              => __( 'Tag', 'hsph-plugin-tagging' ),
        'menu_name'                  => __( 'Tags', 'hsph-plugin-tagging' ),
        'all_items'                  => __( 'All Tags', 'hsph-plugin-tagging' ),
        'new_item_name'              => __( 'New Tag Name', 'hsph-plugin-tagging' ),
        'add_new_item'               => __( 'Add New Tag', 'hsph-plugin-tagging' ),
        'edit_item'                  => __( 'Edit Tag', 'hsph-plugin-tagging' ),
        'update_item'                => __( 'Update Tag', 'hsph-plugin-tagging' ),
        'view_item'                  => __( 'View Tag', 'hsph-plugin-tagging' ),
        'separate_items_with_commas' => __( 'Separate tags with commas', 'hsph-plugin-tagging' ),
        'add_or_remove_items'        => __( 'Add or remove tags', 'hsph-plugin-tagging' ),
        'choose_from_most_used'      => __( 'Choose from the most used tags', 'hsph-plugin-tagging' ),
        'popular_items'              => __( 'Popular Tags', 'hsph-plugin-tagging' ),
        'search_items'               => __( 'Search Tags', 'hsph-plugin-tagging' ),
        'not_found'                  => __( 'No Tags Found', 'hsph-plugin-tagging' ),
        'no_terms'                   => __( 'No tags for this category', 'hsph-plugin-tagging' ),
        'most_used'                  => __( 'Most Used', 'hsph-plugin-tagging' ),
        'items_list'                 => __( 'Tags list', 'hsph-plugin-tagging' ),
        'items_list_navigation'      => __( 'Tags list navigation', 'hsph-plugin-tagging' ),
    );

    $args       = array(
        'labels'       => $labels,
        'hierarchical' => false,
    );
    
    $post_types = apply_filters( 'multisite_taxonomy_tags_post_types', array( 'post' ) );
    register_multisite_taxonomy( 'tag', $post_types, $args );
}
```

### Object types (posts, users, and blogs)

A single multisite taxonomy can be shared across more than one kind of object. Every
relationship is stored with an `object_type` that namespaces the object ID:

| `object_type` | Object ID refers to | `blog_id` stored |
| ------------- | ------------------- | ---------------- |
| `''` (default) | a post on a blog   | the blog's ID    |
| `'user'`       | a network user      | `0` (network-global) |
| `'blog'`       | a blog/site         | `0` (network-global) |

Pass the `object_type` as the last argument to the relationship functions. It defaults to
`''` (the post namespace), so existing post code keeps working unchanged. The `blog_id`
argument is ignored for `'user'` and `'blog'` rows — they are always network-global.

```php
// Assign the "design" affiliation to a post (post namespace — the default).
set_object_multisite_terms( $post_id, 'design', 'affiliation' );

// Assign the same term to a user (network-global; blog_id is forced to 0).
set_object_multisite_terms( $user_id, 'design', 'affiliation', 0, false, 'user' );

// ...and to a whole blog/site.
set_object_multisite_terms( $blog_id, 'design', 'affiliation', 0, false, 'blog' );

// Read a user's terms back. Reads never cross namespaces, so this returns only
// the user's relationships — not the post or blog with the same numeric ID.
$user_terms = get_object_multisite_terms( $user_id, 'affiliation', 0, array(), 'user' );

// Restrict a term query to the user namespace via object_type. Filtering only kicks
// in alongside object_ids, so reads stay byte-compatible with existing post queries.
$user_terms_only = get_multisite_terms(
    array(
        'taxonomy'    => 'affiliation',
        'object_ids'  => $user_id,
        'object_type' => 'user',
    )
);
```

If a taxonomy is registered for exactly one of `'user'` or `'blog'`, you may omit the
`object_type` argument entirely and it will be inferred from the taxonomy.

### URLs and term archives

A publicly queryable taxonomy registers a per-taxonomy permastruct and query var, so every
term has a front-end archive URL. The structure is:

```
<home_url>/multitaxo/<taxonomy-rewrite-slug>/<term-slug>/
```

`home_url()` is the **current blog's** home, so the same term resolves to a different URL on
each site — `…/site-a/multitaxo/affiliation/design/` vs `…/site-b/multitaxo/affiliation/design/`
— and each archive lists only that blog's posts. (`multitaxo` is the shared base slug,
overridable via the `multisite_taxonomy_base_url_slug` filter.)

Build the URL with `get_multisite_term_link()`. The term object already carries its
`multisite_taxonomy`, so you don't pass the taxonomy explicitly:

```php
$link = get_multisite_term_link( $term );   // string URL, or WP_Error if the term is invalid
if ( ! is_wp_error( $link ) ) {
    printf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $term->name ) );
}
```

`Multisite_Taxonomy_Archive` (`inc/class-multisite-taxonomy-archive.php`) turns a hit on that
permastruct into a normal posts archive for the current blog: it sets `is_archive`, scopes the
main loop to the term via `posts_clauses`, lets a theme override the template
(`multisite-taxonomy-<tax>.php` → `multisite-taxonomy.php` → the theme's archive), and 404s on
an unknown slug. The blog root is left untouched, so a front-page `?affiliation=` stream is not
hijacked. Check the request with `is_multisite_taxonomy_archive()` /
`get_queried_multisite_term()`.

#### How the URL distinguishes posts, users, and blogs

The path itself does **not** encode the object type — that is deliberate, so the clean URL
stays backwards compatible. The namespace rides along in an optional `multisite_object_type`
query var on the same URL:

| Object type | URL                                                        |
| ----------- | ---------------------------------------------------------- |
| post (default) | `…/multitaxo/affiliation/design/`                       |
| user        | `…/multitaxo/affiliation/design/?multisite_object_type=user` |
| blog        | `…/multitaxo/affiliation/design/?multisite_object_type=blog` |

An absent or empty `multisite_object_type` means posts (`object_type=''`), so any link built
before object types existed — and every plain `get_multisite_term_link()` URL — keeps resolving
to a posts archive.

#### Users and sites archives

`?multisite_object_type=user` and `=blog` render an archive of the **users** / **sites** assigned
to the term, but only when the taxonomy is registered for that namespace
(`multisite_taxonomy_supports_object_type()`); a request for an unsupported namespace is rejected
with an explicit 404 (`wp_die`) rather than silently falling back to a posts archive.

Because these archives list network-global objects (users/sites across the whole network), they
are **restricted to super admins** — a non-super-admin request is denied with a 403. The plain
posts archive (default URL, no `multisite_object_type`) is unaffected and stays public.

These namespaces have no native WordPress loop, so the controller does not run a posts query for
them. Instead it loads the network-global `WP_User` / `WP_Site` objects itself (paginated via the
relationship table), neutralizes the main posts query, and corrects the main query's
`found_posts` / `max_num_pages` so the theme's normal `the_posts_pagination()` works. Pagination
uses the permastruct's `/page/N/`; a request past the last page 404s like any archive.

Template lookup is object-type aware and falls back to a bundled template so the route works on
any theme out of the box:

```
user: multisite-taxonomy-user-<tax>.php → multisite-taxonomy-user.php ┐
blog: multisite-taxonomy-blog-<tax>.php → multisite-taxonomy-blog.php ┤→ multisite-taxonomy-<tax>.php
                                                                      ┘→ multisite-taxonomy.php
                                                  → (plugin) templates/multisite-taxonomy-objects.php
```

A theme template (or the bundled one) builds its list with these template tags:

```php
if ( is_multisite_taxonomy_object_archive() ) {
    $type    = get_queried_multisite_object_type();          // 'user' or 'blog'
    $objects = get_multisite_taxonomy_archive_objects();     // WP_User[] | WP_Site[] for this page
    foreach ( $objects as $object ) {
        // $object is a WP_User or WP_Site; render it.
    }
    the_posts_pagination();                                  // works against the corrected totals
}
```

The bundled template emits one `multisite_taxonomy_archive_object_item` filter per row so consumers
can customize the markup without copying the whole template. The underlying paginated reader is
`get_multisite_term_object_ids( $term_id, $tax, $object_type, $args )` (returns `ids` + `total`),
and `multisite_taxonomy_archive_objects_per_page` filters the page size (defaults to the
"Blog pages show at most" setting).
