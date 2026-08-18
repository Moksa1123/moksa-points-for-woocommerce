<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Display;

use Moksafopoi\Modules\SpendRules\EarnPreview;
use Moksafopoi\Support\DisplayMessage;

defined( 'ABSPATH' ) || exit;

/**
 * 顯示客製化(對標 YITH customization)— storefront earn teasers a shop owner can switch on per
 * position and word with an editable message template. Each surface is an INDEPENDENT plain
 * option (toggle + template), never a CPT, and each renders only when its own toggle is 'yes':
 *
 *   • Shop loop card  → woocommerce_after_shop_loop_item_title   (moksafopoi_disp_loop_*)
 *   • Cart totals     → woocommerce_cart_totals_after_order_total (moksafopoi_disp_cart_*)
 *   • My Account      → an optional extra line under the balance card (moksafopoi_disp_account_msg)
 *
 * The single-product teaser is NOT here — it stays in {@see EarnPreview}, whose copy this layer
 * makes editable through `moksafopoi_disp_single_msg`; we do not re-hook it.
 *
 * Every estimate reuses the EXACT earn read-path ({@see EarnPreview::points_for_product()}) so the
 * teased figure never diverges from what the order will award. Output is escaped through
 * {@see DisplayMessage::render()} (per-value esc_html + whole-template wp_kses_post). The renderer
 * is wired always-on (front-end only) by Plugin; the per-option gates below decide what shows.
 */
final class Frontend {

	/** Hard ceiling on cart lines we sum, so a runaway cart never spins the loop. */
	private const MAX_CART_LINES = 200;

	/**
	 * 顯示客製化 位置 → WooCommerce hook 對應表。Each location's `moksafopoi_disp_{loc}_position`
	 * option is validated against this allow-list before it is used to hook; an unknown / tampered value
	 * falls back to the first ('default') entry. Keeping the map here is what lets the operator move a
	 * teaser between a small set of vetted hooks without ever letting an arbitrary string reach add_action.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const POSITION_HOOKS = array(
		'loop' => array(
			'after_title'  => 'woocommerce_after_shop_loop_item_title',
			'before_title' => 'woocommerce_before_shop_loop_item_title',
			'after_price'  => 'woocommerce_after_shop_loop_item',
		),
		'cart' => array(
			'after_total'  => 'woocommerce_cart_totals_after_order_total',
			'before_total' => 'woocommerce_cart_totals_before_order_total',
			'after_cart'   => 'woocommerce_after_cart_table',
		),
	);

	/** Attach the enabled storefront teasers to their WooCommerce hooks (front-end only). */
	public static function init(): void {
		if ( 'yes' === get_option( 'moksafopoi_disp_loop_enabled', 'no' ) ) {
			$hook = self::hook_for( 'loop', 'after_title' );
			// before_* fires earlier in the card markup; keep the after_* default at priority 12 so it lands
			// just below the title like before.
			$prio = ( 'woocommerce_after_shop_loop_item_title' === $hook ) ? 12 : 10;
			add_action( $hook, array( self::class, 'render_loop' ), $prio );
		}

		if ( 'yes' === get_option( 'moksafopoi_disp_cart_enabled', 'no' ) ) {
			add_action( self::hook_for( 'cart', 'after_total' ), array( self::class, 'render_cart' ) );
		}

		// My Account extra line: only meaningful when the "My points" page is on; it renders right after
		// the hero balance card via the dedicated action that Endpoint fires. The only vetted account
		// position is after_balance, so there is no hook to switch — the position select is forward-looking.
		add_action( 'moksafopoi_after_account_hero', array( self::class, 'render_account' ) );
	}

	/**
	 * Resolve a location's operator-chosen position option to a concrete, whitelisted WooCommerce hook.
	 * An unknown / tampered position falls back to the supplied default key (and that to the map's first
	 * entry), so add_action() is only ever handed a hook from {@see POSITION_HOOKS}.
	 */
	private static function hook_for( string $loc, string $default_key ): string {
		$map = self::POSITION_HOOKS[ $loc ] ?? array();
		if ( array() === $map ) {
			return '';
		}
		$choice = (string) get_option( 'moksafopoi_disp_' . $loc . '_position', $default_key );
		if ( isset( $map[ $choice ] ) ) {
			return $map[ $choice ];
		}
		return $map[ $default_key ] ?? (string) reset( $map );
	}

