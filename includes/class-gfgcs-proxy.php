<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Proxy {

    const NS    = 'gfgcs/v1';
    const ROUTE = '/f/(?P<entry>\d+)/(?P<field>\d+)/(?P<index>\d+)/(?P<token>[A-Za-z0-9_-]{24})';

    /** Pure HMAC token. 24 base64url chars = 144 bits. */
    public static function token( $entry_id, $field_id, $file_index, $secret ) {
        $msg = (int) $entry_id . '|' . (int) $field_id . '|' . (int) $file_index;
        $raw = hash_hmac( 'sha256', $msg, (string) $secret, true );
        $b64 = rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
        return substr( $b64, 0, 24 );
    }

    public static function verify_token( $candidate, $entry_id, $field_id, $file_index, $secret ) {
        if ( ! is_string( $candidate ) || ! preg_match( '/^[A-Za-z0-9_-]{24}$/', $candidate ) ) {
            return false;
        }
        $expected = self::token( $entry_id, $field_id, $file_index, $secret );
        return hash_equals( $expected, $candidate );
    }

    public static function permanent_url( $entry_id, $field_id, $file_index ) {
        $secret = GFGCS_Settings::signing_secret();
        $tok    = self::token( $entry_id, $field_id, $file_index, $secret );
        return rest_url( self::NS . "/f/{$entry_id}/{$field_id}/{$file_index}/{$tok}" );
    }

    public static function register_routes() {
        register_rest_route( self::NS, self::ROUTE, array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'serve' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public static function serve( $request ) {
        $entry_id = (int) $request['entry'];
        $field_id = (int) $request['field'];
        $idx      = (int) $request['index'];
        $token    = (string) $request['token'];

        $ip  = self::client_ip();
        $key = 'gfgcs_rate_' . md5( $ip );
        $cnt = (int) get_transient( $key );
        if ( $cnt >= 60 ) {
            return new \WP_REST_Response( array(), 429, array( 'Retry-After' => '60' ) );
        }
        set_transient( $key, $cnt + 1, 60 );

        $secret = GFGCS_Settings::signing_secret();
        if ( ! self::verify_token( $token, $entry_id, $field_id, $idx, $secret ) ) {
            return new \WP_REST_Response( null, 404 );
        }
        if ( ! class_exists( 'GFAPI' ) ) {
            return new \WP_REST_Response( null, 404 );
        }
        $entry = \GFAPI::get_entry( $entry_id );
        if ( is_wp_error( $entry ) || ! $entry ) {
            return new \WP_REST_Response( null, 404 );
        }
        $raw   = $entry[ (string) $field_id ] ?? '';
        $files = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
        if ( ! is_array( $files ) || ! isset( $files[ $idx ] ) ) {
            return new \WP_REST_Response( null, 404 );
        }
        $file = $files[ $idx ];

        $cfg = GFGCS_Settings::get_global();
        if ( ! is_array( $cfg['sa'] ) ) {
            return new \WP_REST_Response( null, 500 );
        }
        $form      = \GFAPI::get_form( (int) $entry['form_id'] );
        $effective = GFGCS_Ajax::effective_field_settings( $form, $field_id, $cfg );
        try {
            $signer = new GFGCS_Signer( $cfg['sa'] );
            $url    = $signer->sign_url(
                'GET',
                $effective['bucket'],
                $file['object_path'],
                max( 60, (int) $cfg['redirect_lifetime'] * 60 ),
                array(),
                array(
                    'response-content-disposition' => self::content_disposition( $file['original_name'] ?? 'file' ),
                )
            );
        } catch ( \Throwable $e ) {
            return new \WP_REST_Response( null, 500 );
        }
        $response = new \WP_REST_Response( null, 302 );
        $response->header( 'Location', $url );
        $response->header( 'Cache-Control', 'private, no-store' );
        $response->header( 'X-Robots-Tag', 'noindex, nofollow' );
        return $response;
    }

    /**
     * Build a Content-Disposition value safe for any filename.
     * Format: inline; filename="<ascii-sanitized>"; filename*=UTF-8''<percent-encoded>
     * Strips CR/LF (prevents header injection) and quotes / backslashes.
     */
    private static function content_disposition( $filename ) {
        $filename = (string) $filename;
        // Strip CR/LF and other control chars that could enable header injection.
        $filename = preg_replace( '/[\x00-\x1F\x7F]+/', '', $filename );
        if ( $filename === '' ) {
            $filename = 'file';
        }

        // ASCII fallback: replace non-ASCII bytes with `_`, also escape `"` and `\`.
        $ascii = preg_replace( '/[^\x20-\x7E]/', '_', $filename );
        $ascii = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $ascii );

        // RFC 5987 UTF-8 form: percent-encode the raw bytes.
        $utf8 = rawurlencode( $filename );

        return 'inline; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $utf8;
    }

    private static function client_ip() {
        $cfg    = GFGCS_Settings::get_global();
        $choice = isset( $cfg['trusted_proxy_header'] ) ? $cfg['trusted_proxy_header'] : 'none';

        if ( $choice === 'cf_connecting_ip' && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return self::sanitize_ip( $_SERVER['HTTP_CF_CONNECTING_IP'] );
        }
        if ( $choice === 'x_forwarded_for' && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // X-Forwarded-For may carry a comma-separated list; first entry is the original client.
            $first = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0];
            return self::sanitize_ip( $first );
        }
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return self::sanitize_ip( $_SERVER['REMOTE_ADDR'] );
        }
        return '0.0.0.0';
    }

    private static function sanitize_ip( $ip ) {
        $ip = trim( (string) $ip );
        // Validate against IPv4/IPv6 grammar; fall back to a placeholder for invalid input
        // so a single rate-limit bucket catches misformatted values.
        return filter_var( $ip, FILTER_VALIDATE_IP ) ?: '0.0.0.0';
    }
}
