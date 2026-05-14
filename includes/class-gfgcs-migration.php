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

    public static function maybe_run() {
        if ( get_option( 'gfgcs_version' ) === GFGCS_VERSION ) { return; }
        if ( ! class_exists( 'GFAPI' ) ) { return; }

        $forms = \GFAPI::get_forms( true, true );
        list( $migrated, $warnings ) = self::migrate_forms( $forms );

        foreach ( $migrated as $form ) {
            \GFAPI::update_form( $form );
        }

        if ( ! empty( $warnings ) ) {
            update_option( 'gfgcs_migration_warnings', $warnings, false );
        }
        update_option( 'gfgcs_version', GFGCS_VERSION, false );
    }

    public static function render_notice() {
        $warnings = get_option( 'gfgcs_migration_warnings', array() );
        if ( empty( $warnings ) ) { return; }
        $items = array();
        foreach ( $warnings as $w ) {
            $items[] = sprintf(
                esc_html__( 'Form %1$d, field %2$d: %3$s', 'gf-gcs-uploads' ),
                (int) $w['form_id'], (int) $w['field_id'], esc_html( $w['mimes_value'] )
            );
        }
        $dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=gfgcs_dismiss_migration_warnings' ), 'gfgcs_dismiss_warnings' );
        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>%s</strong></p><ul style="margin-left:24px;list-style:disc"><li>%s</li></ul><p><a class="button" href="%s">%s</a></p></div>',
            esc_html__( 'GCS Uploads: the following MIME types could not be translated to file extensions during the 0.2.0 upgrade. Please reconfigure these fields.', 'gf-gcs-uploads' ),
            implode( '</li><li>', $items ),
            esc_url( $dismiss_url ),
            esc_html__( 'Dismiss', 'gf-gcs-uploads' )
        );
    }

    public static function dismiss_notice() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
        check_admin_referer( 'gfgcs_dismiss_warnings' );
        delete_option( 'gfgcs_migration_warnings' );
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
}
