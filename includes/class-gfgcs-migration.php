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
