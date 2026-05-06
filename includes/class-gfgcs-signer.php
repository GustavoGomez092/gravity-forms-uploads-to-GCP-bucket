<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure V4 signer for GCS. No HTTP, no WP. Inputs in, signed URL out.
 */
class GFGCS_Signer {

    /** @var array decoded service account JSON */
    private $sa;

    /** @var int|null injected timestamp (test seam); null = current time */
    private $now;

    public function __construct( array $sa, $now = null ) {
        if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            throw new \InvalidArgumentException( 'Service account JSON missing client_email or private_key' );
        }
        $this->sa  = $sa;
        $this->now = $now;
    }

    /**
     * Sign a V4 URL.
     *
     * @param string $method      HTTP verb (GET, PUT, DELETE, HEAD, POST).
     * @param string $bucket      Bucket name.
     * @param string $object_path Object name (no leading slash).
     * @param int    $expires     Seconds until URL expiry (max 604800 = 7 days).
     * @param array  $headers     Headers to include in the signature; values lowercased.
     * @param array  $query_extra Extra query params to fold into canonical query string.
     * @return string Signed URL.
     */
    public function sign_url( $method, $bucket, $object_path, $expires, array $headers = array(), array $query_extra = array() ) {
        $expires = (int) $expires;
        if ( $expires < 1 || $expires > 604800 ) {
            throw new \InvalidArgumentException( 'Expires must be between 1 and 604800 seconds' );
        }

        $ts          = $this->now ?: time();
        $request_ts  = gmdate( 'Ymd\THis\Z', $ts );
        $datestamp   = gmdate( 'Ymd', $ts );
        $credential  = $this->sa['client_email'] . '/' . $datestamp . '/auto/storage/goog4_request';
        $host        = 'storage.googleapis.com';

        // Always include host header in the signature.
        $headers_lower = array( 'host' => $host );
        foreach ( $headers as $k => $v ) {
            $headers_lower[ strtolower( $k ) ] = trim( (string) $v );
        }
        ksort( $headers_lower );
        $signed_headers   = implode( ';', array_keys( $headers_lower ) );
        $canonical_headers = '';
        foreach ( $headers_lower as $k => $v ) {
            $canonical_headers .= $k . ':' . $v . "\n";
        }

        // Build canonical query string.
        $query = array(
            'X-Goog-Algorithm'     => 'GOOG4-RSA-SHA256',
            'X-Goog-Credential'    => $credential,
            'X-Goog-Date'          => $request_ts,
            'X-Goog-Expires'       => (string) $expires,
            'X-Goog-SignedHeaders' => $signed_headers,
        );
        $query = array_merge( $query, $query_extra );
        ksort( $query );
        $canonical_query_parts = array();
        foreach ( $query as $k => $v ) {
            $canonical_query_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
        }
        $canonical_query = implode( '&', $canonical_query_parts );

        // Canonical resource: /<bucket>/<object>, with path segments percent-encoded
        // (slashes preserved as path delimiters).
        $encoded_object_path = implode( '/', array_map( 'rawurlencode', explode( '/', $object_path ) ) );
        $canonical_resource = '/' . $bucket . '/' . $encoded_object_path;

        $payload_hash = 'UNSIGNED-PAYLOAD';

        $canonical_request = strtoupper( $method ) . "\n"
            . $canonical_resource . "\n"
            . $canonical_query . "\n"
            . $canonical_headers . "\n"
            . $signed_headers . "\n"
            . $payload_hash;

        $string_to_sign = "GOOG4-RSA-SHA256\n"
            . $request_ts . "\n"
            . $datestamp . "/auto/storage/goog4_request\n"
            . hash( 'sha256', $canonical_request );

        $key = openssl_pkey_get_private( $this->sa['private_key'] );
        if ( ! $key ) {
            throw new \RuntimeException( 'Failed to parse service account private key' );
        }
        $signature = '';
        $ok = openssl_sign( $string_to_sign, $signature, $key, OPENSSL_ALGO_SHA256 );
        if ( PHP_VERSION_ID < 80000 ) {
            openssl_free_key( $key );
        }
        if ( ! $ok ) {
            throw new \RuntimeException( 'openssl_sign failed' );
        }

        return 'https://' . $host . $canonical_resource . '?' . $canonical_query
            . '&X-Goog-Signature=' . bin2hex( $signature );
    }

    /**
     * Sign a URL for starting a resumable upload session.
     * The client MUST send this request as POST with the header:
     *     x-goog-resumable: start
     * The 201 response's Location header is the actual session URI for chunk uploads.
     */
    public function sign_resumable_init_url( $bucket, $object_path, $expires = 3600 ) {
        return $this->sign_url(
            'POST',
            $bucket,
            $object_path,
            $expires,
            array( 'x-goog-resumable' => 'start' )
        );
    }
}
