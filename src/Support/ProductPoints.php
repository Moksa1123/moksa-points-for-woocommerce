<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Per-product points overrides (stored as WC product meta — never a CPT). Read helpers used
 * by SpendRules (earn) and CheckoutRedeem (redeem-base eligibility). Meta keys:
 *
 *  _moksafopoi_earn_override  Fixed points awarded PER ITEM for this product (''/empty = use the
 *                              site-wide 點/NT$ rate). A numeric value (incl. 0) overrides.
 *  _moksafopoi_no_earn        'yes' → this product never earns points (excluded from the earn base).
 *  _moksafopoi_no_redeem      'yes' → this product's line total cannot be discounted with points.
 *
 * All three are plain post meta, so they ride WC product CRUD / import-export with zero schema.
 */
final class ProductPoints {

	public const META_EARN_OVERRIDE = '_moksafopoi_earn_override';
	public const META_NO_EARN       = '_moksafopoi_no_earn';
	public const META_NO_REDEEM     = '_moksafopoi_no_redeem';

	/** Resolve the product id that actually carries the meta (variations inherit from the parent). */
	private static function meta_product_id( int $product_id ): int {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return $product_id;
		}
		$parent = (int) $product->get_parent_id();
		return $parent > 0 ? $parent : $product_id;
	}

	/** Whether this product is barred from earning points. Gift cards never earn (see is_giftcard). */
	public static function no_earn( int $product_id ): bool {
		$pid = self::meta_product_id( $product_id );
		return 'yes' === get_post_meta( $pid, self::META_NO_EARN, true ) || self::is_giftcard( $pid );
	}

	/** Whether this product's line may NOT be paid down with points / store credit. */
	public static function no_redeem( int $product_id ): bool {
		$pid = self::meta_product_id( $product_id );
		return 'yes' === get_post_meta( $pid, self::META_NO_REDEEM, true ) || self::is_giftcard( $pid );
	}

	/**
	 * Gift-card products are always no-earn AND no-redeem. If a customer could pay for a fixed-face gift
	 * card with store credit / points, that would be a principal-neutral wash that still earned points
	 * (and, before the cashback-basis fix, cashback) each cycle — an unbounded mint (F2). Barring gift
	 * cards from both lanes breaks the recycle loop and stops points being minted on gift-card lines.
	 * The flag mirrors GiftCard\Module::PRODUCT_FLAG (kept as a literal to avoid a Support→module dep).
	 */
	private static function is_giftcard( int $product_id ): bool {
		return $product_id > 0 && 'yes' === get_post_meta( $product_id, '_moksafopoi_giftcard', true );
	}

	/**
	 * Fixed per-item points override, or null when none is set (fall back to the global rate).
	 * An explicit 0 is a valid override (this product earns nothing per item but is not "no_earn").
	 */
	public static function earn_override( int $product_id ): ?int {
		$raw = get_post_meta( self::meta_product_id( $product_id ), self::META_EARN_OVERRIDE, true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return max( 0, (int) $raw );
	}
}
