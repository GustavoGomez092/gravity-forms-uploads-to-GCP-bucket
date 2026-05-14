<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Validator {

    /**
     * Verify all files claimed by a gcs_upload field actually exist in GCS
     * with matching sizes.
     *
     * @param array  $files                Decoded file descriptor array from the hidden input.
     * @param string $bucket               GCS bucket name.
     * @param string $prefix               Expected object-path prefix (may contain tokens).
     * @param object $client               GCS client with object_metadata( $bucket, $path ).
     * @param bool   $required             Whether the field is required.
     * @param int    $field_id             The numeric ID of the field being validated.
     * @param array  $allowed_extensions   Whitelist of extensions (empty = no filter).
     * @param array  $disallowed_extensions Blacklist of extensions (empty = no deny check).
     *
     * @return array{code:string,message:string}|null  null = pass.
     */
    public static function verify_field(
        $files, $bucket, $prefix, $client, $required = false, $field_id = 0,
        array $allowed_extensions = array(), array $disallowed_extensions = array()
    ) {
        if ( ! is_array( $files ) ) {
            $files = array();
        }

        if ( empty( $files ) ) {
            return $required
                ? array( 'code' => 'required', 'message' => 'This field is required.' )
                : null;
        }

        // Build a regex pattern from the prefix template:
        // literal segments are escaped, {tokens} become [^/]* (matches a single path segment).
        $prefix_match = rtrim( $prefix, '/' );
        $pattern_parts = preg_split( '/(\{[a-z_]+\})/', $prefix_match, -1, PREG_SPLIT_DELIM_CAPTURE );
        $pattern = '';
        foreach ( $pattern_parts as $part ) {
            if ( $part === '' ) continue;
            if ( preg_match( '/^\{[a-z_]+\}$/', $part ) ) {
                $pattern .= '[^/]*';
            } else {
                $pattern .= preg_quote( $part, '#' );
            }
        }
        $prefix_regex = '#^' . $pattern . '/#';

        foreach ( $files as $f ) {
            $path = (string) ( $f['object_path'] ?? '' );
            $size = (int) ( $f['size'] ?? 0 );

            // Basic integrity: path must be non-empty and size must be positive.
            if ( $path === '' || $size <= 0 ) {
                return array( 'code' => 'tampered_path', 'message' => 'Submission integrity check failed.' );
            }

            // Path-tampering check: must match the prefix regex.
            if ( ! preg_match( $prefix_regex, $path, $matched ) ) {
                return array( 'code' => 'tampered_path', 'message' => 'Submission integrity check failed.' );
            }
            $tail = substr( $path, strlen( $matched[0] ) );
            $expected_tail = (int) $field_id . '/' . ( $f['file_uuid'] ?? '' ) . '/';
            if ( $field_id <= 0 || empty( $f['file_uuid'] ) || strpos( $tail, $expected_tail ) !== 0 ) {
                return array( 'code' => 'tampered_path', 'message' => 'Submission integrity check failed.' );
            }

            // Extension check (defense-in-depth): re-validate against stored original_name.
            $original = (string) ( $f['original_name'] ?? '' );
            if ( $original !== '' ) {
                $dot = strrpos( $original, '.' );
                $ext = $dot === false ? '' : strtolower( substr( $original, $dot + 1 ) );
                if ( $ext === '' || in_array( $ext, array_map( 'strtolower', $disallowed_extensions ), true ) ) {
                    return array( 'code' => 'extension_not_allowed', 'message' => 'This type of file is not allowed.' );
                }
                if ( ! empty( $allowed_extensions ) ) {
                    $normalized = array_map( function ( $e ) { return strtolower( ltrim( trim( $e ), '.' ) ); }, $allowed_extensions );
                    if ( ! in_array( $ext, $normalized, true ) ) {
                        return array( 'code' => 'extension_not_allowed', 'message' => 'This type of file is not allowed.' );
                    }
                }
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
