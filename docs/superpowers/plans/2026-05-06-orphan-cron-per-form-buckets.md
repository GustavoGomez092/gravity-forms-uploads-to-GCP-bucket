# v0.1.1.3 — Orphan Cron Scans Per-Form Override Buckets

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `GFGCS_Cleanup::run()` so the daily cron visits every distinct `(bucket, prefix)` pair across all forms with a `gcs_upload` field, not just the global default.

**Architecture:** A new private `collect_scan_targets()` helper walks every active Gravity Form, resolves its effective bucket + prefix using the same override gates as `GFGCS_Ajax::effective_field_settings()`, deduplicates via a `bucket\0prefix` key, and returns an array of `{bucket, prefix}` targets. `run()` loops over each target, calling `list_objects` + `is_referenced_in_entries` + `delete_object`. A second helper `literal_prefix_portion()` strips dynamic `{token}` segments from a prefix template so only the static path prefix is sent to GCS listing.

**Tech Stack:** PHP 8.2, PHPUnit 9/10, Gravity Forms API (`GFAPI`), Brain\Monkey for WP function stubs.

---

## File Map

| Action | File |
|--------|------|
| Modify | `includes/class-gfgcs-cleanup.php` |
| Create | `tests/unit/test-cleanup.php` |

---

### Task 1: Write failing unit tests for `literal_prefix_portion`

**Files:**
- Create: `tests/unit/test-cleanup.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/unit/test-cleanup.php` with the exact content from the spec. These tests will fail because `literal_prefix_portion` doesn't exist yet.

```php
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
        $this->assertSame( 'uploads/', $this->literal( 'uploads/form-{form_id}/' ) );
    }

    public function test_empty_template_returns_empty() {
        $this->assertSame( '', $this->literal( '' ) );
    }
}
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
cd "/Users/gustavogomez/Local Sites/sscwpro/app/public/wp-content/plugins/gf-gcs-uploads"
'/Users/gustavogomez/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php' vendor/bin/phpunit --filter=CleanupTest
```

Expected: FAIL — `ReflectionException: Method GFGCS_Cleanup::literal_prefix_portion() does not exist`

---

### Task 2: Implement `literal_prefix_portion` and `collect_scan_targets` in `class-gfgcs-cleanup.php`

**Files:**
- Modify: `includes/class-gfgcs-cleanup.php`

- [ ] **Step 1: Replace `run()` body and add the two helpers**

The `run()` method currently calls `list_objects` once against the global bucket. Replace the entire body of `run()` and add the two private helpers after `is_referenced_in_entries`. The class file will grow from 78 lines to ~160 lines.

New `run()`:
```php
public static function run() {
    $cfg = GFGCS_Settings::get_global();
    if ( ! is_array( $cfg['sa'] ) ) return;

    $targets = self::collect_scan_targets( $cfg );
    if ( empty( $targets ) ) return;

    $client  = new GFGCS_GCS_Client( new GFGCS_OAuth( $cfg['sa'] ) );
    $deleted = 0;
    $checked = 0;
    $errors  = array();
    $cutoff  = time() - DAY_IN_SECONDS;

    foreach ( $targets as $t ) {
        try {
            $items = $client->list_objects( $t['bucket'], $t['prefix'], 1000 );
        } catch ( \Throwable $e ) {
            $errors[] = sprintf( '[%s/%s] %s', $t['bucket'], $t['prefix'], $e->getMessage() );
            continue;
        }
        foreach ( $items as $obj ) {
            $checked++;
            $name    = $obj['name'] ?? '';
            $created = isset( $obj['timeCreated'] ) ? strtotime( $obj['timeCreated'] ) : time();
            if ( $created > $cutoff ) continue;
            if ( self::is_referenced_in_entries( $name ) ) continue;
            if ( $client->delete_object( $t['bucket'], $name ) ) {
                $deleted++;
            }
        }
    }

    update_option( 'gfgcs_cleanup_last_run', array(
        'time'    => time(),
        'targets' => count( $targets ),
        'checked' => $checked,
        'deleted' => $deleted,
        'errors'  => $errors,
    ), false );
    if ( ! empty( $errors ) ) {
        update_option( 'gfgcs_cleanup_last_error', implode( '; ', $errors ), false );
    } else {
        delete_option( 'gfgcs_cleanup_last_error' );
    }
}
```

`collect_scan_targets()`:
```php
private static function collect_scan_targets( $cfg ) {
    $targets  = array();
    $key_seen = array();

    $add = function ( $bucket, $prefix_template ) use ( &$targets, &$key_seen ) {
        $bucket = (string) $bucket;
        if ( $bucket === '' ) return;
        $prefix = self::literal_prefix_portion( (string) $prefix_template );
        $k = $bucket . "\0" . $prefix;
        if ( isset( $key_seen[ $k ] ) ) return;
        $key_seen[ $k ] = true;
        $targets[] = array( 'bucket' => $bucket, 'prefix' => $prefix );
    };

    // Always include the global default.
    $add( $cfg['default_bucket'] ?? '', $cfg['default_prefix'] ?? '' );

    if ( ! class_exists( 'GFAPI' ) ) {
        return $targets;
    }
    $forms = \GFAPI::get_forms( true );
    if ( ! is_array( $forms ) ) return $targets;

    foreach ( $forms as $form_summary ) {
        $form = \GFAPI::get_form( $form_summary['id'] );
        if ( ! $form ) continue;

        $has_field = false;
        foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
            if ( isset( $f->type ) && $f->type === 'gcs_upload' ) { $has_field = true; break; }
        }
        if ( ! $has_field ) continue;

        $form_settings = method_exists( 'GFGCS_Addon', 'get_instance' ) ? ( GFGCS_Addon::get_instance()->get_form_settings( $form ) ?: array() ) : array();
        $bucket = ( ! empty( $form_settings['override_bucket'] ) && ! empty( $form_settings['bucket_override'] ) )
            ? $form_settings['bucket_override']
            : ( $cfg['default_bucket'] ?? '' );
        $prefix = ( ! empty( $form_settings['override_prefix'] ) && ! empty( $form_settings['prefix_override'] ) )
            ? $form_settings['prefix_override']
            : ( $cfg['default_prefix'] ?? '' );
        $add( $bucket, $prefix );
    }

    return $targets;
}
```

`literal_prefix_portion()`:
```php
private static function literal_prefix_portion( $template ) {
    $template = (string) $template;
    $brace = strpos( $template, '{' );
    if ( $brace === false ) {
        return $template;
    }
    $literal = substr( $template, 0, $brace );
    $last_slash = strrpos( $literal, '/' );
    if ( $last_slash === false ) {
        return '';
    }
    return substr( $literal, 0, $last_slash + 1 );
}
```

- [ ] **Step 2: Run CleanupTest to confirm all 6 tests pass**

```bash
'/Users/gustavogomez/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php' vendor/bin/phpunit --filter=CleanupTest
```

Expected: 6 tests, 6 assertions, OK.

- [ ] **Step 3: Run full unit suite to confirm no regressions**

```bash
'/Users/gustavogomez/Library/Application Support/Local/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php' vendor/bin/phpunit --testsuite=unit
```

Expected: 49 tests passing (43 existing + 6 new).

---

### Task 3: Commit

- [ ] **Step 1: Stage and commit**

```bash
git add includes/class-gfgcs-cleanup.php tests/unit/test-cleanup.php
git commit -m "fix(v0.1.1): orphan cleanup cron scans per-form override buckets"
```

Expected: commit created on branch `v0.1.1-dev`.
