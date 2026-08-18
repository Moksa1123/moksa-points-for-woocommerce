<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\BuyWithPoints;

use Moksafopoi\Api;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Modules\Redeem\Service as RedeemService;
use Moksafopoi\Support\UserLock;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 純點數購買整件商品 — lets a shopper redeem a whole product for points from its own product page (SUMO's
 * "buy with points"). A product opts in with a per-product points price in meta `_moksafopoi_buyable_points`;
 * a redemption deducts that many points and builds a NT$0 WooCommerce order via the shared, refund-safe
 * {@see RedeemService::build_points_order()} path.
 *
 * The deduct + order build run inside a per-user {@see UserLock} so two concurrent redemptions cannot both
 * pass `can_afford` on the same balance (double-spend). A failed order build refunds the points idempotently.
 */
final class Service {

	public const META_KEY = '_moksafopoi_buyable_points';

	public static function enabled(): bool {
		return 'yes' === (string) get_option( 'moksafopoi_buywithpoints_enabled', 'no' );
	}

	/** The points price for a product (0 = not buyable with points). */
	public static function points_price( int $product_id ): int {
		if ( $product_id <= 0 ) {
			return 0;
		}
		return max( 0, (int) get_post_meta( $product_id, self::META_KEY, true ) );
	}

	/** Whether a product can be bought with points (module on + a positive price set). */
	public static function is_buyable( int $product_id ): bool {
		return self::enabled() && self::points_price( $product_id ) > 0;
	}

	/**
	 * Redeem a product for points. Returns a result array or a WP_Error. Concurrency-safe (per-user lock);
	 * a failed order build refunds the points.
	 *
	 * @return array{ok:bool,order_id:int,cost:int,points_after:int}|WP_Error
	 */
	public static function purchase( int $user_id, int $product_id ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'moksafopoi_bwp_off', __( 'Points-only purchase is not enabled.', 'moksa-points-for-woocommerce' ) );
		}
		if ( $user_id <= 0 ) {
			return new WP_Error( 'moksafopoi_bwp_guest', __( 'Please log in before redeeming.', 'moksa-points-for-woocommerce' ) );
		}
		$cost = self::points_price( $product_id );
		if ( $cost <= 0 ) {
			return new WP_Error( 'moksafopoi_bwp_not_buyable', __( 'This product cannot be redeemed with points.', 'moksa-points-for-woocommerce' ) );
		}
		if ( ! class_exists( RedeemService::class ) ) {
			return new WP_Error( 'moksafopoi_bwp_no_redeem', __( 'Redemption service is not ready.', 'moksa-points-for-woocommerce' ) );
		}

		$result = UserLock::with(
			$user_id,
			static function () use ( $user_id, $product_id, $cost ) {
				if ( ! Api::can_afford( $user_id, $cost ) ) {
					return new WP_Error( 'moksafopoi_bwp_insufficient', __( 'Insufficient points.', 'moksa-points-for-woocommerce' ) );
				}

				$ref = 'buyproduct:' . $product_id . ':' . wp_generate_uuid4();

				// Deduct first (idempotent on the unique ref); refund if the order build fails.
				$deducted = Ledger::record_once(
					$user_id,
					-$cost,
					'redeem',
					'buy_product',
					$ref,
					array(
						'note' => sprintf( /* translators: %d: product id. */ __( 'Points-only purchase #%d', 'moksa-points-for-woocommerce' ), $product_id ),
						'meta' => array( 'product_id' => $product_id ),
					)
				);
				if ( ! $deducted ) {
					return new WP_Error( 'moksafopoi_bwp_deduct_failed', __( 'Failed to deduct points, please try again later.', 'moksa-points-for-woocommerce' ) );
				}

				// NT$0 order with an explicit tax note — this is a points redemption, not a taxable sale.
				$tax_note = __( 'Points-only redemption, NT$0, not a sales transaction; handle invoices / tax per points-redemption rules.', 'moksa-points-for-woocommerce' );
				$order    = RedeemService::build_points_order( $product_id, $user_id, $tax_note );
				if ( is_wp_error( $order ) ) {
					Ledger::record_once(
						$user_id,
						$cost,
						'adjust',
						'buy_product_refund',
						$ref,
						array( 'note' => __( 'Redemption failed, points have been refunded.', 'moksa-points-for-woocommerce' ) )
					);
					return $order;
				}

				$order_id = (int) ( $order['order_id'] ?? 0 );

				/**
				 * A product was bought entirely with points.
				 *
				 * @param int $user_id
				 * @param int $product_id
				 * @param int $cost
				 * @param int $order_id
				 */
				do_action( 'moksafopoi_product_bought', $user_id, $product_id, $cost, $order_id );

				return array(
					'ok'           => true,
					'order_id'     => $order_id,
					'cost'         => $cost,
					'points_after' => Api::get_points( $user_id ),
				);
			}
		);

		return $result;
	}
}
