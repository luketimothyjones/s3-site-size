<?php
/**
 * @wordpress-plugin
 * Plugin Name:       S3 Site Size
 * Plugin URI:        https://aboundant.com
 * Description:       Displays the size of sites hosted from S3 in a multi-site environment
 * Version:           1.0.0
 * Author:            Aboundant, LLC
 * Author URI:        https://aboundant.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * --
 * Network:           true
**/


// Hit the floor if accessed directly
if (!defined('WPINC')) { die; }

define('S3_SITE_SIZE_VERSION', '1.0.0');
define('S3SS_TABLE_NAME', $wpdb->prefix . 's3ss_site_sizes');

/**
 * Create the database upon plugin activation
**/
function activate_s3_site_size() {
    s3ss_create_database();
}

/**
 * Clear cache on deactivation in future?
**/
function deactivate_s3_site_size() {  }

register_activation_hook( __FILE__,   'activate_s3_site_size' );
register_deactivation_hook( __FILE__, 'deactivate_s3_site_size' );

// ----
function s3ss_create_database() {
    global $wpdb;
    
    // Pass if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '" . S3SS_TABLE_NAME . "'") === S3SS_TABLE_NAME) { return; }
    
    // dbDelta runs the risk of updating the table structure and its formatting requirements
    // are really silly. Since we don't need this functionality, $wpdb->query will work fine.
    $wpdb->query("
      CREATE TABLE " . S3SS_TABLE_NAME . " (
        site_id MEDIUMINT(9) NOT NULL,
        site_size BIGINT SIGNED NOT NULL,
        last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (site_id)
    ) {$wpdb->get_charset_collate()};");
}

// ----
add_action("admin_enqueue_scripts", "s3ss_enqueue_admin_style");
function s3ss_enqueue_admin_style($hook) {
    if ($hook === 'settings_page_s3-site-size-settings') {
        wp_enqueue_style('s3ss-settings-page', plugins_url('admin/css/s3-site-size-admin.css', __FILE__));
    }
}

// ----
if (is_admin() && is_main_site()) {
    // Load settings page only for admins on the main (parent) site
    include(plugin_dir_path(__FILE__) . '/admin/partials/s3-site-size-admin-settings.php');
}

// Load S3 functionality site-wide
if (strpos($_SERVER['REQUEST_URI'], '/wp-admin/') === 0) {
    require(plugin_dir_path(__FILE__) . '/includes/s3-site-size-main.php');
}
