<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-signer.php';

class SignerTest extends TestCase {
    private $sa;

    protected function setUp(): void {
        $this->sa = json_decode( file_get_contents( __DIR__ . '/../fixtures/test-sa.json' ), true );
    }

    public function test_sign_get_url_produces_storage_googleapis_host() {
        $signer = new \GFGCS_Signer( $this->sa );
        $url = $signer->sign_url( 'GET', 'my-bucket', 'path/to/object.mov', 900 );
        $this->assertStringStartsWith( 'https://storage.googleapis.com/my-bucket/path/to/object.mov?', $url );
    }

    public function test_sign_get_url_includes_required_query_params() {
        $signer = new \GFGCS_Signer( $this->sa );
        $url = $signer->sign_url( 'GET', 'my-bucket', 'a.txt', 900 );
        parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
        $this->assertSame( 'GOOG4-RSA-SHA256', $q['X-Goog-Algorithm'] );
        $this->assertSame( 'host', $q['X-Goog-SignedHeaders'] );
        $this->assertSame( '900', $q['X-Goog-Expires'] );
        $this->assertMatchesRegularExpression( '/^\d{8}T\d{6}Z$/', $q['X-Goog-Date'] );
        $this->assertNotEmpty( $q['X-Goog-Signature'] );
        $this->assertStringContainsString( '/auto/storage/goog4_request', $q['X-Goog-Credential'] );
    }

    public function test_sign_url_percent_encodes_special_chars_in_object_name() {
        $signer = new \GFGCS_Signer( $this->sa );
        $url = $signer->sign_url( 'GET', 'my-bucket', 'folder/spaces and+plus#hash.mov', 900 );
        $this->assertStringContainsString( '/folder/spaces%20and%2Bplus%23hash.mov?', $url );
    }

    public function test_sign_url_handles_unicode_filenames() {
        $signer = new \GFGCS_Signer( $this->sa );
        $url = $signer->sign_url( 'GET', 'my-bucket', 'foto-niño.jpg', 900 );
        $this->assertStringContainsString( '/foto-ni%C3%B1o.jpg?', $url );
    }

    public function test_signature_is_deterministic_for_fixed_clock() {
        $signer = new \GFGCS_Signer( $this->sa, 1715000000 );
        $url1   = $signer->sign_url( 'GET', 'b', 'o', 900 );
        $url2   = $signer->sign_url( 'GET', 'b', 'o', 900 );
        $this->assertSame( $url1, $url2 );
    }

    public function test_different_methods_produce_different_signatures() {
        $signer = new \GFGCS_Signer( $this->sa, 1715000000 );
        $get    = $signer->sign_url( 'GET', 'b', 'o', 900 );
        $put    = $signer->sign_url( 'PUT', 'b', 'o', 900 );
        $this->assertNotSame( $get, $put );
    }

    public function test_sign_resumable_init_url_includes_x_goog_resumable_in_signed_headers() {
        $signer = new \GFGCS_Signer( $this->sa );
        $url    = $signer->sign_resumable_init_url( 'my-bucket', 'a.mov', 3600 );
        parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
        $this->assertStringContainsString( 'x-goog-resumable', $q['X-Goog-SignedHeaders'] );
        $this->assertStringContainsString( 'host', $q['X-Goog-SignedHeaders'] );
    }
}
