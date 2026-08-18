<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\MyAccount;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * "My points" module — lazy-loaded, boots only when moksafopoi_myaccount_enabled is 'yes'.
 * Adds a "My points" tab to the WooCommerce「我的帳戶」area showing the logged-in customer's
 * current points + currency store-credit (a hero card) followed by their paginated ledger
 * history. Mirrors moforcoupon's MyAccount\Module (rewrite endpoint + menu item + render +
 * is_account_page()-gated enqueue with filemtime cache-busting; reuses a parallel card CSS).
 */
final class Module extends AbstractModule {

	private const REWRITE_FLAG = 'moksafopoi_myaccount_rewrite';

	public function slug(): string {
		return 'myaccount';
	}

	public function label(): string {
		return __( 'My points (My Account)', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'frontend';
	}

	public function tagline(): string {
		return __( 'Show the customer\'s points / store-credit balance and history in WooCommerce "My Account"', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger' );
	}

	public function boot(): void {
		add_action( 'init', array( Endpoint::class, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( Endpoint::class, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . Endpoint::SLUG . '_endpoint', array( Endpoint::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( Endpoint::class, 'enqueue' ) );
		// Front-end redeem button posts here (any logged-in customer; nonce + cap checked in handler).
		add_action( 'admin_post_moksafopoi_redeem', array( Endpoint::class, 'handle_redeem' ) );

		// 兌點碼:會員中心「輸入代碼領點」表單的處理器(限登入;rate-limit + 全域上限 + 每人一次)。
		add_action( 'admin_post_' . RedeemCode::ACTION, array( RedeemCode::class, 'handle' ) );

		// 點數商城前台:把所有上架兌換項目以商城網格呈現,沿用上面同一個 redeem 處理器與每項 nonce。
		add_shortcode( 'moksafopoi_mall', array( Mall::class, 'shortcode' ) );

		// Register the endpoint then flush rewrite rules once per plugin version (a new endpoint
		// needs fresh rules to resolve). Cheap after the first run thanks to the version flag.
		add_action( 'init', array( self::class, 'maybe_flush' ), 99 );
	}

	public static function maybe_flush(): void {
		if ( get_option( self::REWRITE_FLAG ) === MOKSAFOPOI_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLAG, MOKSAFOPOI_VERSION );
	}
}
