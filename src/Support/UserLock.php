<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 每使用者應用鎖 — one lock per user, wrapped around any "read balance then spend" critical section.
 * The ledger's UNIQUE idempotency_key only stops a REPLAY of the SAME write; it does NOT stop two
 * DIFFERENT concurrent spends (each with its own ref) both passing `can_afford` on the same balance and
 * driving it negative (double-spend). Serialising per user with this lock closes that race for every
 * user-initiated spend path (transfer, buy-with-points, checkout redeem, wallet, ledger snapshot).
 *
 * Uses WordPress core's WP_Upgrader::create_lock() (an atomic INSERT IGNORE into the options table done
 * by core), so the plugin itself runs no direct SQL. Owner-approved 2026-11-15
 * (docs/批2-資料庫改寫設計.md §2.D). Differences from the former MySQL GET_LOCK:
 * - create_lock() does not wait, so this class retries every 100 ms until $timeout seconds pass.
 * - A lock left behind by a crashed request is only taken over after LOCK_TTL seconds (GET_LOCK was
 *   freed when the connection closed). LOCK_TTL is generous because the critical section fires our
 *   balance hooks, which other plugins may listen to; a lock must never be treated as stale while its
 *   owner is still working.
 * - Re-entrancy (a nested with() for the same user in the same request, e.g. Ledger::record_once()
 *   inside a transfer) is tracked here, since an options-table lock is not re-entrant by itself.
 *
 * Fails CLOSED — if the lock cannot be taken within the timeout the callback does NOT run and a WP_Error
 * is returned, so a contended spend is refused rather than allowed to race.
 */
final class UserLock {

	/** Seconds after which a lock left by a crashed request may be taken over. */
	private const LOCK_TTL = 30;

	/** Retry interval while waiting for the lock, in microseconds. */
	private const RETRY_USEC = 100000;

	/**
	 * Locks this request currently holds: user id => nesting depth.
	 *
	 * @var array<int,int>
	 */
	private static array $held = array();

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

		// Already held by this request → re-enter without touching the store.
		if ( isset( self::$held[ $user_id ] ) ) {
			++self::$held[ $user_id ];
			try {
				return $fn();
			} finally {
				--self::$held[ $user_id ];
			}
		}

		if ( ! class_exists( 'WP_Upgrader', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$name     = self::name( $user_id );
		$deadline = microtime( true ) + max( 0, $timeout );
		while ( ! \WP_Upgrader::create_lock( $name, self::LOCK_TTL ) ) {
			if ( microtime( true ) >= $deadline ) {
				return new WP_Error( 'moksafopoi_lock_busy', __( 'The system is busy, please try again later.', 'moksa-points-for-woocommerce' ) );
			}
			usleep( self::RETRY_USEC );
		}

		self::$held[ $user_id ] = 1;

		// The cached balance lives in user meta. This request may have loaded that meta BEFORE it got the
		// lock (and a persistent object cache keeps it too), so a balance read inside the lock could still
		// see the value from before the previous holder's spend — every concurrent spender would then pass
		// the balance check. Drop the user's meta cache so reads inside the lock come from the database.
		wp_cache_delete( $user_id, 'user_meta' );

		// create_lock() stores the acquisition time as the lock value. Remember it, so the release below
		// only deletes the lock if it is still ours: if this request ran past LOCK_TTL and another request
		// took the lock over, deleting it would let a third request in alongside that one.
		$stamp = self::stamp( $name );

		try {
			return $fn();
		} finally {
			unset( self::$held[ $user_id ] );
			// An empty stamp means we could not read our own value back (e.g. a lagging read replica);
			// release as before rather than leave the member locked out for LOCK_TTL.
			if ( '' === $stamp || self::stamp( $name ) === $stamp ) {
				\WP_Upgrader::release_lock( $name );
			}
		}
	}

	/** Current value of the lock option, read from the database (another request may have changed it). */
	private static function stamp( string $name ): string {
		$option = $name . '.lock';
		FreshOption::forget( $option );
		return (string) get_option( $option, '' );
	}

	/**
	 * The lock name for a user. Core stores it as the option "<name>.lock"; the option registry skips
	 * those, and release_lock() deletes them.
	 */
	private static function name( int $user_id ): string {
		return 'moksafopoi_spend_' . $user_id;
	}
}
