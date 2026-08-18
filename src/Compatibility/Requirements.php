<?php

declare( strict_types=1 );

namespace Moksafopoi\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Hard requirements gate (PHP / WP / WooCommerce). The plugin refuses to boot its
 * features and shows a dismissible admin notice when unmet, rather than fataling.
 */
final class Requirements {

	public static function met(): bool {
		if ( version_compare( PHP_VERSION, MOKSAFOPOI_MIN_PHP, '<' ) ) {
			return false;
		}
		global $wp_version;
		if ( version_compare( (string) $wp_version, MOKSAFOPOI_MIN_WP, '<' ) ) {
			return false;
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, MOKSAFOPOI_MIN_WC, '<' ) ) {
			return false;
		}
		return true;
	}

	public static function register_admin_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p>'
					. esc_html(
						sprintf(
							/* translators: 1: PHP version, 2: WP version, 3: WC version. */
							__( 'Moksa Points requires PHP %1$s+, WordPress %2$s+ and WooCommerce %3$s+ to run.', 'moksa-points-for-woocommerce' ),
							MOKSAFOPOI_MIN_PHP,
							MOKSAFOPOI_MIN_WP,
							MOKSAFOPOI_MIN_WC
						)
					)
					. '</p></div>';
			}
		);
	}
}
