<?php

declare( strict_types=1 );

namespace Moksafopoi\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Result flags carried on a post-redirect-get URL ("settings saved", "3 imported", "campaign deleted"…).
 *
 * The flags only choose which admin notice to show, but without a check anyone could craft a link that
 * makes the screen announce something that never happened. So the redirect adds a nonce, and a flag is
 * only read back when that nonce is valid for the current user. Same pattern as Moksa Coupons.
 */
final class RedirectNotice {

	private const ACTION = 'moksafopoi_redirect_notice';

	private const FIELD = 'moksafopoi_nn';

	/**
	 * Add result flags plus the nonce to a redirect URL.
	 *
	 * @param array<string,scalar> $flags Flag name => value.
	 * @param string               $url   Redirect target.
	 */
	public static function url( array $flags, string $url ): string {
		$flags[ self::FIELD ] = wp_create_nonce( self::ACTION );
		return add_query_arg( $flags, $url );
	}

	/**
	 * One flag from the current request, or null when it is absent or the nonce does not verify.
	 *
	 * @param string $key Flag name.
	 */
	public static function get( string $key ): ?string {
		if ( ! isset( $_GET[ self::FIELD ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET[ self::FIELD ] ) ), self::ACTION ) ) {
			return null;
		}
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : null;
	}

	private function __construct() {}
}
