<?php
/**
 * The (namespace, blog) pair every relationship row is keyed by.
 *
 * @package multitaxo
 */

/**
 * Scope of a multisite term relationship: which ID namespace, and which blog.
 *
 * A relationship row is identified by two columns, not one. `object_type` names the ID namespace
 * ('' for posts, 'user', 'blog'), and `blog_id` names the site a post ID belongs to, because post
 * IDs are unique per site only. Carrying the two as separate arguments is what lets a caller apply
 * one and forget the other, and forgetting fails open: the read then spans every site on the
 * network. As one value they cannot be half-applied, and the SQL predicates and cache group are
 * built in a single place.
 *
 * Either half may be left open, but only on purpose: {@see Multisite_Object_Scope::across_blogs()}
 * and {@see Multisite_Object_Scope::any()} name that intent at the call site.
 */
final class Multisite_Object_Scope implements JsonSerializable {

	/**
	 * Normalized namespace: '' (post), 'user' or 'blog'. Null spans every namespace.
	 *
	 * @access private
	 * @var string|null
	 */
	private $object_type;

	/**
	 * Blog the object IDs belong to. Null spans every blog.
	 *
	 * @access private
	 * @var int|null
	 */
	private $blog_id;

	/**
	 * Use the named constructors instead.
	 *
	 * @param string|null $object_type Normalized namespace, or null for every namespace.
	 * @param int|null    $blog_id     Blog ID, or null for every blog.
	 */
	private function __construct( $object_type, $blog_id ) {
		$this->object_type = $object_type;
		$this->blog_id     = $blog_id;
	}

	/**
	 * A fully pinned scope.
	 *
	 * Both halves are normalized the way the write path stores them: user and blog relationships
	 * are network-global and land at blog 0, a post relationship takes the given blog and falls
	 * back to the current one. An unstated namespace is the post namespace, which is the default
	 * of every public relationship function.
	 *
	 * @param string|null $object_type Raw object type: '', 'user', 'blog' or null.
	 * @param int         $blog_id     Blog the object IDs belong to. 0 means the current blog.
	 * @return self
	 */
	public static function create( $object_type, $blog_id = 0 ) {
		$object_type = normalize_multisite_object_type( $object_type );

		return new self( $object_type, multisite_relationship_blog_id( $object_type, (int) $blog_id ) );
	}

	/**
	 * A pinned scope whose namespace is inferred from a single-namespace taxonomy when omitted.
	 *
	 * @param string|null $object_type        Raw object type, may be empty.
	 * @param string      $multisite_taxonomy Multisite taxonomy name.
	 * @param int         $blog_id            Blog the object IDs belong to. 0 means the current blog.
	 * @return self
	 */
	public static function for_taxonomy( $object_type, $multisite_taxonomy, $blog_id = 0 ) {
		return self::create( resolve_multisite_object_type( $object_type, $multisite_taxonomy ), $blog_id );
	}

	/**
	 * The post namespace on one blog.
	 *
	 * @param int $blog_id Blog the post IDs belong to. 0 means the current blog.
	 * @return self
	 */
	public static function posts_on( $blog_id = 0 ) {
		return self::create( '', $blog_id );
	}

	/**
	 * The post namespace on every blog.
	 *
	 * Post IDs collide across blogs, so the rows this matches may belong to different posts.
	 *
	 * @return self
	 */
	public static function posts() {
		return self::across_blogs( '' );
	}

	/**
	 * The user namespace, which is network-global.
	 *
	 * @return self
	 */
	public static function users() {
		return self::create( 'user' );
	}

	/**
	 * The blog namespace, which is network-global.
	 *
	 * @return self
	 */
	public static function blogs() {
		return self::create( 'blog' );
	}

	/**
	 * One namespace on every blog: a deliberate network-wide read.
	 *
	 * Post IDs collide across blogs, so the rows this matches may belong to different objects.
	 *
	 * @param string|null $object_type Raw object type, or null for every namespace.
	 * @return self
	 */
	public static function across_blogs( $object_type = null ) {
		return new self( null === $object_type ? null : normalize_multisite_object_type( $object_type ), null );
	}

	/**
	 * Every namespace on every blog: no constraint at all.
	 *
	 * @return self
	 */
	public static function any() {
		return self::across_blogs( null );
	}

	/**
	 * The namespace this scope is pinned to.
	 *
	 * @return string|null '' (post), 'user', 'blog', or null when unpinned.
	 */
	public function object_type() {
		return $this->object_type;
	}

	/**
	 * The blog this scope is pinned to.
	 *
	 * @return int|null Blog ID, or null when unpinned.
	 */
	public function blog_id() {
		return $this->blog_id;
	}

	/**
	 * A stable string for grouping objects that share this scope.
	 *
	 * Unpinned halves keep a distinct marker so an open scope never collides with a pinned one.
	 *
	 * @return string
	 */
	public function key() {
		$blog_id     = null === $this->blog_id ? '*' : (string) $this->blog_id;
		$object_type = null === $this->object_type ? '*' : ( '' === $this->object_type ? 'post' : $this->object_type );

		return $blog_id . ':' . $object_type;
	}

	/**
	 * The scope's identity when it lands in an encoded structure.
	 *
	 * Both halves are private, so a plain `json_encode()` of this object is `{}` and every scope
	 * hashes alike, which silently breaks anything keying a cache on `md5( wp_json_encode( $args ) )`
	 * once a scope is one of those args.
	 *
	 * @return string {@see self::key()}.
	 */
	public function jsonSerialize(): mixed { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- JsonSerializable interface.
		return $this->key();
	}

	/**
	 * Whether another scope pins exactly the same namespace and blog.
	 *
	 * @param Multisite_Object_Scope $other Scope to compare with.
	 * @return bool
	 */
	public function equals( Multisite_Object_Scope $other ) {
		return $this->object_type === $other->object_type() && $this->blog_id === $other->blog_id();
	}

	/**
	 * Whether this scope constrains anything.
	 *
	 * @return bool False only for a scope that is open on both halves.
	 */
	public function is_narrowing() {
		return null !== $this->object_type || null !== $this->blog_id;
	}

	/**
	 * The SQL condition for the relationships table.
	 *
	 * The only place either predicate is written, so a query cannot end up with half a key.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param string $alias Table alias of the relationships table.
	 * @return string Prepared condition, or an empty string for an unpinned scope.
	 */
	public function where( $alias = 'tr' ) {
		global $wpdb;

		$conditions = array();

		if ( null !== $this->object_type ) {
			$conditions[] = $wpdb->prepare( "{$alias}.object_type = %s", $this->object_type ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $alias is a caller-supplied table alias, not input.
		}

		if ( null !== $this->blog_id ) {
			$conditions[] = $wpdb->prepare( "{$alias}.blog_id = %d", $this->blog_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		}

		return implode( ' AND ', $conditions );
	}

	/**
	 * Cache group for the object-to-terms relationship cache.
	 *
	 * The group carries the scope because the cached value is a list of term IDs for an object
	 * ID, and that ID means nothing without one. An unpinned scope falls back to the current
	 * blog's post namespace rather than sharing a group with everything else.
	 *
	 * @param string $multisite_taxonomy Multisite taxonomy name.
	 * @return string Cache group name.
	 */
	public function cache_group( $multisite_taxonomy ) {
		$blog_id     = null === $this->blog_id ? get_current_blog_id() : $this->blog_id;
		$object_type = ( null === $this->object_type || '' === $this->object_type ) ? 'post' : $this->object_type;

		return "{$blog_id}_{$object_type}_{$multisite_taxonomy}_multisite_relationships";
	}
}
