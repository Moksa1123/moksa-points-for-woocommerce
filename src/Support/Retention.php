<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * 帳本保留 / 彙總 — compact ancient ledger rows into one opening-balance row per member, for a shop
 * whose table has grown past what it needs to keep online.
 *
 * This touches the single source of truth, so it is built to be boring:
 *
 *   • **Balances are preserved exactly.** Each member's compacted rows are replaced by ONE row whose
 *     points/credit deltas equal their sum. Recompute the balance before and after and you get the
 *     same number. {@see run()} sums one exact batch, writes the opening row, then deletes only that
 *     batch (ids up to its highest id), so rows written meanwhile are untouched.
 *   • **Redemption rows are kept.** Their refs carry per-member redemption limits and redeem-once codes.
 *   • **A hard floor of one year.** Deleting a row also deletes the UNIQUE idempotency key that stops
 *     a replayed webhook re-awarding it. Recent history is exactly where replays happen, so no
 *     cutoff younger than {@see MIN_AGE_DAYS} is accepted, whatever the caller asks for.
 *   • **Rows that are still doing a job are never compacted.** A row with a future expiry date is
 *     live FIFO inventory; folding it into an opening balance would silently make those points
 *     immortal. Rows other code reads back by reference are kept too: redemptions (per-member limits,
 *     redeem-once codes) and checkout-redemption refunds (how much of an order's spent points was
 *     already given back).
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
		$days   = max( self::MIN_AGE_DAYS, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

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

		$now        = gmdate( 'Y-m-d H:i:s' );
		$candidates = LedgerStore::retention_candidates( $cutoff, $now, self::MAX_USERS );

		// One compaction at a time (two overlapping runs could each sum and delete the same batch).
		if ( ! $dry_run ) {
			if ( ! class_exists( 'WP_Upgrader', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			}
			if ( ! \WP_Upgrader::create_lock( 'moksafopoi_retention', 15 * MINUTE_IN_SECONDS ) ) {
				$out['ok']    = false;
				$out['error'] = __( 'Another compaction is already running. Please try again in a few minutes.', 'moksa-points-for-woocommerce' );
				return $out;
			}
		}

		try {

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

				// Replace exactly ONE batch: sum it, write the opening row with that sum, then delete that same
				// batch (same conditions, ids up to its highest id). A spend or earn written by this member in
				// the meantime is newer than the cutoff and has a higher id, so it is neither summed nor deleted
				// — the balance cannot move. (The former "compare total balance before and after, repair the
				// difference" approach turned a concurrent spend into free points.)
				$batch = LedgerStore::retention_batch( $user_id, $cutoff, $now );
				if ( $batch['rows'] <= 1 ) {
					--$out['users'];
					continue;
				}

				$opening_id = LedgerStore::insert_row(
					array(
						'user_id'         => $user_id,
						'points_delta'    => $batch['p'],
						'amount_delta'    => $batch['c'],
						// balance_after is a NOT NULL snapshot column; a compacted row has no meaningful
						// "balance at the time", so it stores the total of the rows it replaces.
						'balance_after'   => $batch['p'],
						'type'            => 'opening',
						'source'          => 'retention',
						'source_ref'      => 'retention:' . gmdate( 'Ymd', strtotime( $cutoff ) ) . ':' . $user_id,
						'idempotency_key' => sha1( 'retention:' . $cutoff . ':' . $user_id ),
						'note'            => __( 'Opening balance (older entries compacted)', 'moksa-points-for-woocommerce' ),
						'created_at'      => $cutoff,
					),
					array( '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);

				if ( $opening_id <= 0 ) {
					++$out['skipped_users'];
					--$out['users'];
					continue; // Could not write the replacement → do NOT delete the originals.
				}

				$deleted = LedgerStore::delete_compacted( $user_id, $cutoff, $now, $batch['max_id'] );
				if ( $deleted !== $batch['rows'] ) {
					// Cannot happen while the run lock is held; recorded rather than "repaired" with guessed points.
					++$out['skipped_users'];
					$out['mismatch'][] = array(
						'user_id' => $user_id,
						'summed'  => $batch['rows'],
						'deleted' => $deleted,
					);
				}
				Ledger::recompute( $user_id );
			}
		} finally {
			if ( ! $dry_run ) {
				\WP_Upgrader::release_lock( 'moksafopoi_retention' );
			}
		}

		return $out;
	}
}
