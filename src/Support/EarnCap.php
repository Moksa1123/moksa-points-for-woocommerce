<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * 賺點總量上限 — the safety valve that {@see EarnThrottle} (count-based) is not: a ceiling on the
 * TOTAL points a member may EARN within a period, across every farmable source combined. Without it,
 * stacking sources (消費 + 簽到 + 評價 + coupon + 生日) has no天花板 and an aggressive campaign can be
 * farmed. It reads already-earned points straight from the value {@see \Moksafopoi\Modules\Ledger\Ledger}
 * — the single source of truth — so a refund / clawback that removed a prior earn naturally re-opens
 * the allowance, and there is no parallel counter to drift.
 *
 * Applied once, centrally, in {@see \Moksafopoi\Modules\Ledger\Ledger::record_once()} on the
 * positive `type='earn'` branch only, so every current and future earn module is covered without
 * touching each one, while corrections (`adjust`), redemptions (`redeem`/`debit`) and the store-credit
 * lane (`credit`/`cashback`/`giftcard`, points_delta = 0) are structurally exempt.
 *
 * OFF by default (period = 'off'): zero behaviour change until an operator sets an amount + period.
 * Purchase (`spend_rule`) is excluded by default so honest big spenders are never capped; a handful of
 * system sources (transfers-in, bought points, imports, scheduled / admin grants) are ALWAYS exempt.
 */
final class EarnCap {

	private const OPT_AMOUNT  = 'moksafopoi_earn_cap_amount';
	private const OPT_PERIOD  = 'moksafopoi_earn_cap_period';           // day|week|month|year|total|off
	private const OPT_EXCLUDE = 'moksafopoi_earn_cap_exclude_sources';  // comma / newline list

	/**
	 * Sources that must NEVER be capped even if the operator's exclude list omits them: capping a
	 * transfer-in, bought points, an import, or a scheduled / admin grant would silently break those
	 * flows (they are not "farming"). Always merged into the effective exclude set.
	 *
	 * @var array<int,string>
	 */
	private const SYSTEM_EXCLUDED = array( 'transfer_in', 'buypoints', 'import', 'sched', 'manual', 'bulk' );

	/** The configured period, normalised; 'off' disables the cap. */
	public static function period(): string {
		$p = (string) get_option( self::OPT_PERIOD, 'off' );
		return in_array( $p, array( 'day', 'week', 'month', 'year', 'total' ), true ) ? $p : 'off';
	}

	/** The ceiling (points). 0 = no ceiling. */
	public static function cap_amount(): int {
		return max( 0, (int) get_option( self::OPT_AMOUNT, 0 ) );
	}

	/** Whether the cap is in force (a real period + a positive ceiling). */
	public static function active(): bool {
		return 'off' !== self::period() && self::cap_amount() > 0;
	}

	/**
	 * The effective exclude set: the operator's list plus the always-exempt system sources.
	 *
	 * @return array<int,string>
	 */
	public static function excluded_sources(): array {
		$raw  = (string) get_option( self::OPT_EXCLUDE, 'spend_rule' );
		$list = preg_split( '/[\s,]+/', $raw ) ?: array();
		$list = array_filter( array_map( 'sanitize_key', $list ) );
		return array_values( array_unique( array_merge( self::SYSTEM_EXCLUDED, $list ) ) );
	}

	/** Whether a source is exempt from the cap. */
	public static function is_excluded( string $source ): bool {
		return in_array( sanitize_key( $source ), self::excluded_sources(), true );
	}

	/**
	 * Points already earned by $user in the current period, excluding exempt sources — the amount
	 * that counts against the ceiling. Read from the ledger so it is always correct after refunds.
	 */
	public static function earned_this_period( int $user_id ): int {
		if ( $user_id <= 0 || ! self::active() ) {
			return 0;
		}
		$since = EarnThrottle::period_start( 'total' === self::period() ? 'all' : self::period() );

		// Per-source totals, minus the sources the cap ignores (same result as the former NOT IN clause).
		$by_source = LedgerStore::earned_by_source_since( $user_id, null !== $since ? $since : LedgerStore::MIN_DATETIME );
		foreach ( self::excluded_sources() as $source ) {
			unset( $by_source[ (string) $source ] );
		}
		return (int) array_sum( $by_source );
	}

	/** Points still earnable this period (PHP_INT_MAX when the cap is off). */
	public static function remaining( int $user_id ): int {
		if ( ! self::active() ) {
			return PHP_INT_MAX;
		}
		return max( 0, self::cap_amount() - self::earned_this_period( $user_id ) );
	}

	/**
	 * Clamp an incoming positive earn to the room left in the period. Returns how many points may
	 * actually be booked (0..$incoming). A no-op — returns $incoming untouched — when the cap is off,
	 * the user is invalid, or the source is exempt.
	 */
	public static function clamp( int $user_id, int $incoming, string $source ): int {
		if ( $incoming <= 0 || $user_id <= 0 || ! self::active() || self::is_excluded( $source ) ) {
			return $incoming;
		}
		$room = self::remaining( $user_id );
		return $incoming <= $room ? $incoming : $room;
	}
}
