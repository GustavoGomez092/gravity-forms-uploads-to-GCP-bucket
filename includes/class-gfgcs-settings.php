<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Settings {

    const OPTION_GLOBAL = 'gfgcs_global_settings';
    const OPTION_SECRET = 'gfgcs_signing_secret';

    /** Whitelisted tokens for object-prefix expansion. No arbitrary merge tags. */
    const PREFIX_TOKENS = array( 'form_id', 'form_title', 'submission_uuid', 'Y', 'm', 'd' );

    /** AES-256-GCM encrypt; output is base64( iv || tag || ciphertext ). */
    public static function encrypt( $plaintext, $key_material ) {
        $key = hash_hkdf( 'sha256', $key_material, 32, 'gfgcs-settings-v1' );
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( $ct === false ) {
            throw new \RuntimeException( 'encrypt failed' );
        }
        return 'v1:' . base64_encode( $iv . $tag . $ct );
    }

    public static function decrypt( $blob, $key_material ) {
        if ( strpos( $blob, 'v1:' ) !== 0 ) {
            return null;
        }
        $raw = base64_decode( substr( $blob, 3 ), true );
        if ( $raw === false || strlen( $raw ) < 28 ) {
            return null;
        }
        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $ct  = substr( $raw, 28 );
        $key = hash_hkdf( 'sha256', $key_material, 32, 'gfgcs-settings-v1' );
        $pt  = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
        return $pt === false ? null : $pt;
    }

    /** Returns a stable per-site key derived from wp-config salts. */
    public static function site_key() {
        $material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );
        if ( $material === '' ) {
            // Fallback: use site URL + DB prefix. Last resort; should never hit on a real WP install.
            $material = ( defined( 'WP_SITEURL' ) ? WP_SITEURL : '' ) . ( $GLOBALS['table_prefix'] ?? '' );
        }
        return $material;
    }

    public static function redact_sa_for_display( array $sa ) {
        return array(
            'client_email' => $sa['client_email'] ?? '',
            'project_id'   => $sa['project_id'] ?? '',
        );
    }

    public static function expand_prefix( $template, array $ctx ) {
        $out = $template;
        foreach ( self::PREFIX_TOKENS as $tok ) {
            if ( isset( $ctx[ $tok ] ) ) {
                $out = str_replace( '{' . $tok . '}', (string) $ctx[ $tok ], $out );
            }
        }
        return $out;
    }

    /** Reads the global settings; returns array with decrypted SA JSON parsed. */
    public static function get_global() {
        $raw = function_exists( 'get_option' ) ? get_option( self::OPTION_GLOBAL, array() ) : array();
        if ( ! is_array( $raw ) ) {
            $raw = array();
        }
        if ( ! empty( $raw['sa_encrypted'] ) ) {
            $decoded = self::decrypt( $raw['sa_encrypted'], self::site_key() );
            $raw['sa'] = $decoded ? json_decode( $decoded, true ) : null;
        }
        return wp_parse_args( $raw, array(
            'sa'                => null,
            'default_bucket'    => '',
            'default_prefix'    => 'gravityforms/',
            'max_size_mb'       => 1024,
            'allowed_mimes'     => 'image/*, video/*',
            'redirect_lifetime' => 15,
        ) );
    }

    public static function update_global( array $patch ) {
        $existing = function_exists( 'get_option' ) ? get_option( self::OPTION_GLOBAL, array() ) : array();
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        if ( isset( $patch['sa'] ) && is_array( $patch['sa'] ) ) {
            $existing['sa_encrypted'] = self::encrypt( wp_json_encode( $patch['sa'] ), self::site_key() );
            unset( $patch['sa'] );
        }
        $merged = array_merge( $existing, $patch );
        return update_option( self::OPTION_GLOBAL, $merged, false );
    }

    public static function signing_secret() {
        return function_exists( 'get_option' ) ? (string) get_option( self::OPTION_SECRET, '' ) : '';
    }

    public static function rotate_signing_secret() {
        $new = wp_generate_password( 64, true, true );
        update_option( self::OPTION_SECRET, $new, false );
        return $new;
    }
}
