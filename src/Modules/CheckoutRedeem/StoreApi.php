<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\CheckoutRedeem;

use Moksafopoi\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Native WooCommerce Store API integration for the points-redeem wallet, so block Cart / Checkout
 * can read the points state and mutate it WITHOUT a full page reload:
 *
 *   - register_endpoint_data: adds `extensions['moksafopoi-redeem']` to the cart response (balance,
 *     rate, caps, currently-applied points/discount) — readable by the block UI / any headless client.
 *   - register_update_callback: a `moksafopoi-redeem` namespace the front-end calls via
 *     wc.blocksCheckout.extensionCartUpdate({ namespace, data:{ points } }) to set/clear the redeem.
 *
 * Pure server-side validation reuses {@see Module} (clamp against balance + caps). Everything degrades:
 * when these Store API functions are absent (older WC) we simply don't register, and the classic
 * AJAX + render_block panel keeps working.
 */
final class StoreApi {

	private const NAMESPACE = 'moksafopoi-redeem';

	public static function register(): void {
		// The module may boot either side of woocommerce_blocks_loaded, so register immediately when
		// that has already fired, otherwise defer to it. Either way the Store API endpoint data +
		// update callback land before the first cart request.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_now();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( self::class, 'register_now' ) );
		}
	}

	public static function register_now(): void {
		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) && class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( self::class, 'cart_data' ),
					'schema_callback' => array( self::class, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( self::class, 'update' ),
				)
			);
		}
	}

	/** @return array<string,mixed> */
	public static function cart_data(): array {
		$user_id = get_current_user_id();
		$balance = $user_id > 0 ? Api::get_points( $user_id ) : 0;
		$applied = Module::chosen_points();

		return array(
			'enabled'          => $user_id > 0 && $balance > 0,
			'balance'          => (int) $balance,
			'redeem_rate'      => Module::redeem_rate(),
			'min_points'       => Module::min_points(),
			'step'             => Module::step(),
			'max_discount'     => (float) Module::max_discount(),
			'applied_points'   => (int) $applied,
			'applied_discount' => (float) Module::discount_for( $applied ),
		);
	}

	/** @return array<string,mixed> */
	public static function cart_schema(): array {
		$int   = array( 'type' => 'integer', 'context' => array( 'view', 'edit' ), 'readonly' => true );
		$num   = array( 'type' => 'number', 'context' => array( 'view', 'edit' ), 'readonly' => true );
		$bool  = array( 'type' => 'boolean', 'context' => array( 'view', 'edit' ), 'readonly' => true );
		return array(
			'enabled'          => $bool,
			'balance'          => $int,
			'redeem_rate'      => $int,
			'min_points'       => $int,
			'step'             => $int,
			'max_discount'     => $num,
			'applied_points'   => $int,
			'applied_discount' => $num,
		);
	}

	/**
	 * Store API update callback — set/clear the chosen redeem points natively. WooCommerce
	 * recalculates the cart (and our negative fee) immediately after, so the block totals reflect
	 * the discount with no page reload.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function update( array $data ): void {
		if ( get_current_user_id() <= 0 ) {
			return;
		}
		$points = isset( $data['points'] ) ? absint( $data['points'] ) : 0;
		Module::set_session_points( $points );
	}
}
