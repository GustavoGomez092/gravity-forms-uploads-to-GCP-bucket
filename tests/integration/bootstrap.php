<?php
require_once __DIR__ . '/../../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'GFGCS_PLUGIN_DIR' ) ) define( 'GFGCS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
if ( ! defined( 'GFGCS_PLUGIN_URL' ) ) define( 'GFGCS_PLUGIN_URL', 'https://example.test/wp-content/plugins/gf-gcs-uploads/' );
if ( ! defined( 'GFGCS_VERSION' ) ) define( 'GFGCS_VERSION', '0.1.0-test' );

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-signer.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-oauth.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-gcs-client.php';

// Real-network shims for wp_remote_* via curl, since Brain Monkey isn't loaded here.
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) {
        return _gfgcs_it_http( $url, array_merge( $args, array( 'method' => 'GET' ) ) );
    }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        return _gfgcs_it_http( $url, array_merge( $args, array( 'method' => 'POST' ) ) );
    }
}
if ( ! function_exists( 'wp_remote_request' ) ) {
    function wp_remote_request( $url, $args = array() ) {
        return _gfgcs_it_http( $url, $args );
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $r ) { return is_array( $r ) && isset( $r['_wp_error'] ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $r, $h ) {
        if ( ! is_array( $r ) || empty( $r['headers'] ) ) return '';
        $h = strtolower( $h );
        foreach ( $r['headers'] as $k => $v ) {
            if ( strtolower( $k ) === $h ) return is_array( $v ) ? $v[0] : $v;
        }
        return '';
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl ) { return true; } }

function _gfgcs_it_http( $url, $args ) {
    $ch = curl_init( $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_HEADER, true );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $args['method'] ?? 'GET' );
    if ( ! empty( $args['headers'] ) ) {
        $hh = array();
        foreach ( $args['headers'] as $k => $v ) { $hh[] = $k . ': ' . $v; }
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $hh );
    }
    if ( isset( $args['body'] ) ) {
        if ( is_array( $args['body'] ) ) {
            curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $args['body'] ) );
        } else {
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
        }
    }
    if ( ! empty( $args['timeout'] ) ) {
        curl_setopt( $ch, CURLOPT_TIMEOUT, intval( $args['timeout'] ) );
    }
    $raw = curl_exec( $ch );
    if ( $raw === false ) {
        return array( '_wp_error' => true, 'message' => curl_error( $ch ) );
    }
    $code        = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    $header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
    $headers_raw = substr( $raw, 0, $header_size );
    $body        = substr( $raw, $header_size );
    curl_close( $ch );

    $headers = array();
    foreach ( preg_split( "/\r?\n/", $headers_raw ) as $line ) {
        if ( strpos( $line, ':' ) !== false ) {
            list( $k, $v ) = explode( ':', $line, 2 );
            $headers[ strtolower( trim( $k ) ) ] = trim( $v );
        }
    }
    return array(
        'response' => array( 'code' => (int) $code ),
        'headers'  => $headers,
        'body'     => $body,
    );
}

function gfgcs_it_skip_unless_configured( $test ) {
    if ( ! getenv( 'GFGCS_IT_SA_JSON' ) || ! getenv( 'GFGCS_IT_BUCKET' ) ) {
        $test->markTestSkipped( 'Set GFGCS_IT_SA_JSON and GFGCS_IT_BUCKET env vars to run integration tests' );
    }
}
