<?php
defined( 'ABSPATH' ) || exit;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-oauth.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-gcs-client.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-signer.php';

class GFGCS_Ajax {

    public static function register() {
        add_action( 'wp_ajax_gfgcs_test_connection', array( __CLASS__, 'test_connection' ) );
        add_action( 'wp_ajax_gfgcs_rotate_secret',   array( __CLASS__, 'rotate_secret' ) );
    }

    public static function test_connection() {
        check_ajax_referer( 'gfgcs_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        }
        $cfg = GFGCS_Settings::get_global();
        if ( ! is_array( $cfg['sa'] ) ) {
            wp_send_json_error( array( 'message' => 'Service account not configured.' ), 400 );
        }
        if ( empty( $cfg['default_bucket'] ) ) {
            wp_send_json_error( array( 'message' => 'Default bucket not configured.' ), 400 );
        }
        try {
            $client = new GFGCS_GCS_Client( new GFGCS_OAuth( $cfg['sa'] ) );
            $client->list_objects( $cfg['default_bucket'], '', 1 );
            wp_send_json_success( array( 'message' => 'Connection OK.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => $e->getMessage() ), 502 );
        }
    }

    public static function rotate_secret() {
        check_ajax_referer( 'gfgcs_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array(), 403 );
        }
        GFGCS_Settings::rotate_signing_secret();
        wp_send_json_success();
    }
}
