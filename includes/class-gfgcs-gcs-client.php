<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_GCS_Client {
    const BASE = 'https://storage.googleapis.com/storage/v1';

    /** @var object an instance of GFGCS_OAuth (or a duck-typed test double) */
    private $oauth;

    public function __construct( $oauth ) {
        $this->oauth = $oauth;
    }

    /**
     * Fetch object metadata (size, content type). Returns null on 404, throws on transport / 5xx.
     * @return array{size:int, content_type:string, name:string}|null
     */
    public function object_metadata( $bucket, $object_path ) {
        $url = self::BASE . '/b/' . rawurlencode( $bucket ) . '/o/' . rawurlencode( $object_path );
        $res = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Authorization' => 'Bearer ' . $this->oauth->get_access_token() ),
        ) );
        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( 'GCS object_metadata transport error: ' . $res->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code === 404 ) {
            return null;
        }
        if ( $code >= 500 ) {
            throw new \RuntimeException( "GCS object_metadata HTTP $code" );
        }
        if ( $code !== 200 ) {
            throw new \RuntimeException( "GCS object_metadata unexpected HTTP $code" );
        }
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        return array(
            'size'         => isset( $body['size'] ) ? (int) $body['size'] : 0,
            'content_type' => $body['contentType'] ?? '',
            'name'         => $body['name'] ?? '',
        );
    }

    public function delete_object( $bucket, $object_path ) {
        $url = self::BASE . '/b/' . rawurlencode( $bucket ) . '/o/' . rawurlencode( $object_path );
        $res = wp_remote_request( $url, array(
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => array( 'Authorization' => 'Bearer ' . $this->oauth->get_access_token() ),
        ) );
        if ( is_wp_error( $res ) ) {
            return false;
        }
        $code = wp_remote_retrieve_response_code( $res );
        return $code === 204 || $code === 404; // 404 = already gone, treat as success.
    }

    public function list_objects( $bucket, $prefix = '', $max = 1 ) {
        $url = self::BASE . '/b/' . rawurlencode( $bucket ) . '/o?maxResults=' . intval( $max ) . '&prefix=' . rawurlencode( $prefix );
        $res = wp_remote_get( $url, array(
            'timeout' => 5,
            'headers' => array( 'Authorization' => 'Bearer ' . $this->oauth->get_access_token() ),
        ) );
        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( 'GCS list_objects transport error: ' . $res->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) {
            throw new \RuntimeException( "GCS list_objects HTTP $code: " . wp_remote_retrieve_body( $res ) );
        }
        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        return $body['items'] ?? array();
    }
}
