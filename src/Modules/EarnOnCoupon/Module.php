<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\EarnOnCoupon;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * 用券加點 — award bonus points when a customer redeems a coupon, by listening to
 * moforcoupon's always-on `moforcoupon_coupon_redeemed` event (the verified platform
 * integration: per coupon, per order, idempotent, fired on payment). This is the
 * cross-plugin loop — moforcoupon owns the coupon, moksafopoi owns the value.
 *
 * Degrades gracefully: if moforcoupon is absent the event never fires and this module is
 * simply dormant. Separate ledger source from the spend-rule base, so a coupon order can
 * legitimately earn both the base spend points AND a coupon-use bonus without colliding.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'earncoupon';
	}

	public function label(): string {
		return __( 'Add points on coupon use', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Add points when a customer uses a coupon (listens to moforcoupon\'s coupon_redeemed event)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// moforcoupon fires: ($code, WC_Order $order, int $user_id, float $discount, string $campaign).
		add_action( 'moforcoupon_coupon_redeemed', array( self::class, 'on_redeemed' ), 10, 5 );
	}

	/**
	 * @param mixed $order WC_Order.
	 */
	public static function on_redeemed( string $code, $order, int $user_id, float $discount, string $campaign ): void {
		if ( $user_id <= 0 || ! $order instanceof \WC_Order ) {
			return;
		}

		$bonus = self::bonus_points( $code, $campaign, $discount, $user_id, $order );
		if ( $bonus <= 0 ) {
			return;
		}

		Ledger::record_once(
			$user_id,
			$bonus,
			'earn',
			'coupon_redeemed',
			$order->get_id() . ':' . $code,
			array(
				'order_id' => $order->get_id(),
				'note'     => __( 'Add points on coupon use', 'moksa-points-for-woocommerce' ),
				'meta'     => array(
					'code'     => $code,
					'campaign' => $campaign,
				),
			)
		);
	}

	/**
	 * Bonus points for using a coupon. Default: a flat per-use bonus (option, default 0 =
	 * off). Filterable so a campaign can grant more (the rule-table CRUD lands in a later
	 * milestone; the filter is the extension seam today).
	 *
	 * @param mixed $order WC_Order.
	 */
	private static function bonus_points( string $code, string $campaign, float $discount, int $user_id, $order ): int {
		$flat = (int) get_option( 'moksafopoi_coupon_bonus_points', 0 );
		/**
		 * Filter the bonus points granted for a coupon redemption.
		 *
		 * @param int    $flat     Default flat bonus.
		 * @param string $code     Coupon code (lowercased).
		 * @param string $campaign Campaign tag.
		 * @param float  $discount Discount the coupon gave.
		 */
		return (int) max( 0, apply_filters( 'moksafopoi_coupon_bonus_points', $flat, $code, $campaign, $discount ) );
	}
}
