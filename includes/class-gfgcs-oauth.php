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
            $exc = new \GFGCS_OAuth_Exception( 'OAuth token request failed (transient): ' . $response->get_error_message() );
            $exc->kind = 'transient';
            throw $exc;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $err_code   = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : '';
            $err_msg    = is_array( $body ) && isset( $body['error_description'] ) ? (string) $body['error_description'] : 'unknown';
            $is_transient = ( $code >= 500 ) || $code === 0 || $code === 429;
            $hint = self::oauth_error_hint( $err_code );
            $exc  = new \GFGCS_OAuth_Exception( sprintf( 'OAuth token exchange failed (HTTP %d, %s): %s%s', $code, $is_transient ? 'transient' : 'permanent', $err_msg, $hint ) );
            $exc->kind = $is_transient ? 'transient' : 'permanent';
            $exc->error_code = $err_code;
            throw $exc;
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

    private static function oauth_error_hint( $err_code ) {
        switch ( $err_code ) {
            case 'invalid_grant':       return ' — likely causes: clock skew on this server, or the service-account key has been revoked/rotated. Verify NTP and re-paste a fresh key.';
            case 'unauthorized_client': return ' — the service account is not authorized for this token request. Check IAM bindings.';
            case 'invalid_request':     return ' — the JWT assertion is malformed. Check the SA JSON for completeness.';
            default:                    return $err_code !== '' ? " ($err_code)" : '';
        }
    }
}

class GFGCS_OAuth_Exception extends \RuntimeException {
    /** @var string 'transient' | 'permanent' */
    public $kind = 'permanent';
    /** @var string OAuth error code, e.g. 'invalid_grant' */
    public $error_code = '';
}
