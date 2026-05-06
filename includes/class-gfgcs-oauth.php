<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mints OAuth 2.0 access tokens from a service account JSON key.
 * Uses WP HTTP API for the token exchange call (so it's testable + WP-friendly).
 */
class GFGCS_OAuth {
    const TOKEN_ENDPOINT = 'https://www.googleapis.com/oauth2/v4/token';
    const SCOPE          = 'https://www.googleapis.com/auth/devstorage.read_write';
    const TRANSIENT_KEY  = 'gfgcs_oauth_token';

    private $sa;
    private $now;

    public function __construct( array $sa, $now = null ) {
        if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            throw new \InvalidArgumentException( 'Service account JSON missing client_email or private_key' );
        }
        $this->sa  = $sa;
        $this->now = $now;
    }

    /** Returns a fresh access token, caching it via WP transient until ~5 min before expiry. */
    public function get_access_token() {
        if ( function_exists( 'get_transient' ) ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( is_string( $cached ) && $cached !== '' ) {
                return $cached;
            }
        }

        $assertion = $this->build_assertion();
        $response  = wp_remote_post( self::TOKEN_ENDPOINT, array(
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ),
            'timeout' => 10,
        ) );
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( 'OAuth token request failed: ' . $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $msg = is_array( $body ) && isset( $body['error_description'] ) ? $body['error_description'] : 'unknown';
            throw new \RuntimeException( "OAuth token exchange failed (HTTP $code): $msg" );
        }
        $token = $body['access_token'];
        $ttl   = max( 60, intval( $body['expires_in'] ?? 3600 ) - 300 );
        if ( function_exists( 'set_transient' ) ) {
            set_transient( self::TRANSIENT_KEY, $token, $ttl );
        }
        return $token;
    }

    /** Builds and signs the JWT assertion. Public for testability. */
    public function build_assertion() {
        $iat = $this->now ?: time();
        $exp = $iat + 3600;

        $header  = array( 'alg' => 'RS256', 'typ' => 'JWT' );
        $payload = array(
            'iss'   => $this->sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_ENDPOINT,
            'iat'   => $iat,
            'exp'   => $exp,
        );
        $h = self::b64url( wp_json_encode( $header ) );
        $p = self::b64url( wp_json_encode( $payload ) );
        $signing_input = $h . '.' . $p;

        $key = openssl_pkey_get_private( $this->sa['private_key'] );
        if ( ! $key ) {
            throw new \RuntimeException( 'Failed to parse private key' );
        }
        $signature = '';
        $ok        = openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 );
        if ( PHP_VERSION_ID < 80000 ) {
            openssl_free_key( $key );
        }
        if ( ! $ok ) {
            throw new \RuntimeException( 'JWT signing failed' );
        }
        return $signing_input . '.' . self::b64url( $signature );
    }

    private static function b64url( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
