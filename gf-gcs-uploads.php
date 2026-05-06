<?php
/**
 * Plugin Name: Gravity Forms GCS Uploads
 * Description: Offloads Gravity Forms file uploads to Google Cloud Storage via signed-URL resumable uploads. Files bypass the web server entirely.
 * Version: 0.1.2
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Author: SSCW
 * License: GPL-2.0-or-later
 * Text Domain: gf-gcs-uploads
 */

defined( 'ABSPATH' ) || exit;

define( 'GFGCS_VERSION', '0.1.2' );
define( 'GFGCS_PLUGIN_FILE', __FILE__ );
define( 'GFGCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFGCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'gform_loaded', function () {
    if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
        return;
    }
    GFForms::include_addon_framework();
    require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-addon.php';
    GFAddOn::register( 'GFGCS_Addon' );
}, 5 );

register_activation_hook( __FILE__, function () {
    if ( ! class_exists( 'GFForms' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Gravity Forms GCS Uploads requires Gravity Forms to be installed and active.', 'gf-gcs-uploads' ) );
    }
    if ( ! get_option( 'gfgcs_signing_secret' ) ) {
        update_option( 'gfgcs_signing_secret', wp_generate_password( 64, true, true ), false );
    }
} );

register_deactivation_hook( __FILE__, function () {
    require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-cleanup.php';
    GFGCS_Cleanup::unregister();
} );
