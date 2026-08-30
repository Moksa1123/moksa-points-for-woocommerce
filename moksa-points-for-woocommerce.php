<?php
/**
 * Plugin Name:        Moksa Points for WooCommerce
 * Plugin URI:         https://github.com/Moksa1123/moksa-points-for-woocommerce
 * Description:        The value engine for the Moksa platform: an idempotent points / store-credit ledger, earn rules, redeem, a checkout wallet, and Abilities/MCP. The single source of truth for customer balance — companion plugins read it, never store their own.
 * Version:            1.0.1
 * Requires at least:  7.0
 * Tested up to:       7.1
 * Requires PHP:       8.2
 * Requires Plugins:   woocommerce
 * WC requires at least: 10.7
 * WC tested up to:    10.9
 * Author:             MoksaWeb
 * Author URI:         https://moksaweb.com/
 * License:            GPLv3 or later
 * License URI:        https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:        moksa-points-for-woocommerce
 * Domain Path:        /languages
 *
 * @package Moksafopoi
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/* Constants */
const MOKSAFOPOI_VERSION    = '1.0.1';
const MOKSAFOPOI_DB_VERSION = '1';
const MOKSAFOPOI_MIN_PHP    = '8.2';
const MOKSAFOPOI_MIN_WP     = '7.0';
const MOKSAFOPOI_MIN_WC     = '10.7';
const MOKSAFOPOI_TEXTDOMAIN = 'moksa-points-for-woocommerce';

define( 'MOKSAFOPOI_PLUGIN_FILE', __FILE__ );
define( 'MOKSAFOPOI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MOKSAFOPOI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOKSAFOPOI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Autoload — prefer Composer's autoloader; otherwise fall back to a built-in PSR-4
 * autoloader so the plugin works without Composer (it has no runtime dependencies).
 */
$moksafopoi_autoload = MOKSAFOPOI_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $moksafopoi_autoload ) ) {
	require_once $moksafopoi_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Moksafopoi\\';
			$length = strlen( $prefix );
			if ( strncmp( $prefix, $class_name, $length ) !== 0 ) {
				return;
			}
			$relative = substr( $class_name, $length );
			$path     = MOKSAFOPOI_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

/*
 * Shared Moksa Kit (bundled library; single-instance version election across the suite).
 * Infrastructure every Moksa plugin's settings screen needs - the `mowp-` design system, the settings
 * change log, the settings export/import porter - carried once instead of five times. Always loaded:
 * it registers an autoloader and nothing else, makes no request and touches no option by itself.
 */
require_once __DIR__ . '/lib/moksa-kit/moksa-kit.php';

/* Shared Moksa AI launcher (bundled; single-instance version election across the suite). */
require_once __DIR__ . '/lib/moksa-ai/moksa-ai.php';
add_filter(
	'moksa_ai_sections',
	static function ( array $sections ): array {
		$sections[] = array(
			'id'        => 'points',
			'label'     => __( 'Points', 'moksa-points-for-woocommerce' ),
			'icon'      => 'star-filled',
			'namespace' => 'points/',
		);
		return $sections;
	}
);

/* HPOS + Block Checkout compatibility — must run before woocommerce_init */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MOKSAFOPOI_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MOKSAFOPOI_PLUGIN_FILE, true );
		}
	}
);

/* Boot */
add_action(
	'plugins_loaded',
	static function (): void {
		\Moksafopoi\Plugin::instance()->boot();
	},
	5
);

// i18n: translations load just-in-time (WP 4.6+) for wordpress.org-hosted plugins; textdomain matches the slug.

/* Activation: create tables + seed safe defaults. Deactivation: clear cron. */
register_activation_hook(
	__FILE__,
	static function (): void {
		\Moksafopoi\Support\Schema::install();
		\Moksafopoi\Support\Activation::on_activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\Moksafopoi\Support\Cron::clear();
	}
);
