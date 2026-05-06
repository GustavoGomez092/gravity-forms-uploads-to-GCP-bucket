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
                    'response-content-disposition' => 'inline; filename="' . str_replace( '"', '', $file['original_name'] ?? 'file' ) . '"',
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

    private static function client_ip() {
        foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $first = explode( ',', $_SERVER[ $h ] )[0];
                return trim( $first );
            }
        }
        return '0.0.0.0';
    }
}
