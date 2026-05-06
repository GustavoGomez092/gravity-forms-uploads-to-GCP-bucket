<?php
defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-validator.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-gcs-client.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-oauth.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-proxy.php';

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
        add_action( 'init', function () {
            if ( class_exists( 'GF_Fields' ) ) {
                require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-field.php';
            }
        } );
        parent::init();
        require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';
        GFGCS_Ajax::register();
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'gform_validation', array( $this, 'validate_submission' ) );
        add_action( 'rest_api_init', array( 'GFGCS_Proxy', 'register_routes' ) );
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

    public function form_settings_fields( $form ) {
        return array(
            array(
                'title'  => esc_html__( 'GCS Storage Overrides', 'gf-gcs-uploads' ),
                'fields' => array(
                    array(
                        'name'    => 'override_bucket',
                        'label'   => esc_html__( 'Override default bucket', 'gf-gcs-uploads' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'name' => 'override_bucket', 'label' => esc_html__( 'Yes', 'gf-gcs-uploads' ) ) ),
                    ),
                    array(
                        'name'       => 'bucket_override',
                        'label'      => esc_html__( 'Bucket', 'gf-gcs-uploads' ),
                        'type'       => 'text',
                        'class'      => 'medium',
                        'dependency' => 'override_bucket',
                    ),
                    array(
                        'name'    => 'override_prefix',
                        'label'   => esc_html__( 'Override default object prefix', 'gf-gcs-uploads' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'name' => 'override_prefix', 'label' => esc_html__( 'Yes', 'gf-gcs-uploads' ) ) ),
                    ),
                    array(
                        'name'       => 'prefix_override',
                        'label'      => esc_html__( 'Prefix', 'gf-gcs-uploads' ),
                        'type'       => 'text',
                        'class'      => 'medium',
                        'dependency' => 'override_prefix',
                        'tooltip'    => esc_html__( 'Tokens: {form_title}, {form_id}, {Y}, {m}, {d}, {submission_uuid}. Plugin auto-appends <field_id>/<file_uuid>/<filename>.', 'gf-gcs-uploads' ),
                    ),
                    array(
                        'name'    => 'override_size',
                        'label'   => esc_html__( 'Override default max file size', 'gf-gcs-uploads' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'name' => 'override_size', 'label' => esc_html__( 'Yes', 'gf-gcs-uploads' ) ) ),
                    ),
                    array(
                        'name'       => 'max_size_mb',
                        'label'      => esc_html__( 'Max size (MB)', 'gf-gcs-uploads' ),
                        'type'       => 'text',
                        'class'      => 'small',
                        'dependency' => 'override_size',
                    ),
                    array(
                        'name'    => 'override_mimes',
                        'label'   => esc_html__( 'Override default allowed MIME types', 'gf-gcs-uploads' ),
                        'type'    => 'checkbox',
                        'choices' => array( array( 'name' => 'override_mimes', 'label' => esc_html__( 'Yes', 'gf-gcs-uploads' ) ) ),
                    ),
                    array(
                        'name'       => 'allowed_mimes',
                        'label'      => esc_html__( 'Allowed MIMEs', 'gf-gcs-uploads' ),
                        'type'       => 'text',
                        'class'      => 'medium',
                        'dependency' => 'override_mimes',
                    ),
                ),
            ),
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( (string) $hook, 'gf_settings' ) === false ) {
            return;
        }
        wp_enqueue_script( 'gfgcs-admin', GFGCS_PLUGIN_URL . 'assets/js/gfgcs-admin.js', array(), GFGCS_VERSION, true );
        wp_localize_script( 'gfgcs-admin', 'GFGCSAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'gfgcs_admin' ),
            'i18n'    => array(
                'testing'   => __( 'Testing…', 'gf-gcs-uploads' ),
                'rotating'  => __( 'Rotating…', 'gf-gcs-uploads' ),
                'rotated'   => __( 'Signing secret rotated. All existing permanent URLs are now invalid.', 'gf-gcs-uploads' ),
                'confirm'   => __( 'This will break every previously emitted permanent URL. Continue?', 'gf-gcs-uploads' ),
            ),
        ) );
    }

    public function validate_submission( $validation_result ) {
        $form = $validation_result['form'];
        $cfg  = GFGCS_Settings::get_global();
        if ( ! is_array( $cfg['sa'] ) ) {
            return $validation_result;
        }
        $client = null;
        foreach ( $form['fields'] as &$field ) {
            if ( $field->type !== 'gcs_upload' ) continue;
            $raw    = rgpost( 'input_' . $field->id );
            $files  = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
            $effective = GFGCS_Ajax::effective_field_settings( $form, $field->id, $cfg );
            if ( ! $client ) {
                $client = new GFGCS_GCS_Client( new GFGCS_OAuth( $cfg['sa'] ) );
            }
            $err = GFGCS_Validator::verify_field( $files, $effective['bucket'], $effective['prefix'], $client, ! empty( $field->isRequired ) );
            if ( $err ) {
                $field->failed_validation  = true;
                $field->validation_message = $err['message'];
                $validation_result['is_valid'] = false;
                $this->log_error( $err['code'], array( 'form_id' => $form['id'], 'field_id' => $field->id ) );
            }
        }
        $validation_result['form'] = $form;
        return $validation_result;
    }

    public function log_error( $code, $context = array() ) {
        if ( ! function_exists( 'wp_upload_dir' ) ) return;
        $dir = wp_upload_dir();
        $log_dir = trailingslashit( $dir['basedir'] ) . 'gf-gcs-uploads';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            @file_put_contents( $log_dir . '/.htaccess', "Order deny,allow\nDeny from all\n" );
        }
        $line = gmdate( 'c' ) . " [$code] " . wp_json_encode( $context ) . "\n";
        @file_put_contents( $log_dir . '/log-' . gmdate( 'Y-m' ) . '.log', $line, FILE_APPEND );
    }
}
