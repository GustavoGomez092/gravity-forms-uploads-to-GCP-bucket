<?php
defined( 'ABSPATH' ) || exit;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-proxy.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';

class GFGCS_Merge_Tags {

    public static function register() {
        add_filter( 'gform_entry_field_value', array( __CLASS__, 'filter_entry_field_value' ), 10, 4 );
        add_filter( 'gform_replace_merge_tags', array( __CLASS__, 'filter_replace_merge_tags' ), 10, 7 );
        add_filter( 'gform_webhooks_request_data', array( __CLASS__, 'filter_webhook_request_data' ), 10, 4 );
    }

    public static function decode_files( $value ) {
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( ! is_string( $value ) || $value === '' ) {
            return array();
        }
        $files = json_decode( $value, true );
        return is_array( $files ) ? $files : array();
    }

    public static function proxy_urls( array $files, $entry_id, $field_id, $secret = null ) {
        if ( empty( $files ) ) return array();
        $secret = $secret ?: GFGCS_Settings::signing_secret();
        $urls = array();
        foreach ( $files as $i => $f ) {
            $tok    = GFGCS_Proxy::token( $entry_id, $field_id, $i, $secret );
            $urls[] = rest_url( "gfgcs/v1/f/{$entry_id}/{$field_id}/{$i}/{$tok}" );
        }
        return $urls;
    }

    public static function render( array $files, $entry_id, $field_id, $modifier = 'urls', $secret = null ) {
        if ( empty( $files ) ) return '';
        $modifier = $modifier ?: 'urls';
        $urls     = self::proxy_urls( $files, $entry_id, $field_id, $secret );

        if ( $modifier === 'json' ) {
            return wp_json_encode( $urls );
        }
        if ( in_array( $modifier, array( 'filename', 'size', 'mime', 'key' ), true ) ) {
            return self::render_metadata( $files, $modifier );
        }
        if ( $modifier === 'html' || $modifier === 'links' ) {
            return self::render_links( $files, $urls );
        }
        return implode( "\n", $urls );
    }

    public static function render_for_format( array $files, $entry_id, $field_id, $modifier = '', $format = 'html', $secret = null ) {
        $modifier = $modifier ?: '';
        if ( $modifier === 'json' || in_array( $modifier, array( 'filename', 'size', 'mime', 'key' ), true ) ) {
            return self::render( $files, $entry_id, $field_id, $modifier, $secret );
        }
        return self::render( $files, $entry_id, $field_id, $format === 'html' ? 'html' : 'urls', $secret );
    }

