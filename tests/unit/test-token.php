<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-proxy.php';

class TokenTest extends TestCase {
    public function test_valid_token_verifies() {
        $t = \GFGCS_Proxy::token( 42, 4, 0, 'secret' );
        $this->assertTrue( \GFGCS_Proxy::verify_token( $t, 42, 4, 0, 'secret' ) );
    }

    public function test_token_for_other_index_is_rejected() {
        $t = \GFGCS_Proxy::token( 42, 4, 0, 'secret' );
        $this->assertFalse( \GFGCS_Proxy::verify_token( $t, 42, 4, 1, 'secret' ) );
    }

    public function test_token_after_secret_rotation_is_rejected() {
        $t = \GFGCS_Proxy::token( 42, 4, 0, 'old' );
        $this->assertFalse( \GFGCS_Proxy::verify_token( $t, 42, 4, 0, 'new' ) );
    }

    public function test_malformed_token_does_not_throw() {
        $this->assertFalse( \GFGCS_Proxy::verify_token( '!!!not-base64!!!', 42, 4, 0, 'secret' ) );
        $this->assertFalse( \GFGCS_Proxy::verify_token( '', 42, 4, 0, 'secret' ) );
    }

    public function test_token_is_24_chars_url_safe() {
        $t = \GFGCS_Proxy::token( 42, 4, 0, 'secret' );
        $this->assertSame( 24, strlen( $t ) );
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $t );
    }

    public function test_content_disposition_strips_control_chars() {
        $rm = new \ReflectionMethod( '\GFGCS_Proxy', 'content_disposition' );
        $rm->setAccessible( true );
        $out = $rm->invoke( null, "evil\r\nset-cookie: x=y\r\n.jpg" );
        $this->assertStringNotContainsString( "\r", $out );
        $this->assertStringNotContainsString( "\n", $out );
        $this->assertStringStartsWith( 'inline; filename="', $out );
    }

    public function test_content_disposition_handles_unicode_via_rfc5987() {
        $rm = new \ReflectionMethod( '\GFGCS_Proxy', 'content_disposition' );
        $rm->setAccessible( true );
        $out = $rm->invoke( null, 'foto-niño.jpg' );
        // ASCII fallback substitutes non-ASCII with _
        $this->assertStringContainsString( 'filename="foto-ni__o.jpg"', $out );
        // RFC 5987 UTF-8 form preserves the original via percent-encoding
        $this->assertStringContainsString( "filename*=UTF-8''foto-ni%C3%B1o.jpg", $out );
    }

    public function test_content_disposition_escapes_quotes() {
        $rm = new \ReflectionMethod( '\GFGCS_Proxy', 'content_disposition' );
        $rm->setAccessible( true );
        $out = $rm->invoke( null, 'a"b.jpg' );
        $this->assertStringContainsString( 'filename="a\\"b.jpg"', $out );
    }

    public function test_content_disposition_empty_filename_falls_back() {
        $rm = new \ReflectionMethod( '\GFGCS_Proxy', 'content_disposition' );
        $rm->setAccessible( true );
        $out = $rm->invoke( null, '' );
        $this->assertStringContainsString( 'filename="file"', $out );
    }
}
