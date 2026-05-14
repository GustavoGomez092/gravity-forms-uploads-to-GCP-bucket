<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';

class ExtAllowedTest extends TestCase {

    public function test_empty_allow_list_accepts_any_extension() {
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'photo.jpg', array() ) );
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'doc.pdf',   array() ) );
    }

    public function test_allow_list_membership_is_case_insensitive() {
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'photo.JPG', array( 'jpg', 'png' ) ) );
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'photo.jpg', array( 'JPG' ) ) );
    }

    public function test_allow_list_strips_leading_dot() {
        $this->assertTrue( \GFGCS_Ajax::ext_allowed( 'photo.jpg', array( '.jpg' ) ) );
    }

    public function test_extension_not_in_list_is_rejected() {
        $this->assertFalse( \GFGCS_Ajax::ext_allowed( 'doc.pdf', array( 'jpg', 'png' ) ) );
    }

    public function test_filename_with_no_extension_is_rejected_when_list_is_non_empty() {
        $this->assertFalse( \GFGCS_Ajax::ext_allowed( 'no-extension', array( 'jpg' ) ) );
    }

    public function test_dotfile_extension_is_extracted_like_pathinfo() {
        // .htaccess → extension "htaccess". Caught by ext_disallowed elsewhere; here we just pin the behavior.
        $this->assertTrue(  \GFGCS_Ajax::ext_allowed( '.htaccess', array( 'htaccess' ) ) );
        $this->assertFalse( \GFGCS_Ajax::ext_allowed( '.htaccess', array( 'jpg' ) ) );
    }

    public function test_multi_dot_filename_uses_final_extension() {
        $this->assertTrue(  \GFGCS_Ajax::ext_allowed( 'archive.tar.gz', array( 'gz' ) ) );
        $this->assertFalse( \GFGCS_Ajax::ext_allowed( 'archive.tar.gz', array( 'tar' ) ) );
    }

    public function test_trailing_dot_filename_is_rejected() {
        $this->assertFalse( \GFGCS_Ajax::ext_allowed( 'filename.', array( 'jpg' ) ) );
    }
}
