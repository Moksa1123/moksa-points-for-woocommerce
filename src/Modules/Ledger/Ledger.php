<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Ledger;

use Moksafopoi\Support\EarnCap;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * The value ledger service: append-only writes, derived balance, history. Every
 * state-changing write goes through record_once(), whose UNIQUE idempotency_key makes a
 * replayed or late hook a no-op — this is what lets sibling listeners attach late and
 * orders re-process (status flips, cron replays) without ever double-crediting.
 *
 * Two value lanes share the ledger: integer `points` (loyalty) and a `currency`
 * store-credit amount (wallet / gift card). Balance is a projection (SUM of deltas),
 * cached in user_meta for fast checkout reads but always recomputable from the table.
 */
final class Ledger {

	private const META_POINTS = '_moksafopoi_balance';
	private const META_CREDIT = '_moksafopoi_credit_balance';

	/**
	 * Append one immutable ledger row, idempotently. A duplicate (source, source_ref,
	 * user, bucket) is silently ignored (already recorded) and returns false.
	 *
	 * @param array{amount_delta?:float,order_id?:int,note?:string,meta?:array<string,mixed>,bucket?:string,expires_at?:?string,created_by?:int} $args
	 * @return bool True when a NEW row was written; false when it was already recorded.
	 */
	public static function record_once( int $user_id, int $points_delta, string $type, string $source, string $source_ref, array $args = array() ): bool {
		global $wpdb;

		$amount_delta = isset( $args['amount_delta'] ) ? (float) $args['amount_delta'] : 0.0;
		if ( $user_id <= 0 || ( 0 === $points_delta && 0.0 === $amount_delta ) ) {
			return false;
		}

		// Earn-cap safety valve: a positive points EARN is clamped to the per-period ceiling so no
		// combination of sources can be farmed past it. Only the `earn` branch is affected —
		// corrections (adjust), redemptions (redeem/debit) and the store-credit lane are exempt by
		// construction. Off by default; excluded sources pass through untouched. Applied here so every
		// current and future earn module is covered without editing each one.
		if ( $points_delta > 0 && 'earn' === $type ) {
			// 排除名單:被排除的角色 / 帳號(員工、批發、測試帳號)完全不賺點 — 在 EarnCap 之前
			// 早退,同樣一次蓋掉所有賺點模組。花點 / 手動調整 / 儲值金不受影響。
			if ( \Moksafopoi\Support\EarnExclusions::excluded( $user_id ) ) {
				return false;
			}
			$points_delta = EarnCap::clamp( $user_id, $points_delta, $source );
			if ( $points_delta <= 0 && 0.0 === $amount_delta ) {
				return false; // Cap fully consumed — nothing left to book.
			}
		}

		$bucket = (string) ( $args['bucket'] ?? '' );
		$idem   = sha1( $source . ':' . $source_ref . ':' . $user_id . ':' . $bucket );

		$table = Schema::ledger_table();

		// The running `balance_after` snapshot must be read + written atomically per user, or two
		// concurrent writes can both read the same cached base and stamp an incorrect running balance
		// on the ledger-browser column (the authoritative balance is always recomputed below — this only
		// keeps the informational per-row column exact). Serialise the snapshot + INSERT with the per-user
		// advisory lock. It is re-entrant with any caller-held UserLock (transfer / redeem already lock);
		// on contention we fall back to an unlocked insert so a write is never blocked (best-effort snapshot).
		$do_insert = static function () use ( &$wpdb, $table, $user_id, $points_delta, $amount_delta, $type, $source, $source_ref, $idem, $args ): int {
			$balance_after = self::points( $user_id ) + $points_delta;
			// INSERT IGNORE: rows_affected === 0 means the UNIQUE idempotency_key already exists.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table}
						(user_id, points_delta, amount_delta, balance_after, type, source, source_ref, idempotency_key, order_id, note, meta, created_by, created_at, expires_at)
						VALUES (%d, %d, %f, %d, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s)",
					$user_id,
					$points_delta,
					$amount_delta,
					$balance_after,
					$type,
					$source,
					$source_ref,
					$idem,
					(int) ( $args['order_id'] ?? 0 ),
					(string) ( $args['note'] ?? '' ),
					isset( $args['meta'] ) ? (string) wp_json_encode( $args['meta'] ) : null,
					(int) ( $args['created_by'] ?? 0 ),
					gmdate( 'Y-m-d H:i:s' ),
					isset( $args['expires_at'] ) ? $args['expires_at'] : null
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return (int) $wpdb->rows_affected;
		};

		$locked = \Moksafopoi\Support\UserLock::with( $user_id, $do_insert );
		$rows   = ( $locked instanceof \WP_Error ) ? $do_insert() : (int) $locked;

		if ( 1 !== $rows ) {
			return false; // Duplicate — already recorded.
		}

		self::recompute( $user_id );

		if ( 0 !== $points_delta ) {
			if ( $points_delta > 0 ) {
				do_action( 'moksafopoi_points_earned', $user_id, $points_delta, $source, $args );
			} else {
				do_action( 'moksafopoi_points_redeemed', $user_id, abs( $points_delta ), $source, $args );
			}
		}
		do_action( 'moksafopoi_balance_changed', $user_id, self::points( $user_id ), self::credit( $user_id ) );

		return true;
	}

	/** Current integer points balance (cached projection of the ledger). */
	public static function points( int $user_id ): int {
		$cached = get_user_meta( $user_id, self::META_POINTS, true );
		return '' === $cached ? self::recompute( $user_id )['points'] : (int) $cached;
	}

	/** Current currency store-credit balance (wallet / gift-card lane). */
	public static function credit( int $user_id ): float {
		$cached = get_user_meta( $user_id, self::META_CREDIT, true );
		return '' === $cached ? self::recompute( $user_id )['credit'] : (float) $cached;
	}

	/**
	 * Recompute both balances from the table and refresh the cached projection.
	 *
	 * @return array{points:int,credit:float}
	 */
	public static function recompute( int $user_id ): array {
		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user id bound via $wpdb->prepare().
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) AS p, COALESCE(SUM(amount_delta),0) AS c FROM {$table} WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$points = (int) ( $row['p'] ?? 0 );
		$credit = (float) ( $row['c'] ?? 0 );
		update_user_meta( $user_id, self::META_POINTS, $points );
		update_user_meta( $user_id, self::META_CREDIT, $credit );
		return array(
			'points' => $points,
			'credit' => $credit,
		);
	}

	/**
	 * Recent ledger rows for a user (newest first).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = Schema::ledger_table();
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, points_delta, amount_delta, balance_after, type, source, note, created_at FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return is_array( $rows ) ? $rows : array();
	}
}
