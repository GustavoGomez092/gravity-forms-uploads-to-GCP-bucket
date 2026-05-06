<?php
namespace GFGCS\Tests\Unit;

use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase {
    public function test_plugin_constants_defined() {
        $this->assertTrue( defined( 'GFGCS_PLUGIN_DIR' ) );
        $this->assertTrue( defined( 'GFGCS_VERSION' ) );
    }
}
