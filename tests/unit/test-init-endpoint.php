<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';

class InitEndpointTest extends TestCase {

    public function test_build_object_path_appends_field_uuid_filename_to_prefix() {
        $path = \GFGCS_Ajax::build_object_path( 'complaints/2026/05/abc-123/', 4, 'f-uuid', 'photo with spaces.jpg' );
        $this->assertSame( 'complaints/2026/05/abc-123/4/f-uuid/photo with spaces.jpg', $path );
    }

    public function test_build_object_path_strips_dangerous_filename_chars() {
        $path = \GFGCS_Ajax::build_object_path( 'p/', 1, 'u', '../../etc/passwd' );
        $this->assertSame( 'p/1/u/etc-passwd', $path );
    }

    public function test_build_object_path_collapses_long_filenames() {
        $name = str_repeat( 'a', 300 ) . '.jpg';
        $path = \GFGCS_Ajax::build_object_path( 'p/', 1, 'u', $name );
        $parts = explode( '/', $path );
        $this->assertLessThanOrEqual( 200, strlen( end( $parts ) ) );
    }

    public function test_mime_allowed_glob_matches_image_star() {
        $this->assertTrue( \GFGCS_Ajax::mime_allowed( 'image/jpeg', array( 'image/*' ) ) );
        $this->assertTrue( \GFGCS_Ajax::mime_allowed( 'video/mp4', array( 'image/*', 'video/*' ) ) );
        $this->assertFalse( \GFGCS_Ajax::mime_allowed( 'application/pdf', array( 'image/*', 'video/*' ) ) );
    }

    public function test_mime_allowed_empty_list_allows_all() {
        $this->assertTrue( \GFGCS_Ajax::mime_allowed( 'application/octet-stream', array() ) );
    }

    public function test_ext_allowed_chains_with_ext_disallowed() {
        // ext_allowed says yes, but ext_disallowed says no — disallowed wins.
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'shell.php', array( 'php' ) ) );
        $this->assertTrue( \GFGCS_Ajax::ext_disallowed( 'shell.php' ) );
    }

    public function test_effective_field_settings_drops_per_field_mime_lookup() {
        // Build a fake form + field with allowedMimes set; the returned settings
        // must NOT pick up the per-field MIME (since we now expect per-field
        // filtering via allowedExtensions, not allowedMimes).
        $field = new \stdClass();
        $field->id = 1;
        $field->type = 'gcs_upload';
        $field->allowedMimes = 'image/jpeg';
        $field->allowedExtensions = 'jpg';
        $field->maxFileSize = 0;
        $form = array( 'id' => 1, 'title' => 't', 'fields' => array( $field ) );
        $global = array(
            'default_bucket' => 'b',
            'default_prefix' => 'p/',
            'allowed_mimes'  => 'image/*',
            'max_size_mb'    => 10,
        );
        $eff = \GFGCS_Ajax::effective_field_settings( $form, 1, $global );
        // allowed_mimes should reflect the global value only, not the per-field allowedMimes.
        $this->assertSame( array( 'image/*' ), $eff['allowed_mimes'] );
        // allowed_extensions surfaced as a new key.
        $this->assertSame( array( 'jpg' ), $eff['allowed_extensions'] );
    }
}
