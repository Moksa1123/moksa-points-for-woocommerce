<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 賺點頻率節流 — the safety valve every repeatable (non-purchase) earn action must pass before it books
 * points, so a daily check-in / birthday / any future recurring bonus cannot be farmed. It counts how
 * many times a (user, source) has ALREADY earned within the current period straight from the value
 * {@see \Moksafopoi\Modules\Ledger\Ledger} — the single source of truth — so a refund /
 * reversal that removed a prior grant naturally re-opens the allowance, and there is no parallel
 * user-meta counter to drift.
 *
 * Period windows are UTC calendar buckets (day / week / month / year) matching the ledger's gmdate
 * timestamps. Purchase-based earning (SpendRules) is NOT throttled here — it is naturally bounded by
 * the order.
 */
final class EarnThrottle {

	/**
	 * Whether a (user, source) may earn again now: fewer than $max positive grants of that source exist
	 * in the current period. $max <= 0 means "unlimited" (always allowed). A per-lifetime cap uses
	 * period 'all'.
	 */
	public static function allow( int $user_id, string $source, int $max, string $period = 'day' ): bool {
		if ( $user_id <= 0 || '' === $source ) {
			return false;
		}
		if ( $max <= 0 ) {
			return true; // 0 / negative = no cap.
		}
		return self::count( $user_id, $source, $period ) < $max;
	}

	/**
	 * Positive-point grants of $source booked for $user within the current $period window.
	 */
	public static function count( int $user_id, string $source, string $period = 'day' ): int {
		global $wpdb;
		if ( $user_id <= 0 || '' === $source ) {
			return 0;
		}
		$table = \Moksafopoi\Support\Schema::ledger_table();
		$since = self::period_start( $period );

		if ( null === $since ) {
			// 'all' — lifetime count, no time bound.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND source = %s AND points_delta > 0",
					$user_id,
					$source
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND source = %s AND points_delta > 0 AND created_at >= %s",
				$user_id,
				$source,
				$since
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * The UTC start of the current period as a gmdate string, or null for 'all' (lifetime).
	 * Public so the per-period earn-total cap ({@see EarnCap}) reuses the exact same calendar
	 * buckets as the count-based throttle, and the two never drift.
	 */
	public static function period_start( string $period ): ?string {
		$now = time();
		switch ( $period ) {
			case 'all':
				return null;
			case 'week':
				// ISO-ish: start of the current 7-day window anchored on Monday (UTC).
				$dow   = (int) gmdate( 'N', $now ); // 1 (Mon) .. 7 (Sun)
				$start = $now - ( $dow - 1 ) * DAY_IN_SECONDS;
				return gmdate( 'Y-m-d 00:00:00', $start );
			case 'month':
				return gmdate( 'Y-m-01 00:00:00', $now );
			case 'year':
				return gmdate( 'Y-01-01 00:00:00', $now );
			case 'day':
			default:
				return gmdate( 'Y-m-d 00:00:00', $now );
		}
	}
}
