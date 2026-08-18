<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Shared daily heartbeat. Modules attach time-based work (e.g. points expiry) to the
 * `moksafopoi_daily` action instead of each scheduling its own event.
 */
final class Cron {

	private const HOOK = 'moksafopoi_daily';

	public static function register(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function clear(): void {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}
}
