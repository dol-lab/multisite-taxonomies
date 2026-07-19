<?php
/**
 * PHPUnit bootstrap for the Multisite Taxonomies fork.
 *
 * Mirrors the spaces-core setup. Always runs as multisite: the plugin keys its tables off
 * $wpdb->base_prefix and stores user/blog relationships network-globally (blog_id 0), so a
 * single-site bootstrap would not represent the real environment. The relationship tables are
 * created lazily on `init` by Multitaxo_Plugin::register_database_tables(), which the WP test
 * bootstrap fires after the plugin is loaded — so no explicit schema install is needed here.
 *
 * @package multitaxo
 */

if ( ! defined( 'WP_TESTS_MULTISITE' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Handle the modern wordpress-develop repository structure.
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) && file_exists( "{$_tests_dir}/tests/phpunit/includes/functions.php" ) ) {
	if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
		define( 'WP_TESTS_CONFIG_FILE_PATH', "{$_tests_dir}/wp-tests-config.php" );
	}
	$_tests_dir .= '/tests/phpunit';
}

// Forward custom PHPUnit Polyfills configuration to the WP PHPUnit bootstrap.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/multisite-taxonomies.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require_once "{$_tests_dir}/includes/bootstrap.php";

/*
 * Purge stale rows from previous runs (mirrors the spaces-core bootstrap). The multisite_*
 * tables are not part of the core schema the test installer resets, and a mid-test implicit
 * commit (e.g. a factory-created site's CREATE TABLE statements) makes that test's rows
 * permanent — they then leak into later runs and skew raw row-count assertions. The fresh
 * install needs no preexisting rows, so an empty baseline is always correct.
 */
( function () {
	global $wpdb;
	$tables = array( 'multisite_termmeta', 'multisite_terms', 'multisite_term_relationships', 'multisite_term_multisite_taxonomy' );
	foreach ( $tables as $table ) {
		$wpdb->query( 'TRUNCATE TABLE `' . $wpdb->base_prefix . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test bootstrap housekeeping on fixed table names.
	}
} )();
