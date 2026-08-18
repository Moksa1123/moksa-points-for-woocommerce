<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Engage;

use Moksafopoi\Support\Label;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 即時賺點吐司 — the single "you earned +N points" confirmation on the order-received page.
 *
 * The figure is summed straight off the LEDGER rows this order actually booked, so it covers every
 * earn module at once (spend / campaign / first-order / cashback…) and can never congratulate a
 * customer for points that were not written. Nothing booked yet (deferred payment, award-on-shipping)
 * → nothing rendered.
 *
 * This is the one implementation: 前台獎勵中心浮窗 (RewardHub) delegates here, and {@see rendered()}
 * makes a double-hook a no-op when both modules are switched on.
 */
final class Toast {

	/** Guard so the toast prints once even when two modules hook the thank-you page. */
	private static bool $rendered = false;

	/**
	 * Render the toast for an order.
	 *
	 * @param int|string $order_id The order just placed.
	 */
	public static function render_thankyou( $order_id ): void {
		if ( self::$rendered ) {
			return;
		}

		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$earned = self::earned_for_order( $order_id );
		if ( $earned <= 0 ) {
			return;
		}

		self::$rendered = true;

		echo '<div class="moksafopoi-toast woocommerce-message" role="status">'
			. esc_html(
				sprintf(
					/* translators: %s: the points just earned, with unit, e.g.「120 點」. */
					__( '🎉 You earned %s from this purchase!', 'moksa-points-for-woocommerce' ),
					Label::format( $earned )
				)
			)
			. '</div>';
	}

	/** Has the toast already been printed on this request? */
	public static function rendered(): bool {
		return self::$rendered;
	}

	/** Total positive points the ledger booked for this order (0 when nothing was awarded). */
	private static function earned_for_order( int $order_id ): int {
		global $wpdb;
		$table = Schema::ledger_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (name from Schema::ledger_table()); interpolated part is static SQL, order id bound via $wpdb->prepare().
		$earned = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points_delta),0) FROM {$table} WHERE order_id = %d AND points_delta > 0", $order_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $earned;
	}
}
