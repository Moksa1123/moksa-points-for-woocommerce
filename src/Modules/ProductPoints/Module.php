<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\ProductPoints;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\ProductPoints;

defined( 'ABSPATH' ) || exit;

/**
 * 逐商品點數 — adds a「點數」tab to the WooCommerce product-data box so each product can override
 * the site-wide earn/redeem behaviour: a fixed per-item points award, "never earns", and "cannot
 * be paid with points". Stored as plain product meta (no CPT). The read side lives in
 * {@see ProductPoints} so SpendRules / CheckoutRedeem honour it even when this admin module is off.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'productpoints';
	}

	public function label(): string {
		return __( 'Per-product points override', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'earn';
	}

	public function tagline(): string {
		return __( 'Add a "Points" tab in the product editor: how many points per item, this product cannot earn points / cannot be paid for with points', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		add_filter( 'woocommerce_product_data_tabs', array( self::class, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( self::class, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( self::class, 'save' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_tab( array $tabs ): array {
		$tabs['moksa-points-for-woocommerce'] = array(
			'label'    => __( 'Points', 'moksa-points-for-woocommerce' ),
			'target'   => 'moksafopoi_product_data',
			'class'    => array(),
			'priority' => 80,
		);
		return $tabs;
	}

	public static function render_panel(): void {
		global $post;
		$product_id = $post instanceof \WP_Post ? (int) $post->ID : 0;

		$override  = get_post_meta( $product_id, ProductPoints::META_EARN_OVERRIDE, true );
		$no_earn   = 'yes' === get_post_meta( $product_id, ProductPoints::META_NO_EARN, true );
		$no_redeem = 'yes' === get_post_meta( $product_id, ProductPoints::META_NO_REDEEM, true );

		echo '<div id="moksafopoi_product_data" class="panel woocommerce_options_panel hidden">';

		woocommerce_wp_text_input(
			array(
				'id'                => ProductPoints::META_EARN_OVERRIDE,
				'value'             => is_scalar( $override ) ? (string) $override : '',
				'label'             => __( 'Points per item (override)', 'moksa-points-for-woocommerce' ),
				'desc_tip'          => true,
				'description'       => __( 'Leave empty = use the site-wide "points per NT$1" ratio. Enter a number (including 0) = this product gives this many points per item.', 'moksa-points-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => ProductPoints::META_NO_EARN,
				'value'       => $no_earn ? 'yes' : 'no',
				'label'       => __( 'This product cannot earn points', 'moksa-points-for-woocommerce' ),
				'description' => __( 'When checked, this product\'s amount is not counted toward the earning base.', 'moksa-points-for-woocommerce' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => ProductPoints::META_NO_REDEEM,
				'value'       => $no_redeem ? 'yes' : 'no',
				'label'       => __( 'This product cannot be paid for with points', 'moksa-points-for-woocommerce' ),
				'description' => __( 'When checked, this product\'s amount is not included in the base eligible for points redemption.', 'moksa-points-for-woocommerce' ),
			)
		);

		echo '</div>';
	}

	public static function save( int $post_id ): void {
		// WooCommerce verifies its own product-data nonce before firing this hook; re-check the
		// capability defensively.
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$override_raw = isset( $_POST[ ProductPoints::META_EARN_OVERRIDE ] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce handled by WC product save.
			? sanitize_text_field( wp_unslash( (string) $_POST[ ProductPoints::META_EARN_OVERRIDE ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce handled by WC product save.
			: '';
		if ( '' === $override_raw ) {
			delete_post_meta( $post_id, ProductPoints::META_EARN_OVERRIDE );
		} else {
			update_post_meta( $post_id, ProductPoints::META_EARN_OVERRIDE, (string) max( 0, (int) $override_raw ) );
		}

		$no_earn = isset( $_POST[ ProductPoints::META_NO_EARN ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce handled by WC product save.
		update_post_meta( $post_id, ProductPoints::META_NO_EARN, $no_earn );

		$no_redeem = isset( $_POST[ ProductPoints::META_NO_REDEEM ] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce handled by WC product save.
		update_post_meta( $post_id, ProductPoints::META_NO_REDEEM, $no_redeem );
	}
}
