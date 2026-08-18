<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\SpendRules;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Modules\Ledger\Ledger;
use Moksafopoi\Support\CategoryPoints;
use Moksafopoi\Support\Multipliers;
use Moksafopoi\Support\ProductPoints;

defined( 'ABSPATH' ) || exit;

/**
 * 消費集點 — earn points on a paid order. The Taiwan-native base loyalty rule: roughly
 * 1 點 per NT$1 of the goods total, floor-rounded to whole points (house-safe). Members
 * declare a tier multiplier through the `moksafopoi_earn_multiplier` filter (moformember
 * returns e.g. VIP ×1.5) — this module never reads tier directly.
 *
 * Idempotent + replay-safe: keyed on the order id, credited only when payment actually
 * lands (processing/completed), so deferred ATM / 超商代碼 callbacks never double-credit.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'spendrules';
	}

	public function label(): string {
		return __( 'Earn points on purchase', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Add points based on the amount spent after order payment (default 1 point / NT$1, applies the membership multiplier).', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'woocommerce_order_status_processing', array( self::class, 'earn' ) );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'earn' ) );

		// Storefront「購買可得約 X 點」preview on the single-product page (opt-out via option).
		if ( ! is_admin() && 'no' !== get_option( 'moksafopoi_show_earn_preview', 'yes' ) ) {
			EarnPreview::init();
		}
	}

	public static function earn( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return; // Guests do not accrue points (no account to hold them).
		}

		// Currency-aware: a multi-currency shop hands us an order total in the CUSTOMER's currency,
		// so the rate has to be the one for that currency, not the base one.
		$rate          = \Moksafopoi\Support\Rates::earn_rate( $order );
		$exclude_sale  = 'yes' === get_option( 'moksafopoi_earn_exclude_sale', 'no' );
		// Earn-base composition (default OFF → goods-only, the current behaviour). When enabled, the
		// order's shipping / tax totals join the earn base at the site rate (no category multiplier,
		// since shipping & tax belong to no product category).
		$include_ship  = 'yes' === get_option( 'moksafopoi_earn_include_shipping', 'no' );
		$include_tax   = 'yes' === get_option( 'moksafopoi_earn_include_tax', 'no' );
		// Tier multiplier (e.g. moformember VIP ×1.5). Kept as the canonical sibling-facing filter.
		$tier_mult     = (float) apply_filters( 'moksafopoi_earn_multiplier', 1.0, $user_id, $order );

		// Walk line items so per-product overrides (fixed per-item points, no-earn, sale exclusion)
		// and per-CATEGORY multipliers apply. $line_points = points already multiplied by the line's
		// category multiplier (so a 2× category earns double on that line). We read $item->get_total()
		// (already net of any coupon discount), so we never earn on the pre-discount price.
		//
		// We also accumulate $earn_base (the un-multiplied rate-base + override points) purely to
		// decide the spend-tier (滿額加倍) bonus below — the bonus is judged on the raw earn base,
		// before any multiplier inflates it.
		$line_points = 0.0;
		$earn_base   = 0.0;
		$gross_base  = 0.0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$product_id = (int) $item->get_product_id();
			$qty        = (int) $item->get_quantity();

			if ( ProductPoints::no_earn( $product_id ) ) {
				continue; // Excluded product — its spend never counts.
			}
			if ( $exclude_sale && $product->is_on_sale() ) {
				continue; // Sale items excluded by the global switch.
			}

			$line_total = (float) $item->get_total(); // After line-level coupon discounts.
			$gross_base += $line_total;

			$cat_mult = CategoryPoints::product_multiplier( $product_id );

			$override = ProductPoints::earn_override( $product_id );
			if ( null !== $override ) {
				$base         = (float) $override * max( 0, $qty );
				$earn_base   += $base;
				$line_points += $base * $cat_mult; // Fixed per-item award, scaled by the category.
				continue;
			}

			$base         = $line_total * $rate;
			$earn_base   += $base;
			$line_points += $base * $cat_mult;
		}

		// Optionally fold shipping and/or tax into the earn base at the plain site rate (category
		// multiplier = 1.0 — they belong to no product). Both default OFF, preserving goods-only earn.
		if ( $include_ship ) {
			$ship_total  = (float) $order->get_shipping_total(); // Net of any shipping discount, ex-tax.
			$ship_base   = max( 0.0, $ship_total ) * $rate;
			$earn_base  += $ship_base;
			$line_points += $ship_base;
			$gross_base += max( 0.0, $ship_total );
		}
		if ( $include_tax ) {
			$tax_total   = (float) $order->get_total_tax(); // All tax on the order (goods + shipping).
			$tax_base    = max( 0.0, $tax_total ) * $rate;
			$earn_base  += $tax_base;
			$line_points += $tax_base;
			$gross_base += max( 0.0, $tax_total );
		}

		$earn_base   = max( 0.0, $earn_base );
		$line_points = max( 0.0, $line_points );

		// Order-level multipliers (NOT per-line): the member's highest role multiplier and the
		// spend-tier (滿額加倍) bonus judged on the raw earn base. Final stacking order is explicit:
		//   final multiplier = tier(filter) × role × spend-bonus   (category was applied per line).
		// Every factor is individually clamped (≤10) in its resolver, so the product can never explode.
		$role_mult   = Multipliers::role_multiplier( $user_id );
		$bonus_mult  = Multipliers::spend_bonus( $earn_base );
		$multiplier  = $tier_mult * $role_mult * $bonus_mult;

		$raw    = $line_points * $multiplier;
		$points = self::round_points( $raw );

		// Single-order earn cap (0 = unlimited).
		$max = (int) get_option( 'moksafopoi_earn_max_per_order', 0 );
		if ( $max > 0 && $points > $max ) {
			$points = $max;
		}

		if ( $points <= 0 ) {
			return;
		}

		$written = Ledger::record_once(
			$user_id,
			$points,
			'earn',
			'spend_rule',
			(string) $order_id,
			array(
				'order_id' => $order_id,
				'note'     => __( 'Earn points on purchase', 'moksa-points-for-woocommerce' ),
				'meta'     => array(
					'earn_base'    => $earn_base,
					'gross_base'   => $gross_base,
					'line_points'  => $line_points,
					'rate'         => $rate,
					'tier_mult'    => $tier_mult,
					'role_mult'    => $role_mult,
					'bonus_mult'   => $bonus_mult,
					'multiplier'   => $multiplier,
					'capped_at'    => $max,
					'include_ship' => $include_ship,
					'include_tax'  => $include_tax,
				),
				'expires_at' => self::expiry_date(),
			)
		);

		// Leave an order footprint (order note + machine-readable meta) so external reports/exports,
		// sibling plugins and the HPOS order screen can see points EARNED for this order — parity with
		// the redeem / wallet / cashback marks, which all stamp the order. Only on the first (idempotent)
		// write; the ledger UNIQUE key already prevents double-award on replayed status hooks.
		if ( $written && $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: number of points earned on the order. */
					__( 'Earned %s points on this purchase.', 'moksa-points-for-woocommerce' ),
					number_format_i18n( $points )
				)
			);
			$order->update_meta_data( '_moksafopoi_earned_points', (string) $points );
			$order->save();
		}
	}

	/**
	 * Round a raw points figure exactly as the earn engine does (floor by default; option allows
	 * round/ceil). Public so the single-product earn preview rounds identically and never diverges
	 * from what the order will actually award.
	 */
	public static function round_points_public( float $raw ): int {
		return self::round_points( $raw );
	}

	/** Floor by default (house-safe); option allows round/ceil. */
	private static function round_points( float $raw ): int {
		// `moksafopoi_earn_rounding` is the canonical key; fall back to the legacy `moksafopoi_rounding`.
		$mode = (string) get_option( 'moksafopoi_earn_rounding', (string) get_option( 'moksafopoi_rounding', 'floor' ) );
		switch ( $mode ) {
			case 'round':
				return (int) round( $raw );
			case 'ceil':
				return (int) ceil( $raw );
			default:
				return (int) floor( $raw );
		}
	}

	/** Expiry stamp for earned points, honouring the configured window (0 = never). */
	private static function expiry_date(): ?string {
		$months = (int) get_option( 'moksafopoi_points_expire_months', 0 );
		if ( $months <= 0 ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', time() + $months * MONTH_IN_SECONDS );
	}
}
