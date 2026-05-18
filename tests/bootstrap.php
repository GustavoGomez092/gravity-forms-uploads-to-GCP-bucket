<?php
// Patchwork MUST be loaded before composer's autoloader. PHPUnit discovers test
// classes from BOTH the unit and integration testsuites at startup, and the
// integration test file `tests/integration/test-upload-flow.php` has a
// top-level `require_once .../integration/bootstrap.php` that declares real
// `wp_remote_get` / `wp_remote_post` / `wp_remote_request` shims. Without
// Patchwork's stream wrapper already installed, those declarations escape
// Patchwork's source preprocessor — and any later `Functions\when('wp_remote_*')`
// in unit tests throws `Patchwork\Exceptions\DefinedTooEarly`. Loading
// Patchwork.php here puts the stream wrapper in place before any test class
// (and therefore the integration bootstrap) is autoloaded.
require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';
require_once __DIR__ . '/../vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'GFGCS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'GFGCS_PLUGIN_URL', 'https://example.test/wp-content/plugins/gf-gcs-uploads/' );
define( 'GFGCS_VERSION', '0.1.0-test' );
define( 'AUTH_KEY', 'test-unit-auth-key-for-hkdf-derivation' );

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $k ) { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $k, $v, $ttl ) { return true; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults ) {
        return array_merge( $defaults, (array) $args );
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $k ) {
        $GLOBALS['_test_deleted_transients'][] = $k;
        return true;
    }
}
