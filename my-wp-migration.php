<?php
/**
 * Plugin Name:       My WP Migration
 * Description:       Backup, export and import (migrate) your complete WordPress site — database, media, themes and plugins. No size limits, works on any host.
 * Version:           1.0.0
 * Requires at least: 5.3
 * Requires PHP:      7.0
 * License:           GPL-2.0-or-later
 * Text Domain:       my-wp-migration
 */

defined( 'ABSPATH' ) || exit;

// Guard against a second copy of this plugin (e.g. a different folder name imported from another site).
if ( defined( 'MWPM_VERSION' ) ) {
	return;
}

define( 'MWPM_VERSION', '1.0.0' );
define( 'MWPM_FILE', __FILE__ );
define( 'MWPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWPM_URL', plugin_dir_url( __FILE__ ) );

require_once MWPM_DIR . 'includes/class-mwpm-storage.php';
require_once MWPM_DIR . 'includes/class-mwpm-db.php';
require_once MWPM_DIR . 'includes/class-mwpm-archive.php';
require_once MWPM_DIR . 'includes/class-mwpm-replace.php';
require_once MWPM_DIR . 'includes/class-mwpm-export.php';
require_once MWPM_DIR . 'includes/class-mwpm-import.php';
require_once MWPM_DIR . 'includes/class-mwpm-admin.php';

register_activation_hook( __FILE__, array( 'MWPM_Storage', 'ensure' ) );

MWPM_Admin::init();
