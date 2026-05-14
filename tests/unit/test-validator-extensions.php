<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';
require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-validator.php';

class ValidatorExtensionsTest extends TestCase {

    public function test_verify_field_rejects_extension_not_in_allow_list() {
        $client = new class {
            public function object_metadata( $bucket, $path ) { return array( 'size' => 100 ); }
        };
        $files = array(
            array(
                'object_path'   => 'p/5/file-uuid/photo.exe',
                'size'          => 100,
                'file_uuid'     => 'file-uuid',
                'original_name' => 'photo.exe',
            ),
        );
        $err = \GFGCS_Validator::verify_field(
            $files, 'b', 'p', $client, false, 5,
            array( 'jpg', 'png' ),       // allowed extensions
            array( 'php', 'phtml' )       // disallowed
        );
        $this->assertNotNull( $err );
        $this->assertSame( 'extension_not_allowed', $err['code'] );
    }

    public function test_verify_field_rejects_disallowed_extension_even_if_in_allow_list() {
        $client = new class {
            public function object_metadata( $bucket, $path ) { return array( 'size' => 100 ); }
        };
        $files = array(
            array(
                'object_path'   => 'p/5/file-uuid/shell.php',
                'size'          => 100,
                'file_uuid'     => 'file-uuid',
                'original_name' => 'shell.php',
            ),
        );
        $err = \GFGCS_Validator::verify_field(
            $files, 'b', 'p', $client, false, 5,
            array( 'php' ),               // admin foolishly allows php
            array( 'php', 'phtml' )       // disallowed list still applies
        );
        $this->assertNotNull( $err );
        $this->assertSame( 'extension_not_allowed', $err['code'] );
    }

    public function test_verify_field_passes_when_extension_is_ok() {
        $client = new class {
            public function object_metadata( $bucket, $path ) { return array( 'size' => 100 ); }
        };
        $files = array(
            array(
                'object_path'   => 'p/5/file-uuid/photo.jpg',
                'size'          => 100,
                'file_uuid'     => 'file-uuid',
                'original_name' => 'photo.jpg',
            ),
        );
        $err = \GFGCS_Validator::verify_field(
            $files, 'b', 'p', $client, false, 5,
            array( 'jpg' ), array( 'php' )
        );
        $this->assertNull( $err );
    }
}
