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
}
