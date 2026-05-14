<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Migration {

    const MIME_MAP = array(
        'image/jpeg' => array( 'jpg', 'jpeg' ),
        'image/png'  => array( 'png' ),
        'image/gif'  => array( 'gif' ),
        'image/webp' => array( 'webp' ),
        'image/bmp'  => array( 'bmp' ),
        'image/svg+xml' => array( 'svg' ),
        'image/*'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg' ),
        'application/pdf'  => array( 'pdf' ),
        'application/zip'  => array( 'zip' ),
        'application/msword' => array( 'doc' ),
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => array( 'docx' ),
        'application/vnd.ms-excel' => array( 'xls' ),
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => array( 'xlsx' ),
        'text/csv'   => array( 'csv' ),
        'text/plain' => array( 'txt' ),
    );

    /**
     * Apply MIME→extension translation to every gcs_upload field in the supplied forms.
     * Pure function — no DB writes. Caller is responsible for persisting + clearing warnings.
     *
     * @param array $forms List of form arrays as returned by GFAPI::get_forms().
     * @return array{0:array,1:array} [migrated_forms, warnings]
     *   warnings: list of { form_id, field_id, mimes_value }
     */
    public static function migrate_forms( array $forms ) {
        $warnings = array();
        foreach ( $forms as &$form ) {
            if ( empty( $form['fields'] ) ) { continue; }
            foreach ( $form['fields'] as $field ) {
                if ( ! is_object( $field ) || ( $field->type ?? '' ) !== 'gcs_upload' ) { continue; }
                if ( ! isset( $field->allowedMimes ) ) { continue; }
                $mimes_value = (string) $field->allowedMimes;
                list( $exts, $unmapped ) = self::map_mimes_to_exts( $mimes_value );
                $field->allowedExtensions = $exts;
                unset( $field->allowedMimes );
                if ( ! empty( $unmapped ) ) {
                    $warnings[] = array(
                        'form_id'     => (int) ( $form['id'] ?? 0 ),
                        'field_id'    => (int) ( $field->id ?? 0 ),
                        'mimes_value' => $mimes_value,
                    );
                }
            }
        }
        unset( $form );
        return array( $forms, $warnings );
    }

    /**
     * @param string $mimes Comma-separated MIME list (e.g. "image/jpeg, application/pdf").
     * @return array{0:string,1:array} [extensions_csv, unmappable_inputs]
     */
    public static function map_mimes_to_exts( $mimes ) {
        $unmapped = array();
        $exts     = array();
        $entries  = array_filter( array_map( 'trim', explode( ',', (string) $mimes ) ) );
        foreach ( $entries as $entry ) {
            $key = strtolower( $entry );
            if ( isset( self::MIME_MAP[ $key ] ) ) {
                foreach ( self::MIME_MAP[ $key ] as $e ) {
                    if ( ! in_array( $e, $exts, true ) ) { $exts[] = $e; }
                }
            } else {
                $unmapped[] = $entry;
            }
        }
        return array( implode( ',', $exts ), $unmapped );
    }
}
