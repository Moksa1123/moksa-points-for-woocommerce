<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\AdminMenu;

use Moksafopoi\Settings\SettingsScreen;

defined( 'ABSPATH' ) || exit;

/**
 * Independent top-level「Moksa 點數」admin menu — so this large plugin lives in its own place
 * (matching the moforcoupon「Moksa 優惠券」UX) instead of being buried as a submenu under
 * WooCommerce. The top-level slug is a clean dashboard slug whose landing page is the points
 * hub ({@see Dashboard}); the settings and 兌換型錄 screens hang off it as sub-pages. Sibling
 * sub-pages (兌換型錄) attach themselves under this slug via their own parent filter.
 */
final class Menu {

	/** Clean top-level dashboard slug (the hub landing page). */
	public const TOPLEVEL = 'moksa-points-for-woocommerce';

	private const CAP = 'manage_woocommerce';

	public static function register(): void {
		add_menu_page(
			__( 'Moksa Points', 'moksa-points-for-woocommerce' ),
			__( 'Moksa Points', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::TOPLEVEL,
			array( Dashboard::class, 'render' ),
			'dashicons-star-filled',
			55.7
		);

		// Rename the auto-generated first submenu (slug === parent) to「儀表板」.
		add_submenu_page(
			self::TOPLEVEL,
			__( 'Dashboard', 'moksa-points-for-woocommerce' ),
			__( 'Dashboard', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::TOPLEVEL,
			array( Dashboard::class, 'render' ),
			0
		);

		// Settings sub-pages (基本設定 / 顯示與信件 / 工具 / 紀錄) — one submenu item each so no
		// single page has to carry every tab. Labels/slugs/callbacks come from SettingsScreen::pages().
		$position = 5;
		foreach ( SettingsScreen::pages() as $spec ) {
			add_submenu_page(
				self::TOPLEVEL,
				(string) $spec['label'],
				(string) $spec['menu'],
				self::CAP,
				(string) $spec['slug'],
				$spec['cb'],
				$position
			);
			++$position;
		}
	}
}
