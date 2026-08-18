<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\BuyWithPoints;

use Moksafopoi\Api;
use Moksafopoi\Modules\AbstractModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * 純點數購買整件商品 — the admin field (per-product points price) + the product-page「用點數兌換整件」button.
 * The redemption itself (deduct + NT$0 order, per-user locked, refund-safe) lives in {@see Service}.
 * Destructive spend path → front-end form only, NOT registered as an ability / MCP tool.
 */
final class Module extends AbstractModule {

	public const ACTION = 'moksafopoi_buy_with_points';
	private const NONCE  = 'moksafopoi_buy_with_points';
	private const FLASH  = 'moksafopoi_bwp_flash_';

	public function slug(): string {
		return 'buywithpoints';
	}

	public function label(): string {
		return __( 'Buy products with points only', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'redeem';
	}

	public function tagline(): string {
		return __( 'Offer "Redeem the whole item for X points" on the product page (creates an NT$0 order). Set a points price per product; deducting points uses a per-user lock to prevent concurrent double-spend.', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		if ( is_admin() ) {
			add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'admin_field' ) );
			add_action( 'woocommerce_process_product_meta', array( self::class, 'save_field' ) );
		}
		add_action( 'woocommerce_single_product_summary', array( self::class, 'render_button' ), 35 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle_guest' ) );
	}

	/* ------------------------------------------------------------------ admin */

	/** Per-product「點數售價」field on the product data → General tab. */
	public static function admin_field(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => Service::META_KEY,
				'label'       => __( 'Points price (points-only purchase)', 'moksa-points-for-woocommerce' ),
				'description' => __( 'Once set, members can redeem the whole item on the product page for this many points (creates an NT$0 order). Leave empty / 0 = points-only purchase not available. Requires the "Buy products with points only" module to be enabled.', 'moksa-points-for-woocommerce' ),
				'desc_tip'    => true,
				'type'        => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			)
		);
	}

	/** Save the points price. WooCommerce has already verified the product meta nonce before this fires. */
	public static function save_field( int $post_id ): void {
		if ( $post_id <= 0 || ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- woocommerce_process_product_meta verifies the product nonce before this hook; value cast to int.
		$raw = isset( $_POST[ Service::META_KEY ] ) ? (int) wp_unslash( $_POST[ Service::META_KEY ] ) : 0;
		$raw = max( 0, $raw );
		if ( $raw > 0 ) {
			update_post_meta( $post_id, Service::META_KEY, $raw );
		} else {
			delete_post_meta( $post_id, Service::META_KEY );
		}
	}

	/* ------------------------------------------------------------------ front-end */

	/** Render the「用點數兌換整件」button on the single product page. */
	public static function render_button(): void {
		$product_id = (int) get_the_ID();
		if ( ! Service::is_buyable( $product_id ) ) {
			return;
		}
		$cost    = Service::points_price( $product_id );
		$user_id = get_current_user_id();

		echo '<div class="moksafopoi-buy-with-points" style="margin:10px 0">';

		if ( is_array( $flash = get_transient( self::FLASH . $user_id ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			delete_transient( self::FLASH . $user_id );
			$ok   = ! empty( $flash['ok'] );
			echo '<p style="padding:8px 12px;border-radius:4px;background:' . esc_attr( $ok ? '#e6f4ea' : '#fce8e6' ) . '">'
				. esc_html( (string) ( $flash['message'] ?? '' ) ) . '</p>';
		}

		$label = sprintf( /* translators: %s: points cost. */ __( 'Redeem the whole item for %s points', 'moksa-points-for-woocommerce' ), number_format_i18n( $cost ) );

		if ( $user_id <= 0 ) {
			echo '<p class="moksafopoi-bwp-hint">' . esc_html( $label ) . ' — '
				. '<a href="' . esc_url( wp_login_url( get_permalink( $product_id ) ) ) . '">' . esc_html__( 'Please log in first', 'moksa-points-for-woocommerce' ) . '</a></p>';
			echo '</div>';
			return;
		}

		if ( ! Api::can_afford( $user_id, $cost ) ) {
			echo '<p class="moksafopoi-bwp-hint">' . esc_html( $label )
				. '（' . esc_html( sprintf( /* translators: %s: current points. */ __( 'You currently have %s point(s)', 'moksa-points-for-woocommerce' ), number_format_i18n( Api::get_points( $user_id ) ) ) ) . '）</p>';
			echo '</div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="product_id" value="' . esc_attr( (string) $product_id ) . '">';
		wp_nonce_field( self::NONCE, '_wpnonce', true, true );
		echo '<button type="submit" class="button alt moksafopoi-bwp-button">' . esc_html( $label ) . '</button>';
		echo '</form></div>';
	}

	/** Guests can't redeem — bounce to login. */
	public static function handle_guest(): void {
		wp_safe_redirect( wp_login_url( wp_get_referer() ? wp_get_referer() : home_url( '/' ) ) );
		exit;
	}

	/** admin-post: redeem a product for points, then redirect to the order-received page (or back). */
	public static function handle(): void {
		if ( ! is_user_logged_in() ) {
			self::handle_guest();
			return;
		}
		check_admin_referer( self::NONCE );
		$user_id    = get_current_user_id();
		$product_id = isset( $_POST['product_id'] ) ? (int) wp_unslash( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int.

		$result   = Service::purchase( $user_id, $product_id );
		$fallback = wp_get_referer() ? wp_get_referer() : home_url( '/' );

		if ( $result instanceof WP_Error ) {
			set_transient( self::FLASH . $user_id, array( 'ok' => false, 'message' => $result->get_error_message() ), MINUTE_IN_SECONDS );
			wp_safe_redirect( $fallback );
			exit;
		}

		// Success → order-received page when we can build one, else back to the product with a notice.
		$redirect = $fallback;
		$order_id = (int) ( $result['order_id'] ?? 0 );
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$redirect = $order->get_checkout_order_received_url();
			}
		}
		set_transient(
			self::FLASH . $user_id,
			array(
				'ok'      => true,
				'message' => sprintf( /* translators: %d: points spent. */ __( 'Redemption successful! Deducted %d point(s) and created an order.', 'moksa-points-for-woocommerce' ), (int) $result['cost'] ),
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
