<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Ledger;

use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Daily points-expiry sweep. Earn rows may carry an `expires_at`; when the configured
 * expiry window lapses, the still-unspent portion of those points is written back as a
 * negative `expire` row (idempotent per user+day), keeping the ledger the single truth.
 */
final class Expiry {

	public static function run(): void {
		$months = (int) get_option( 'moksafopoi_points_expire_months', 0 );
		if ( $months <= 0 ) {
			return; // Expiry disabled.
		}

		global $wpdb;
		$table = Schema::ledger_table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Users who hold earn rows that have passed their expires_at and still have a positive balance.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM {$table} WHERE expires_at IS NOT NULL AND expires_at > '1971-01-01 00:00:00' AND expires_at <= %s AND points_delta > 0",
				$now
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$day = gmdate( 'Y-m-d' );
		foreach ( array_map( 'intval', (array) $user_ids ) as $user_id ) {
			if ( Ledger::points( $user_id ) <= 0 ) {
				continue;
			}

			// True FIFO lot accounting: replay this user's ledger chronologically, building a queue of
			// earn "lots" (each with its own expires_at). Every debit — a spend, a prior expiry, a negative
			// adjustment — consumes from the OLDEST lots first. What is left in lots whose expires_at has
			// now passed is exactly the still-unspent expired portion. This never re-burns points that were
			// already spent or already expired (the old flat "sum of expired earn rows" over-burned when a
			// later non-expiring lot propped the balance up to the ceiling).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT points_delta, expires_at FROM {$table} WHERE user_id = %d ORDER BY created_at ASC, id ASC",
					$user_id
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$lots = array();
			foreach ( (array) $rows as $r ) {
				$delta = (int) $r['points_delta'];
				if ( $delta > 0 ) {
					$lots[] = array( 'remaining' => $delta, 'expires_at' => $r['expires_at'] );
				} elseif ( $delta < 0 ) {
					$need = -$delta;
					foreach ( $lots as $k => $lot ) {
						if ( $need <= 0 ) {
							break;
						}
						if ( $lots[ $k ]['remaining'] <= 0 ) {
							continue;
						}
						$take                     = min( (int) $lots[ $k ]['remaining'], $need );
						$lots[ $k ]['remaining'] -= $take;
						$need                    -= $take;
					}
				}
			}

			$burn = 0;
			foreach ( $lots as $lot ) {
				$exp = (string) ( $lot['expires_at'] ?? '' );
				if ( (int) $lot['remaining'] > 0 && '' !== $exp && $exp > '1971-01-01 00:00:00' && $exp <= $now ) {
					$burn += (int) $lot['remaining'];
				}
			}
			if ( $burn <= 0 ) {
				continue;
			}
			Ledger::record_once(
				$user_id,
				-$burn,
				'expire',
				'expiry',
				$day,
				array(
					'bucket' => $day,
					'note'   => __( 'Points expiry', 'moksa-points-for-woocommerce' ),
				)
			);
		}
	}
}
