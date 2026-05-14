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

    public function test_migrate_forms_translates_per_field_allowed_mimes() {
        $field          = new \stdClass();
        $field->id      = 5;
        $field->type    = 'gcs_upload';
        $field->allowedMimes = 'image/jpeg, application/pdf';

        $forms = array(
            array( 'id' => 12, 'title' => 'Test', 'fields' => array( $field ) ),
        );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );

        $f = $migrated[0]['fields'][0];
        $this->assertSame( 'jpg,jpeg,pdf', $f->allowedExtensions );
        $this->assertFalse( property_exists( $f, 'allowedMimes' ) );
        $this->assertSame( array(), $warnings );
    }

    public function test_migrate_forms_records_unmappable_warnings() {
        $field          = new \stdClass();
        $field->id      = 5;
        $field->type    = 'gcs_upload';
        $field->allowedMimes = 'application/x-custom';

        $forms = array(
            array( 'id' => 12, 'title' => 'Test', 'fields' => array( $field ) ),
        );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );

        $this->assertSame( '', $migrated[0]['fields'][0]->allowedExtensions );
        $this->assertCount( 1, $warnings );
        $this->assertSame( 12, $warnings[0]['form_id'] );
        $this->assertSame( 5, $warnings[0]['field_id'] );
        $this->assertSame( 'application/x-custom', $warnings[0]['mimes_value'] );
    }

    public function test_migrate_forms_skips_non_gcs_fields() {
        $field         = new \stdClass();
        $field->id     = 5;
        $field->type   = 'fileupload';
        $field->allowedMimes = 'image/jpeg';

        $forms = array(
            array( 'id' => 12, 'title' => 'T', 'fields' => array( $field ) ),
        );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );
        $this->assertTrue( property_exists( $migrated[0]['fields'][0], 'allowedMimes' ) );
        $this->assertSame( array(), $warnings );
    }

    public function test_migrate_forms_strips_corrupt_inputtype_on_gcs_upload_field() {
        $field            = new \stdClass();
        $field->id        = 9;
        $field->type      = 'gcs_upload';
        $field->inputType = 'fileupload';
        $field->multipleFiles = true;

        $forms = array( array( 'id' => 1, 'title' => 'T', 'fields' => array( $field ) ) );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );
        $this->assertFalse( property_exists( $migrated[0]['fields'][0], 'inputType' ) );
        $this->assertTrue( $migrated[0]['fields'][0]->multipleFiles );
        $this->assertSame( array(), $warnings );
    }

    public function test_migrate_forms_leaves_inputtype_alone_on_non_gcs_field() {
        $field            = new \stdClass();
        $field->id        = 9;
        $field->type      = 'fileupload';
        $field->inputType = 'fileupload';

        $forms = array( array( 'id' => 1, 'title' => 'T', 'fields' => array( $field ) ) );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );
        $this->assertSame( 'fileupload', $migrated[0]['fields'][0]->inputType );
    }

    public function test_migrate_forms_is_idempotent_when_no_allowed_mimes() {
        $field       = new \stdClass();
        $field->id   = 5;
        $field->type = 'gcs_upload';
        $field->allowedExtensions = 'jpg,jpeg,pdf';

        $forms = array(
            array( 'id' => 12, 'title' => 'T', 'fields' => array( $field ) ),
        );

        list( $migrated, $warnings ) = \GFGCS_Migration::migrate_forms( $forms );
        $this->assertSame( 'jpg,jpeg,pdf', $migrated[0]['fields'][0]->allowedExtensions );
        $this->assertSame( array(), $warnings );
    }
}
