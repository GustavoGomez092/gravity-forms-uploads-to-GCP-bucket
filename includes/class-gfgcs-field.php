<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GF_Field' ) ) {
    return;
}

class GF_Field_GCSUpload extends GF_Field {
    public $type = 'gcs_upload';

    public function get_form_editor_field_title() {
        return esc_attr__( 'GCS Upload', 'gf-gcs-uploads' );
    }

    public function get_form_editor_button() {
        return array( 'group' => 'advanced_fields', 'text' => $this->get_form_editor_field_title() );
    }

    public function get_form_editor_field_settings() {
        return array(
            'label_setting',
            'description_setting',
            'rules_setting',
            'gcs_multiple_files_setting',
            'gcs_max_files_setting',
            'gcs_max_size_setting',
            'gcs_mime_setting',
            'css_class_setting',
            'visibility_setting',
            'admin_label_setting',
        );
    }

    public function get_field_input( $form, $value = '', $entry = null ) {
        $form_id   = absint( $form['id'] );
        $field_id  = intval( $this->id );
        $multiple  = ! empty( $this->multipleFiles );
        $max_files = max( 1, intval( $this->maxFiles ?: 20 ) );
        $max_size  = max( 1, intval( $this->maxFileSize ?: 1024 ) );
        $mimes     = $this->allowedMimes ?: '';
        $name      = "input_{$field_id}";
        $value_str = is_string( $value ) ? $value : ( is_array( $value ) ? wp_json_encode( $value ) : '' );

        $config = array(
            'formId'    => $form_id,
            'fieldId'   => $field_id,
            'multiple'  => $multiple,
            'maxFiles'  => $max_files,
            'maxSize'   => $max_size * 1024 * 1024,
            'mimes'     => array_filter( array_map( 'trim', explode( ',', $mimes ) ) ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'gfgcs_init_' . $form_id ),
        );

        return sprintf(
            '<div class="ginput_container ginput_container_gcs_upload" data-gfgcs-config="%s">'
            . '<div class="gfgcs-dropzone" tabindex="0" role="button">%s</div>'
            . '<ul class="gfgcs-files" aria-live="polite"></ul>'
            . '<input type="hidden" name="%s" id="%s" class="gfgcs-hidden" value="%s" />'
            . '<noscript><p>%s</p></noscript>'
            . '</div>',
            esc_attr( wp_json_encode( $config ) ),
            esc_html__( 'Drop files here or click to browse', 'gf-gcs-uploads' ),
            esc_attr( $name ),
            esc_attr( $name ),
            esc_attr( $value_str ),
            esc_html__( 'JavaScript is required to upload files securely.', 'gf-gcs-uploads' )
        );
    }

    public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
        $files = is_string( $value ) ? json_decode( $value, true ) : ( is_array( $value ) ? $value : array() );
        if ( ! is_array( $files ) || empty( $files ) ) {
            return '';
        }
        $out = array();
        foreach ( $files as $f ) {
            $out[] = esc_html( $f['original_name'] ?? $f['object_path'] ?? '' );
        }
        return $format === 'html' ? '<ul><li>' . implode( '</li><li>', $out ) . '</li></ul>' : implode( "\n", $out );
    }
}

GF_Fields::register( new GF_Field_GCSUpload() );

add_action( 'gform_field_advanced_settings', function ( $position ) {
    if ( $position === 50 ) {
        ?>
        <li class="gcs_multiple_files_setting field_setting">
            <label><input type="checkbox" id="field_gcs_multiple" onclick="SetFieldProperty('multipleFiles', this.checked);" />
                <?php esc_html_e( 'Allow multiple files', 'gf-gcs-uploads' ); ?></label>
        </li>
        <li class="gcs_max_files_setting field_setting">
            <label><?php esc_html_e( 'Max files', 'gf-gcs-uploads' ); ?>
                <input type="number" min="1" max="50" id="field_gcs_max_files" onchange="SetFieldProperty('maxFiles', parseInt(this.value,10) || 1);" /></label>
        </li>
        <li class="gcs_max_size_setting field_setting">
            <label><?php esc_html_e( 'Max file size (MB)', 'gf-gcs-uploads' ); ?>
                <input type="number" min="1" id="field_gcs_max_size" onchange="SetFieldProperty('maxFileSize', parseInt(this.value,10) || 1);" /></label>
        </li>
        <li class="gcs_mime_setting field_setting">
            <label><?php esc_html_e( 'Allowed MIME types (comma separated, glob style)', 'gf-gcs-uploads' ); ?>
                <input type="text" id="field_gcs_mimes" onchange="SetFieldProperty('allowedMimes', this.value);" /></label>
        </li>
        <?php
    }
}, 10, 1 );

add_filter( 'gform_tooltips', function ( $tt ) {
    $tt['form_field_gcs_max_size'] = esc_html__( 'Per-file maximum, in MB. Leave blank to use form/global default.', 'gf-gcs-uploads' );
    return $tt;
} );

add_action( 'gform_enqueue_scripts', function ( $form ) {
    $has = false;
    foreach ( (array) $form['fields'] as $f ) {
        if ( $f->type === 'gcs_upload' ) { $has = true; break; }
    }
    if ( ! $has ) return;
    wp_enqueue_style( 'gfgcs-field', GFGCS_PLUGIN_URL . 'assets/css/gfgcs-field.css', array(), GFGCS_VERSION );
    wp_enqueue_script( 'gfgcs-uploader', GFGCS_PLUGIN_URL . 'assets/js/gfgcs-uploader.js', array(), GFGCS_VERSION, true );
    wp_scripts()->add_data( 'gfgcs-uploader', 'type', 'module' );
}, 10, 1 );
