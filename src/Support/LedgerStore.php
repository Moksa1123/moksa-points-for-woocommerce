<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The ONLY place that reads or writes the value ledger table (`{prefix}moksafopoi_ledger`).
 *
 * Why the ledger stays a custom table (decided by the plugin owner on 2026-11-15, design doc
 * docs/批2-資料庫改寫設計.md §3 option c): every row carries an idempotency key guarded by a UNIQUE
 * index, so a replayed hook / cron / order status flip can never credit twice; balances are SUMs over
 * indexed columns. A CPT/meta store cannot give that guarantee at the database level.
 *
 * Rules for this file:
 * - Every query is a fixed SQL literal. The table name is bound with %i and every value with
 *   %d / %s / %f through $wpdb->prepare(); nothing is interpolated into SQL.
 * - Optional filters are expressed inside the fixed SQL (e.g. `( %d < 0 OR user_id = %d )`) or done in
 *   PHP on the result, never by concatenating WHERE fragments.
 * - phpcs.xml excludes DirectQuery / NoCaching for this one file only (permanent exception approved by
 *   the owner). No other file may use $wpdb on this table.
 *
 * Methods return raw rows / scalars; business logic stays with the callers.
 */
final class LedgerStore {

	/** Lower / upper bounds used when a caller has no time limit on that side (created_at is NOT NULL). */
	public const MIN_DATETIME = '1000-01-01 00:00:00';
	public const MAX_DATETIME = '9999-12-31 23:59:59';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'moksafopoi_ledger';
	}

	/* ------------------------------------------------------------------ writes */

	/**
	 * INSERT IGNORE one ledger row. Returns rows affected: 1 = written, 0 = the idempotency key already
	 * exists (duplicate, nothing written).
	 *
	 * @param array{user_id:int,points_delta:int,amount_delta:float,balance_after:int,type:string,source:string,source_ref:string,idempotency_key:string,order_id:int,note:string,meta:?string,created_by:int,created_at:string,expires_at:?string} $row
	 */
	public static function insert_ignore( array $row ): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i
					(user_id, points_delta, amount_delta, balance_after, type, source, source_ref, idempotency_key, order_id, note, meta, created_by, created_at, expires_at)
					VALUES (%d, %d, %f, %d, %s, %s, %s, %s, %d, %s, %s, %d, %s, %s)',
				self::table(),
				$row['user_id'],
				$row['points_delta'],
				$row['amount_delta'],
				$row['balance_after'],
				$row['type'],
				$row['source'],
				$row['source_ref'],
				$row['idempotency_key'],
				$row['order_id'],
				$row['note'],
				$row['meta'],
				$row['created_by'],
				$row['created_at'],
				$row['expires_at']
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/**
	 * Plain insert used by retention compaction (opening row / repair row). Returns the new row id, or 0
	 * when the insert failed.
	 *
	 * @param array<string,int|float|string> $row Column => value.
	 * @param array<int,string>              $formats
	 */
	public static function insert_row( array $row, array $formats ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::table(), $row, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Retention: delete one member's compacted rows (everything older than the cutoff except the new opening row). */
	/**
	 * Retention: the batch one member's compaction will replace — row count, sums and the highest id.
	 * Redemption rows (type redeem, sources redeem_code / redeem_refund) are never compacted: their refs
	 * carry per-member redemption limits and redeem-once codes.
	 *
	 * @return array{rows:int,p:int,c:float,max_id:int}
	 */
	public static function retention_batch( int $user_id, string $cutoff, string $now ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(points_delta),0) AS p, COALESCE(SUM(amount_delta),0) AS c, COALESCE(MAX(id),0) AS m
				FROM %i
				WHERE user_id = %d AND created_at < %s
					AND ( expires_at IS NULL OR expires_at < %s OR expires_at < '1971-01-01 00:00:00' )
					AND type <> 'opening' AND type <> 'redeem' AND source NOT IN ('redeem_code', 'redeem_refund', 'checkout_redeem_refund')",
				self::table(),
				$user_id,
				$cutoff,
				$now
			),
			ARRAY_A
		);
		return array(
			'rows'   => (int) ( $row['n'] ?? 0 ),
			'p'      => (int) ( $row['p'] ?? 0 ),
			'c'      => (float) ( $row['c'] ?? 0 ),
			'max_id' => (int) ( $row['m'] ?? 0 ),
		);
	}

	/**
	 * Retention: delete exactly the batch retention_batch() summed — same conditions, and only ids up to
	 * that batch's highest id, so a row written in the meantime is never touched.
	 */
	public static function delete_compacted( int $user_id, string $cutoff, string $now, int $max_id ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i
				WHERE user_id = %d AND created_at < %s AND id <= %d
					AND ( expires_at IS NULL OR expires_at < %s OR expires_at < '1971-01-01 00:00:00' )
					AND type <> 'opening' AND type <> 'redeem' AND source NOT IN ('redeem_code', 'redeem_refund', 'checkout_redeem_refund')",
				self::table(),
				$user_id,
				$cutoff,
				$max_id,
				$now
			)
		);
	}

	/** Uninstall: drop the ledger table. */
	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) );
	}

	/* ------------------------------------------------------------------ one member */

	/**
	 * Points + store-credit sums for one member (the authoritative balance).
	 *
	 * @return array{p:int,c:float}
	 */
	public static function sums_for_user( int $user_id ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT COALESCE(SUM(points_delta),0) AS p, COALESCE(SUM(amount_delta),0) AS c FROM %i WHERE user_id = %d', self::table(), $user_id ),
			ARRAY_A
		);
		return array(
			'p' => (int) ( $row['p'] ?? 0 ),
			'c' => (float) ( $row['c'] ?? 0 ),
		);
	}

	/** @return array<int,array<string,mixed>> Newest first. */
	public static function history( int $user_id, int $limit, int $offset ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, points_delta, amount_delta, balance_after, type, source, source_ref, order_id, note, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
				self::table(),
				$user_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> points_delta + expires_at, oldest first (FIFO replay for expiry). */
	public static function rows_chronological( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT points_delta, expires_at FROM %i WHERE user_id = %d ORDER BY created_at ASC, id ASC', self::table(), $user_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Soonest future expiry among this member's earn rows ('' when none). */
	public static function next_expiry( int $user_id, string $now ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MIN(expires_at) FROM %i WHERE user_id = %d AND expires_at IS NOT NULL AND expires_at > %s AND points_delta > 0',
				self::table(),
				$user_id,
				$now
			)
		);
	}

	/** Earn points of this member that expire in (now, until]. */
	public static function expiring_for_user( int $user_id, string $now, string $until ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE user_id = %d AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND points_delta > 0',
				self::table(),
				$user_id,
				$now,
				$until
			)
		);
	}

	/** Lifetime positive points (tier basis). $include_topup = false leaves out type 'topup'. */
	public static function earned_total( int $user_id, bool $include_topup ): int {
		global $wpdb;
		if ( $include_topup ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE user_id = %d AND points_delta > 0', self::table(), $user_id )
			);
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE user_id = %d AND points_delta > 0 AND type <> 'topup'", self::table(), $user_id )
		);
	}

	/**
	 * Earned points ('earn' rows) per source since a time; the earn cap subtracts its excluded sources in
	 * PHP from this breakdown.
	 *
	 * @return array<string,int> source => points
	 */
	public static function earned_by_source_since( int $user_id, string $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source, COALESCE(SUM(points_delta),0) AS total FROM %i WHERE user_id = %d AND type = 'earn' AND points_delta > 0 AND created_at >= %s GROUP BY source",
				self::table(),
				$user_id,
				$since
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['source'] ] = (int) $row['total'];
		}
		return $out;
	}

	/** Positive rows of one source for a member since a time (throttle). */
	public static function count_positive_since( int $user_id, string $source, string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND source = %s AND points_delta > 0 AND created_at >= %s',
				self::table(),
				$user_id,
				$source,
				$since
			)
		);
	}

	/** All rows (any sign) of one source for a member since a time (transfer daily limit). */
	public static function count_source_since( int $user_id, string $source, string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND source = %s AND created_at >= %s',
				self::table(),
				$user_id,
				$source,
				$since
			)
		);
	}

	/** Rows with an exact source + source_ref for a member (balance import "already booked"). */
	public static function count_ref( int $user_id, string $source, string $source_ref ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND source = %s AND source_ref = %s',
				self::table(),
				$user_id,
				$source,
				$source_ref
			)
		);
	}

	/**
	 * The first row booked for a member under one source + source_ref, or null. Lets an idempotent
	 * caller answer a retry with what the first call did.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function row_for_ref( int $user_id, string $source, string $source_ref ): ?array {
		global $wpdb;
		// The column's collation ignores case, but references are case-sensitive (the idempotency key
		// is a hash of the exact text), so "INV-1" and "inv-1" are different: match exactly in PHP.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, points_delta, amount_delta, type, order_id, created_at, source_ref FROM %i WHERE user_id = %d AND source = %s AND source_ref = %s ORDER BY id ASC LIMIT 20',
				self::table(),
				$user_id,
				$source,
				$source_ref
			),
			ARRAY_A
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( (string) $row['source_ref'] === $source_ref ) {
				return $row;
			}
		}
		return null;
	}

	/** Net points booked for a member on one order from one source. */
	public static function sum_for_order_source( int $user_id, int $order_id, string $source ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE user_id = %d AND order_id = %d AND source = %s',
				self::table(),
				$user_id,
				$order_id,
				$source
			)
		);
	}

	/** Positive points a member got from one source + source_ref (referral claw-back). */
	public static function granted_for_ref( int $user_id, string $source, string $source_ref ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE user_id = %d AND source = %s AND source_ref = %s AND points_delta > 0',
				self::table(),
				$user_id,
				$source,
				$source_ref
			)
		);
	}

	/** Redeem rows of a member whose source_ref matches a LIKE pattern (per-member reward limit). */
	public static function count_redeems_like( int $user_id, string $like ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND type = 'redeem' AND source_ref LIKE %s", self::table(), $user_id, $like )
		);
	}

	/** Rows of one source whose source_ref matches a LIKE pattern (e.g. refunds of failed redemptions). */
	public static function count_source_like( int $user_id, string $source, string $like ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND source = %s AND source_ref LIKE %s', self::table(), $user_id, $source, $like )
		);
	}

	/* ------------------------------------------------------------------ one order */

	/** Positive points booked against an order (thank-you toast). */
	public static function earned_for_order( int $order_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE order_id = %d AND points_delta > 0', self::table(), $order_id )
		);
	}

	/** Positive 'earn' points a member got for an order (refund claw-back). */
	public static function earned_for_order_user( int $order_id, int $user_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE order_id = %d AND user_id = %d AND type = 'earn' AND points_delta > 0",
				self::table(),
				$order_id,
				$user_id
			)
		);
	}

	/**
	 * Earned / spent points and net store credit booked against an order (admin order screen).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function order_summary( int $order_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) AS earned,
					COALESCE(SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END),0) AS spent,
					COALESCE(SUM(amount_delta),0) AS credit
				FROM %i WHERE order_id = %d',
				self::table(),
				$order_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/* ------------------------------------------------------------------ reports (whole ledger) */

	/**
	 * Issued / redeemed / expired / outstanding / credit issued, for rows created at or after $since.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function report_totals( string $since ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END),0) AS issued,
					COALESCE(SUM(CASE WHEN type = 'redeem' AND points_delta < 0 THEN -points_delta ELSE 0 END),0) AS redeemed,
					COALESCE(SUM(CASE WHEN type = 'expire' AND points_delta < 0 THEN -points_delta ELSE 0 END),0) AS expired,
					COALESCE(SUM(points_delta),0) AS outstanding,
					COALESCE(SUM(CASE WHEN amount_delta > 0 THEN amount_delta ELSE 0 END),0) AS credit_issued
				FROM %i WHERE created_at >= %s",
				self::table(),
				$since
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> Per-day earned / redeemed since a date (dashboard chart). */
	public static function daily_since( string $since ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(created_at) AS d,
					SUM(CASE WHEN points_delta > 0 THEN points_delta ELSE 0 END) AS earned,
					SUM(CASE WHEN points_delta < 0 THEN -points_delta ELSE 0 END) AS redeemed
				FROM %i WHERE created_at >= %s GROUP BY DATE(created_at)',
				self::table(),
				$since
			),
			ARRAY_A
		);
	}

	/** @return array<int,array<string,mixed>> Top 8 earning sources since a date. */
	public static function top_sources_since( string $since ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, SUM(points_delta) AS total FROM %i
				WHERE points_delta > 0 AND created_at >= %s GROUP BY source ORDER BY total DESC LIMIT 8',
				self::table(),
				$since
			),
			ARRAY_A
		);
	}

	/** @return array<int,array<string,mixed>> Top 10 members by current balance. */
	public static function top_balances(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT user_id, SUM(points_delta) AS balance FROM %i GROUP BY user_id HAVING balance > 0 ORDER BY balance DESC LIMIT 10', self::table() ),
			ARRAY_A
		);
	}

	/** Sum of positive points (issued), as the raw SUM (null when the ledger is empty). */
	public static function sum_positive(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(points_delta) FROM %i WHERE points_delta > %d', self::table(), 0 ) );
		return null === $value ? null : (string) $value;
	}

	/** Sum of negative points (as a negative number), raw SUM (null when none). */
	public static function sum_negative(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(points_delta) FROM %i WHERE points_delta < %d', self::table(), 0 ) );
		return null === $value ? null : (string) $value;
	}

	/** Net points in circulation, raw SUM (null when the ledger is empty). */
	public static function sum_all(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(points_delta) FROM %i', self::table() ) );
		return null === $value ? null : (string) $value;
	}

	/** Positive points issued since a time (use MIN_DATETIME for lifetime). */
	public static function issued_since( string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(points_delta),0) FROM %i WHERE points_delta > 0 AND created_at >= %s', self::table(), $since )
		);
	}

	/** Points spent (negative, not expiry) since a time, as a positive number. */
	public static function spent_since( string $since ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(-points_delta),0) FROM %i WHERE points_delta < 0 AND type <> 'expire' AND created_at >= %s", self::table(), $since )
		);
	}

	/** Points removed by expiry (lifetime), as a positive number. */
	public static function expired_total(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(-points_delta),0) FROM %i WHERE points_delta < 0 AND type = 'expire'", self::table() )
		);
	}

	/** @return array<int,string> Every positive member balance, ascending (liability forecast). */
	public static function positive_balances(): array {
		global $wpdb;
		return (array) $wpdb->get_col(
			$wpdb->prepare( 'SELECT SUM(points_delta) AS bal FROM %i GROUP BY user_id HAVING bal > 0 ORDER BY bal ASC', self::table() )
		);
	}

	/** Earn points (all members) expiring in (from, until]. */
	public static function expiring_between( string $from, string $until ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(points_delta),0) FROM %i
				WHERE points_delta > 0 AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s',
				self::table(),
				$from,
				$until
			)
		);
	}

	/** @return array<int,array<string,mixed>> Per member: soonest expiry + points expiring in (now, cutoff]. */
	public static function expiring_by_user( string $now, string $cutoff, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, MIN(expires_at) AS next_expiry, COALESCE(SUM(points_delta),0) AS expiring
					FROM %i
					WHERE expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s AND points_delta > 0
					GROUP BY user_id
					LIMIT %d',
				self::table(),
				$now,
				$cutoff,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,string> Members holding earn rows whose expiry has passed. */
	public static function users_with_expired_earns( string $now ): array {
		global $wpdb;
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM %i WHERE expires_at IS NOT NULL AND expires_at > '1971-01-01 00:00:00' AND expires_at <= %s AND points_delta > 0",
				self::table(),
				$now
			)
		);
	}

	/**
	 * Leaderboard: members by points earned in [start, end), best first. The caller drops excluded
	 * members in PHP, so it asks for limit + number of excluded members.
	 *
	 * @return array<int,array<string,mixed>>|null Null on a database error.
	 */
	public static function top_earners( string $start, string $end, int $limit ): ?array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, SUM(points_delta) AS earned FROM %i WHERE points_delta > 0 AND created_at >= %s AND created_at < %s
					GROUP BY user_id ORDER BY earned DESC, user_id ASC LIMIT %d',
				self::table(),
				$start,
				$end,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : null;
	}

	/** Leaderboard: one member's earned points in [start, end). */
	public static function earned_between( int $user_id, string $start, string $end ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(points_delta) FROM %i WHERE user_id = %d AND points_delta > 0 AND created_at >= %s AND created_at < %s',
				self::table(),
				$user_id,
				$start,
				$end
			)
		);
	}

	/** Leaderboard: how many members earned strictly more than $points in [start, end). */
	public static function count_ahead( string $start, string $end, int $points ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ( SELECT user_id FROM %i WHERE points_delta > 0 AND created_at >= %s AND created_at < %s
					GROUP BY user_id HAVING SUM(points_delta) > %d ) t',
				self::table(),
				$start,
				$end,
				$points
			)
		);
	}

	/** @return array<int,array<string,mixed>> Retention: members with more than one compactable row. */
	public static function retention_candidates( string $cutoff, string $now, int $limit ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, COUNT(*) AS rows_count,
					COALESCE(SUM(points_delta),0) AS points_sum,
					COALESCE(SUM(amount_delta),0) AS credit_sum
				FROM %i
				WHERE created_at < %s
					AND ( expires_at IS NULL OR expires_at < %s OR expires_at < '1971-01-01 00:00:00' )
					AND type <> 'opening' AND type <> 'redeem' AND source NOT IN ('redeem_code', 'redeem_refund', 'checkout_redeem_refund')
				GROUP BY user_id
				HAVING rows_count > 1
				LIMIT %d",
				self::table(),
				$cutoff,
				$now,
				$limit
			),
			ARRAY_A
		);
	}

	/* ------------------------------------------------------------------ admin ledger browser */

	/** @return array<int,string> Up to 50 distinct values of `type` or `source` (filter dropdowns). */
	public static function distinct_values( string $column ): array {
		global $wpdb;
		if ( 'type' === $column ) {
			return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT type FROM %i ORDER BY type ASC LIMIT 50', self::table() ) );
		}
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i ORDER BY source ASC LIMIT 50', self::table() ) );
	}

	/**
	 * Browser filters. Each one is optional: user_id < 0, '' type / source / from / to mean "no filter".
	 *
	 * @param array{user_id:int,type:string,source:string,from:string,to:string} $f
	 */
	public static function browse_count( array $f ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				WHERE ( %d < 0 OR user_id = %d )
					AND ( %s = '' OR type = %s )
					AND ( %s = '' OR source = %s )
					AND ( %s = '' OR created_at >= %s )
					AND ( %s = '' OR created_at <= %s )",
				self::table(),
				$f['user_id'],
				$f['user_id'],
				$f['type'],
				$f['type'],
				$f['source'],
				$f['source'],
				$f['from'],
				$f['from'],
				$f['to'],
				$f['to']
			)
		);
	}

	/**
	 * @param array{user_id:int,type:string,source:string,from:string,to:string} $f
	 * @return array<int,array<string,mixed>> Newest first.
	 */
	public static function browse_rows( array $f, int $limit, int $offset ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, points_delta, amount_delta, balance_after, type, source, source_ref, order_id, note, created_at FROM %i
				WHERE ( %d < 0 OR user_id = %d )
					AND ( %s = '' OR type = %s )
					AND ( %s = '' OR source = %s )
					AND ( %s = '' OR created_at >= %s )
					AND ( %s = '' OR created_at <= %s )
				ORDER BY id DESC LIMIT %d OFFSET %d",
				self::table(),
				$f['user_id'],
				$f['user_id'],
				$f['type'],
				$f['type'],
				$f['source'],
				$f['source'],
				$f['from'],
				$f['from'],
				$f['to'],
				$f['to'],
				$limit,
				$offset
			),
			ARRAY_A
		);
	}

	/** @return array<string,mixed>|null One row for the reversal action. */
	public static function row_for_reversal( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, points_delta, source_ref FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/* ------------------------------------------------------------------ legacy store-credit import */

	/**
	 * One-time import of Moksa Coupons 0.x store credit (user meta `_moforcoupon_store_credit`). Kept
	 * here, inside the approved exception file, on the owner's decision (2026-11-15): a site that ran a
	 * private 0.x build and has not yet installed Points still needs it.
	 *
	 * @return array<int,array<string,mixed>> user_id + meta_value
	 */
	public static function legacy_store_credit_rows( string $meta_key ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_value FROM %i WHERE meta_key = %s AND meta_value <> '' AND meta_value <> '0'",
				$wpdb->usermeta,
				$meta_key
			),
			ARRAY_A
		);
	}

	private function __construct() {}
}
