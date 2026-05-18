<?php
namespace GFGCS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-merge-tags.php';

class MergeTagsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        if ( ! function_exists( 'rest_url' ) ) {
            Functions\when( 'rest_url' )->returnArg();
        }
        if ( ! function_exists( 'esc_url' ) ) {
            Functions\when( 'esc_url' )->returnArg();
        }
        if ( ! function_exists( 'esc_html' ) ) {
            Functions\when( 'esc_html' )->alias( function ( $value ) {
                return htmlspecialchars( $value );
            } );
        }
    }
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_render_field_value_returns_newline_separated_urls() {
        $files = array(
            array( 'object_path' => 'p/4/u/a.jpg' ),
            array( 'object_path' => 'p/4/u/b.jpg' ),
        );
        $out = \GFGCS_Merge_Tags::render( $files, 42, 4, 'urls', 'sec' );
        $this->assertStringContainsString( "gfgcs/v1/f/42/4/0/", $out );
        $this->assertStringContainsString( "gfgcs/v1/f/42/4/1/", $out );
        $this->assertSame( 1, substr_count( $out, "\n" ) );
    }

    public function test_render_field_value_json_modifier_returns_json_array() {
        $files = array( array( 'object_path' => 'p/4/u/a.jpg' ) );
        $out = \GFGCS_Merge_Tags::render( $files, 42, 4, 'json', 'sec' );
        $decoded = json_decode( $out, true );
        $this->assertIsArray( $decoded );
        $this->assertCount( 1, $decoded );
        $this->assertStringContainsString( 'gfgcs/v1/f/42/4/0/', $decoded[0] );
    }

    public function test_render_empty_field_returns_empty_string() {
        $this->assertSame( '', \GFGCS_Merge_Tags::render( array(), 42, 4, 'urls', 'sec' ) );
        $this->assertSame( '', \GFGCS_Merge_Tags::render( array(), 42, 4, 'json', 'sec' ) );
    }

    public function test_render_for_html_format_returns_links() {
        $files = array( array(
            'object_path'   => 'p/4/u/a.jpg',
            'original_name' => 'a.jpg',
        ) );

        $out = \GFGCS_Merge_Tags::render_for_format( $files, 42, 4, '', 'html', 'sec' );

        $this->assertStringContainsString( 'gfgcs/v1/f/42/4/0/', $out );
        $this->assertStringContainsString( 'target="_blank"', $out );
        $this->assertStringContainsString( 'rel="noopener"', $out );
        $this->assertStringContainsString( '>a.jpg</a>', $out );
    }

    public function test_filter_webhook_request_data_rewrites_object_path_with_proxy_url_keeping_other_keys() {
        if ( ! function_exists( 'wp_json_encode' ) ) {
            Functions\when( 'wp_json_encode' )->alias( function ( $v ) { return json_encode( $v ); } );
        }
        Functions\expect( 'get_option' )->with( 'gfgcs_signing_secret', '' )->andReturn( 'sec' );

        $field = (object) array( 'id' => 52, 'type' => 'gcs_upload' );
        $form  = array( 'fields' => array( $field ) );
        $entry = array(
            'id' => 99,
            '52' => json_encode( array(
                array( 'object_path' => 'p/52/u/a.png', 'original_name' => 'a.png', 'size' => 1, 'mime' => 'image/png', 'file_uuid' => 'u-a' ),
                array( 'object_path' => 'p/52/u/b.png', 'original_name' => 'b.png', 'size' => 2, 'mime' => 'image/png', 'file_uuid' => 'u-b' ),
            ) ),
        );

        $out = \GFGCS_Merge_Tags::filter_webhook_request_data( $entry, array(), $entry, $form );

        $this->assertArrayHasKey( '52', $out );
        $decoded = json_decode( $out['52'], true );
        $this->assertIsArray( $decoded );
        $this->assertCount( 2, $decoded );

        // object_path replaced with proxy URL.
        $this->assertStringContainsString( 'gfgcs/v1/f/99/52/0/', $decoded[0]['object_path'] );
        $this->assertStringContainsString( 'gfgcs/v1/f/99/52/1/', $decoded[1]['object_path'] );

        // Other keys preserved.
        $this->assertSame( 'a.png',      $decoded[0]['original_name'] );
        $this->assertSame( 1,            $decoded[0]['size'] );
        $this->assertSame( 'image/png',  $decoded[0]['mime'] );
        $this->assertSame( 'u-a',        $decoded[0]['file_uuid'] );
        $this->assertSame( 'b.png',      $decoded[1]['original_name'] );
        $this->assertSame( 'u-b',        $decoded[1]['file_uuid'] );
    }

    public function test_filter_webhook_request_data_leaves_non_gcs_fields_alone() {
        Functions\when( 'get_option' )->justReturn( 'sec' );

        $field = (object) array( 'id' => 7, 'type' => 'text' );
        $form  = array( 'fields' => array( $field ) );
        $entry = array( 'id' => 99, '7' => 'hello' );

        $out = \GFGCS_Merge_Tags::filter_webhook_request_data( $entry, array(), $entry, $form );

        $this->assertSame( 'hello', $out['7'] );
    }

    public function test_filter_webhook_request_data_select_fields_mode_maps_custom_key_to_field_id() {
        if ( ! function_exists( 'wp_json_encode' ) ) {
            Functions\when( 'wp_json_encode' )->alias( function ( $v ) { return json_encode( $v ); } );
        }
        Functions\when( 'get_option' )->justReturn( 'sec' );

        $field = (object) array( 'id' => 53, 'type' => 'gcs_upload' );
        $form  = array( 'fields' => array( $field ) );
        $entry = array( 'id' => 7 );
        $descriptor = json_encode( array(
            array( 'object_path' => 'p/53/u/a.png', 'original_name' => 'a.png' ),
        ) );
        $request_data = array(
            'ClaimantType' => 'Customer',
            'UploadedFiles' => $descriptor,
        );
        $feed = array(
            'meta' => array(
                'requestBodyType' => 'select_fields',
                'fieldValues' => array(
                    array( 'key' => 'gf_custom', 'custom_key' => 'ClaimantType', 'value' => '45' ),
                    array( 'key' => 'gf_custom', 'custom_key' => 'UploadedFiles', 'value' => '53' ),
                ),
            ),
        );

        $out = \GFGCS_Merge_Tags::filter_webhook_request_data( $request_data, $feed, $entry, $form );

        $this->assertSame( 'Customer', $out['ClaimantType'] );
        $decoded = json_decode( $out['UploadedFiles'], true );
        $this->assertIsArray( $decoded );
        $this->assertCount( 1, $decoded );
        $this->assertStringContainsString( 'gfgcs/v1/f/7/53/0/', $decoded[0]['object_path'] );
        $this->assertSame( 'a.png', $decoded[0]['original_name'] );
    }

    public function test_filter_webhook_request_data_skips_when_value_not_descriptor_json() {
        Functions\when( 'get_option' )->justReturn( 'sec' );

        $field = (object) array( 'id' => 52, 'type' => 'gcs_upload' );
        $form  = array( 'fields' => array( $field ) );
        // Already substituted to URLs (e.g. via select-fields mode that ran replace_variables) — leave it.
        $entry = array( 'id' => 99, '52' => 'https://example.test/?gfgcs_dl=abc&k=xyz' );

        $out = \GFGCS_Merge_Tags::filter_webhook_request_data( $entry, array(), $entry, $form );

        $this->assertSame( 'https://example.test/?gfgcs_dl=abc&k=xyz', $out['52'] );
    }

    public function test_render_metadata_modifiers() {
        $files = array( array(
            'object_path'   => 'p/4/u/a.jpg',
            'original_name' => 'a.jpg',
            'size'          => 123,
            'mime'          => 'image/jpeg',
        ) );

        $this->assertSame( 'a.jpg', \GFGCS_Merge_Tags::render( $files, 42, 4, 'filename', 'sec' ) );
        $this->assertSame( '123', \GFGCS_Merge_Tags::render( $files, 42, 4, 'size', 'sec' ) );
        $this->assertSame( 'image/jpeg', \GFGCS_Merge_Tags::render( $files, 42, 4, 'mime', 'sec' ) );
        $this->assertSame( 'p/4/u/a.jpg', \GFGCS_Merge_Tags::render( $files, 42, 4, 'key', 'sec' ) );
    }
}
