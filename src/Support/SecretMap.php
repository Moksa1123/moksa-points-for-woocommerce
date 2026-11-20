<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The options this plugin treats as secrets, and the WordPress 7.2 Secrets API name each one will move
 * to (naming rule: `<wp.org slug>/<option name without the plugin prefix>`).
 *
 * Today (1.0.6) the list keeps them out of the settings screen's HTML and out of settings export /
 * import. The move to the Secrets API itself is phase 1 of docs/WP72-相容盤點與Secrets搬遷設計.md and
 * ships separately once 7.2 can be tested.
 */
final class SecretMap {

	/** @var array<string,string> option name => secret name */
	public const KEYS = array(
		'moksafopoi_line_token'     => 'moksa-points-for-woocommerce/line_token',
		'moksafopoi_webhook_secret' => 'moksa-points-for-woocommerce/webhook_secret',
	);

	/** @return array<int,string> Option names that hold secrets. */
	public static function options(): array {
		return array_keys( self::KEYS );
	}

	public static function is_secret( string $option ): bool {
		return isset( self::KEYS[ $option ] );
	}

	private function __construct() {}
}
