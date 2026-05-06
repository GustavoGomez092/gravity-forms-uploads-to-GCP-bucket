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
}
