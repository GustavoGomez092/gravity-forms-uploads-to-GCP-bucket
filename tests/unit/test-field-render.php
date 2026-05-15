<?php
// phpcs:disable

// ── Global-namespace stubs (must come before any namespace block) ────────────
namespace {
    if ( ! class_exists( 'GF_Field' ) ) {
        class GF_Field {
            public $id               = 0;
            public $type             = '';
            public $multipleFiles    = false;
            public $maxFiles         = 0;
            public $maxFileSize      = 0;
            public $allowedExtensions = '';
        }
    }
    if ( ! class_exists( 'GF_Fields' ) ) {
        class GF_Fields {
            public static function register( $f ) {}
        }
    }

    // WP hook stubs so class-gfgcs-field.php can be loaded in isolation.
    if ( ! function_exists( 'add_action' ) ) {
        function add_action( $tag, $cb, $priority = 10, $args = 1 ) {}
    }
    if ( ! function_exists( 'add_filter' ) ) {
        function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {}
    }
    if ( ! function_exists( 'absint' ) ) {
        function absint( $v ) { return abs( intval( $v ) ); }
    }
    if ( ! function_exists( 'wp_create_nonce' ) ) {
        function wp_create_nonce( $a ) { return 'noncestub'; }
    }
    if ( ! function_exists( 'admin_url' ) ) {
        function admin_url( $p ) { return 'http://e/'.$p; }
    }
    if ( ! function_exists( 'esc_attr' ) ) {
        function esc_attr( $v ) { return htmlspecialchars( $v, ENT_QUOTES ); }
    }
    if ( ! function_exists( 'esc_html__' ) ) {
        function esc_html__( $s, $d = '' ) { return $s; }
    }
    if ( ! function_exists( 'esc_html' ) ) {
        function esc_html( $s ) { return htmlspecialchars( $s ); }
    }
    if ( ! function_exists( 'esc_url' ) ) {
        function esc_url( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
    }
    if ( ! function_exists( 'rest_url' ) ) {
        function rest_url( $p ) { return 'https://example.test/wp-json/' . ltrim( $p, '/' ); }
    }
    if ( ! function_exists( 'wp_json_encode' ) ) {
        function wp_json_encode( $d ) { return json_encode( $d ); }
    }

    require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-field.php';
}

// ── Test class ───────────────────────────────────────────────────────────────
namespace GFGCS\Tests\Unit {

    use PHPUnit\Framework\TestCase;

    class FieldRenderTest extends TestCase {

        public function test_settings_list_uses_native_general_section_blocks() {
            $f        = new \GF_Field_GCSUpload();
            $settings = $f->get_form_editor_field_settings();
            $this->assertContains( 'file_extensions_setting', $settings );
            $this->assertContains( 'multiple_files_setting', $settings );
            $this->assertContains( 'file_size_setting', $settings );
            $this->assertContains( 'rules_setting', $settings );
            $this->assertNotContains( 'gcs_mime_setting', $settings );
            $this->assertNotContains( 'gcs_max_files_setting', $settings );
        }

        public function test_rules_caption_single_file_shows_max_size_only() {
            $f = new \GF_Field_GCSUpload();
            $f->multipleFiles = false;
            $f->maxFileSize   = 5;
            $this->assertSame( 'Max. file size: 5 MB.', $f->render_rules_caption() );
        }

        public function test_rules_caption_multi_file_with_cap_shows_both() {
            $f = new \GF_Field_GCSUpload();
            $f->multipleFiles = true;
            $f->maxFileSize   = 5;
            $f->maxFiles      = 10;
            $this->assertSame( 'Max. file size: 5 MB. Maximum number of files: 10.', $f->render_rules_caption() );
        }

        public function test_rules_caption_multi_file_without_cap_shows_size_only() {
            $f = new \GF_Field_GCSUpload();
            $f->multipleFiles = true;
            $f->maxFileSize   = 5;
            $f->maxFiles      = 0;
            $this->assertSame( 'Max. file size: 5 MB.', $f->render_rules_caption() );
        }

