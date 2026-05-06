<?php
defined( 'ABSPATH' ) || exit;

class GFGCS_Cleanup {
	const HOOK = 'gfgcs_cleanup_orphans';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK );
		}
	}

	public static function unregister() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
	}

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
			$page_token = null;
			do {
				try {
					$page = $client->list_objects( $t['bucket'], $t['prefix'], 1000, $page_token );
				} catch ( \Throwable $e ) {
					$errors[] = sprintf( '[%s/%s] %s', $t['bucket'], $t['prefix'], $e->getMessage() );
					break;
				}
				foreach ( $page['items'] as $obj ) {
					$checked++;
					$name    = $obj['name'] ?? '';
					$created = isset( $obj['timeCreated'] ) ? strtotime( $obj['timeCreated'] ) : time();
					if ( $created > $cutoff ) continue;
					if ( self::is_referenced_in_entries( $name ) ) continue;
					if ( $client->delete_object( $t['bucket'], $name ) ) {
						$deleted++;
					}
				}
				$page_token = $page['next_page_token'];
			} while ( ! empty( $page_token ) );
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

	/**
	 * Walk every form, resolve its effective bucket + literal-prefix portion,
	 * dedupe, and return the list to scan.
	 *
	 * @return array<int,array{bucket:string,prefix:string}>
	 */
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

		// Always include the global default — covers forms without a per-form override.
		$add( $cfg['default_bucket'] ?? '', $cfg['default_prefix'] ?? '' );

		if ( ! class_exists( 'GFAPI' ) ) {
			return $targets;
		}
		$forms = \GFAPI::get_forms( true ); // active only
		if ( ! is_array( $forms ) ) return $targets;

		foreach ( $forms as $form_summary ) {
			$form = \GFAPI::get_form( $form_summary['id'] );
			if ( ! $form ) continue;

			// Does this form have a gcs_upload field at all? If not, skip.
			$has_field = false;
			foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
				if ( isset( $f->type ) && $f->type === 'gcs_upload' ) { $has_field = true; break; }
			}
			if ( ! $has_field ) continue;

			// Resolve form-level overrides via the same gates the AJAX init uses.
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

	/**
	 * Strip the first {token} and everything after, leaving the static literal part of
	 * the prefix template that GCS object listing can match. Trailing slash preserved.
	 */
	private static function literal_prefix_portion( $template ) {
		$template = (string) $template;
		$brace = strpos( $template, '{' );
		if ( $brace === false ) {
			return $template; // no tokens; entire template is literal
		}
		$literal = substr( $template, 0, $brace );
		// If we cut mid-segment (no trailing slash), trim back to the previous slash.
		$last_slash = strrpos( $literal, '/' );
		if ( $last_slash === false ) {
			return ''; // template begins with a token at root — list whole bucket
		}
		return substr( $literal, 0, $last_slash + 1 );
	}

	private static function is_referenced_in_entries( $object_path ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return true; // fail-safe: never delete when GF isn't loaded
		}
		$forms = \GFAPI::get_forms( true ); // active forms only
		foreach ( $forms as $form_summary ) {
			$form = \GFAPI::get_form( $form_summary['id'] );
			if ( ! $form ) continue;
			foreach ( (array) ( $form['fields'] ?? array() ) as $f ) {
				if ( ! isset( $f->type ) || $f->type !== 'gcs_upload' ) continue;
				$count = \GFAPI::count_entries( (int) $form['id'], array(
					'field_filters' => array(
						array( 'key' => (string) $f->id, 'operator' => 'contains', 'value' => $object_path ),
					),
				) );
				if ( is_int( $count ) && $count > 0 ) return true;
			}
		}
		return false;
	}
}
