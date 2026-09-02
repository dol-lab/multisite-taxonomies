<?php
/**
 * A single taggable object: which namespace, which blog, which ID.
 *
 * @package multitaxo
 */

/**
 * Identity of an object a multisite term can be assigned to.
 *
 * An ID alone does not identify anything on a network: post 42, user 42 and site 42 are three
 * different things, and post 42 exists on every blog. The relationship table has always known
 * this ({@see Multisite_Object_Scope}), but callers passed the three parts as separate arguments
 * and could apply one without the others. This class is the whole identity as one value.
 *
 * The named constructors are the only way in, and each pins the namespace it is named after, so
 * an unstated namespace is not a thing that can happen. `user()` and `blog()` take no blog at all,
 * because those relationships are network-global.
 *
 * A taxonomy registered for more than one namespace cannot have its namespace inferred from the
 * taxonomy alone, which is exactly why naming it has to be free rather than optional.
 */
final class Multisite_Object implements JsonSerializable {

	/**
	 * The (namespace, blog) pair, always fully pinned.
	 *
	 * @access private
	 * @var Multisite_Object_Scope
	 */
	private $scope;

	/**
	 * Object ID within the namespace.
	 *
	 * @access private
	 * @var int
	 */
	private $id;

	/**
	 * Use the named constructors instead.
	 *
	 * @param Multisite_Object_Scope $scope Pinned scope.
	 * @param int                    $id    Object ID.
	 */
	private function __construct( Multisite_Object_Scope $scope, $id ) {
		$this->scope = $scope;
		$this->id    = (int) $id;
	}

	/**
	 * A post, page or custom post type on one blog.
	 *
	 * The blog is resolved here, not when the object is used: `post( $id )` means the blog that
	 * is current at construction. Build the object inside the `switch_to_blog()` it belongs to,
	 * or name the blog. The old three-argument API took the blog per call and could not be got
	 * wrong this way.
	 *
	 * @param int $post_id Post ID.
	 * @param int $blog_id Blog the post lives on. 0 means the blog current right now.
	 * @return self
	 */
	public static function post( $post_id, $blog_id = 0 ) {
		return new self( Multisite_Object_Scope::posts_on( $blog_id ), $post_id );
	}

	/**
	 * A network user. User relationships are network-global, so there is no blog to pass.
	 *
	 * @param int $user_id User ID.
	 * @return self
	 */
	public static function user( $user_id ) {
		return new self( Multisite_Object_Scope::users(), $user_id );
	}

	/**
	 * A site on the network. Site relationships are network-global, so there is no blog to pass.
	 *
	 * @param int $site_id Site (blog) ID of the site being tagged.
	 * @return self
	 */
	public static function blog( $site_id ) {
		return new self( Multisite_Object_Scope::blogs(), $site_id );
	}

	/**
	 * An object whose namespace is only known at runtime.
	 *
	 * Prefer the named constructors; this exists for code iterating over namespaces and for
	 * rehydrating rows read from the database.
	 *
	 * @param string $object_type Raw object type: '', a post type, 'user' or 'blog'.
	 * @param int    $id          Object ID.
	 * @param int    $blog_id     Blog the ID belongs to. Ignored for user and blog namespaces.
	 * @return self
	 */
	public static function create( $object_type, $id, $blog_id = 0 ) {
		return new self( Multisite_Object_Scope::create( $object_type, $blog_id ), $id );
	}

	/**
	 * Rebuild an object from a relationships table row.
	 *
	 * @param object|array $row Row carrying object_id, blog_id and object_type.
	 * @return self
	 */
	public static function from_row( $row ) {
		$row = (array) $row;

		return self::create(
			isset( $row['object_type'] ) ? $row['object_type'] : '',
			isset( $row['object_id'] ) ? $row['object_id'] : 0,
			isset( $row['blog_id'] ) ? (int) $row['blog_id'] : 0
		);
	}

	/**
	 * Object ID within its namespace.
	 *
	 * @return int
	 */
	public function id() {
		return $this->id;
	}

	/**
	 * Normalized namespace: '' (post), 'user' or 'blog'.
	 *
	 * @return string
	 */
	public function object_type() {
		return $this->scope->object_type();
	}

	/**
	 * Blog the ID belongs to. 0 for the network-global namespaces.
	 *
	 * @return int
	 */
	public function blog_id() {
		return $this->scope->blog_id();
	}

	/**
	 * The (namespace, blog) pair, for the query layer and the SQL/cache helpers.
	 *
	 * @return Multisite_Object_Scope
	 */
	public function scope() {
		return $this->scope;
	}

	/**
	 * A stable string identifying this object across namespaces.
	 *
	 * Use it as an array key: plain IDs collide, these do not.
	 *
	 * @return string
	 */
	public function key() {
		return $this->scope->key() . ':' . $this->id;
	}

