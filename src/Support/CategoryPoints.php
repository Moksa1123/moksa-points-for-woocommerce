<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Per-category earn multiplier, stored as `product_cat` taxonomy TERM META (never a CPT, never an
 * option). The admin sets「此分類點數倍率」on the category add/edit screen; SpendRules reads it per
 * line item so a product in a 2× category earns double on that line.
 *
 * Term meta key: `_moksafopoi_cat_earn_multiplier` (float, default 1.0). A product may sit in
 * several categories — the read side takes the MAX multiplier across them (the most generous wins),
 * so overlapping campaigns never stack into an absurd product. Clamped to a sane ceiling.
 */
final class CategoryPoints {

	public const META_MULTIPLIER = '_moksafopoi_cat_earn_multiplier';

	/** Hard ceiling on any single category multiplier (defence against fat-finger / runaway awards). */
	public const MAX_MULTIPLIER = 10.0;

	/**
	 * The earn multiplier for one category term (default 1.0, clamped to (0 .. MAX]).
	 */
	public static function term_multiplier( int $term_id ): float {
		if ( $term_id <= 0 ) {
			return 1.0;
		}
		$raw = get_term_meta( $term_id, self::META_MULTIPLIER, true );
		if ( '' === $raw || null === $raw || false === $raw ) {
			return 1.0;
		}
		return self::clamp( (float) $raw );
	}

	/**
	 * The multiplier to apply to a product's earn line: the MAX category multiplier across every
	 * `product_cat` the product belongs to (variations inherit from the parent). Defaults to 1.0
	 * when the product has no category or no category sets an override.
	 *
	 * @param int $product_id WC product (or variation) id.
	 */
	public static function product_multiplier( int $product_id ): float {
		$product = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return 1.0;
		}
		$parent  = (int) $product->get_parent_id();
		$lookup  = $parent > 0 ? $parent : $product_id;
		$term_ids = wp_get_post_terms( $lookup, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) || array() === $term_ids ) {
			return 1.0;
		}

		$best = 1.0;
		foreach ( $term_ids as $term_id ) {
			$best = max( $best, self::term_multiplier( (int) $term_id ) );
		}

		/**
		 * Filter the resolved per-product category multiplier.
		 *
		 * @param float $best       The MAX category multiplier (>=1.0, clamped).
		 * @param int   $product_id The looked-up product id.
		 */
		return self::clamp( (float) apply_filters( 'moksafopoi_category_multiplier', $best, $product_id ) );
	}

	/** Clamp a multiplier to (0 .. MAX_MULTIPLIER]; non-positive / invalid falls back to 1.0. */
	public static function clamp( float $value ): float {
		if ( $value <= 0.0 ) {
			return 1.0;
		}
		return min( self::MAX_MULTIPLIER, $value );
	}
}
