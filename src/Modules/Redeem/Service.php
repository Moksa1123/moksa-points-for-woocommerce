<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Redeem;

use Moksafopoi\Api;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\Schema;
use Moksafopoi\Support\UserLock;

defined( 'ABSPATH' ) || exit;

/**
 * Spend points on a catalog reward. The headline reward kind is 'coupon' — points become a
 * real WooCommerce coupon, MINTED BY moforcoupon (this plugin never creates coupon logic): it
 * asks moforcoupon's PersonalCoupon::issue to clone a template coupon, locked to the customer.
 * Zero hard dep — if moforcoupon is absent the redemption fails cleanly and the points are
 * refunded. Points are deducted through the idempotent ledger; a failed fulfilment refunds.
 */
final class Service {

	/**
	 * @param array<string,mixed> $args  Expects reward_id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function redeem( int $user_id, string $reward_kind, array $args ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'moksafopoi_no_user', __( 'Please log in before redeeming.', 'moksa-points-for-woocommerce' ) );
		}
		$reward = self::get_reward( (int) ( $args['reward_id'] ?? 0 ) );
		if ( null === $reward ) {
			return new \WP_Error( 'moksafopoi_no_reward', __( 'Redemption item not found.', 'moksa-points-for-woocommerce' ) );
		}
		if ( 1 !== (int) $reward['active'] ) {
			return new \WP_Error( 'moksafopoi_reward_off', __( 'This redemption item is disabled.', 'moksa-points-for-woocommerce' ) );
		}
		if ( 'code' === (string) $reward['kind'] ) {
			// 兌點碼是「領點」不是「花點」— 只能走會員中心的輸入代碼表單,不走扣點兌換。
			return new \WP_Error( 'moksafopoi_wrong_lane', __( 'This is a redemption code; please enter the code in the "Enter redemption code" field in My Account.', 'moksa-points-for-woocommerce' ) );
		}
		if ( 0 === (int) $reward['stock'] ) {
			return new \WP_Error( 'moksafopoi_reward_out', __( 'This redemption item is sold out.', 'moksa-points-for-woocommerce' ) );
		}

		// Serialise the affordability-check + debit per user (GET_LOCK). Without this, concurrent
		// redemptions each read the same balance, each pass can_afford, and each commit a distinct-ref
		// debit — minting N rewards for one balance. The random ref is retained so legitimate repeat
		// redemptions still work; the lock (not the ref) is what makes check-then-debit atomic. Mirrors
		// Transfer/BuyWithPoints. UserLock returns WP_Error when contended, so a racing request is refused.
		return UserLock::with(
			$user_id,
			static function () use ( $user_id, $reward ) {
				$cost = (int) $reward['cost_points'];
				if ( ! Api::can_afford( $user_id, $cost ) ) {
					return new \WP_Error( 'moksafopoi_insufficient', __( 'Insufficient points.', 'moksa-points-for-woocommerce' ) );
				}

				$kind    = (string) $reward['kind'];
				$payload = json_decode( (string) $reward['payload'], true );
				$payload = is_array( $payload ) ? $payload : array();

				// Per-user redemption limit (stored in the reward payload; 0 = unlimited). Counted inside
				// the per-user lock so concurrent redemptions cannot both slip past the cap.
				$per_user = isset( $payload['per_user_limit'] ) ? (int) $payload['per_user_limit'] : 0;
				if ( $per_user > 0 && self::count_user_redemptions( $user_id, (int) $reward['id'] ) >= $per_user ) {
					return new \WP_Error( 'moksafopoi_reward_limit', __( 'You have reached the redemption limit for this reward.', 'moksa-points-for-woocommerce' ) );
				}

				$ref = 'reward:' . (int) $reward['id'] . ':' . wp_generate_uuid4();

				// Deduct first (idempotent on the unique ref); refund if fulfilment fails.
				$deducted = Ledger::record_once(
					$user_id,
					-$cost,
					'redeem',
					'redeem_' . $kind,
					$ref,
					array(
						'note' => sprintf(
							/* translators: %s: reward label. */
							__( 'Redeem: %s', 'moksa-points-for-woocommerce' ),
							(string) $reward['label']
						),
						'meta' => array( 'reward_id' => (int) $reward['id'] ),
					)
				);
				if ( ! $deducted ) {
					return new \WP_Error( 'moksafopoi_deduct_failed', __( 'Failed to deduct points, please try again later.', 'moksa-points-for-woocommerce' ) );
				}

				$result = self::fulfil( $kind, $payload, $user_id );
				if ( is_wp_error( $result ) ) {
					Ledger::record_once(
						$user_id,
						$cost,
						'adjust',
						'redeem_refund',
						$ref,
						array( 'note' => __( 'Redemption failed, points have been refunded.', 'moksa-points-for-woocommerce' ) )
					);
					return $result;
				}

				self::decrement_stock( (int) $reward['id'] );
				do_action( 'moksafopoi_points_redeemed', $user_id, $cost, $kind, array( 'reward_id' => (int) $reward['id'], 'result' => $result ) );

				return array_merge(
					array(
						'ok'           => true,
						'cost'         => $cost,
						'points_after' => Api::get_points( $user_id ),
					),
					$result
				);
			}
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function fulfil( string $kind, array $payload, int $user_id ) {
		if ( 'coupon' === $kind ) {
			if ( ! class_exists( '\\MoksaWeb\\Moforcoupon\\Support\\PersonalCoupon' ) ) {
				return new \WP_Error( 'moksafopoi_no_coupon_plugin', __( 'The coupon plugin is not active, so coupons cannot be redeemed right now.', 'moksa-points-for-woocommerce' ) );
			}
			$template    = (string) ( $payload['template'] ?? '' );
			$template_id = ctype_digit( $template ) ? (int) $template : (int) wc_get_coupon_id_by_code( $template );
			if ( $template_id <= 0 ) {
				return new \WP_Error( 'moksafopoi_no_template', __( 'The template coupon used for redemption does not exist.', 'moksa-points-for-woocommerce' ) );
			}
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
			$new   = \MoksaWeb\Moforcoupon\Support\PersonalCoupon::issue(
				$template_id,
				(string) ( $payload['prefix'] ?? 'PTS' ),
				$user_id,
				$email,
				(int) ( $payload['expiry_days'] ?? 0 )
			);
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$coupon = new \WC_Coupon( (int) $new );
			return array(
				'kind'        => 'coupon',
				'coupon_id'   => (int) $new,
				'coupon_code' => $coupon->get_code(),
			);
		}

		if ( 'product' === $kind ) {
			return self::fulfil_product( $payload, $user_id );
		}

		if ( 'cart_discount' === $kind ) {
			// Convert the redeemed points into store credit of a fixed face value (payload.amount),
			// which the customer then applies at checkout via the wallet. Self-contained: no coupon
			// plugin needed. The points were already deducted by the caller; this grants the credit.
			$amount = isset( $payload['amount'] ) ? round( (float) $payload['amount'], 2 ) : 0.0;
			if ( $amount <= 0 ) {
				return new \WP_Error( 'moksafopoi_bad_discount', __( 'This discount reward is misconfigured (no amount).', 'moksa-points-for-woocommerce' ) );
			}
			$ok = Ledger::record_once(
				$user_id,
				0,
				'redeem',
				'reward_credit',
				'reward_credit:' . wp_generate_uuid4(),
				array(
					'amount_delta' => $amount,
					'note'         => __( 'Redeemed points for store credit', 'moksa-points-for-woocommerce' ),
				)
			);
			if ( ! $ok ) {
				return new \WP_Error( 'moksafopoi_credit_failed', __( 'Failed to grant the store credit, please try again later.', 'moksa-points-for-woocommerce' ) );
			}
			return array( 'kind' => 'cart_discount', 'amount' => $amount );
		}

		return new \WP_Error( 'moksafopoi_unknown_reward', __( 'Unsupported redemption type.', 'moksa-points-for-woocommerce' ) );
	}

	/**
	 * Public reuse hook for the NT$0 gift-order builder — used by the「純點數購買整件商品」module so it
	 * shares this plugin's single, refund-safe, HPOS-safe order-creation path instead of duplicating it.
	 * Returns the same {kind,order_id} / WP_Error contract as {@see fulfil_product()}.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function build_points_order( int $product_id, int $user_id, string $note = '' ) {
		return self::fulfil_product(
			array(
				'product_id' => $product_id,
				'note'       => $note,
			),
			$user_id
		);
	}

	/**
	 * Fulfil a physical-gift reward: build a NT$0 WooCommerce order (the gift product at price 0)
	 * for the redeeming member, mark it 待出貨 (processing) and note it as a points redemption.
	 * Pure CRUD — HPOS-safe, never touches posts/meta tables directly. Returns a WP_Error on any
	 * failure so {@see redeem()} runs its idempotent point refund; nothing here is committed unless
	 * the order saves cleanly.
	 *
	 * @param array<string,mixed> $payload Expects product_id and optional note.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function fulfil_product( array $payload, int $user_id ) {
		if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'moksafopoi_no_wc', __( 'WooCommerce is not active, so physical gifts cannot be redeemed right now.', 'moksa-points-for-woocommerce' ) );
		}

		$product_id = (int) ( $payload['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return new \WP_Error( 'moksafopoi_no_product', __( 'The gift product used for redemption is not set.', 'moksa-points-for-woocommerce' ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error( 'moksafopoi_bad_product', __( 'The gift product used for redemption does not exist.', 'moksa-points-for-woocommerce' ) );
		}

		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'moksafopoi_order_failed', __( 'Failed to create the gift order; please try again later.', 'moksa-points-for-woocommerce' ) );
		}

		try {
			// Add the gift at price 0 (subtotal/total zeroed) so the order is a NT$0 gift, not a sale.
			$item_id = $order->add_product(
				$product,
				1,
				array(
					'subtotal' => 0,
					'total'    => 0,
				)
			);
			if ( ! $item_id ) {
				return self::cancel_order( $order, 'moksafopoi_add_product_failed', __( 'Could not add the gift to the order.', 'moksa-points-for-woocommerce' ) );
			}

			// Bill the order to the member so it shows in their account + notifications resolve.
			self::set_order_address( $order, $user_id );

			$order->set_created_via( 'moksafopoi_redeem' );
			$order->calculate_totals( false );
			$order->set_total( 0 ); // Gift: zero out any tax/shipping the calc may have added.

			$note = sprintf(
				/* translators: %s: optional operator note appended to the order. */
				__( 'Redeem points for a physical gift. %s', 'moksa-points-for-woocommerce' ),
				(string) ( $payload['note'] ?? '' )
			);
			$order->add_order_note( trim( $note ) );

			// 待出貨。set_status + save persists atomically via CRUD (HPOS-safe).
			$order->update_status( 'processing', __( 'Redeemed points for a physical gift, pending shipment.', 'moksa-points-for-woocommerce' ), true );
			$order->save();
		} catch ( \Throwable $e ) {
			return self::cancel_order( $order, 'moksafopoi_order_exception', __( 'An error occurred while creating the gift order; please try again later.', 'moksa-points-for-woocommerce' ) );
		}

		return array(
			'kind'     => 'product',
			'order_id' => (int) $order->get_id(),
		);
	}

	/**
	 * Best-effort cleanup of a half-built gift order before returning the failure that triggers the
	 * point refund, so a failed redemption never leaves an orphan order lying around.
	 */
	private static function cancel_order( \WC_Order $order, string $code, string $message ): \WP_Error {
		try {
			$order->update_status( 'failed', __( 'Failed to create the gift order; it has been canceled.', 'moksa-points-for-woocommerce' ) );
			$order->save();
		} catch ( \Throwable $e ) {
			// Swallow — the caller's refund is what matters; an orphan failed order is harmless.
			unset( $e );
		}
		return new \WP_Error( $code, $message );
	}

	/** Copy the member's saved account name/email onto the order (CRUD; no direct meta writes). */
	private static function set_order_address( \WC_Order $order, int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );
		$order->set_billing_first_name( '' !== $first ? $first : $user->display_name );
		$order->set_billing_last_name( $last );
		$order->set_billing_email( (string) $user->user_email );
	}

	/** @return array<string,mixed>|null */
	/** Count how many times a user has already redeemed a specific reward (matched via the ledger ref prefix). */
	private static function count_user_redemptions( int $user_id, int $reward_id ): int {
		global $wpdb;
		$table = Schema::ledger_table();
		$like  = 'reward:' . $reward_id . ':%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal ledger table (Schema::ledger_table()); the user id + LIKE value are bound via $wpdb->prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND type = 'redeem' AND source_ref LIKE %s", $user_id, $like ) );
	}

	private static function get_reward( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); id bound via $wpdb->prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function decrement_stock( int $id ): void {
		global $wpdb;
		$table = Schema::rewards_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- internal rewards table (name from Schema::rewards_table()); id bound via $wpdb->prepare(). -1 = unlimited, never decrements below 0.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET stock = stock - 1 WHERE id = %d AND stock > 0", $id ) );
	}
}