	/**
	 * The object's identity when it lands in an encoded structure.
	 *
	 * Same reason as {@see Multisite_Object_Scope::jsonSerialize()}: private properties would
	 * otherwise encode to `{}`.
	 *
	 * @return string {@see self::key()}.
	 */
	public function jsonSerialize(): mixed { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- JsonSerializable interface.
		return $this->key();
	}

	/**
	 * Whether another value denotes the same object.
	 *
	 * @param Multisite_Object $other Object to compare with.
	 * @return bool
	 */
	public function equals( Multisite_Object $other ) {
		return $this->key() === $other->key();
	}

	/**
	 * The terms this object carries in a taxonomy.
	 *
	 * @param string $multisite_taxonomy Multisite taxonomy name.
	 * @param array  $args               See Multisite_Term_Query::__construct(). 'object_scope' is
	 *                                   set from this object and cannot be overridden.
	 * @return array|WP_Error Terms, or WP_Error when the taxonomy does not exist.
	 */
	public function terms( $multisite_taxonomy, $args = array() ) {
		if ( ! multisite_taxonomy_exists( $multisite_taxonomy ) ) {
			return new WP_Error( 'invalid_multisite_taxonomy', __( 'Invalid multisite taxonomy.', 'multitaxo' ) );
		}

		$args = wp_parse_args( $args );

		$args['taxonomy']     = array( $multisite_taxonomy );
		$args['object_ids']   = array( $this->id );
		$args['object_scope'] = $this->scope;

		$terms = get_multisite_terms( $args );

		return is_wp_error( $terms ) ? $terms : (array) $terms;
	}

	/**
	 * The term IDs this object carries in a taxonomy.
	 *
	 * @param string $multisite_taxonomy Multisite taxonomy name.
	 * @return int[]|WP_Error Term IDs, or WP_Error when the taxonomy does not exist.
	 */
	public function term_ids( $multisite_taxonomy ) {
		$terms = $this->terms( $multisite_taxonomy, array( 'fields' => 'ids' ) );

		return is_wp_error( $terms ) ? $terms : array_map( 'intval', $terms );
	}

	/**
	 * Replace (or extend) the terms this object carries in a taxonomy.
	 *
	 * @param string           $multisite_taxonomy Multisite taxonomy name.
	 * @param int|string|array $multisite_terms    Term ID, slug, name or an array of them.
	 * @param bool             $append             Add to the existing terms instead of replacing them.
	 * @return array|WP_Error Affected mtmt IDs, or WP_Error on failure.
	 */
	public function set_terms( $multisite_taxonomy, $multisite_terms, $append = false ) {
		return set_object_multisite_terms( $this->id, $multisite_terms, $multisite_taxonomy, $this->blog_id(), $append, $this->object_type() );
	}

	/**
	 * Add terms, keeping the ones already assigned.
	 *
	 * @param string           $multisite_taxonomy Multisite taxonomy name.
	 * @param int|string|array $multisite_terms    Term ID, slug, name or an array of them.
	 * @return array|WP_Error Affected mtmt IDs, or WP_Error on failure.
	 */
	public function add_terms( $multisite_taxonomy, $multisite_terms ) {
		return $this->set_terms( $multisite_taxonomy, $multisite_terms, true );
	}

	/**
	 * Remove terms from this object.
	 *
	 * @param string           $multisite_taxonomy Multisite taxonomy name.
	 * @param int|string|array $multisite_terms    Term ID, slug, name or an array of them.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function remove_terms( $multisite_taxonomy, $multisite_terms ) {
		return remove_object_multisite_terms( $this->id, $multisite_terms, $multisite_taxonomy, $this->blog_id(), $this->object_type() );
	}

	/**
	 * Whether this object carries a term (or any term at all) in a taxonomy.
	 *
	 * @param string                $multisite_taxonomy Multisite taxonomy name.
	 * @param int|string|array|null $multisite_terms    Term ID, slug, name or an array of them.
	 *                                                  Null asks whether any term is assigned.
	 * @return bool|WP_Error
	 */
	public function has_term( $multisite_taxonomy, $multisite_terms = null ) {
		return is_object_in_multsite_term( $this->id, $multisite_taxonomy, $multisite_terms, $this->blog_id(), $this->object_type() );
	}

	/**
	 * The WordPress object this identity points at.
	 *
	 * Hydration is namespace-aware: a post needs its own blog switched to, a user and a site do
	 * not. Resolving many objects at once belongs to {@see Multisite_Object_Set::hydrate()},
	 * which groups them first and switches at most once per blog.
	 *
	 * @return WP_Post|WP_User|WP_Site|null Null when the object no longer exists.
	 */
	public function resolve() {
		$set      = Multisite_Object_Set::from_objects( array( $this ) );
		$resolved = $set->hydrate();

		return isset( $resolved[ $this->key() ] ) ? $resolved[ $this->key() ] : null;
	}

	/**
	 * Whether the object still exists.
	 *
	 * @return bool
	 */
	public function exists() {
		return null !== $this->resolve();
	}
}
