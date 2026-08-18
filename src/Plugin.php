<?php

declare( strict_types=1 );

namespace Moksafopoi;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap (singleton). Wires always-on services (the read API + its global
 * wrappers) and the lazy module registry once WooCommerce is ready, after a hard
 * requirements check. Mirrors the moforcoupon reference architecture.
 */
final class Plugin {

	private static ?self $instance = null;

	private ModuleRegistry $modules;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->modules = new ModuleRegistry();
	}

	public function __clone() {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function __wakeup(): void {
		throw new \LogicException( 'Plugin is a singleton.' );
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	public static function version(): string {
		return MOKSAFOPOI_VERSION;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! Compatibility\Requirements::met() ) {
			Compatibility\Requirements::register_admin_notice();
			return;
		}

		add_action( 'woocommerce_init', array( $this, 'on_woocommerce_init' ) );
		add_filter( 'plugin_action_links_' . MOKSAFOPOI_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	/**
	 * @param array<int,string> $links
	 * @return array<int,string>
	 */
	public function plugin_action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Settings\SettingsScreen::url() ),
			esc_html__( 'Settings', 'moksa-points-for-woocommerce' )
		);
		array_unshift( $links, $settings );
		return $links;
	}

	public function on_woocommerce_init(): void {
		// Always-on: the read API global wrappers, so a sibling's function_exists() probe is
		// stable regardless of which modules are toggled on.
		Api::register_wrappers();

		// Always-on: sibling store-credit intake(分潤佣金以儲值金撥付等)。事件不來就是 no-op;
		// 入帳走帳本 UNIQUE 冪等鍵,sibling 重放事件不會重複入帳。
		Support\SiblingCredits::register();

		// Always-on: 顯示型短碼([moksafopoi_balance] / [moksafopoi_show_if])。純唯讀,
		// 不出現在內容中就零成本。
		Support\Shortcodes::register();

		// Always-on, but self-disabling: the WooCommerce Subscriptions compatibility layer hooks
		// nothing at all unless that extension is present.
		Compatibility\Subscriptions::init();

		// Always-on, but inert until configured: extra points for paying with a particular gateway.
		Support\GatewayPoints::register();

		// Admin-only: 訂單編輯畫面「點數異動」唯讀一行(賺點 / 扣點 / 儲值金,讀帳本 order_id 索引)。
		if ( is_admin() ) {
			Support\AdminOrderInfo::register();
		}

		// Always-on: the settings screen save handler + (when our menu is off) a fallback menu.
		add_action( 'admin_post_' . Settings\SettingsScreen::ACTION, array( Settings\SettingsScreen::class, 'handle' ) );
		add_action( 'admin_post_moksafopoi_export_settings', array( Settings\SettingsScreen::class, 'handle_export' ) );
		add_action( 'admin_enqueue_scripts', array( Settings\SettingsScreen::class, 'enqueue_admin' ) );
		// Fall back to the WooCommerce submenu unless an independent AdminMenu module is BOTH enabled
		// AND actually built — otherwise the settings page is orphaned when the toggle is on but the
		// module class was never shipped.
		if ( ! $this->modules->is_enabled( 'adminmenu' ) || ! class_exists( Modules\AdminMenu\Module::class ) ) {
			add_action( 'admin_menu', array( Settings\SettingsScreen::class, 'register_fallback' ) );
		}

		// Always-on (front-end only): the 顯示客製化 storefront teasers (shop loop / cart / my-account).
		// Each surface gates itself on its own moksafopoi_disp_* option, so nothing renders unless the
		// operator switched that position on — no module toggle gates this display layer.
		if ( ! is_admin() ) {
			Modules\Display\Frontend::init();
		}

		// Always-on: the shared daily heartbeat (expiry sweeps attach here).
		Support\Cron::register();

		// Always-on: keep the custom-table schema current even if the ledger / reward-admin
		// modules are toggled off (idempotent — a no-op once the db_version option matches).
		add_action( 'admin_init', array( Support\Schema::class, 'maybe_upgrade' ) );

		// Run the one-time store-credit migration after upgrades (idempotent, sentinel-gated).
		add_action( 'admin_init', array( Support\Migration::class, 'maybe_run' ) );

		$this->modules->boot();
	}
}
