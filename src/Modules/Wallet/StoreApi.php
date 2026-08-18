<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\Wallet;

use Moksafopoi\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Native WooCommerce Store API integration for the store-credit wallet, so block Cart / Checkout can
 * read the wallet state and mutate it without a full page reload:
 *
 *   - register_endpoint_data: adds `extensions['moksafopoi-wallet']` to the cart response (balance,
 *     caps, currently-applied amount).
 *   - register_update_callback: a `moksafopoi-wallet` namespace the front-end calls via
 *     wc.blocksCheckout.extensionCartUpdate({ namespace, data:{ amount } }) to set / clear the wallet.
 *
 * Server-side validation reuses {@see Module} (clamp against balance + caps). Degrades gracefully:
 * when these Store API functions are absent (older WC) we simply don't register, and the classic
 * AJAX + render_block panel keep working.
 */
final class StoreApi {

	private const NAMESPACE = 'moksafopoi-wallet';

	public static function register(): void {
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
		$balance = $user_id > 0 ? Api::get_balance( $user_id ) : 0.0;
		$applied = Module::chosen_amount();

		return array(
			'enabled'          => $user_id > 0 && $balance > 0,
			'balance'          => (float) $balance,
			'max_discount'     => (float) Module::max_discount(),
			'applied_amount'   => (float) $applied,
		);
	}

	/** @return array<string,mixed> */
	public static function cart_schema(): array {
		$num  = array( 'type' => 'number', 'context' => array( 'view', 'edit' ), 'readonly' => true );
		$bool = array( 'type' => 'boolean', 'context' => array( 'view', 'edit' ), 'readonly' => true );
		return array(
			'enabled'        => $bool,
			'balance'        => $num,
			'max_discount'   => $num,
			'applied_amount' => $num,
		);
	}

	/**
	 * Store API update callback — set / clear the chosen wallet amount natively.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function update( array $data ): void {
		if ( get_current_user_id() <= 0 ) {
			return;
		}
		$amount = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
		Module::set_session_amount( $amount );
	}
}
