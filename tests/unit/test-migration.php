<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-migration.php';

class MigrationTest extends TestCase {

    public function test_map_mimes_translates_known_pairs() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/jpeg, application/pdf' );
        $this->assertSame( 'jpg,jpeg,pdf', $exts );
        $this->assertSame( array(), $unmapped );
    }

    public function test_map_mimes_handles_image_star_glob() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/*' );
        $this->assertSame( 'jpg,jpeg,png,gif,webp,bmp,svg', $exts );
        $this->assertSame( array(), $unmapped );
    }

    public function test_map_mimes_collects_unmappable_entries() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/jpeg, application/x-custom' );
        $this->assertSame( 'jpg,jpeg', $exts );
        $this->assertSame( array( 'application/x-custom' ), $unmapped );
    }

    public function test_map_mimes_deduplicates_overlapping_extensions() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/jpeg, image/jpeg' );
        $this->assertSame( 'jpg,jpeg', $exts );
    }

    public function test_map_mimes_empty_input_returns_empty_output() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( '' );
        $this->assertSame( '', $exts );
        $this->assertSame( array(), $unmapped );
    }

    public function test_map_mimes_normalizes_uppercase_input() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'IMAGE/JPEG, APPLICATION/PDF' );
        $this->assertSame( 'jpg,jpeg,pdf', $exts );
        $this->assertSame( array(), $unmapped );
    }

    public function test_map_mimes_skips_empty_entries_in_csv() {
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/jpeg, , application/pdf' );
        $this->assertSame( 'jpg,jpeg,pdf', $exts );
        $this->assertSame( array(), $unmapped );
    }

    public function test_map_mimes_treats_mime_parameters_as_unmappable() {
        // MIME params like "; charset=binary" aren't stripped — the entry lands in unmapped.
        // Admin-configured allowedMimes values do not include parameters in practice; this test
        // simply documents the current behavior so it isn't silently changed later.
        list( $exts, $unmapped ) = \GFGCS_Migration::map_mimes_to_exts( 'image/jpeg; charset=binary' );
        $this->assertSame( '', $exts );
        $this->assertSame( array( 'image/jpeg; charset=binary' ), $unmapped );
    }
}
