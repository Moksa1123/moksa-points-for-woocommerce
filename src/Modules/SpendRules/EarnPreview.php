<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\SpendRules;

use Moksafopoi\Support\CategoryPoints;
use Moksafopoi\Support\DisplayMessage;
use Moksafopoi\Support\Multipliers;
use Moksafopoi\Support\ProductPoints;

defined( 'ABSPATH' ) || exit;

/**
 * 商品頁賺點預覽 — shows「購買可得約 X <unit>」on the single-product page so a shopper sees the
 * reward before adding to cart. The estimate reuses the EXACT earn read-path as {@see Module::earn()}:
 *
 *   • {@see ProductPoints::no_earn()}        → no badge at all (this product never earns)
 *   • {@see ProductPoints::earn_override()}  → fixed per-item points instead of price × rate
 *   • the「特價品不賺點」global switch         → suppressed while the product is on sale
 *   • {@see CategoryPoints::product_multiplier()} → the same per-line category multiplier
 *   • {@see Multipliers::role_multiplier()}  → the logged-in member's role multiplier (filterable,
 *                                              so a moformember tier filter is honoured too)
 *   • {@see Module::round_points_public()}   → the same floor/round/ceil rounding the order uses
 *
 * It deliberately does NOT apply the spend-tier (滿額加倍) bonus — that depends on the whole order's
 * base, which a single product page cannot know — so the copy says「約」(approximately). Output is
 * fully escaped; nothing is echoed unless the SpendRules earn engine itself is enabled. No CPT.
 */
final class EarnPreview {

	/**
	 * 顯示客製化 位置 → WooCommerce hook 對應表 for the single-product teaser. Validated against this
	 * allow-list before hooking; an unknown value falls back to before_add_to_cart, so add_action() is
	 * only ever handed a vetted hook.
	 *
	 * @var array<string,string>
	 */
	private const POSITION_HOOKS = array(
		'before_add_to_cart' => 'woocommerce_before_add_to_cart_button',
		'after_summary'      => 'woocommerce_single_product_summary',
		'after_meta'         => 'woocommerce_product_meta_end',
	);

	/**
	 * Attach the preview to the single-product page at the operator-chosen position. Called from
	 * {@see Module::boot()} only when 消費集點 is enabled and the preview option is on.
	 */
	public static function init(): void {
		$position = self::position();

		if ( 'after_summary' === $position ) {
			// Late in the product summary column, after price / excerpt / add-to-cart.
			add_action( 'woocommerce_single_product_summary', array( self::class, 'render' ), 35 );
		} elseif ( 'after_meta' === $position ) {
			// After the product meta block (SKU / categories / tags).
			add_action( 'woocommerce_product_meta_end', array( self::class, 'render' ) );
		} else {
			// Default: right above the add-to-cart button.
			add_action( 'woocommerce_before_add_to_cart_button', array( self::class, 'render' ), 5 );
		}
	}

	/**
	 * The validated single-product display position. Reads the 顯示客製化 option
	 * (moksafopoi_disp_single_position) and falls back to the legacy moksafopoi_earn_preview_position
	 * for stores configured before this field existed; anything not in {@see POSITION_HOOKS} → default.
	 */
	private static function position(): string {
		$legacy = (string) get_option( 'moksafopoi_earn_preview_position', 'before_add_to_cart' );
		$choice = (string) get_option( 'moksafopoi_disp_single_position', $legacy );
		return isset( self::POSITION_HOOKS[ $choice ] ) ? $choice : 'before_add_to_cart';
	}

	/** Echo the「購買可得約 X <unit>」line for the current product (escaped; silent when N/A). */
	public static function render(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $product is WooCommerce's core loop global (must keep this exact name); canonical wc_get_product() fallback, not a plugin global.
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$points = self::points_for_product( $product );
		if ( $points <= 0 ) {
			return; // No-earn product, zero-priced, or rounds to nothing — show nothing.
		}

		// Operator-editable copy (顯示客製化 tab) with {points}/{points_label}/{value} placeholders;
		// falls back to the built-in「購買可得約 X <unit>」default. DisplayMessage handles escaping
		// (per-value esc_html + whole-template wp_kses_post), so the output below is already safe.
		if ( ! DisplayMessage::is_visible( 'single' ) ) {
			return;
		}

		$template = (string) get_option( 'moksafopoi_disp_single_msg', self::default_single_msg() );
		$html     = DisplayMessage::render( $template, $points );
		if ( '' === $html ) {
			return;
		}

		echo '<p class="moksafopoi-earn-preview moksafopoi-disp moksafopoi-disp--single"' . DisplayMessage::style_attr( 'single' ) . '>' . DisplayMessage::icon( 'single' ) . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style_attr() is esc_attr()'d; render() returns wp_kses_post()'d HTML with each value esc_html()'d.
	}

	/** Default single-product teaser template (operator may override via moksafopoi_disp_single_msg). */
	public static function default_single_msg(): string {
		return __( 'Earn about {points} {points_label} on purchase', 'moksa-points-for-woocommerce' );
	}

	/**
	 * Estimated points a single unit of this product earns, computed on the SAME read-path the order
	 * earn engine uses. Returns 0 when the product never earns (no_earn / on-sale-excluded / price 0).
	 */
	public static function points_for_product( \WC_Product $product ): int {
		$product_id = (int) $product->get_id();

		// 1) Honour the per-product "never earns" flag exactly like earn().
		if ( ProductPoints::no_earn( $product_id ) ) {
			return 0;
		}

		// 2) Honour the global「特價品不賺點」switch exactly like earn().
		$exclude_sale = 'yes' === get_option( 'moksafopoi_earn_exclude_sale', 'no' );
		if ( $exclude_sale && $product->is_on_sale() ) {
			return 0;
		}

		$rate     = \Moksafopoi\Support\Rates::earn_rate();
		$cat_mult = CategoryPoints::product_multiplier( $product_id );

		// 3) Per-item base: a fixed override wins over price × rate (mirrors earn()).
		$override = ProductPoints::earn_override( $product_id );
		if ( null !== $override ) {
			$base = (float) $override; // Per single item.
		} else {
			// Match earn(): the order earns on the EX-TAX line total ($item->get_total()), so preview
			// on the ex-tax unit price too (the「賺點基底含稅」option folds tax in at order time only).
			$price = function_exists( 'wc_get_price_excluding_tax' )
				? (float) wc_get_price_excluding_tax( $product )
				: (float) $product->get_price();
			if ( $price <= 0.0 ) {
				return 0;
			}
			$base = $price * $rate;
		}

		// 4) Category multiplier (per-line) then the member's role multiplier (filterable). The
		//    spend-tier bonus is intentionally omitted — it is an order-level figure (hence「約」).
		$role_mult = Multipliers::role_multiplier( get_current_user_id() );
		$raw       = $base * $cat_mult * $role_mult;

		// 5) Round identically to the order (floor by default).
		return max( 0, Module::round_points_public( $raw ) );
	}
}
