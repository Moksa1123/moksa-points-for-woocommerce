<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\AdminMenu;

use Moksafopoi\Modules\AbstractModule;

defined( 'ABSPATH' ) || exit;

/**
 * 點數管理選單 — lazy-loaded, boots only when moksafopoi_adminmenu_enabled is 'yes'.
 * Promotes points management into its own independent top-level「Moksa 點數」menu (settings +
 * 兌換型錄) instead of a submenu under WooCommerce. When this module's class is present the
 * plugin's bootstrap stops registering the WooCommerce-submenu fallback, so there is no
 * duplicate entry.
 */
final class Module extends AbstractModule {

	public function slug(): string {
		return 'adminmenu';
	}

	public function label(): string {
		return __( 'Points management menu', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'admin';
	}

	public function tagline(): string {
		return __( 'Move points management into a dedicated top-level menu "Moksa Points" (Settings / Redemption catalog)', 'moksa-points-for-woocommerce' );
	}

	public function boot(): void {
		if ( ! is_admin() ) {
			return;
		}
		// Build the top-level menu before the sibling sub-pages (RewardAdmin runs at priority 20).
		add_action( 'admin_menu', array( Menu::class, 'register' ), 9 );

		// Style the hub dashboard landing page.
		add_action( 'admin_enqueue_scripts', array( Dashboard::class, 'enqueue_admin' ) );

		// Reparent the 兌換型錄 sub-page from WooCommerce to our top-level menu.
		add_filter( 'moksafopoi_rewardadmin_parent', array( self::class, 'rewardadmin_parent' ) );

		// Reparent the 行銷活動 sub-page from WooCommerce to our top-level menu.
		add_filter( 'moksafopoi_campaign_parent', array( self::class, 'rewardadmin_parent' ) );
	}

	public static function rewardadmin_parent(): string {
		return Menu::TOPLEVEL;
	}
}