        public function test_get_field_input_single_file_uses_native_classes() {
            $f = new \GF_Field_GCSUpload();
            $f->id = 53;
            $f->multipleFiles = false;
            $f->maxFileSize = 2;

            $html = $f->get_field_input( array( 'id' => 8 ), '', null );

            $this->assertStringContainsString( 'ginput_container_fileupload', $html );
            $this->assertStringContainsString( 'ginput_container_fileupload_gcs', $html );
            $this->assertStringContainsString( 'Max. file size: 2 MB.', $html );
            $this->assertStringContainsString( 'name="input_53"', $html );
            $this->assertStringNotContainsString( 'gform_drop_area', $html );
        }

        public function test_get_field_input_multi_file_uses_native_dropzone_markup() {
            $f = new \GF_Field_GCSUpload();
            $f->id = 53;
            $f->multipleFiles = true;
            $f->maxFileSize = 2;
            $f->maxFiles = 5;

            $html = $f->get_field_input( array( 'id' => 8 ), '', null );

            // Renamed from native `gform_fileupload_multifile` to avoid GF's
            // frontend plupload-init from crashing on our element.
            $this->assertStringContainsString( 'gfgcs-multifile', $html );
            $this->assertStringNotContainsString( 'gform_fileupload_multifile', $html );
            $this->assertStringContainsString( 'gform_drop_area', $html );
            $this->assertStringContainsString( 'gform_drop_instructions', $html );
            $this->assertStringContainsString( 'gform_button_select_files', $html );
            $this->assertStringContainsString( 'ginput_preview_list', $html );
            $this->assertStringContainsString( 'Max. file size: 2 MB. Maximum number of files: 5.', $html );
            $this->assertStringContainsString( 'name="input_53"', $html );
        }

        public function test_get_value_merge_tag_html_outputs_file_link() {
            $f = new \GF_Field_GCSUpload();
            $f->id = 52;
            $raw = wp_json_encode( array(
                array(
                    'object_path'   => 'gravityforms/2026/05/submission/52/file-uuid/video_clip.mp4',
                    'original_name' => 'video_clip.mp4',
                    'size'          => 5242912,
                    'mime'          => 'video/mp4',
                    'file_uuid'     => 'file-uuid',
                ),
            ) );

            $out = $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), '', $raw, false, false, 'html', false );

            $this->assertStringContainsString( '<a href="https://example.test/wp-json/gfgcs/v1/f/6016/52/0/', $out );
            $this->assertStringContainsString( 'target="_blank"', $out );
            $this->assertStringContainsString( 'rel="noopener"', $out );
            $this->assertStringContainsString( '>video_clip.mp4</a>', $out );
            $this->assertStringNotContainsString( 'object_path', $out );
        }

        public function test_get_value_merge_tag_text_outputs_plain_proxy_url() {
            $f = new \GF_Field_GCSUpload();
            $f->id = 52;
            $raw = wp_json_encode( array(
                array( 'object_path' => 'p/52/u/a.mp4', 'original_name' => 'a.mp4' ),
            ) );

            $out = $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), '', $raw, false, false, 'text', false );

            $this->assertStringStartsWith( 'https://example.test/wp-json/gfgcs/v1/f/6016/52/0/', $out );
            $this->assertStringNotContainsString( '<a ', $out );
            $this->assertStringNotContainsString( 'object_path', $out );
        }

        public function test_get_value_merge_tag_modifiers_return_requested_values() {
            $f = new \GF_Field_GCSUpload();
            $f->id = 52;
            $raw = wp_json_encode( array(
                array(
                    'object_path'   => 'p/52/u/a.mp4',
                    'original_name' => 'a.mp4',
                    'size'          => 123,
                    'mime'          => 'video/mp4',
                ),
            ) );

            $this->assertSame( 'a.mp4', $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), 'filename', $raw, false, false, 'html', false ) );
            $this->assertSame( '123', $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), 'size', $raw, false, false, 'html', false ) );
            $this->assertSame( 'video/mp4', $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), 'mime', $raw, false, false, 'html', false ) );
            $this->assertSame( 'p/52/u/a.mp4', $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), 'key', $raw, false, false, 'html', false ) );

            $json = $f->get_value_merge_tag( $raw, '52', array( 'id' => 6016 ), array(), 'json', $raw, false, false, 'html', false );
            $decoded = json_decode( $json, true );
            $this->assertIsArray( $decoded );
            $this->assertStringStartsWith( 'https://example.test/wp-json/gfgcs/v1/f/6016/52/0/', $decoded[0] );
        }
    }
}
