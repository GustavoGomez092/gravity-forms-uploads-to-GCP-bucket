<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-ajax.php';

class AbortEndpointTest extends TestCase {

    public function test_key_belongs_to_submission_accepts_matching_uuid_prefix() {
        $uuid = '12345678-1234-4567-89ab-cdef01234567';
        $this->assertTrue( \GFGCS_Ajax::key_belongs_to_submission(
            'complaints/2026/05/' . $uuid . '/4/file-uuid/photo.jpg',
            $uuid
        ) );
    }

    public function test_key_belongs_to_submission_rejects_other_uuid() {
        $this->assertFalse( \GFGCS_Ajax::key_belongs_to_submission(
            'complaints/2026/05/aaaaaaaa-1234-4567-89ab-cdef01234567/4/uuid/photo.jpg',
            'bbbbbbbb-1234-4567-89ab-cdef01234567'
        ) );
    }

    public function test_key_belongs_to_submission_rejects_empty_uuid() {
        $this->assertFalse( \GFGCS_Ajax::key_belongs_to_submission( 'any/key', '' ) );
    }

    public function test_key_belongs_to_submission_requires_trailing_slash_after_uuid() {
        // A key containing the UUID but NOT followed by '/' should be rejected — guards against UUID being a prefix of another UUID.
        $uuid = '12345678-1234-4567-89ab-cdef01234567';
        $this->assertFalse( \GFGCS_Ajax::key_belongs_to_submission(
            'pfx/' . $uuid . 'X/photo.jpg',  // UUID is followed by 'X', not '/'
            $uuid
        ) );
    }
}
