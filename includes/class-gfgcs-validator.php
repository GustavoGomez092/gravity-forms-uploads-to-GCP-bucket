<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Validator {

    /**
     * Verify all files claimed by a gcs_upload field actually exist in GCS
     * with matching sizes.
     *
     * @param array  $files    Decoded file descriptor array from the hidden input.
     * @param string $bucket   GCS bucket name.
     * @param string $prefix   Expected object-path prefix (may contain tokens).
     * @param object $client   GCS client with object_metadata( $bucket, $path ).
     * @param bool   $required Whether the field is required.
     *
     * @return array{code:string,message:string}|null  null = pass.
     */
    public static function verify_field( $files, $bucket, $prefix, $client, $required = false ) {
        if ( ! is_array( $files ) ) {
            $files = array();
        }

        if ( empty( $files ) ) {
            return $required
                ? array( 'code' => 'required', 'message' => 'This field is required.' )
                : null;
        }

        // Derive the static literal portion of the prefix (strip token placeholders).
        $prefix_match = rtrim( $prefix, '/' );
        $literal      = preg_replace( '/\{[a-z_]+\}.*$/', '', $prefix_match );
        $literal      = rtrim( $literal, '/' );

        foreach ( $files as $f ) {
            $path = (string) ( $f['object_path'] ?? '' );
            $size = (int) ( $f['size'] ?? 0 );

            // Basic integrity: path must be non-empty and size must be positive.
            if ( $path === '' || $size <= 0 ) {
                return array( 'code' => 'tampered_path', 'message' => 'Submission integrity check failed.' );
            }

            // Path-tampering check: must start with the literal prefix portion.
            if ( $literal !== '' && strpos( $path, $literal ) !== 0 ) {
                return array( 'code' => 'tampered_path', 'message' => 'Submission integrity check failed.' );
            }

            // HEAD the object in GCS.
            try {
                $meta = $client->object_metadata( $bucket, $path );
            } catch ( \Throwable $e ) {
                // 5xx / network error — soft-fail, never permit through.
                return array( 'code' => 'verify_unavailable', 'message' => 'File verification temporarily unavailable. Please try again.' );
            }

            // Object must exist.
            if ( $meta === null ) {
                return array( 'code' => 'missing_object', 'message' => 'One or more files did not finish uploading.' );
            }

            // Reported size must match what the client told us.
            if ( (int) $meta['size'] !== $size ) {
                return array( 'code' => 'size_mismatch', 'message' => 'One or more files did not finish uploading.' );
            }
        }

        return null;
    }
}
