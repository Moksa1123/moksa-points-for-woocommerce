<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\GiftCard;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * 儲值商品(禮品卡 / 點數包)— turn any WooCommerce product into stored value the buyer receives
 * on payment: a GIFT CARD tops up store credit (the currency lane), a POINTS PACK tops up points
 * (the points lane). Together they are "buy credits with money" — the buyCred feature every rival
 * charges for — without a single new post type or table.
 *
 * 禮品卡儲值 — turn any WooCommerce product into a store-credit gift card. The shop owner ticks
 * 「此商品為禮品卡」on a product (product-meta `_moksafopoi_giftcard`), optionally with a fixed
 * 面額; when an order containing that product is paid, the buyer's store-credit balance is topped up
 * by the face value × quantity through the idempotent ledger (currency lane). A later refund reverses
 * the top-up once.
 *
 * No CPT, no custom table: the "is a gift card" flag + face value live on the product's post-meta,
 * the credited value lives in the shared {@see Ledger} (amount_delta lane), and idempotency is keyed
 * on the order line so a status flip / cron replay never double-credits. Recipient delivery (gifting
 * to someone else) is a later enhancement; v1 tops up the purchasing customer's own wallet.
 *
 * Points packs follow the identical, already-proven path (per-line idempotency ref, reversal on
 * cancel/refund). They are booked as type `topup`, NOT `earn`, so purchased points are spendable but
 * do NOT inflate "lifetime earned" — a member cannot buy their way up the tier ladder unless the shop
 * opts in with `moksafopoi_topup_counts_toward_tier`.
 */
final class Module extends AbstractModule {

	private const PRODUCT_FLAG   = '_moksafopoi_giftcard';
	private const PRODUCT_AMOUNT = '_moksafopoi_giftcard_amount';
	private const ORDER_MARK     = '_moksafopoi_giftcard_credited';

	/** Points-pack lane: the flag and the points granted per unit. */
	private const PACK_FLAG   = '_moksafopoi_pointspack';
	private const PACK_POINTS = '_moksafopoi_pointspack_points';

	/** Ceiling on a single points-pack line, so a mistyped product cannot mint a fortune. */
	private const MAX_PACK_POINTS = 1000000;

	public function slug(): string {
		return 'giftcard';
	}

