<?php
namespace GFGCS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-oauth.php';

class OAuthErrorsTest extends TestCase {
    private $sa;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->sa = json_decode( file_get_contents( __DIR__ . '/../fixtures/test-sa.json' ), true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_500_classified_as_transient() {
        Functions\when( 'wp_remote_post' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

        $oauth = new \GFGCS_OAuth( $this->sa );
        try {
            $oauth->get_access_token();
            $this->fail( 'Expected exception' );
        } catch ( \GFGCS_OAuth_Exception $e ) {
            $this->assertSame( 'transient', $e->kind );
        }
    }

    public function test_invalid_grant_classified_as_permanent() {
        Functions\when( 'wp_remote_post' )->justReturn( array() );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 400 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"invalid_grant","error_description":"Invalid JWT"}' );

        $oauth = new \GFGCS_OAuth( $this->sa );
        try {
            $oauth->get_access_token();
            $this->fail( 'Expected exception' );
        } catch ( \GFGCS_OAuth_Exception $e ) {
            $this->assertSame( 'permanent', $e->kind );
            $this->assertSame( 'invalid_grant', $e->error_code );
            $this->assertStringContainsString( 'clock skew', $e->getMessage() );
        }
    }
}
