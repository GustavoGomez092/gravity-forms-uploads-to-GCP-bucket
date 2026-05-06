<?php
defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';

class GFGCS_Addon extends GFAddOn {
    protected $_version           = GFGCS_VERSION;
    protected $_min_gravityforms_version = '2.7';
    protected $_slug              = 'gf-gcs-uploads';
    protected $_path              = 'gf-gcs-uploads/gf-gcs-uploads.php';
    protected $_full_path         = __FILE__;
    protected $_title             = 'GCS Uploads';
    protected $_short_title       = 'GCS Uploads';

    private static $_instance = null;

    public static function get_instance() {
        if ( null === self::$_instance ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function init() {
        parent::init();
    }

    public function plugin_settings_fields() {
        $current = GFGCS_Settings::get_global();
        $sa_blurb = '';
        if ( is_array( $current['sa'] ) ) {
            $disp     = GFGCS_Settings::redact_sa_for_display( $current['sa'] );
            $sa_blurb = sprintf(
                /* translators: 1: client email, 2: project id */
                esc_html__( 'Currently configured: %1$s (project %2$s). Paste new JSON below to replace.', 'gf-gcs-uploads' ),
                esc_html( $disp['client_email'] ),
                esc_html( $disp['project_id'] )
            );
        }

        return array(
            array(
                'title'  => esc_html__( 'Google Cloud Storage Credentials', 'gf-gcs-uploads' ),
                'fields' => array(
                    array(
                        'name'        => 'sa_json',
                        'label'       => esc_html__( 'Service Account JSON', 'gf-gcs-uploads' ),
                        'type'        => 'textarea',
                        'class'       => 'large',
                        'tooltip'     => esc_html__( 'Paste the contents of the JSON key for a service account with Storage Object Admin on your bucket.', 'gf-gcs-uploads' ),
                        'description' => $sa_blurb,
                    ),
                    array(
                        'name'  => 'test_connection',
                        'label' => '',
                        'type'  => 'html',
                        'html'  => '<button type="button" class="button" id="gfgcs-test-connection">' . esc_html__( 'Test Connection', 'gf-gcs-uploads' ) . '</button> <span id="gfgcs-test-result"></span>',
                    ),
                ),
            ),
            array(
                'title'  => esc_html__( 'Default Storage', 'gf-gcs-uploads' ),
                'fields' => array(
                    array(
                        'name'    => 'default_bucket',
                        'label'   => esc_html__( 'Default Bucket Name', 'gf-gcs-uploads' ),
                        'type'    => 'text',
                        'class'   => 'medium',
                        'default_value' => $current['default_bucket'],
                    ),
                    array(
                        'name'    => 'default_prefix',
                        'label'   => esc_html__( 'Default Object Prefix', 'gf-gcs-uploads' ),
                        'type'    => 'text',
                        'class'   => 'medium',
                        'default_value' => $current['default_prefix'],
                        'tooltip' => esc_html__( 'Available tokens: {form_title}, {form_id}, {Y}, {m}, {d}, {submission_uuid}. The plugin appends <field>/<file_uuid>/<filename> automatically.', 'gf-gcs-uploads' ),
                    ),
                    array(
                        'name'    => 'max_size_mb',
                        'label'   => esc_html__( 'Default Max File Size (MB)', 'gf-gcs-uploads' ),
                        'type'    => 'text',
                        'class'   => 'small',
                        'default_value' => (string) $current['max_size_mb'],
                    ),
                    array(
                        'name'    => 'allowed_mimes',
                        'label'   => esc_html__( 'Default Allowed MIME Types', 'gf-gcs-uploads' ),
                        'type'    => 'text',
                        'class'   => 'medium',
                        'default_value' => $current['allowed_mimes'],
                    ),
                ),
            ),
            array(
                'title'  => esc_html__( 'Access', 'gf-gcs-uploads' ),
                'fields' => array(
                    array(
                        'name'  => 'rotate_secret',
                        'label' => esc_html__( 'Proxy URL Signing Secret', 'gf-gcs-uploads' ),
                        'type'  => 'html',
                        'html'  => '<button type="button" class="button" id="gfgcs-rotate-secret">' . esc_html__( 'Rotate', 'gf-gcs-uploads' ) . '</button> <em>' . esc_html__( 'Rotating invalidates every existing permanent file URL.', 'gf-gcs-uploads' ) . '</em>',
                    ),
                    array(
                        'name'    => 'redirect_lifetime',
                        'label'   => esc_html__( 'Signed Redirect Lifetime (minutes)', 'gf-gcs-uploads' ),
                        'type'    => 'text',
                        'class'   => 'small',
                        'default_value' => (string) $current['redirect_lifetime'],
                        'tooltip' => esc_html__( 'How long the GCS signed URL the proxy redirects to remains valid. 1–10080.', 'gf-gcs-uploads' ),
                    ),
                ),
            ),
        );
    }

    public function update_plugin_settings( $settings ) {
        $patch = array(
            'default_bucket'    => sanitize_text_field( $settings['default_bucket'] ?? '' ),
            'default_prefix'    => sanitize_text_field( $settings['default_prefix'] ?? 'gravityforms/' ),
            'max_size_mb'       => max( 1, intval( $settings['max_size_mb'] ?? 1024 ) ),
            'allowed_mimes'     => sanitize_text_field( $settings['allowed_mimes'] ?? 'image/*, video/*' ),
            'redirect_lifetime' => min( 10080, max( 1, intval( $settings['redirect_lifetime'] ?? 15 ) ) ),
        );
        $sa_raw = trim( (string) ( $settings['sa_json'] ?? '' ) );
        if ( $sa_raw !== '' ) {
            $sa = json_decode( $sa_raw, true );
            if ( ! is_array( $sa ) ) {
                GFCommon::add_error_message( esc_html__( 'Service Account JSON is not valid JSON.', 'gf-gcs-uploads' ) );
                return;
            }
            foreach ( array( 'type', 'client_email', 'private_key' ) as $req ) {
                if ( empty( $sa[ $req ] ) ) {
                    GFCommon::add_error_message( sprintf( esc_html__( 'Service Account JSON is missing required key: %s', 'gf-gcs-uploads' ), $req ) );
                    return;
                }
            }
            $patch['sa'] = $sa;
        }
        GFGCS_Settings::update_global( $patch );
    }
}
