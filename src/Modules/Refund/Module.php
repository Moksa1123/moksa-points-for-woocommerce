<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Refund;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 退款回補(扣回已賺點數) — when an order is cancelled or refunded, claw back the loyalty points that
 * the purchase awarded, so a refunded order does not leave the customer holding points they should no
 * longer have. The counterpart flows are owned by their own modules and are intentionally NOT touched
 * here: {@see \Moksafopoi\Modules\CheckoutRedeem\Module} restores points SPENT at checkout,
 * {@see \Moksafopoi\Modules\GiftCard\Module} / Cashback reverse their store-credit grants.
 *
 * This module reverses only the EARN rows (ledger type `earn`) booked against the order — the spend
 * points. The clawback is idempotent (order-meta marker + the ledger UNIQUE ref) and clamped to the
 * customer's current balance so a customer who already spent the points is never driven negative.
 * No CPT / table: it reads the shared {@see Ledger} and writes one offsetting debit.
 */
final class Module extends AbstractModule {

	private const ORDER_MARK  = '_moksafopoi_earn_clawed';
	private const CLAWED_META = '_moksafopoi_earn_clawed_pts';

	public function slug(): string {
		return 'refund';
	}

	public function label(): string {
		return __( 'Refund claw-back (claw back earned points)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'spend';
	}

	public function tagline(): string {
		return __( 'When an order is canceled / refunded, claw back the points issued for that purchase (clamped so the balance does not go negative); works alongside checkout redemption refunds and store credit refunds.', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Cancellation has no refund object → full clawback. Refunds (partial OR full) fire
		// woocommerce_order_refunded → proportional clawback; this replaces the old status-only
		// woocommerce_order_status_refunded hook, which never fired on a PARTIAL refund (F3).
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'clawback' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_refunded' ), 10, 2 );
	}

	/** Cancellation: claw back the full earned points (no refund object exists). */
	public static function clawback( $order_id ): void {
		self::reverse_earned( (int) $order_id, 1.0 );
	}

	/**
	 * Refund (partial or full): claw back earned points in proportion to the cumulative refunded amount.
	 *
	 * @param int $order_id
	 * @param int $refund_id
	 */
	public static function on_refunded( $order_id, $refund_id = 0 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$total    = (float) $order->get_total();
		$fraction = $total > 0 ? ( (float) $order->get_total_refunded() / $total ) : 1.0;
		self::reverse_earned( (int) $order_id, $fraction );
	}

	/**
	 * Claw back earned points to match the refunded fraction, cumulatively and idempotently. Books the
	 * FULL owed reversal even if it drives the balance negative (a recoverable debt) — points already
	 * spent or transferred before the refund can no longer escape the clawback (F5). Successive partial
	 * refunds each book only the newly-owed delta.
	 */
	private static function reverse_earned( int $order_id, float $fraction ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$earned = self::earned_points( $order_id, $user_id );
		if ( $earned <= 0 ) {
			$order->update_meta_data( self::ORDER_MARK, '1' ); // nothing to claw; mark to avoid re-scanning.
			$order->save();
			return;
		}

		$fraction = max( 0.0, min( 1.0, $fraction ) );
		$target   = (int) round( $earned * $fraction ); // cumulative points that should be clawed by now.
		$already  = (int) $order->get_meta( self::CLAWED_META );
		$delta    = $target - $already;
		if ( $delta <= 0 ) {
			return; // replay / earlier partial already covered this — idempotent no-op.
		}

		Ledger::record_once(
			$user_id,
			-$delta,
			'earn_clawback',
			'earn_clawback',
			'earn_clawback:' . $order_id . ':' . $target, // ref advances with the cumulative target so each new partial books once.
			array(
				'order_id' => $order_id,
				'note'     => __( 'Claw back points earned on purchase', 'moksa-points-for-woocommerce' ),
				'meta'     => array( 'earned' => $earned, 'fraction' => $fraction ),
			)
		);

		$order->update_meta_data( self::CLAWED_META, (string) $target );
		if ( $target >= $earned ) {
			$order->update_meta_data( self::ORDER_MARK, '1' );
		}
		$order->save();
	}

	/**
	 * Sum of the positive `earn` points this order awarded (spend-rule / coupon earn carry order_id).
	 * Reversal / adjust / store-credit rows are excluded by the type filter.
	 */
	private static function earned_points( int $order_id, int $user_id ): int {
		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, user values bound via $wpdb->prepare().
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE order_id = %d AND user_id = %d AND type = 'earn' AND points_delta > 0",
				$order_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return max( 0, (int) $sum );
	}
}
