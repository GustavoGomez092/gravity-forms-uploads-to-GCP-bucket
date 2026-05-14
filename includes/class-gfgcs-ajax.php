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
        self::register_init();
        self::register_abort();
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

    public static function register_init() {
        add_action( 'wp_ajax_gfgcs_init',        array( __CLASS__, 'init_upload' ) );
        add_action( 'wp_ajax_nopriv_gfgcs_init', array( __CLASS__, 'init_upload' ) );
    }

    public static function init_upload() {
        $form_id  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        $field_id = isset( $_POST['field_id'] ) ? absint( $_POST['field_id'] ) : 0;
        if ( ! $form_id || ! $field_id ) {
            wp_send_json_error( array( 'code' => 'bad_request' ), 400 );
        }
        if ( ! check_ajax_referer( 'gfgcs_init_' . $form_id, 'nonce', false ) ) {
            wp_send_json_error( array( 'code' => 'bad_nonce' ), 403 );
        }
        $cfg = GFGCS_Settings::get_global();
        if ( ! is_array( $cfg['sa'] ) || ! $cfg['default_bucket'] ) {
            wp_send_json_error( array( 'code' => 'not_configured' ), 503 );
        }
        $form = class_exists( 'GFAPI' ) ? GFAPI::get_form( $form_id ) : null;
        if ( ! $form ) {
            wp_send_json_error( array( 'code' => 'unknown_form' ), 404 );
        }

        $effective = self::effective_field_settings( $form, $field_id, $cfg );
        if ( ! $effective ) {
            wp_send_json_error( array( 'code' => 'unknown_field' ), 404 );
        }

        $filename = isset( $_POST['filename'] ) ? wp_unslash( (string) $_POST['filename'] ) : '';
        $size     = isset( $_POST['size'] ) ? (int) $_POST['size'] : 0;
        $mime     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['type'] ) ) : 'application/octet-stream';
        if ( $filename === '' || $size <= 0 ) {
            wp_send_json_error( array( 'code' => 'bad_request' ), 400 );
        }
        if ( $size > $effective['max_size_bytes'] ) {
            wp_send_json_error( array( 'code' => 'size_exceeded', 'max' => $effective['max_size_bytes'] ), 422 );
        }
        if ( self::ext_disallowed( $filename ) ) {
            wp_send_json_error( array( 'code' => 'extension_not_allowed', 'message' => 'This type of file is not allowed.' ), 422 );
        }
        if ( ! self::ext_allowed( $filename, $effective['allowed_extensions'] ) ) {
            wp_send_json_error( array( 'code' => 'extension_not_allowed', 'message' => 'This type of file is not allowed.' ), 422 );
        }
        if ( ! self::mime_allowed( $mime, $effective['allowed_mimes'] ) ) {
            wp_send_json_error( array( 'code' => 'mime_not_allowed', 'allowed' => $effective['allowed_mimes'] ), 422 );
        }

        $submission_uuid = isset( $_POST['submission_uuid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['submission_uuid'] ) ) : '';
        if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submission_uuid ) ) {
            $submission_uuid = self::uuidv4();
        }
        $file_uuid = self::uuidv4();

        $prefix_template = $effective['prefix'];
        $prefix          = GFGCS_Settings::expand_prefix( $prefix_template, array(
            'form_id'         => $form_id,
            'form_title'      => $form['title'] ?? '',
            'submission_uuid' => $submission_uuid,
            'Y' => gmdate( 'Y' ), 'm' => gmdate( 'm' ), 'd' => gmdate( 'd' ),
        ) );
        if ( substr( $prefix, -1 ) !== '/' ) {
            $prefix .= '/';
        }
        $object_path = self::build_object_path( $prefix, $field_id, $file_uuid, $filename );

        try {
            $signer = new GFGCS_Signer( $cfg['sa'] );
            $signed = $signer->sign_resumable_init_url( $effective['bucket'], $object_path, 3600 );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'code' => 'signing_failed', 'message' => $e->getMessage() ), 500 );
        }
        wp_send_json_success( array(
            'signed_init_url' => $signed,
            'object_path'     => $object_path,
            'submission_uuid' => $submission_uuid,
            'file_uuid'       => $file_uuid,
        ) );
    }

    /** Resolves bucket/prefix/limits with per-field > per-form > global precedence (per-field for size/mimes only). */
    public static function effective_field_settings( $form, $field_id, $global ) {
        $field = null;
        foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
            if ( (int) $f->id === (int) $field_id && $f->type === 'gcs_upload' ) {
                $field = $f;
                break;
            }
        }
        if ( ! $field ) {
            return null;
        }
        $form_settings = method_exists( 'GFGCS_Addon', 'get_instance' ) ? ( GFGCS_Addon::get_instance()->get_form_settings( $form ) ?: array() ) : array();

        // Bucket / prefix: per-form override (gated by checkbox) OR global default.
        $bucket = ( ! empty( $form_settings['override_bucket'] ) && ! empty( $form_settings['bucket_override'] ) )
            ? $form_settings['bucket_override']
            : $global['default_bucket'];

        $prefix = ( ! empty( $form_settings['override_prefix'] ) && ! empty( $form_settings['prefix_override'] ) )
            ? $form_settings['prefix_override']
            : $global['default_prefix'];

        // Max size: per-field maxFileSize > per-form override > global.
        $max_mb_field = (int) ( $field->maxFileSize ?? 0 );
        $max_mb_form  = ( ! empty( $form_settings['override_size'] ) ) ? (int) ( $form_settings['max_size_mb'] ?? 0 ) : 0;
        $max_mb       = $max_mb_field ?: ( $max_mb_form ?: (int) $global['max_size_mb'] );

        // MIMEs: per-form override > global (per-field allowedMimes removed; extension check handles per-field filtering).
        $mimes_form = ( ! empty( $form_settings['override_mimes'] ) ) ? trim( (string) ( $form_settings['allowed_mimes'] ?? '' ) ) : '';
        $mimes_str  = $mimes_form !== '' ? $mimes_form : $global['allowed_mimes'];
        $mimes      = array_filter( array_map( 'trim', explode( ',', $mimes_str ) ) );

        // Extensions: per-field allowedExtensions.
        $exts_str = trim( (string) ( $field->allowedExtensions ?? '' ) );
        $exts     = array_values( array_filter( array_map(
            function ( $e ) { return strtolower( ltrim( trim( $e ), '.' ) ); },
            explode( ',', $exts_str )
        ) ) );

        return array(
            'bucket'             => $bucket,
            'prefix'             => $prefix,
            'max_size_bytes'     => $max_mb * 1024 * 1024,
            'allowed_mimes'      => $mimes,
            'allowed_extensions' => $exts,
        );
    }

    public static function build_object_path( $prefix, $field_id, $file_uuid, $filename ) {
        // Strip path traversal sequences before charset sanitization.
        $sanitized = preg_replace( '/\.\.?\//', '', $filename );
        $base = preg_replace( '/[^A-Za-z0-9._\- ]+/', '-', $sanitized );
        $base = trim( $base, '-' );
        if ( $base === '' ) { $base = 'file'; }
        if ( strlen( $base ) > 200 ) {
            $ext  = '';
            $dot  = strrpos( $base, '.' );
            if ( $dot !== false && $dot > strlen( $base ) - 12 ) {
                $ext  = substr( $base, $dot );
                $base = substr( $base, 0, $dot );
            }
            $base = substr( $base, 0, 200 - strlen( $ext ) ) . $ext;
        }
        return rtrim( $prefix, '/' ) . '/' . intval( $field_id ) . '/' . $file_uuid . '/' . $base;
    }

    public static function mime_allowed( $mime, array $patterns ) {
        if ( empty( $patterns ) ) { return true; }
        foreach ( $patterns as $p ) {
            $p = strtolower( trim( $p ) );
            if ( $p === '' ) { continue; }
            if ( substr( $p, -2 ) === '/*' ) {
                if ( strpos( strtolower( $mime ), substr( $p, 0, -1 ) ) === 0 ) { return true; }
            } elseif ( strtolower( $mime ) === $p ) {
                return true;
            }
        }
        return false;
    }

    public static function ext_allowed( $filename, array $allowed_exts ) {
        $normalized = array();
        foreach ( $allowed_exts as $e ) {
            $e = strtolower( ltrim( trim( (string) $e ), '.' ) );
            if ( $e !== '' ) { $normalized[] = $e; }
        }
        if ( empty( $normalized ) ) { return true; }
        $dot = strrpos( $filename, '.' );
        if ( $dot === false || $dot === strlen( $filename ) - 1 ) { return false; }
        $ext = strtolower( substr( $filename, $dot + 1 ) );
        return in_array( $ext, $normalized, true );
    }

    public static function ext_disallowed( $filename ) {
        $denied = self::get_disallowed_extensions();
        $dot = strrpos( $filename, '.' );
        if ( $dot === false || $dot === strlen( $filename ) - 1 ) { return false; }
        $ext = strtolower( substr( $filename, $dot + 1 ) );
        return in_array( $ext, array_map( 'strtolower', $denied ), true );
    }

    public static function get_disallowed_extensions() {
        return class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_disallowed_file_extensions' )
            ? (array) \GFCommon::get_disallowed_file_extensions()
            : array( 'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'js', 'pl', 'py', 'cgi', 'asp', 'aspx', 'sh', 'htaccess' );
    }

    private static function uuidv4() {
        $b = random_bytes( 16 );
        $b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
        $b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
    }

    public static function register_abort() {
        add_action( 'wp_ajax_gfgcs_abort',        array( __CLASS__, 'abort_upload' ) );
        add_action( 'wp_ajax_nopriv_gfgcs_abort', array( __CLASS__, 'abort_upload' ) );
    }

    public static function abort_upload() {
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        if ( ! $form_id || ! check_ajax_referer( 'gfgcs_init_' . $form_id, 'nonce', false ) ) {
            wp_send_json_error( array( 'code' => 'bad_nonce' ), 403 );
        }
        $submission_uuid = isset( $_POST['submission_uuid'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['submission_uuid'] ) ) : '';
        $object_key      = isset( $_POST['object_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['object_key'] ) ) : '';
        if ( $object_key === '' || ! self::key_belongs_to_submission( $object_key, $submission_uuid ) ) {
            wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
        }
        $cfg = GFGCS_Settings::get_global();
        if ( ! is_array( $cfg['sa'] ) || ! $cfg['default_bucket'] ) {
            wp_send_json_error( array( 'code' => 'not_configured' ), 503 );
        }
        try {
            $client = new GFGCS_GCS_Client( new GFGCS_OAuth( $cfg['sa'] ) );
            $deleted = $client->delete_object( $cfg['default_bucket'], $object_key );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'code' => 'delete_failed', 'message' => $e->getMessage() ), 502 );
        }
        if ( $deleted === false ) {
            // delete_object returns false on transport errors instead of throwing — surface as 502.
            wp_send_json_error( array( 'code' => 'delete_failed', 'message' => 'GCS delete failed.' ), 502 );
        }
        wp_send_json_success( array( 'deleted' => true ) );
    }

    public static function key_belongs_to_submission( $object_key, $submission_uuid ) {
        $submission_uuid = (string) $submission_uuid;
        if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submission_uuid ) ) {
            return false;
        }
        return strpos( $object_key, $submission_uuid . '/' ) !== false;
    }
}
