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
