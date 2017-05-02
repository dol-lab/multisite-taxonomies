<?php
/**
 * Multisite Taxonomies
 *
 * @package multitaxo
 */

/**
 * Plugin Name: Multisite Taxonomies
 * Plugin URI:  https://github.com/HarvardChanSchool/multisite-taxonomies
 * Description: Multisite Taxonomies brings the ability to register custom taxonomies, accessible on an entire multisite network, to WordPress.
 * Version:     0.0.1
 * Author:      Harvard Chan Webteam
 * Author URI:  http://www.hsph.harvard.edu/information-technology/
 * Text Domain: multitaxo
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants.
define( 'MULTITAXO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MULTITAXO_PLUGIN_URL', plugins_url( '', __FILE__ ) );
define( 'MULTITAXO_ASSETS_URL', plugins_url( '/assets', __FILE__ ) );

// Plugin init.
require_once( plugin_dir_path( __FILE__ ) . 'inc/class-multisitetaxonomies.php' );
$multitaxo = new MultisiteTaxonomies();