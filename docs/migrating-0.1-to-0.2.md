# Migrating from 0.1 to 0.2

0.2 makes an object's identity a value instead of three loose arguments, because `$object_type`
and `$blog_id` are two halves of one key and applying one without the other fails open (the
[Notes](../README.md#notes) in the README say why). The old relationship functions still work and
still behave the same way; they carry `@deprecated` tags, and runtime notices arrive in 0.3.

| 0.1 | 0.2 |
| --- | --- |
| `get_object_multisite_terms( $id, $tax, $blog, $args, $type )` | `Multisite_Object::post( $id, $blog )->terms( $tax, $args )` |
| `get_object_multisite_terms( $uid, $tax, 0, array( 'fields' => 'ids' ), 'user' )` | `Multisite_Object::user( $uid )->term_ids( $tax )` |
| `set_object_multisite_terms( $id, $terms, $tax, $blog, false, $type )` | `Multisite_Object::…( $id, $blog )->set_terms( $tax, $terms )` |
| `set_object_multisite_terms( …, true, $type )` | `->add_terms( $tax, $terms )` |
| `add_object_multisite_terms( $id, $terms, $tax, $blog, $type )` | `->add_terms( $tax, $terms )` |
| `remove_object_multisite_terms( $id, $terms, $tax, $blog, $type )` | `->remove_terms( $tax, $terms )` |
| `is_object_in_multsite_term( $id, $tax, $terms, $blog, $type )` | `->has_term( $tax, $terms )` |
| `get_multisite_term_object_ids( $term, $tax, $type, $args )` | `multisite_term_objects( $term, $tax, $args )` |
| `get_multisite_term_objects_by_type( $term, $tax )` | `multisite_term_objects( $term, $tax )->grouped()` |
| `get_objects_in_multisite_term( $terms, $taxes, $args )` | `multisite_term_objects( $terms, $tax, $args )` |

Three mappings are not one-to-one:

**`get_multisite_term_object_ids()` returned `array( 'ids' => …, 'total' => … )` for one namespace.**
`multisite_term_objects()` returns every namespace as a set, and the total is a separate question:
`multisite_term_object_counts()`, which answers it for many terms in one query. To keep the old
shape, scope the call and read `ids()`:

```php
$ids = multisite_term_objects( $term, $tax, array( 'scope' => Multisite_Object_Scope::posts_on( $blog_id ) ) )->ids_of( '' );
```

**A namespace that used to be inferred now has to be named.** Calls that omitted `$object_type` for
a user-only or site-only taxonomy were relying on `resolve_multisite_object_type()`. Replacing them
with `Multisite_Object::user()` or `::blog()` is the whole change; replacing them with `::post()`
silently reads the wrong namespace.

**`get_object_multisite_taxonomies()` matches registered names, not namespaces.** Passing it a
normalized `''` returns nothing. Use `get_multisite_taxonomies_for_namespace()` when the question is
about relationships, and keep the old function for admin code that thinks in post types.

`Multisite_Term_Query` is unchanged and still accepts `object_type`, `blog_id` and `object_scope`.
`Multisite_Object_Scope` is unchanged too, and gained the named filters `posts()`, `posts_on()`,
`users()` and `blogs()`.

Two 0.2 changes are behavioural rather than API changes: deleting a user from the network now
removes their relationship rows, and deleting a site now also removes the row that tags the site
itself. Both are stored network-globally, so nothing reached them before and they outlived what
they described, inflating term counts.
