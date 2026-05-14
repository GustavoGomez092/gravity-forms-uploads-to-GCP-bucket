<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GF_Field' ) ) {
    return;
}

class GF_Field_GCSUpload extends GF_Field {
    public $type = 'gcs_upload';

    /**
     * Always report our own type. GF's editor wires the "Enable Multi-File Upload"
     * checkbox to ToggleMultiFile()->StartChangeInputType("fileupload"), which
     * writes inputType="fileupload" onto our field. The default get_input_type()
     * prefers inputType over type, which makes GF treat us as a native fileupload
     * field on submit ($_POST['input_N'] gets dropped in favor of $_FILES).
     * Hard-pin the return so the corruption can't take effect.
     */
    public function get_input_type() {
        return $this->type;
    }

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
            'file_extensions_setting',
            'multiple_files_setting',
            'file_size_setting',
            'rules_setting',
            'default_value_setting',
            'css_class_setting',
            'visibility_setting',
            'admin_label_setting',
        );
    }

    public function render_rules_caption() {
        $max_mb = max( 1, intval( $this->maxFileSize ?: 0 ) );
        $size_str = sprintf( 'Max. file size: %d MB.', $max_mb );
        if ( ! empty( $this->multipleFiles ) && intval( $this->maxFiles ?: 0 ) > 0 ) {
            return $size_str . ' ' . sprintf( 'Maximum number of files: %d.', intval( $this->maxFiles ) );
        }
        return $size_str;
    }

    public function get_field_input( $form, $value = '', $entry = null ) {
        $form_id   = absint( $form['id'] );
        $field_id  = intval( $this->id );
        $multiple  = ! empty( $this->multipleFiles );
        $max_files = max( 1, intval( $this->maxFiles ?: 20 ) );
        $max_size  = max( 1, intval( $this->maxFileSize ?: 1024 ) );
        $exts_str  = (string) ( $this->allowedExtensions ?? '' );
        $name      = "input_{$field_id}";
        $value_str = is_string( $value ) ? $value : ( is_array( $value ) ? wp_json_encode( $value ) : '' );

        $config = array(
            'formId'            => $form_id,
            'fieldId'           => $field_id,
            'multiple'          => $multiple,
            'maxFiles'          => $max_files,
            'maxSize'           => $max_size * 1024 * 1024,
            'allowedExtensions' => array_values( array_filter( array_map(
                function ( $e ) { return strtolower( ltrim( trim( $e ), '.' ) ); },
                explode( ',', $exts_str )
            ) ) ),
            'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'gfgcs_init_' . $form_id ),
        );

        $config_attr = esc_attr( wp_json_encode( $config ) );
        $caption     = esc_html( $this->render_rules_caption() );

        if ( $multiple ) {
            return $this->render_multifile_markup( $name, $value_str, $config_attr, $caption );
        }
        return $this->render_singlefile_markup( $name, $value_str, $config_attr, $caption );
    }

    private function render_singlefile_markup( $name, $value_str, $config_attr, $caption ) {
        return sprintf(
            '<div class="ginput_container ginput_container_fileupload ginput_container_fileupload_gcs" data-gfgcs-config="%1$s">'
            . '<input type="file" class="gfgcs-file-input" />'
            . '<span class="gfield_description gform_fileupload_rules">%2$s</span>'
            . '<div class="validation_message gfgcs-validation" aria-live="polite"></div>'
            . '<input type="hidden" name="%3$s" class="gfgcs-hidden" value="%4$s" />'
            . '<noscript><p>%5$s</p></noscript>'
            . '</div>',
            $config_attr,
            $caption,
            esc_attr( $name ),
            esc_attr( $value_str ),
            esc_html__( 'JavaScript is required to upload files securely.', 'gf-gcs-uploads' )
        );
    }

    private function render_multifile_markup( $name, $value_str, $config_attr, $caption ) {
        // NOTE: native GF uses `gform_fileupload_multifile` here, but GF's frontend JS
        // scans `.gform_fileupload_multifile` and attempts to attach plupload to each
        // match — which crashes on our element because we don't populate plupload's
        // settings object. Use a sibling class instead. The inner native classes
        // (.gform_drop_area, .gform_button_select_files, .ginput_preview_list) still
        // give us theme styling on the dropzone itself.
        return sprintf(
            '<div class="ginput_container ginput_container_fileupload ginput_container_fileupload_gcs" data-gfgcs-config="%1$s">'
            . '<div class="gfgcs-multifile">'
            . '<div class="gform_drop_area">'
            . '<span class="gform_drop_instructions">%2$s</span>'
            . '<button type="button" class="button gform_button_select_files">%3$s</button>'
            . '</div>'
            . '<input type="file" multiple class="gfgcs-file-input" style="display:none" />'
            . '<span class="gfield_description gform_fileupload_rules">%4$s</span>'
            . '<div class="validation_message gfgcs-validation" aria-live="polite"></div>'
            . '<ul class="ginput_preview_list" aria-live="polite"></ul>'
            . '</div>'
            . '<input type="hidden" name="%5$s" class="gfgcs-hidden" value="%6$s" />'
            . '<noscript><p>%7$s</p></noscript>'
            . '</div>',
            $config_attr,
            esc_html__( 'Drop files here or', 'gf-gcs-uploads' ),
            esc_html__( 'Select files', 'gf-gcs-uploads' ),
            $caption,
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

add_action( 'gform_editor_js', function () {
    wp_enqueue_script(
        'gfgcs-editor',
        GFGCS_PLUGIN_URL . 'assets/js/gfgcs-editor.js',
        array( 'jquery' ),
        GFGCS_VERSION,
        true
    );
} );
