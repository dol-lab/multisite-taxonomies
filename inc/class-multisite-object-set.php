<?php
/**
 * A heterogeneous list of tagged objects.
 *
 * @package multitaxo
 */

/**
 * The objects carrying a term, across namespaces and blogs.
 *
 * A term shared by posts, users and sites cannot answer "who carries me?" with a list of integers:
 * IDs collide across namespaces and across blogs, so the answer has to keep each object's identity
 * with it. This is that answer, plus the two things every consumer of a mixed taxonomy ends up
 * writing by hand: splitting the result per namespace, and turning rows into real WordPress
 * objects without a `switch_to_blog()` per row.
 *
 * The set is immutable; `of()`, `on()` and `in()` return new sets.
 */
final class Multisite_Object_Set implements IteratorAggregate, Countable {

	/**
	 * The objects, keyed by {@see Multisite_Object::key()} so duplicates collapse.
	 *
	 * @access private
	 * @var Multisite_Object[]
	 */
	private $objects = array();

	/**
	 * Use {@see Multisite_Object_Set::from_objects()} or {@see Multisite_Object_Set::from_rows()}.
	 *
	 * @param Multisite_Object[] $objects Objects keyed by their identity string.
	 */
	private function __construct( array $objects ) {
		$this->objects = $objects;
	}

	/**
	 * Build a set from objects, in the order given.
	 *
	 * @param Multisite_Object[] $objects Objects to include. Duplicates collapse.
	 * @return self
	 */
	public static function from_objects( array $objects ) {
		$keyed = array();
		foreach ( $objects as $object ) {
			if ( $object instanceof Multisite_Object ) {
				$keyed[ $object->key() ] = $object;
			}
		}

		return new self( $keyed );
	}

	/**
	 * Build a set from relationships table rows.
	 *
	 * @param array $rows Rows carrying object_id, blog_id and object_type.
	 * @return self
	 */
	public static function from_rows( array $rows ) {
		return self::from_objects( array_map( array( 'Multisite_Object', 'from_row' ), $rows ) );
	}

	/**
	 * An empty set.
	 *
	 * @return self
	 */
	public static function empty_set() {
		return new self( array() );
	}

	/**
	 * The objects, as a list.
	 *
	 * @return Multisite_Object[]
	 */
	public function all() {
		return array_values( $this->objects );
	}

	/**
	 * The objects, keyed by their identity string.
	 *
	 * @return Multisite_Object[]
	 */
	public function keyed() {
		return $this->objects;
	}

	/**
	 * The first object, or null when the set is empty.
	 *
	 * @return Multisite_Object|null
	 */
	public function first() {
		foreach ( $this->objects as $object ) {
			return $object;
		}

		return null;
	}

	/**
	 * Only the objects in one namespace.
	 *
	 * @param string $object_type Raw object type: '', a post type, 'user' or 'blog'.
	 * @return self
	 */
	public function of( $object_type ) {
		$object_type = normalize_multisite_object_type( $object_type );

		return $this->filter(
			function ( Multisite_Object $candidate ) use ( $object_type ) {
				return $candidate->object_type() === $object_type;
			}
		);
	}

	/**
	 * Only the objects belonging to one blog.
	 *
	 * @param int $blog_id Blog ID. 0 matches the network-global namespaces.
	 * @return self
	 */
	public function on( $blog_id ) {
		$blog_id = (int) $blog_id;

		return $this->filter(
			function ( Multisite_Object $candidate ) use ( $blog_id ) {
				return $candidate->blog_id() === $blog_id;
			}
		);
	}

	/**
	 * Only the objects matching a scope. An unpinned half matches everything.
	 *
	 * @param Multisite_Object_Scope $scope Scope to match against.
	 * @return self
	 */
	public function in( Multisite_Object_Scope $scope ) {
		return $this->filter(
			function ( Multisite_Object $candidate ) use ( $scope ) {
				$type_ok = null === $scope->object_type() || $scope->object_type() === $candidate->object_type();
				$blog_ok = null === $scope->blog_id() || $scope->blog_id() === $candidate->blog_id();

				return $type_ok && $blog_ok;
			}
		);
	}

	/**
	 * The set split by namespace.
	 *
	 * @return array Namespace ('', 'user', 'blog') => Multisite_Object_Set. Empty namespaces are omitted.
	 */
	public function grouped() {
		$groups = array();
		foreach ( $this->objects as $key => $object ) {
			$groups[ $object->object_type() ][ $key ] = $object;
		}

		return array_map(
			function ( $objects ) {
				return new self( $objects );
			},
			$groups
		);
	}

	/**
	 * How many objects each namespace contributes.
	 *
	 * @return array Namespace ('', 'user', 'blog') => int. Empty namespaces are omitted.
	 */
	public function counts() {
		return array_map( 'count', $this->grouped() );
	}

