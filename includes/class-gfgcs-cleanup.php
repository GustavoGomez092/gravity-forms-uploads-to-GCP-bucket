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
		if ( ! is_array( $cfg['sa'] ) || ! $cfg['default_bucket'] ) {
			return;
		}

		$client    = new GFGCS_GCS_Client( new GFGCS_OAuth( $cfg['sa'] ) );
		$deleted   = 0;
		$checked   = 0;
		try {
			$items = $client->list_objects( $cfg['default_bucket'], rtrim( $cfg['default_prefix'], '/' ) . '/', 1000 );
		} catch ( \Throwable $e ) {
			update_option( 'gfgcs_cleanup_last_error', $e->getMessage(), false );
			return;
		}
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $items as $obj ) {
			$checked++;
			$name    = $obj['name'] ?? '';
			$created = isset( $obj['timeCreated'] ) ? strtotime( $obj['timeCreated'] ) : time();
			if ( $created > $cutoff ) {
				continue;
			}
			if ( self::is_referenced_in_entries( $name ) ) {
				continue;
			}
			if ( $client->delete_object( $cfg['default_bucket'], $name ) ) {
				$deleted++;
			}
		}
		update_option( 'gfgcs_cleanup_last_run', array(
			'time'    => time(),
			'checked' => $checked,
			'deleted' => $deleted,
		), false );
	}

	private static function is_referenced_in_entries( $object_path ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			return true;
		}
		$search = array(
			'field_filters' => array(
				'mode' => 'any',
				array( 'key' => 'meta', 'operator' => 'contains', 'value' => $object_path ),
			),
		);
		$count = \GFAPI::count_entries( null, $search );
		return $count > 0;
	}
}
