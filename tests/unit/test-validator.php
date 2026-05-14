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
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertNull( $err );
    }

    public function test_missing_object_fails() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => 'image/jpeg', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = null;
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'missing_object', $err['code'] );
    }

    public function test_size_mismatch_fails() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => 'image/jpeg', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = array( 'size' => 99, 'content_type' => '', 'name' => '' );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'size_mismatch', $err['code'] );
    }

    public function test_path_outside_prefix_fails_with_security_code() {
        $files = array( array( 'object_path' => 'OTHER/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => '', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'tampered_path', $err['code'] );
    }

    public function test_5xx_during_head_returns_soft_fail() {
        $files = array( array( 'object_path' => 'gravityforms/4/u1/a.jpg', 'size' => 100, 'file_uuid' => 'u1', 'mime' => '', 'original_name' => 'a.jpg', 'uploaded_at' => 'x' ) );
        $c = new FakeClient();
        $c->responses['gravityforms/4/u1/a.jpg'] = new \RuntimeException( 'GCS object_metadata HTTP 503' );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'verify_unavailable', $err['code'] );
    }

    public function test_empty_value_for_required_field_fails() {
        $err = \GFGCS_Validator::verify_field( array(), 'b', 'gravityforms/', new FakeClient(), true, 4 );
        $this->assertSame( 'required', $err['code'] );
    }

    public function test_empty_value_for_non_required_field_passes() {
        $err = \GFGCS_Validator::verify_field( array(), 'b', 'gravityforms/', new FakeClient(), false, 4 );
        $this->assertNull( $err );
    }

    public function test_token_leading_prefix_is_not_bypassed() {
        // Prefix begins with a token; an attacker submits a path that doesn't fit the template.
        $files = array( array(
            'object_path'  => 'evil/4/u1/a.jpg',
            'size'         => 100,
            'file_uuid'    => 'u1',
            'mime'         => 'image/jpeg',
            'original_name' => 'a.jpg',
            'uploaded_at'  => 'x',
        ) );
        $c = new FakeClient();
        // Prefix template is "{form_id}/uploads/" — expanded path would be like "7/uploads/4/u1/a.jpg"
        // The attacker's "evil/4/u1/a.jpg" can't match "[^/]*/uploads/" so must be rejected.
        $err = \GFGCS_Validator::verify_field( $files, 'b', '{form_id}/uploads/', $c, false, 4 );
        $this->assertSame( 'tampered_path', $err['code'] );
    }

    public function test_uppercase_year_token_is_recognized_in_prefix_template() {
        // Regression: validator's regex builder used [a-z_]+ which failed to recognize
        // the uppercase {Y} token from PREFIX_TOKENS, treating it as a literal "\{Y\}"
        // in the regex and rejecting every legitimate upload as tampered_path.
        $files = array( array(
            'object_path'   => 'gravityforms/2026/05/sub-uuid-abc/4/u1/a.jpg',
            'size'          => 100,
            'file_uuid'     => 'u1',
            'mime'          => 'image/jpeg',
            'original_name' => 'a.jpg',
            'uploaded_at'   => 'x',
        ) );
        $c = new FakeClient();
        $c->responses['gravityforms/2026/05/sub-uuid-abc/4/u1/a.jpg'] = array(
            'size' => 100, 'content_type' => 'image/jpeg', 'name' => 'a.jpg',
        );
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/{Y}/{m}/{submission_uuid}/', $c, false, 4 );
        $this->assertNull( $err );
    }

    public function test_field_id_mismatch_is_rejected() {
        // Path's field-id segment (5) doesn't match the field being validated (4).
        $files = array( array(
            'object_path'  => 'gravityforms/5/u1/a.jpg',
            'size'         => 100,
            'file_uuid'    => 'u1',
            'mime'         => 'image/jpeg',
            'original_name' => 'a.jpg',
            'uploaded_at'  => 'x',
        ) );
        $c = new FakeClient();
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'tampered_path', $err['code'] );
    }

    public function test_file_uuid_mismatch_is_rejected() {
        // Descriptor claims file_uuid=u1 but path uses u2 — descriptor was tampered to point at another file.
        $files = array( array(
            'object_path'  => 'gravityforms/4/u2/a.jpg',
            'size'         => 100,
            'file_uuid'    => 'u1',
            'mime'         => 'image/jpeg',
            'original_name' => 'a.jpg',
            'uploaded_at'  => 'x',
        ) );
        $c = new FakeClient();
        $err = \GFGCS_Validator::verify_field( $files, 'b', 'gravityforms/', $c, false, 4 );
        $this->assertSame( 'tampered_path', $err['code'] );
    }
}
