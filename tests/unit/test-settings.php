<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once GFGCS_PLUGIN_DIR . 'includes/class-gfgcs-settings.php';

class SettingsTest extends TestCase {
    public function test_encrypt_then_decrypt_roundtrip() {
        $plain  = '{"type":"service_account","project_id":"x","private_key":"...."}';
        $cipher = \GFGCS_Settings::encrypt( $plain, 'test-salt-key' );
        $this->assertNotSame( $plain, $cipher );
        $this->assertSame( $plain, \GFGCS_Settings::decrypt( $cipher, 'test-salt-key' ) );
    }

    public function test_decrypt_with_wrong_key_returns_null() {
        $cipher = \GFGCS_Settings::encrypt( 'secret', 'key-a' );
        $this->assertNull( \GFGCS_Settings::decrypt( $cipher, 'key-b' ) );
    }

    public function test_redact_sa_for_display_returns_only_email_and_project() {
        $sa  = array(
            'type'         => 'service_account',
            'project_id'   => 'p1',
            'private_key'  => '-----BEGIN PRIVATE KEY----- ...',
            'client_email' => 'svc@p1.iam.gserviceaccount.com',
        );
        $out = \GFGCS_Settings::redact_sa_for_display( $sa );
        $this->assertSame( 'p1', $out['project_id'] );
        $this->assertSame( 'svc@p1.iam.gserviceaccount.com', $out['client_email'] );
        $this->assertArrayNotHasKey( 'private_key', $out );
    }

    public function test_expand_prefix_tokens_only_whitelisted_tokens() {
        $ctx = array(
            'form_id'         => 7,
            'form_title'      => 'Complaints',
            'submission_uuid' => 'abc-123',
            'Y' => '2026', 'm' => '05', 'd' => '06',
        );
        $this->assertSame( 'complaints/2026/05/abc-123/', \GFGCS_Settings::expand_prefix( 'complaints/{Y}/{m}/{submission_uuid}/', $ctx ) );
    }

    public function test_expand_prefix_ignores_unknown_tokens() {
        $ctx = array( 'form_id' => 7 );
        // {entry_email} isn't whitelisted — left literal.
        $this->assertSame( 'x/{entry_email}/7/', \GFGCS_Settings::expand_prefix( 'x/{entry_email}/{form_id}/', $ctx ) );
    }
}