	public function label(): string {
		return __( 'Stored-value products (gift card / points pack)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Mark a product as a gift card (adds its face value to store credit) or as a points pack (adds points) on purchase; refunds reverse it automatically.', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		// Admin: a「此商品為禮品卡」toggle + 面額 field on the product General tab.
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render_product_fields' ) );
			add_action( 'woocommerce_process_product_meta', array( self::class, 'save_product_fields' ) );
		}

		// Top up on payment. `completed` is the safe grant point for a stored-value product; `processing`
		// is also honoured so virtual gift cards (auto-complete off) still credit on payment.
		add_action( 'woocommerce_order_status_completed', array( self::class, 'credit_order' ) );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'credit_order' ) );

		// Reverse the top-up if the order is later cancelled / refunded (idempotent).
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'reverse_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'reverse_order' ) );
	}

	/* ------------------------------------------------------------------ admin product fields */

	public static function render_product_fields(): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => self::PRODUCT_FLAG,
				'label'       => __( 'This product is a gift card', 'moksa-points-for-woocommerce' ),
				'description' => __( 'When checked, buying this product adds its face value to the customer\'s store credit balance (usable at checkout).', 'moksa-points-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::PRODUCT_AMOUNT,
				'label'             => __( 'Gift card face value (NT$)', 'moksa-points-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Leave empty or 0 = use the product price as the top-up amount.', 'moksa-points-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '0.01',
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::PACK_FLAG,
				'label'       => __( 'This product is a points pack', 'moksa-points-for-woocommerce' ),
				'description' => __( 'When checked, buying this product adds the points below to the customer\'s balance.', 'moksa-points-for-woocommerce' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::PACK_POINTS,
				'label'             => __( 'Points granted per unit', 'moksa-points-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'How many points one unit of this product grants. Purchased points are spendable but do not count towards tiers or badges by default.', 'moksa-points-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
	}

	public static function save_product_fields( int $post_id ): void {
		// Nonce is verified by WooCommerce's product save flow before this fires.
		$is_card = isset( $_POST[ self::PRODUCT_FLAG ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence-only checkbox, verified by WC product save.
		update_post_meta( $post_id, self::PRODUCT_FLAG, $is_card );

		$amount = isset( $_POST[ self::PRODUCT_AMOUNT ] ) ? (float) wp_unslash( $_POST[ self::PRODUCT_AMOUNT ] ) : 0.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by WC product save; value is cast to float (numeric sanitisation).
		update_post_meta( $post_id, self::PRODUCT_AMOUNT, max( 0.0, $amount ) );

		$is_pack = isset( $_POST[ self::PACK_FLAG ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- presence-only checkbox, verified by WC product save.
		update_post_meta( $post_id, self::PACK_FLAG, $is_pack );

		$points = isset( $_POST[ self::PACK_POINTS ] ) ? (int) wp_unslash( $_POST[ self::PACK_POINTS ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified by WC product save; value is cast to int (numeric sanitisation).
		update_post_meta( $post_id, self::PACK_POINTS, min( self::MAX_PACK_POINTS, max( 0, $points ) ) );
	}

	/* ------------------------------------------------------------------ credit / reverse */

	/** Whether a product is a gift card. */
	private static function is_gift_card( int $product_id ): bool {
		return $product_id > 0 && 'yes' === get_post_meta( $product_id, self::PRODUCT_FLAG, true );
	}

	/** Whether a product is a points pack. */
	private static function is_points_pack( int $product_id ): bool {
		return $product_id > 0 && 'yes' === get_post_meta( $product_id, self::PACK_FLAG, true );
	}

	/** The points one unit of a points pack grants (0 when unset / not a pack). */
	private static function unit_points( int $product_id ): int {
		if ( ! self::is_points_pack( $product_id ) ) {
			return 0;
		}
		return min( self::MAX_PACK_POINTS, max( 0, (int) get_post_meta( $product_id, self::PACK_POINTS, true ) ) );
	}

	/** The per-unit face value of a gift-card line: the fixed 面額, or the line's unit price. */
	private static function unit_value( int $product_id, \WC_Order_Item_Product $item ): float {
		$fixed = (float) get_post_meta( $product_id, self::PRODUCT_AMOUNT, true );
		if ( $fixed > 0 ) {
			return $fixed;
		}
		$qty = max( 1, (int) $item->get_quantity() );
		return max( 0.0, (float) $item->get_total() / $qty ); // ex-tax line price / qty.
	}

	/**
	 * Credit the buyer's store-credit balance for every gift-card line on the order, once per line.
	 *
	 * @param int $order_id
	 */
	public static function credit_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return; // guest purchase — no wallet to credit.
		}

		$credited = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			$quantity   = max( 1, (int) $item->get_quantity() );

			if ( self::is_gift_card( $product_id ) ) {
				$value = self::unit_value( $product_id, $item ) * $quantity;
				if ( $value > 0 ) {
					$ok = Ledger::record_once(
						$user_id,
						0,
						'giftcard',
						'giftcard',
						'giftcard:' . $order_id . ':' . (int) $item_id,
						array(
							'amount_delta' => $value,
							'order_id'     => $order_id,
							'note'         => __( 'Gift card top-up', 'moksa-points-for-woocommerce' ),
							'meta'         => array( 'product_id' => $product_id ),
						)
					);
					$credited = $credited || $ok;
				}
			}

			// Points pack: same path, points lane. Booked as `topup` (not `earn`) so it is exempt from
			// the earn cap — a purchase is not earning — and does not inflate lifetime-earned totals.
			$points = self::unit_points( $product_id ) * $quantity;
			if ( $points > 0 ) {
				$ok = Ledger::record_once(
					$user_id,
					$points,
					'topup',
					'pointspack',
					'pointspack:' . $order_id . ':' . (int) $item_id,
					array(
						'order_id' => $order_id,
						'note'     => __( 'Points pack purchase', 'moksa-points-for-woocommerce' ),
						'meta'     => array( 'product_id' => $product_id ),
					)
				);
				$credited = $credited || $ok;
			}
		}

		if ( $credited ) {
			$order->update_meta_data( self::ORDER_MARK, '1' );
			$order->save();
		}
	}

	/**
	 * Reverse the gift-card top-up once when the order is cancelled / refunded. Re-derives the same
	 * per-line value and books an offsetting debit (idempotent on the refund source_ref).
	 *
	 * @param int $order_id
	 */
	public static function reverse_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( '1' !== (string) $order->get_meta( self::ORDER_MARK ) ) {
			return; // nothing was credited for this order.
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			$quantity   = max( 1, (int) $item->get_quantity() );

			if ( self::is_gift_card( $product_id ) ) {
				$value = self::unit_value( $product_id, $item ) * $quantity;
				if ( $value > 0 ) {
					Ledger::record_once(
						$user_id,
						0,
						'giftcard_reverse',
						'giftcard_refund',
						'giftcard_refund:' . $order_id . ':' . (int) $item_id,
						array(
							'amount_delta' => -$value,
							'order_id'     => $order_id,
							'note'         => __( 'Gift card top-up reversal', 'moksa-points-for-woocommerce' ),
						)
					);
				}
			}

			$points = self::unit_points( $product_id ) * $quantity;
			if ( $points > 0 ) {
				Ledger::record_once(
					$user_id,
					-$points,
					'topup_reverse',
					'pointspack_refund',
					'pointspack_refund:' . $order_id . ':' . (int) $item_id,
					array(
						'order_id' => $order_id,
						'note'     => __( 'Points pack purchase reversal', 'moksa-points-for-woocommerce' ),
					)
				);
			}
		}
	}
}
