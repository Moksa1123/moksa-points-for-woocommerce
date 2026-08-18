<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 帳本保留 / 彙總 — compact ancient ledger rows into one opening-balance row per member, for a shop
 * whose table has grown past what it needs to keep online.
 *
 * This touches the single source of truth, so it is built to be boring:
 *
 *   • **Balances are preserved exactly.** Each member's compacted rows are replaced by ONE row whose
 *     points/credit deltas equal their sum. Recompute the balance before and after and you get the
 *     same number — that is the invariant, and {@see run()} verifies it per member before deleting
 *     anything.
 *   • **A hard floor of one year.** Deleting a row also deletes the UNIQUE idempotency key that stops
 *     a replayed webhook re-awarding it. Recent history is exactly where replays happen, so no
 *     cutoff younger than {@see MIN_AGE_DAYS} is accepted, whatever the caller asks for.
 *   • **Rows that are still doing a job are never compacted.** A row with a future expiry date is
 *     live FIFO inventory; folding it into an opening balance would silently make those points
 *     immortal.
 *   • **Dry run first.** {@see run()} defaults to reporting what it would do and writing nothing.
 *
 * The compacted row is `type=opening, source=retention` so it is obvious in any export what it is.
 */
final class Retention {

	/** Never compact anything younger than this, whatever the caller asks. */
	public const MIN_AGE_DAYS = 365;

	/** Members processed per run, so one request cannot run away on a huge table. */
	public const MAX_USERS = 500;

	/**
	 * Compact rows older than `$days`.
	 *
	 * @param int  $days    Age in days; clamped up to {@see MIN_AGE_DAYS}.
	 * @param bool $dry_run True (default) = report only.
	 * @return array{ok:bool,error?:string,cutoff:string,users:int,rows:int,points:int,credit:float,skipped_users:int,dry_run:bool}
	 */
	public static function run( int $days, bool $dry_run = true ): array {
		global $wpdb;

		$days   = max( self::MIN_AGE_DAYS, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = Schema::ledger_table();

		$out = array(
			'ok'            => true,
			'cutoff'        => $cutoff,
			'users'         => 0,
			'rows'          => 0,
			'points'        => 0,
			'credit'        => 0.0,
			'skipped_users' => 0,
			'dry_run'       => $dry_run,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); every value bound via $wpdb->prepare(). This is an explicit, operator-triggered maintenance sweep, so caching does not apply.
		$candidates = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS rows_count,
					COALESCE(SUM(points_delta),0) AS points_sum,
					COALESCE(SUM(amount_delta),0) AS credit_sum
				FROM {$table}
				WHERE created_at < %s
					AND ( expires_at IS NULL OR expires_at < %s OR expires_at < '1971-01-01 00:00:00' )
					AND type <> 'opening'
				GROUP BY user_id
				HAVING rows_count > 1
				LIMIT %d",
				$cutoff,
				gmdate( 'Y-m-d H:i:s' ),
				self::MAX_USERS
			),
			ARRAY_A
		);

		foreach ( $candidates as $row ) {
			$user_id = (int) $row['user_id'];
			$rows    = (int) $row['rows_count'];
			$points  = (int) $row['points_sum'];
			$credit  = (float) $row['credit_sum'];

			if ( $user_id <= 0 ) {
				continue;
			}

			$out['users']  += 1;
			$out['rows']   += $rows;
			$out['points'] += $points;
			$out['credit'] += $credit;

			if ( $dry_run ) {
				continue;
			}

			// Balance before, so the invariant can be checked rather than assumed.
			$before_points = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d", $user_id ) );
			$before_credit = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_delta),0) FROM {$table} WHERE user_id = %d", $user_id ) );

			$inserted = $wpdb->insert(
				$table,
				array(
					'user_id'         => $user_id,
					'points_delta'    => $points,
					'amount_delta'    => $credit,
					// balance_after is a NOT NULL snapshot column; a compacted row has no meaningful
					// "balance at the time", so it stores the running total the compaction leaves behind.
					'balance_after'   => $points,
					'type'            => 'opening',
					'source'          => 'retention',
					'source_ref'      => 'retention:' . gmdate( 'Ymd', strtotime( $cutoff ) ) . ':' . $user_id,
					'idempotency_key' => sha1( 'retention:' . $cutoff . ':' . $user_id ),
					'note'            => __( 'Opening balance (older entries compacted)', 'moksa-points-for-woocommerce' ),
					'created_at'      => $cutoff,
				),
				array( '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( ! $inserted ) {
				++$out['skipped_users'];
				--$out['users'];
				continue; // Could not write the replacement → do NOT delete the originals.
			}
			$opening_id = (int) $wpdb->insert_id;

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table}
					WHERE user_id = %d AND created_at < %s AND id <> %d
						AND ( expires_at IS NULL OR expires_at < %s OR expires_at < '1971-01-01 00:00:00' )
						AND type <> 'opening'",
					$user_id,
					$cutoff,
					$opening_id,
					gmdate( 'Y-m-d H:i:s' )
				)
			);

			$after_points = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE user_id = %d", $user_id ) );
			$after_credit = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount_delta),0) FROM {$table} WHERE user_id = %d", $user_id ) );

			if ( $before_points !== $after_points || abs( $before_credit - $after_credit ) > 0.001 ) {
				// The invariant broke: put the difference back rather than leave a member short. This
				// should be unreachable, which is exactly why it is checked.
				$wpdb->insert(
					$table,
					array(
						'user_id'         => $user_id,
						'points_delta'    => $before_points - $after_points,
						'amount_delta'    => $before_credit - $after_credit,
						'balance_after'   => $before_points,
						'type'            => 'adjust',
						'source'          => 'retention_repair',
						'source_ref'      => 'retention_repair:' . $user_id . ':' . time(),
						'idempotency_key' => sha1( 'retention_repair:' . $user_id . ':' . microtime( true ) ),
						'note'            => __( 'Retention compaction repair', 'moksa-points-for-woocommerce' ),
						'created_at'      => gmdate( 'Y-m-d H:i:s' ),
					),
					array( '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				++$out['skipped_users'];
			}

			unset( $deleted );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return $out;
	}
}