	/**
	 * The object IDs, per namespace.
	 *
	 * Deliberately not a flat list: IDs from different namespaces mean different things.
	 *
	 * @return array Namespace ('', 'user', 'blog') => int[].
	 */
	public function ids() {
		$ids = array();
		foreach ( $this->objects as $object ) {
			$ids[ $object->object_type() ][] = $object->id();
		}

		return $ids;
	}

	/**
	 * The object IDs in one namespace, as a flat list.
	 *
	 * The shape a single-namespace read wants, where {@see Multisite_Object_Set::ids()} would
	 * hand back a map with one key. Post IDs still come from every blog in the set unless the
	 * set was scoped to one, so treat them as IDs only, not as identities.
	 *
	 * @param string $object_type Raw object type: '', a post type, 'user' or 'blog'.
	 * @return int[]
	 */
	public function ids_of( $object_type ) {
		$ids = array();
		foreach ( $this->of( $object_type ) as $object ) {
			$ids[] = $object->id();
		}

		return $ids;
	}

	/**
	 * The WordPress objects behind these identities.
	 *
	 * Objects are grouped by (blog, namespace) first, so this costs one query per group and at
	 * most one `switch_to_blog()` per blog, however the set is ordered. Objects that no longer
	 * exist are skipped, so the result can be shorter than the set.
	 *
	 * @return array Identity string => WP_Post|WP_User|WP_Site, in the set's order.
	 */
	public function hydrate() {
		$by_scope = array();
		foreach ( $this->objects as $object ) {
			$by_scope[ $object->scope()->key() ]['scope'] = $object->scope();
			$by_scope[ $object->scope()->key() ]['ids'][] = $object->id();
		}

		$resolved = array();
		foreach ( $by_scope as $group ) {
			$resolved += $this->hydrate_group( $group['scope'], array_unique( $group['ids'] ) );
		}

		// Restore the set's own order, dropping whatever no longer exists.
		$ordered = array();
		foreach ( $this->objects as $key => $object ) {
			if ( isset( $resolved[ $key ] ) ) {
				$ordered[ $key ] = $resolved[ $key ];
			}
		}

		return $ordered;
	}

	/**
	 * Hydrate one (blog, namespace) group in a single query.
	 *
	 * @access private
	 *
	 * @param Multisite_Object_Scope $scope Scope shared by every ID in the group.
	 * @param int[]                  $ids   Object IDs.
	 * @return array Identity string => WP_Post|WP_User|WP_Site.
	 */
	private function hydrate_group( Multisite_Object_Scope $scope, array $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}

		$found = array();

		switch ( $scope->object_type() ) {
			case 'user':
				foreach ( get_users(
					array(
						'include' => $ids,
						'blog_id' => 0,
						'number'  => count( $ids ),
					)
				) as $user ) {
					$found[ Multisite_Object::user( $user->ID )->key() ] = $user;
				}
				break;

			case 'blog':
				foreach ( get_sites(
					array(
						'site__in' => $ids,
						'number'   => count( $ids ),
					)
				) as $site ) {
					$found[ Multisite_Object::blog( $site->blog_id )->key() ] = $site;
				}
				break;

			default:
				$blog_id  = $scope->blog_id();
				$switched = false;
				if ( $blog_id > 0 && get_current_blog_id() !== $blog_id ) {
					switch_to_blog( $blog_id );
					$switched = true;
				}

				$posts = get_posts(
					array(
						'post__in'            => $ids,
						'post_type'           => 'any',
						'post_status'         => 'any',
						'posts_per_page'      => count( $ids ),
						'ignore_sticky_posts' => true,
						'no_found_rows'       => true,
						'suppress_filters'    => false,
					)
				);

				foreach ( $posts as $post ) {
					$found[ Multisite_Object::post( $post->ID, $blog_id )->key() ] = $post;
				}

				if ( $switched ) {
					restore_current_blog();
				}
				break;
		}

		return $found;
	}

	/**
	 * Whether the set holds nothing.
	 *
	 * @return bool
	 */
	public function is_empty() {
		return empty( $this->objects );
	}

	/**
	 * Number of objects in the set.
	 *
	 * @return int
	 */
	#[\ReturnTypeWillChange]
	public function count() {
		return count( $this->objects );
	}

	/**
	 * Iterate the objects, keyed by their identity string.
	 *
	 * @return ArrayIterator
	 */
	#[\ReturnTypeWillChange]
	public function getIterator() {
		return new ArrayIterator( $this->objects );
	}

	/**
	 * A new set holding the objects a callback keeps.
	 *
	 * @access private
	 *
	 * @param callable $keep Receives a Multisite_Object, returns bool.
	 * @return self
	 */
	private function filter( callable $keep ) {
		return new self( array_filter( $this->objects, $keep ) );
	}
}
