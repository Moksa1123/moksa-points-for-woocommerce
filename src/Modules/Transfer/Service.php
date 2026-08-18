<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Transfer;

use Moksafopoi\Api;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\EarnThrottle;
use Moksafopoi\Support\Schema;
use Moksafopoi\Support\UserLock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 會員對會員點數轉贈 — move points from one member to another as a matched, value-conserving pair of
 * ledger writes under ONE ref: `-N transfer_out` on the sender, `+N transfer_in` on the recipient. Because
 * two concurrent transfers could each pass `can_afford` on the same balance (the idempotency key stops only
 * a replay, not a race), the sender's affordability check + debit run INSIDE a per-user {@see UserLock} —
 * closing the double-spend window. `transfer_in` is EarnCap-excluded, so the recipient credit is never
 * clamped and the two legs always conserve value.
 */
final class Service {

	public static function enabled(): bool {
		return 'yes' === (string) get_option( 'moksafopoi_transfer_enabled', 'no' );
	}

	/** Minimum points per transfer (default 1). */
	public static function min_points(): int {
		return max( 1, (int) get_option( 'moksafopoi_transfer_min', 1 ) );
	}

	/** Maximum points per single transfer (0 = unlimited). */
	public static function max_points(): int {
		return max( 0, (int) get_option( 'moksafopoi_transfer_max', 0 ) );
	}

	/** Maximum number of transfers a member may SEND per day (0 = unlimited). */
	public static function max_per_day(): int {
		return max( 0, (int) get_option( 'moksafopoi_transfer_max_per_day', 0 ) );
	}

	/**
	 * Resolve a recipient identifier (email / login / numeric id) to a user id, or 0 when not found.
	 */
	public static function resolve_recipient( string $identifier ): int {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return 0;
		}
		$user = false;
		if ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}
		if ( ! $user ) {
			$user = get_user_by( 'login', $identifier );
		}
		if ( ! $user && ctype_digit( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		}
		return $user ? (int) $user->ID : 0;
	}

	/** Count the sender's transfers booked today (outgoing legs), sharing EarnThrottle's day boundary. */
	public static function sent_today( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		$table = Schema::ledger_table();
		$since = EarnThrottle::period_start( 'day' );
		if ( null === $since ) {
			return 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND source = %s AND created_at >= %s",
				$user_id,
				'transfer_out',
				$since
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Transfer $points from $from to $to. Returns a result array on success, or a WP_Error.
	 *
	 * @return array{ref:string,points:int,to:int,balance:int}|WP_Error
	 */
	public static function transfer( int $from, int $to, int $points, string $note = '' ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'moksafopoi_transfer_off', __( 'Points transfer is not enabled.', 'moksa-points-for-woocommerce' ) );
		}
		if ( $from <= 0 ) {
			return new WP_Error( 'moksafopoi_transfer_guest', __( 'Please log in first.', 'moksa-points-for-woocommerce' ) );
		}
		if ( $to <= 0 ) {
			return new WP_Error( 'moksafopoi_transfer_recipient', __( 'Recipient member not found; please check the email / username.', 'moksa-points-for-woocommerce' ) );
		}
		if ( $from === $to ) {
			return new WP_Error( 'moksafopoi_transfer_self', __( 'You cannot transfer to yourself.', 'moksa-points-for-woocommerce' ) );
		}
		if ( $points <= 0 ) {
			return new WP_Error( 'moksafopoi_transfer_amount', __( 'Please enter the number of points to transfer.', 'moksa-points-for-woocommerce' ) );
		}
		$min = self::min_points();
		if ( $points < $min ) {
			/* translators: %d: minimum points. */
			return new WP_Error( 'moksafopoi_transfer_min', sprintf( __( 'At least %d point(s) per transfer.', 'moksa-points-for-woocommerce' ), $min ) );
		}
		$max = self::max_points();
		if ( $max > 0 && $points > $max ) {
			/* translators: %d: maximum points. */
			return new WP_Error( 'moksafopoi_transfer_max', sprintf( __( 'At most %d point(s) per transfer.', 'moksa-points-for-woocommerce' ), $max ) );
		}
		$cap = self::max_per_day();
		if ( $cap > 0 && self::sent_today( $from ) >= $cap ) {
			/* translators: %d: max transfers per day. */
			return new WP_Error( 'moksafopoi_transfer_daycap', sprintf( __( 'You have reached today\'s transfer limit (%d times).', 'moksa-points-for-woocommerce' ), $cap ) );
		}

		// Critical section: affordability + debit + credit, serialised per SENDER against concurrent spends.
		$result = UserLock::with(
			$from,
			static function () use ( $from, $to, $points, $note ) {
				if ( ! Api::can_afford( $from, $points ) ) {
					return new WP_Error( 'moksafopoi_transfer_insufficient', __( 'Insufficient points.', 'moksa-points-for-woocommerce' ) );
				}
				$ref        = 'xfer_' . gmdate( 'YmdHis' ) . '_' . wp_generate_password( 10, false );
				$created_by = get_current_user_id();

				$out = Ledger::record_once(
					$from,
					-$points,
					'debit',
					'transfer_out',
					$ref,
					array( 'note' => $note, 'created_by' => $created_by, 'meta' => array( 'to' => $to ) )
				);
				if ( ! $out ) {
					return new WP_Error( 'moksafopoi_transfer_failed', __( 'Transfer failed; please try again later.', 'moksa-points-for-woocommerce' ) );
				}

				$in = Ledger::record_once(
					$to,
					$points,
					'earn',
					'transfer_in',
					$ref,
					array( 'note' => $note, 'created_by' => $created_by, 'meta' => array( 'from' => $from ) )
				);
				if ( ! $in ) {
					// Compensate the sender so points can never vanish if the credit leg fails.
					Ledger::record_once(
						$from,
						$points,
						'earn',
						'transfer_reversal',
						$ref,
						array( 'note' => __( 'Auto-refund on failed transfer', 'moksa-points-for-woocommerce' ), 'created_by' => $created_by )
					);
					return new WP_Error( 'moksafopoi_transfer_failed', __( 'Transfer failed; your points have been refunded.', 'moksa-points-for-woocommerce' ) );
				}

				/**
				 * A member-to-member points transfer completed.
				 *
				 * @param int    $from
				 * @param int    $to
				 * @param int    $points
				 * @param string $ref
				 */
				do_action( 'moksafopoi_points_transferred', $from, $to, $points, $ref );

				return array(
					'ref'     => $ref,
					'points'  => $points,
					'to'      => $to,
					'balance' => Api::get_points( $from ),
				);
			}
		);

		return $result;
	}
}
