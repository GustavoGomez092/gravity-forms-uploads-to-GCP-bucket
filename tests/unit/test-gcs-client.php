<?php
namespace GFGCS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-gcs-client.php';

class FakeOAuth {
    public function get_access_token() { return 'tkn-abc'; }
}

class GcsClientTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_object_metadata_returns_size_and_content_type() {
        Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( wp_json_encode( array(
            'size' => '4321',
            'contentType' => 'video/quicktime',
            'name' => 'a.mov',
        ) ) );

        $c = new \GFGCS_GCS_Client( new FakeOAuth() );
        $meta = $c->object_metadata( 'b', 'a.mov' );
        $this->assertSame( 4321, $meta['size'] );
        $this->assertSame( 'video/quicktime', $meta['content_type'] );
    }

    public function test_object_metadata_returns_null_on_404() {
        Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 404 ) ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 404 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

        $c = new \GFGCS_GCS_Client( new FakeOAuth() );
        $this->assertNull( $c->object_metadata( 'b', 'missing' ) );
    }

    public function test_delete_object_returns_true_on_204() {
        Functions\when( 'wp_remote_request' )->justReturn( array( 'response' => array( 'code' => 204 ) ) );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 204 );

        $c = new \GFGCS_GCS_Client( new FakeOAuth() );
        $this->assertTrue( $c->delete_object( 'b', 'a.mov' ) );
    }
}
