<?php
defined( 'ABSPATH' ) || exit;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-proxy.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';

class GFGCS_Merge_Tags {

    public static function register() {
        add_filter( 'gform_entry_field_value', array( __CLASS__, 'filter_entry_field_value' ), 10, 4 );
        add_filter( 'gform_replace_merge_tags', array( __CLASS__, 'filter_replace_merge_tags' ), 10, 7 );
    }

    public static function render( array $files, $entry_id, $field_id, $modifier = 'urls', $secret = null ) {
        if ( empty( $files ) ) return '';
        $secret = $secret ?: GFGCS_Settings::signing_secret();
        $urls = array();
        foreach ( $files as $i => $f ) {
            $tok    = GFGCS_Proxy::token( $entry_id, $field_id, $i, $secret );
            $urls[] = rest_url( "gfgcs/v1/f/{$entry_id}/{$field_id}/{$i}/{$tok}" );
        }
        if ( $modifier === 'json' ) {
            return wp_json_encode( $urls );
        }
        return implode( "\n", $urls );
    }

    /** Override the entry-display value so admin-detail screens use proxy URLs too. */
    public static function filter_entry_field_value( $value, $field, $entry, $form ) {
        if ( ! $field || $field->type !== 'gcs_upload' ) return $value;
        $files = is_string( $value ) ? json_decode( $value, true ) : ( is_array( $value ) ? $value : array() );
        if ( ! is_array( $files ) ) return '';
        $secret = GFGCS_Settings::signing_secret();
        $links  = array();
        foreach ( $files as $i => $f ) {
            $tok    = GFGCS_Proxy::token( $entry['id'], $field->id, $i, $secret );
            $url    = rest_url( "gfgcs/v1/f/{$entry['id']}/{$field->id}/{$i}/{$tok}" );
            $name   = esc_html( $f['original_name'] ?? $f['object_path'] ?? '' );
            $links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $name . '</a>';
        }
        return implode( '<br>', $links );
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
                $modifier = $m[2] ?? 'urls';
                $field    = null;
                foreach ( (array) $form['fields'] as $f ) {
                    if ( (int) $f->id === $field_id ) { $field = $f; break; }
                }
                if ( ! $field || $field->type !== 'gcs_upload' ) {
                    return $m[0];
                }
                $raw   = $entry[ (string) $field_id ] ?? '';
                $files = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
                $value = self::render( is_array( $files ) ? $files : array(), $entry['id'], $field_id, $modifier, $secret );
                return $url_encode ? rawurlencode( $value ) : $value;
            },
            $text
        );
    }
}
