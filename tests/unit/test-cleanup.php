<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-cleanup.php';

class CleanupTest extends TestCase {

    /** Use reflection to invoke the private static helper. */
    private function literal( $template ) {
        $rm = new \ReflectionMethod( '\GFGCS_Cleanup', 'literal_prefix_portion' );
        $rm->setAccessible( true );
        return $rm->invoke( null, $template );
    }

    public function test_template_with_no_tokens_returns_unchanged() {
        $this->assertSame( 'gravityforms/', $this->literal( 'gravityforms/' ) );
    }

    public function test_template_with_token_at_end_keeps_literal_segments() {
        $this->assertSame( 'complaints/', $this->literal( 'complaints/{form_id}/' ) );
    }

    public function test_template_with_multiple_tokens_returns_first_literal_run() {
        $this->assertSame( 'complaints/', $this->literal( 'complaints/{Y}/{m}/{d}/' ) );
    }

    public function test_template_starting_with_token_returns_empty_string() {
        $this->assertSame( '', $this->literal( '{form_id}/uploads/' ) );
    }

    public function test_token_cut_mid_segment_trims_to_previous_slash() {
        // "uploads/form-{form_id}/" — cutting before "{" gives "uploads/form-",
        // which is mid-segment. Should trim back to "uploads/".
        $this->assertSame( 'uploads/', $this->literal( 'uploads/form-{form_id}/' ) );
    }

    public function test_empty_template_returns_empty() {
        $this->assertSame( '', $this->literal( '' ) );
    }
}
