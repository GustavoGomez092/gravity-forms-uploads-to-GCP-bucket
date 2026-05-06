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
        Functions\when( 'rest_url' )->returnArg();
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
}
