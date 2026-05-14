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
    }
}