	/**
	 * Shop-loop teaser:「購買可得 {points} {points_label}」under each product card. Silent for a
	 * product that never earns / rounds to nothing, so the grid stays clean.
	 */
	public static function render_loop(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( get_the_ID() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- $product is WooCommerce's core loop global (must keep this exact name); canonical wc_get_product() fallback, not a plugin global.
		}
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$points = EarnPreview::points_for_product( $product );
		if ( $points <= 0 ) {
			return;
		}

		if ( ! DisplayMessage::is_visible( 'loop' ) ) {
			return;
		}

		$template = (string) get_option( 'moksafopoi_disp_loop_msg', self::default_loop_msg() );
		$html     = DisplayMessage::render( $template, $points );
		if ( '' === $html ) {
			return;
		}

		echo '<p class="moksafopoi-disp moksafopoi-disp--loop"' . DisplayMessage::style_attr( 'loop' ) . '>' . DisplayMessage::icon( 'loop' ) . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style_attr() is esc_attr()'d; render() returns wp_kses_post()'d HTML with each value esc_html()'d.
	}

	/**
	 * Cart teaser:「本次消費可得約 {points} {points_label}」appended to the cart totals. The estimate
	 * sums every line's per-unit earn × quantity on the SAME read-path the order uses (so per-product
	 * overrides / no-earn / category multipliers all apply); it is「約」because the order-level spend-tier
	 * bonus cannot be known from the cart alone.
	 */
	public static function render_cart(): void {
		$points = self::cart_points_estimate();
		if ( $points <= 0 ) {
			return;
		}

		if ( ! DisplayMessage::is_visible( 'cart' ) ) {
			return;
		}

		$template = (string) get_option( 'moksafopoi_disp_cart_msg', self::default_cart_msg() );
		$html     = DisplayMessage::render( $template, $points );
		if ( '' === $html ) {
			return;
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- style_attr() returns an esc_attr()'d style="" attribute; icon() and render() return wp_kses_post()'d HTML with each value esc_html()'d.
		echo '<tr class="moksafopoi-disp-cart-row order-total"><th>' . esc_html__( 'Estimated earning', 'moksa-points-for-woocommerce' )
			. '</th><td data-title="' . esc_attr__( 'Estimated earning', 'moksa-points-for-woocommerce' ) . '">'
			. '<span class="moksafopoi-disp moksafopoi-disp--cart"' . DisplayMessage::style_attr( 'cart' ) . '>'
			. DisplayMessage::icon( 'cart' ) . $html
			. '</span></td></tr>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Optional extra line on the「我的點數」page, under the balance hero (e.g. a custom call-to-action).
	 * Off by default (blank option). The points figure is the member's CURRENT balance, passed by the
	 * Endpoint when it fires the action.
	 *
	 * @param int $points The member's current points balance (supplied by Endpoint).
	 */
	public static function render_account( int $points = 0 ): void {
		if ( ! DisplayMessage::is_visible( 'account' ) ) {
			return;
		}

		$template = trim( (string) get_option( 'moksafopoi_disp_account_msg', '' ) );
		if ( '' === $template ) {
			return; // Opt-in: nothing configured → nothing shown.
		}

		$html = DisplayMessage::render( $template, max( 0, $points ) );
		if ( '' === $html ) {
			return;
		}

		echo '<p class="moksafopoi-disp moksafopoi-disp--account"' . DisplayMessage::style_attr( 'account' ) . '>' . DisplayMessage::icon( 'account' ) . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- style_attr() is esc_attr()'d; render() returns wp_kses_post()'d HTML with each value esc_html()'d.
	}

	/**
	 * Whole-cart points estimate: sum of each line's per-unit earn × quantity, reusing
	 * {@see EarnPreview::points_for_product()} (the exact earn read-path). Bounded by MAX_CART_LINES.
	 */
	public static function cart_points_estimate(): int {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$total = 0;
		$seen  = 0;
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( ++$seen > self::MAX_CART_LINES ) {
				break;
			}
			$product = isset( $line['data'] ) && $line['data'] instanceof \WC_Product ? $line['data'] : null;
			if ( null === $product ) {
				continue;
			}
			$qty = isset( $line['quantity'] ) ? max( 0, (int) $line['quantity'] ) : 0;
			if ( $qty <= 0 ) {
				continue;
			}
			$total += EarnPreview::points_for_product( $product ) * $qty;
		}

		return max( 0, $total );
	}

	/** Default shop-loop template (operator may override via the option). */
	public static function default_loop_msg(): string {
		return __( 'Earn {points} {points_label} on this purchase', 'moksa-points-for-woocommerce' );
	}

	/** Default cart template (operator may override via the option). */
	public static function default_cart_msg(): string {
		return __( 'This purchase earns about {points} {points_label}', 'moksa-points-for-woocommerce' );
	}
}
