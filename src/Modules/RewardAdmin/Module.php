<?php

declare( strict_types=1 );

namespace Moksafopoi\Modules\RewardAdmin;

use Moksafopoi\Modules\AbstractModule;
use Moksafopoi\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * 兌換型錄管理 — back-office CRUD for the rewards table that {@see \Moksafopoi\Modules\Redeem\Service}
 * fulfils. Adds one submenu page beside the points settings screen where an operator can list,
 * add, edit, delete and toggle redeemable rewards. Today the only reward kind is 'coupon': the
 * payload is split into three friendly inputs (template code/id, coupon-code prefix, expiry days)
 * and stored as the JSON {template,prefix,expiry_days} the redeem service already reads.
 *
 * All writes go through one nonce-protected, capability-gated admin-post handler with prepared
 * statements; every output is escaped and every input is unslashed + sanitised.
 */
final class Module extends AbstractModule {

	public const PAGE   = 'moksafopoi-rewards';
	public const CAP    = 'manage_woocommerce';
	public const NONCE  = 'moksafopoi_reward_admin';
	public const ACTION = 'moksafopoi_save_reward';

	public function slug(): string {
		return 'rewardadmin';
	}

	public function label(): string {
		return __( 'Redemption catalog', 'moksa-points-for-woocommerce' );
	}

	public function category(): string {
		return 'spend';
	}

	public function tagline(): string {
		return __( 'Manage the items that can be redeemed with points in the admin (coupon templates, points cost, stock, publish status)', 'moksa-points-for-woocommerce' );
	}

	/** @return array<int,string> */
	public function requires(): array {
		return array( 'ledger', 'redeem' );
	}

	public function boot(): void {
		// Cheap guard so a manual upgrade that skipped activation still has the rewards table.
		Schema::maybe_upgrade();

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( Screen::class, 'enqueue_admin' ) );
		add_action( 'admin_post_' . self::ACTION, array( Screen::class, 'handle' ) );

		do_action( 'moksafopoi_rewardadmin_booted' );
	}

	/**
	 * Add the rewards submenu next to the points settings page. Parent matches whichever menu
	 * the settings screen lives under (the independent "Moksa Points" top-level when the AdminMenu
	 * module is on, otherwise the WooCommerce menu fallback).
	 */
	public function register_menu(): void {
		$parent = $this->parent_slug();

		add_submenu_page(
			$parent,
			__( 'Redemption catalog', 'moksa-points-for-woocommerce' ),
			__( 'Redemption catalog', 'moksa-points-for-woocommerce' ),
			self::CAP,
			self::PAGE,
			array( Screen::class, 'render' )
		);
	}

	/** Resolve the parent slug shared with the settings screen, so the two pages sit together. */
	private function parent_slug(): string {
		/**
		 * Filter the parent menu slug the rewards catalog page is attached to.
		 *
		 * @param string $parent Default parent slug.
		 */
		$parent = apply_filters( 'moksafopoi_rewardadmin_parent', 'woocommerce' );

		return is_string( $parent ) && '' !== $parent ? $parent : 'woocommerce';
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

}
