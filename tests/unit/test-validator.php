<?php
namespace GFGCS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-validator.php';

class FakeClient {
    public $responses = array();
    public function object_metadata( $bucket, $path ) {
        $r = $this->responses[ $path ] ?? null;
        if ( $r instanceof \Exception ) throw $r;
        return $r;
    }
}

class ValidatorTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_all_present_with_matching_size_passes() {
        $files = array(
            array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'mime' => 'image/jpeg', 'original_name' => 'a.jpg', 'file_uuid' => 'u1', 'uploaded_at' => '2026-05-06T00:00:00Z' ),
        );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = array( 'size' => 100, 'content_type' => 'image/jpeg', 'name' => 'a.jpg' );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c );
        $this->assertNull( $err );
    }

    public function test_missing_object_fails() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => 'image/jpeg', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = null;
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c );
        $this->assertSame( 'missing_object', $err['code'] );
    }

    public function test_size_mismatch_fails() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => 'image/jpeg', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = array( 'size' => 99, 'content_type' => '', 'name' => '' );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c );
        $this->assertSame( 'size_mismatch', $err['code'] );
    }

    public function test_path_outside_prefix_fails_with_security_code() {
        $files = array( array( 'object_path' => 'OTHER/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => '', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c );
        $this->assertSame( 'tampered_path', $err['code'] );
    }

    public function test_5xx_during_head_returns_soft_fail() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => '', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = new \RuntimeException( 'GCS object_metadata HTTP 503' );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c );
        $this->assertSame( 'verify_unavailable', $err['code'] );
    }

    public function test_empty_value_for_required_field_fails() {
        $err = \GFGCS_Validator::verify_field( array(), 'b', 'gravityforms/', new FakeClient(), true );
        $this->assertSame( 'required', $err['code'] );
    }

    public function test_empty_value_for_non_required_field_passes() {
        $err = \GFGCS_Validator::verify_field( array(), 'b', 'gravityforms/', new FakeClient(), false );
        $this->assertNull( $err );
    }
}
