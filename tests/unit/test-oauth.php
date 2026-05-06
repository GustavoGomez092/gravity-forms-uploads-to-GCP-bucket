<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-oauth.php';

class OAuthTest extends TestCase {
    private $sa;

    protected function setUp(): void {
        $this->sa = json_decode( file_get_contents( __DIR__ . '/../fixtures/test-sa.json' ), true );
    }

    public function test_jwt_assertion_has_three_segments() {
        $oauth = new \GFGCS_OAuth( $this->sa, 1715000000 );
        $jwt   = $oauth->build_assertion();
        $this->assertCount( 3, explode( '.', $jwt ) );
    }

    public function test_jwt_payload_contains_required_claims() {
        $oauth   = new \GFGCS_OAuth( $this->sa, 1715000000 );
        $jwt     = $oauth->build_assertion();
        list( , $payload_b64 ) = explode( '.', $jwt );
        $payload = json_decode( base64_decode( strtr( $payload_b64, '-_', '+/' ) ), true );
        $this->assertSame( $this->sa['client_email'], $payload['iss'] );
        $this->assertSame( 'https://www.googleapis.com/oauth2/v4/token', $payload['aud'] );
        $this->assertSame( 'https://www.googleapis.com/auth/devstorage.read_write', $payload['scope'] );
        $this->assertSame( 1715000000, $payload['iat'] );
        $this->assertSame( 1715003600, $payload['exp'] );
    }

    public function test_jwt_signature_verifies_against_public_key() {
        $oauth     = new \GFGCS_OAuth( $this->sa, 1715000000 );
        $jwt       = $oauth->build_assertion();
        list( $h, $p, $s ) = explode( '.', $jwt );
        $signing_input = $h . '.' . $p;
        $sig           = base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
        $pubkey        = openssl_pkey_get_details( openssl_pkey_get_private( $this->sa['private_key'] ) )['key'];
        $this->assertSame( 1, openssl_verify( $signing_input, $sig, $pubkey, OPENSSL_ALGO_SHA256 ) );
    }
}
