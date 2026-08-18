<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 每使用者應用鎖 — a MySQL advisory lock (GET_LOCK) scoped to one user, wrapped around any "read balance
 * then spend" critical section. The ledger's UNIQUE idempotency_key only stops a REPLAY of the SAME
 * write; it does NOT stop two DIFFERENT concurrent spends (each with its own ref) both passing
 * `can_afford` on the same balance and driving it negative (double-spend). Serialising per user with this
 * lock closes that race for every user-initiated spend path (transfer, buy-with-points, checkout redeem).
 *
 * Connection-scoped: WordPress uses a single DB connection per request, so acquire + release ride the same
 * session. Re-entrant within a request (MySQL 5.7+ grants the same session a lock it already holds). Fails
 * CLOSED — if the lock cannot be taken within the timeout the callback does NOT run and a WP_Error is
 * returned, so a contended spend is refused rather than allowed to race.
 */
final class UserLock {

	/**
	 * Run $fn while holding the per-user lock. Returns whatever $fn returns, or a WP_Error when the lock
	 * could not be acquired within $timeout seconds. The lock is always released (even if $fn throws).
	 *
	 * @param callable():mixed $fn
	 * @return mixed|WP_Error
	 */
	public static function with( int $user_id, callable $fn, int $timeout = 5 ) {
		if ( $user_id <= 0 ) {
			return new WP_Error( 'moksafopoi_lock_user', __( 'Invalid user.', 'moksa-points-for-woocommerce' ) );
		}
		global $wpdb;
		$name = self::name( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, not a table read.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout ) ) );
		if ( '1' !== (string) $got ) {
			return new WP_Error( 'moksafopoi_lock_busy', __( 'The system is busy, please try again later.', 'moksa-points-for-woocommerce' ) );
		}

		try {
			return $fn();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the advisory lock.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/**
	 * The lock name for a user. Kept well under MySQL's 64-char limit and salted so two WordPress installs
	 * sharing one MySQL server never collide on the same user id.
	 */
	private static function name( int $user_id ): string {
		return 'mfp_spend_' . substr( md5( (string) wp_salt( 'auth' ) ), 0, 12 ) . '_' . $user_id;
	}
}
