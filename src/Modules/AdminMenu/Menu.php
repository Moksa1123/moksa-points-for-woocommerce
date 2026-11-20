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

	/**
	 * Menu icon as an inline SVG (a star). WordPress 7.2 replaces Dashicons in the admin menu with its own
	 * SVG set, so a "dashicons-*" name may no longer draw; a data-URI SVG is recoloured by core's
	 * svg-painter on every version.
	 */
	private static function icon(): string {
		// <svg viewBox="0 0 20 20"><path fill="black" d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9z"/></svg>
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xMCAxLjVsMi42IDUuMyA1LjkuOS00LjMgNC4xIDEgNS44TDEwIDE0LjlsLTUuMiAyLjcgMS01LjhMMS41IDcuN2w1LjktLjl6Ii8+PC9zdmc+';
	}

	public static function register(): void {
		add_menu_page(
			__( 'Moksa Points', 'moksa-points-for-woocommerce' ),
			__( 'Moksa Points', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::TOPLEVEL,
			array( Dashboard::class, 'render' ),
			self::icon(),
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
