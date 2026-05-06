<?php
namespace GFGCS\Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class UploadFlowTest extends TestCase {
    private $sa;
    private $bucket;

    protected function setUp(): void {
        gfgcs_it_skip_unless_configured( $this );
        $this->sa     = json_decode( getenv( 'GFGCS_IT_SA_JSON' ), true );
        $this->bucket = getenv( 'GFGCS_IT_BUCKET' );
    }

    public function test_signed_put_then_objects_get_returns_metadata() {
        $signer = new \GFGCS_Signer( $this->sa );
        $object = 'it/' . uniqid( '', true ) . '/hello.txt';
        $put    = $signer->sign_url( 'PUT', $this->bucket, $object, 600 );
        $body   = 'hello world';
        $r      = wp_remote_request( $put, array( 'method' => 'PUT', 'body' => $body, 'timeout' => 30 ) );
        $this->assertSame( 200, wp_remote_retrieve_response_code( $r ), 'PUT failed: ' . wp_remote_retrieve_body( $r ) );

        $client = new \GFGCS_GCS_Client( new \GFGCS_OAuth( $this->sa ) );
        $meta   = $client->object_metadata( $this->bucket, $object );
        $this->assertNotNull( $meta );
        $this->assertSame( strlen( $body ), $meta['size'] );

        $this->assertTrue( $client->delete_object( $this->bucket, $object ) );
        $this->assertNull( $client->object_metadata( $this->bucket, $object ) );
    }

    public function test_signed_get_returns_object_bytes() {
        $signer = new \GFGCS_Signer( $this->sa );
        $client = new \GFGCS_GCS_Client( new \GFGCS_OAuth( $this->sa ) );
        $object = 'it/' . uniqid( '', true ) . '/get.txt';

        $put = $signer->sign_url( 'PUT', $this->bucket, $object, 600 );
        wp_remote_request( $put, array( 'method' => 'PUT', 'body' => 'GET-test', 'timeout' => 30 ) );

        $get = $signer->sign_url( 'GET', $this->bucket, $object, 600 );
        $r   = wp_remote_get( $get );
        $this->assertSame( 200, wp_remote_retrieve_response_code( $r ) );
        $this->assertSame( 'GET-test', wp_remote_retrieve_body( $r ) );

        $client->delete_object( $this->bucket, $object );
    }

    public function test_resumable_session_start_returns_location() {
        $signer = new \GFGCS_Signer( $this->sa );
        $object = 'it/' . uniqid( '', true ) . '/resume.bin';
        $url    = $signer->sign_resumable_init_url( $this->bucket, $object, 600 );
        $r      = wp_remote_post( $url, array(
            'headers' => array( 'x-goog-resumable' => 'start', 'Content-Length' => '0' ),
            'body'    => '',
            'timeout' => 30,
        ) );
        $this->assertSame( 201, wp_remote_retrieve_response_code( $r ) );
        $loc = wp_remote_retrieve_header( $r, 'location' );
        $this->assertNotEmpty( $loc );
        wp_remote_request( $loc, array( 'method' => 'DELETE', 'timeout' => 10 ) );
    }
}