    private static function render_links( array $files, array $urls ) {
        $links = array();
        foreach ( $files as $i => $f ) {
            $name    = $f['original_name'] ?? $f['object_path'] ?? $urls[ $i ] ?? 'file';
            $links[] = '<a href="' . esc_url( $urls[ $i ] ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>';
        }
        return implode( '<br>', $links );
    }

    private static function render_metadata( array $files, $modifier ) {
        $key = $modifier === 'filename' ? 'original_name' : ( $modifier === 'key' ? 'object_path' : $modifier );
        $values = array();
        foreach ( $files as $f ) {
            $values[] = (string) ( $f[ $key ] ?? '' );
        }
        return implode( "\n", $values );
    }

    /** Override the entry-display value so admin-detail screens use proxy URLs too. */
    public static function filter_entry_field_value( $value, $field, $entry, $form ) {
        if ( ! $field || $field->type !== 'gcs_upload' ) return $value;
        // Read the raw descriptor JSON from the entry directly. By the time this
        // filter fires, $value has already been transformed by
        // GF_Field_GCSUpload::get_value_entry_detail() into a `<ul>` of names,
        // so json_decode($value) would fail and the filter would wipe the
        // display. The entry array always contains the original JSON keyed by
        // field id (string).
        $raw   = isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '';
        $files = self::decode_files( $raw );
        if ( empty( $files ) ) return $value;
        return self::render( $files, $entry['id'], $field->id, 'html' );
    }

    /**
     * Rewrite GCS-upload descriptors in webhook payloads so the raw bucket key is
     * replaced by the HMAC-signed proxy URL while the rest of the descriptor shape
     * (original_name, size, mime, file_uuid) is preserved. The GF Webhooks add-on
     * builds the payload outside our field's value transformations
     * (class-gf-webhooks.php:826-844), so without this the raw object_path reaches
     * the recipient instead of a usable URL.
     *
     * Handles both body-type modes:
     *   - "all_fields": $request_data IS $entry; keys are stringified field ids.
     *   - "select_fields" / "field_values": $request_data keys are the user-defined
     *     custom_keys from $feed['meta']['fieldValues']; we map those back to field ids
     *     so we know which entries correspond to a gcs_upload field.
     */
    public static function filter_webhook_request_data( $request_data, $feed, $entry, $form ) {
        if ( ! is_array( $request_data ) || empty( $form['fields'] ) || empty( $entry['id'] ) ) {
            return $request_data;
        }

        $gcs_field_ids = array();
        foreach ( (array) $form['fields'] as $field ) {
            if ( is_object( $field ) && ( $field->type ?? '' ) === 'gcs_upload' ) {
                $gcs_field_ids[ (int) $field->id ] = (int) $field->id;
            }
        }
        if ( empty( $gcs_field_ids ) ) {
            return $request_data;
        }

        $body_type = $feed['meta']['requestBodyType'] ?? '';
        $key_to_field_id = array();
        if ( $body_type === 'select_fields' || $body_type === 'field_values' ) {
            $mappings = $feed['meta']['fieldValues'] ?? array();
            if ( is_array( $mappings ) ) {
                foreach ( $mappings as $m ) {
                    $value = $m['value'] ?? '';
                    if ( $value === '' || $value === 'gf_custom' ) {
                        continue;
                    }
                    $fid = (int) $value;
                    if ( ! isset( $gcs_field_ids[ $fid ] ) ) {
                        continue;
                    }
                    $key = ( ( $m['key'] ?? '' ) === 'gf_custom' ) ? ( $m['custom_key'] ?? '' ) : ( $m['key'] ?? '' );
                    if ( $key === '' ) {
                        continue;
                    }
                    $key_to_field_id[ $key ] = $fid;
                }
            }
        } else {
            // all_fields and anything else: entry-shaped payload, keys are field ids.
            foreach ( $gcs_field_ids as $fid ) {
                $key_to_field_id[ (string) $fid ] = $fid;
            }
        }

        $secret = GFGCS_Settings::signing_secret();
        foreach ( $key_to_field_id as $key => $fid ) {
            if ( ! array_key_exists( $key, $request_data ) ) {
                continue;
            }
            $files = self::decode_files( $request_data[ $key ] );
            if ( empty( $files ) ) {
                continue;
            }
            $urls = self::proxy_urls( $files, $entry['id'], $fid, $secret );
            $rewritten = array();
            foreach ( $files as $i => $f ) {
                if ( ! is_array( $f ) ) {
                    continue;
                }
                $f['object_path'] = $urls[ $i ] ?? ( $f['object_path'] ?? '' );
                $rewritten[] = $f;
            }
            $request_data[ $key ] = wp_json_encode( $rewritten );
        }
        return $request_data;
    }

    /** Render {Field:N} and {Field:N:json} merge tags as proxy URLs in webhook payloads etc. */
    public static function filter_replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
        if ( ! is_string( $text ) || strpos( $text, '{' ) === false || empty( $entry ) ) {
            return $text;
        }
        $secret = GFGCS_Settings::signing_secret();

        return preg_replace_callback(
            '/{[A-Za-z0-9 _-]+:(\d+)(?::([a-z]+))?}/',
            function ( $m ) use ( $form, $entry, $secret, $url_encode ) {
                $field_id = (int) $m[1];
                $modifier = $m[2] ?? '';
                $field    = null;
                foreach ( (array) $form['fields'] as $f ) {
                    if ( (int) $f->id === $field_id ) { $field = $f; break; }
                }
                if ( ! $field || $field->type !== 'gcs_upload' ) {
                    return $m[0];
                }
                $raw   = $entry[ (string) $field_id ] ?? '';
                $files = self::decode_files( $raw );
                $value = self::render_for_format( $files, $entry['id'], $field_id, $modifier, $format, $secret );
                return $url_encode ? rawurlencode( $value ) : $value;
            },
            $text
        );
    }
}
